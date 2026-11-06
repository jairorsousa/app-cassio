<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Conta bloqueada sai na hora; rota de módulo não liberado = 403 (não basta esconder o menu). */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(403, 'Acesso bloqueado.');
            }

            return redirect()->route('login')->with('status', 'Seu acesso foi bloqueado. Fale com o administrador.');
        }

        $modules = Access::forRoute($request->route()?->getName());
        if ($modules !== null && $user && ! Access::allows($user, $modules)) {
            if ($request->isMethod('GET') && $request->route()?->getName() === 'dashboard') {
                return redirect()->route(Access::homeRoute($user));
            }
            abort(403, 'Você não tem acesso a este módulo.');
        }

        return $next($request);
    }
}
