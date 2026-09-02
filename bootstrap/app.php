<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Portale pubblico dei committenti e feed del calendario: rotte
        // anonime, fuori dal gruppo "web" perché non devono avviare sessione
        // né lasciare cookie
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('portale')
                ->group(base_path('routes/portale.php'));
            \Illuminate\Support\Facades\Route::middleware('calendario')
                ->group(base_path('routes/calendario.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->statefulApi();
        // La radice smista per permesso (mappa, portale o guida)
        $middleware->redirectUsersTo('/');
        // Chi bussa al portale dell'impresa da ospite trova la SUA porta
        // d'ingresso, non quella del gestionale
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('impresa')
            ? route('impresa.login')
            : route('login'));
        $middleware->web(append: [
            \App\Http\Middleware\InvalidateStaleSessions::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\SetPermissionsTeam::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\SetPermissionsTeam::class,
        ]);
        // Gruppo del portale pubblico: niente sessione, niente cookie,
        // niente Inertia. Solo il riconoscimento del committente e il tetto
        // di richieste
        $middleware->group('portale', [
            'throttle:portale',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ResolvePublicPortal::class,
        ]);
        // Feed iCal: il gettone nell'indirizzo è l'unico riconoscimento,
        // resta solo il tetto di richieste (i lettori di calendari
        // interrogano poche volte al giorno; il limite ferma i tentativi
        // a raffica di gettoni)
        $middleware->group('calendario', [
            'throttle:calendario',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
