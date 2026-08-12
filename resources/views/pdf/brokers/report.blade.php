<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de Corretores</title>
    <style>
        @page { margin: 16mm 13mm 15mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: #1f2937;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.4;
        }

        .header {
            border-bottom: 2px solid #ff6f00;
            margin-bottom: 14px;
            padding-bottom: 11px;
        }

        .brand-table,
        .filter-table,
        .metric-table,
        .data-table {
            border-collapse: collapse;
            width: 100%;
        }

        .brand-mark {
            background: #ff6f00;
            border-radius: 8px;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            height: 35px;
            text-align: center;
            width: 35px;
        }

        .brand-name {
            font-size: 14px;
            font-weight: 700;
            padding-left: 9px;
        }

        .document-type {
            color: #6b7280;
            font-size: 8px;
            letter-spacing: .7px;
            text-align: right;
            text-transform: uppercase;
        }

        .document-title {
            color: #111827;
            font-size: 12px;
            font-weight: 700;
            margin-top: 2px;
            text-align: right;
        }

        .filters {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            margin-bottom: 13px;
            padding: 9px 11px;
        }

        .filter-table td {
            padding-right: 18px;
            vertical-align: top;
            width: 50%;
        }

        .label {
            color: #6b7280;
            display: block;
            font-size: 7px;
            letter-spacing: .35px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .value {
            color: #111827;
            font-size: 9px;
            font-weight: 700;
        }

        .metric-table {
            margin-bottom: 14px;
            table-layout: fixed;
        }

        .metric-table td {
            padding-right: 8px;
            vertical-align: top;
            width: 25%;
        }

        .metric-table td:last-child { padding-right: 0; }

        .metric {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            min-height: 52px;
            padding: 9px 10px;
        }

        .metric .value {
            font-size: 13px;
            margin-top: 3px;
        }

        .section { margin-bottom: 15px; }

        .section-title {
            border-bottom: 1px solid #e5e7eb;
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            margin: 0 0 7px;
            padding-bottom: 5px;
        }

        .section-count {
            color: #6b7280;
            float: right;
            font-size: 8px;
            font-weight: 400;
        }

        .data-table {
            border: 1px solid #e5e7eb;
            page-break-inside: auto;
        }

        .data-table thead { display: table-header-group; }
        .data-table tr { page-break-inside: avoid; }

        .data-table th {
            background: #f3f4f6;
            color: #6b7280;
            font-size: 7px;
            letter-spacing: .3px;
            padding: 6px 8px;
            text-align: left;
            text-transform: uppercase;
        }

        .data-table td {
            border-top: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
        }

        .text-right { text-align: right !important; }
        .strong { color: #111827; font-weight: 700; }

        .empty {
            border: 1px dashed #d1d5db;
            color: #6b7280;
            padding: 13px;
            text-align: center;
        }

        .footer {
            bottom: -10mm;
            color: #9ca3af;
            font-size: 7px;
            left: 0;
            position: fixed;
            right: 0;
            text-align: center;
        }

        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
@php
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $formatDate = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : null;
    $period = match (true) {
        (bool) ($rangeStart && $rangeEnd) => $formatDate($rangeStart).' a '.$formatDate($rangeEnd),
        (bool) $rangeStart => 'A partir de '.$formatDate($rangeStart),
        (bool) $rangeEnd => 'Até '.$formatDate($rangeEnd),
        default => 'Todo o histórico',
    };
@endphp

<div class="footer">
    Cassio Finance | Gerado em {{ $generatedAt->format('d/m/Y H:i') }} | Página <span class="page-number"></span>
</div>

<header class="header">
    <table class="brand-table">
        <tr>
            <td class="brand-mark">CM</td>
            <td class="brand-name">Cassio Finance</td>
            <td>
                <div class="document-type">Relatório</div>
                <div class="document-title">Corretores</div>
            </td>
        </tr>
    </table>
</header>

<section class="filters">
    <table class="filter-table">
        <tr>
            <td>
                <span class="label">Período</span>
                <span class="value">{{ $period }}</span>
            </td>
            <td>
                <span class="label">Corretor</span>
                <span class="value">{{ $selectedBroker?->name ?: 'Todos os corretores' }}</span>
            </td>
        </tr>
    </table>
</section>

<table class="metric-table">
    <tr>
        <td><div class="metric"><span class="label">Comissões (total)</span><div class="value">{{ $money($totalCommissions) }}</div></div></td>
        <td><div class="metric"><span class="label">Comissões pendentes</span><div class="value">{{ $money($totalPendingCommissions) }}</div></div></td>
        <td><div class="metric"><span class="label">Adiantamentos (total)</span><div class="value">{{ $money($totalAdvances) }}</div></div></td>
        <td><div class="metric"><span class="label">Adiantamentos a compensar</span><div class="value">{{ $money($openAdvancesBalance) }}</div></div></td>
    </tr>
</table>

<section class="section">
    <h2 class="section-title">
        Comissões pagas no período
        <span class="section-count">{{ $paidCommissions->count() }} registro(s)</span>
    </h2>
    @if ($paidCommissions->isEmpty())
        <div class="empty">Nenhuma comissão paga no período selecionado.</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 18%;">Data</th>
                    <th>Corretor</th>
                    <th>Tipo de caso</th>
                    <th class="text-right" style="width: 20%;">Comissão</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($paidCommissions as $commission)
                    <tr>
                        <td>{{ $commission->reference_date->format('d/m/Y') }}</td>
                        <td>{{ $commission->broker->name }}</td>
                        <td>{{ $commission->caseType?->name ?: '-' }}</td>
                        <td class="text-right strong">{{ $money($commission->commission_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<section class="section">
    <h2 class="section-title">
        Resumo por corretor
        <span class="section-count">{{ $brokers->count() }} corretor(es)</span>
    </h2>
    @if ($brokers->isEmpty())
        <div class="empty">Nenhum corretor com comissões no período selecionado.</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Corretor</th>
                    <th class="text-right" style="width: 25%;">Comissões (qtd.)</th>
                    <th class="text-right" style="width: 25%;">Valor total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($brokers as $broker)
                    <tr>
                        <td>{{ $broker->name }}</td>
                        <td class="text-right">{{ $broker->commissions->count() }}</td>
                        <td class="text-right strong">{{ $money($broker->commissions->sum('commission_amount')) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
</body>
</html>
