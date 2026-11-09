<?php

namespace App\Domains\Contacts\Models;

use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Writs\Models\WritAssignor;
use App\Support\BrazilianDocuments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;
    use \App\Support\HidesTestRecords;

    public const ROLE_OPTIONS = [
        'cedente' => 'Cedente',
        'corretor' => 'Corretor',
        'advogado' => 'Advogado',
        'pessoal' => 'Pessoal',
        'cliente' => 'Cliente',
    ];

    protected $fillable = [
        'is_test',
        'name', 'type', 'roles', 'document', 'birth_date',
        'phone', 'phones', 'email', 'emails', 'address',
        'zip_code', 'street', 'neighborhood', 'number', 'complement', 'city', 'state',
        'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'pix_key', 'pix_key_type',
        'status', 'notes',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'birth_date' => 'date',
        'phones' => 'array',
        'emails' => 'array',
        'roles' => 'array',
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            $roles = array_values(array_unique(array_filter((array) $contact->roles)));
            if ($roles === [] && $contact->type) {
                $roles = [$contact->type];
            }
            $roles = array_values(array_filter($roles, fn ($role) => array_key_exists($role, self::ROLE_OPTIONS)));
            if ($roles === []) {
                $roles = ['cedente'];
            }
            $contact->roles = $roles;
            // type continua sendo o papel principal (compatibilidade com filtros antigos).
            if (! in_array($contact->type, $roles, true)) {
                $contact->type = $roles[0];
            }
        });

        // Renomear em Contatos atualiza o usuário e os participantes do Divisões vinculados (PersonNameSync).
        static::updated(function (Contact $contact) {
            if ($contact->wasChanged('name')) {
                \App\Support\PersonNameSync::fromContact($contact);
            }
        });
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

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

    /**
     * Compatível com o type legado e com roles (JSON).
     * Aceita contatos cujo type OU algum papel em roles corresponda.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where(function (Builder $inner) use ($type) {
            $inner->where('type', $type)
                ->orWhereJsonContains('roles', $type);
        });
    }

    public function scopeWithRole($query, string $role)
    {
        return $query->ofType($role);
    }

    public function hasRole(string $role): bool
    {
        if ($this->type === $role) {
            return true;
        }

        return in_array($role, (array) $this->roles, true);
    }

    /** @return list<string> */
    public function roleList(): array
    {
        $roles = array_values(array_unique(array_filter((array) $this->roles)));
        if ($roles === [] && $this->type) {
            $roles = [$this->type];
        }

        return $roles === [] ? ['cedente'] : $roles;
    }

    public function typeLabel(): string
    {
        $labels = array_map(
            fn ($role) => self::ROLE_OPTIONS[$role] ?? ucfirst($role),
            $this->roleList(),
        );

        return implode(', ', $labels);
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
     * Cadastros incompletos / inválidos (avisos, sem apagar).
     *
     * @return list<string>
     */
    public function incompletenessIssues(): array
    {
        $issues = [];
        $roles = $this->roleList();

        if (in_array('cedente', $roles, true)) {
            $doc = trim((string) $this->document);
            if ($doc === '') {
                $issues[] = 'CPF/CNPJ obrigatório para cedente';
            } elseif (! BrazilianDocuments::isValidCpfOrCnpj($doc)) {
                $issues[] = 'CPF/CNPJ inválido';
            }
        } elseif (trim((string) $this->document) !== '' && ! BrazilianDocuments::isValidCpfOrCnpj($this->document)) {
            $issues[] = 'CPF/CNPJ inválido';
        }

        $phones = array_values(array_filter($this->phones ?: [$this->phone]));
        foreach ($phones as $phone) {
            if (! BrazilianDocuments::isValidPhone($phone)) {
                $issues[] = 'Telefone em formato inválido';
                break;
            }
        }

        if (in_array('corretor', $roles, true)) {
            $hasPix = trim((string) $this->pix_key) !== '';
            $hasBank = trim((string) $this->bank_name) !== '' && trim((string) $this->bank_account) !== '';
            if (! $hasPix && ! $hasBank) {
                $issues[] = 'Corretor sem PIX nem dados bancários';
            }
        }

        return $issues;
    }

    public function isIncomplete(): bool
    {
        return $this->incompletenessIssues() !== [];
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
            'valores_a_receber' => $this->receivables()->count() + ReceiptDestination::where('contact_id', $this->id)->count(),
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
        if (($blockers['valores_a_receber'] ?? 0) > 0) {
            $labels[] = $blockers['valores_a_receber'].' vínculo(s) de valores a receber ou repasses';
        }

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
