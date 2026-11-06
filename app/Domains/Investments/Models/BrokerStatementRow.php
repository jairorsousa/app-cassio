<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerStatementRow extends Model
{
    protected $fillable = ['import_id', 'row_number', 'raw', 'date', 'ticker', 'kind', 'source_type_label', 'quantity', 'unit_price', 'gross_amount', 'withholding_tax', 'fee_amount', 'net_amount', 'lending_fee_rate', 'external_id', 'source_key', 'status', 'duplicate_of_event_id', 'force_import', 'messages', 'income_event_id'];

    protected $casts = ['raw' => 'array', 'messages' => 'array', 'date' => 'date', 'force_import' => 'boolean', 'quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'gross_amount' => 'decimal:2', 'withholding_tax' => 'decimal:2', 'fee_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'lending_fee_rate' => 'decimal:6'];

    /** Tipos de linha. Só proventos/aluguel viram evento; operações e transferências ficam pendentes de registro manual. */
    public const KINDS = [
        'dividend' => 'Dividendo', 'jcp' => 'JCP', 'fii_income' => 'Rendimento FII',
        'lending_fee' => 'Aluguel (taxa recebida)', 'lending_reimbursement' => 'Reembolso (aluguel)',
        'buy' => 'Compra', 'sell' => 'Venda', 'transfer' => 'Transferência/custódia', 'fee' => 'Taxa/tarifa',
        'yield' => 'Rendimento (ação)', 'lending_tax' => 'IR sobre aluguel', 'lending_settlement' => 'Liquidação de aluguel (agregada)',
        'trade_settlement' => 'Liquidação de operações', 'tax' => 'Imposto', 'cash_yield' => 'Conta remunerada', 'fraction' => 'Fração/leilão',
        'subscription_right' => 'Direito de subscrição',
        'other' => 'Outro', 'unknown' => 'Não identificado',
    ];

    /** Não viram evento: registrar/reconciliar manualmente. */
    public const MANUAL_KINDS = ['buy', 'sell', 'transfer', 'fee', 'trade_settlement', 'lending_settlement', 'tax', 'cash_yield', 'fraction', 'subscription_right'];

    public const INCOME_KINDS = ['dividend', 'jcp', 'fii_income', 'yield', 'lending_fee', 'lending_reimbursement'];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BrokerStatementImport::class, 'import_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(AssetIncomeEvent::class, 'duplicate_of_event_id');
    }
}
