<?php

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Models\ReceiptDestination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    private function categoryRows(Transaction $transaction): array
    {
        if (($transaction->credit_card_invoice_id && $transaction->type === 'expense') || $transaction->bankAccount?->type === 'repasse') { return []; }
        $ownCents = app(\App\Domains\Banking\Services\OwnFinancialService::class)->ownCents($transaction);
        if ($transaction->processReceipt) {
            $own = $ownCents / 100;
            $recovered = $transaction->destinations->sum(fn ($part) => $part->recoveredCents()) / 100;
            $acquired = $transaction->destinations->sum(fn ($part) => (float) $part->payments->sum('writ_amount'));
            return [
                ['category' => $transaction->processReceipt->category?->name ?? '— sem categoria —', 'type' => 'income', 'amount' => $own - $acquired, 'own_amount' => $own - $acquired],
                ['category' => 'Valores para repasse', 'type' => 'income', 'amount' => (float) $transaction->amount - $own - $recovered, 'own_amount' => 0],
                ['category' => 'Requisitórios adquiridos', 'type' => 'income', 'amount' => $acquired, 'own_amount' => $acquired],
                ['category' => 'Recuperação de despesas', 'type' => 'income', 'amount' => $recovered, 'own_amount' => 0],
            ];
        }
        $rows = $transaction->allocations->isNotEmpty()
            ? $transaction->allocations->map(fn ($part) => ['category' => $part->category?->name ?? '— sem categoria —', 'type' => $transaction->type, 'amount' => (float) $part->amount])->all()
            : [['category' => $transaction->category?->name ?? '— sem categoria —', 'type' => $transaction->type, 'amount' => (float) $transaction->amount]];
        $remaining = (int) round((float) $transaction->amount * 100);
        foreach ($rows as &$row) {
            $cents = (int) round($row['amount'] * 100);
            $own = $remaining > 0 ? \Brick\Math\BigInteger::of($ownCents)->multipliedBy($cents)->quotient($remaining)->toInt() : 0;
            $row['own_amount'] = $own / 100;
            $ownCents -= $own;
            $remaining -= $cents;
        }
        return $rows;
    }

    public function with(): array
    {
        $month = $this->month;

        $data = (function () use ($month) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
            $end = (clone $start)->endOfMonth();

            $byCategory = Transaction::with(['category', 'allocations.category', 'processReceipt.category', 'destinations.recoveries', 'destinations.payments', 'bankAccount', 'receivableInstallments', 'receivableSettlements', 'destinationPayment'])
                ->whereBetween('date', [$start, $end])
                ->where('status', 'settled')
                ->whereIn('type', ['income', 'expense', 'invoice_payment'])
                ->get()
                ->flatMap(fn ($transaction) => $this->categoryRows($transaction))
                ->groupBy('category')
                ->map(fn ($group) => [
                    'income' => $group->where('type', 'income')->sum('amount'),
                    'expense' => $group->whereIn('type', ['expense', 'invoice_payment'])->sum('amount'),
                    'own_income' => $group->where('type', 'income')->sum('own_amount'),
                    'own_expense' => $group->whereIn('type', ['expense', 'invoice_payment'])->sum('own_amount'),
                    'third_party_in' => $group->where('type', 'income')->sum(fn ($row) => $row['amount'] - $row['own_amount']),
                    'third_party_out' => $group->whereIn('type', ['expense', 'invoice_payment'])->sum(fn ($row) => $row['amount'] - $row['own_amount']),
                ]);

            $byAccount = Transaction::with('bankAccount')
                ->whereBetween('date', [$start, $end])
                ->where('status', 'settled')
                ->whereNotNull('bank_account_id')
                ->whereHas('bankAccount', fn ($q) => $q->where('type', '!=', 'repasse'))
                ->get()
                ->groupBy(fn ($t) => $t->bankAccount?->name ?? '—')
                ->map(fn ($group) => [
                    'in' => $group->whereIn('type', ['income'])->sum('amount')
                        + $group->where('type', 'transfer')->where('amount', '>', 0)->sum('amount'),
                    'out' => $group->whereIn('type', ['expense', 'invoice_payment'])->sum('amount')
                        + abs((float) $group->where('type', 'transfer')->where('amount', '<', 0)->sum('amount')),
                ]);

            $totalIncome = $byCategory->sum('income');
            $totalExpense = $byCategory->sum('expense');

            return compact('byCategory', 'byAccount', 'totalIncome', 'totalExpense');
        })();

        $destinations = ReceiptDestination::with('commission', 'payments.transaction', 'recoveries')->whereHas('receipt', fn ($q) => $q->where('type', 'income')->where('status', 'settled')->whereBetween('date', [Carbon::parse($month.'-01')->startOfMonth(), Carbon::parse($month.'-01')->endOfMonth()]))->get();
        $recoveredExpenses = $destinations->sum(fn ($part) => $part->recoveredCents()) / 100;
        $acquiredReceipts = $destinations->sum(fn ($part) => (float) $part->payments->sum('writ_amount'));
        $thirdPartyIncome = $destinations->where('kind', '!=', 'own')->sum('amount') - $recoveredExpenses - $acquiredReceipts - $destinations->sum(fn ($part) => $part->offsetCents()) / 100;
        $own = app(\App\Domains\Banking\Services\OwnFinancialService::class)->totals(Carbon::parse($month.'-01')->startOfMonth(), Carbon::parse($month.'-01')->endOfMonth());
        $ownIncome = $own['income'];
        $ownExpense = $own['expense'];
        $cashResult = $data['byAccount']->sum('in') - $data['byAccount']->sum('out');
        $neutralOut = max(0, $data['totalExpense'] - $ownExpense);
        $neutralIn = max(0, $data['totalIncome'] - $ownIncome);
        $pendingRepasses = $destinations->sum(fn ($part) => $part->remainingCents()) / 100;

        return $data + compact('thirdPartyIncome', 'ownIncome', 'ownExpense', 'cashResult', 'neutralIn', 'neutralOut', 'pendingRepasses', 'recoveredExpenses') + ['result' => $own['result']];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-md">
    <x-banking.subnav />
    <x-fx.card>
        <div class="flex items-end gap-sm">
            <x-fx.input label="Mês de referência" type="month" wire:model.live="month" />
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Receitas próprias</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($ownIncome, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Despesas próprias</div>
            <div class="text-xl font-bold text-system-down">R$ {{ number_format($ownExpense, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Resultado próprio</div>
            <div class="text-xl font-bold {{ $result >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($result, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Movimentação de caixa e valores de terceiros</h3>
        <div class="grid gap-4 text-sm md:grid-cols-3">
            <div>Entradas bancárias<strong class="mt-1 block text-lg">R$ {{ number_format($byAccount->sum('in'), 2, ',', '.') }}</strong></div>
            <div>Saídas bancárias<strong class="mt-1 block text-lg">R$ {{ number_format($byAccount->sum('out'), 2, ',', '.') }}</strong></div>
            <div>Variação do caixa<strong class="mt-1 block text-lg">R$ {{ number_format($cashResult, 2, ',', '.') }}</strong></div>
        </div>
        <p class="mt-3 text-xs text-mono-600">Adiantamentos e devoluções movimentam o banco. Apenas a parte própria entra no resultado acima. Compras no cartão entram no resultado quando a fatura é paga, uma única vez. Perdas reconhecidas são despesas sem nova saída bancária.</p>
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Recebimentos de processo do mês</h3>
        <div class="grid gap-4 md:grid-cols-3 text-sm">
            <div>Receitas após separar terceiros<strong class="mt-1 block text-lg text-system-up">R$ {{ number_format($ownIncome, 2, ',', '.') }}</strong></div>
            <div>Valores de clientes, corretores e escritórios<strong class="mt-1 block text-lg">R$ {{ number_format($thirdPartyIncome, 2, ',', '.') }}</strong></div>
            <div>Despesas recuperadas<strong class="mt-1 block text-lg">R$ {{ number_format($recoveredExpenses, 2, ',', '.') }}</strong></div>
        </div>
        <p class="mt-3 text-xs text-mono-600">Devoluções vinculadas a Valores a receber e partes de terceiros são excluídas das receitas próprias. As tabelas abaixo permitem conferir as movimentações registradas.</p>
    </x-fx.card>

    <x-fx.card class="overflow-x-auto">
        <h3 class="text-md font-semibold mb-sm">Resultado próprio e valores de terceiros por categoria</h3>
        <table class="fx-table w-full text-sm">
            <thead>
                <tr>
                    <th class="text-left">Categoria</th>
                    <th class="text-right">Receita própria</th>
                    <th class="text-right">Despesa própria</th><th class="text-right">Outras entradas</th><th class="text-right">Outras saídas</th>
                    <th class="text-right">Resultado próprio</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($byCategory as $name => $row)
                    <tr>
                        <td>{{ $name }}</td>
                        <td class="text-right text-system-up">R$ {{ number_format($row['own_income'], 2, ',', '.') }}</td>
                        <td class="text-right text-system-down">R$ {{ number_format($row['own_expense'], 2, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format($row['third_party_in'], 2, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format($row['third_party_out'], 2, ',', '.') }}</td>
                        <td class="text-right font-semibold">R$ {{ number_format($row['own_income'] - $row['own_expense'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Por conta</h3>
        <table class="fx-table w-full text-sm">
            <thead>
                <tr>
                    <th class="text-left">Conta</th>
                    <th class="text-right">Entradas</th>
                    <th class="text-right">Saídas</th>
                    <th class="text-right">Saldo do mês</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($byAccount as $name => $row)
                    <tr>
                        <td>{{ $name }}</td>
                        <td class="text-right text-system-up">R$ {{ number_format($row['in'], 2, ',', '.') }}</td>
                        <td class="text-right text-system-down">R$ {{ number_format($row['out'], 2, ',', '.') }}</td>
                        <td class="text-right font-semibold">R$ {{ number_format($row['in'] - $row['out'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-fx.card>
</div>
