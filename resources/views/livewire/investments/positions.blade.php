<?php

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Services\AssetPositionService;
use App\Domains\Investments\Services\RefreshAssetQuotesService;
use Livewire\Attributes\Layout;
use App\Support\Concerns\SortsColumns;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use SortsColumns;

    protected function sortListKey(): string
    {
        return 'positions';
    }

    protected function sortLists(): array
    {
        return ['positions'];
    }

    public function mount(): void
    {
        $this->loadSort();
    }

    public ?int $editingQuoteAssetId = null;
    public string $quotePrice = '';
    public string $quoteDate = '';
    public string $search = '';

    public function cancel(): void { $this->editingQuoteAssetId = null; $this->resetValidation(); }

    public function startQuote(int $assetId): void
    {
        $position = AssetPosition::with('asset')->where('asset_id', $assetId)->first();
        $this->editingQuoteAssetId = $assetId;
        $this->quoteDate = \App\Support\BusinessDate::today();
        $this->resetValidation();
        $this->quotePrice = $position?->asset?->usesAutomaticLiquidity()
            ? \App\Support\Money::decimal(round($position->marketValue(), 2), 2)
            : \App\Support\Money::decimal((float) ($position?->current_price ?? $position?->average_price ?? 0), 4);
    }

    public function saveQuote(AssetPositionService $service): void
    {
        // Preço com 4 casas no padrão BR ("12,3456"); saldo de liquidez automática com 2 casas.
        $isLiquidity = Asset::find($this->editingQuoteAssetId)?->usesAutomaticLiquidity() ?? false;
        $this->quotePrice = \App\Support\Money::decimal($this->quotePrice, $isLiquidity ? 2 : 4);
        $this->validate([
            'quotePrice' => 'required|numeric|min:0',
            'quoteDate' => 'required|date|before_or_equal:'.\App\Support\BusinessDate::today(),
        ]);

        $asset = Asset::with('position')->findOrFail($this->editingQuoteAssetId);
        $price = (float) $this->quotePrice;
        if ($asset->usesAutomaticLiquidity()) {
            $quantity = (float) ($asset->position?->quantity ?? 0);
            if ($quantity <= 0) {
                $this->addError('quotePrice', 'Registre uma aplicação antes de informar o saldo.');

                return;
            }
            $price = $price / $quantity;
        }
        $service->setQuote($asset, $this->quoteDate, $price);

        $this->editingQuoteAssetId = null;
        $this->quotePrice = '';
        session()->flash('status', $asset->usesAutomaticLiquidity() ? 'Saldo da aplicação atualizado.' : 'Cotação atualizada.');
    }

    public function editingAutomaticLiquidity(): bool
    {
        return $this->editingQuoteAssetId
            ? (bool) Asset::find($this->editingQuoteAssetId)?->usesAutomaticLiquidity()
            : false;
    }

    public function recalculate(int $assetId, AssetPositionService $service): void
    {
        $asset = Asset::findOrFail($assetId);
        $service->recalculate($asset);
        session()->flash('status', 'Posição recalculada.');
    }

    public function refreshQuotes(RefreshAssetQuotesService $service): void
    {
        $result = $service->refresh();
        $updated = count($result['updated']);
        $failed = count($result['failed']);

        if ($updated === 0 && $failed === 0) {
            session()->flash('status', 'Nenhum ativo listado em bolsa nas posições abertas. Cotações manuais permanecem como estão.');

            return;
        }

        $parts = [];
        if ($updated > 0) {
            $parts[] = $updated === 1
                ? '1 cotação atualizada ('.$result['updated'][0].').'
                : $updated.' cotações atualizadas ('.implode(', ', $result['updated']).').';
        }
        if ($failed > 0) {
            $parts[] = 'Não foi possível atualizar '.implode(', ', $result['failed']).'.';
        }

        session()->flash('status', implode(' ', $parts));
    }

    public function with(): array
    {
        return [
            'positions' => AssetPosition::with('asset.assetClass', 'asset.quotes')
                ->when($this->search, fn ($q) => $q->whereHas('asset', fn ($q) => $q->where('ticker', 'like', '%'.$this->search.'%')->orWhere('name', 'like', '%'.$this->search.'%')))
                ->where('quantity', '>', 0)
                ->get()
                ->pipe(fn ($items) => $this->sortRows($items, 'positions', [
                    'ticker' => fn ($p) => $p->asset?->ticker,
                    'class' => fn ($p) => $p->asset?->assetClass?->name,
                    'quantity' => fn ($p) => (float) $p->quantity,
                    'avg_price' => fn ($p) => (float) $p->average_price,
                    'invested' => fn ($p) => (float) $p->total_invested,
                    'quote' => fn ($p) => (float) ($p->current_price ?? $p->average_price),
                    'market' => fn ($p) => (float) $p->marketValue(),
                    'result' => fn ($p) => (float) $p->unrealizedPnL(),
                    'percent' => fn ($p) => (float) $p->unrealizedPnLPercent(),
                ], [[fn ($p) => $p->asset?->ticker, 'asc']])),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Posições</x-slot>

<div class="investment-area"><x-investments.subnav /><x-investments.b3-notice what="positions" />
<div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Minha carteira</h2><p class="mt-2 text-sm text-mono-600">Posições abertas, preço médio, valor atual e resultado por ativo.</p></div><div class="flex flex-wrap gap-2"><x-jr.button variant="standard" wire:click="refreshQuotes" wire:loading.attr="disabled"><span class="material-icons-outlined text-[18px]">sync</span><span wire:loading.remove>Atualizar cotações</span><span wire:loading>Atualizando...</span></x-jr.button><x-jr.button href="{{ route('investments.operations.index') }}">Registrar movimentação</x-jr.button></div></div>
<x-jr.card><x-jr.input label="Buscar na carteira" icon="search" wire:model.live.debounce.300ms="search" placeholder="Código ou nome do ativo" /></x-jr.card>
<x-jr.card>
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    @if ($positions->isEmpty())
        <div class="text-sm text-mono-600">Nenhuma posição em aberto.</div>
    @else
        <x-fx.sort-mobile list="positions" :sort-by="$sortBy" :sort-dir="$sortDir" primary /><div class="overflow-x-auto"><table class="investment-table">
            <thead>
                <tr>
                    <x-fx.sort-th column="ticker" label="Ticker" list="positions" :sorts="$sorts" /><x-fx.sort-th column="class" label="Classe" list="positions" :sorts="$sorts" /><x-fx.sort-th column="quantity" label="Quantidade" list="positions" :sorts="$sorts" align="right" /><x-fx.sort-th column="avg_price" label="Preço médio" list="positions" :sorts="$sorts" align="right" /><x-fx.sort-th column="invested" label="Investido" list="positions" :sorts="$sorts" align="right" /><x-fx.sort-th column="quote" label="Cotação" list="positions" :sorts="$sorts" align="right" /><x-fx.sort-th column="market" label="Valor de mercado" list="positions" :sorts="$sorts" align="right" /><x-fx.sort-th column="result" label="Resultado" list="positions" :sorts="$sorts" align="right" /><x-fx.sort-th column="percent" label="%" list="positions" :sorts="$sorts" align="right" />
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($positions as $p)
                    <tr>
                        <td class="font-semibold">{{ $p->asset?->ticker }}</td>
                        <td>{{ $p->asset?->assetClass?->name }}</td>
                        <td class="text-right">{{ $p->asset?->usesAutomaticLiquidity() ? 'Saldo automático' : number_format((float) $p->quantity, 6, ',', '.') }}</td>
                        <td class="text-right">{{ $p->asset?->usesAutomaticLiquidity() ? '—' : 'R$ '.number_format((float) $p->average_price, 4, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format((float) $p->total_invested, 2, ',', '.') }}</td>
                        <td class="text-right">
                            <button type="button" class="text-primary-500 hover:underline" wire:click="startQuote({{ $p->asset_id }})">{{ $p->asset?->usesAutomaticLiquidity() ? 'Atualizar saldo' : 'R$ '.number_format((float) ($p->current_price ?? $p->average_price), 4, ',', '.') }}</button><p class="mt-1 text-xs text-mono-600">{{ $p->asset?->quotes->first()?->date?->format('d/m/Y') ?? ($p->asset?->usesAutomaticLiquidity() ? 'Informe o saldo exibido pelo banco' : ($p->asset?->isMarketQuoted() ? 'Aguardando cotação automática' : 'Preço médio · sem cotação')) }}</p>
                        </td>
                        <td class="text-right font-semibold">R$ {{ number_format($p->marketValue(), 2, ',', '.') }}</td>
                        <td class="text-right {{ $p->unrealizedPnL() >= 0 ? 'text-up' : 'text-down' }}">
                            R$ {{ number_format($p->unrealizedPnL(), 2, ',', '.') }}
                        </td>
                        <td class="text-right {{ $p->unrealizedPnLPercent() >= 0 ? 'text-up' : 'text-down' }}">
                            {{ number_format($p->unrealizedPnLPercent(), 2, ',', '.') }}%
                        </td>
                        <td class="text-right">
                            <button class="investment-action" wire:click="recalculate({{ $p->asset_id }})" title="Recalcular do zero">↻</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <td colspan="4">Totais</td>
                    <td class="text-right">R$ {{ number_format($positions->sum('total_invested'), 2, ',', '.') }}</td>
                    <td></td>
                    <td class="text-right">R$ {{ number_format($positions->sum(fn($p) => $p->marketValue()), 2, ',', '.') }}</td>
                    <td class="text-right">R$ {{ number_format($positions->sum(fn($p) => $p->unrealizedPnL()), 2, ',', '.') }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table></div>
    @endif
</x-jr.card>
<p class="text-xs text-mono-600">Ações, FIIs, ETFs e BDRs da B3 são atualizados automaticamente em dias úteis após o fechamento. Clique no preço para ajustar manualmente. CDB, Tesouro e demais ativos sem ticker de bolsa continuam manuais. Sem cotação, o patrimônio usa o preço médio.</p>
@if ($editingQuoteAssetId)
<x-investments.modal :title="$this->editingAutomaticLiquidity() ? 'Atualizar saldo da aplicação' : 'Atualizar cotação'" submit="saveQuote">
<x-jr.input label="Data da cotação *" type="date" name="quoteDate" wire:model="quoteDate" required />
@if ($this->editingAutomaticLiquidity())
<x-jr.input label="Saldo atual (R$) *" type="text" x-money name="quotePrice" wire:model="quotePrice" wire:key="quote-balance" required />
@else
<x-jr.input label="Preço por unidade (R$) *" type="text" x-decimal.pad="4" placeholder="0,0000" name="quotePrice" wire:model="quotePrice" wire:key="quote-price" required />
@endif
<p class="text-sm text-mono-600 md:col-span-2">{{ $this->editingAutomaticLiquidity() ? 'Informe o saldo total mostrado pelo banco. A diferença será reconhecida como valorização da aplicação.' : 'O valor mais recente por data será utilizado na carteira.' }}</p>
</x-investments.modal>
@endif
</div>
