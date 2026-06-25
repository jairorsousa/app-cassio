<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Broker extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contact_id', 'name', 'document', 'rg', 'birth_date',
        'phone', 'email', 'address',
        'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'pix_key',
        'status', 'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'status' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(BrokerAdvance::class);
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(BrokerCommissionRule::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(BrokerCommission::class);
    }

    public function commissionPayments(): HasMany
    {
        return $this->hasMany(BrokerCommissionPayment::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
