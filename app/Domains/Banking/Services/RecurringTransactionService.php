<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\RecurringTransaction;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringTransactionService
{
    public function generateForToday(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $generated = 0;

        $candidates = RecurringTransaction::active()
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->get();

        foreach ($candidates as $rec) {
            if ($this->shouldRunToday($rec, $today)) {
                $this->runOnce($rec, $today);
                $generated++;
            }
        }

        return $generated;
    }

    private function shouldRunToday(RecurringTransaction $rec, Carbon $today): bool
    {
        if ($rec->last_run_date && $rec->last_run_date->isSameDay($today)) {
            return false;
        }

        return match ($rec->frequency) {
            'daily' => true,
            'weekly' => $rec->start_date->dayOfWeek === $today->dayOfWeek,
            'monthly' => ($rec->day_of_month ?? $rec->start_date->day) === $today->day
                || ($today->isLastOfMonth() && ($rec->day_of_month ?? $rec->start_date->day) > $today->day),
            'yearly' => $rec->start_date->month === $today->month && $rec->start_date->day === $today->day,
            default => false,
        };
    }

    private function runOnce(RecurringTransaction $rec, Carbon $today): void
    {
        DB::transaction(function () use ($rec, $today) {
            Transaction::create([
                'type' => $rec->type,
                'date' => $today,
                'amount' => $rec->amount,
                'description' => $rec->description,
                'status' => 'settled',
                'category_id' => $rec->category_id,
                'bank_account_id' => $rec->bank_account_id,
                'credit_card_id' => $rec->credit_card_id,
            ]);

            $rec->update(['last_run_date' => $today]);

            if ($rec->end_date && $today->greaterThanOrEqualTo($rec->end_date)) {
                $rec->update(['status' => 'finished']);
            }
        });
    }
}
