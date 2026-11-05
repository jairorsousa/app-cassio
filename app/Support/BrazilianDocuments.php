<?php

namespace App\Support;

/**
 * Validação de CPF/CNPJ e telefone BR. Não altera dados existentes inválidos;
 * só rejeita novos valores inválidos quando a regra é aplicada.
 */
class BrazilianDocuments
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function isValidCpf(?string $value): bool
    {
        $cpf = self::digits($value);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(?string $value): bool
    {
        $cnpj = self::digits($value);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnpj[$i] * $weights1[$i];
        }
        $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        if ((int) $cnpj[12] !== $digit) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $cnpj[$i] * $weights2[$i];
        }
        $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);

        return (int) $cnpj[13] === $digit;
    }

    public static function isValidCpfOrCnpj(?string $value): bool
    {
        $digits = self::digits($value);
        if ($digits === '') {
            return true;
        }
        if (strlen($digits) === 11) {
            return self::isValidCpf($digits);
        }
        if (strlen($digits) === 14) {
            return self::isValidCnpj($digits);
        }

        return false;
    }

    /** Aceita 10 ou 11 dígitos (fixo ou celular), com ou sem máscara. */
    public static function isValidPhone(?string $value): bool
    {
        $digits = self::digits($value);
        if ($digits === '') {
            return true;
        }

        return (bool) preg_match('/^\d{10,11}$/', $digits);
    }

    public static function formatCpfCnpj(?string $value): string
    {
        $digits = self::digits($value);
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
        }
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
        }

        return (string) $value;
    }

    public static function formatPhone(?string $value): string
    {
        $digits = self::digits($value);
        if (strlen($digits) === 11) {
            return '('.substr($digits, 0, 2).') '.substr($digits, 2, 5).'-'.substr($digits, 7);
        }
        if (strlen($digits) === 10) {
            return '('.substr($digits, 0, 2).') '.substr($digits, 2, 4).'-'.substr($digits, 6);
        }

        return (string) $value;
    }
}
