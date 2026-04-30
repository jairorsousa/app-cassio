<?php

namespace App\Domains\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'document', 'rg', 'birth_date',
        'phone', 'email', 'address',
        'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'pix_key',
        'status', 'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'status'     => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
