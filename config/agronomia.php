<?php

/*
 * Dizionari agronomici dell'anagrafica albero: l'unica fonte delle voci.
 *
 * Le tendine della scheda albero, la validazione delle scritture e le stampe
 * leggono tutte da qui: aggiungere o correggere una voce si fa in questo file
 * e in nessun altro posto. I valori si salvano cosi' come sono scritti
 * (minuscoli, in italiano), come gia' avviene per lo stato vegetativo.
 */
return [

    // Fase fisiologica a sei stadi (colonna trees.age_class). Le schede
    // compilate prima del passaggio a sei stadi portano i quattro storici
    // (giovane, adulto, maturo, senescente): sono un sottoinsieme di questi,
    // quindi restano valide senza correzioni.
    'fase_fisiologica' => [
        'giovane',
        'giovane adulto',
        'adulto',
        'maturo',
        'senescente',
        'veterano',
    ],

    'stato_vegetativo' => [
        'buono',
        'medio',
        'scarso',
        'deperiente',
        'secco',
    ],

    // Come cresce l'albero rispetto agli altri: da solo, in fila, in gruppo.
    'posizione_sociale' => [
        'isolato',
        'filare',
        'gruppo',
        'area boscata',
    ],

    // Frequentazione sotto la chioma: che cosa colpirebbe un cedimento.
    // I bersagli puntuali (l'area giochi, il marciapiede) si elencano nella
    // valutazione di stabilita'; qui si classifica quanto il posto e' vissuto.
    'bersaglio' => [
        'assente',
        'saltuario',
        'frequente',
        'costante',
    ],

    // Dove affondano le radici: condiziona irrigazione, stabilita' e crescita.
    'sito_di_crescita' => [
        'prato o parco',
        'aiuola',
        'bauletto rialzato',
        'buca su marciapiede',
        'buca nell\'asfalto',
        'banchina stradale erbosa',
        'parcheggio',
        'piazza pavimentata',
        'giardino',
        'cortile',
        'scarpata',
        'argine o riva',
        'area boscata',
        'terreno agricolo',
        'fioriera o vaso',
        'tetto verde',
        'altro',
    ],

    // L'eta' in anni e' precisa (data di impianto nota, conteggio anelli)
    // o stimata a vista: al femminile perche' qualifica "l'eta'".
    'qualificatore_eta' => [
        'precisa',
        'stimata',
    ],
];
