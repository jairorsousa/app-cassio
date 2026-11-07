<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Extrato · {{ $group->name }}</title>
<style>
    @page { margin: 28px 30px 46px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f2937; }
    .header { border-bottom: 3px solid #ff6f00; padding-bottom: 10px; margin-bottom: 12px; }
    .brand-logo { width: 34px; height: 34px; vertical-align: middle; }
    .brand-name { font-size: 15px; font-weight: bold; vertical-align: middle; margin-left: 6px; }
    h1 { font-size: 15px; margin: 8px 0 2px; }
    .muted { color: #6b7280; }
    .filters { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 7px 10px; margin-bottom: 10px; }
    .cards { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px; }
    .card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 7px 9px; }
    .card .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; }
    .card .val { font-size: 12px; font-weight: bold; margin-top: 2px; }
    h2 { font-size: 11.5px; margin: 14px 0 6px; color: #ff6f00; }
    table.data-table { width: 100%; border-collapse: collapse; }
    .data-table th { background: #fff3e0; text-align: left; padding: 5px 4px; font-size: 8.5px; border-bottom: 1px solid #ffcc80; }
    .data-table td { padding: 4px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .pos { color: #15803d; } .neg { color: #b91c1c; }
    .opening td { background: #f8fafc; font-style: italic; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #6b7280; text-align: center; }
    .footer .page:after { content: counter(page); }
</style>
</head>
<body>
@php
    $fmtDate = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
    $cls = fn (int $v) => $v > 0 ? 'pos' : ($v < 0 ? 'neg' : '');
@endphp
<div class="footer">Vértice CM | Gerado em {{ $generatedAt->format('d/m/Y H:i') }} | Página <span class="page"></span></div>

<div class="header">
    <img src="{{ $logo }}" class="brand-logo" alt="">
    <span class="brand-name">Vértice CM</span>
    <h1>Extrato do grupo · {{ $group->name }}</h1>
    <div class="muted">{{ $group->typeLabel() }} · {{ $members->count() }} participante(s)</div>
</div>

<div class="filters">
    <strong>Período:</strong>
    {{ $rangeStart ? $fmtDate($rangeStart) : 'início' }} a {{ $rangeEnd ? $fmtDate($rangeEnd) : 'hoje' }}
    · <strong>Participantes:</strong> {{ $members->map(fn ($m) => $m->name.($m->active ? '' : ' (desativado)'))->join(', ') }}
</div>

<table class="cards"><tr>
    <td class="card"><div class="lbl">Despesas no período</div><div class="val">R$ {{ $money($report['totals']['expenses']) }}</div></td>
    <td class="card"><div class="lbl">Acertos no período</div><div class="val">R$ {{ $money($report['totals']['settlements']) }}</div></td>
    <td class="card"><div class="lbl">Movimentos</div><div class="val">{{ count($report['rows']) }}</div></td>
</tr></table>

<h2>Movimentos e saldo acumulado por pessoa</h2>
<table class="data-table">
    <thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th class="r">Valor</th>@foreach ($members as $m)<th class="r">{{ $m->name }}</th>@endforeach</tr></thead>
    <tbody>
        @if ($rangeStart)
            <tr class="opening"><td>{{ $fmtDate($rangeStart) }}</td><td>Saldo</td><td>Saldo anterior ao período</td><td></td>
                @foreach ($members as $m)@php($v = (int) ($report['opening'][$m->id] ?? 0))<td class="r {{ $cls($v) }}">{{ $money($v) }}</td>@endforeach</tr>
        @endif
        @forelse ($report['rows'] as $r)
            <tr><td>{{ $fmtDate($r['date']) }}</td><td>{{ $r['kind'] }}</td><td>{{ $r['description'] }}</td><td class="r">{{ $money($r['cents']) }}</td>
                @foreach ($members as $m)@php($v = (int) ($r['running'][$m->id] ?? 0))<td class="r {{ $cls($v) }}">{{ $money($v) }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ 4 + $members->count() }}" class="muted">Sem movimentos no período.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>Saldo final</h2>
<table class="data-table">
    <thead><tr><th>Participante</th><th class="r">Saldo</th><th>Situação</th></tr></thead>
    <tbody>
        @foreach ($members as $m)@php($v = (int) ($report['closing'][$m->id] ?? 0))
            <tr><td>{{ $m->name }}</td><td class="r {{ $cls($v) }}">R$ {{ $money($v) }}</td><td>{{ $v > 0 ? 'tem a receber' : ($v < 0 ? 'deve' : 'quitado') }}</td></tr>
        @endforeach
    </tbody>
</table>

<h2>Quem paga quem</h2>
@if ($report['transfers'])
    <table class="data-table">
        <thead><tr><th>Quem paga</th><th>Para quem</th><th class="r">Valor</th></tr></thead>
        <tbody>
            @foreach ($report['transfers'] as $t)
                <tr><td>{{ $members[$t['from']]->name ?? '—' }}</td><td>{{ $members[$t['to']]->name ?? '—' }}</td><td class="r">R$ {{ $money($t['cents']) }}</td></tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">Ninguém deve nada. Grupo quitado{{ $rangeEnd ? ' até '.$fmtDate($rangeEnd) : '' }}.</p>
@endif
</body>
</html>
