<?php

use App\Http\Controllers\Web\WebAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('mappa'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'show'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:10,1');
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

    Route::get('/territorio', fn () => Inertia::render('Territorio'))
        ->middleware('can:clients.view')->name('territorio');

    Route::get('/catalogo', fn () => Inertia::render('Catalogo'))
        ->middleware('can:catalog.view')->name('catalogo');
});
