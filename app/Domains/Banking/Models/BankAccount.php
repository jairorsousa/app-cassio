<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class BankAccount extends Model
{
    use SoftDeletes;

    public const TYPE_CHECKING = 'checking';

    public const TYPE_SAVINGS = 'savings';

    public const TYPE_INVESTMENT = 'investment';

    public const TYPE_FUND = 'fund';

    public const TYPE_CASH = 'cash';

    public const TYPE_REPASSE = 'repasse';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_CHECKING => 'Corrente',
        self::TYPE_SAVINGS => 'Poupança',
        self::TYPE_INVESTMENT => 'Investimento',
        self::TYPE_FUND => 'Fundo (aplicação automática)',
        self::TYPE_CASH => 'Dinheiro',
        self::TYPE_REPASSE => 'Repasse',
    ];

    /** Ordem de agrupamento no seletor. */
    public const TYPE_ORDER = [
        self::TYPE_CHECKING,
        self::TYPE_SAVINGS,
        self::TYPE_INVESTMENT,
        self::TYPE_FUND,
        self::TYPE_CASH,
        self::TYPE_REPASSE,
    ];

    protected $fillable = [
        'name', 'bank', 'agency', 'number', 'type',
        'initial_balance', 'initial_balance_date', 'linked_account_id', 'status', 'notes',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'initial_balance_date' => 'date',
        'status' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInvestment($query)
    {
        return $query->whereIn('type', [self::TYPE_INVESTMENT, self::TYPE_FUND]);
    }

    public function isInvestment(): bool
    {
        return in_array($this->type, [self::TYPE_INVESTMENT, self::TYPE_FUND], true);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst((string) $this->type);
    }

    /** Rótulo curto para o seletor (ex.: Corrente, Fundo). */
    public function typeShortLabel(): string
    {
        return match ($this->type) {
            self::TYPE_FUND => 'Fundo',
            self::TYPE_INVESTMENT => 'Investimento',
            self::TYPE_CHECKING => 'Corrente',
            self::TYPE_SAVINGS => 'Poupança',
            self::TYPE_CASH => 'Dinheiro',
            self::TYPE_REPASSE => 'Repasse',
            default => $this->typeLabel(),
        };
    }

    /** Ex.: "Banco do Brasil · Corrente" — distingue contas do mesmo banco. */
    public function pickerLabel(): string
    {
        return $this->name.' · '.$this->typeShortLabel();
    }

    /**
     * Opções para o seletor pesquisável, agrupadas por tipo.
     *
     * @param  Collection<int, self>|iterable<self>  $accounts
     * @return Collection<int, array<string, mixed>>
     */
    public static function pickerOptions(iterable $accounts): Collection
    {
        $accounts = collect($accounts)->sortBy([
            fn (self $a) => array_search($a->type, self::TYPE_ORDER, true) === false
                ? 99
                : array_search($a->type, self::TYPE_ORDER, true),
            fn (self $a) => mb_strtolower($a->name),
        ])->values();

        $options = collect();
        $currentType = null;
        foreach ($accounts as $account) {
            if ($account->type !== $currentType) {
                $currentType = $account->type;
                $options->push([
                    'id' => 'group-'.$currentType,
                    'is_header' => true,
                    'label' => self::TYPE_LABELS[$currentType] ?? $account->typeLabel(),
                    'type' => $currentType,
                    'search' => self::TYPE_LABELS[$currentType] ?? $account->typeLabel(),
                ]);
            }
            $options->push([
                'id' => $account->id,
                'label' => $account->pickerLabel(),
                'name' => $account->name,
                'description' => $account->typeShortLabel()
                    .($account->bank ? ' · '.$account->bank : ''),
                'type' => $account->type,
                'search' => $account->name.' '.$account->typeShortLabel().' '.$account->typeLabel().' '.($account->bank ?? ''),
            ]);
        }

        return $options;
    }

    /** Conta corrente vinculada a uma conta de investimento (aplicação/resgate = transferência). */
    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_account_id');
    }

    /** Lançamento anterior à data do saldo inicial: histórico, não altera o saldo nem o resultado. */
    public function isHistoricalDate(Carbon|string|null $date): bool
    {
        return $this->initial_balance_date !== null && $date !== null
            && Carbon::parse($date)->startOfDay()->lt($this->initial_balance_date->copy()->startOfDay());
    }

    /**
     * Saldo = saldo inicial + lançamentos liquidados a partir da data do saldo inicial (se houver).
     * Com $until, devolve o saldo ao final daquele dia (usado em "Conferir saldo").
     */
    public function balance(Carbon|string|null $until = null): float
    {
        $query = $this->transactions()->where('status', 'settled');
        if ($this->initial_balance_date) {
            $query->whereDate('date', '>=', $this->initial_balance_date->toDateString());
        }
        if ($until !== null) {
            $query->whereDate('date', '<=', Carbon::parse($until)->toDateString());
        }
        $signed = (float) $query
            ->selectRaw("SUM(CASE
                WHEN type = 'income' THEN amount
                WHEN type IN ('expense', 'invoice_payment') THEN -amount
                WHEN type = 'transfer' THEN amount
                ELSE 0
            END) as total")
            ->value('total') ?? 0;

        return (float) $this->initial_balance + $signed;
    }
}
