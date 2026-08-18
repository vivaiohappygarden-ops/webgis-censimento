<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limite generale API per utente (le tile della mappa ne consumano molte)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });

        // Portale pubblico: il visitatore è anonimo e si conta per indirizzo,
        // ma una mappa a tutto schermo chiede decine di riquadri per ogni
        // spostamento e più persone possono condividere lo stesso indirizzo
        // (ufficio comunale, scuola, rete mobile). Il tetto è alto di
        // proposito: serve a fermare gli abusi, non la navigazione normale
        RateLimiter::for('portale', function (Request $request) {
            return Limit::perMinute(1200)->by($request->ip());
        });
    }
}
