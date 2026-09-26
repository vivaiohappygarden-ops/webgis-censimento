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

    // Oggi: nella veste nuova e' la pagina di casa di chiunque veda il
    // censimento (mostra solo le sezioni che l'utente puo' vedere); in quella
    // precedente resta il cruscotto dei lavori, per chi li vede
    Route::get('/oggi', function () {
        $utente = \Illuminate\Support\Facades\Auth::user();
        if (\App\Support\Interfaccia::nuova($utente)) {
            abort_unless($utente->can('assets.view') || $utente->can('works.view'), 403);

            return Inertia::render('Nuovo/Oggi');
        }
        abort_unless($utente->can('works.view'), 403);

        return Inertia::render('Oggi');
    })->name('oggi');

    // La scelta fra la nuova interfaccia e la precedente, per il proprio utente
    Route::post('/interfaccia', [\App\Http\Controllers\Web\InterfacciaController::class, 'scegli'])->name('interfaccia');

    Route::get('/mappa', fn () => Inertia::render('Mappa'))
        ->middleware('can:assets.view')->name('mappa');

    Route::get('/censimento', fn () => Inertia::render('Censimento/Index'))
        ->middleware('can:assets.view')->name('censimento');

    // Veste nuova: l'elenco del patrimonio con l'anteprima (blocco 2). Le altre
    // schede di Patrimonio (mappa, alberi e VTA, irrigazione) sono ancora le
    // pagine di prima, con la stessa testata
    Route::get('/patrimonio', fn () => Inertia::render('Nuovo/Patrimonio'))
        ->middleware('can:assets.view')->name('patrimonio');

    // La scheda dell'elemento: nella veste nuova (blocco 3) si legge prima di
    // modificarla, per sezioni; ?precedente=1 apre comunque quella di prima
    Route::get('/censimento/{asset}', function (string $asset) {
        $nuova = \App\Support\Interfaccia::nuova(\Illuminate\Support\Facades\Auth::user()) && ! request()->boolean('precedente');

        return Inertia::render($nuova ? 'Nuovo/Scheda' : 'Censimento/Show', [
            'assetId' => $asset,
            'navigazioneUrl' => config('portal.navigation_url'),
        ]);
    })->whereUuid('asset')->middleware('can:assets.view')->name('censimento.show');

    Route::get('/vta', fn () => Inertia::render('Vta'))
        ->middleware('can:assets.view')->name('vta');

    // Il modello di collegamento per "Naviga" è lo stesso del portale
    // pubblico ("Raggiungi l'elemento"): un solo posto dove si decide quale
    // app di mappe si apre (config/portal.php, PORTAL_NAVIGATION_URL)
    Route::get('/operatore', fn () => Inertia::render('Operatore', [
        'urlNavigazione' => config('portal.navigation_url'),
        // Lo stato vegetativo si sceglie dal dizionario del gestionale, non
        // si scrive a mano: la pagina lo porta con se' (e resta nella cache
        // del service worker, quindi vale anche senza rete)
        'statiVegetativi' => config('agronomia.stato_vegetativo'),
    ]))->middleware('can:assets.create')->name('operatore');

    // Lavori: nella veste nuova (blocco 4) l'elenco con l'anteprima e le schede
    // Agenda/Gantt; ?precedente=1 apre la pagina di prima. La pagina
    // dell'ordine e' nuova e non ha un doppione nella veste precedente
    Route::get('/lavori', function () {
        $nuova = \App\Support\Interfaccia::nuova(\Illuminate\Support\Facades\Auth::user()) && ! request()->boolean('precedente');

        return Inertia::render($nuova ? 'Nuovo/Lavori' : 'Lavori');
    })->middleware('can:works.view')->name('lavori');

    Route::get('/lavori/{ordine}', fn (string $ordine) => Inertia::render('Nuovo/Ordine', ['ordineId' => $ordine]))
        ->whereUuid('ordine')->middleware('can:works.view')->name('lavori.ordine');

    // Veste nuova (blocchi 5, 6 e 7): le sezioni che riuniscono le pagine di prima
    Route::get('/documenti', function () {
        $utente = \Illuminate\Support\Facades\Auth::user();
        abort_unless($utente->can('assets.view') || $utente->can('works.view'), 403);

        return Inertia::render('Nuovo/Documenti');
    })->name('documenti');
    Route::get('/committenti', fn () => Inertia::render('Nuovo/Committenti'))
        ->middleware('can:clients.view')->name('committenti');
    Route::get('/impostazioni', fn () => Inertia::render('Nuovo/Impostazioni', [
        'dominioPortali' => (string) config('portal.base_host', ''),
    ]))->name('impostazioni');

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

    // Il portale del Comune: nella veste nuova (dal 26/09/2026) la pagina con
    // mappa, patrimonio, lavori, segnalazioni e documenti; ?precedente=1 apre
    // quella di prima. Gli sfondi della mappa sono quelli del portale
    // pubblico piu' quelli propri del Comune (ortofoto, carta tecnica)
    Route::get('/portale', function () {
        $utente = \Illuminate\Support\Facades\Auth::user();
        $nuova = \App\Support\Interfaccia::nuova($utente) && ! request()->boolean('precedente');
        $committente = $utente->client_id ? \App\Models\Client::query()->find($utente->client_id) : null;

        return Inertia::render($nuova ? 'Nuovo/Portale' : 'Portale', [
            'sfondi' => array_merge(
                \App\Services\Portale\PortalExtent::sfondi(),
                \App\Services\Carto\SfondiCommittente::perMappa($committente),
            ),
            'navigazioneUrl' => config('portal.navigation_url'),
        ]);
    })->middleware('can:portal.view')->name('portale');
    // Il portale dell'impresa appaltatrice: i lavori affidati alle sue squadre
    Route::get('/impresa', fn () => Inertia::render('Impresa'))
        ->middleware('can:impresa.view')->name('impresa');
});
