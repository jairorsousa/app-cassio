<?php

namespace App\Domains\Broadcasts\Models;

use App\Support\TransmissionAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Lista "Não receber": vale para o sistema todo (consentimento é do telefone, não de quem envia). */
class TransmissionSuppression extends Model
{
    protected $fillable = ['phone_hash', 'phone', 'name', 'reason', 'source', 'created_by', 'suppressed_at', 'restored_at', 'restored_by'];

    protected $casts = ['phone' => 'encrypted', 'suppressed_at' => 'datetime', 'restored_at' => 'datetime'];

    protected $hidden = ['phone'];

    public const SOURCES = ['manual' => 'Manual'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('restored_at');
    }

    public static function isActive(string $phoneHash): bool
    {
        return self::active()->where('phone_hash', $phoneHash)->exists();
    }

    /** @return array<string, true> */
    public static function activeHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $found = [];
        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            foreach (self::active()->whereIn('phone_hash', $chunk)->pluck('phone_hash') as $h) {
                $found[$h] = true;
            }
        }

        return $found;
    }

    /** Adiciona (ou mantém) o telefone na lista. Retorna null se o telefone for inválido. */
    public static function suppress(string $rawPhone, ?string $name, ?string $reason, ?int $userId, string $source = 'manual'): ?self
    {
        $phone = TransmissionAudience::e164($rawPhone);
        if ($phone === null) {
            return null;
        }
        $hash = TransmissionAudience::hash($phone);
        $existing = self::active()->where('phone_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        return self::create(['phone_hash' => $hash, 'phone' => $phone, 'name' => $name ?: null, 'reason' => $reason ?: null, 'source' => $source, 'created_by' => $userId, 'suppressed_at' => now()]);
    }

    /** Volta a receber: só por ação explícita. O registro fica para auditoria. */
    public function restore(?int $userId): void
    {
        if (! $this->restored_at) {
            $this->update(['restored_at' => now(), 'restored_by' => $userId]);
        }
    }

    public function maskedPhone(): string
    {
        $d = preg_replace('/\D/', '', (string) $this->phone);

        return $d === '' ? '—' : '••••'.substr($d, -4);
    }

    /**
     * Classifica uma resposta recebida. Ainda NÃO está ligado a nenhum webhook:
     * nada no sistema reage a mensagens recebidas nesta fase.
     */
    public static function classify(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));
        $t = preg_replace('/[\s[:punct:]]+/u', ' ', $t);
        $t = trim(strtr($t, ['ã' => 'a', 'á' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']));

        return in_array($t, ['sair', 'quero sair', 'nao quero mais receber', 'parar', 'pare'], true) ? 'optout' : 'unknown';
    }
}
