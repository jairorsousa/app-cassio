<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Grava o log de auditoria. Nunca interrompe a operação do usuário se falhar. */
class AuditLogger
{
    /** Campos nunca gravados no log (aparecem como "(oculto)"). */
    private const HIDDEN = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'token_hash', 'secret', 'api_key', 'access_token', 'refresh_token', 'smtp_password', 'google_sub'];

    /** Modelos de alto volume e sem valor de auditoria (cotações, filas, o próprio log). */
    private const SKIP = '/(AuditLog|Snapshot|Quote|PriceCache|Session|Job|BroadcastRecipient|BroadcastDelivery|Notification)$/';

    private static ?bool $ready = null;

    private static bool $disabled = false;

    public static function withoutLogging(callable $fn): mixed
    {
        $prev = self::$disabled;
        self::$disabled = true;
        try {
            return $fn();
        } finally {
            self::$disabled = $prev;
        }
    }

    public static function shouldLog(Model $model): bool
    {
        if (self::$disabled || preg_match(self::SKIP, class_basename($model))) {
            return false;
        }
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('audit_logs');
            } catch (Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    public static function resetCache(): void
    {
        self::$ready = null;
    }

    private static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, ['updated_at', 'created_at'], true)) {
                continue;
            }
            foreach (self::HIDDEN as $h) {
                if ($k === $h || str_contains((string) $k, 'password') || str_contains((string) $k, 'secret')) {
                    $v = '(oculto)';
                    break;
                }
            }
            if (is_string($v) && mb_strlen($v) > 500) {
                $v = mb_substr($v, 0, 500).'…';
            }
            $out[$k] = $v;
        }

        return $out;
    }

    public static function model(string $action, Model $model): void
    {
        if (! self::shouldLog($model)) {
            return;
        }
        try {
            $before = null;
            $after = null;
            if ($action === 'created') {
                $after = self::clean($model->getAttributes());
            } elseif ($action === 'updated') {
                $changes = self::clean($model->getChanges());
                if ($changes === []) {
                    return;
                }
                $after = $changes;
                $before = self::clean(array_intersect_key($model->getRawOriginal(), $changes));
            } elseif ($action === 'deleted') {
                $before = self::clean($model->getAttributes());
            }
            $label = null;
            foreach (['name', 'description', 'title', 'process_number', 'email'] as $f) {
                if (! empty($model->getAttribute($f)) && is_scalar($model->getAttribute($f))) {
                    $label = mb_substr((string) $model->getAttribute($f), 0, 200);
                    break;
                }
            }
            self::write($action, class_basename($model), is_numeric($model->getKey()) ? (int) $model->getKey() : null, $label, $before, $after);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function write(string $action, ?string $entityType, ?int $entityId, ?string $label, ?array $before, ?array $after, $user = null): void
    {
        $user ??= auth()->user();
        $req = app()->runningInConsole() ? null : request();
        self::withoutLogging(fn () => AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? (app()->runningInConsole() ? 'Sistema (comando)' : null),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $label,
            'before' => $before,
            'after' => $after,
            'ip' => $req?->ip(),
            'url' => $req ? mb_substr($req->fullUrl(), 0, 255) : null,
        ]));
    }
}
