<?php

namespace App\Http\Middleware;

use App\Support\RolePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Somente leitura: nenhuma requisição que grave fora do Livewire (que tem verificação própria por ação). */
class EnforceReadOnlyRole
{
    private const ALLOWED_ROUTES = ['logout', 'livewire.update', 'livewire.upload-file'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && RolePolicy::isReadOnly($request->user())
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)
            && ! $request->is('livewire/*')) {
            abort(403, 'Seu perfil é Somente leitura: esta ação não é permitida.');
        }

        return $next($request);
    }
}
