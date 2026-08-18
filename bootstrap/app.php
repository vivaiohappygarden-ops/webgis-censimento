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
        // Portale pubblico dei committenti: rotte anonime, fuori dal gruppo
        // "web" perché non devono avviare sessione né lasciare cookie
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('portale')
                ->group(base_path('routes/portale.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->statefulApi();
        // La radice smista per permesso (mappa, portale o guida)
        $middleware->redirectUsersTo('/');
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
