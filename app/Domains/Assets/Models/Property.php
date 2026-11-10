<?php

namespace App\Domains\Assets\Models;

use App\Domains\Banking\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Bem patrimonial (imóvel, veículo...). O cadastro em si não gera lançamento.
 * Pagamentos (PropertyPayment) geram lançamento na conta ou compra no cartão, na categoria "Aquisição de bem" (fora do resultado).
 */
class Property extends Model
{
    use SoftDeletes;

    public const KINDS = ['imovel' => 'Imóvel', 'veiculo' => 'Veículo', 'terreno' => 'Terreno', 'participacao' => 'Participação', 'outro' => 'Outro'];

    public const OWNERS = ['cassio' => 'Cassio', 'luciane' => 'Luciane', 'ambos' => 'Cassio e Luciane', 'empresa' => 'Empresa'];

    protected $fillable = ['kind', 'description', 'identifier', 'acquired_at', 'acquisition_value', 'current_value', 'current_value_date', 'owner', 'bank_account_id', 'status', 'sold_at', 'sale_value', 'notes', 'attachment_path', 'attachment_original_name', 'attachment_mime', 'attachment_size'];

    protected $casts = ['acquired_at' => 'date', 'current_value_date' => 'date', 'sold_at' => 'date', 'acquisition_value' => 'decimal:2', 'current_value' => 'decimal:2', 'sale_value' => 'decimal:2'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PropertyPayment::class)->orderByRaw('date is null')->orderBy('date')->orderBy('id');
    }

    /** Pago, previsto e quanto falta do valor total da compra (valor de aquisição). Em centavos para não errar arredondamento. */
    public function paymentSummary(): array
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        $paid = (int) $payments->where('status', 'paid')->sum(fn ($p) => (int) round((float) $p->amount * 100));
        $planned = (int) $payments->where('status', 'planned')->sum(fn ($p) => (int) round((float) $p->amount * 100));
        $total = (int) round((float) ($this->acquisition_value ?? 0) * 100);

        return [
            'total' => $total / 100,
            'paid' => $paid / 100,
            'planned' => $planned / 100,
            'remaining' => max(0, $total - $paid) / 100,
            'over' => max(0, $paid - $total) / 100,
            'percent' => $total > 0 ? min(100, (int) floor($paid * 100 / $total)) : 0,
        ];
    }

    public function hasAttachment(): bool
    {
        return (bool) $this->attachment_path && Storage::disk('local')->exists($this->attachment_path);
    }

    /** Valor patrimonial: valor atual informado; sem ele, o de aquisição. Vendidos não contam. */
    public function patrimonyValue(): float
    {
        if ($this->status === 'sold') {
            return 0.0;
        }

        return (float) ($this->current_value ?? $this->acquisition_value ?? 0);
    }

    public static function activeTotal(): float
    {
        return round((float) static::where('status', 'active')->get()->sum(fn ($p) => $p->patrimonyValue()), 2);
    }
}
