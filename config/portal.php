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

    'reserved_slugs' => [
        'www', 'app', 'api', 'admin', 'mail', 'smtp', 'imap', 'pop', 'ftp',
        'ns', 'ns1', 'ns2', 'mx', 'cdn', 'static', 'assets', 'storage',
        'test', 'staging', 'dev', 'demo', 'portale', 'comune', 'operatore',
    ],

];
