<?php

namespace App\Domains\Dashboard\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardSnapshot extends Model
{
    protected $fillable = ['payload', 'generated_at'];

    protected $casts = [
        'payload' => 'array',
        'generated_at' => 'datetime',
    ];

    public static function latest_snapshot(): ?self
    {
        return static::orderByDesc('generated_at')->first();
    }
}
