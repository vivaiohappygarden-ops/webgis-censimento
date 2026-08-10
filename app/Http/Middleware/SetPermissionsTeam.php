<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Imposta il "team" spatie/permission (= tenant) dell'utente autenticato,
 * così i controlli di ruolo/permesso sono confinati alla sua organizzazione.
 */
class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        }

        return $next($request);
    }
}
