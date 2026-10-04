<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Funzioni;
use App\Support\Interfaccia;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $organizzazione = $user ? Organization::find($user->tenant_id) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // Il sistema metrico serve alla mappa per le coordinate piane e le misure
                    'organization' => $organizzazione?->only(['name', 'slug', 'metric_srid']),
                    'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                    // Chi gestisce la piattaforma vede la console nel menu
                    'piattaforma' => (bool) $user->is_platform_manager,
                    // Le zone dell'utente (vuoto = sede centrale): il menu le dice
                    'zone' => \App\Support\PerimetroZone::zone($user),
                ] : null,
            ],
            // L'accesso di assistenza in corso: il layout mostra la fascia
            // con l'organizzazione e il pulsante per terminarlo
            'assistenza' => fn () => ($a = $request->session()->get('assistenza')) && is_array($a)
                ? ['organizzazione' => $a['organizzazione'] ?? '', 'inizio' => $a['inizio'] ?? null]
                : null,
            // La veste del gestionale scelta dall'utente (o quella predefinita):
            // il layout monta il menu nuovo o quello precedente in base a questa
            'interfaccia' => [
                'modo' => Interfaccia::per($user),
                'predefinita' => Interfaccia::predefinita(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Dominio dei portali pubblici: serve alla pagina Territorio per
            // mostrare l'indirizzo vero di un Comune, o per dire che manca
            'portale' => [
                'base_host' => config('portal.base_host'),
            ],
            // Le funzioni accese per l'organizzazione dalla console della piattaforma
            // (es. il gestionale giardini): le pagine non mostrano i comandi di quelle spente
            'funzioni' => Funzioni::per($organizzazione),
            // Dizionari agronomici della scheda albero: le tendine leggono
            // le stesse voci che il server accetta, senza copie nel JS
            'agronomia' => config('agronomia'),
        ];
    }
}
