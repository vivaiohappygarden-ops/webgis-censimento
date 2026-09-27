<?php

namespace App\Http\Middleware;

use App\Services\Sicurezza\DueFattori;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chi e' obbligato dalla regola della sua organizzazione ad avere la
 * verifica in due passaggi, e non l'ha ancora accesa, trova aperta solo la
 * pagina del proprio accesso (dove la attiva) e l'uscita: tutto il resto
 * rimanda li'. Le chiamate API rispondono 403 con il motivo, tranne quelle
 * che servono a quella pagina.
 */
class RichiediDueFattori
{
    private const APERTE_WEB = ['sicurezza', 'logout', 'interfaccia', 'login', 'login/*'];

    private const APERTE_API = ['api/v1/profilo/*', 'api/v1/sicurezza/*', 'api/v1/auth/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? ($request->bearerToken() ? $request->user('sanctum') : null);
        if (! $user || $user->mfa_enabled || ! DueFattori::obbligatoriaPer($user)) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            if ($request->is(...self::APERTE_API)) {
                return $next($request);
            }
            abort(403, 'La tua organizzazione richiede la verifica in due passaggi: attivala dalla pagina "Il mio accesso" del gestionale.');
        }

        if ($request->is(...self::APERTE_WEB)) {
            return $next($request);
        }

        return redirect()->route('sicurezza', ['obbligatoria' => 1]);
    }
}
