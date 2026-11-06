<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'brand', 'bank', 'limit',
        'holder_type', 'holder_name', 'holder_contact_id',
        'closing_day', 'due_day',
        'default_payment_account_id', 'status', 'notes',
    ];

    protected $casts = [
        'limit' => 'decimal:2',
        'status' => 'boolean',
        'closing_day' => 'integer',
        'due_day' => 'integer',
    ];

    /** Titulares possíveis (Etapa 5). Nulo = não informado (cartões antigos). */
    public const HOLDER_TYPES = [
        'cassio' => 'Cássio',
        'filho' => 'Filho(a)',
        'luciane' => 'Luciane',
        'contato' => 'Contato',
    ];

    public function holderContact(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Contacts\Models\Contact::class, 'holder_contact_id')->withTrashed();
    }

    public function hasHolder(): bool
    {
        return $this->holder_type !== null && $this->holder_type !== '';
    }

    /** Chave estável para filtro por pessoa: cassio, luciane, filho:Nome, contato:ID ou none. */
    public function holderKey(): string
    {
        return match ($this->holder_type) {
            'cassio', 'luciane' => $this->holder_type,
            'filho' => 'filho:'.mb_strtolower(trim((string) $this->holder_name)),
            'contato' => 'contato:'.(int) $this->holder_contact_id,
            default => 'none',
        };
    }

    public function holderLabel(): string
    {
        return match ($this->holder_type) {
            'cassio' => 'Cássio',
            'luciane' => 'Luciane',
            'filho' => trim((string) $this->holder_name) !== '' ? trim((string) $this->holder_name).' (filho/a)' : 'Filho(a)',
            'contato' => $this->holderContact?->name ?? 'Contato removido',
            default => 'Não informado',
        };
    }

    /** @return array<string,string> chave => rótulo, para o filtro de gastos por pessoa */
    public static function holderFilterOptions(): array
    {
        $options = [];
        foreach (static::withTrashed()->with('holderContact')->get() as $card) {
            $options[$card->holderKey()] = $card->holderLabel();
        }
        asort($options);
        if (isset($options['none'])) {
            $none = $options['none'];
            unset($options['none']);
            $options['none'] = $none;
        }

        return $options;
    }

    /** @return list<int> */
    public static function idsForHolderKey(string $key): array
    {
        return static::withTrashed()->get()->filter(fn ($c) => $c->holderKey() === $key)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function defaultPaymentAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'default_payment_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
