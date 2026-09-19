<?php

use App\Http\Controllers\Sito\SitoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sito aziendale
|--------------------------------------------------------------------------
|
| Il dominio nudo (senza prefisso) e' il sito che parla ai Comuni; i portali
| civici stanno sui sottodomini e il gestionale sul suo. Le stesse pagine si
| raggiungono in due modi:
|   - dal dominio di produzione (config sito.base_host); il suo "www" rinvia
|     al dominio nudo, cosi' c'e' una sola versione da indicizzare
|   - dal percorso di collaudo /sito, sempre noindex
| Le rotte si dichiarano una volta e si registrano su tutti e due.
|
| Finche' il dominio non e' configurato le rotte per dominio non esistono:
| il sito resta visibile solo su /sito e la radice del dominio non pubblica
| niente. E' voluto: e' la leva con cui si decide quando aprirlo.
|
| Nessuna sessione e nessun cookie (gruppo di middleware "sito"): e' quello
| che permette di dichiarare in pie' di pagina che non c'e' niente da
| accettare.
|
*/

$rotte = function (): void {
    Route::get('/', [SitoController::class, 'pagina'])->defaults('quale', 'home')->name('home');

    foreach (array_keys(SitoController::PAGINE) as $pagina) {
        if ($pagina !== 'home') {
            Route::get('/'.$pagina, [SitoController::class, 'pagina'])->defaults('quale', $pagina)->name($pagina);
        }
    }

    // Gli indirizzi della prima versione del sito rinviano ai nuovi
    foreach (array_keys(SitoController::RINOMINATE) as $vecchia) {
        Route::get('/'.$vecchia, [SitoController::class, 'rinominata'])->defaults('vecchia', $vecchia)->name('vecchia.'.$vecchia);
    }
};

if ($base = config('sito.base_host')) {
    Route::domain($base)->name('sito.')->group(function () use ($rotte): void {
        $rotte();
        Route::get('/sitemap.xml', [SitoController::class, 'sitemap'])->name('sitemap');
    });

    Route::domain('www.'.$base)->name('sito.www.')
        ->get('/{percorso?}', [SitoController::class, 'senzaWww'])
        ->where('percorso', '.*')->name('rinvio');
}

if (config('sito.path_fallback')) {
    Route::prefix('sito')->name('sito.percorso.')->group($rotte);
}

// robots.txt e' unico per tutti i nomi serviti (ha preso il posto del file
// statico in public/): sul dominio del sito dichiara la mappa, altrove non
// vieta niente, come prima
Route::get('/robots.txt', [SitoController::class, 'robots'])->name('robots');
