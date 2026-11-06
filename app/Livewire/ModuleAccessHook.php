<?php

namespace App\Livewire;

use App\Support\Access;
use Illuminate\Support\Facades\Auth;
use Livewire\ComponentHook;

/** Mesmo controle das rotas, aplicado a todo componente Livewire (montagem e cada atualização). */
class ModuleAccessHook extends ComponentHook
{
    public function boot(): void
    {
        $user = auth()->user();
        // Conta bloqueada: desconecta e nega, inclusive em componentes livres (perfil, configurações).
        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request = request();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'Acesso bloqueado.');
        }

        $modules = Access::forComponent($this->component->getName());
        if ($modules !== null && ! Access::allows($user, $modules)) {
            abort(403, 'Você não tem acesso a este módulo.');
        }
    }
}
