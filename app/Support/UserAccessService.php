<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Regras de convite/usuários. Criar/regenerar não envia e-mail; envio explícito fica em InvitationMailService. */
class UserAccessService
{
    private static function admin(?User $actor): User
    {
        abort_unless($actor && $actor->isActive() && $actor->isAdmin(), 403);

        return $actor;
    }

    private static function modules(array $modules): array
    {
        $clean = Access::clean($modules);
        if ($clean === []) {
            throw ValidationException::withMessages(['modules' => 'Escolha pelo menos um módulo.']);
        }

        return $clean;
    }

    /** @return array{0: UserInvitation, 1: string} convite + token em texto (mostrado uma única vez) */
    public static function invite(?User $actor, string $name, string $email, array $modules): array
    {
        $actor = self::admin($actor);
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw ValidationException::withMessages(['inviteEmail' => 'Informe nome e e-mail válidos.']);
        }
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['inviteEmail' => 'Já existe um usuário com este e-mail.']);
        }
        $modules = self::modules($modules);

        return DB::transaction(function () use ($actor, $name, $email, $modules) {
            // Um convite válido por e-mail: os pendentes anteriores deixam de valer.
            UserInvitation::where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'token_encrypted' => null]);
            $token = Str::random(64);
            $inv = UserInvitation::create(['name' => $name, 'email' => $email, 'modules' => $modules, 'token_hash' => UserInvitation::hashToken($token), 'token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($token), 'expires_at' => now()->addDays(UserInvitation::VALID_DAYS), 'invited_by' => $actor->id]);

            return [$inv, $token];
        });
    }

    public static function regenerate(?User $actor, int $invitationId): string
    {
        self::admin($actor);

        return DB::transaction(function () use ($invitationId) {
            $inv = UserInvitation::whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($inv->accepted_at) {
                throw ValidationException::withMessages(['invite' => 'Convite já aceito.']);
            }
            if (User::whereRaw('lower(email) = ?', [$inv->email])->exists()) {
                throw ValidationException::withMessages(['invite' => 'Já existe um usuário com este e-mail.']);
            }
            $token = Str::random(64);
            $inv->update(['token_hash' => UserInvitation::hashToken($token), 'token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($token), 'expires_at' => now()->addDays(UserInvitation::VALID_DAYS), 'revoked_at' => null]);

            return $token;
        });
    }

    public static function revoke(?User $actor, int $invitationId): void
    {
        self::admin($actor);
        UserInvitation::whereKey($invitationId)->whereNull('accepted_at')->update(['revoked_at' => now(), 'token_encrypted' => null]);
    }

    public static function accept(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $inv = UserInvitation::where('token_hash', UserInvitation::hashToken($token))->lockForUpdate()->first();
            if (! $inv || $inv->status() !== 'pending') {
                throw ValidationException::withMessages(['password' => 'Este convite não é mais válido. Peça um novo ao administrador.']);
            }
            if (User::whereRaw('lower(email) = ?', [mb_strtolower($inv->email)])->exists()) {
                throw ValidationException::withMessages(['password' => 'Já existe uma conta com este e-mail. Peça ajuda ao administrador.']);
            }
            $user = new User;
            $user->forceFill([
                'name' => $inv->name, 'email' => $inv->email, 'password' => Hash::make($password), 'email_verified_at' => now(),
                'is_admin' => false, 'status' => 'active', 'modules' => Access::clean((array) $inv->modules),
            ])->save();
            $inv->update(['accepted_at' => now(), 'accepted_user_id' => $user->id, 'token_encrypted' => null]);

            return $user;
        });
    }

    private static function target(User $actor, int $userId): User
    {
        $u = User::findOrFail($userId);
        if ($u->id === $actor->id || $u->isAdmin()) {
            throw ValidationException::withMessages(['users' => 'O administrador não pode ser bloqueado nem ter os módulos alterados por aqui.']);
        }

        return $u;
    }

    public static function setModules(?User $actor, int $userId, array $modules): void
    {
        $actor = self::admin($actor);
        $u = self::target($actor, $userId);
        $u->forceFill(['modules' => self::modules($modules)])->save();
    }

    public static function block(?User $actor, int $userId): void
    {
        $actor = self::admin($actor);
        $u = self::target($actor, $userId);
        // "Lembrar de mim" deixa de valer; sessões abertas caem na próxima ação (middleware).
        $u->forceFill(['status' => 'blocked', 'blocked_at' => now(), 'remember_token' => Str::random(60)])->save();
    }

    public static function reactivate(?User $actor, int $userId): void
    {
        $actor = self::admin($actor);
        $u = self::target($actor, $userId);
        $u->forceFill(['status' => 'active', 'blocked_at' => null])->save(); // módulos liberados continuam os mesmos
    }
}
