<?php

namespace App\Domains\Contacts\Models;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Writs\Models\WritAssignor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'type', 'document', 'birth_date',
        'phone', 'phones', 'email', 'emails', 'address',
        'zip_code', 'street', 'number', 'complement', 'city', 'state',
        'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'pix_key', 'pix_key_type',
        'status', 'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'phones'     => 'array',
        'emails'     => 'array',
        'status'     => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function writAssignors(): HasMany
    {
        return $this->hasMany(WritAssignor::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function brokerProfile(): HasOne
    {
        return $this->hasOne(Broker::class);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'advogado' => 'Advogado',
            'corretor' => 'Corretor',
            default => 'Cedente',
        };
    }

    public function pixKeyTypeLabel(): string
    {
        return match ($this->pix_key_type) {
            'email' => 'E-mail',
            'cpf' => 'CPF/CNPJ',
            'telefone' => 'Telefone',
            'aleatoria' => 'Aleatória',
            default => '—',
        };
    }

    /**
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        $broker = $this->brokerProfile()->first()
            ?: ($this->document ? Broker::where('document', $this->document)->whereNull('contact_id')->first() : null)
            ?: Broker::whereNull('contact_id')->where('name', $this->name)->first();

        return array_filter([
            'requisitorios' => $this->writAssignors()->count(),
            'lancamentos' => $this->transactions()->count() + ($broker?->transactions()->count() ?? 0),
            'adiantamentos' => $broker?->advances()->count() ?? 0,
            'comissoes' => $broker?->commissions()->count() ?? 0,
        ]);
    }

    public function canBeDeleted(): bool
    {
        return $this->deletionBlockers() === [];
    }

    public function deletionBlockMessage(): string
    {
        $blockers = $this->deletionBlockers();

        if ($blockers === []) {
            return '';
        }

        $labels = [];

        if (($blockers['requisitorios'] ?? 0) > 0) {
            $labels[] = $blockers['requisitorios'].' requisitório(s)';
        }

        if (($blockers['lancamentos'] ?? 0) > 0) {
            $labels[] = $blockers['lancamentos'].' lançamento(s)';
        }

        if (($blockers['adiantamentos'] ?? 0) > 0) {
            $labels[] = $blockers['adiantamentos'].' adiantamento(s)';
        }

        if (($blockers['comissoes'] ?? 0) > 0) {
            $labels[] = $blockers['comissoes'].' comissão(ões)';
        }

        return 'Não é possível excluir este contato porque ele possui '.implode(' e ', $labels).' vinculado(s).';
    }
}
