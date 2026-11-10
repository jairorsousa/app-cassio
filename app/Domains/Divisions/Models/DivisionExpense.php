<?php

namespace App\Domains\Divisions\Models;

use App\Domains\Banking\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DivisionExpense extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'alimentacao' => 'Alimentação', 'moradia' => 'Moradia', 'contas' => 'Contas da casa', 'transporte' => 'Transporte',
        'viagem' => 'Viagem', 'lazer' => 'Lazer', 'saude' => 'Saúde', 'educacao' => 'Educação', 'mercado' => 'Mercado',
        'escritorio' => 'Escritório', 'servicos' => 'Serviços', 'presentes' => 'Presentes', 'outros' => 'Outros',
    ];

    public const MODES = ['equal' => 'Partes iguais', 'percent' => 'Por percentual', 'amount' => 'Por valor', 'shares' => 'Por cotas'];

    /** Tipo: despesa comum (regra geral) ou parcela da compra do bem ligado ao grupo. */
    public const KINDS = ['expense' => 'Despesa', 'purchase' => 'Compra do bem'];

    /** Na compra do bem, quem pagou a mais: adiantamento (segue a divisão, gera crédito) ou aumenta a própria parte (sem dívida). */
    public const PURCHASE_MODES = ['advance' => 'Adiantamento aos sócios', 'increase' => 'Aumentar minha parte'];

    public const STATUS = ['pending' => 'Aguardando aprovação', 'approved' => 'Aprovada', 'rejected' => 'Recusada'];

    protected $fillable = ['group_id', 'description', 'category', 'date', 'amount', 'paid_by_member_id', 'split_mode', 'status',
        'installment_group_id', 'installment_number', 'installment_total', 'transaction_id', 'receipt_path', 'receipt_original_name',
        'receipt_mime', 'notes', 'created_by', 'payment_method', 'kind', 'purchase_mode'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2', 'installment_number' => 'integer', 'installment_total' => 'integer'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(DivisionGroup::class, 'group_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(DivisionMember::class, 'paid_by_member_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DivisionExpenseShare::class, 'expense_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DivisionAudit::class, 'expense_id')->orderByDesc('id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Categorias fixas + as criadas pelo botão "+ Nova categoria". */
    public static function categories(): array
    {
        $fixed = self::CATEGORIES;
        $outros = ['outros' => $fixed['outros']];
        unset($fixed['outros']);
        $custom = DivisionCategory::orderBy('label')->pluck('label', 'key')->all();

        return $fixed + $custom + $outros;
    }

    public function isPurchase(): bool
    {
        return $this->kind === 'purchase';
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? $this->category;
    }
}
