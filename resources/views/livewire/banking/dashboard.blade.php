<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\CashPositionService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-space-5 animate-pulse">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-space-5">
                <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
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
        $summary = Cache::remember('banking.dashboard.summary.v2.'.\App\Domains\Banking\Services\OwnFinancialService::currentRegime(), 300, function () {
            $accounts = BankAccount::active()->get();
            $cash = app(CashPositionService::class)->summarize($accounts);
            $totalBalance = $cash['accounts_balance'];
            $repasseBalance = $cash['repasse_balance'];
            $accountsBalance = $cash['accounts_balance'];
            $thirdPartyBalance = $cash['third_party_balance'];
            $ownBalance = $cash['own_balance'];

            $upcomingInvoices = CreditCardInvoice::with('creditCard')
                ->whereIn('status', ['open', 'closed', 'partially_paid'])
                ->where('due_date', '>=', now())
                ->orderBy('due_date')
                ->limit(5)
                ->get();

            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();

            $own = app(\App\Domains\Banking\Services\OwnFinancialService::class)->totals($monthStart, $monthEnd);
            $monthIncome = $own['income'];
            $monthExpense = $own['expense'];

            $pending = Transaction::with(['category', 'bankAccount'])
                ->where('status', 'pending')
                ->orderBy('date')
                ->limit(10)
                ->get();

            return compact(
                'accounts',
                'totalBalance',
                'repasseBalance',
                'accountsBalance',
                'thirdPartyBalance',
                'ownBalance',
                'upcomingInvoices',
                'monthIncome',
                'monthExpense',
                'pending',
            );
        });

        $totalReceivable = Receivable::with('installments.settlements')->get()->sum(fn ($receivable) => $receivable->remainingCents()) / 100;

        return $summary + [
            'totalReceivable' => $totalReceivable,
            'result' => $summary['monthIncome'] - $summary['monthExpense'],
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-space-5">
    <x-banking.subnav active="overview" />
    <div class="flex justify-end"><livewire:banking.regime-switch /></div>
    <x-banking.cash-position
        :accounts-balance="$accountsBalance"
        :third-party-balance="$thirdPartyBalance"
        :own-balance="$ownBalance"
    />
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-space-5">
        <x-fx.card class="min-w-0">
            <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
                <span class="uppercase tracking-[0.05em]">Resultado do período</span>
                <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Receitas próprias menos despesas próprias do mês atual, incluindo perdas reconhecidas." aria-label="Receitas próprias menos despesas próprias do mês atual, incluindo perdas reconhecidas.">info</span>
            </div>
            <div class="mt-space-2 text-fs-24 font-bold font-mono [font-variant-numeric:tabular-nums] {{ $result >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">{{ $result < 0 ? '-R$' : 'R$' }} {{ number_format(abs($result), 2, ',', '.') }}</div>
            <div class="mt-space-1 text-fs-12 text-cryptex-text-secondary">Mês atual</div>
        </x-fx.card>
        <x-fx.card class="min-w-0">
            <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
                <span class="uppercase tracking-[0.05em]">Receitas</span>
                <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Receitas próprias recebidas no mês atual. Devoluções de adiantamentos e valores de terceiros não são receitas próprias." aria-label="Receitas próprias recebidas no mês atual. Devoluções de adiantamentos e valores de terceiros não são receitas próprias.">info</span>
            </div>
            <div class="mt-space-2 text-fs-24 font-bold text-cryptex-green-500 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($monthIncome, 2, ',', '.') }}</div>
            <div class="mt-space-1 text-fs-12 text-cryptex-text-secondary">Mês atual</div>
        </x-fx.card>
        <x-fx.card class="min-w-0">
            <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
                <span class="uppercase tracking-[0.05em]">Despesas</span>
                <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Despesas próprias pagas no mês atual, incluindo a parte própria das faturas. Valores adiantados a terceiros não entram como despesa. Inclui perdas reconhecidas explicitamente." aria-label="Despesas próprias pagas no mês atual, incluindo a parte própria das faturas. Valores adiantados a terceiros não entram como despesa. Inclui perdas reconhecidas explicitamente.">info</span>
            </div>
            <div class="mt-space-2 text-fs-24 font-bold text-cryptex-red-500 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($monthExpense, 2, ',', '.') }}</div>
            <div class="mt-space-1 text-fs-12 text-cryptex-text-secondary">Mês atual</div>
        </x-fx.card>
        <x-fx.card class="min-w-0">
            <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
                <span class="uppercase tracking-[0.05em]">Total a receber</span>
                <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Saldo em aberto de adiantamentos e pagamentos feitos em nome de terceiros, incluindo parcelas futuras e valores sem data definida." aria-label="Saldo em aberto de adiantamentos e pagamentos feitos em nome de terceiros, incluindo parcelas futuras e valores sem data definida.">info</span>
            </div>
            <div class="mt-space-2 text-fs-24 font-bold text-cryptex-brand-400 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($totalReceivable, 2, ',', '.') }}</div>
            <a href="{{ route('banking.receivables.index') }}" class="mt-space-1 inline-block text-fs-12 font-medium text-cryptex-brand-400 hover:underline">Ver valores a receber</a>
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

</div>
