<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provento recebido (inclusive reembolso de aluguel de ações). Registro só em Investimentos:
 * não gera lançamento no Financeiro (evita duplicar receitas já lançadas nas contas).
 */
class AssetIncomeEvent extends Model
{
    public const TYPE_LABELS = [
        'dividend' => 'Dividendo', 'jcp' => 'JCP', 'fii_income' => 'Rendimento (FII)',
        'lending_reimbursement' => 'Reembolso (aluguel)', 'lending_fee' => 'Taxa de aluguel', 'other' => 'Outro',
    ];

    protected $fillable = ['asset_id', 'import_batch_id', 'ticker', 'payment_date', 'record_date', 'type', 'source_type_label', 'quantity', 'gross_amount', 'withholding_tax', 'net_amount', 'is_lending', 'lending_contract_ref', 'lending_fee_rate', 'broker_name', 'asset_class_label', 'bank_account_id', 'transaction_id', 'source', 'source_file', 'source_key', 'notes'];

    protected $casts = ['payment_date' => 'date', 'record_date' => 'date', 'quantity' => 'decimal:6', 'gross_amount' => 'decimal:2', 'withholding_tax' => 'decimal:2', 'net_amount' => 'decimal:2', 'is_lending' => 'boolean', 'lending_fee_rate' => 'decimal:4'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
