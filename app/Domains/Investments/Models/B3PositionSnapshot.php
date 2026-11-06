<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto da posição B3 (agregada por ticker, todas as corretoras). Não substitui o PM calculado pelas operações. */
class B3PositionSnapshot extends Model
{
    public const ORIGIN_LABELS = [
        'custodia' => 'Custódia', 'custodia_fii' => 'Custódia (FII)', 'custodia_bdr' => 'Custódia (BDR)',
        'emprestimo_doador' => 'Emprestado (doador)', 'custodia+emprestimo_doador' => 'Custódia + emprestado',
    ];

    protected $fillable = ['import_batch_id', 'asset_id', 'base_date', 'ticker', 'product', 'origin', 'quantity', 'quantity_custody', 'quantity_lent', 'average_price', 'average_price_status', 'close_price', 'market_value', 'institutions', 'source_key'];

    protected $casts = ['base_date' => 'date', 'quantity' => 'decimal:6', 'quantity_custody' => 'decimal:6', 'quantity_lent' => 'decimal:6', 'average_price' => 'decimal:4', 'close_price' => 'decimal:4', 'market_value' => 'decimal:2'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvestmentImportBatch::class, 'import_batch_id');
    }

    public function hasAveragePrice(): bool
    {
        return $this->average_price !== null;
    }

    /** Custo (qtd × PM) ou null quando o PM é desconhecido. */
    public function cost(): ?float
    {
        return $this->average_price === null ? null : round((float) $this->quantity * (float) $this->average_price, 2);
    }

    /** Cotação mais nova que a data-base, se houver (brapi, plano gratuito com atraso). */
    public function latestQuote(): ?AssetQuote
    {
        if (! $this->asset_id) {
            return null;
        }

        return AssetQuote::where('asset_id', $this->asset_id)->whereDate('date', '>=', $this->base_date)->orderByDesc('date')->orderByDesc('id')->first();
    }

    /** Valor atual: quantidade × última cotação; sem cotação, o valor de mercado da B3 na data-base. */
    public function currentValue(?AssetQuote $quote = null): float
    {
        $quote ??= $this->latestQuote();

        return $quote ? round((float) $this->quantity * (float) $quote->price, 2) : (float) $this->market_value;
    }

    /** Snapshot mais recente confirmado (uma data-base). */
    public static function latestConfirmed()
    {
        $batch = InvestmentImportBatch::where('status', 'confirmed')->whereNotNull('base_date')->whereHas('positions')->orderByDesc('base_date')->orderByDesc('id')->first();

        return $batch ? static::where('import_batch_id', $batch->id)->orderBy('ticker')->get() : collect();
    }
}
