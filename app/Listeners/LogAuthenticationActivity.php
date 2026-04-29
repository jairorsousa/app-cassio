<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->event('login')
            ->log('Login realizado');
    }

    public function handleFailed(Failed $event): void
    {
        $email = is_array($event->credentials) ? ($event->credentials['email'] ?? null) : null;

        activity('auth')
            ->withProperties([
                'email' => $email,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->event('login_failed')
            ->log('Tentativa de login falhou');
    }

    public function handleLockout(Lockout $event): void
    {
        activity('auth')
            ->withProperties([
                'ip' => $event->request->ip(),
                'user_agent' => $event->request->userAgent(),
            ])
            ->event('lockout')
            ->log('Login bloqueado por tentativas excessivas');
    }

    public function handleLogout(Logout $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'ip' => request()->ip(),
            ])
            ->event('logout')
            ->log('Logout realizado');
    }
}
