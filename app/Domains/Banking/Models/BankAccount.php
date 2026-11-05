<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'bank', 'agency', 'number', 'type',
        'initial_balance', 'initial_balance_date', 'linked_account_id', 'status', 'notes',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'initial_balance_date' => 'date',
        'status' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInvestment($query)
    {
        return $query->where('type', 'investment');
    }

    public function isInvestment(): bool
    {
        return $this->type === 'investment';
    }

    /** Conta corrente vinculada a uma conta de investimento (aplicação/resgate = transferência). */
    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_account_id');
    }

    /** Lançamento anterior à data do saldo inicial: histórico, não altera o saldo nem o resultado. */
    public function isHistoricalDate(Carbon|string|null $date): bool
    {
        return $this->initial_balance_date !== null && $date !== null
            && Carbon::parse($date)->startOfDay()->lt($this->initial_balance_date->copy()->startOfDay());
    }

    /**
     * Saldo = saldo inicial + lançamentos liquidados a partir da data do saldo inicial (se houver).
     * Com $until, devolve o saldo ao final daquele dia (usado em "Conferir saldo").
     */
    public function balance(Carbon|string|null $until = null): float
    {
        $query = $this->transactions()->where('status', 'settled');
        if ($this->initial_balance_date) {
            $query->whereDate('date', '>=', $this->initial_balance_date->toDateString());
        }
        if ($until !== null) {
            $query->whereDate('date', '<=', Carbon::parse($until)->toDateString());
        }
        $signed = (float) $query
            ->selectRaw("SUM(CASE
                WHEN type = 'income' THEN amount
                WHEN type IN ('expense', 'invoice_payment') THEN -amount
                WHEN type = 'transfer' THEN amount
                ELSE 0
            END) as total")
            ->value('total') ?? 0;

        return (float) $this->initial_balance + $signed;
    }
}
