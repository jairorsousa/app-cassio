<?php

namespace App\Domains\Writs\Services;

use App\Domains\Banking\Services\ReceiptWritLinkService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Writs\Events\WritMovedToFinalized;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class WritService
{
    /**
     * Linear pipeline (com possibilidade de regressão livre).
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'monitoring' => ['negotiation', 'lost'],
        'negotiation' => ['monitoring', 'pending', 'lost'],
        'pending' => ['negotiation', 'signature', 'lost'],
        'signature' => ['pending', 'paid', 'lost'],
        'paid' => ['signature', 'petitioning', 'awaiting_receipt', 'finalized', 'lost'],
        'petitioning' => ['paid', 'awaiting_receipt', 'finalized', 'lost'],
        'awaiting_receipt' => ['petitioning', 'finalized', 'lost'],
        'finalized' => ['awaiting_receipt', 'petitioning', 'lost'],
        'lost' => ['monitoring', 'negotiation', 'pending', 'signature', 'paid', 'petitioning', 'awaiting_receipt'],
    ];

    /** @return array<string, array<int, string>> mapa completo (usado pelo kanban para não abrir modal de movimento proibido) */
    public function allowedTransitions(): array
    {
        return self::ALLOWED_TRANSITIONS;
    }

    /** @return array<int, string> */
    public function allowedTransitionsFrom(string $stage): array
    {
        return self::ALLOWED_TRANSITIONS[$stage] ?? [];
    }

    /**
     * @param  array<string, mixed>  $context  campos opcionais (monitoring_at, negotiation_at, cession_at, signature_at, paid_at, petitioned_at, awaiting_receipt_at, finalized_at, actual_receipt_amount, lost_reason, notes)
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
            $label = fn (string $s) => Writ::STAGE_LABELS[$s] ?? $s;
            $hint = ($current === 'pending' && $newStage === 'paid') ? ' Mova antes para Assinatura.' : '';
            throw new \DomainException("Transição não permitida: {$label($current)} → {$label($newStage)}.{$hint}");
        }

        if ($newStage === 'lost' && blank($context['lost_reason'] ?? null)) {
            throw new \DomainException('Informe o motivo para marcar o requisitório como perdido.');
        }

        if ($newStage === 'monitoring' && blank($context['monitoring_at'] ?? null) && blank($writ->monitoring_at)) {
            throw new \DomainException('Informe a data e hora para monitorar o processo.');
        }

        if ($newStage === 'petitioning' && blank($context['petitioned_at'] ?? null) && blank($writ->petitioned_at)) {
            throw new \DomainException('Informe a data e hora do peticionamento.');
        }

        if ($newStage === 'awaiting_receipt' && blank($context['awaiting_receipt_at'] ?? null) && blank($writ->awaiting_receipt_at)) {
            throw new \DomainException('Informe a data e hora para aguardar recebimento.');
        }

        if ($newStage === 'signature' && blank($context['signature_at'] ?? null) && blank($writ->signature_at)) {
            throw new \DomainException('Informe a data e hora da assinatura.');
        }


        $updatedWrit = DB::transaction(function () use ($writ, $current, $newStage, $context) {
            $writ = Writ::lockForUpdate()->findOrFail($writ->id);
            $patch = ['stage' => $newStage];

            if ($newStage === 'monitoring' && isset($context['monitoring_at'])) {
                $patch['monitoring_at'] = $context['monitoring_at'];
            }

            if ($newStage === 'negotiation') {
                $patch['negotiation_at'] = $context['negotiation_at'] ?? ($writ->negotiation_at ?? now());
            }

            if ($newStage === 'pending' && isset($context['cession_at'])) {
                $patch['cession_at'] = $context['cession_at'];
            }

            if ($newStage === 'signature') {
                if (isset($context['signature_at'])) {
                    $patch['signature_at'] = $context['signature_at'];
                } elseif (! $writ->signature_at) {
                    $patch['signature_at'] = now();
                }
            }


            if ($newStage === 'paid') {
                $patch['paid_at'] = $context['paid_at'] ?? ($writ->paid_at ?? \App\Support\BusinessDate::today());
                if (isset($context['paid_amount'])) {
                    $patch['paid_amount'] = $context['paid_amount'];
                }
                foreach (['notary_expenses_amount', 'other_expenses_amount'] as $expense) {
                    if (array_key_exists($expense, $context)) {
                        $patch[$expense] = (float) ($context[$expense] ?? 0);
                    }
                }
                if (isset($context['source_bank_account_id'])) {
                    $patch['source_bank_account_id'] = $context['source_bank_account_id'];
                }
            }

            if ($newStage === 'petitioning' && isset($context['petitioned_at'])) {
                $patch['petitioned_at'] = $context['petitioned_at'];
            }

            if ($newStage === 'awaiting_receipt' && isset($context['awaiting_receipt_at'])) {
                $patch['awaiting_receipt_at'] = $context['awaiting_receipt_at'];
            }

            if ($newStage === 'finalized') {
                $patch['finalized_at'] = $context['finalized_at'] ?? ($writ->finalized_at ?? \App\Support\BusinessDate::today());
                if (isset($context['actual_receipt_amount'])) {
                    $patch['actual_receipt_amount'] = $context['actual_receipt_amount'];
                }
                if (isset($context['destination_bank_account_id'])) {
                    $patch['destination_bank_account_id'] = $context['destination_bank_account_id'];
                }
            }

            if ($newStage === 'lost') {
                $patch['lost_reason'] = trim((string) $context['lost_reason']);
                $patch['lost_at'] = $context['lost_at'] ?? now();
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

        app(WritGoogleCalendarSyncDispatcher::class)->sync($updatedWrit);

        return $updatedWrit;
    }

    public function delete(Writ $writ): void
    {
        DB::transaction(function () use ($writ) {
            $writ = Writ::lockForUpdate()->findOrFail($writ->id);
            app(ReceiptWritLinkService::class)->guardWrit($writ);
            foreach ($writ->transactions()->lockForUpdate()->get() as $transaction) {
                app(TransactionService::class)->deleteGenerated($transaction);
            }
            $writ->history()->delete();
            Activity::where('subject_type', Writ::class)->where('subject_id', $writ->id)->delete();
            $writ->forceDelete();
        });
    }

    public function recordCessionDateChange(Writ $writ, ?CarbonInterface $previousCessionAt, CarbonInterface $newCessionAt): void
    {
        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => 'pending',
            'to_stage' => 'pending',
            'transitioned_at' => now(),
            'notes' => sprintf(
                'Data da cessão atualizada de: %s para: %s',
                $previousCessionAt ? $previousCessionAt->format('d/m/Y H:i') : '—',
                $newCessionAt->format('d/m/Y H:i'),
            ),
            'user_id' => Auth::id(),
        ]);
    }

    public function recordMonitoringDateChange(Writ $writ, ?CarbonInterface $previousMonitoringAt, CarbonInterface $newMonitoringAt): void
    {
        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => 'monitoring',
            'to_stage' => 'monitoring',
            'transitioned_at' => now(),
            'notes' => sprintf(
                'Data do monitoramento atualizada de: %s para: %s',
                $previousMonitoringAt ? $previousMonitoringAt->format('d/m/Y H:i') : '—',
                $newMonitoringAt->format('d/m/Y H:i'),
            ),
            'user_id' => Auth::id(),
        ]);
    }

    public function recordPetitionDateChange(Writ $writ, ?CarbonInterface $previousPetitionedAt, CarbonInterface $newPetitionedAt): void
    {
        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => 'petitioning',
            'to_stage' => 'petitioning',
            'transitioned_at' => now(),
            'notes' => sprintf(
                'Data do peticionamento atualizada de: %s para: %s',
                $previousPetitionedAt ? $previousPetitionedAt->format('d/m/Y H:i') : '—',
                $newPetitionedAt->format('d/m/Y H:i'),
            ),
            'user_id' => Auth::id(),
        ]);
    }

    public function recordAwaitingReceiptDateChange(Writ $writ, ?CarbonInterface $previousAwaitingReceiptAt, CarbonInterface $newAwaitingReceiptAt): void
    {
        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => 'awaiting_receipt',
            'to_stage' => 'awaiting_receipt',
            'transitioned_at' => now(),
            'notes' => sprintf(
                'Data de aguardar recebimento atualizada de: %s para: %s',
                $previousAwaitingReceiptAt ? $previousAwaitingReceiptAt->format('d/m/Y H:i') : '—',
                $newAwaitingReceiptAt->format('d/m/Y H:i'),
            ),
            'user_id' => Auth::id(),
        ]);
    }


    public function recordSignatureDateChange(Writ $writ, ?CarbonInterface $previousSignatureAt, CarbonInterface $newSignatureAt): void
    {
        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => 'signature',
            'to_stage' => 'signature',
            'transitioned_at' => now(),
            'notes' => sprintf(
                'Data da assinatura atualizada de: %s para: %s',
                $previousSignatureAt ? $previousSignatureAt->format('d/m/Y H:i') : '—',
                $newSignatureAt->format('d/m/Y H:i'),
            ),
            'user_id' => Auth::id(),
        ]);
    }
}
