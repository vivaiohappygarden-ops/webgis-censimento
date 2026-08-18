<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Organization;
use App\Support\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Riconosce di quale committente è il portale pubblico che si sta aprendo.
 *
 * Il visitatore è anonimo, quindi TenantScope non filtra nulla (si disattiva
 * senza utente autenticato): ogni query del portale deve filtrare a mano sul
 * committente risolto qui. È la regola di sicurezza principale di tutta
 * l'area pubblica.
 */
class ResolvePublicPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('comune');

        $client = Client::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('public_slug', $slug)
            ->where('public_enabled', true)
            ->where('is_active', true)
            // A organizzazione disattivata (contratto cessato) il portale si
            // spegne da solo, come già fa la pagina del QR
            ->whereIn('tenant_id', Organization::query()->withoutGlobalScopes()
                ->where('is_active', true)->whereNull('deleted_at')->select('id'))
            ->first();

        abort_if($client === null, 404);

        // Percorso di ripiego /comune/{slug}: i collegamenti interni devono
        // conservarlo, sul sottodominio invece la radice è il portale stesso
        $suPercorso = str_starts_with((string) $request->route()?->uri(), 'comune/');
        $context = new PortalContext($client, $suPercorso ? '/comune/'.$slug : '');

        app()->instance(PortalContext::class, $context);
        view()->share('portale', $context);

        return $next($request);
    }
}
