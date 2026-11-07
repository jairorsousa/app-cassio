<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Convidado (não admin) precisa completar o cadastro antes de usar o sistema. Sair continua liberado. */
class EnsureProfileCompleted
{
    private const ALLOWED_ROUTES = ['profile.complete', 'logout', 'login', 'invite.accept'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isAdmin() || $user->profile_completed_at !== null) {
            return $next($request);
        }
        $name = $request->route()?->getName();
        if (in_array($name, self::ALLOWED_ROUTES, true) || $request->is('livewire/*') || $request->is('livewire-*/*')) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            abort(409, 'Complete seu cadastro.');
        }

        return redirect()->route('profile.complete');
    }
}
