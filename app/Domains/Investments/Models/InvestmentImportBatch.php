<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentImportBatch extends Model
{
    protected $fillable = ['source', 'base_date', 'status', 'position_file_hash', 'income_file_hash', 'position_file_name', 'income_file_name', 'position_path', 'income_path', 'summary', 'created_by', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['base_date' => 'date', 'summary' => 'array', 'confirmed_at' => 'datetime'];

    public function positions(): HasMany
    {
        return $this->hasMany(B3PositionSnapshot::class, 'import_batch_id');
    }

    public function incomeEvents(): HasMany
    {
        return $this->hasMany(AssetIncomeEvent::class, 'import_batch_id');
    }
}
