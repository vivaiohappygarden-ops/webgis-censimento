<?php

use App\Http\Controllers\Portale\ElementoController;
use App\Http\Controllers\Portale\HomeController;
use App\Http\Controllers\Portale\MappaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portale pubblico del committente
|--------------------------------------------------------------------------
|
| Nessun accesso richiesto. Le stesse pagine sono raggiungibili in due modi:
|   - dal sottodominio del Comune  (mentana.<PORTAL_BASE_HOST>)
|   - dal percorso di ripiego      (/comune/mentana)
| Il secondo serve in sviluppo e per collaudare un Comune prima che il suo
| record DNS sia pubblicato. Le rotte sono definite una volta sola e
| registrate su entrambi gli indirizzi.
|
| Il gruppo di middleware "portale" non avvia la sessione: un sito civico non
| deve depositare cookie sul dispositivo del cittadino.
|
*/

$rotte = function (): void {
    Route::get('/', [HomeController::class, 'home'])->name('home');
    Route::get('/cerca', [HomeController::class, 'cerca'])->name('cerca');
    Route::get('/stemma', [HomeController::class, 'logo'])->name('stemma');
    Route::get('/privacy', [HomeController::class, 'informativa'])->name('privacy');

    Route::get('/elemento/{codice}', [ElementoController::class, 'mostra'])->name('elemento');
    Route::get('/elemento/{codice}/foto', [ElementoController::class, 'foto'])->name('elemento.foto');
    Route::get('/elemento/{codice}/foto/{foto}', [ElementoController::class, 'fotoEvento'])
        ->whereUuid('foto')->name('elemento.foto.evento');

    Route::get('/vincolo/{vincolo}/documento', [ElementoController::class, 'documentoVincolo'])
        ->whereUuid('vincolo')->name('vincolo.documento');

    Route::get('/mappa', [MappaController::class, 'mappa'])->name('mappa');
    Route::get('/mappa/{z}/{x}/{y}.pbf', [MappaController::class, 'tile'])
        ->whereNumber(['z', 'x', 'y'])->name('mappa.tile');
};

if ($base = config('portal.base_host')) {
    /*
     * Il gestionale puo' vivere sullo stesso dominio dei portali, per esempio
     * gestionale.censimentoalberature.it con i Comuni su
     * <comune>.censimentoalberature.it. In quel caso il suo nome combacia con
     * {comune}: senza questa esclusione le rotte pubbliche gli passerebbero
     * davanti e l'intero gestionale sparirebbe dietro un "non trovato",
     * perche' nessun committente si chiama come lui.
     */
    Route::domain('{comune}.'.$base)
        ->where(['comune' => App\Support\PortalLabels::vincoloSottodominio($base)])
        ->name('portale.')->group($rotte);
}

if (config('portal.path_fallback')) {
    Route::prefix('comune/{comune}')->name('portale.percorso.')->group($rotte);
}
