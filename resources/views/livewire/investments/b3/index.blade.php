<?php

use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\B3MidasImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads, WithPagination;

    public $positionFile = null;

    public $incomeFile = null;

    public string $baseDate = '';

    public ?int $previewBatchId = null;

    public ?array $preview = null;

    public string $year = '';

    public string $type = '';

    public string $ticker = '';

    public function updated($property): void
    {
        if (in_array($property, ['year', 'type', 'ticker'], true)) {
            $this->resetPage();
        }
    }

    public function generatePreview(B3MidasImportService $service): void
    {
        $this->validate([
            'positionFile' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
            'incomeFile' => ['nullable', 'file', 'mimes:csv,txt', 'max:10240'],
            'baseDate' => [$this->positionFile ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ], ['baseDate.required' => 'Informe a data-base da posição B3.']);
        if (! $this->positionFile && ! $this->incomeFile) {
            $this->addError('positionFile', 'Envie posicao.csv e/ou proventos.csv.');

            return;
        }
        $dir = 'imports/b3/'.Str::uuid();
        $posPath = $this->positionFile?->storeAs($dir, 'posicao.csv', 'local');
        $incPath = $this->incomeFile?->storeAs($dir, 'proventos.csv', 'local');
        $pos = $posPath ? Storage::disk('local')->get($posPath) : null;
        $inc = $incPath ? Storage::disk('local')->get($incPath) : null;
        $result = $service->preview($pos, $inc, $this->baseDate ?: null);
        $batch = InvestmentImportBatch::create([
            'source' => 'b3_midas', 'base_date' => $this->baseDate ?: null, 'status' => 'preview', 'created_by' => auth()->id(),
            'position_file_name' => $this->positionFile?->getClientOriginalName(), 'income_file_name' => $this->incomeFile?->getClientOriginalName(),
            'position_path' => $posPath, 'income_path' => $incPath,
            'position_file_hash' => $pos !== null ? hash('sha256', $pos) : null, 'income_file_hash' => $inc !== null ? hash('sha256', $inc) : null,
            'summary' => $result['summary'],
        ]);
        $this->previewBatchId = $batch->id;
        $this->preview = ['ok' => $result['ok'], 'errors' => $result['errors'], 'warnings' => $result['warnings'], 'summary' => $result['summary'], 'sample' => array_slice($result['positions'], 0, 40)];
        $this->reset(['positionFile', 'incomeFile']);
    }

    public function confirmImport(B3MidasImportService $service): void
    {
        $batch = InvestmentImportBatch::where('status', 'preview')->find($this->previewBatchId);
        if (! $batch) {
            session()->flash('error', 'Prévia não encontrada ou já confirmada.');

            return;
        }
        $pos = $batch->position_path ? Storage::disk('local')->get($batch->position_path) : null;
        $inc = $batch->income_path ? Storage::disk('local')->get($batch->income_path) : null;
        if (($pos !== null && hash('sha256', $pos) !== $batch->position_file_hash) || ($inc !== null && hash('sha256', $inc) !== $batch->income_file_hash)) {
            session()->flash('error', 'Os arquivos mudaram depois da prévia. Gere a prévia de novo.');

            return;
        }
        try {
            $created = $service->confirm($service->preview($pos, $inc, $batch->base_date?->toDateString()), $batch, auth()->id());
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        $this->preview = null;
        $this->previewBatchId = null;
        session()->flash('status', "Importação confirmada: {$created['positions']} posições e {$created['income']} proventos novos. Nenhum lançamento foi criado no Financeiro.");
    }

    public function discardPreview(): void
    {
        $batch = InvestmentImportBatch::where('status', 'preview')->find($this->previewBatchId);
        if ($batch) {
            foreach (array_filter([$batch->position_path, $batch->income_path]) as $p) {
                Storage::disk('local')->delete($p);
            }
            $batch->delete();
        }
        $this->preview = null;
        $this->previewBatchId = null;
    }

    public function with(): array
    {
        $positions = B3PositionSnapshot::latestConfirmed();
        $events = AssetIncomeEvent::query()
            ->when($this->year !== '', fn ($q) => $q->whereYear('payment_date', (int) $this->year))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->ticker !== '', fn ($q) => $q->where('ticker', strtoupper($this->ticker)))
            ->orderByDesc('payment_date')->orderByDesc('id');

        return [
            'positions' => $positions,
            'baseDateShown' => $positions->first()?->base_date,
            'events' => (clone $events)->paginate(50),
            'eventsTotal' => (float) (clone $events)->sum('net_amount'),
            'byYear' => AssetIncomeEvent::selectRaw('substr(payment_date, 1, 4) as y, type, sum(net_amount) as total, count(*) as n')->groupBy('y', 'type')->orderBy('y')->get()->groupBy('y'),
            'years' => AssetIncomeEvent::selectRaw('distinct substr(payment_date, 1, 4) as y')->orderByDesc('y')->pluck('y'),
        ];
    }
}; ?>

<x-slot name="header">Investimentos</x-slot>

@php $m = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.'); $q = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 6, ',', '.'), '0'), ','); @endphp

<div class="flex flex-col gap-space-5">
    <x-investments.subnav />
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
    @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif

    <x-fx.card>
        <div class="flex flex-wrap items-end gap-space-3">
            <div>
                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Posição B3 (custódia e aluguel)</h3>
                @if ($baseDateShown)
                    <div class="text-fs-12 text-cryptex-text-secondary">Fonte: B3 Área do Investidor (export Midas) · data-base <strong>{{ $baseDateShown->format('d/m/Y') }}</strong> · agregada por ticker (todas as corretoras)</div>
                @else
                    <div class="text-fs-12 text-cryptex-text-secondary">Nenhuma posição B3 importada ainda.</div>
                @endif
            </div>
            @if ($positions->isNotEmpty())
                <div class="ml-auto text-right">
                    <div class="text-fs-12 text-cryptex-text-tertiary uppercase">Valor de mercado na data-base</div>
                    <div class="text-fs-20 font-bold font-mono">{{ $m($positions->sum('market_value')) }}</div>
                </div>
            @endif
        </div>
        @if ($positions->isNotEmpty())
            <div class="mt-space-3 space-y-2 md:hidden" data-mobile="b3-positions">
                @foreach ($positions as $p)
                    <div class="rounded-sm border border-cryptex-border-default p-3 text-sm">
                        <div class="flex items-start justify-between gap-2"><div><strong>{{ $p->ticker }}</strong> <span class="text-xs text-mono-600">{{ \App\Domains\Investments\Models\B3PositionSnapshot::ORIGIN_LABELS[$p->origin] ?? $p->origin }}</span></div><strong>{{ $m($p->market_value) }}</strong></div>
                        <div class="text-xs text-mono-600 break-words">{{ $p->product }}</div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                            <dt class="text-mono-600">Quantidade</dt><dd class="text-right">{{ $q($p->quantity) }}</dd>
                            <dt class="text-mono-600">Custódia / emprestada</dt><dd class="text-right">{{ $p->quantity_custody === null ? 'não separado' : $q($p->quantity_custody) }} / {{ $p->quantity_lent === null ? 'não separado' : $q($p->quantity_lent) }}</dd>
                            <dt class="text-mono-600">PM</dt><dd class="text-right">{!! $p->hasAveragePrice() ? e($m($p->average_price)) : '<span class="text-system-down">não informado</span>' !!}</dd>
                            <dt class="text-mono-600">Fechamento</dt><dd class="text-right">{{ $m($p->close_price) }}</dd>
                        </dl>
                        @if ($p->institutions)<div class="mt-1 text-xs text-mono-600 break-words">{{ $p->institutions }}</div>@endif
                    </div>
                @endforeach
            </div>
            <div class="mt-space-3 hidden overflow-x-auto md:block">
                <table class="fx-table w-full text-sm">
                    <thead><tr><th class="text-left">Ativo</th><th class="text-left">Situação</th><th class="text-right">Quantidade</th><th class="text-right">Custódia</th><th class="text-right">Emprestada</th><th class="text-right">PM</th><th class="text-right">Fechamento</th><th class="text-right">Valor</th><th class="text-left">Instituições (B3)</th></tr></thead>
                    <tbody>
                        @foreach ($positions as $p)
                            <tr>
                                <td><strong>{{ $p->ticker }}</strong><div class="text-xs text-mono-600">{{ $p->product }}</div></td>
                                <td>{{ \App\Domains\Investments\Models\B3PositionSnapshot::ORIGIN_LABELS[$p->origin] ?? $p->origin }}</td>
                                <td class="text-right">{{ $q($p->quantity) }}</td>
                                <td class="text-right">{{ $p->quantity_custody === null ? 'não separado' : $q($p->quantity_custody) }}</td>
                                <td class="text-right">{{ $p->quantity_lent === null ? 'não separado' : $q($p->quantity_lent) }}</td>
                                <td class="text-right">{!! $p->hasAveragePrice() ? e($m($p->average_price)) : '<span class="text-system-down">não informado</span>' !!}</td>
                                <td class="text-right">{{ $m($p->close_price) }}</td>
                                <td class="text-right font-semibold">{{ $m($p->market_value) }}</td>
                                <td class="text-xs">{{ $p->institutions }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-mono-600">PM “não informado” fica ausente (não é zero) e não entra em custo/rentabilidade. Instituições são as informadas pela B3; não foram vinculadas a contas.</p>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-fs-16 font-semibold text-cryptex-text-primary mb-space-3">Proventos recebidos (líquido)</h3>
        <p class="text-xs text-mono-600 mb-space-3">Registrados só em Investimentos; não criam lançamentos no Financeiro (as entradas antigas já estão nas contas). Reembolso de aluguel é o valor repassado pelo tomador, não a taxa do empréstimo.</p>
        @php $btc = \App\Domains\Investments\Models\AssetIncomeEvent::where('type', 'lending_fee')->selectRaw('count(*) n, sum(gross_amount) g, sum(withholding_tax) ir, sum(net_amount) l, min(payment_date) a, max(payment_date) b')->first(); @endphp
        @if ($btc && $btc->n > 0)
            <p class="text-xs mb-space-3">Taxas de aluguel (BTC) recebidas: {{ $btc->n }} lançamento(s) de {{ \Illuminate\Support\Carbon::parse($btc->a)->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($btc->b)->format('d/m/Y') }} · bruto <strong>{{ $m($btc->g) }}</strong> − IR {{ $m($btc->ir) }} = líquido <strong>{{ $m($btc->l) }}</strong>. Corretagem/emolumentos do aluguel ficam no extrato como pendência manual (não descontados aqui).</p>
        @endif
        @if ($byYear->isNotEmpty())
            <div class="overflow-x-auto mb-space-3">
                <table class="fx-table w-full text-sm">
                    <thead><tr><th class="text-left">Ano</th>@foreach (\App\Domains\Investments\Models\AssetIncomeEvent::TYPE_LABELS as $k => $l)@if (! in_array($k, ['other', 'yield']))<th class="text-right">{{ $l }}</th>@endif @endforeach<th class="text-right">Total</th></tr></thead>
                    <tbody>
                        @foreach ($byYear as $y => $rows)
                            <tr><td>{{ $y }}</td>@foreach (['dividend', 'jcp', 'fii_income', 'lending_reimbursement', 'lending_fee'] as $k)<td class="text-right">{{ $m($rows->firstWhere('type', $k)?->total ?? 0) }}</td>@endforeach<td class="text-right font-semibold">{{ $m($rows->sum('total')) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="flex flex-wrap gap-space-3 items-end mb-space-3 text-sm">
            <label class="flex flex-col gap-1">Ano<select wire:model.live="year" class="h-10 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2"><option value="">Todos</option>@foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></label>
            <label class="flex flex-col gap-1">Tipo<select wire:model.live="type" class="h-10 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2"><option value="">Todos</option>@foreach (\App\Domains\Investments\Models\AssetIncomeEvent::TYPE_LABELS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label class="flex flex-col gap-1">Ticker<input wire:model.live.debounce.500ms="ticker" class="h-10 w-28 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 uppercase" /></label>
            <div class="ml-auto">Total filtrado: <strong>{{ $m($eventsTotal) }}</strong></div>
        </div>
        <div class="space-y-2 md:hidden" data-mobile="b3-income">
            @forelse ($events as $e)
                <div class="rounded-sm border border-cryptex-border-default p-3 text-sm">
                    <div class="flex items-start justify-between gap-2"><div><strong>{{ $e->ticker }}</strong> <span class="text-xs text-mono-600">{{ $e->typeLabel() }}</span></div><strong class="text-system-up">{{ $m($e->net_amount) }}</strong></div>
                    <div class="mt-1 text-xs text-mono-600">{{ $e->payment_date->format('d/m/Y') }} · {{ $e->broker_name ?: '—' }} · {{ $e->source === 'b3_midas' ? 'B3 (Midas)' : ($e->source === 'broker_statement' ? 'Extrato corretora' : $e->source) }}</div>
                    <div class="mt-1 text-xs text-mono-600">Qtd. {{ $q($e->quantity) }} · Bruto {{ $e->gross_amount === null ? 'não informado' : $m($e->gross_amount) }} · IR {{ $e->withholding_tax === null ? '—' : $m($e->withholding_tax) }}</div>
                </div>
            @empty
                <p class="text-sm text-mono-600">Nenhum provento importado.</p>
            @endforelse
        </div>
        <div class="hidden overflow-x-auto md:block">
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Pagamento</th><th class="text-left">Ativo</th><th class="text-left">Tipo</th><th class="text-right">Qtd. base</th><th class="text-right">Bruto</th><th class="text-right">IR</th><th class="text-right">Líquido</th><th class="text-left">Corretora</th><th class="text-left">Origem</th></tr></thead>
                <tbody>
                    @forelse ($events as $e)
                        <tr><td>{{ $e->payment_date->format('d/m/Y') }}</td><td>{{ $e->ticker }}</td><td>{{ $e->typeLabel() }}</td><td class="text-right">{{ $q($e->quantity) }}</td><td class="text-right">{{ $e->gross_amount === null ? 'não informado' : $m($e->gross_amount) }}</td><td class="text-right">{{ $e->withholding_tax === null ? '—' : $m($e->withholding_tax) }}</td><td class="text-right font-semibold">{{ $m($e->net_amount) }}</td><td>{{ $e->broker_name }}</td><td>{{ $e->source === 'b3_midas' ? 'B3 (Midas)' : ($e->source === 'broker_statement' ? 'Extrato corretora' : $e->source) }}</td></tr>
                    @empty
                        <tr><td colspan="9" class="text-mono-600">Nenhum provento importado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-space-3">{{ $events->links() }}</div>
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Importar export B3 (Midas)</h3>
        <p class="text-xs text-mono-600 mb-space-3">Primeiro gera uma prévia sem gravar nada. Só depois de conferir, confirme. Reimportar o mesmo arquivo não duplica (cada linha tem chave de origem).</p>
        <form wire:submit="generatePreview" class="grid gap-space-3 md:grid-cols-4 items-end text-sm">
            <label class="flex flex-col gap-1">posicao.csv<input type="file" wire:model="positionFile" accept=".csv,text/csv" /></label>
            <label class="flex flex-col gap-1">Data-base da posição<input type="date" wire:model="baseDate" class="h-10 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2" /></label>
            <label class="flex flex-col gap-1">proventos.csv<input type="file" wire:model="incomeFile" accept=".csv,text/csv" /></label>
            <button type="submit" class="min-h-[40px] px-3 rounded-sm bg-cryptex-brand-400 text-white font-semibold">Gerar prévia</button>
        </form>
        @error('positionFile')<div class="text-system-down text-xs mt-1">{{ $message }}</div>@enderror
        @error('incomeFile')<div class="text-system-down text-xs mt-1">{{ $message }}</div>@enderror
        @error('baseDate')<div class="text-system-down text-xs mt-1">{{ $message }}</div>@enderror

        @if ($preview)
            @php $s = $preview['summary']; @endphp
            <div class="mt-space-4 border-t border-cryptex-border-subtle pt-space-3 text-sm flex flex-col gap-space-2" data-import-preview>
                <h4 class="font-semibold">Prévia da importação</h4>
                <div>Posição: <strong>{{ $s['positions']['count'] }}</strong> ativos ({{ $s['positions']['new'] }} novos, {{ $s['positions']['existing'] }} já importados) · valor <strong>{{ $m($s['positions']['market_value']) }}</strong> · data-base {{ $s['base_date'] ? \Illuminate\Support\Carbon::parse($s['base_date'])->format('d/m/Y') : '—' }}</div>
                @if ($s['positions']['unknown_average_price'])<div>PM ausente: {{ implode(', ', $s['positions']['unknown_average_price']) }}</div>@endif
                @if ($s['positions']['lent_tickers'])<div>Com aluguel (doador): {{ implode(', ', $s['positions']['lent_tickers']) }}</div>@endif
                <div>Proventos: <strong>{{ $s['income']['count'] }}</strong> ({{ $s['income']['new'] }} novos, {{ $s['income']['existing'] }} já importados) · líquido <strong>{{ $m($s['income']['net_total']) }}</strong> · {{ $s['income']['reimbursements'] }} reembolsos de aluguel · {{ $s['income']['from'] ? \Illuminate\Support\Carbon::parse($s['income']['from'])->format('d/m/Y') : '—' }} a {{ $s['income']['to'] ? \Illuminate\Support\Carbon::parse($s['income']['to'])->format('d/m/Y') : '—' }}</div>
                <div class="flex flex-wrap gap-space-3 text-xs">@foreach ($s['income']['by_type'] as $t => $r)<span>{{ \App\Domains\Investments\Models\AssetIncomeEvent::TYPE_LABELS[$t] ?? $t }}: {{ $r['count'] }} · {{ $m($r['net']) }}</span>@endforeach</div>
                @if ($preview['errors'])
                    <x-fx.alert variant="error"><strong>Erros (não é possível confirmar):</strong><ul class="list-disc ml-4">@foreach (array_slice($preview['errors'], 0, 30) as $e)<li>{{ $e }}</li>@endforeach</ul></x-fx.alert>
                @endif
                @if ($preview['warnings'])
                    <div class="text-xs text-mono-600"><strong>Avisos:</strong><ul class="list-disc ml-4">@foreach ($preview['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul></div>
                @endif
                <div class="flex gap-space-3 mt-space-2">
                    @if ($preview['ok'])
                        <button type="button" wire:click="confirmImport" wire:confirm="Confirmar a importação? Serão gravados os ativos, a posição B3 e os proventos novos. Nada será lançado no Financeiro." class="min-h-[40px] px-3 rounded-sm bg-cryptex-brand-400 text-white font-semibold">Confirmar importação</button>
                    @endif
                    <button type="button" wire:click="discardPreview" class="min-h-[40px] px-3 rounded-sm border border-cryptex-border-default">Descartar prévia</button>
                </div>
            </div>
        @endif
    </x-fx.card>
</div>
