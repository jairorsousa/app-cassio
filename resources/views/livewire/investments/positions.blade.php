<?php

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Services\AssetPositionService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingQuoteAssetId = null;
    public string $quotePrice = '';
    public string $quoteDate = '';
    public string $search = '';

    public function cancel(): void { $this->editingQuoteAssetId = null; $this->resetValidation(); }

    public function startQuote(int $assetId): void
    {
        $position = AssetPosition::where('asset_id', $assetId)->first();
        $this->editingQuoteAssetId = $assetId;
        $this->quoteDate = today()->format("Y-m-d");
        $this->resetValidation();
        $this->quotePrice = (string) ($position?->current_price ?? $position?->average_price ?? 0);
    }

    public function saveQuote(AssetPositionService $service): void
    {
        $this->validate([
            'quotePrice' => 'required|numeric|min:0',
            'quoteDate' => 'required|date|before_or_equal:today',
        ]);

        $asset = Asset::findOrFail($this->editingQuoteAssetId);
        $service->setQuote($asset, $this->quoteDate, (float) $this->quotePrice);

        $this->editingQuoteAssetId = null;
        $this->quotePrice = '';
        session()->flash('status', 'Cotação atualizada.');
    }

    public function recalculate(int $assetId, AssetPositionService $service): void
    {
        $asset = Asset::findOrFail($assetId);
        $service->recalculate($asset);
        session()->flash('status', 'Posição recalculada.');
    }

    public function with(): array
    {
        return [
            'positions' => AssetPosition::with('asset.assetClass', 'asset.quotes')
                ->when($this->search, fn ($q) => $q->whereHas('asset', fn ($q) => $q->where('ticker', 'like', '%'.$this->search.'%')->orWhere('name', 'like', '%'.$this->search.'%')))
                ->where('quantity', '>', 0)
                ->get()
                ->sortByDesc(fn ($p) => $p->marketValue())
                ->values(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Posições</x-slot>

<div class="investment-area"><x-investments.subnav />
<div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Minha carteira</h2><p class="mt-2 text-sm text-mono-600">Posições abertas, preço médio, valor atual e resultado por ativo.</p></div><x-jr.button href="{{ route('investments.operations.index') }}">Registrar movimentação</x-jr.button></div>
<x-jr.card><x-jr.input label="Buscar na carteira" icon="search" wire:model.live.debounce.300ms="search" placeholder="Código ou nome do ativo" /></x-jr.card>
<x-jr.card>
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    @if ($positions->isEmpty())
        <div class="text-sm text-mono-600">Nenhuma posição em aberto.</div>
    @else
        <div class="overflow-x-auto"><table class="investment-table">
            <thead>
                <tr>
                    <th class="text-left">Ticker</th>
                    <th class="text-left">Classe</th>
                    <th class="text-right">Quantidade</th>
                    <th class="text-right">Preço médio</th>
                    <th class="text-right">Investido</th>
                    <th class="text-right">Cotação</th>
                    <th class="text-right">Valor de mercado</th>
                    <th class="text-right">Resultado</th>
                    <th class="text-right">%</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($positions as $p)
                    <tr>
                        <td class="font-semibold">{{ $p->asset?->ticker }}</td>
                        <td>{{ $p->asset?->assetClass?->name }}</td>
                        <td class="text-right">{{ number_format((float) $p->quantity, 6, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format((float) $p->average_price, 4, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format((float) $p->total_invested, 2, ',', '.') }}</td>
                        <td class="text-right">
                            <button type="button" class="text-primary-500 hover:underline" wire:click="startQuote({{ $p->asset_id }})">R$ {{ number_format((float) ($p->current_price ?? $p->average_price), 4, ',', '.') }}</button><p class="mt-1 text-xs text-mono-600">{{ $p->asset?->quotes->first()?->date?->format('d/m/Y') ?? 'Preço médio · sem cotação' }}</p>
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
<p class="text-xs text-mono-600">Atualize a cotação clicando no preço. Valores em reais, informados manualmente. Sem cotação, o patrimônio é estimado pelo preço médio.</p>
@if ($editingQuoteAssetId)
<x-investments.modal title="Atualizar cotação" submit="saveQuote">
<x-jr.input label="Data da cotação *" type="date" name="quoteDate" wire:model="quoteDate" required />
<x-jr.input label="Preço por unidade (R$) *" type="number" step="0.0001" min="0" name="quotePrice" wire:model="quotePrice" required />
<p class="text-sm text-mono-600 md:col-span-2">O valor mais recente por data será utilizado na carteira.</p>
</x-investments.modal>
@endif
</div>
