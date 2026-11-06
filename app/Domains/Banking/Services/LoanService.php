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

    public function create(array $data, ?UploadedFile $attachment = null): Loan
    {
        Validator::make($data, [
            'contact_id' => 'required|integer|exists:contacts,id',
            'bank_account_id' => 'required|integer',
            'description' => 'required|string|max:200',
            'principal' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'date' => 'required|date_format:Y-m-d|before_or_equal:'.\App\Support\BusinessDate::today(),
            'installments' => 'required|integer|min:1|max:120',
            'first_due_date' => 'nullable|date_format:Y-m-d|after_or_equal:date',
            'interest_rate_am' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:5000',
            'request_key' => 'required|uuid',
        ])->validate();

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
            if ($rows->contains(fn ($row) => $row->loan_id !== $loan->id)) {
                abort(404);
            }
            Loan::lockForUpdate()->findOrFail($loan->id);
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
                throw new \DomainException('Estorne os recebimentos antes de remover este empréstimo.');
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
