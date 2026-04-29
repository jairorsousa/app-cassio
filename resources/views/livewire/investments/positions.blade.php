<?php

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Services\AssetPositionService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingQuoteAssetId = null;
    public string $quotePrice = '';

    public function startQuote(int $assetId): void
    {
        $position = AssetPosition::where('asset_id', $assetId)->first();
        $this->editingQuoteAssetId = $assetId;
        $this->quotePrice = (string) ($position?->current_price ?? $position?->average_price ?? 0);
    }

    public function saveQuote(AssetPositionService $service): void
    {
        $this->validate([
            'quotePrice' => 'required|numeric|min:0',
        ]);

        $asset = Asset::findOrFail($this->editingQuoteAssetId);
        $service->setQuote($asset, now()->format('Y-m-d'), (float) $this->quotePrice);

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
            'positions' => AssetPosition::with('asset.assetClass')
                ->where('quantity', '>', 0)
                ->get()
                ->sortByDesc(fn ($p) => $p->marketValue())
                ->values(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Posições</x-slot>

<x-fx.card>
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    @if ($positions->isEmpty())
        <div class="text-sm text-mono-600">Nenhuma posição em aberto.</div>
    @else
        <table class="fx-table w-full text-sm">
            <thead>
                <tr>
                    <th class="text-left">Ticker</th>
                    <th class="text-left">Classe</th>
                    <th class="text-right">Quantidade</th>
                    <th class="text-right">Preço médio</th>
                    <th class="text-right">Investido</th>
                    <th class="text-right">Cotação</th>
                    <th class="text-right">Valor de mercado</th>
                    <th class="text-right">PnL</th>
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
                            @if ($editingQuoteAssetId === $p->asset_id)
                                <form wire:submit="saveQuote" class="inline-flex items-center gap-xs">
                                    <input type="number" step="0.0001" wire:model="quotePrice" class="fx-form-field !w-24" />
                                    <button type="submit" class="fx-btn fx-btn--text fx-btn--sm">OK</button>
                                    <button type="button" class="fx-btn fx-btn--text fx-btn--sm" wire:click="$set('editingQuoteAssetId', null)">×</button>
                                </form>
                            @else
                                <button type="button" class="hover:underline" wire:click="startQuote({{ $p->asset_id }})">
                                    R$ {{ number_format((float) ($p->current_price ?? $p->average_price), 4, ',', '.') }}
                                </button>
                            @endif
                        </td>
                        <td class="text-right font-semibold">R$ {{ number_format($p->marketValue(), 2, ',', '.') }}</td>
                        <td class="text-right {{ $p->unrealizedPnL() >= 0 ? 'text-system-up' : 'text-system-down' }}">
                            R$ {{ number_format($p->unrealizedPnL(), 2, ',', '.') }}
                        </td>
                        <td class="text-right {{ $p->unrealizedPnLPercent() >= 0 ? 'text-system-up' : 'text-system-down' }}">
                            {{ number_format($p->unrealizedPnLPercent(), 2, ',', '.') }}%
                        </td>
                        <td class="text-right">
                            <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="recalculate({{ $p->asset_id }})" title="Recalcular do zero">↻</button>
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
        </table>
    @endif
</x-fx.card>
