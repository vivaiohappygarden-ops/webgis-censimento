<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un utente disattivato non deve continuare a lavorare con una sessione o
 * un token aperti prima della disattivazione: il login già lo esclude,
 * qui si chiude anche ciò che era rimasto in piedi.
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
        if ($user && ! $user->is_active) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, 'Utente disattivato: contattare l\'amministratore.');
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
