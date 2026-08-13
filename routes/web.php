<?php

use App\Http\Controllers\Web\WebAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// La radice manda ognuno dove può lavorare: il cliente non ha la mappa
Route::get('/', function () {
    $user = \Illuminate\Support\Facades\Auth::user();

    return redirect()->route($user ? \App\Support\HomeRoute::for($user) : 'login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'show'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:10,1');
});

// Pagina pubblica dell'elemento (QR sul cartellino): nessun accesso richiesto.
// Ogni visita consuma due richieste (pagina + foto) e il pubblico mobile
// condivide pochi IP di operatore: il tetto tiene conto di entrambe le cose
Route::middleware('throttle:240,1')->group(function () {
    Route::get('/p/{token}', [\App\Http\Controllers\Web\PublicTreeController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{16,64}')->name('public.tree');
    Route::get('/p/{token}/foto', [\App\Http\Controllers\Web\PublicTreeController::class, 'photo'])
        ->where('token', '[A-Za-z0-9]{16,64}')->name('public.tree.photo');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');

    Route::get('/mappa', fn () => Inertia::render('Mappa'))
        ->middleware('can:assets.view')->name('mappa');

    Route::get('/censimento', fn () => Inertia::render('Censimento/Index'))
        ->middleware('can:assets.view')->name('censimento');

    Route::get('/censimento/{asset}', fn (string $asset) => Inertia::render('Censimento/Show', ['assetId' => $asset]))
        ->whereUuid('asset')->middleware('can:assets.view')->name('censimento.show');

    Route::get('/vta', fn () => Inertia::render('Vta'))
        ->middleware('can:assets.view')->name('vta');

    Route::get('/operatore', fn () => Inertia::render('Operatore'))
        ->middleware('can:assets.create')->name('operatore');

    Route::get('/lavori', fn () => Inertia::render('Lavori'))
        ->middleware('can:works.view')->name('lavori');

    Route::get('/listini', fn () => Inertia::render('Listini'))
        ->middleware('can:works.view')->name('listini');

    Route::get('/segnalazioni', fn () => Inertia::render('Segnalazioni'))
        ->middleware('can:works.view')->name('segnalazioni');

    Route::get('/ispezioni', fn () => Inertia::render('Ispezioni'))
        ->middleware('can:works.view')->name('ispezioni');

    Route::get('/territorio', fn () => Inertia::render('Territorio'))
        ->middleware('can:clients.view')->name('territorio');

    Route::get('/irrigazione', fn () => Inertia::render('Irrigazione'))
        ->middleware('can:areas.view')->name('irrigazione');

    Route::get('/catalogo', fn () => Inertia::render('Catalogo'))
        ->middleware('can:catalog.view')->name('catalogo');

    // La guida è per tutti gli utenti autenticati, senza permessi dedicati
    Route::get('/guida', fn () => Inertia::render('Guida'))->name('guida');

    Route::get('/portale', fn () => Inertia::render('Portale'))
        ->middleware('can:portal.view')->name('portale');
});
