<?php

use App\Http\Controllers\Sito\SitoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sito aziendale
|--------------------------------------------------------------------------
|
| Il dominio nudo (censimentoalberature.it, senza prefisso) e' il sito che
| parla ai Comuni; i portali civici stanno sui sottodomini e il gestionale
| sul suo. Le stesse pagine si raggiungono in due modi:
|   - dal dominio nudo e dal suo "www"
|   - dal percorso di collaudo /sito
| Il secondo serve in sviluppo e per guardare il sito prima di pubblicare il
| record DNS. Le rotte si dichiarano una volta e si registrano su entrambi.
|
| Finche' SITO_BASE_HOST non e' impostato le rotte per dominio non esistono:
| il sito resta visibile solo su /sito. E' voluto - un sito aziendale senza
| recapiti e senza ragione sociale non va pubblicato - e diventa la leva con
| cui si decide quando aprirlo al pubblico.
|
| Nessuna sessione e nessun cookie: come per i portali, e' quello che
| permette di dichiarare in pie' di pagina che non c'e' niente da accettare.
|
*/

$rotte = function (): void {
    Route::get('/', [SitoController::class, 'pagina'])->defaults('quale', 'home')->name('home');

    foreach (['censimento', 'stabilita', 'portale', 'conformita', 'chi-siamo', 'contatti', 'privacy'] as $pagina) {
        Route::get('/'.$pagina, [SitoController::class, 'pagina'])->defaults('quale', $pagina)->name($pagina);
    }
};

if ($base = config('sito.base_host')) {
    Route::domain($base)->name('sito.')->group($rotte);
    Route::domain('www.'.$base)->name('sito.www.')->group($rotte);
}

if (config('sito.path_fallback')) {
    Route::prefix('sito')->name('sito.percorso.')->group($rotte);
}
