<?php

namespace App\Support;

use App\Domains\Broadcasts\Models\TransmissionSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Agendamentos de transmissão. Horários digitados e exibidos em America/Fortaleza (UTC−3); gravados em UTC.
 * Semanal: uma vez por semana, alternando segunda → terça → quarta e manhã (09:00) ↔ tarde (14:00).
 */
class TransmissionScheduler
{
    public const TZ = 'America/Fortaleza';

    public const PERIOD_TIMES = ['morning' => '09:00', 'afternoon' => '14:00'];

    public const MIN_LEAD_MINUTES = 5;

    /** Valida o primeiro envio. Retorna [CarbonImmutable UTC|null, string erro|null]. */
    public static function firstRun(string $mode, string $date, string $time, string $period): array
    {
        if ($mode === 'now') {
            return [null, null];
        }
        if ($mode === 'weekly') {
            if (! isset(self::PERIOD_TIMES[$period])) {
                return [null, 'Escolha o período: manhã (09:00) ou tarde (14:00).'];
            }
            $time = self::PERIOD_TIMES[$period];
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return [null, 'Informe data e horário válidos.'];
        }
        try {
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, self::TZ);
        } catch (\Throwable) {
            return [null, 'Informe data e horário válidos.'];
        }
        if (! $local || $local->format('Y-m-d H:i') !== $date.' '.$time) {
            return [null, 'Informe data e horário válidos.'];
        }
        if ($time < '08:00' || $time > '20:00') {
            return [null, 'Escolha um horário entre 08:00 e 20:00 (horário de Fortaleza/Imperatriz, UTC−3).'];
        }
        if ($mode === 'weekly' && ! in_array($local->dayOfWeekIso, [1, 2, 3], true)) {
            return [null, 'O envio semanal começa numa segunda, terça ou quarta-feira.'];
        }
        if ($local->lt(CarbonImmutable::now(self::TZ)->addMinutes(self::MIN_LEAD_MINUTES))) {
            return [null, 'Escolha um horário com pelo menos 5 minutos de antecedência.'];
        }

        return [$local->setTimezone(config('app.timezone')), null];
    }

    /** Ocorrência k (0 = primeira) de um agendamento semanal, em UTC. */
    public static function weeklyOccurrence(CarbonImmutable $firstUtc, string $firstPeriod, int $k): CarbonImmutable
    {
        $first = $firstUtc->setTimezone(self::TZ);
        if ($k === 0) {
            return $firstUtc->setTimezone(config('app.timezone'));
        }
        $d0 = $first->dayOfWeekIso; // 1..3
        $day = (($d0 - 1 + $k) % 3) + 1;
        $period = $k % 2 === 0 ? $firstPeriod : ($firstPeriod === 'morning' ? 'afternoon' : 'morning');
        [$h, $m] = explode(':', self::PERIOD_TIMES[$period]);

        return $first->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($k)->addDays($day - 1)->setTime((int) $h, (int) $m)->setTimezone(config('app.timezone'));
    }

    public static function weekKey(CarbonImmutable $utc): string
    {
        return $utc->setTimezone(self::TZ)->format('o-\WW');
    }

    public static function create(?int $userId, string $mode, string $body, array $labels, ?UploadedFile $media, CarbonImmutable $firstUtc, string $period): TransmissionSchedule
    {
        return DB::transaction(function () use ($userId, $mode, $body, $labels, $media, $firstUtc, $period) {
            $s = TransmissionSchedule::create([
                'created_by' => $userId, 'kind' => $mode === 'weekly' ? 'weekly' : 'once', 'status' => 'active', 'labels' => array_values($labels), 'body' => $body,
                'first_run_at' => $firstUtc, 'first_period' => $mode === 'weekly' ? $period : null, 'next_run_at' => $firstUtc,
            ]);
            if ($media) {
                $ext = strtolower($media->getClientOriginalExtension());
                $path = $media->storeAs('transmissions/schedule-'.$s->id, Str::random(32).'.'.$ext, 'local');
                $s->update(['media_path' => $path, 'media_name' => $media->getClientOriginalName(), 'media_mime' => TransmissionDispatcher::AUDIO_MIME[$ext] ?? $media->getMimeType()]);
            }

            return $s;
        });
    }

    /** Próxima ocorrência semanal estritamente depois de $after (pula as perdidas, sem acumular). */
    private static function nextWeeklyAfter(TransmissionSchedule $s, CarbonImmutable $after, int $fromK): array
    {
        $k = $fromK;
        $first = CarbonImmutable::instance($s->first_run_at);
        do {
            $at = self::weeklyOccurrence($first, (string) $s->first_period, $k);
            $k++;
        } while ($at->lte($after) && $k < $fromK + 600);

        return [$at, $k - 1];
    }

    public static function owned(int $id, ?int $userId): TransmissionSchedule
    {
        return TransmissionSchedule::where('created_by', $userId)->findOrFail($id);
    }

    public static function pause(int $id, ?int $userId): bool
    {
        return DB::transaction(function () use ($id, $userId) {
            $s = TransmissionSchedule::where('created_by', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($s->kind !== 'weekly' || $s->status !== 'active') {
                return false;
            }
            $s->update(['status' => 'paused']);

            return true;
        });
    }

    public static function resume(int $id, ?int $userId): bool
    {
        return DB::transaction(function () use ($id, $userId) {
            $s = TransmissionSchedule::where('created_by', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($s->kind !== 'weekly' || $s->status !== 'paused') {
                return false;
            }
            // Nunca dispara atrasado: vai para a próxima ocorrência futura (com 5 min de folga).
            [$at, $k] = self::nextWeeklyAfter($s, CarbonImmutable::now()->addMinutes(self::MIN_LEAD_MINUTES), (int) $s->runs_count);
            $s->update(['status' => 'active', 'next_run_at' => $at, 'runs_count' => $k, 'last_error' => null]);

            return true;
        });
    }

    public static function cancel(int $id, ?int $userId): bool
    {
        $s = DB::transaction(function () use ($id, $userId) {
            $s = TransmissionSchedule::where('created_by', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($s->status, ['active', 'paused'], true)) {
                return null;
            }
            $s->update(['status' => 'cancelled', 'next_run_at' => null]);

            return $s;
        });
        if ($s) {
            self::releaseMedia($s);
        }

        return (bool) $s;
    }

    private static function releaseMedia(TransmissionSchedule $s): void
    {
        if ($s->media_path) {
            Storage::disk('local')->deleteDirectory('transmissions/schedule-'.$s->id);
        }
    }

    /**
     * Executa os agendamentos vencidos. Cada ocorrência é reservada (e o próximo horário calculado) numa transação
     * com trava ANTES de buscar contatos: duas execuções simultâneas não geram dois lotes.
     */
    public static function runDue(ChatwootContacts $contacts): array
    {
        $created = [];
        $ids = TransmissionSchedule::where('status', 'active')->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->orderBy('next_run_at')->pluck('id');
        foreach ($ids as $id) {
            $claim = DB::transaction(function () use ($id) {
                $s = TransmissionSchedule::whereKey($id)->lockForUpdate()->first();
                if (! $s || $s->status !== 'active' || ! $s->next_run_at || $s->next_run_at->isFuture()) {
                    return null;
                }
                $due = CarbonImmutable::instance($s->next_run_at);
                $key = self::weekKey($due);
                if ($s->kind === 'weekly') {
                    [$next, $k] = self::nextWeeklyAfter($s, CarbonImmutable::now()->max($due), (int) $s->runs_count + 1);
                    if ($s->last_week_key === $key) {
                        $s->update(['next_run_at' => $next, 'runs_count' => $k]); // já houve envio nesta semana

                        return null;
                    }
                    $s->update(['next_run_at' => $next, 'runs_count' => $k, 'last_week_key' => $key, 'last_run_at' => now()]);
                } else {
                    $s->update(['next_run_at' => null, 'status' => 'done', 'runs_count' => 1, 'last_week_key' => $key, 'last_run_at' => now()]);
                }

                return $s->fresh();
            });
            if (! $claim) {
                continue;
            }
            $created[] = self::generate($claim, $contacts);
        }

        return array_filter($created);
    }

    private static function generate(TransmissionSchedule $s, ChatwootContacts $contacts): ?int
    {
        $byLabel = [];
        foreach ((array) $s->labels as $label) {
            $list = $contacts->forLabel((string) $label);
            if ($list === null) {
                return self::fail($s, $contacts->error ?: 'Falha ao carregar contatos.');
            }
            $byLabel[(string) $label] = $list;
        }
        $audience = TransmissionAudience::withoutSuppressed(TransmissionAudience::build($byLabel));
        if ($audience['unique'] === 0) {
            return self::fail($s, 'Nenhum contato com telefone válido nas etiquetas escolhidas.');
        }
        if ($s->media_path && ! Storage::disk('local')->exists($s->media_path)) {
            return self::fail($s, 'O anexo do agendamento não está mais disponível.');
        }
        $batch = TransmissionDispatcher::start($s->created_by, (string) $s->body, null, $audience, (array) $s->labels, $s->media(), $s->id);
        $s->update(['last_batch_id' => $batch->id, 'last_error' => null]);
        if ($s->kind === 'once') {
            self::releaseMedia($s);
        }

        return $batch->id;
    }

    /** Falha: registra o motivo e para (semanal fica pausado; nada é tentado de novo sozinho). */
    private static function fail(TransmissionSchedule $s, string $error): null
    {
        $s->update(['last_error' => mb_substr($error, 0, 500), 'status' => $s->kind === 'weekly' ? 'paused' : 'failed']);
        if ($s->kind === 'once') {
            self::releaseMedia($s);
        }

        return null;
    }
}
