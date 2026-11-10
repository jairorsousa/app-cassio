<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Titular de cartão (lista editável: Cássio, Luciane, Cassius Augusto, Isabela...). */
class CardHolder extends Model
{
    protected $fillable = ['name', 'contact_id'];

    /** Pessoa (Contatos) deste titular, quando ligada. */
    public function contact(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domains\Contacts\Models\Contact::class)->withTrashed();
    }

    public function cards(): HasMany
    {
        return $this->hasMany(CreditCard::class, 'card_holder_id');
    }

    /** Titular padrão de cartão novo. */
    public static function defaultId(): ?int
    {
        return static::query()->where('name', 'Cássio')->value('id') ?? static::query()->orderBy('id')->value('id');
    }
}
