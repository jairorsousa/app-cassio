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

    public const KINDS = ['imovel' => 'Imóvel', 'veiculo' => 'Veículo', 'terreno' => 'Terreno', 'rural' => 'Imóvel rural', 'participacao' => 'Participação', 'outro' => 'Outro'];

    public const OWNERS = ['cassio' => 'Cassio', 'luciane' => 'Luciane', 'ambos' => 'Cassio e Luciane', 'empresa' => 'Empresa'];

    protected $fillable = ['kind', 'description', 'identifier', 'acquired_at', 'acquisition_value', 'current_value', 'current_value_date', 'owner', 'bank_account_id', 'division_group_id', 'status', 'sold_at', 'sale_value', 'notes', 'attachment_path', 'attachment_original_name', 'attachment_mime', 'attachment_size'];

    protected $casts = ['acquired_at' => 'date', 'current_value_date' => 'date', 'sold_at' => 'date', 'acquisition_value' => 'decimal:2', 'current_value' => 'decimal:2', 'sale_value' => 'decimal:2'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    /** Compra em sociedade: grupo do Divisões onde as parcelas são lançadas. */
    public function divisionGroup(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Divisions\Models\DivisionGroup::class, 'division_group_id')->withTrashed();
    }

    public function isShared(): bool
    {
        return $this->division_group_id !== null;
    }

    private ?array $societyCache = null;

    /**
     * Compra em sociedade: a parte de cada sócio acompanha o que cada um assumiu das despesas "Compra do bem" do grupo
     * (no adiantamento, a parte pela divisão combinada; no "aumentar minha parte", tudo para quem pagou).
     * Em centavos internamente; devolve reais.
     */
    public function societySummary(): ?array
    {
        if (! $this->isShared()) {
            return null;
        }
        if ($this->societyCache !== null) {
            return $this->societyCache;
        }
        $group = \App\Domains\Divisions\Models\DivisionGroup::withTrashed()->with('members')->find($this->division_group_id);
        $owner = $group?->ownerMember();
        $expenses = \App\Domains\Divisions\Models\DivisionExpense::where('group_id', $this->division_group_id)
            ->where('kind', 'purchase')->where('status', 'approved')->with('shares', 'payer')->orderBy('date')->orderBy('id')->get();
        $part = $cash = [];
        foreach ($expenses as $e) {
            $cash[$e->paid_by_member_id] = ($cash[$e->paid_by_member_id] ?? 0) + (int) round((float) $e->amount * 100);
            foreach ($e->shares as $s) {
                $part[$s->member_id] = ($part[$s->member_id] ?? 0) + (int) round((float) $s->amount * 100);
            }
        }
        $paid = (int) $expenses->sum(fn ($e) => (int) round((float) $e->amount * 100));
        $total = $this->acquisition_value !== null ? (int) round((float) $this->acquisition_value * 100) : null;
        $members = [];
        foreach ($group?->members ?? [] as $m) {
            $members[] = ['id' => $m->id, 'name' => $m->name, 'is_owner' => (bool) $m->is_owner, 'part' => ($part[$m->id] ?? 0) / 100, 'cash' => ($cash[$m->id] ?? 0) / 100,
                'percent' => $paid > 0 ? round(($part[$m->id] ?? 0) * 100 / $paid, 2) : null];
        }
        $ownerPart = $owner ? ($part[$owner->id] ?? 0) : 0;

        return $this->societyCache = [
            'group' => $group, 'expenses' => $expenses, 'members' => $members,
            'total' => $total !== null ? $total / 100 : null,
            'paid' => $paid / 100,
            'remaining' => $total !== null ? max(0, $total - $paid) / 100 : null,
            'percent_paid' => $total ? min(100, (int) floor($paid * 100 / $total)) : 0,
            'owner_part' => $ownerPart / 100,
            'owner_percent' => $paid > 0 ? $ownerPart / $paid : null,
        ];
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

        if ($this->isShared()) {
            // Compra em sociedade: valor atual (ou total) × parte do Cassio; sem valor informado, conta o que ele já assumiu da compra.
            $s = $this->societySummary();
            $base = $this->current_value ?? $this->acquisition_value;
            if ($base !== null && $s['owner_percent'] !== null) {
                return round((float) $base * $s['owner_percent'], 2);
            }

            return (float) $s['owner_part'];
        }

        return (float) ($this->current_value ?? $this->acquisition_value ?? 0);
    }

    public static function activeTotal(): float
    {
        return round((float) static::where('status', 'active')->get()->sum(fn ($p) => $p->patrimonyValue()), 2);
    }
}
