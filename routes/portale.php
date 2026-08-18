<?php

use App\Http\Controllers\Portale\HomeController;
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
    Route::get('/stemma', [HomeController::class, 'logo'])->name('stemma');
};

if ($base = config('portal.base_host')) {
    Route::domain('{comune}.'.$base)->name('portale.')->group($rotte);
}

if (config('portal.path_fallback')) {
    Route::prefix('comune/{comune}')->name('portale.percorso.')->group($rotte);
}
