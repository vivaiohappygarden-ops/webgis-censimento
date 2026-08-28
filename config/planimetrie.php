<?php

/*
 * Planimetrie delle aree nella scheda della località (decisione committente
 * 28/08/2026): sfondo cartografico quando la rete lo consente, altrimenti
 * disegno tecnico dalle sole geometrie. La stampa non deve mai fallire per
 * colpa dello sfondo: al primo intoppo si ripiega sul disegno.
 */
return [

    // 'auto': prova lo sfondo cartografico e ripiega sul disegno tecnico;
    // 'disegno': sempre e solo disegno tecnico (nessuna richiesta in rete)
    'sfondo' => env('PLANIMETRIE_SFONDO', 'auto'),

    // Riquadri raster dello sfondo (schema {z}/{x}/{y}) e attribuzione dovuta
    'tile_url' => env('PLANIMETRIE_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'attribuzione' => env('PLANIMETRIE_ATTRIBUZIONE', 'Sfondo cartografico © OpenStreetMap contributors'),

    // Secondi concessi al recupero di ogni riquadro: oltre, si ripiega
    'timeout' => (int) env('PLANIMETRIE_TIMEOUT', 4),

    // Secondi complessivi di rete per l'INTERA stampa: una rete lenta ma
    // viva non deve tenere in ostaggio il PDF per minuti
    'tempo_massimo' => (int) env('PLANIMETRIE_TEMPO_MASSIMO', 20),

    // Oltre questo numero di tavole la stampa le omette e lo dichiara:
    // un comune con decine di aree non deve esaurire memoria e tempo PHP
    'massimo_tavole' => (int) env('PLANIMETRIE_MASSIMO_TAVOLE', 20),

    // Cartella della scorta dei riquadri (null = storage/app/planimetrie-tiles)
    'cache_dir' => env('PLANIMETRIE_CACHE_DIR'),

    // I riquadri scaricati si conservano su disco per non richiederli a ogni
    // ristampa (rispetto per il servizio e stampe piu' veloci)
    'cache_giorni' => 30,

    // Larghezza dell'immagine di ogni planimetria nel PDF
    'larghezza_px' => 1100,

    // Oltre questo numero di elementi le etichette si omettono (il disegno
    // diventerebbe illeggibile) e la didascalia lo dichiara
    'massimo_etichette' => 40,
];
