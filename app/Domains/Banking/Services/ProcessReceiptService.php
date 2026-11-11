<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\ProcessReceipt;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Models\ProcessExpenseRecovery;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

    /**
     * Linhas de corretores do recebimento. Formato novo: $data['brokers'] = [[broker_id, base (fees|gross), mode (percent|fixed), value, advances => [id => valor]]].
     * Formato antigo (um corretor: broker_id, broker_amount, commission_mode, advances) continua aceito.
     */
    public function brokerRows(array $data): array
    {
        if (array_key_exists('brokers', $data)) {
            return array_values(array_filter((array) $data['brokers'], fn ($row) => is_array($row) && ! empty($row['broker_id'])));
        }
        if (empty($data['broker_id'])) {
            return [];
        }

        return [[
            'broker_id' => $data['broker_id'], 'base' => 'fees',
            'mode' => ($data['commission_mode'] ?? 'amount') === 'percent' ? 'percent' : 'fixed',
            'value' => (string) ($data['broker_amount'] ?? '0'), 'advances' => $data['advances'] ?? [],
        ]];
    }

    /** Valor de um corretor: % sobre honorários, % sobre o valor bruto ou valor fixo (arredondamento meio para cima). */
    public function brokerAmount(array $row, int $fees, int $total): int
    {
        $base = ($row['base'] ?? 'fees') === 'gross' ? $total : $fees;
        $mode = ($row['mode'] ?? 'fixed') === 'percent' ? 'percent' : 'amount';
        if (! in_array($row['base'] ?? 'fees', ['fees', 'gross'], true) || ! in_array($row['mode'] ?? 'fixed', ['percent', 'fixed'], true)) {
            throw new \DomainException('Escolha a base (honorários ou valor bruto) e o modo (percentual ou fixo) de cada corretor.');
        }

        return $this->portion((string) ($row['value'] ?? '0'), $mode, $base);
    }

    /**
     * Linhas de honorário extra/outro. Valores vazios são ignorados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extrasRows(array $data): array
    {
        $rows = [];
        foreach ((array) ($data['fee_extras'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $amount = $row['amount'] ?? '';
            if ($amount === '' || $amount === null) {
                continue;
            }
            $rows[] = $row;
        }

        return array_values($rows);
    }

    /**
     * Totais dos extras em centavos: os que entram na base do escritório/corretor e os que não entram.
     *
     * @return array{in: int, out: int, total: int}
     */
    public function extrasTotals(array $data): array
    {
        $in = $out = 0;
        foreach ($this->extrasRows($data) as $row) {
            $cents = $this->destinations->cents((string) ($row['amount'] ?? '0'), true);
            if (! empty($row['in_commission_base'])) {
                $in += $cents;
            } else {
                $out += $cents;
            }
        }

        return ['in' => $in, 'out' => $out, 'total' => $in + $out];
    }

    /**
     * IDs das comissões geradas por este recebimento (usadas para tratar as compensações dele como disponíveis ao editar).
     *
     * @return array<int, int>
     */
    public function receiptCommissionIds(?Transaction $receipt): array
    {
        if (! $receipt || ! $receipt->exists) {
            return [];
        }

        return $receipt->destinations()->whereNotNull('commission_id')->pluck('commission_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Adiantamentos pessoais em aberto do corretor, do mais antigo para o mais novo, com o saldo disponível em centavos.
     * Na edição, o que este recebimento já compensou volta a contar como disponível (será refeito ao gravar).
     *
     * @return \Illuminate\Support\Collection<int, array{advance: BrokerAdvance, available: int}>
     */
    public function openAdvances(int $brokerId, ?Transaction $receipt = null, bool $lock = false): \Illuminate\Support\Collection
    {
        $own = $this->receiptCommissionIds($receipt);
        $query = BrokerAdvance::personal()->where('broker_id', $brokerId)->orderBy('date')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(function (BrokerAdvance $advance) use ($own) {
            $settled = BrokerCommissionSettlement::where('advance_id', $advance->id)
                ->when($own !== [], fn ($q) => $q->whereNotIn('commission_id', $own))->sum('amount_offset');

            return ['advance' => $advance, 'available' => max(0, (int) round(((float) $advance->amount - (float) $settled) * 100))];
        })->filter(fn ($row) => $row['available'] > 0)->values();
    }

    /**
     * Compensação automática: abate a comissão do corretor nos adiantamentos em aberto, do mais antigo para o mais novo,
     * até acabar a comissão ou os adiantamentos. Retorna [advance_id => centavos].
     *
     * @return array<int, int>
     */
    public function autoAllocation(int $brokerId, int $commissionCents, ?Transaction $receipt = null, bool $lock = false): array
    {
        $left = max(0, $commissionCents);
        $result = [];
        foreach ($this->openAdvances($brokerId, $receipt, $lock) as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $row['available']);
            $result[$row['advance']->id] = $take;
            $left -= $take;
        }

        return $result;
    }

    /** All percentages for commissions are based on fees, never on the client's share (salvo corretor com base no valor bruto). */
    public function calculate(Transaction $receipt, array $data): array
    {
        if (! array_key_exists('brokers', $data)) {
            return $this->calculateLegacy($receipt, $data);
        }
        $total = $this->destinations->cents((string) $receipt->amount);
        $fees = $this->portion((string) ($data['fees'] ?? '0'), $data['fees_mode'] ?? 'amount', $total);
        $extras = $this->extrasTotals($data);
        if ($fees + $extras['total'] > $total) {
            throw new \DomainException($extras['total'] > 0
                ? 'Os honorários e os extras não podem superar a entrada.'
                : 'Os honorários não podem superar a entrada.');
        }
        $base = $fees + $extras['in'];
        $office = $this->portion((string) ($data['office_amount'] ?? '0'), $data['commission_mode'] ?? 'amount', $base);
        $brokers = array_map(fn ($row) => $this->brokerAmount($row, $base, $total), $this->brokerRows($data));
        $sum = array_sum($brokers);
        if ($office + $sum > $base) {
            throw new \DomainException('Escritório + corretores (R$ '.number_format(($office + $sum) / 100, 2, ',', '.').') superam a base dos honorários (R$ '.number_format($base / 100, 2, ',', '.').'). Minha receita não pode ficar negativa.');
        }

        return [
            'total' => $total,
            'fees' => $fees,
            'extras' => $extras['total'],
            'extras_in_base' => $extras['in'],
            'commission_base' => $base,
            'fees_total' => $fees + $extras['total'],
            'client' => $total - $fees - $extras['total'],
            'broker' => $sum,
            'brokers' => $brokers,
            'office' => $office,
            'own' => $base - $sum - $office + $extras['out'],
        ];
    }

    /** All percentages for commissions are based on fees, never on the client's share. */
    private function calculateLegacy(Transaction $receipt, array $data): array
    {
        $total = $this->destinations->cents((string) $receipt->amount);
        $fees = $this->portion((string) ($data['fees'] ?? '0'), $data['fees_mode'] ?? 'amount', $total);
        $extras = $this->extrasTotals($data);
        $base = $fees + $extras['in'];
        $broker = empty($data['broker_id']) ? 0 : $this->portion((string) ($data['broker_amount'] ?? '0'), $data['commission_mode'] ?? 'amount', $base);
        $office = $this->portion((string) ($data['office_amount'] ?? '0'), $data['commission_mode'] ?? 'amount', $base);
        if (($data['commission_mode'] ?? 'amount') === 'percent') {
            $brokerPercent = empty($data['broker_id']) ? 0 : $this->destinations->cents((string) ($data['broker_amount'] ?? '0'), true);
            $officePercent = $this->destinations->cents((string) ($data['office_amount'] ?? '0'), true);
            if ($brokerPercent + $officePercent > 10000) {
                throw new \DomainException('As comissões não podem superar 100% dos honorários.');
            }
            if ($base > 0) {
                // Distribute leftover cents deterministically, keeping the exact total.
                $parts = app(TransactionAllocationService::class)->fromPercentages($this->money($base), [
                    ['percentage' => $this->money($brokerPercent)],
                    ['percentage' => $this->money($officePercent)],
                    ['percentage' => $this->money(10000 - $brokerPercent - $officePercent)],
                ], requireComplete: false);
                $broker = $this->destinations->cents($parts[0]['amount'], true);
                $office = $this->destinations->cents($parts[1]['amount'], true);
            }
        }
        if ($fees + $extras['total'] > $total || $broker + $office > $base) {
            throw new \DomainException($extras['total'] > 0
                ? 'Os honorários e os extras não podem superar a entrada, e as comissões não podem superar a base dos honorários.'
                : 'Os honorários não podem superar a entrada, e as comissões não podem superar os honorários.');
        }

        return [
            'total' => $total,
            'fees' => $fees,
            'extras' => $extras['total'],
            'extras_in_base' => $extras['in'],
            'commission_base' => $base,
            'fees_total' => $fees + $extras['total'],
            'client' => $total - $fees - $extras['total'],
            'broker' => $broker,
            'brokers' => $broker > 0 ? [$broker] : [],
            'office' => $office,
            'own' => $base - $broker - $office + $extras['out'],
        ];
    }

    public function save(Transaction $receipt, array $data): void
    {
        $data['fee_extras'] = $this->extrasRows($data);
        $legacy = ! array_key_exists('brokers', $data);
        if ($legacy && empty($data['broker_id'])) {
            $data['broker_id'] = null;
            $data['broker_amount'] = '0.00';
            $data['expenses'] = [];
            $data['advances'] = [];
        }
        $data['expenses'] ??= [];
        $data['advances'] ??= [];
        $rows = $this->brokerRows($data);
        if ($rows === []) {
            $data['expenses'] = [];
        }
        $money = ['regex:/^\d{1,13}(?:\.\d{1,2})?$/'];
        Validator::make($data + ['broker_rows' => $rows], [
            'broker_id' => ['nullable', 'integer', Rule::exists('brokers', 'id')->where('status', true)->whereNull('deleted_at')],
            'broker_rows' => 'array|max:10',
            'broker_rows.*.broker_id' => ['required', 'integer', 'distinct', Rule::exists('brokers', 'id')->where('status', true)->whereNull('deleted_at')],
            'broker_rows.*.base' => 'required|in:fees,gross',
            'broker_rows.*.mode' => 'required|in:percent,fixed',
            'broker_rows.*.value' => ['required', ...$money],
            'broker_rows.*.auto' => 'sometimes|boolean',
            'broker_rows.*.advances' => 'sometimes|array|max:200',
            'broker_rows.*.advances.*' => ['nullable', ...$money],
            'client_contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('status', true)->whereNull('deleted_at')],
            'receivables' => 'sometimes|array|max:200',
            'receivables.*' => ['nullable', ...$money],
            'client_name' => 'required|string|max:200',
            'process_ref' => [\Illuminate\Validation\Rule::requiredIf(! empty($data['is_extra_only'])), 'nullable', 'string', 'max:120'],
            'is_extra_only' => 'sometimes|boolean',
            'fee_extras' => 'sometimes|array|max:20',
            'fee_extras.*.type' => 'required|in:extra,other',
            'fee_extras.*.description' => 'nullable|string|max:200',
            'fee_extras.*.amount' => ['required', ...$money],
            'fee_extras.*.in_commission_base' => 'sometimes|boolean',
            'includes_writ' => 'sometimes|boolean',
            'writ_id' => [Rule::requiredIf(! empty($data['includes_writ'])), 'nullable', 'integer', Rule::exists('writs', 'id')->whereNull('deleted_at')],
            'writ_amount' => [Rule::requiredIf(! empty($data['includes_writ'])), 'nullable', ...$money],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('type', 'income')->where('status', true)],
            'fees_mode' => 'required|in:amount,percent', 'commission_mode' => 'required|in:amount,percent',
            'fees' => ['required', ...$money],
            'broker_amount' => [Rule::requiredIf($legacy), ...$money],
            'office_amount' => ['required', ...$money],
            'expenses' => 'present|array|max:200', 'advances' => 'present|array|max:200',
            'expenses.*' => ['nullable', ...$money],
            'advances.*' => ['nullable', ...$money],
        ], [
            'broker_rows.*.broker_id.distinct' => 'Cada corretor deve aparecer uma única vez no recebimento.',
            'writ_id.required' => 'Selecione o requisitório comprado incluído neste recebimento.',
            'writ_amount.required' => 'Informe o valor do crédito do requisitório comprado.',
            'process_ref.required' => 'Informe o número do processo para marcar este recebimento como honorário extra.',
        ])->validate();
        DB::transaction(function () use ($receipt, $data, $rows) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $this->destinations->eligible($receipt);
            $brokers = [];
            foreach ($rows as $row) {
                $broker = Broker::lockForUpdate()->findOrFail($row['broker_id']);
                $contact = app(BrokerProfileService::class)->contactForBroker($broker);
                if (! $contact || ! $contact->status) {
                    throw new \DomainException('Selecione um contato ativo do tipo corretor ('.$broker->name.').');
                }
                $brokers[] = $broker;
            }
            $values = $this->calculate($receipt, $data);
            $brokerIds = array_map(fn ($broker) => $broker->id, $brokers);
            if (count($values['brokers']) !== count($brokers)) {
                // Formato antigo: corretor selecionado com comissão zero não gera parte nem comissão.
                $brokers = [];
                $rows = [];
            }
            foreach ($values['brokers'] as $index => $cents) {
                if ($cents <= 0) {
                    throw new \DomainException('Informe a comissão do corretor '.$brokers[$index]->name.' (valor maior que zero) ou remova-o.');
                }
            }
            $allocations = app(ReceiptAccountAllocationService::class);
            $targets = [];
            foreach (['client', 'office'] as $kind) {
                if ($values[$kind] > 0) {
                    $targets[$kind] = $allocations->target($receipt, $kind);
                }
            }
            $destRows = [
                ['kind' => 'client', 'contact_id' => $data['client_contact_id'] ?? null, 'beneficiary' => trim($data['client_name']), 'amount' => $this->money($values['client'])],
                ['kind' => 'own', 'amount' => $this->money($values['own'])],
            ];
            if ($brokers !== []) {
                $caseType = CaseType::updateOrCreate(['name' => 'Recebimento de processo'], ['status' => true]);
                foreach ($brokers as $index => $broker) {
                    $destRows[] = [
                        'kind' => 'broker', 'broker_id' => $broker->id, 'case_type_id' => $caseType->id,
                        'commission_name' => mb_substr(trim($data['client_name']), 0, 160),
                        'amount' => $this->money($values['brokers'][$index]),
                    ];
                }
            }
            if ($values['office'] > 0) {
                $destRows[] = ['kind' => 'office', 'beneficiary' => ReceiptAccountAllocationService::OFFICE_ACCOUNT, 'amount' => $this->money($values['office'])];
            }
            // Edição: desfaz as compensações geradas por este recebimento; elas são refeitas abaixo (manual ou automática), igual a uma criação.
            $ownCommissionIds = $this->receiptCommissionIds($receipt);
            if ($ownCommissionIds !== []) {
                foreach (BrokerCommission::whereIn('id', $ownCommissionIds)->lockForUpdate()->get() as $ownCommission) {
                    if ($ownCommission->payments()->exists()) {
                        throw new \DomainException('Desfaça os repasses do corretor '.($ownCommission->broker?->name ?? '').' antes de alterar a divisão do recebimento.');
                    }
                }
                BrokerCommissionSettlement::whereIn('commission_id', $ownCommissionIds)->delete();
            }
            $this->destinations->replace($receipt, $destRows, automaticAdvances: false);
            $process = ProcessReceipt::create([
                'receipt_id' => $receipt->id, 'broker_id' => $brokers[0]->id ?? null,
                'process_ref' => trim((string) ($data['process_ref'] ?? '')) ?: null,
                'fees_amount' => $this->money($values['fees']),
                'is_extra_only' => ! empty($data['is_extra_only']),
                'category_id' => $data['category_id'] ?? null,
            ]);
            foreach ($this->extrasRows($data) as $row) {
                $cents = $this->destinations->cents((string) ($row['amount'] ?? '0'), true);
                if (! $cents) {
                    continue;
                }
                $process->feeItems()->create([
                    'kind' => (($row['type'] ?? $row['kind'] ?? 'extra') === 'other') ? 'other' : 'extra',
                    'description' => trim((string) ($row['description'] ?? '')) ?: null,
                    'amount' => $this->money($cents),
                    'in_commission_base' => ! empty($row['in_commission_base']),
                ]);
            }
            $client = $receipt->destinations()->where('kind', 'client')->sole();
            $brokerParts = $receipt->destinations()->where('kind', 'broker')->orderBy('id')->get()->values();
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
                $expense = BrokerProcessExpense::whereIn('broker_id', $brokerIds)->lockForUpdate()->find($id);
                if (! $expense || $cents > $expense->remainingCents()) {
                    throw new \DomainException('Uma despesa selecionada não pertence aos corretores ou não possui saldo suficiente. Atualize os valores.');
                }
                $recovered += $cents;
                if ($recovered > $values['client']) {
                    throw new \DomainException('As despesas descontadas não podem superar o valor do cliente.');
                }
                $client->recoveries()->create(['expense_id' => $expense->id, 'amount' => $this->money($cents)]);
            }
            foreach ($rows as $index => $row) {
                $commission = $brokerParts[$index]->commission ?? null;
                $offset = 0;
                if (filter_var($row['auto'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    // Compensação automática: do adiantamento mais antigo para o mais novo, até o valor da comissão.
                    $advances = array_map(fn ($cents) => $this->money($cents), $this->autoAllocation($brokers[$index]->id, $values['brokers'][$index], null, true));
                } else {
                    $advances = (array) ($row['advances'] ?? []);
                    ksort($advances);
                }
                foreach ($advances as $id => $amount) {
                    if ($amount === '' || $amount === null) {
                        continue;
                    }
                    $cents = $this->destinations->cents((string) $amount, true);
                    if (! $cents) {
                        continue;
                    }
                    // Compensa somente adiantamentos deste corretor; nunca de outro.
                    $advance = BrokerAdvance::personal()->where('broker_id', $brokers[$index]->id)->lockForUpdate()->find($id);
                    if (! $commission || ! $advance || $cents > (int) round(((float) $advance->amount - (float) $advance->settlements()->lockForUpdate()->get()->sum('amount_offset')) * 100)) {
                        throw new \DomainException('Um adiantamento selecionado não pertence ao corretor '.$brokers[$index]->name.' ou não possui saldo suficiente.');
                    }
                    $offset += $cents;
                    if ($offset > $values['brokers'][$index]) {
                        throw new \DomainException('Os adiantamentos não podem superar a comissão do corretor '.$brokers[$index]->name.'.');
                    }
                    BrokerCommissionSettlement::create([
                        'commission_id' => $commission->id, 'advance_id' => $advance->id,
                        'amount_offset' => $this->money($cents), 'settled_at' => $receipt->date,
                    ]);
                }
                if ($commission) {
                    app(BrokerCommissionService::class)->syncStatus($commission->fresh());
                }
                $process->brokerShares()->create([
                    'broker_id' => $brokers[$index]->id, 'base' => $row['base'] ?? 'fees', 'mode' => $row['mode'] ?? 'fixed',
                    'rate_or_amount' => $this->money($this->destinations->cents((string) ($row['value'] ?? '0'), true)),
                    'calculated_amount' => $this->money($values['brokers'][$index]),
                    'compensated_amount' => $this->money($offset), 'commission_id' => $commission?->id,
                ]);
            }
            foreach ($targets as $kind => $target) {
                $part = $receipt->destinations()->where('kind', $kind)->sole();
                $allocations->sync($part, $target);
            }
            if (! empty($data['includes_writ']) && ! empty($data['writ_id'])) {
                $this->settleWrit($receipt, $client->fresh(), $process, (int) $data['writ_id'], (string) $data['writ_amount']);
            }
            foreach (($data['receivables'] ?? []) as $id => $amount) {
                if ($amount === null || $amount === '' || $this->destinations->cents((string) $amount, true) === 0) {
                    continue;
                }
                $receivable = Receivable::findOrFail($id);
                app(ReceivableService::class)->offset($receivable, $client->fresh(), (string) $amount, (string) Str::uuid());
            }
            $links = array_filter([
                'link_contact_id' => $receipt->link_contact_id ?: ($data['client_contact_id'] ?? null),
                'link_process' => $receipt->link_process ?: ($process->process_ref ?? null),
                'link_writ_id' => $receipt->link_writ_id ?: ($process->writ_id ?? null),
            ]);
            $links ? $receipt->forceFill($links)->save() : $receipt->touch();
        });
    }

    /**
     * Crédito do cliente que já foi comprado como requisitório: baixa o requisitório como Recebido
     * (data e valor reais; o prazo real sai de paid_at → finalized_at) e vincula a parte do cliente
     * à entrada do requisitório, zerando-a, para que o dinheiro conte uma única vez e não vire repasse.
     */
    private function settleWrit(Transaction $receipt, \App\Domains\Banking\Models\ReceiptDestination $client, ProcessReceipt $process, int $writId, string $amount): void
    {
        $cents = $this->destinations->cents($amount);
        if ($cents > $client->remainingCents()) {
            throw new \DomainException('O crédito do requisitório (R$ '.number_format($cents / 100, 2, ',', '.').') não pode superar a parte do cliente (R$ '.number_format($client->remainingCents() / 100, 2, ',', '.').').');
        }
        $writ = Writ::lockForUpdate()->findOrFail($writId);
        if ((float) $writ->paid_amount <= 0) {
            throw new \DomainException('O requisitório selecionado não tem valor de aquisição pago; só requisitórios comprados podem ser baixados aqui.');
        }
        if ($writ->stage !== 'finalized') {
            try {
                $writ = app(WritService::class)->transitionTo($writ, 'finalized', [
                    'finalized_at' => $receipt->date->toDateString(), 'actual_receipt_amount' => $this->money($cents),
                    'destination_bank_account_id' => $receipt->bank_account_id,
                    'notes' => 'Baixa pelo recebimento de processo #'.$receipt->id,
                ]);
            } catch (\DomainException $e) {
                throw new \DomainException('Não foi possível baixar o requisitório como Recebido a partir da etapa "'.$writ->stageLabel().'". '.$e->getMessage());
            }
        }
        $income = $writ->transactions()->where('type', 'income')->orderBy('id')->first();
        if (! $income) {
            throw new \DomainException('O requisitório está como Recebido, mas sem valor de recebimento. Informe o valor recebido no requisitório.');
        }
        app(ReceiptWritLinkService::class)->link($client, $income->id, $this->money($cents));
        $process->update(['writ_id' => $writ->id, 'writ_amount' => $this->money($cents)]);
    }

    public function undoRecovery(Transaction $receipt, int $id): void
    {
        DB::transaction(function () use ($receipt, $id) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $recovery = ProcessExpenseRecovery::whereHas('destination', fn ($q) => $q->where('receipt_id', $receipt->id))->findOrFail($id);
            BrokerProcessExpense::lockForUpdate()->findOrFail($recovery->expense_id);
            $part = $recovery->destination;
            $recovery->delete();
            if ($part->repasse_account_id) {
                $allocations = app(ReceiptAccountAllocationService::class);
                $allocations->sync($part->fresh(), $allocations->target($receipt, 'client'));
            }
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
