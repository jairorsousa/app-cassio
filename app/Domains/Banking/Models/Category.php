<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['parent_id', 'name', 'type', 'icon', 'color', 'status'];

    protected $casts = [
        'status' => 'boolean',
        'is_system' => 'boolean',
    ];

    public const TRANSFER_KEY = 'transfer';

    public const WRIT_PROFIT_NAME = 'Juros Ganho (RPV / Precatórios)';

    private static ?int $writProfitId = null;

    /** Categoria de lucro realizado de requisitorios nos relatorios. */
    public static function writProfitCategoryId(): int
    {
        if (self::$writProfitId && self::whereKey(self::$writProfitId)->exists()) {
            return self::$writProfitId;
        }

        $id = self::where('name', self::WRIT_PROFIT_NAME)->where('type', 'income')->value('id');
        if (! $id) {
            $parentId = self::where('name', 'Rendimento de Investimentos')->where('type', 'income')->value('id');
            $category = new self([
                'name' => self::WRIT_PROFIT_NAME,
                'type' => 'income',
                'status' => true,
                'parent_id' => $parentId,
            ]);
            $category->save();
            $id = $category->id;
        }

        return self::$writProfitId = (int) $id;
    }

    private static ?int $transferId = null;

    protected static function booted(): void
    {
        static::updating(function (Category $category) {
            if ($category->getOriginal('is_system')) {
                throw new \DomainException('Categoria de sistema não pode ser alterada.');
            }
        });
        static::deleting(function (Category $category) {
            if ($category->is_system) {
                throw new \DomainException('Categoria de sistema não pode ser excluída.');
            }
        });
    }

    /** Categoria fixa "Transferência entre contas" (fora de receita, despesa e resultado). */
    public static function transferCategoryId(): int
    {
        if (self::$transferId && self::whereKey(self::$transferId)->exists()) {
            return self::$transferId;
        }
        $id = self::where('system_key', self::TRANSFER_KEY)->value('id');
        if (! $id) {
            $category = new self(['name' => 'Transferência entre contas', 'type' => 'transfer', 'status' => true]);
            $category->is_system = true;
            $category->system_key = self::TRANSFER_KEY;
            $category->save();
            $id = $category->id;
        }

        return self::$transferId = (int) $id;
    }

    public function scopeSelectable($query)
    {
        return $query->where('is_system', false);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TransactionAllocation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
