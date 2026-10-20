<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\ProcessReceipt;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Brokers\Models\ProcessExpenseRecovery;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerProfileService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProcessReceiptService
{
    public function __construct(private ReceiptDestinationService $destinations) {}

    public function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function portion(string $value, string $mode, int $base): int
    {
        $value = $this->destinations->cents($value, true);
        if ($mode === 'percent') {
            if ($value > 10000) {
                throw new \DomainException('O percentual não pode ultrapassar 100%.');
            }

            return BigInteger::of($base)->multipliedBy($value)->plus(5000)->quotient(10000)->toInt();
        }
        if ($mode !== 'amount') {
            throw new \DomainException('Selecione valor ou percentual.');
        }

        return $value;
    }

    /** All percentages for commissions are based on fees, never on the client's share. */
    public function calculate(Transaction $receipt, array $data): array
    {
        $total = $this->destinations->cents((string) $receipt->amount);
        $fees = $this->portion((string) ($data['fees'] ?? '0'), $data['fees_mode'] ?? 'amount', $total);
        $broker = $this->portion((string) ($data['broker_amount'] ?? '0'), $data['commission_mode'] ?? 'amount', $fees);
        $office = $this->portion((string) ($data['office_amount'] ?? '0'), $data['commission_mode'] ?? 'amount', $fees);
        if (($data['commission_mode'] ?? 'amount') === 'percent') {
            $brokerPercent = $this->destinations->cents((string) ($data['broker_amount'] ?? '0'), true);
            $officePercent = $this->destinations->cents((string) ($data['office_amount'] ?? '0'), true);
            if ($brokerPercent + $officePercent > 10000) {
                throw new \DomainException('As comissões não podem superar 100% dos honorários.');
            }
            if ($fees > 0) {
                // Distribute leftover cents deterministically, keeping the exact total.
                $parts = app(TransactionAllocationService::class)->fromPercentages($this->money($fees), [
                    ['percentage' => $this->money($brokerPercent)],
                    ['percentage' => $this->money($officePercent)],
                    ['percentage' => $this->money(10000 - $brokerPercent - $officePercent)],
                ], requireComplete: false);
                $broker = $this->destinations->cents($parts[0]['amount'], true);
                $office = $this->destinations->cents($parts[1]['amount'], true);
            }
        }
        if ($fees > $total || $broker + $office > $fees) {
            throw new \DomainException('Os honorários não podem superar a entrada, e as comissões não podem superar os honorários.');
        }

        return ['total' => $total, 'fees' => $fees, 'client' => $total - $fees, 'broker' => $broker, 'office' => $office, 'own' => $fees - $broker - $office];
    }

    public function save(Transaction $receipt, array $data): void
    {
        Validator::make($data, [
            'broker_id' => ['required', 'integer', Rule::exists('brokers', 'id')->where('status', true)->whereNull('deleted_at')],
            'case_type_id' => ['nullable', 'integer', Rule::exists('case_types', 'id')->where('status', true)],
            'commission_name' => 'nullable|string|max:160',
            'client_name' => 'required|string|max:200', 'office_name' => 'nullable|string|max:200',
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('type', 'income')->where('status', true)],
            'fees_mode' => 'required|in:amount,percent', 'commission_mode' => 'required|in:amount,percent',
            'fees' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'broker_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'office_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'expenses' => 'present|array|max:200', 'advances' => 'present|array|max:200',
            'expenses.*' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'advances.*' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ])->validate();
        DB::transaction(function () use ($receipt, $data) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $this->destinations->eligible($receipt);
            $broker = Broker::lockForUpdate()->findOrFail($data['broker_id']);
            $contact = app(BrokerProfileService::class)->contactForBroker($broker);
            if (! $contact || ! $contact->status) {
                throw new \DomainException('Selecione um contato ativo do tipo corretor.');
            }
            $values = $this->calculate($receipt, $data);
            if ($values['office'] > 0 && trim($data['office_name'] ?? '') === '') {
                throw new \DomainException('Informe o nome do escritório.');
            }
            $rows = [
                ['kind' => 'client', 'beneficiary' => trim($data['client_name']), 'amount' => $this->money($values['client'])],
                ['kind' => 'own', 'amount' => $this->money($values['own'])],
            ];
            if ($values['broker'] > 0) {
                if (empty($data['case_type_id'])) {
                    throw ValidationException::withMessages(['case_type_id' => 'Selecione o tipo de caso da comissão do corretor.']);
                }
                $rows[] = [
                    'kind' => 'broker', 'broker_id' => $broker->id, 'case_type_id' => $data['case_type_id'],
                    'commission_name' => trim($data['commission_name'] ?? '') ?: mb_substr(trim($data['client_name']), 0, 160),
                    'amount' => $this->money($values['broker']),
                ];
            }
            if ($values['office'] > 0) {
                $rows[] = ['kind' => 'office', 'beneficiary' => trim($data['office_name']), 'amount' => $this->money($values['office'])];
            }
            $this->destinations->replace($receipt, $rows, automaticAdvances: false);
            ProcessReceipt::create([
                'receipt_id' => $receipt->id, 'broker_id' => $broker->id,
                'fees_amount' => $this->money($values['fees']), 'category_id' => $data['category_id'] ?? null,
            ]);
            $client = $receipt->destinations()->where('kind', 'client')->sole();
            $recovered = 0;
            $expenses = $data['expenses'];
            ksort($expenses);
            foreach ($expenses as $id => $amount) {
                if ($amount === '' || $amount === null) {
                    continue;
                }
                $cents = $this->destinations->cents((string) $amount, true);
                if (! $cents) {
                    continue;
                }
                $expense = BrokerProcessExpense::where('broker_id', $broker->id)->lockForUpdate()->find($id);
                if (! $expense || $cents > $expense->remainingCents()) {
                    throw new \DomainException('Uma despesa selecionada não pertence ao corretor ou não possui saldo suficiente. Atualize os valores.');
                }
                $recovered += $cents;
                if ($recovered > $values['client']) {
                    throw new \DomainException('As despesas descontadas não podem superar o valor do cliente.');
                }
                $client->recoveries()->create(['expense_id' => $expense->id, 'amount' => $this->money($cents)]);
            }
            $commission = $receipt->destinations()->where('kind', 'broker')->first()?->commission;
            $offset = 0;
            $advances = $data['advances'];
            ksort($advances);
            foreach ($advances as $id => $amount) {
                if ($amount === '' || $amount === null) {
                    continue;
                }
                $cents = $this->destinations->cents((string) $amount, true);
                if (! $cents) {
                    continue;
                }
                $advance = BrokerAdvance::where('broker_id', $broker->id)->lockForUpdate()->find($id);
                if (! $commission || ! $advance || $cents > (int) round(((float) $advance->amount - (float) $advance->settlements()->lockForUpdate()->get()->sum('amount_offset')) * 100)) {
                    throw new \DomainException('Um adiantamento selecionado não pertence ao corretor ou não possui saldo suficiente.');
                }
                $offset += $cents;
                if ($offset > $values['broker']) {
                    throw new \DomainException('Os adiantamentos não podem superar a comissão do corretor.');
                }
                BrokerCommissionSettlement::create([
                    'commission_id' => $commission->id, 'advance_id' => $advance->id,
                    'amount_offset' => $this->money($cents), 'settled_at' => $receipt->date,
                ]);
            }
            if ($commission) {
                app(BrokerCommissionService::class)->syncStatus($commission->fresh());
            }
            $receipt->touch();
        });
    }

    public function undoRecovery(Transaction $receipt, int $id): void
    {
        DB::transaction(function () use ($receipt, $id) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $recovery = ProcessExpenseRecovery::whereHas('destination', fn ($q) => $q->where('receipt_id', $receipt->id))->findOrFail($id);
            BrokerProcessExpense::lockForUpdate()->findOrFail($recovery->expense_id);
            $recovery->delete();
            $receipt->touch();
        });
    }

    public function undoAdvance(Transaction $receipt, int $id): void
    {
        DB::transaction(function () use ($receipt, $id) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $ids = $receipt->destinations()->pluck('commission_id');
            $settlement = BrokerCommissionSettlement::whereIn('commission_id', $ids)->findOrFail($id);
            $commission = BrokerCommission::lockForUpdate()->findOrFail($settlement->commission_id);
            BrokerAdvance::lockForUpdate()->findOrFail($settlement->advance_id);
            $settlement->delete();
            app(BrokerCommissionService::class)->syncStatus($commission->fresh());
            $receipt->touch();
        });
    }
}
