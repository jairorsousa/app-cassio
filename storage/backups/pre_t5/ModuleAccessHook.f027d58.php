<?php

namespace App\Livewire;

use App\Support\Access;
use Livewire\ComponentHook;

/** Mesmo controle das rotas, aplicado a todo componente Livewire (montagem e cada atualização). */
class ModuleAccessHook extends ComponentHook
{
    public function boot(): void
    {
        $modules = Access::forComponent($this->component->getName());
        if ($modules !== null && ! Access::allows(auth()->user(), $modules)) {
            abort(403, 'Você não tem acesso a este módulo.');
        }
    }
}
