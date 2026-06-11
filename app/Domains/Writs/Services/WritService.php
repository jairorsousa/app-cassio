<?php

namespace App\Domains\Writs\Services;

use App\Domains\Writs\Events\WritMovedToFinalized;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WritService
{
    /**
     * Linear pipeline (com possibilidade de regressão livre).
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'negotiation' => ['pending', 'paid'],
        'pending' => ['negotiation', 'paid'],
        'paid' => ['pending', 'petitioning', 'finalized'],
        'petitioning' => ['paid', 'finalized'],
        'finalized' => ['petitioning'],
    ];

    /**
     * @param  array<string, mixed>  $context  campos opcionais (cession_at, paid_at, finalized_at, actual_receipt_amount, notes)
     */
    public function transitionTo(Writ $writ, string $newStage, array $context = []): Writ
    {
        $current = $writ->stage;

        if ($current === $newStage) {
            return $writ;
        }

        if (! in_array($newStage, Writ::STAGES, true)) {
            throw new \InvalidArgumentException("Etapa inválida: {$newStage}");
        }

        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];
        if (! in_array($newStage, $allowed, true)) {
            throw new \DomainException("Transição não permitida: {$current} → {$newStage}");
        }

        return DB::transaction(function () use ($writ, $current, $newStage, $context) {
            $patch = ['stage' => $newStage];

            if ($newStage === 'pending' && isset($context['cession_at'])) {
                $patch['cession_at'] = $context['cession_at'];
            }

            if ($newStage === 'paid') {
                $patch['paid_at'] = $context['paid_at'] ?? ($writ->paid_at ?? now()->toDateString());
                if (isset($context['paid_amount'])) {
                    $patch['paid_amount'] = $context['paid_amount'];
                }
                if (isset($context['source_bank_account_id'])) {
                    $patch['source_bank_account_id'] = $context['source_bank_account_id'];
                }
            }

            if ($newStage === 'petitioning' && isset($context['petitioned_at'])) {
                $patch['petitioned_at'] = $context['petitioned_at'];
            }

            if ($newStage === 'finalized') {
                $patch['finalized_at'] = $context['finalized_at'] ?? ($writ->finalized_at ?? now()->toDateString());
                if (isset($context['actual_receipt_amount'])) {
                    $patch['actual_receipt_amount'] = $context['actual_receipt_amount'];
                }
                if (isset($context['destination_bank_account_id'])) {
                    $patch['destination_bank_account_id'] = $context['destination_bank_account_id'];
                }
            }

            $writ->update($patch);

            WritStageHistory::create([
                'writ_id' => $writ->id,
                'from_stage' => $current,
                'to_stage' => $newStage,
                'transitioned_at' => now(),
                'notes' => $context['notes'] ?? null,
                'user_id' => Auth::id(),
            ]);

            if ($newStage === 'paid') {
                WritMovedToPaid::dispatch($writ->fresh());
            }

            if ($newStage === 'finalized') {
                WritMovedToFinalized::dispatch($writ->fresh());
            }

            return $writ->fresh('history');
        });
    }
}
