<?php

namespace App\Support;

/** Junta os contatos das etiquetas escolhidas e deixa cada telefone uma única vez. */
class TransmissionAudience
{
    /** Telefone em formato +DDI...; número brasileiro sem DDI (10–11 dígitos) ganha 55. Não mexe no 9º dígito. */
    public static function e164(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if ($d === '') {
            return null;
        }
        if (strlen($d) === 10 || strlen($d) === 11) {
            $d = '55'.$d;
        }

        return (strlen($d) >= 12 && strlen($d) <= 15) ? '+'.$d : null;
    }

    public static function hash(string $e164): string
    {
        return hash_hmac('sha256', $e164, (string) config('app.key'));
    }

    /** Tira quem está na lista "Não receber" (uma consulta). Soma em 'suppressed'. */
    public static function withoutSuppressed(array $audience): array
    {
        $blocked = \App\Domains\Broadcasts\Models\TransmissionSuppression::activeHashes(array_column($audience['recipients'], 'phone_hash'));
        $keep = array_values(array_filter($audience['recipients'], fn ($r) => ! isset($blocked[$r['phone_hash']])));
        $audience['suppressed'] = ($audience['suppressed'] ?? 0) + (count($audience['recipients']) - count($keep));
        $audience['recipients'] = $keep;
        $audience['unique'] = count($keep);

        return $audience;
    }

    /**
     * @param  array<string, array<int, array{id:mixed,name:mixed,phone_number:mixed}>>  $byLabel
     */
    public static function build(array $byLabel): array
    {
        $recipients = [];
        $byContact = [];
        $raw = $missing = $invalid = $duplicates = 0;
        foreach ($byLabel as $label => $contacts) {
            foreach ($contacts as $c) {
                $raw++;
                $digits = preg_replace('/\D/', '', (string) ($c['phone_number'] ?? ''));
                $phone = self::e164($c['phone_number'] ?? null);
                $id = isset($c['id']) && $c['id'] !== '' ? (int) $c['id'] : null;
                if ($phone === null) {
                    // mesmo contato já incluído por outra etiqueta não conta como problema novo
                    if ($id !== null && isset($byContact[$id])) {
                        $duplicates++;
                    } elseif ($digits === '') {
                        $missing++;
                    } else {
                        $invalid++;
                    }

                    continue;
                }
                $h = self::hash($phone);
                if (isset($recipients[$h]) || ($id !== null && isset($byContact[$id]))) {
                    $duplicates++;
                    $key = isset($recipients[$h]) ? $h : $byContact[$id];
                    if (! in_array((string) $label, $recipients[$key]['labels'], true)) {
                        $recipients[$key]['labels'][] = (string) $label;
                    }

                    continue;
                }
                $recipients[$h] = ['contact_id' => $id, 'name' => $c['name'] ?? null, 'phone' => $phone, 'phone_hash' => $h, 'labels' => [(string) $label]];
                if ($id !== null) {
                    $byContact[$id] = $h;
                }
            }
        }

        return ['recipients' => array_values($recipients), 'raw' => $raw, 'unique' => count($recipients), 'duplicates' => $duplicates, 'missing_phone' => $missing, 'invalid_phone' => $invalid, 'suppressed' => 0];
    }
}
