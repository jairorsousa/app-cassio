<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Requisitório {{ $writ->process_number ?: '#'.$writ->id }}</title>
    <style>
        @page { margin: 22mm 15mm 18mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: #1f2937;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.45;
        }

        .header {
            border-bottom: 2px solid #ff6f00;
            margin-bottom: 18px;
            padding-bottom: 14px;
        }

        .brand-table,
        .info-table,
        .data-table,
        .metric-table {
            border-collapse: collapse;
            width: 100%;
        }

        .brand-mark {
            background: #ff6f00;
            border-radius: 9px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            height: 38px;
            text-align: center;
            width: 38px;
        }

        .brand-name {
            font-size: 14px;
            font-weight: 700;
            padding-left: 10px;
        }

        .document-type {
            color: #6b7280;
            font-size: 8px;
            letter-spacing: 1px;
            text-align: right;
            text-transform: uppercase;
        }

        .document-number {
            font-size: 11px;
            font-weight: 700;
            padding-top: 3px;
            text-align: right;
        }

        .hero {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 18px;
            padding: 14px 16px;
        }

        .hero-title {
            color: #111827;
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .hero-subtitle { color: #6b7280; }

        .status {
            background: #dcfce7;
            border-radius: 999px;
            color: #047857;
            display: inline-block;
            font-size: 8px;
            font-weight: 700;
            padding: 5px 10px;
        }

        .section {
            margin-bottom: 16px;
            page-break-inside: avoid;
        }

        .section-title {
            border-bottom: 1px solid #e5e7eb;
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            margin: 0 0 9px;
            padding-bottom: 5px;
        }

        .info-table td {
            padding: 5px 10px 5px 0;
            vertical-align: top;
            width: 33.333%;
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

        .data-table {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

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
            padding: 7px 8px;
            vertical-align: top;
        }

        .metric-table { table-layout: fixed; }

        .metric-table td {
            padding: 0 7px 8px 0;
            vertical-align: top;
            width: 25%;
        }

        .metric {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            min-height: 49px;
            padding: 8px;
        }

        .metric .value { font-size: 10px; }
        .metric.emphasis { background: #ecfdf5; border-color: #a7f3d0; }
        .metric.emphasis .value { color: #047857; }

        .note {
            background: #fff7ed;
            border-left: 3px solid #ff6f00;
            padding: 8px 10px;
            white-space: pre-line;
        }

        .muted { color: #6b7280; }

        .footer {
            bottom: -12mm;
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
    $date = fn ($value, $withTime = false) => $value ? $value->format($withTime ? 'd/m/Y H:i' : 'd/m/Y') : '-';
    $clientName = $writ->assignors->first()?->contact?->name ?: ($writ->assignor_name ?: 'Cedente não informado');
    $stageLabel = $writ->stage === 'finalized' ? 'Finalizado' : $writ->stageLabel();
    $profit = $writ->stage === 'finalized' ? $writ->actualProfit() : $writ->estimatedProfit();
    $profitPercentage = $writ->stage === 'finalized' ? $writ->actualProfitPercentage() : $writ->estimatedProfitPercentage();
    $receiptAmount = $writ->stage === 'finalized' ? $writ->actual_receipt_amount : $writ->estimated_receipt_amount;
    $receiptLabel = $writ->stage === 'finalized' ? 'Valor recebido' : 'Recebimento estimado';
    $dates = array_filter([
        'Monitoramento' => $writ->monitoring_at ? $date($writ->monitoring_at, true) : null,
        'Cessão' => $writ->cession_at ? $date($writ->cession_at, true) : null,
        'Pagamento' => $writ->paid_at ? $date($writ->paid_at) : null,
        'Peticionamento' => $writ->petitioned_at ? $date($writ->petitioned_at, true) : null,
        'Aguardando recebimento' => $writ->awaiting_receipt_at ? $date($writ->awaiting_receipt_at, true) : null,
        'Recebimento' => $writ->finalized_at ? $date($writ->finalized_at) : null,
        'Perda' => $writ->lost_at ? $date($writ->lost_at, true) : null,
    ]);
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
                <div class="document-type">Requisitório</div>
                <div class="document-number">{{ $writ->process_number ?: '#'.$writ->id }}</div>
            </td>
        </tr>
    </table>
</header>

<section class="hero">
    <table class="brand-table">
        <tr>
            <td>
                <h1 class="hero-title">{{ $clientName }}</h1>
                <div class="hero-subtitle">{{ $writ->type === 'rpv' ? 'RPV' : 'Precatório' }} | {{ $writ->debtor_entity ?: 'Ente devedor não informado' }}</div>
            </td>
            <td style="text-align: right; width: 145px;">
                <span class="status">{{ $stageLabel }}</span>
            </td>
        </tr>
    </table>
</section>

<section class="section">
    <h2 class="section-title">Identificação</h2>
    <table class="info-table">
        <tr>
            <td><span class="label">Processo</span><span class="value">{{ $writ->process_number ?: '-' }}</span></td>
            <td><span class="label">Vara / Tribunal</span><span class="value">{{ $writ->court ?: '-' }}</span></td>
            <td><span class="label">Natureza do crédito</span><span class="value">{{ $writ->credit_nature ?: '-' }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Ente devedor</span><span class="value">{{ $writ->debtor_entity ?: '-' }}</span></td>
            <td><span class="label">Tipo</span><span class="value">{{ $writ->type === 'rpv' ? 'RPV' : 'Precatório' }}</span></td>
            <td><span class="label">Etapa atual</span><span class="value">{{ $stageLabel }}</span></td>
        </tr>
    </table>
</section>

<section class="section">
    <h2 class="section-title">Cedentes</h2>
    <table class="data-table">
        <thead>
        <tr>
            <th style="width: 15%;">Papel</th>
            <th style="width: 32%;">Nome</th>
            <th style="width: 20%;">CPF / CNPJ</th>
            <th>Contato</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($writ->assignors as $assignor)
            <tr>
                <td>{{ $assignor->role === 'advogado' ? 'Advogado' : 'Parte' }}</td>
                <td><strong>{{ $assignor->contact?->name ?: '-' }}</strong></td>
                <td>{{ $assignor->contact?->document ?: '-' }}</td>
                <td>
                    {{ $assignor->contact?->phone ?: $assignor->contact?->email ?: '-' }}
                </td>
            </tr>
        @empty
            <tr>
                <td>Parte</td>
                <td><strong>{{ $writ->assignor_name ?: 'Cedente não informado' }}</strong></td>
                <td>{{ $writ->assignor_document ?: '-' }}</td>
                <td>{{ $writ->assignor_contact ?: '-' }}</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</section>

<section class="section">
    <h2 class="section-title">Valores e resultado</h2>
    <table class="metric-table">
        <tr>
            <td><div class="metric"><span class="label">Valor de face</span><span class="value">{{ $money($writ->face_value) }}</span></div></td>
            <td><div class="metric"><span class="label">Parte negociada</span><span class="value">{{ $money($writ->negotiated_amount) }}</span></div></td>
            <td><div class="metric"><span class="label">Valor da proposta</span><span class="value">{{ $money($writ->proposed_amount) }}</span></div></td>
            <td><div class="metric"><span class="label">Valor pago</span><span class="value">{{ $money($writ->paid_amount) }}</span></div></td>
        </tr>
        <tr>
            <td><div class="metric"><span class="label">Despesas cartorárias</span><span class="value">{{ $money($writ->notary_expenses_amount) }}</span></div></td>
            <td><div class="metric"><span class="label">Outras despesas</span><span class="value">{{ $money($writ->other_expenses_amount) }}</span></div></td>
            <td><div class="metric"><span class="label">Custo total</span><span class="value">{{ $money($writ->totalCost()) }}</span></div></td>
            <td><div class="metric"><span class="label">Deságio calculado</span><span class="value">{{ number_format($writ->discountPercentageCalculated(), 2, ',', '.') }}%</span></div></td>
        </tr>
        <tr>
            <td><div class="metric emphasis"><span class="label">{{ $receiptLabel }}</span><span class="value">{{ $money($receiptAmount) }}</span></div></td>
            <td><div class="metric emphasis"><span class="label">Lucro {{ $writ->stage === 'finalized' ? 'real' : 'estimado' }}</span><span class="value">{{ $money($profit) }}</span></div></td>
            <td><div class="metric emphasis"><span class="label">Retorno</span><span class="value">{{ number_format((float) $profitPercentage, 2, ',', '.') }}%</span></div></td>
            <td><div class="metric"><span class="label">Prazo</span><span class="value">{{ $writ->stage === 'finalized' ? ($writ->actualMonths() ?? '-') : ($writ->estimated_months ?? '-') }} meses</span></div></td>
        </tr>
    </table>
</section>

@if ($dates !== [])
    <section class="section">
        <h2 class="section-title">Datas do requisitório</h2>
        <table class="data-table">
            <thead><tr><th>Evento</th><th style="width: 35%;">Data</th></tr></thead>
            <tbody>
            @foreach ($dates as $label => $formattedDate)
                <tr><td>{{ $label }}</td><td><strong>{{ $formattedDate }}</strong></td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif

@if ($writ->lost_reason)
    <section class="section">
        <h2 class="section-title">Motivo da perda</h2>
        <div class="note">{{ $writ->lost_reason }}</div>
    </section>
@endif

@if ($writ->notes)
    <section class="section">
        <h2 class="section-title">Observações</h2>
        <div class="note">{{ $writ->notes }}</div>
    </section>
@endif

@if ($writ->history->isNotEmpty())
    <section class="section">
        <h2 class="section-title">Histórico de etapas</h2>
        <table class="data-table">
            <thead>
            <tr>
                <th style="width: 22%;">Data</th>
                <th style="width: 38%;">Movimentação</th>
                <th>Responsável / observação</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($writ->history as $historyItem)
                <tr>
                    <td>{{ $date($historyItem->transitioned_at, true) }}</td>
                    <td>
                        {{ $historyItem->from_stage ? \App\Domains\Writs\Models\Writ::STAGE_LABELS[$historyItem->from_stage] : 'Criado' }}
                        @if ($historyItem->from_stage) -&gt; @endif
                        {{ \App\Domains\Writs\Models\Writ::STAGE_LABELS[$historyItem->to_stage] }}
                    </td>
                    <td>
                        {{ $historyItem->user?->name ?: 'Sistema' }}
                        @if ($historyItem->notes)<br><span class="muted">{{ $historyItem->notes }}</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif

@if ($writ->transactions->isNotEmpty())
    <section class="section">
        <h2 class="section-title">Lançamentos vinculados</h2>
        <table class="data-table">
            <thead>
            <tr><th style="width: 18%;">Data</th><th style="width: 15%;">Tipo</th><th>Descrição</th><th style="width: 22%; text-align: right;">Valor</th></tr>
            </thead>
            <tbody>
            @foreach ($writ->transactions as $transaction)
                <tr>
                    <td>{{ $date($transaction->date) }}</td>
                    <td>{{ $transaction->type === 'income' ? 'Receita' : 'Despesa' }}</td>
                    <td>{{ $transaction->description }}</td>
                    <td style="text-align: right;"><strong>{{ $money($transaction->amount) }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif
</body>
</html>
