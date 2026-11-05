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
        'default_mode', 'default_rate_or_amount', 'default_base',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'status' => 'boolean',
        'default_rate_or_amount' => 'decimal:3',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(BrokerAdvance::class);
    }

    public function personalAdvances(): HasMany
    {
        return $this->advances()->where('kind', 'personal');
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

    public function processExpenses(): HasMany
    {
        return $this->hasMany(BrokerProcessExpense::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function scopeSelectable($query)
    {
        return $query->active()->whereHas('contact', function ($contacts) {
            $contacts->where(function ($inner) {
                $inner->where('type', 'corretor')->orWhereJsonContains('roles', 'corretor');
            })->active();
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /** Exclui corretores cujo contato está marcado como teste. */
    public function scopeWithoutTestContacts($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('contact_id')
                ->orWhereHas('contact', fn ($contacts) => $contacts->where('is_test', false));
        });
    }
}
