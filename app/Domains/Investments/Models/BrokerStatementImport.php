<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerStatementImport extends Model
{
    protected $fillable = ['broker_name', 'file_name', 'file_kind', 'file_path', 'file_hash', 'status', 'text_status', 'headers', 'mapping', 'ai_suggestion', 'ai_provider', 'summary', 'expires_at', 'created_by', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['headers' => 'array', 'mapping' => 'array', 'ai_suggestion' => 'array', 'summary' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public const TTL_HOURS = 24;

    public function rows(): HasMany
    {
        return $this->hasMany(BrokerStatementRow::class, 'import_id')->orderBy('row_number');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'needs_review'], true) && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
