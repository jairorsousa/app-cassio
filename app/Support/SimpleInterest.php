<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Juros e encargos de mora usados em Valores a receber e Empréstimos.
 *
 * Periodicidade da taxa: % ao mês, com pro rata de dias/30 (como já era).
 * Tipo (padrão: simples):
 * - Simples:    juros = P * i * n,  total = P * (1 + i * n)
 * - Compostos:  total = P * (1 + i)^n,  juros = P * ((1 + i)^n - 1)
 *   onde i = taxa_am/100 e n = dias/30.
 *
 * Encargos:
 * - Juros combinados: desde a data inicial, sobre o principal vigente em cada intervalo
 *   entre a data inicial e as baixas de principal (sem capitalizar juros em aberto).
 * - Multa após vencimento (uma vez): principal * (multa_pct/100). Independente do tipo.
 * - Mora após vencimento: mesma fórmula do tipo da mora, sobre o principal em aberto,
 *   com n = dias_atraso/30.
 *
 * Exemplos (simples): 1000 a 2% a.m. por 90 dias = 60; multa 2% + mora 1% a.m. por 30 dias = 20 + 10 = 30.
 * Exemplo (compostos): 1000 a 2% a.m. por 90 dias = 1000 * ((1,02)^3 − 1) = 61,21.
 *
 * Alocação de baixas (ordem): 1) multa em aberto, 2) juros (combinados + mora) em aberto, 3) principal.
 * Juros só viram receita quando recebidos. O principal em aberto é o principal contratado
 * menos o principal já baixado (não inclui encargos).
 */
class SimpleInterest
{
    public const TYPE_SIMPLE = 'simple';

    public const TYPE_COMPOUND = 'compound';

    public static function normalizeType(?string $type): string
    {
        return $type === self::TYPE_COMPOUND ? self::TYPE_COMPOUND : self::TYPE_SIMPLE;
    }

    public static function typeLabel(?string $type): string
    {
        return self::normalizeType($type) === self::TYPE_COMPOUND ? 'Compostos' : 'Simples';
    }

    public static function isValidType(?string $type): bool
    {
        return in_array($type, [self::TYPE_SIMPLE, self::TYPE_COMPOUND], true);
    }

    /** @return int juros em centavos */
    public static function interestCents(int $principalCents, float $rateAm, int $days, string $type = self::TYPE_SIMPLE): int
    {
        if ($principalCents <= 0 || $rateAm <= 0 || $days <= 0) {
            return 0;
        }

        $i = $rateAm / 100;
        $n = $days / 30;
        if (self::normalizeType($type) === self::TYPE_COMPOUND) {
            return (int) round($principalCents * (pow(1 + $i, $n) - 1));
        }

        return (int) round($principalCents * $i * $n);
    }

    /** @return int multa em centavos (aplicação única) */
    public static function penaltyCents(int $principalCents, float $penaltyPct): int
    {
        if ($principalCents <= 0 || $penaltyPct <= 0) {
            return 0;
        }

        return (int) round($principalCents * ($penaltyPct / 100));
    }

    public static function daysBetween(Carbon|string $from, Carbon|string $to): int
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($end->lte($start)) {
            return 0;
        }

        return (int) $start->diffInDays($end);
    }

    /**
     * @param  list<array{date:string,principal:int,interest:int,penalty:int}>  $settlements
     * @return array{principal_open:int,interest_open:int,penalty_open:int,interest_accrued:int,penalty_accrued:int,balance:int,days:int,days_late:int}
     */
    public static function balance(
        int $principalCents,
        string $startDate,
        ?string $dueDate,
        float $rateAm,
        float $penaltyPct,
        float $lateRateAm,
        string $asOf,
        array $settlements = [],
        string $interestType = self::TYPE_SIMPLE,
        string $lateInterestType = self::TYPE_SIMPLE,
    ): array {
        $interestType = self::normalizeType($interestType);
        $lateInterestType = self::normalizeType($lateInterestType);
        $asOfDay = Carbon::parse($asOf)->startOfDay()->toDateString();
        $startDay = Carbon::parse($startDate)->startOfDay()->toDateString();

        $events = collect($settlements)
            ->map(fn ($row) => [
                'date' => Carbon::parse($row['date'])->startOfDay()->toDateString(),
                'principal' => (int) $row['principal'],
                'interest' => (int) $row['interest'],
                'penalty' => (int) $row['penalty'],
            ])
            ->sortBy([['date', 'asc']])
            ->values();

        $principalOpen = $principalCents;
        $interestPaid = 0;
        $penaltyPaid = 0;
        $cursor = $startDay;
        $contracted = 0;

        foreach ($events as $event) {
            $until = Carbon::parse($event['date'])->lt($asOfDay) ? $event['date'] : $asOfDay;
            $contracted += self::interestCents($principalOpen, $rateAm, self::daysBetween($cursor, $until), $interestType);
            $cursor = $until;
            if (Carbon::parse($event['date'])->gt($asOfDay)) {
                continue;
            }
            $principalOpen = max(0, $principalOpen - $event['principal']);
            $interestPaid += $event['interest'];
            $penaltyPaid += $event['penalty'];
            $cursor = $event['date'];
        }
        $contracted += self::interestCents($principalOpen, $rateAm, self::daysBetween($cursor, $asOfDay), $interestType);

        $days = self::daysBetween($startDay, $asOfDay);
        $daysLate = 0;
        $penaltyAccrued = 0;
        $lateInterest = 0;
        if ($dueDate && Carbon::parse($asOfDay)->gt(Carbon::parse($dueDate)->startOfDay())) {
            $daysLate = self::daysBetween($dueDate, $asOfDay);
            $penaltyAccrued = self::penaltyCents($principalOpen, $penaltyPct);
            $lateInterest = self::interestCents($principalOpen, $lateRateAm, $daysLate, $lateInterestType);
        }

        $interestAccrued = $contracted + $lateInterest;
        $interestOpen = max(0, $interestAccrued - $interestPaid);
        $penaltyOpen = max(0, $penaltyAccrued - $penaltyPaid);

        return [
            'principal_open' => $principalOpen,
            'interest_open' => $interestOpen,
            'penalty_open' => $penaltyOpen,
            'interest_accrued' => $interestAccrued,
            'penalty_accrued' => $penaltyAccrued,
            'balance' => $principalOpen + $interestOpen + $penaltyOpen,
            'days' => $days,
            'days_late' => $daysLate,
        ];
    }

    /**
     * Prévia do total (principal + juros combinados) em cada tipo, até o vencimento
     * informado ou, se não houver, em 30 dias.
     *
     * @return array{until_label:string,simple:string,compound:string,simple_cents:int,compound_cents:int}|null
     */
    public static function previewTotals(int $principalCents, string $startDate, ?string $until, float $rateAm): ?array
    {
        if ($principalCents <= 0 || $rateAm <= 0) {
            return null;
        }
        try {
            $start = Carbon::parse($startDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        $given = null;
        if ($until) {
            try {
                $given = Carbon::parse($until)->startOfDay();
            } catch (\Throwable) {
                $given = null;
            }
        }
        $asOf = ($given && $given->gt($start)) ? $given : $start->copy()->addDays(30);
        $days = self::daysBetween($start, $asOf);
        $simple = self::interestCents($principalCents, $rateAm, $days, self::TYPE_SIMPLE);
        $compound = self::interestCents($principalCents, $rateAm, $days, self::TYPE_COMPOUND);

        return [
            'until_label' => ($given && $given->gt($start))
                ? 'até '.$asOf->format('d/m/Y')
                : 'em 30 dias',
            'simple' => self::moneyBr($principalCents + $simple),
            'compound' => self::moneyBr($principalCents + $compound),
            'simple_cents' => $principalCents + $simple,
            'compound_cents' => $principalCents + $compound,
        ];
    }

    /**
     * Prévia só da mora (sem multa e sem principal) após 30 dias de atraso.
     *
     * @return array{until_label:string,simple:string,compound:string,simple_cents:int,compound_cents:int}|null
     */
    public static function previewLateTotals(int $principalCents, float $lateRateAm): ?array
    {
        if ($principalCents <= 0 || $lateRateAm <= 0) {
            return null;
        }
        $simple = self::interestCents($principalCents, $lateRateAm, 30, self::TYPE_SIMPLE);
        $compound = self::interestCents($principalCents, $lateRateAm, 30, self::TYPE_COMPOUND);

        return [
            'until_label' => 'mora em 30 dias de atraso',
            'simple' => self::moneyBr($simple),
            'compound' => self::moneyBr($compound),
            'simple_cents' => $simple,
            'compound_cents' => $compound,
        ];
    }

    /**
     * Aloca uma baixa em centavos: multa → juros → principal.
     *
     * @return array{principal:int,interest:int,penalty:int}
     */
    public static function allocate(int $paymentCents, int $principalOpen, int $interestOpen, int $penaltyOpen): array
    {
        if ($paymentCents <= 0) {
            throw new \InvalidArgumentException('O valor da baixa deve ser positivo.');
        }
        $max = $principalOpen + $interestOpen + $penaltyOpen;
        if ($paymentCents > $max) {
            throw new \DomainException('O recebimento não pode ultrapassar o saldo em aberto.');
        }
        $left = $paymentCents;
        $penalty = min($left, $penaltyOpen);
        $left -= $penalty;
        $interest = min($left, $interestOpen);
        $left -= $interest;
        $principal = min($left, $principalOpen);

        return ['principal' => $principal, 'interest' => $interest, 'penalty' => $penalty];
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function moneyBr(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';

        return $sign.'R$ '.number_format(abs($cents) / 100, 2, ',', '.');
    }
}
