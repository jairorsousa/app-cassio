<?php

namespace App\Domains\Brokers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseType extends Model
{
    protected $fillable = ['name', 'status'];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function commissionRules(): HasMany
    {
        return $this->hasMany(BrokerCommissionRule::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(BrokerCommission::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
