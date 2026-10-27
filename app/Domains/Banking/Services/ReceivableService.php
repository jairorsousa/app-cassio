<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\ReceivableSettlement;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReceivableService
{
    public function cents(string $amount, bool $zero = false): int
    {
        return app(ReceiptDestinationService::class)->cents($amount, $zero);
    }

    public function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** One actual payment can be shared by several people; its remainder is the owner's expense. */
    public function create(array $data): array
    {
        Validator::make($data, [
            'origin' => 'required|in:bank,card,existing', 'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'description' => 'required|string|max:200', 'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'bank_account_id' => 'nullable|integer', 'credit_card_id' => 'nullable|integer', 'transaction_id' => 'nullable|integer',
            'installments' => 'required|integer|min:1|max:120', 'repayment_mode' => 'required|in:dated,process,undated',
            'first_due_date' => ['nullable', Rule::requiredIf(($data['repayment_mode'] ?? '') === 'dated' && empty($data['use_invoice_dates'])), 'date_format:Y-m-d'],
            'use_invoice_dates' => 'sometimes|boolean',
            'writ_id' => 'nullable|integer|exists:writs,id', 'process_reference' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:5000', 'request_key' => 'required|uuid',
            'people' => 'required|array|min:1|max:20', 'people.*.contact_id' => 'required|integer|distinct',
            'people.*.amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ])->validate();

        return DB::transaction(function () use ($data) {
            if ($existing = Receivable::where('request_key', $data['request_key'])->first()) {
                return [$existing];
            }
            // Serialize classifications of the same payment, including all its card installments.
            if ($data['origin'] === 'existing') {
                $first = Transaction::findOrFail($data['transaction_id'] ?? null);
                $sources = Transaction::with('invoice')->when($first->installment_group_id,
                    fn ($q) => $q->where('installment_group_id', $first->installment_group_id),
                    fn ($q) => $q->whereKey($first->id))->orderBy('installment_number')->orderBy('id')->lockForUpdate()->get();
                foreach ($sources as $source) {
                    $this->eligibleOrigin($source);
                }
                $data['date'] = min($sources->min(fn ($source) => $source->date->toDateString()), today()->toDateString());
            } elseif ($data['origin'] === 'card') {
                $card = CreditCard::where('status', true)->find($data['credit_card_id'] ?? null);
                if (! $card) {
                    throw new \DomainException('Selecione um cartão ativo.');
                }
                $sources = collect(app(InstallmentService::class)->split($card, Carbon::parse($data['date']), (float) $data['amount'], (int) $data['installments'], $data['description'], notes: $data['notes'] ?? null));
            } else {
                $account = $this->account($data['bank_account_id'] ?? null);
                $this->cents($data['amount']);
                $sources = collect([app(TransactionService::class)->create([
                    'type' => 'expense', 'status' => 'settled', 'date' => $data['date'], 'amount' => $data['amount'],
                    'description' => $data['description'], 'bank_account_id' => $account->id, 'notes' => $data['notes'] ?? null,
                ])]);
            }
            $capacity = $sources->mapWithKeys(fn ($source) => [$source->id => $this->cents((string) $source->amount) - $source->receivableInstallments()->get()->sum(fn ($part) => $part->amountCents())])->all();
            if (($data['repayment_mode'] ?? '') === 'dated' && ! $sources->first()->credit_card_id && empty($data['first_due_date'])) {
                throw new \DomainException('Informe o primeiro vencimento das devoluções.');
            }
            $created = [];
            foreach (array_values($data['people']) as $index => $person) {
                $contact = Contact::active()->find($person['contact_id']);
                if (! $contact) {
                    throw new \DomainException('Selecione um contato ativo para cada pessoa.');
                }
                $amount = $this->cents((string) $person['amount']);
                $available = array_sum($capacity);
                if ($amount > $available) {
                    throw new \DomainException('A soma dos valores de terceiros não pode superar o valor disponível do lançamento.');
                }
                $receivable = Receivable::create([
                    'contact_id' => $contact->id, 'description' => $data['description'], 'date' => $data['date'],
                    'repayment_mode' => $data['repayment_mode'], 'writ_id' => ($data['writ_id'] ?? null) ?: null,
                    'process_reference' => $data['process_reference'] ?? null, 'notes' => $data['notes'] ?? null,
                    'request_key' => $index === 0 ? $data['request_key'] : null,
                ]);
                $left = $amount;
                $number = 0;
                foreach ($sources as $sourceIndex => $source) {
                    $share = $available > 0 ? BigInteger::of($left)->multipliedBy($capacity[$source->id])->quotient($available)->toInt() : 0;
                    $available -= $capacity[$source->id];
                    $capacity[$source->id] -= $share;
                    $left -= $share;
                    if (! $share) {
                        continue;
                    }
                    $count = $source->credit_card_id ? 1 : (int) $data['installments'];
                    $base = intdiv($share, $count);
                    if ($base === 0) {
                        throw new \DomainException('O valor de cada parcela deve ser de pelo menos um centavo.');
                    }
                    for ($i = 1; $i <= $count; $i++) {
                        $due = null;
                        if ($data['repayment_mode'] === 'dated') {
                            $due = $source->credit_card_id && $data['origin'] !== 'bank'
                                ? (! empty($data['use_invoice_dates']) ? $source->invoice->due_date : Carbon::parse($data['first_due_date'])->addMonthsNoOverflow($sourceIndex))
                                : Carbon::parse($data['first_due_date'])->addMonthsNoOverflow($number);
                        }
                        $receivable->installments()->create([
                            'transaction_id' => $source->id, 'number' => ++$number,
                            'amount' => $this->money($base + ($i === $count ? $share % $count : 0)), 'due_date' => $due,
                        ]);
                    }
                }
                $created[] = $receivable;
            }
            foreach ($sources as $source) {
                $source->touch();
            }
            $this->invalidate();

            return $created;
        });
    }

    private function eligibleOrigin(Transaction $source): void
    {
        if ($source->type !== 'expense' || $source->status !== 'settled' || $source->isReadOnly() || (float) $source->amount <= 0
            || (! $source->bank_account_id && ! $source->credit_card_invoice_id) || $source->bankAccount?->type === 'repasse'
            || $source->destinationPayment()->exists() || BrokerCommissionPayment::where('transaction_id', $source->id)->exists()) {
            throw new \DomainException('Selecione uma saída bancária paga ou compra no cartão, sem vínculo com repasses ou outros módulos.');
        }
    }

    private function account(?int $id): BankAccount
    {
        $account = BankAccount::active()->where('type', '!=', 'repasse')->find($id);
        if (! $account) {
            throw new \DomainException('Selecione uma conta bancária ativa, diferente de uma conta de repasse.');
        }

        return $account;
    }

    /** Optional existing income avoids duplicating an imported repayment. */
    public function receive(Receivable $receivable, string $amount, string $date, int $accountId, string $key, ?int $existingId = null, ?int $installmentId = null): void
    {
        DB::transaction(function () use ($receivable, $amount, $date, $accountId, $key, $existingId, $installmentId) {
            $receivable = Receivable::lockForUpdate()->findOrFail($receivable->id);
            if ($this->repeated($key, $receivable)) {
                return;
            }
            $this->validateDate($receivable, $date);
            $account = $this->account($accountId);
            $cents = $this->cents($amount);
            $transaction = null;
            if ($existingId) {
                $transaction = Transaction::lockForUpdate()->findOrFail($existingId);
                if ($transaction->type !== 'income' || $transaction->status !== 'settled' || $transaction->isReadOnly() || $transaction->bank_account_id !== $account->id
                    || $transaction->credit_card_id || $transaction->destinations()->exists() || $transaction->receivableSettlements()->exists()
                    || $transaction->date->toDateString() !== $date || $this->cents((string) $transaction->amount) !== $cents) {
                    throw new \DomainException('Vincule uma entrada recebida, livre, com a mesma conta, data e valor da devolução.');
                }
            }
            $rows = $this->allocateSettlement($receivable, $cents, $date, 'cash', $key, $installmentId);
            if (! $transaction) {
                $transaction = app(TransactionService::class)->create([
                    'type' => 'income', 'status' => 'settled', 'date' => $date, 'amount' => $this->money($cents), 'bank_account_id' => $accountId,
                    'description' => mb_substr('Devolução · '.$receivable->contact->name.' · '.$receivable->description, 0, 200),
                    'source_type' => ReceivableSettlement::class, 'source_id' => $rows[0]->id,
                ]);
            }
            foreach ($rows as $row) {
                $row->update(['transaction_id' => $transaction->id, 'transaction_created' => ! $existingId]);
            }
            $transaction->touch();
            $this->invalidate();
        });
    }

    public function writeOff(Receivable $receivable, string $amount, string $date, string $key, string $reason): void
    {
        DB::transaction(function () use ($receivable, $amount, $date, $key, $reason) {
            $receivable = Receivable::lockForUpdate()->findOrFail($receivable->id);
            if ($this->repeated($key, $receivable)) {
                return;
            }
            $this->validateDate($receivable, $date);
            Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:1000'])->validate();
            $cents = $this->cents($amount);
            $rows = $this->allocateSettlement($receivable, $cents, $date, 'loss', $key);
            $entry = app(TransactionService::class)->create([
                'type' => 'expense', 'status' => 'settled', 'amount' => $this->money($cents), 'date' => $date,
                'description' => mb_substr('Perda reconhecida · '.$receivable->description, 0, 200),
                'source_type' => ReceivableSettlement::class, 'source_id' => $rows[0]->id, 'notes' => $reason,
            ]);
            foreach ($rows as $row) {
                $row->update(['transaction_id' => $entry->id, 'transaction_created' => true, 'notes' => $reason]);
            }
            $this->invalidate();
        });
    }

    public function offset(Receivable $receivable, ReceiptDestination $destination, string $amount, string $key): void
    {
        DB::transaction(function () use ($receivable, $destination, $amount, $key) {
            $receipt = Transaction::lockForUpdate()->findOrFail($destination->receipt_id);
            app(ReceiptDestinationService::class)->eligible($receipt);
            $destination = ReceiptDestination::lockForUpdate()->findOrFail($destination->id);
            $receivable = Receivable::lockForUpdate()->findOrFail($receivable->id);
            if ($this->repeated($key, $receivable)) {
                return;
            }
            if ($destination->kind !== 'client' || $destination->contact_id !== $receivable->contact_id) {
                throw new \DomainException('A compensação exige uma parte de cliente vinculada ao mesmo contato.');
            }
            $this->validateDate($receivable, $receipt->date->toDateString());
            $cents = $this->cents($amount);
            if ($cents > $destination->remainingCents()) {
                throw new \DomainException('O desconto não pode ultrapassar o valor ainda disponível para o cliente.');
            }
            $rows = $this->allocateSettlement($receivable, $cents, $receipt->date->toDateString(), 'offset', $key);
            foreach ($rows as $row) {
                $row->update(['destination_id' => $destination->id]);
            }
            if ($destination->repasse_account_id) {
                app(ReceiptAccountAllocationService::class)->sync($destination->fresh(), $destination->repasseAccount()->firstOrFail());
            }
            $receipt->touch();
            $this->invalidate();
        });
    }

    private function validateDate(Receivable $receivable, string $date): void
    {
        Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$receivable->date->toDateString()])->validate();
    }

    private function repeated(string $key, Receivable $receivable): bool
    {
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();
        $existing = ReceivableSettlement::where('batch_key', $key)->first();
        if ($existing && $existing->receivableInstallment->receivable_id !== $receivable->id) {
            throw new \DomainException('Esta confirmação já foi utilizada em outro recebimento.');
        }

        return $existing !== null;
    }

    private function allocateSettlement(Receivable $receivable, int $amount, string $date, string $kind, string $key, ?int $installmentId = null): array
    {
        $parts = $receivable->installments()->with('settlements')->when($installmentId, fn ($q) => $q->whereKey($installmentId))->orderBy('number')->lockForUpdate()->get();
        if ($amount > $parts->sum(fn ($part) => $part->remainingCents())) {
            throw new \DomainException('O recebimento não pode ultrapassar o saldo em aberto.');
        }
        $rows = [];
        foreach ($parts as $part) {
            $portion = min($amount, $part->remainingCents());
            if (! $portion) {
                continue;
            }
            $rows[] = $part->settlements()->create(['batch_key' => $key, 'kind' => $kind, 'amount' => $this->money($portion), 'date' => $date]);
            $amount -= $portion;
            if (! $amount) {
                break;
            }
        }

        return $rows;
    }

    /** Undo an entire confirmation, including a payment covering several installments. */
    public function undo(Receivable $receivable, string $key): void
    {
        DB::transaction(function () use ($receivable, $key) {
            $rows = ReceivableSettlement::where('batch_key', $key)->get();
            if ($rows->isEmpty()) {
                return;
            }
            if ($rows->contains(fn ($row) => $row->receivableInstallment->receivable_id !== $receivable->id)) {
                abort(404);
            }
            $destination = $rows->first()->destination;
            if ($destination) {
                Transaction::lockForUpdate()->findOrFail($destination->receipt_id);
            }
            Receivable::lockForUpdate()->findOrFail($receivable->id);
            $transaction = $rows->first()->transaction;
            $created = $rows->first()->transaction_created;
            foreach ($rows as $row) {
                $row->delete();
            }
            if ($transaction) {
                if ($created) {
                    app(TransactionService::class)->deleteGenerated($transaction);
                } else {
                    $transaction->touch();
                }
            }
            if ($destination) {
                if ($destination->repasse_account_id) {
                    app(ReceiptAccountAllocationService::class)->sync($destination->fresh(), $destination->repasseAccount()->firstOrFail());
                }
                $destination->receipt->touch();
            }
            $this->invalidate();
        });
    }

    public function reschedule(Receivable $receivable, array $dates): void
    {
        DB::transaction(function () use ($receivable, $dates) {
            $receivable = Receivable::lockForUpdate()->findOrFail($receivable->id);
            Validator::make(['dates' => $dates], ['dates' => 'array', 'dates.*' => 'nullable|date_format:Y-m-d|after_or_equal:'.$receivable->date->toDateString()])->validate();
            foreach ($receivable->installments()->with('settlements')->get() as $part) {
                if ($part->remainingCents() && array_key_exists($part->id, $dates)) {
                    $part->update(['due_date' => $dates[$part->id] ?: null]);
                }
            }
            if ($receivable->installments()->whereNotNull('due_date')->exists()) {
                $receivable->update(['repayment_mode' => 'dated']);
            } elseif ($receivable->repayment_mode === 'dated') {
                $receivable->update(['repayment_mode' => 'undated']);
            }
            $receivable->touch();
        });
    }

    public function detach(Receivable $receivable): void
    {
        DB::transaction(function () use ($receivable) {
            $receivable = Receivable::lockForUpdate()->findOrFail($receivable->id);
            if ($receivable->settlements()->exists()) {
                throw new \DomainException('Estorne os recebimentos e compensações antes de remover este controle.');
            }
            $sources = $receivable->installments->pluck('transaction_id')->unique();
            $receivable->delete();
            foreach (Transaction::whereIn('id', $sources)->get() as $source) {
                $source->touch();
            }
            $this->invalidate();
        });
    }

    public function invalidate(): void
    {
        Cache::forget('banking.dashboard.summary');
        // Classification can change the own share of invoice payments from other months.
        app(DashboardSnapshotService::class)->invalidate();
    }
}
