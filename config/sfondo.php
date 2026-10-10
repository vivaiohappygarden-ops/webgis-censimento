<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sfondo della mappa per l'uso senza rete (dal 10/10/2026)
    |--------------------------------------------------------------------------
    |
    | L'app di campo deve funzionare senza connessione per un'intera giornata,
    | e la mappa ne fa parte: lo sfondo stradale non puo' arrivare dai server
    | di OpenStreetMap (senza rete non rispondono, e le loro regole d'uso
    | vietano di scaricarne le tessere in blocco). Il server ritaglia quindi
    | dalle costruzioni Protomaps (dati OpenStreetMap, formato PMTiles, licenza
    | ODbL) lo sfondo vettoriale del territorio di ogni organizzazione e lo
    | serve ai telefoni, che lo scaricano una volta e lo leggono anche offline.
    |
    */

    'sorgente' => [
        // L'elenco delle costruzioni giornaliere e la radice dei file
        'elenco' => env('SFONDO_ELENCO', 'https://build.protomaps.com/builds.json'),
        'base' => env('SFONDO_BASE', 'https://build.protomaps.com/'),
        // Una costruzione precisa (indirizzo completo o file sul disco) al posto dell'ultima
        'fissa' => env('SFONDO_SORGENTE'),
        'timeout' => (int) env('SFONDO_TIMEOUT', 120),
    ],

    // Zoom massimo delle tessere: le costruzioni Protomaps arrivano al 15, e
    // MapLibre ingrandisce da solo le tessere vettoriali oltre
    'zoom_max' => (int) env('SFONDO_ZOOM_MAX', 15),

    // Margine attorno al territorio (aree ed elementi censiti), in chilometri
    'margine_km' => (float) env('SFONDO_MARGINE_KM', 2.0),

    // Tetto di tessere per organizzazione: oltre, il territorio e' troppo
    // grande per un solo file e il comando lo dice invece di riempire il disco
    'tessere_massime' => (int) env('SFONDO_TESSERE_MASSIME', 6000),

    // Dopo tanti giorni il comando giornaliero rifa' lo sfondo
    'giorni_validita' => (int) env('SFONDO_GIORNI_VALIDITA', 30),

    // Cartella sul disco privato
    'cartella' => 'sfondi',

];
