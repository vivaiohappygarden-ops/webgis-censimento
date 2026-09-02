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
        /*
         * Limite generale delle API, contato per utente.
         *
         * Aprire una pagina del gestionale costa più di una richiesta: elenco,
         * committenti, aree, cataloghi partono insieme, e chi lavora tiene
         * aperte più schede del browser sullo stesso accesso. Il tetto deve
         * fermare gli abusi, non il lavoro normale: quando lo superava, la
         * pagina restava vuota e l'unico rimedio era ricaricarla a mano.
         *
         * I riquadri della mappa hanno un conto a parte (limite "tiles"):
         * spostarsi sulla mappa non deve consumare il credito delle altre
         * pagine.
         */
        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/v1/tiles/*')) {
                return Limit::none();
            }

            return Limit::perMinute(600)->by($request->user()?->id ?: $request->ip());
        });

        // Riquadri della mappa: una schermata ne chiede una ventina e ogni
        // spostamento ne chiede altri. Stesso ragionamento del portale
        // pubblico, applicato a chi lavora
        RateLimiter::for('tiles', function (Request $request) {
            return Limit::perMinute(1200)->by($request->user()?->id ?: $request->ip());
        });

        // Portale pubblico: il visitatore è anonimo e si conta per indirizzo,
        // ma una mappa a tutto schermo chiede decine di riquadri per ogni
        // spostamento e più persone possono condividere lo stesso indirizzo
        // (ufficio comunale, scuola, rete mobile). Il tetto è alto di
        // proposito: serve a fermare gli abusi, non la navigazione normale
        RateLimiter::for('portale', function (Request $request) {
            return Limit::perMinute(1200)->by($request->ip());
        });

        // Feed del calendario da abbonamento: un lettore di calendari
        // interroga poche volte al giorno, ma un ufficio intero può uscire
        // dallo stesso indirizzo. Il tetto serve a scoraggiare i tentativi
        // a raffica di gettoni, non a limitare gli abbonati legittimi
        RateLimiter::for('calendario', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
