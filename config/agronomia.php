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

    // Prescrizioni ricorrenti delle valutazioni di stabilita': la scheda VTA le
    // propone con la ricerca a parole e le aggiunge al testo libero, che resta
    // modificabile. Sono formule d'uso, non un protocollo: il tecnico scrive
    // quello che prescrive davvero.
    'prescrizioni_vta' => [
        'Rimonda del secco nella prossima stagione di riposo vegetativo',
        'Rimozione dei rami secchi, spezzati o pericolanti entro 30 giorni',
        'Potatura di alleggerimento della chioma',
        'Potatura di contenimento della chioma',
        'Potatura di riduzione della chioma (al massimo 20-25 per cento) con tagli di ritorno',
        'Potatura di rialzo della chioma per il passaggio pedonale o veicolare',
        'Potatura di risanamento con rimozione delle parti colpite',
        'Spollonatura al colletto e lungo il fusto',
        'Consolidamento dinamico della chioma (cablaggio)',
        'Consolidamento statico della chioma',
        'Installazione di palo tutore o ancoraggio',
        'Prova di trazione (SIM)',
        'Analisi strumentale del fusto (tomografia sonica)',
        'Analisi strumentale del fusto (resistografia)',
        'Indagine dell\'apparato radicale (scavo con aria compressa)',
        'Monitoraggio semestrale della parte lesionata',
        'Ricontrollo visivo entro 12 mesi',
        'Interdizione dell\'area sottostante fino all\'intervento',
        'Transennamento dell\'area di caduta',
        'Abbattimento e sostituzione con esemplare della stessa specie',
        'Abbattimento e sostituzione con specie adeguata al sito',
        'Abbattimento con rimozione della ceppaia',
        'Trattamento endoterapico contro il punteruolo rosso (Rhynchophorus ferrugineus)',
        'Trattamento endoterapico contro la processionaria del pino (Thaumetopoea pityocampa)',
        'Trattamento contro la cocciniglia tartaruga del pino (Toumeyella parvicornis)',
        'Trattamento contro la cameraria dell\'ippocastano (Cameraria ohridella)',
        'Trattamento contro afidi e cocciniglie',
        'Trattamento fungicida contro oidio',
        'Segnalazione al Servizio fitosanitario regionale per sospetto cancro colorato del platano (Ceratocystis platani)',
        'Rimozione del materiale di risulta e pulizia dell\'area',
        'Rimozione del terreno in eccesso al colletto',
        'Decompattazione del terreno nell\'area di pertinenza',
        'Pacciamatura dell\'area di pertinenza',
        'Irrigazione di soccorso nel periodo estivo',
        'Concimazione di sostegno',
        'Ampliamento dell\'area di pertinenza permeabile',
        'Rimozione di cavi, legature o corpi estranei dal fusto',
        'Nessun intervento: mantenere il programma di controllo ordinario',
    ],
];
