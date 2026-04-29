<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-md animate-pulse">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
                <div class="h-24 bg-mono-100 rounded-md"></div>
                <div class="h-24 bg-mono-100 rounded-md"></div>
                <div class="h-24 bg-mono-100 rounded-md"></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
                <div class="h-48 bg-mono-100 rounded-md"></div>
                <div class="h-48 bg-mono-100 rounded-md"></div>
            </div>
        </div>
        HTML;
    }

    public function with(): array
    {
        $summary = Cache::remember('banking.dashboard.summary', 300, function () {
            $accounts = BankAccount::active()->get();
            $totalBalance = $accounts->sum(fn ($a) => $a->balance());

            $upcomingInvoices = CreditCardInvoice::with('creditCard')
                ->whereIn('status', ['open', 'closed', 'partially_paid'])
                ->where('due_date', '>=', now())
                ->orderBy('due_date')
                ->limit(5)
                ->get();

            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();

            $monthIncome = (float) Transaction::where('type', 'income')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->where('status', 'settled')
                ->sum('amount');

            $monthExpense = (float) Transaction::whereIn('type', ['expense', 'invoice_payment'])
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->where('status', 'settled')
                ->sum('amount');

            $pending = Transaction::with(['category', 'bankAccount'])
                ->where('status', 'pending')
                ->orderBy('date')
                ->limit(10)
                ->get();

            return compact('accounts', 'totalBalance', 'upcomingInvoices', 'monthIncome', 'monthExpense', 'pending');
        });

        return $summary + ['result' => $summary['monthIncome'] - $summary['monthExpense']];
    }
}; ?>

<x-slot name="header">Financeiro · Dashboard</x-slot>

<div class="flex flex-col gap-md">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase tracking-wide">Saldo total</div>
            <div class="text-xl font-bold text-mono-900">R$ {{ number_format($totalBalance, 2, ',', '.') }}</div>
            <div class="text-xxs text-mono-600 mt-xxxs">{{ $accounts->count() }} contas ativas</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase tracking-wide">Receita mês</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($monthIncome, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase tracking-wide">Despesa mês</div>
            <div class="text-xl font-bold text-system-down">R$ {{ number_format($monthExpense, 2, ',', '.') }}</div>
            <div class="text-xxs mt-xxxs {{ $result >= 0 ? 'text-system-up' : 'text-system-down' }}">
                Resultado: R$ {{ number_format($result, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Faturas próximas</h3>
            @if ($upcomingInvoices->isEmpty())
                <div class="text-sm text-mono-600">Nenhuma fatura aberta.</div>
            @else
                <ul class="flex flex-col gap-xs">
                    @foreach ($upcomingInvoices as $inv)
                        <li class="flex justify-between items-center py-xxs border-b border-mono-100">
                            <div>
                                <div class="text-sm font-medium">{{ $inv->creditCard->name }}</div>
                                <div class="text-xxs text-mono-600">Venc. {{ $inv->due_date->format('d/m/Y') }} · {{ $inv->reference_month }}</div>
                            </div>
                            <div class="text-sm font-semibold">R$ {{ number_format($inv->total, 2, ',', '.') }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>

        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Lançamentos pendentes</h3>
            @if ($pending->isEmpty())
                <div class="text-sm text-mono-600">Sem pendências.</div>
            @else
                <ul class="flex flex-col gap-xs">
                    @foreach ($pending as $t)
                        <li class="flex justify-between items-center py-xxs border-b border-mono-100">
                            <div>
                                <div class="text-sm">{{ $t->description }}</div>
                                <div class="text-xxs text-mono-600">{{ $t->date->format('d/m/Y') }}</div>
                            </div>
                            <div class="text-sm font-semibold {{ $t->type === 'income' ? 'text-system-up' : 'text-system-down' }}">
                                R$ {{ number_format($t->amount, 2, ',', '.') }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>
    </div>

    <div class="flex gap-xs">
        <x-fx.button href="{{ route('banking.transactions.index') }}" variant="primary">Lançamentos</x-fx.button>
        <x-fx.button href="{{ route('banking.accounts.index') }}" variant="standard">Contas</x-fx.button>
        <x-fx.button href="{{ route('banking.cards.index') }}" variant="standard">Cartões</x-fx.button>
        <x-fx.button href="{{ route('banking.reports.cashflow') }}" variant="standard">Fluxo</x-fx.button>
    </div>
</div>
