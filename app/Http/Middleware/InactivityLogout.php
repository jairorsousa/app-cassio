<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class InactivityLogout
{
    private const TIMEOUT_MINUTES = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $last = $request->session()->get('last_activity');
            $now = now()->getTimestamp();

            if ($last !== null && ($now - $last) > self::TIMEOUT_MINUTES * 60) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', __('Sessão expirada por inatividade.'));
            }

            $request->session()->put('last_activity', $now);
        }

        return $next($request);
    }
}
