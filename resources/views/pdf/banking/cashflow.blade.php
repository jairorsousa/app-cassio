<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Fluxo de caixa</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #1f2937; }
        h1 { font-size: 14px; margin: 0 0 4px; }
        h2 { font-size: 11px; margin: 14px 0 6px; border-bottom: 1px solid #ff6f00; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; }
        .r { text-align: right; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    @php $m = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'); @endphp
    <h1>Fluxo de caixa</h1>
    <div class="muted">{{ $period->label() }} · Gerado em {{ $generatedAt->format('d/m/Y H:i') }} (horário de Fortaleza)</div>
    <div class="muted">@foreach ($filterLabels as $k => $l){{ $k }}: {{ $l }}@if (! $loop->last) · @endif @endforeach</div>

    <h2>Resumo</h2>
    <table>
        <tr><th></th><th class="r">Atual</th><th class="r">Período anterior</th><th class="r">Variação</th><th class="r">%</th></tr>
        @foreach (['income' => 'Receitas próprias', 'expense' => 'Despesas próprias', 'result' => 'Resultado próprio'] as $k => $l)
            @php $c = $report['comparison'][$k]; @endphp
            <tr><td>{{ $l }}</td><td class="r">{{ $m($c['current']) }}</td><td class="r">{{ $m($c['previous']) }}</td><td class="r">{{ $m($c['diff']) }}</td><td class="r">{{ $c['pct'] === null ? '—' : number_format($c['pct'], 1, ',', '.').'%' }}</td></tr>
        @endforeach
    </table>
    <table style="margin-top:6px">
        <tr><td>Saldo nas contas</td><td class="r">{{ $m($report['cash']['accounts_balance']) }}</td><td>Dinheiro de terceiros</td><td class="r">{{ $m($report['cash']['third_party_balance']) }}</td><td>Saldo próprio ({{ \Illuminate\Support\Carbon::parse($report['position_date'])->format('d/m/Y') }})</td><td class="r">{{ $m($report['cash']['own_balance']) }}</td></tr>
        <tr><td>Entradas realizadas</td><td class="r">{{ $m($report['realized']['gross_in']) }}</td><td>Saídas realizadas</td><td class="r">{{ $m($report['realized']['gross_out']) }}</td><td>Previsto (entradas / saídas)</td><td class="r">{{ $m($report['forecast']['income']) }} / {{ $m($report['forecast']['expense']) }}</td></tr>
    </table>

    <h2>Por categoria</h2>
    <table>
        <tr><th>Categoria</th><th class="r">Receita própria</th><th class="r">Despesa própria</th><th class="r">Outras entradas</th><th class="r">Outras saídas</th><th class="r">Resultado</th></tr>
        @foreach ($report['realized']['by_category'] as $name => $row)
            <tr><td>{{ $name }}</td><td class="r">{{ $m($row['own_income']) }}</td><td class="r">{{ $m($row['own_expense']) }}</td><td class="r">{{ $m($row['third_party_in']) }}</td><td class="r">{{ $m($row['third_party_out']) }}</td><td class="r">{{ $m($row['own_income'] - $row['own_expense']) }}</td></tr>
        @endforeach
    </table>

    <h2>Por conta</h2>
    <table>
        <tr><th>Conta</th><th class="r">Entradas</th><th class="r">Saídas</th><th class="r">Saldo do período</th></tr>
        @foreach ($report['realized']['by_account'] as $name => $row)
            <tr><td>{{ $name }}</td><td class="r">{{ $m($row['in']) }}</td><td class="r">{{ $m($row['out']) }}</td><td class="r">{{ $m($row['in'] - $row['out']) }}</td></tr>
        @endforeach
    </table>

    <h2>Previsto no período</h2>
    <table>
        <tr><th>Data</th><th>Origem</th><th>Descrição</th><th class="r">Entrada</th><th class="r">Saída</th></tr>
        @forelse ($report['forecast']['items'] as $i)
            <tr><td>{{ \Illuminate\Support\Carbon::parse($i['date'])->format('d/m/Y') }}</td><td>{{ $i['source'] }}</td><td>{{ $i['description'] }}</td><td class="r">{{ $i['direction'] === 'in' ? $m($i['amount']) : '' }}</td><td class="r">{{ $i['direction'] === 'out' ? $m($i['amount']) : '' }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">Nada previsto.</td></tr>
        @endforelse
    </table>

    <h2>Projeção ({{ $projection['months'] }} meses, a partir de {{ $m($projection['start_balance']) }})</h2>
    <table>
        <tr><th>Mês</th><th class="r">Entradas</th><th class="r">Saídas</th><th class="r">Líquido</th><th class="r">Saldo projetado</th></tr>
        @foreach ($projection['rows'] as $row)
            <tr><td>{{ $row['label'] }}</td><td class="r">{{ $m($row['income']) }}</td><td class="r">{{ $m($row['expense']) }}</td><td class="r">{{ $m($row['net']) }}</td><td class="r">{{ $m($row['balance']) }}</td></tr>
        @endforeach
    </table>
    <p class="muted">Regras: transferências, repasses, adiantamentos e principal emprestado/devolvido fora do resultado próprio; juros recebidos entram. Requisitórios previstos apenas em Pago, Peticionamento e Aguardando recebimento.</p>
</body>
</html>
