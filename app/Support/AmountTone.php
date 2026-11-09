<?php

namespace App\Support;

/**
 * Cor do valor de um lançamento (só o valor é colorido; o texto ao redor fica na cor padrão).
 * Despesa = vermelho, receita/entrada = verde, transferência entre contas = cor de texto padrão.
 * Estorno de cartão (despesa negativa) volta dinheiro, então fica verde. Pagamento de fatura é saída: vermelho.
 * Não usado no Divisões, que tem lógica própria (quem deve a quem).
 */
class AmountTone
{
    public const IN = 'in';

    public const OUT = 'out';

    public const NEUTRAL = 'neutral';

    public static function for(?string $type, float|int|string|null $amount = null): string
    {
        return match ($type) {
            'income' => self::IN,
            'expense' => (float) $amount < 0 ? self::IN : self::OUT,
            'invoice_payment' => self::OUT,
            default => self::NEUTRAL, // transfer e qualquer tipo sem sentido de entrada/saída
        };
    }

    /** Classe CSS (definida em layouts/app: contraste AA no claro e no escuro). */
    public static function cssClass(?string $type, float|int|string|null $amount = null): string
    {
        return 'fx-amount-'.self::for($type, $amount);
    }

    public static function money(float|int|string|null $amount, bool $absolute = true): string
    {
        $value = (float) $amount;

        return ($absolute || $value >= 0 ? '' : '− ').'R$ '.number_format(abs($value), 2, ',', '.');
    }
}
