<?php

namespace App\Domains\Assets\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pagamento de um bem: pago (gera lançamento na conta ou compra no cartão) ou previsto (só planejamento). */
class PropertyPayment extends Model
{
    public const METHODS = ['account' => 'Conta', 'card' => 'Cartão'];

    protected $fillable = ['property_id', 'status', 'date', 'amount', 'method', 'bank_account_id', 'credit_card_id', 'installments', 'category_id', 'notes', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2', 'installments' => 'integer'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Lançamentos gerados (1 na conta, ou 1 por parcela no cartão). */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'source_id')->where('source_type', self::class);
    }

    public function isPlanned(): bool
    {
        return $this->status === 'planned';
    }

    public function sourceLabel(): string
    {
        if ($this->method === 'account') {
            return $this->bankAccount?->name ?? 'Conta';
        }
        if ($this->method === 'card') {
            return 'Cartão '.($this->creditCard?->name ?? 'a escolher').($this->installments > 1 ? ' · '.$this->installments.'x' : '');
        }

        return 'Forma a definir';
    }
}
