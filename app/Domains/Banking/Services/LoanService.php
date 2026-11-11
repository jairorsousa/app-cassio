<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Models\LoanSettlement;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use App\Support\SimpleInterest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LoanService
{
    public const ATTACHMENT_DISK = 'local';

    public function cents(string $amount, bool $zero = false): int
    {
        return app(ReceiptDestinationService::class)->cents($amount, $zero);
    }

    public function money(int $cents): string
    {
        return SimpleInterest::money($cents);
    }

    /**
     * Campos opcionais vazios viram null (ou 0 nos juros). O MySQL em modo estrito recusa '' em coluna DATE
     * ("Incorrect date value"), e era isso que derrubava o "Salvar" com o vencimento vazio.
     */
    private function normalize(array $data): array
    {
        foreach (['first_due_date', 'notes', 'interest_rate_am'] as $key) {
            if (array_key_exists($key, $data) && (is_null($data[$key]) || (is_string($data[$key]) && trim($data[$key]) === ''))) {
                $data[$key] = $key === 'interest_rate_am' ? '0' : null;
            }
        }

        return $data;
    }

    private function rules(): array
    {
        return [
            'contact_id' => 'required|integer|exists:contacts,id',
            'bank_account_id' => 'required|integer',
            'description' => 'required|string|max:200',
            'principal' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'date' => 'required|date_format:Y-m-d|before_or_equal:'.\App\Support\BusinessDate::today(),
            'installments' => 'required|integer|min:1|max:120',
            'first_due_date' => 'nullable|date_format:Y-m-d|after_or_equal:date',
            'interest_rate_am' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public function create(array $data, ?UploadedFile $attachment = null): Loan
    {
        $data = $this->normalize($data);
        Validator::make($data, $this->rules() + ['request_key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($data, $attachment) {
            if ($existing = Loan::where('request_key', $data['request_key'])->first()) {
                return $existing;
            }
            $contact = Contact::active()->find($data['contact_id']);
            if (! $contact) {
                throw new \DomainException('Selecione um contato ativo.');
            }
            $account = $this->account((int) $data['bank_account_id']);
            $principal = $this->cents((string) $data['principal']);
            if ($principal <= 0) {
                throw new \DomainException('O valor do empréstimo deve ser positivo.');
            }
            $count = (int) $data['installments'];
            $rate = (float) ($data['interest_rate_am'] ?? 0);
            $firstDue = $data['first_due_date'] ?? null;
            if ($count > 1 && ! $firstDue) {
                throw new \DomainException('Informe o primeiro vencimento das parcelas.');
            }

            $loan = Loan::create([
                'contact_id' => $contact->id,
                'bank_account_id' => $account->id,
                'description' => $data['description'],
                'principal' => $this->money($principal),
                'date' => $data['date'],
                'installment_count' => $count,
                'first_due_date' => $firstDue,
                'interest_rate_am' => $rate,
                'notes' => $data['notes'] ?? null,
                'request_key' => $data['request_key'],
            ]);

            $this->buildInstallments($loan, $principal, $count, $firstDue);

            // Saída de caixa → crédito a recuperar: não entra no resultado (categoria de sistema).
            $out = app(TransactionService::class)->create([
                'type' => 'expense',
                'status' => 'settled',
                'date' => $data['date'],
                'amount' => $this->money($principal),
                'description' => mb_substr('Empréstimo concedido · '.$contact->name.' · '.$data['description'], 0, 200),
                'bank_account_id' => $account->id,
                'category_id' => Category::loanDisbursementCategoryId(),
                'source_type' => Loan::class,
                'source_id' => $loan->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $loan->update(['disbursement_transaction_id' => $out->id]);

            if ($attachment) {
                $this->storeAttachment($loan, $attachment);
            }

            $this->invalidate();

            return $loan->fresh(['installments', 'contact', 'bankAccount']);
        });
    }

    private function buildInstallments(Loan $loan, int $principal, int $count, ?string $firstDue): void
    {
        $base = intdiv($principal, $count);
        if ($base === 0) {
            throw new \DomainException('O valor de cada parcela deve ser de pelo menos um centavo.');
        }
        for ($i = 1; $i <= $count; $i++) {
            $amount = $base + ($i === $count ? $principal % $count : 0);
            $due = $firstDue
                ? Carbon::parse($firstDue)->addMonthsNoOverflow($i - 1)->toDateString()
                : null;
            $loan->installments()->create([
                'number' => $i,
                'amount' => $this->money($amount),
                'due_date' => $due,
            ]);
        }
    }

    /**
     * Edita o empréstimo e o lançamento de saída ligado a ele.
     * Com devoluções registradas, só descrição e observações podem mudar (o resto mexeria no que já foi recebido).
     */
    public function update(Loan $loan, array $data): Loan
    {
        $data = $this->normalize($data);
        Validator::make($data, $this->rules())->validate();

        return DB::transaction(function () use ($loan, $data) {
            $loan = Loan::with(['installments', 'settlements', 'disbursement', 'contact'])->lockForUpdate()->findOrFail($loan->id);
            $principal = $this->cents((string) $data['principal']);
            if ($principal <= 0) {
                throw new \DomainException('O valor do empréstimo deve ser positivo.');
            }
            $count = (int) $data['installments'];
            $rate = (float) ($data['interest_rate_am'] ?? 0);
            $firstDue = $data['first_due_date'] ?? null;
            if ($count > 1 && ! $firstDue) {
                throw new \DomainException('Informe o primeiro vencimento das parcelas.');
            }

            if ($loan->settlements->isNotEmpty()) {
                $changed = array_keys(array_filter([
                    'pessoa' => (int) $data['contact_id'] !== (int) $loan->contact_id,
                    'conta' => (int) $data['bank_account_id'] !== (int) $loan->bank_account_id,
                    'valor' => $principal !== $loan->principalCents(),
                    'data' => $data['date'] !== $loan->date->toDateString(),
                    'parcelas' => $count !== (int) $loan->installment_count,
                    'vencimento' => $firstDue !== $loan->first_due_date?->toDateString(),
                    'juros' => abs($rate - (float) $loan->interest_rate_am) > 0.00001,
                ]));
                if ($changed !== []) {
                    throw new \DomainException('Este empréstimo já tem devoluções registradas, então só dá para mudar a descrição e as observações. Para mudar '.implode(', ', $changed).', estorne antes as devoluções no histórico.');
                }
                $loan->update(['description' => $data['description'], 'notes' => $data['notes'] ?? null]);
                if ($loan->disbursement) {
                    app(TransactionService::class)->updateGenerated($loan->disbursement, [
                        'description' => mb_substr('Empréstimo concedido · '.$loan->contact->name.' · '.$data['description'], 0, 200),
                        'notes' => $data['notes'] ?? null,
                    ]);
                }
                $this->invalidate();

                return $loan->fresh(['installments', 'contact', 'bankAccount']);
            }

            $contact = (int) $data['contact_id'] === (int) $loan->contact_id ? $loan->contact : Contact::active()->find($data['contact_id']);
            if (! $contact) {
                throw new \DomainException('Selecione um contato ativo.');
            }
            $account = (int) $data['bank_account_id'] === (int) $loan->bank_account_id
                ? BankAccount::withTrashed()->findOrFail($loan->bank_account_id)
                : $this->account((int) $data['bank_account_id']);

            $loan->update([
                'contact_id' => $contact->id,
                'bank_account_id' => $account->id,
                'description' => $data['description'],
                'principal' => $this->money($principal),
                'date' => $data['date'],
                'installment_count' => $count,
                'first_due_date' => $firstDue,
                'interest_rate_am' => $rate,
                'notes' => $data['notes'] ?? null,
            ]);
            $loan->installments()->delete();
            $this->buildInstallments($loan, $principal, $count, $firstDue);

            $values = [
                'date' => $data['date'],
                'amount' => $this->money($principal),
                'description' => mb_substr('Empréstimo concedido · '.$contact->name.' · '.$data['description'], 0, 200),
                'bank_account_id' => $account->id,
                'notes' => $data['notes'] ?? null,
            ];
            if ($loan->disbursement) {
                app(TransactionService::class)->updateGenerated($loan->disbursement, $values);
            } else {
                $out = app(TransactionService::class)->create($values + [
                    'type' => 'expense', 'status' => 'settled', 'category_id' => Category::loanDisbursementCategoryId(),
                    'source_type' => Loan::class, 'source_id' => $loan->id,
                ]);
                $loan->update(['disbursement_transaction_id' => $out->id]);
            }
            $this->invalidate();

            return $loan->fresh(['installments', 'contact', 'bankAccount']);
        });
    }

    /**
     * Prévia de uma devolução de vários empréstimos da mesma pessoa.
     * Automático: do mais antigo para o mais novo. Manual: valor por empréstimo ($manual = [loan_id => centavos]).
     *
     * @param  list<int>  $loanIds
     * @param  array<int, int>|null  $manual
     * @return array{rows: list<array{loan: Loan, balance: array, open: int, applied: int, remaining: int}>, total: int, applied: int, left: int}
     */
    public function planBatch(int $contactId, int $cents, string $date, array $loanIds, ?array $manual = null): array
    {
        $loanIds = array_values(array_unique(array_map('intval', $loanIds)));
        $loans = Loan::with(['installments.settlements', 'settlements'])->where('contact_id', $contactId)
            ->whereIn('id', $loanIds)->orderBy('date')->orderBy('id')->get();
        if ($loans->count() !== count($loanIds)) {
            throw new \DomainException('Selecione só empréstimos desta pessoa.');
        }
        $left = $cents;
        $rows = [];
        foreach ($loans as $loan) {
            if ($date < $loan->date->toDateString()) {
                throw new \DomainException('A data da devolução é anterior ao empréstimo "'.$loan->description.'" de '.$loan->date->format('d/m/Y').'.');
            }
            $balance = $loan->balanceAsOf($date);
            $open = $balance['balance'];
            if ($manual !== null) {
                $applied = max(0, (int) ($manual[$loan->id] ?? 0));
                if ($applied > $open) {
                    throw new \DomainException('O valor informado para "'.$loan->description.'" passa do saldo dele (R$ '.number_format($open / 100, 2, ',', '.').').');
                }
            } else {
                $applied = max(0, min($left, $open));
            }
            $left -= $applied;
            $rows[] = ['loan' => $loan, 'balance' => $balance, 'open' => $open, 'applied' => $applied, 'remaining' => $open - $applied];
        }

        return ['rows' => $rows, 'total' => $cents, 'applied' => $cents - $left, 'left' => $left];
    }

    /**
     * Uma devolução (um só lançamento de entrada) abatendo vários empréstimos da mesma pessoa.
     * Principal recuperado não é receita; juros recebidos vão para as baixas (interest_amount) e só eles entram no resultado.
     *
     * @param  list<int>  $loanIds
     * @param  array<int, string>|null  $manual  [loan_id => "123.45"]
     */
    public function receiveBatch(int $contactId, string $amount, string $date, int $accountId, string $key, array $loanIds, ?array $manual = null, ?string $notes = null): Transaction
    {
        Validator::make(['key' => $key, 'date' => $date], [
            'key' => 'required|uuid',
            'date' => 'required|date_format:Y-m-d|before_or_equal:'.\App\Support\BusinessDate::today(),
        ])->validate();
        if ($loanIds === []) {
            throw new \DomainException('Marque pelo menos um empréstimo.');
        }

        return DB::transaction(function () use ($contactId, $amount, $date, $accountId, $key, $loanIds, $manual, $notes) {
            if ($existing = LoanSettlement::where('batch_key', $key)->first()) {
                return $existing->transaction;
            }
            Loan::whereIn('id', $loanIds)->lockForUpdate()->get();
            $contact = Contact::withTrashed()->findOrFail($contactId);
            $account = $this->account($accountId);
            $cents = $this->cents($amount);
            $manualCents = $manual === null ? null : collect($manual)
                ->map(fn ($value) => trim((string) $value) === '' ? 0 : $this->cents((string) $value, true))->all();
            $plan = $this->planBatch($contactId, $cents, $date, $loanIds, $manualCents);
            if ($plan['left'] > 0) {
                throw new \DomainException('Sobram R$ '.number_format($plan['left'] / 100, 2, ',', '.').' do valor recebido. Marque mais empréstimos ou ajuste os valores.');
            }
            if ($plan['left'] < 0) {
                throw new \DomainException('A soma dos valores por empréstimo passa do valor recebido em R$ '.number_format(-$plan['left'] / 100, 2, ',', '.').'.');
            }

            $rows = [];
            $interest = 0;
            foreach ($plan['rows'] as $row) {
                if ($row['applied'] <= 0) {
                    continue;
                }
                $balance = $row['balance'];
                $split = SimpleInterest::allocate($row['applied'], $balance['principal_open'], $balance['interest_open'], $balance['penalty_open']);
                $charges = $split['interest'] + $split['penalty'];
                $rows = array_merge($rows, $this->allocatePrincipal($row['loan'], $split['principal'], $date, $key, null, $charges, $notes));
                $interest += $charges;
            }
            if ($rows === []) {
                throw new \DomainException('Informe quanto abater em pelo menos um empréstimo.');
            }
            $count = collect($rows)->pluck('loan_id')->unique()->count();
            $transaction = app(TransactionService::class)->create([
                'type' => 'income',
                'status' => 'settled',
                'date' => $date,
                'amount' => $this->money($cents),
                'bank_account_id' => $account->id,
                'description' => mb_substr('Devolução de empréstimos · '.$contact->name.' · '.$count.' '.($count === 1 ? 'empréstimo' : 'empréstimos'), 0, 200),
                'category_id' => $interest > 0 ? Category::loanInterestCategoryId() : null,
                'source_type' => LoanSettlement::class,
                'source_id' => $rows[0]->id,
                'notes' => $notes,
            ]);
            foreach ($rows as $row) {
                $row->update(['transaction_id' => $transaction->id, 'transaction_created' => true]);
            }
            $this->invalidate();

            return $transaction;
        });
    }

    public function receive(Loan $loan, string $amount, string $date, int $accountId, string $key, ?int $installmentId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($loan, $amount, $date, $accountId, $key, $installmentId, $notes) {
            $loan = Loan::with(['installments.settlements', 'settlements', 'contact'])->lockForUpdate()->findOrFail($loan->id);
            if ($this->repeated($key, $loan)) {
                return;
            }
            Validator::make(['date' => $date], [
                'date' => 'required|date_format:Y-m-d|before_or_equal:'.\App\Support\BusinessDate::today().'|after_or_equal:'.$loan->date->toDateString(),
            ])->validate();
            $account = $this->account($accountId);
            $cents = $this->cents($amount);
            $balance = $loan->balanceAsOf($date);
            $split = SimpleInterest::allocate($cents, $balance['principal_open'], $balance['interest_open'], $balance['penalty_open']);

            $rows = $this->allocatePrincipal($loan, $split['principal'], $date, $key, $installmentId, $split['interest'], $notes);
            $transaction = app(TransactionService::class)->create([
                'type' => 'income',
                'status' => 'settled',
                'date' => $date,
                'amount' => $this->money($cents),
                'bank_account_id' => $account->id,
                'description' => mb_substr('Recebimento de empréstimo · '.$loan->contact->name.' · '.$loan->description, 0, 200),
                'category_id' => $split['interest'] > 0 ? Category::loanInterestCategoryId() : null,
                'source_type' => LoanSettlement::class,
                'source_id' => $rows[0]->id,
                'notes' => $notes,
            ]);
            foreach ($rows as $row) {
                $row->update(['transaction_id' => $transaction->id, 'transaction_created' => true]);
            }
            $this->invalidate();
        });
    }

    public function undo(Loan $loan, string $key): void
    {
        DB::transaction(function () use ($loan, $key) {
            $rows = LoanSettlement::where('batch_key', $key)->get();
            if ($rows->isEmpty()) {
                return;
            }
            if (! $rows->contains(fn ($row) => $row->loan_id === $loan->id)) {
                abort(404);
            }
            // Devolução conjunta: estornar desfaz a devolução inteira (todos os empréstimos e o lançamento único).
            Loan::whereIn('id', $rows->pluck('loan_id')->unique())->lockForUpdate()->get();
            $transaction = $rows->first()->transaction;
            $created = $rows->first()->transaction_created;
            foreach ($rows as $row) {
                $row->delete();
            }
            if ($transaction && $created) {
                app(TransactionService::class)->deleteGenerated($transaction);
            } elseif ($transaction) {
                $transaction->touch();
            }
            $this->invalidate();
        });
    }

    public function detach(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);
            if ($loan->settlements()->exists()) {
                throw new \DomainException('Este empréstimo tem devoluções registradas. Estorne as devoluções no histórico antes de excluir.');
            }
            $out = $loan->disbursement;
            $loan->deleteAttachment();
            $loan->installments()->delete();
            $loan->delete();
            if ($out) {
                app(TransactionService::class)->deleteGenerated($out);
            }
            $this->invalidate();
        });
    }

    public function storeAttachment(Loan $loan, UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->guessExtension());
        if (! in_array($mime, TransactionAttachmentService::ALLOWED_MIMES, true) || ! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            throw new \DomainException('O comprovante deve ser PDF, JPG ou PNG.');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \DomainException('O comprovante deve ter no máximo 10 MB.');
        }
        if ($loan->attachment_path) {
            Storage::disk(self::ATTACHMENT_DISK)->delete($loan->attachment_path);
        }
        $path = $file->storeAs('emprestimos/'.$loan->id, Str::uuid().'.'.$extension, self::ATTACHMENT_DISK);
        $loan->forceFill([
            'attachment_path' => $path,
            'attachment_original_name' => mb_substr($file->getClientOriginalName() ?: 'comprovante.'.$extension, 0, 200),
            'attachment_mime' => $mime,
            'attachment_size' => (int) $file->getSize(),
        ])->save();
    }

    /** @return list<LoanSettlement> */
    private function allocatePrincipal(Loan $loan, int $principalCents, string $date, string $key, ?int $installmentId, int $interestCents, ?string $notes): array
    {
        $parts = $loan->installments()->with('settlements')
            ->when($installmentId, fn ($q) => $q->whereKey($installmentId))
            ->orderBy('number')->lockForUpdate()->get();
        if ($principalCents > $parts->sum(fn ($part) => $part->remainingPrincipalCents())) {
            throw new \DomainException('O principal da baixa não pode ultrapassar o saldo das parcelas selecionadas.');
        }
        $rows = [];
        $left = $principalCents;
        $interestLeft = $interestCents;
        foreach ($parts as $part) {
            $portion = min($left, $part->remainingPrincipalCents());
            $interestShare = $rows === [] ? $interestLeft : 0;
            if (! $portion && ! $interestShare) {
                continue;
            }
            $rows[] = $loan->settlements()->create([
                'loan_installment_id' => $part->id,
                'batch_key' => $key,
                'amount' => $this->money($portion + $interestShare),
                'principal_amount' => $this->money($portion),
                'interest_amount' => $this->money($interestShare),
                'date' => $date,
                'notes' => $notes,
            ]);
            $left -= $portion;
            $interestLeft -= $interestShare;
            if ($left === 0 && $interestLeft === 0) {
                break;
            }
        }
        if ($interestLeft > 0 && $rows === []) {
            // Só juros (principal já quitado nas parcelas mas juros em aberto) — grava na primeira parcela.
            $part = $loan->installments()->orderBy('number')->firstOrFail();
            $rows[] = $loan->settlements()->create([
                'loan_installment_id' => $part->id,
                'batch_key' => $key,
                'amount' => $this->money($interestLeft),
                'principal_amount' => $this->money(0),
                'interest_amount' => $this->money($interestLeft),
                'date' => $date,
                'notes' => $notes,
            ]);
        }

        return $rows;
    }

    private function account(int $id): BankAccount
    {
        $account = BankAccount::active()->where('type', '!=', 'repasse')->find($id);
        if (! $account) {
            throw new \DomainException('Selecione uma conta bancária ativa, diferente de uma conta de repasse.');
        }

        return $account;
    }

    private function repeated(string $key, Loan $loan): bool
    {
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();
        $existing = LoanSettlement::where('batch_key', $key)->first();
        if ($existing && $existing->loan_id !== $loan->id) {
            throw new \DomainException('Esta confirmação já foi utilizada em outro recebimento.');
        }

        return $existing !== null;
    }

    public function invalidate(): void
    {
        Cache::forget('banking.dashboard.summary');
        Cache::forget('banking.dashboard.summary.v2');
        Cache::forget('banking.dashboard.summary.v2.cash');
        Cache::forget('banking.dashboard.summary.v2.accrual');
        app(DashboardSnapshotService::class)->invalidate();
    }
}
