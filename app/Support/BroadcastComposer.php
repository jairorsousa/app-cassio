<?php

namespace App\Support;

/** Monta exatamente o texto que sai na transmissão (a prévia usa a mesma função). Não acessa a rede. */
class BroadcastComposer
{
    /** null = vazio; false = inválido; string = URL normalizada (só http/https). */
    public static function normalizeUrl(?string $url): string|false|null
    {
        $u = trim((string) $url);
        if ($u === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) && ! preg_match('#^[^:/]+:\d+#', $u)) {
                return false; // javascript:, mailto:, data: etc.
            }
            $u = 'https://'.$u;
        }
        $scheme = strtolower((string) parse_url($u, PHP_URL_SCHEME));
        $host = (string) parse_url($u, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! str_contains($host, '.') || preg_match('/\s/', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return $u;
    }

    public static function formatText(string $text, bool $splitParagraphs = true): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $text);
        if ($splitParagraphs) {
            // Enter simples vira linha em branco; 2+ enters ficam como estão (idempotente).
            $t = preg_replace('/(?<!\n)\n(?!\n)/', "\n\n", $t);
        }

        return trim($t);
    }

    public static function compose(string $text, ?string $url, bool $splitParagraphs = true): string
    {
        $body = self::formatText($text, $splitParagraphs);
        $link = self::normalizeUrl($url);
        if (is_string($link)) {
            $body = $body === '' ? $link : $body."\n\n".$link;
        }

        return $body;
    }

    /** Contagens do público carregado (sem nova chamada ao Chatwoot). Duplicados não são removidos nesta fase. */
    public static function audienceStats(array $contacts): array
    {
        $missing = $invalid = 0;
        $seen = [];
        $dups = 0;
        foreach ($contacts as $c) {
            $digits = preg_replace('/\D/', '', (string) ($c['phone_number'] ?? ''));
            if ($digits === '') {
                $missing++;
            } elseif (strlen($digits) < 10 || strlen($digits) > 15) {
                $invalid++;
            }
            $key = $digits !== '' ? 'p'.$digits : 'i'.($c['id'] ?? spl_object_id((object) $c));
            if (isset($seen[$key])) {
                $dups++;
            }
            $seen[$key] = true;
        }
        $total = count($contacts);

        return ['total' => $total, 'with_id' => count(array_filter($contacts, fn ($c) => ! empty($c['id']))), 'missing_phone' => $missing, 'invalid_phone' => $invalid, 'duplicates' => $dups, 'valid' => max(0, $total - $missing - $invalid)];
    }
}
