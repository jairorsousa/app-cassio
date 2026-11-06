<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardInvoice extends Model
{
    protected $fillable = [
        'credit_card_id', 'reference_month', 'status',
        'total', 'paid_amount', 'due_date', 'closing_date', 'closed_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
        'closing_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class)->where('type', 'invoice_payment');
    }

    /** Fatura ainda recebendo compras (ciclo não fechado). */
    public function isCycleOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isOverdue(): bool
    {
        return $this->remainingAmount() > 0.004 && $this->due_date && $this->due_date->lt(now()->startOfDay());
    }

    /** Toda fatura com saldo a pagar pode ser paga (inclusive fechada/vencida; aberta = pagamento antecipado). */
    public function isPayable(): bool
    {
        return $this->remainingAmount() > 0.004;
    }

    /** Status exibido: paga, vencida, parcial, fechada, aberta. */
    public function displayStatus(): string
    {
        if ((float) $this->total > 0 && $this->remainingAmount() <= 0.004) {
            return 'paid';
        }
        if ($this->isOverdue()) {
            return 'overdue';
        }
        if ((float) $this->paid_amount > 0) {
            return 'partially_paid';
        }

        return $this->status === 'open' ? 'open' : 'closed';
    }

    public const STATUS_LABELS = [
        'open' => 'Aberta', 'closed' => 'Fechada', 'partially_paid' => 'Parcial', 'paid' => 'Paga', 'overdue' => 'Vencida',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'partially_paid'], true);
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->total - (float) $this->paid_amount);
    }
}
