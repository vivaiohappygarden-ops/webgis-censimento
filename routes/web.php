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

    Route::get('/catalogo', fn () => Inertia::render('Catalogo'))
        ->middleware('can:catalog.view')->name('catalogo');
});
