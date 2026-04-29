<?php

use App\Domains\Banking\Models\Transaction;
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

            $byCategory = Transaction::with('category')
                ->whereBetween('date', [$start, $end])
                ->where('status', 'settled')
                ->whereIn('type', ['income', 'expense', 'invoice_payment'])
                ->get()
                ->groupBy(fn ($t) => $t->category?->name ?? '— sem categoria —')
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

        return $data + ['result' => $data['totalIncome'] - $data['totalExpense']];
    }
}; ?>

<x-slot name="header">Financeiro · Fluxo de Caixa</x-slot>

<div class="flex flex-col gap-md">
    <x-fx.card>
        <div class="flex items-end gap-sm">
            <x-fx.input label="Mês de referência" type="month" wire:model.live="month" />
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Receitas</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($totalIncome, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Despesas</div>
            <div class="text-xl font-bold text-system-down">R$ {{ number_format($totalExpense, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Resultado</div>
            <div class="text-xl font-bold {{ $result >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($result, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Por categoria</h3>
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
