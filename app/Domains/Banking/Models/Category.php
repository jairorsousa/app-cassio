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

    public const LOAN_DISBURSEMENT_KEY = 'loan_disbursement';

    public const LOAN_INTEREST_KEY = 'loan_interest';

    public const ASSET_PURCHASE_KEY = 'asset_purchase';

    /** Proventos (dividendos, JCP, rendimentos de FII) lançados automaticamente pelo módulo Investimentos. */
    public const DIVIDEND_INCOME_KEY = 'dividend_income';

    public const DIVIDEND_INCOME_NAME = 'Ações e FIIs (Proventos)';

    private static ?int $dividendIncomeId = null;

    /** Categoria de receita dos proventos: pela chave estável; se faltar, pelo nome; se não existir, cria (editável). */
    public static function dividendIncomeCategoryId(): int
    {
        if (self::$dividendIncomeId && self::whereKey(self::$dividendIncomeId)->exists()) {
            return self::$dividendIncomeId;
        }
        $id = self::where('system_key', self::DIVIDEND_INCOME_KEY)->value('id')
            ?: self::where('name', self::DIVIDEND_INCOME_NAME)->where('type', 'income')->value('id');
        if (! $id) {
            $category = new self([
                'name' => self::DIVIDEND_INCOME_NAME,
                'type' => 'income',
                'status' => true,
                'parent_id' => self::where('name', 'Rendimento de Investimentos')->where('type', 'income')->value('id'),
            ]);
            $category->system_key = self::DIVIDEND_INCOME_KEY;
            $category->save();
            $id = $category->id;
        }

        return self::$dividendIncomeId = (int) $id;
    }

    /** Pagamento de bem (relógio, carro...): sai da conta ou entra na fatura, mas é troca de dinheiro por patrimônio, fora do resultado. */
    public static function assetPurchaseCategoryId(): int
    {
        $id = self::where('system_key', self::ASSET_PURCHASE_KEY)->value('id');
        if (! $id) {
            $category = new self(['name' => 'Aquisição de bem', 'type' => 'transfer', 'status' => true]);
            $category->is_system = true;
            $category->system_key = self::ASSET_PURCHASE_KEY;
            $category->save();
            $id = $category->id;
        }

        return (int) $id;
    }

    private static ?int $loanDisbursementId = null;

    private static ?int $loanInterestId = null;

    /** Saída de empréstimo concedido: conversão caixa→crédito, fora do resultado. */
    public static function loanDisbursementCategoryId(): int
    {
        if (self::$loanDisbursementId && self::whereKey(self::$loanDisbursementId)->exists()) {
            return self::$loanDisbursementId;
        }
        $id = self::where('system_key', self::LOAN_DISBURSEMENT_KEY)->value('id');
        if (! $id) {
            $category = new self(['name' => 'Empréstimo concedido', 'type' => 'transfer', 'status' => true]);
            $category->is_system = true;
            $category->system_key = self::LOAN_DISBURSEMENT_KEY;
            $category->save();
            $id = $category->id;
        }

        return self::$loanDisbursementId = (int) $id;
    }

    /** Juros efetivamente recebidos de empréstimos concedidos (receita). */
    public static function loanInterestCategoryId(): int
    {
        if (self::$loanInterestId && self::whereKey(self::$loanInterestId)->exists()) {
            return self::$loanInterestId;
        }
        $id = self::where('system_key', self::LOAN_INTEREST_KEY)->value('id');
        if (! $id) {
            $parentId = self::where('name', 'Rendimento de Investimentos')->where('type', 'income')->value('id');
            $category = new self([
                'name' => 'Juros de empréstimos',
                'type' => 'income',
                'status' => true,
                'parent_id' => $parentId,
            ]);
            $category->is_system = true;
            $category->system_key = self::LOAN_INTEREST_KEY;
            $category->save();
            $id = $category->id;
        }

        return self::$loanInterestId = (int) $id;
    }



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
