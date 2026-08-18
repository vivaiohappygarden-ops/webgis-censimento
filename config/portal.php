<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dominio di base del portale pubblico
    |--------------------------------------------------------------------------
    |
    | Ogni committente che attiva il portale ha un proprio sottodominio
    | (es. "mentana" + "censimenti.example.it" -> mentana.censimenti.example.it).
    | Lasciando il valore vuoto le rotte per sottodominio non vengono
    | registrate e il portale resta raggiungibile solo dal percorso di
    | ripiego /comune/{slug}, che serve in sviluppo e finché il DNS del
    | Comune non è pronto.
    |
    */

    'base_host' => env('PORTAL_BASE_HOST'),

    /*
    | Percorso di ripiego /comune/{slug}: va tenuto attivo anche in
    | produzione, perché è l'indirizzo con cui si collauda un Comune prima
    | di pubblicare il record DNS.
    */

    'path_fallback' => (bool) env('PORTAL_PATH_FALLBACK', true),

    /*
    | Sottodomini che non possono essere assegnati a un committente perché
    | servono al servizio o sono comunemente usati dai browser.
    */

    /*
    |--------------------------------------------------------------------------
    | Sfondi cartografici della mappa pubblica
    |--------------------------------------------------------------------------
    |
    | Tre sfondi come nei portali civici di riferimento. Gli indirizzi sono
    | configurabili perché la scelta del fornitore è una decisione con
    | ricadute di licenza e di costo: le tessere standard di OpenStreetMap
    | sono a uso limitato e la loro politica d'uso esclude i siti pubblici ad
    | alto traffico, quindi prima della messa in linea vanno sostituite con
    | un fornitore dedicato. Uno sfondo senza indirizzo non compare nel
    | selettore.
    |
    */

    'basemaps' => [
        [
            'id' => 'stradale',
            'nome' => 'Stradale',
            'url' => env('PORTAL_TILES_STRADALE', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
            'attribuzione' => env('PORTAL_TILES_STRADALE_ATTR', '© OpenStreetMap contributors'),
            'scuro' => false,
        ],
        [
            'id' => 'satellite',
            'nome' => 'Satellite',
            'url' => env('PORTAL_TILES_SATELLITE',
                'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
            'attribuzione' => env('PORTAL_TILES_SATELLITE_ATTR', 'Immagini Esri, Maxar, Earthstar Geographics'),
            'scuro' => true,
        ],
        [
            'id' => 'scura',
            'nome' => 'Scura',
            'url' => env('PORTAL_TILES_SCURA', 'https://basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'),
            'attribuzione' => env('PORTAL_TILES_SCURA_ATTR', '© OpenStreetMap contributors © CARTO'),
            'scuro' => true,
        ],
    ],

    'reserved_slugs' => [
        'www', 'app', 'api', 'admin', 'mail', 'smtp', 'imap', 'pop', 'ftp',
        'ns', 'ns1', 'ns2', 'mx', 'cdn', 'static', 'assets', 'storage',
        'test', 'staging', 'dev', 'demo', 'portale', 'comune', 'operatore',
    ],

];
