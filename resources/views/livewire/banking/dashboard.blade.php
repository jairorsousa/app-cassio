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
        <div class="flex flex-col gap-space-5 animate-pulse">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-space-5">
                <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-5">
                <div class="h-[192px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[192px] bg-cryptex-bg-tertiary rounded-md"></div>
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

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-space-5">
    <x-banking.subnav />
    <div class="grid grid-cols-1 md:grid-cols-3 gap-space-5">
        <x-fx.card>
            <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">Saldo total</div>
            <div class="text-fs-24 font-bold text-cryptex-text-primary font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($totalBalance, 2, ',', '.') }}</div>
            <div class="text-fs-12 text-cryptex-text-secondary mt-space-1 font-mono"><span class="text-cryptex-brand-400">{{ $accounts->count() }}</span> contas ativas</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">Receita mês</div>
            <div class="text-fs-24 font-bold text-cryptex-green-500 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($monthIncome, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">Despesa mês</div>
            <div class="text-fs-24 font-bold text-cryptex-red-500 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($monthExpense, 2, ',', '.') }}</div>
            <div class="text-fs-12 mt-space-1 font-mono [font-variant-numeric:tabular-nums] {{ $result >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                Resultado: R$ {{ number_format($result, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-5">
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">Faturas próximas</h3>
            @if ($upcomingInvoices->isEmpty())
                <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma fatura aberta.</div>
            @else
                <ul class="flex flex-col gap-space-3">
                    @foreach ($upcomingInvoices as $inv)
                        <li class="flex justify-between items-center py-space-2 border-b border-cryptex-border-subtle last:border-0">
                            <div>
                                <div class="text-fs-14 font-medium text-cryptex-text-primary">{{ $inv->creditCard->name }}</div>
                                <div class="text-fs-12 text-cryptex-text-secondary">Venc. <span class="font-mono">{{ $inv->due_date->format('d/m/Y') }}</span> <span class="mx-1 opacity-50">·</span> <span class="font-mono">{{ $inv->reference_month }}</span></div>
                            </div>
                            <div class="text-fs-14 font-medium font-mono text-cryptex-red-500 [font-variant-numeric:tabular-nums]">R$ {{ number_format($inv->total, 2, ',', '.') }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>

        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">Lançamentos pendentes</h3>
            @if ($pending->isEmpty())
                <div class="text-fs-14 text-cryptex-text-secondary">Sem pendências.</div>
            @else
                <ul class="flex flex-col gap-space-3">
                    @foreach ($pending as $t)
                        <li class="flex justify-between items-center py-space-2 border-b border-cryptex-border-subtle last:border-0">
                            <div>
                                <div class="text-fs-14 text-cryptex-text-primary">{{ $t->description }}</div>
                                <div class="text-fs-12 text-cryptex-text-secondary font-mono">{{ $t->date->format('d/m/Y') }}</div>
                            </div>
                            <div class="text-fs-14 font-medium font-mono [font-variant-numeric:tabular-nums] {{ $t->type === 'income' ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                                R$ {{ number_format($t->amount, 2, ',', '.') }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>
    </div>

    <div class="flex gap-space-3">
        <x-fx.button href="{{ route('banking.transactions.index') }}" variant="primary">Lançamentos</x-fx.button>
        <x-fx.button href="{{ route('banking.accounts.index') }}" variant="secondary">Contas</x-fx.button>
        <x-fx.button href="{{ route('banking.cards.index') }}" variant="secondary">Cartões</x-fx.button>
        <x-fx.button href="{{ route('banking.reports.cashflow') }}" variant="secondary">Fluxo</x-fx.button>
    </div>
</div>
