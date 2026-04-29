<?php

namespace App\Domains\Partnership\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partnership extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'cnpj', 'participation_percentage', 'joined_at', 'status', 'notes',
    ];

    protected $casts = [
        'participation_percentage' => 'decimal:3',
        'joined_at' => 'date',
        'status' => 'boolean',
    ];

    public function contributions(): HasMany
    {
        return $this->hasMany(PartnershipContribution::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(PartnershipExpense::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(PartnershipDistribution::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function totalContributed(): float
    {
        return (float) $this->contributions()->where('status', 'done')->sum('amount');
    }

    public function totalExpenses(): float
    {
        return (float) $this->expenses()->sum('proportional_amount');
    }

    public function totalDistributions(): float
    {
        return (float) $this->distributions()->sum('amount');
    }

    public function netResult(): float
    {
        return round($this->totalDistributions() - $this->totalContributed() - $this->totalExpenses(), 2);
    }

    public function roiPercent(): float
    {
        $base = $this->totalContributed() + $this->totalExpenses();
        return $base > 0 ? round(($this->netResult() / $base) * 100, 3) : 0.0;
    }
}
