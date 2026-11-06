<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Juros simples e encargos de mora usados em Valores a receber e Empréstimos.
 *
 * Fórmulas (valores em centavos; taxas em % a.m.):
 * - Juros combinados: principal * (taxa_am/100) * (dias/30), desde a data inicial.
 * - Multa após vencimento (uma vez): principal * (multa_pct/100).
 * - Mora após vencimento: principal * (taxa_mora_am/100) * (dias_atraso/30).
 *
 * Exemplos: 1000 a 2% a.m. por 90 dias = 60; multa 2% + mora 1% a.m. por 30 dias = 20 + 10 = 30.
 *
 * Com baixas parciais de principal, os juros combinados são apurados por segmentos
 * (principal vigente em cada intervalo entre a data inicial e as baixas).
 *
 * Alocação de baixas (ordem): 1) multa em aberto, 2) juros (combinados + mora) em aberto, 3) principal.
 * O principal em aberto é o principal contratado menos o principal já baixado (não inclui encargos).
 */
class SimpleInterest
{
    /** @return int juros em centavos */
    public static function interestCents(int $principalCents, float $rateAm, int $days): int
    {
        if ($principalCents <= 0 || $rateAm <= 0 || $days <= 0) {
            return 0;
        }

        return (int) round($principalCents * ($rateAm / 100) * ($days / 30));
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
    ): array {
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
            $contracted += self::interestCents($principalOpen, $rateAm, self::daysBetween($cursor, $until));
            $cursor = $until;
            if (Carbon::parse($event['date'])->gt($asOfDay)) {
                continue;
            }
            $principalOpen = max(0, $principalOpen - $event['principal']);
            $interestPaid += $event['interest'];
            $penaltyPaid += $event['penalty'];
            $cursor = $event['date'];
        }
        $contracted += self::interestCents($principalOpen, $rateAm, self::daysBetween($cursor, $asOfDay));

        $days = self::daysBetween($startDay, $asOfDay);
        $daysLate = 0;
        $penaltyAccrued = 0;
        $lateInterest = 0;
        if ($dueDate && Carbon::parse($asOfDay)->gt(Carbon::parse($dueDate)->startOfDay())) {
            $daysLate = self::daysBetween($dueDate, $asOfDay);
            $penaltyAccrued = self::penaltyCents($principalOpen, $penaltyPct);
            $lateInterest = self::interestCents($principalOpen, $lateRateAm, $daysLate);
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
}
