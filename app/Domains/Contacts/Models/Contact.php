<?php

namespace App\Domains\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'type', 'document', 'birth_date',
        'phone', 'phones', 'email', 'emails', 'address',
        'zip_code', 'street', 'number', 'complement', 'city', 'state',
        'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'pix_key', 'pix_key_type',
        'status', 'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'phones'     => 'array',
        'emails'     => 'array',
        'status'     => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'advogado' => 'Advogado',
            'corretor' => 'Corretor',
            default => 'Cedente',
        };
    }

    public function pixKeyTypeLabel(): string
    {
        return match ($this->pix_key_type) {
            'email' => 'E-mail',
            'cpf' => 'CPF/CNPJ',
            'telefone' => 'Telefone',
            'aleatoria' => 'Aleatória',
            default => '—',
        };
    }
}
