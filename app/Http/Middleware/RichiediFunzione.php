<?php

namespace App\Http\Middleware;

use App\Support\Funzioni;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chiude le chiamate di una funzione spenta per l'organizzazione di chi chiede
 * (es. il gestionale giardini): la pagina non mostra i comandi, ma e' il
 * server a dire di no.
 */
class RichiediFunzione
{
    public function handle(Request $request, Closure $next, string $funzione): Response
    {
        $utente = $request->user();
        if (! $utente || ! Funzioni::attiva($utente->tenant_id, $funzione)) {
            abort(403, 'Questa funzione non è attiva per la tua organizzazione.');
        }

        return $next($request);
    }
}
