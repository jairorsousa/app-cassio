<?php

namespace App\Support;

/**
 * Valores em reais digitados ou colados no padrão brasileiro.
 * Aceita 1234,56 · 1.234,56 · R$ 1.234,56 · 1234.56 · 1,234.56 e devolve "1234.56".
 */
final class Money
{
    public static function normalize(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $text = preg_replace('/[\s\x{00a0}]+/u', '', trim($value)) ?? '';
        $text = str_ireplace('R$', '', $text);
        if ($text === '' || ! preg_match('/\d/', $text)) {
            return '';
        }

        $negative = str_starts_with($text, '-') || str_ends_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $text = preg_replace('/[^\d.,]/', '', $text) ?? '';

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        $integers = $text;
        $fraction = '';

        if ($lastComma !== false && $lastDot !== false) {
            $at = max($lastComma, $lastDot);
            $integers = substr($text, 0, $at);
            $fraction = substr($text, $at + 1);
        } elseif ($lastComma !== false) {
            $parts = explode(',', $text);
            if (count($parts) > 2) {
                $integers = implode('', $parts);
            } else {
                [$integers, $fraction] = $parts;
            }
        } elseif ($lastDot !== false) {
            $parts = explode('.', $text);
            if (count($parts) > 2 || (strlen($parts[1]) === 3 && strlen($parts[0]) <= 3 && preg_match('/^[1-9]/', $parts[0]))) {
                $integers = implode('', $parts);
            } else {
                [$integers, $fraction] = $parts;
            }
        }

        $integers = ltrim(preg_replace('/\D/', '', $integers) ?? '', '0') ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction) ?? '';
        $amount = round((float) ($integers.'.'.($fraction === '' ? '0' : $fraction)), 2);

        return number_format($negative ? -$amount : $amount, 2, '.', '');
    }

    public static function toFloat(string|int|float|null $value): float
    {
        $normalized = self::normalize($value);

        return $normalized === '' ? 0.0 : (float) $normalized;
    }
}
