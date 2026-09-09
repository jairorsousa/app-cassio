<nav class="flex flex-wrap gap-6 border-b border-mono-100" aria-label="Investimentos">
    @foreach ([['investments.dashboard', 'dashboard', 'Visão geral'], ['investments.positions', 'account_balance_wallet', 'Carteira'], ['investments.operations.index', 'swap_horiz', 'Movimentações'], ['investments.dividends.index', 'payments', 'Proventos'], ['investments.assets.index', 'category', 'Ativos'], ['investments.reports', 'query_stats', 'Rentabilidade']] as [$route, $icon, $label])
        <a href="{{ route($route) }}" @class(['inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-semibold transition-colors', 'border-primary-500 text-primary-500' => request()->routeIs($route), 'border-transparent text-mono-600 hover:text-mono-900' => !request()->routeIs($route)])>
            <span class="material-icons-outlined text-[18px]">{{ $icon }}</span>{{ $label }}
        </a>
    @endforeach
</nav>
