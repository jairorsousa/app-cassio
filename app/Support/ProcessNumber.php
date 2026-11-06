<?php

namespace App\Support;

/**
 * Número de processo no padrão CNJ: NNNNNNN-DD.AAAA.J.TR.OOOO.
 * Só formata quando o texto tem exatamente 20 dígitos e apenas separadores comuns
 * (espaço, ponto, hífen, barra). Qualquer outro texto é devolvido sem alteração
 * (apenas sem espaços nas pontas). Nunca completa com zeros.
 */
final class ProcessNumber
{
    public const CANONICAL = '/^\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}$/';

    public static function format(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || ! preg_match('/^[\d\s.\-\/\x{2010}-\x{2015}]+$/u', $trimmed)) {
            return $trimmed;
        }
        $digits = preg_replace('/\D/', '', $trimmed);
        if (strlen($digits) !== 20) {
            return $trimmed;
        }

        return substr($digits, 0, 7).'-'.substr($digits, 7, 2).'.'.substr($digits, 9, 4).'.'
            .substr($digits, 13, 1).'.'.substr($digits, 14, 2).'.'.substr($digits, 16, 4);
    }

    public static function isCanonical(?string $value): bool
    {
        return $value !== null && preg_match(self::CANONICAL, $value) === 1;
    }
}
