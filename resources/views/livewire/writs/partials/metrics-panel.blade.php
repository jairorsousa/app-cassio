@php
    $open = $metrics['open'];
    $received = $metrics['received'];
    $invested = $metrics['invested'];
    $situation = $metrics['situation'];
    $averages = $metrics['averages'];
    $chart = $metrics['chart'];
    $upcoming = $metrics['upcoming'];
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $pct = fn ($value) => $value === null ? null : number_format((float) $value, 2, ',', '.').'%';
    $monthNames = [1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'];
@endphp

<div class="flex flex-col gap-4" data-metrics-panel>
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2 xl:grid-cols-4">
        <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="open">
            <div class="relative flex items-start gap-2 border-b border-mono-100 pb-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[20px]">hourglass_top</span>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-bold leading-5 text-mono-900">Dinheiro investido aguardando pagamento</h2>
                    <p class="mt-0.5 text-[11px] text-mono-500">{{ $open['count'] }} {{ $open['count'] === 1 ? 'requisitório comprado' : 'requisitórios comprados' }}, ainda não voltaram do tribunal</p>
                </div>
                <x-fx.help-tip label="O que significa este número" text="É o que você já pagou (compra + cartório e outras despesas) nos requisitórios que já comprou e que o tribunal ainda não depositou. Não inclui os que estão só em Monitorar, Negociação, Cessão, Assinatura ou Perdido, porque ainda não houve compra." />
            </div>
            <p class="pt-3 text-2xl font-bold tabular-nums text-primary-500">{{ $money($open['cost']) }}</p>
            <dl class="mt-2 space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-mono-600">Quanto deve voltar</dt>
                    <dd class="shrink-0 font-semibold tabular-nums text-mono-900">{{ $money($open['expected_receipt']) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-mono-600">Lucro esperado</dt>
                    <dd class="flex shrink-0 items-center gap-2">
                        @if ($open['expected_profit_pct'] === null)
                            <span class="rounded-pill bg-mono-100 px-2 py-0.5 text-[11px] font-bold text-mono-500">sem custo</span>
                        @else
                            <span class="rounded-pill bg-primary-100 px-2 py-0.5 text-[11px] font-bold text-primary-500">{{ $pct($open['expected_profit_pct']) }}</span>
                        @endif
                        <span class="font-semibold tabular-nums text-primary-500">{{ $money($open['expected_profit']) }}</span>
                    </dd>
                </div>
            </dl>
            @if ($open['without_cost'] > 0)
                <p class="mt-2 text-[11px] text-mono-500">{{ $open['without_cost'] }} {{ $open['without_cost'] === 1 ? 'requisitório' : 'requisitórios' }} em aberto sem custo informado — o % não inclui esses casos.</p>
            @endif
        </x-jr.card>

        <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="received">
            <div class="relative flex items-start gap-2 border-b border-mono-100 pb-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-up-bg text-up">
                    <span class="material-icons-outlined text-[20px]">check_circle</span>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-bold leading-5 text-mono-900">Já recebido</h2>
                    <p class="mt-0.5 text-[11px] text-mono-500">{{ $received['count'] }} {{ $received['count'] === 1 ? 'requisitório encerrado' : 'requisitórios encerrados' }}</p>
                </div>
                <x-fx.help-tip label="O que significa este número" text="Soma do que o tribunal já depositou nos requisitórios encerrados. O lucro real é esse total menos o que você pagou neles (compra + despesas). O percentual é o lucro dividido pelo custo." />
            </div>
            <p class="pt-3 text-2xl font-bold tabular-nums text-up">{{ $money($received['amount']) }}</p>
            <dl class="mt-2 space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-mono-600">Você tinha investido</dt>
                    <dd class="shrink-0 font-semibold tabular-nums text-mono-900">{{ $money($received['cost']) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-mono-600">Lucro real</dt>
                    <dd class="flex shrink-0 items-center gap-2">
                        @if ($received['profit_pct'] === null)
                            <span class="rounded-pill bg-mono-100 px-2 py-0.5 text-[11px] font-bold text-mono-500">sem custo</span>
                        @else
                            <span class="rounded-pill px-2 py-0.5 text-[11px] font-bold {{ $received['profit'] >= 0 ? 'bg-up-bg text-up' : 'bg-down-bg text-down' }}">{{ $pct($received['profit_pct']) }}</span>
                        @endif
                        <span class="font-semibold tabular-nums {{ $received['profit'] >= 0 ? 'text-up' : 'text-down' }}">{{ $money($received['profit']) }}</span>
                    </dd>
                </div>
            </dl>
        </x-jr.card>

        <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="invested">
            <div class="relative flex items-start gap-2 border-b border-mono-100 pb-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-mono-100 text-mono-600">
                    <span class="material-icons-outlined text-[20px]">account_balance_wallet</span>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-bold leading-5 text-mono-900">Total investido até hoje</h2>
                    <p class="mt-0.5 text-[11px] text-mono-500">{{ $invested['acquired_count'] }} {{ $invested['acquired_count'] === 1 ? 'compra' : 'compras' }} (em aberto + já recebidos)</p>
                </div>
                <x-fx.help-tip label="O que significa este número" text="Tudo o que você já desembolsou nos requisitórios comprados: o que ainda está em aberto mais o custo dos que já foram recebidos. É a soma do valor pago com cartório e outras despesas. Monitorar, Negociação, Cessão, Assinatura e Perdido não entram, porque ainda não houve compra. Valor de face é o valor oficial do crédito; valor negociado é o combinado na compra. Nenhum dos dois é o que você pagou." />
            </div>
            <p class="pt-3 text-2xl font-bold tabular-nums text-mono-900">{{ $money($invested['total']) }}</p>
            <dl class="mt-2 space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-mono-600">Valor de face dos comprados</dt>
                    <dd class="shrink-0 font-semibold tabular-nums text-mono-900">{{ $money($invested['face']) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-mono-600">Valor negociado</dt>
                    <dd class="shrink-0 font-semibold tabular-nums text-mono-900">{{ $money($invested['negotiated']) }}</dd>
                </div>
            </dl>
        </x-jr.card>

        <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="situation">
            <div class="relative flex items-start gap-2 border-b border-mono-100 pb-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-mono-100 text-mono-600">
                    <span class="material-icons-outlined text-[20px]">view_kanban</span>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-bold leading-5 text-mono-900">Situação agora</h2>
                    <p class="mt-0.5 text-[11px] text-mono-500">Quantos requisitórios há em cada etapa</p>
                </div>
                <x-fx.help-tip label="O que significa este número" text="Aguardando pagamento do tribunal são os já comprados e ainda não recebidos (Pagamento, Peticionamento e Aguardando recebimento). Previsão vencida usa a data da compra mais o prazo estimado em meses. Em análise são os que ainda não foram comprados (Monitorar, Negociação, Cessão e Assinatura)." />
            </div>
            <ul class="divide-y divide-mono-100 pt-1 text-sm">
                <li class="flex items-baseline justify-between gap-3 py-2">
                    <span class="text-mono-700">Aguardando o tribunal</span>
                    <span class="font-bold tabular-nums text-mono-900">{{ $open['count'] }}</span>
                </li>
                <li class="flex items-baseline justify-between gap-3 py-2">
                    <span class="{{ $open['overdue_count'] > 0 ? 'font-semibold text-down' : 'text-mono-700' }}">Com previsão vencida</span>
                    <span class="font-bold tabular-nums {{ $open['overdue_count'] > 0 ? 'text-down' : 'text-mono-900' }}">{{ $open['overdue_count'] }}</span>
                </li>
                <li class="flex items-baseline justify-between gap-3 py-2">
                    <span class="text-mono-700">Já recebidos</span>
                    <span class="font-bold tabular-nums text-up">{{ $received['count'] }}</span>
                </li>
                <li class="flex items-baseline justify-between gap-3 py-2">
                    <span class="text-mono-700">Em análise (ainda não comprados)</span>
                    <span class="font-bold tabular-nums text-mono-900">{{ $situation['pipeline_count'] }}</span>
                </li>
                @if ($situation['lost_count'] > 0)
                    <li class="flex items-baseline justify-between gap-3 py-2">
                        <span class="text-mono-700">Perdidos</span>
                        <span class="font-bold tabular-nums text-mono-900">{{ $situation['lost_count'] }}</span>
                    </li>
                @endif
            </ul>
            <p class="mt-1 text-[11px] text-mono-500">
                Em aberto: {{ $open['by_stage']['paid'] }} em Pagamento · {{ $open['by_stage']['petitioning'] }} em Peticionamento · {{ $open['by_stage']['awaiting_receipt'] }} aguardando recebimento
            </p>
        </x-jr.card>
    </div>

    <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="monthly">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="relative flex items-start gap-2">
                <div class="min-w-0">
                    <h2 class="text-base font-bold text-mono-900">Recebido por mês</h2>
                    <p class="mt-0.5 text-[11px] text-mono-500">Pela data em que o dinheiro entrou, no horário de Fortaleza</p>
                </div>
                <x-fx.help-tip label="O que significa este número" text="Cada barra é o total depositado naquele mês e o lucro daqueles recebimentos (depositado menos o que você tinha pago). A data usada é a data de recebimento. Meses sem depósito aparecem zerados na média." />
            </div>
            <div class="flex flex-wrap gap-1 rounded-pill border border-mono-200 bg-mono-white p-1" role="group" aria-label="Período do gráfico">
                <button type="button" wire:click="setMetricsPeriod('12m')"
                    class="flex h-11 shrink-0 items-center rounded-pill px-3 text-sm font-semibold transition-colors {{ $metricsPeriod === '12m' ? 'bg-mono-900 text-mono-white' : 'text-mono-600 hover:bg-mono-100' }}">
                    Últimos 12 meses
                </button>
                @foreach ($metrics['available_years'] as $year)
                    <button type="button" wire:click="setMetricsPeriod('{{ $year }}')"
                        class="flex h-11 shrink-0 items-center rounded-pill px-3 text-sm font-semibold transition-colors {{ $metricsPeriod === (string) $year ? 'bg-mono-900 text-mono-white' : 'text-mono-600 hover:bg-mono-100' }}">
                        {{ $year }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="mx-auto mt-4 w-full max-w-3xl" data-receipt-chart>
            <x-dashboard.chart type="bar" :title="'Recebido por mês · '.$chart['title']" :labels="$chart['labels']" :datasets="$chart['datasets']" :height="200" />
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[20rem] text-sm">
                <caption class="sr-only">Recebido e lucro por mês em {{ $chart['title'] }}</caption>
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-mono-500">
                        <th class="pb-2 pr-3 font-medium">Mês</th>
                        <th class="pb-2 pr-3 text-right font-medium">Recebido</th>
                        <th class="pb-2 pr-3 text-right font-medium">Lucro</th>
                        <th class="pb-2 text-right font-medium">Qtde</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-mono-100">
                    @foreach ($chart['months'] as $month)
                        <tr class="{{ $month['count'] === 0 ? 'text-mono-400' : 'text-mono-900' }}">
                            <td class="py-2 pr-3 font-medium">{{ $month['label'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $money($month['received']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums {{ $month['count'] > 0 ? ($month['profit'] >= 0 ? 'text-up' : 'text-down') : '' }}">{{ $money($month['profit']) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $month['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($received['without_date'] > 0)
            <p class="mt-3 text-[11px] text-mono-500">{{ $received['without_date'] }} {{ $received['without_date'] === 1 ? 'recebimento está' : 'recebimentos estão' }} sem data e não entrou no gráfico mensal. O total de “Já recebido” continua incluindo esse valor.</p>
        @endif
    </x-jr.card>

    <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
        <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="years">
            <div class="relative flex items-start justify-between gap-2">
                <h2 class="text-base font-bold text-mono-900">Resumo por ano</h2>
                <x-fx.help-tip label="O que significa este número" text="O ano é o do recebimento, não o da compra. O percentual é o lucro daquele ano dividido pelo custo dos requisitórios recebidos naquele ano." />
            </div>
            @if (empty($metrics['years']))
                <p class="mt-3 text-sm text-mono-500">Nenhum requisitório recebido ainda.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[20rem] text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-mono-500">
                                <th class="pb-2 pr-3 font-medium">Ano</th>
                                <th class="pb-2 pr-3 text-right font-medium">Recebido</th>
                                <th class="pb-2 pr-3 text-right font-medium">Custo</th>
                                <th class="pb-2 pr-3 text-right font-medium">Lucro</th>
                                <th class="pb-2 pr-3 text-right font-medium">%</th>
                                <th class="pb-2 text-right font-medium">Qtde</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mono-100">
                            @foreach ($metrics['years'] as $year)
                                <tr class="{{ (string) $year['year'] === $metricsPeriod ? 'bg-mono-50' : '' }}">
                                    <td class="py-2 pr-3 font-semibold text-mono-900">{{ $year['year'] }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-mono-900">{{ $money($year['received']) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-mono-900">{{ $money($year['cost']) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums font-semibold {{ $year['profit'] >= 0 ? 'text-up' : 'text-down' }}">{{ $money($year['profit']) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-mono-900">{{ $year['profit_pct'] === null ? '—' : $pct($year['profit_pct']) }}</td>
                                    <td class="py-2 text-right tabular-nums text-mono-900">{{ $year['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-jr.card>

        <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="averages">
            <div class="relative flex items-start justify-between gap-2">
                <h2 class="text-base font-bold text-mono-900">Médias</h2>
                <x-fx.help-tip label="O que significa este número" text="A média mensal divide o total recebido (e o lucro) pelos meses do período, inclusive os meses sem depósito, para não inflar o número. O tempo médio é da data da compra até a data do recebimento, só dos já encerrados com as duas datas. A rentabilidade mensal equivalente usa o conjunto dessas operações que ficaram pelo menos 30 dias — operações muito curtas ficam de fora, porque distorcem o cálculo." />
            </div>
            <dl class="mt-3 divide-y divide-mono-100 text-sm">
                <div class="flex items-start justify-between gap-3 py-3">
                    <dt class="text-mono-600">Média mensal em {{ $averages['year'] }} <span class="block text-[11px] text-mono-400">jan a {{ $monthNames[$averages['months_elapsed_year']] }}, {{ $averages['months_elapsed_year'] }} {{ $averages['months_elapsed_year'] === 1 ? 'mês' : 'meses' }} decorridos</span></dt>
                    <dd class="shrink-0 text-right">
                        <div class="font-semibold tabular-nums text-mono-900">{{ $money($averages['monthly_received_year']) }} / mês</div>
                        <div class="text-[11px] tabular-nums {{ $averages['monthly_profit_year'] >= 0 ? 'text-up' : 'text-down' }}">lucro {{ $money($averages['monthly_profit_year']) }}</div>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-3 py-3">
                    <dt class="text-mono-600">Média mensal nos últimos 12 meses</dt>
                    <dd class="shrink-0 text-right">
                        <div class="font-semibold tabular-nums text-mono-900">{{ $money($averages['monthly_received_12m']) }} / mês</div>
                        <div class="text-[11px] tabular-nums {{ $averages['monthly_profit_12m'] >= 0 ? 'text-up' : 'text-down' }}">lucro {{ $money($averages['monthly_profit_12m']) }}</div>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-3 py-3">
                    <dt class="text-mono-600">Tempo médio entre a compra e o recebimento</dt>
                    <dd class="shrink-0 text-right font-semibold tabular-nums text-mono-900">
                        @if ($averages['avg_months_to_receipt'] === null)
                            —
                        @else
                            {{ number_format($averages['avg_months_to_receipt'], 1, ',', '.') }} meses
                            <span class="block text-[11px] font-medium text-mono-500">{{ $averages['avg_days_to_receipt'] }} dias · {{ $averages['timed_count'] }} {{ $averages['timed_count'] === 1 ? 'caso' : 'casos' }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-3 py-3">
                    <dt class="text-mono-600">Lucro médio por requisitório recebido</dt>
                    <dd class="shrink-0 text-right font-semibold tabular-nums {{ ($averages['avg_profit_per_writ'] ?? 0) >= 0 ? 'text-up' : 'text-down' }}">
                        {{ $averages['avg_profit_per_writ'] === null ? '—' : $money($averages['avg_profit_per_writ']) }}
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-3 py-3">
                    <dt class="text-mono-600">Rentabilidade mensal equivalente</dt>
                    <dd class="shrink-0 text-right font-semibold tabular-nums text-mono-900">
                        @if ($averages['monthly_equivalent'] === null)
                            <span class="font-medium text-mono-500">sem base segura</span>
                        @else
                            {{ number_format($averages['monthly_equivalent']['percent'], 2, ',', '.') }}% ao mês
                            <span class="block text-[11px] font-medium text-mono-500">{{ $averages['monthly_equivalent']['count'] }} {{ $averages['monthly_equivalent']['count'] === 1 ? 'caso' : 'casos' }} com 30 dias ou mais</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-jr.card>
    </div>

    <x-jr.card :padding="false" class="min-w-0 p-4" data-metric="upcoming">
        <div class="relative flex items-start justify-between gap-2">
            <div>
                <h2 class="text-base font-bold text-mono-900">Próximos recebimentos previstos</h2>
                <p class="mt-0.5 text-[11px] text-mono-500">Com base na data da compra mais o prazo estimado. Nada foi alterado nos cadastros.</p>
            </div>
            <x-fx.help-tip label="O que significa este número" text="A previsão é a data da compra acrescida do prazo estimado em meses de cada requisitório. Os que já passaram dessa data aparecem em “Previsão vencida”, só para você ver — o sistema não muda etapa nem valor. Os demais entram no mês previsto." />
        </div>

        @if ($upcoming['overdue']['count'] > 0)
            <div class="mt-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3" data-overdue-forecast>
                <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                    <p class="font-bold text-down">{{ $upcoming['overdue']['count'] }} com previsão vencida</p>
                    <p class="text-sm font-semibold tabular-nums text-down">devem voltar {{ $money($upcoming['overdue']['expected_receipt']) }}</p>
                </div>
                <p class="mt-1 text-[11px] text-mono-600">Custo ainda em aberto nesses casos: {{ $money($upcoming['overdue']['cost']) }}. A lista do quadro abaixo continua igual; este aviso só destaca o atraso da previsão.</p>
            </div>
        @endif

        @if (empty($upcoming['months']) && $upcoming['overdue']['count'] === 0)
            <p class="mt-3 text-sm text-mono-500">Nenhum recebimento previsto em aberto.</p>
        @elseif (! empty($upcoming['months']))
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[20rem] text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-mono-500">
                            <th class="pb-2 pr-3 font-medium">Mês previsto</th>
                            <th class="pb-2 pr-3 text-right font-medium">Deve voltar</th>
                            <th class="pb-2 pr-3 text-right font-medium">Custo</th>
                            <th class="pb-2 text-right font-medium">Qtde</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-mono-100">
                        @foreach ($upcoming['months'] as $month)
                            <tr>
                                <td class="py-2 pr-3 font-medium text-mono-900">{{ $month['label'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-mono-900">{{ $money($month['expected_receipt']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-mono-900">{{ $money($month['cost']) }}</td>
                                <td class="py-2 text-right tabular-nums text-mono-900">{{ $month['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-jr.card>
</div>
