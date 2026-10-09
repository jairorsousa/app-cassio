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

    public function with(): array
    {
        $month = $this->month;

        $data = Cache::remember("banking.cashflow.{$month}", 3600, function () use ($month) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
            $end = (clone $start)->endOfMonth();

            $byCategory = Transaction::with(['category', 'allocations.category'])
                ->whereBetween('date', [$start, $end])
                ->where('status', 'settled')
                ->whereIn('type', ['income', 'expense', 'invoice_payment'])
                ->get()
                ->flatMap(fn ($transaction) => $transaction->allocations->isNotEmpty()
                    ? $transaction->allocations->map(fn ($allocation) => [
                        'category' => $allocation->category?->name ?? '— sem categoria —',
                        'type' => $transaction->type,
                        'amount' => (float) $allocation->amount,
                    ])
                    : [[
                        'category' => $transaction->category?->name ?? '— sem categoria —',
                        'type' => $transaction->type,
                        'amount' => (float) $transaction->amount,
                    ]])
                ->groupBy('category')
                ->map(fn ($group) => [
                    'income' => $group->where('type', 'income')->sum('amount'),
                    'expense' => $group->whereIn('type', ['expense', 'invoice_payment'])->sum('amount'),
                ]);

            $byAccount = Transaction::with('bankAccount')
                ->whereBetween('date', [$start, $end])
                ->where('status', 'settled')
                ->whereNotNull('bank_account_id')
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
        });

        $destinations = ReceiptDestination::with('commission', 'payments.transaction')->whereHas('receipt', fn ($q) => $q->where('type', 'income')->where('status', 'settled')->whereBetween('date', [Carbon::parse($month.'-01')->startOfMonth(), Carbon::parse($month.'-01')->endOfMonth()]))->get();
        $thirdPartyIncome = $destinations->where('kind', '!=', 'own')->sum('amount');
        $ownIncome = round($data['totalIncome'] - $thirdPartyIncome, 2);
        $pendingRepasses = $destinations->sum(fn ($part) => $part->remainingCents()) / 100;

        return $data + compact('thirdPartyIncome', 'ownIncome', 'pendingRepasses') + ['result' => $data['totalIncome'] - $data['totalExpense']];
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
            <div class="text-xxs text-mono-600 uppercase">Entradas recebidas</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($totalIncome, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Despesas</div>
            <div class="text-xl font-bold text-system-down">R$ {{ number_format($totalExpense, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Resultado de caixa</div>
            <div class="text-xl font-bold {{ $result >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($result, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Destinação dos recebimentos do mês</h3>
        <div class="grid gap-4 md:grid-cols-3 text-sm">
            <div>Receitas após separar terceiros<strong class="mt-1 block text-lg text-system-up">R$ {{ number_format($ownIncome, 2, ',', '.') }}</strong></div>
            <div>Destinado a clientes, corretores e escritórios<strong class="mt-1 block text-lg">R$ {{ number_format($thirdPartyIncome, 2, ',', '.') }}</strong></div>
            <div>Ainda a repassar destes recebimentos<strong class="mt-1 block text-lg">R$ {{ number_format($pendingRepasses, 2, ',', '.') }}</strong></div>
        </div>
        <p class="mt-3 text-xs text-mono-600">Recebimentos sem destinação são considerados receitas próprias. O resultado de caixa acima acompanha entradas e saídas efetivas.</p>
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Entradas e saídas por categoria</h3>
        <table class="fx-table w-full text-sm">
            <thead>
                <tr>
                    <th class="text-left">Categoria</th>
                    <th class="text-right">Receita</th>
                    <th class="text-right">Despesa</th>
                    <th class="text-right">Líquido</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($byCategory as $name => $row)
                    <tr>
                        <td>{{ $name }}</td>
                        <td class="text-right text-system-up">R$ {{ number_format($row['income'], 2, ',', '.') }}</td>
                        <td class="text-right text-system-down">R$ {{ number_format($row['expense'], 2, ',', '.') }}</td>
                        <td class="text-right font-semibold">R$ {{ number_format($row['income'] - $row['expense'], 2, ',', '.') }}</td>
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
