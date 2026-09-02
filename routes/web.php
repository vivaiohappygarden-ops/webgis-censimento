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
    // La porta d'ingresso delle imprese appaltatrici: stessa serratura
    // (il POST resta /login), parole pensate per la ditta
    Route::get('/impresa/login', [WebAuthController::class, 'showImpresa'])->name('impresa.login');
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

/*
 * Verifica dei nomi a dominio per il rilascio automatico del certificato.
 * Caddy interroga questo indirizzo prima di chiedere un certificato per un
 * sottodominio: senza, chiunque potrebbe far emettere certificati puntando
 * un proprio nome sul nostro server.
 */
Route::get('/interno/tls', function (\Illuminate\Http\Request $request) {
    $host = strtolower(trim((string) $request->query('domain')));
    $base = strtolower((string) config('portal.base_host'));
    $principale = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    if ($host !== '' && $host === $principale) {
        return response('', 200);
    }

    if ($host === '' || $base === '' || ! str_ends_with($host, '.'.$base)) {
        return response('', 404);
    }

    $slug = substr($host, 0, -strlen('.'.$base));

    $esiste = \App\Models\Client::query()->withoutGlobalScopes()
        ->whereNull('deleted_at')
        ->where('public_slug', $slug)
        ->where('public_enabled', true)
        ->where('is_active', true)
        ->whereIn('tenant_id', \App\Models\Organization::query()->withoutGlobalScopes()
            ->where('is_active', true)->whereNull('deleted_at')->select('id'))
        ->exists();

    return response('', $esiste ? 200 : 404);
})->middleware('throttle:120,1')->name('interno.tls');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');

    Route::get('/oggi', fn () => Inertia::render('Oggi'))
        ->middleware('can:works.view')->name('oggi');

    Route::get('/mappa', fn () => Inertia::render('Mappa'))
        ->middleware('can:assets.view')->name('mappa');

    Route::get('/censimento', fn () => Inertia::render('Censimento/Index'))
        ->middleware('can:assets.view')->name('censimento');

    Route::get('/censimento/{asset}', fn (string $asset) => Inertia::render('Censimento/Show', ['assetId' => $asset]))
        ->whereUuid('asset')->middleware('can:assets.view')->name('censimento.show');

    Route::get('/vta', fn () => Inertia::render('Vta'))
        ->middleware('can:assets.view')->name('vta');

    // Il modello di collegamento per "Naviga" è lo stesso del portale
    // pubblico ("Raggiungi l'elemento"): un solo posto dove si decide quale
    // app di mappe si apre (config/portal.php, PORTAL_NAVIGATION_URL)
    Route::get('/operatore', fn () => Inertia::render('Operatore', [
        'urlNavigazione' => config('portal.navigation_url'),
    ]))->middleware('can:assets.create')->name('operatore');

    Route::get('/lavori', fn () => Inertia::render('Lavori'))
        ->middleware('can:works.view')->name('lavori');

    Route::get('/listini', fn () => Inertia::render('Listini'))
        ->middleware('can:works.view')->name('listini');

    Route::get('/segnalazioni', fn () => Inertia::render('Segnalazioni'))
        ->middleware('can:works.view')->name('segnalazioni');

    Route::get('/ispezioni', fn () => Inertia::render('Ispezioni'))
        ->middleware('can:works.view')->name('ispezioni');

    Route::get('/fitosanitari', fn () => Inertia::render('Fitosanitari'))
        ->middleware('can:works.view')->name('fitosanitari');

    Route::get('/patentini', fn () => Inertia::render('Patentini'))
        ->middleware('can:works.view')->name('patentini');

    Route::get('/statistiche', fn () => Inertia::render('Statistiche'))
        ->middleware('can:works.view')->name('statistiche');

    Route::get('/territorio', fn () => Inertia::render('Territorio'))
        ->middleware('can:clients.view')->name('territorio');

    Route::get('/irrigazione', fn () => Inertia::render('Irrigazione'))
        ->middleware('can:areas.view')->name('irrigazione');

    Route::get('/catalogo', fn () => Inertia::render('Catalogo'))
        ->middleware('can:catalog.view')->name('catalogo');

    Route::get('/utenti', fn () => Inertia::render('Utenti'))
        ->middleware('can:users.manage')->name('utenti');

    // La guida è per tutti gli utenti autenticati, senza permessi dedicati
    Route::get('/guida', fn () => Inertia::render('Guida'))->name('guida');

    Route::get('/portale', fn () => Inertia::render('Portale'))
        ->middleware('can:portal.view')->name('portale');
    // Il portale dell'impresa appaltatrice: i lavori affidati alle sue squadre
    Route::get('/impresa', fn () => Inertia::render('Impresa'))
        ->middleware('can:impresa.view')->name('impresa');
});
