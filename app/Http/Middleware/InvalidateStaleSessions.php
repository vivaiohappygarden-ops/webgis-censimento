<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Al cambio password le sessioni web aperte con quella vecchia decadono:
 * l'hash della password vive in sessione e viene confrontato a ogni
 * richiesta (come AuthenticateSession del framework, ma ancorato al guard
 * web: il guard predefinito può essere un altro). Alla decadenza la
 * richiesta prosegue da ospite: le pagine protette rimandano al login da
 * sole, quelle pubbliche restano visibili.
 *
 * La chiave di sessione è la stessa che usa Sanctum sulle richieste API
 * stateful (password_hash_web) e nello stesso formato HMAC di
 * SessionGuard::hashPasswordForCookie: i due controlli devono concordare,
 * o si butterebbero fuori a vicenda.
 */
class InvalidateStaleSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $request->hasSession() ? $guard->user() : null;

        if ($user) {
            $current = (string) $user->getAuthPassword();
            $hmac = method_exists($guard, 'hashPasswordForCookie')
                ? $guard->hashPasswordForCookie($current)
                : null;

            $stored = $request->session()->get('password_hash_web');
            $stillValid = $stored === null
                || ($hmac !== null && hash_equals($hmac, (string) $stored))
                || hash_equals($current, (string) $stored);

            if (! $stillValid) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $next($request);
            }

            $request->session()->put('password_hash_web', $hmac ?? $current);
        }

        return $next($request);
    }
}
