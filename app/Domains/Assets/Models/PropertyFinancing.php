<?php

namespace App\Domains\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Contrato de financiamento de um bem (banco/credor + parcelas geradas). */
class PropertyFinancing extends Model
{
    public const SYSTEMS = ['price' => 'Tabela Price', 'sac' => 'SAC'];

    protected $fillable = [
        'property_id', 'creditor', 'financed_amount', 'installment_count', 'installment_amount',
        'first_due_date', 'interest_rate_am', 'amortization_system', 'notes', 'created_by',
    ];

    protected $casts = [
        'financed_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'first_due_date' => 'date',
        'interest_rate_am' => 'decimal:4',
        'installment_count' => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PropertyFinancingInstallment::class)->orderBy('number');
    }

    public function hasRate(): bool
    {
        return $this->interest_rate_am !== null && (float) $this->interest_rate_am > 0
            && in_array($this->amortization_system, ['price', 'sac'], true);
    }

    /** Soma das parcelas em aberto, em centavos. */
    public function openCents(): int
    {
        $rows = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();

        return (int) $rows->where('status', 'open')->sum(fn ($i) => (int) round((float) $i->amount * 100));
    }

    public function paidCents(): int
    {
        $rows = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();

        return (int) $rows->where('status', 'paid')->sum(fn ($i) => (int) round((float) $i->amount * 100));
    }

    public function totalInstallmentsCents(): int
    {
        $rows = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();

        return (int) $rows->sum(fn ($i) => (int) round((float) $i->amount * 100));
    }

    public function remainingCount(): int
    {
        $rows = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();

        return $rows->where('status', 'open')->count();
    }

    /**
     * Saldo devedor em reais. Com taxa e tabela, é o principal que falta; sem taxa, a soma das parcelas em aberto
     * (inclui juros futuros — o painel avisa).
     */
    public function remainingDebt(): float
    {
        if ($this->hasRate()) {
            return $this->remainingPrincipal();
        }

        return $this->openCents() / 100;
    }

    public function remainingDebtIncludesFutureInterest(): bool
    {
        return ! $this->hasRate();
    }

    /** Principal restante segundo Price ou SAC, percorrendo as parcelas já pagas. */
    public function remainingPrincipal(): float
    {
        $rate = (float) $this->interest_rate_am / 100;
        $balance = (int) round((float) $this->financed_amount * 100);
        $rows = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();
        $n = $rows->count();
        $i = 0;
        foreach ($rows->sortBy('number') as $row) {
            $i++;
            if ($this->amortization_system === 'sac') {
                $amort = $i === $n ? $balance : (int) round(((float) $this->financed_amount * 100) / max(1, (int) $this->installment_count));
            } else {
                $interest = (int) round($balance * $rate);
                $amort = (int) round((float) $row->amount * 100) - $interest;
            }
            $amort = max(0, min($balance, $amort));
            if ($row->status === 'paid') {
                $balance -= $amort;
            }
        }

        return max(0, $balance) / 100;
    }
}
