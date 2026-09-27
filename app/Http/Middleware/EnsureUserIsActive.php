<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\Piattaforma\ConsolePiattaforma;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un utente disattivato non deve continuare a lavorare con una sessione o
 * un token aperti prima della disattivazione: il login già lo esclude,
 * qui si chiude anche ciò che era rimasto in piedi. Lo stesso vale per
 * l'organizzazione sospesa dalla piattaforma e per l'accesso di assistenza
 * arrivato a scadenza.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // La pagina pubblica del QR è per chiunque: anche chi ha in tasca
        // una sessione di un utente disattivato deve poterla vedere
        if ($request->routeIs('public.tree', 'public.tree.photo')) {
            return $next($request);
        }

        $user = $request->user();
        $motivo = $user ? $this->motivoDiChiusura($user) : null;
        if ($motivo !== null) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, $motivo);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $motivo]);
        }

        return $next($request);
    }

    /**
     * Perche' questa sessione non puo' proseguire: utente disattivato,
     * organizzazione sospesa dalla piattaforma, accesso di assistenza
     * scaduto. Null se va tutto bene.
     */
    private function motivoDiChiusura($user): ?string
    {
        if (! $user->is_active) {
            return 'Utente disattivato: contattare l\'amministratore.';
        }
        if (ConsolePiattaforma::assistenzaScaduta($user)) {
            return 'L\'accesso di assistenza è terminato.';
        }
        if (! Organization::query()->whereKey($user->tenant_id)->where('is_active', true)->exists()) {
            return ConsolePiattaforma::MESSAGGIO_SOSPESA;
        }

        return null;
    }
}
