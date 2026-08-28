<?php

/*
 * Stati di avanzamento lavori (SAL).
 *
 * Decisioni del committente (26/08/2026): la validazione e' manuale e rende
 * il documento immutabile; l'IVA si applica riga per riga (aliquote anche
 * diverse nello stesso SAL); non si gestiscono i pagamenti, solo il segno
 * "fatturato"; le spese generali percentuali sono SPENTE di serie e si
 * accendono da qui (o dal file .env del server), senza toccare il codice.
 */
return [

    // Percentuale di spese generali aggiunta in coda al SAL (0 = spente).
    // Se attiva, viene fotografata nel SAL alla creazione e ripartita
    // sulle aliquote IVA in proporzione agli imponibili.
    'spese_generali_percento' => (float) env('SAL_SPESE_GENERALI', 0),

    // Aliquote IVA proposte per le righe; ogni riga puo' cambiare la sua
    'aliquote' => [0.0, 4.0, 10.0, 22.0],

    'aliquota_predefinita' => (float) env('SAL_IVA_PREDEFINITA', 22),
];
