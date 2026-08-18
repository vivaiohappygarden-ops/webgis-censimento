<?php

/*
|--------------------------------------------------------------------------
| Stima dell'anidride carbonica immagazzinata dagli alberi
|--------------------------------------------------------------------------
|
| ATTENZIONE. I coefficienti qui sotto vanno verificati e, se serve,
| sostituiti dal tecnico prima di pubblicare il dato su un sito di un ente
| pubblico. Il programma non inventa formule: applica un modello dichiarato,
| e il modello dichiarato è questo file. Cambiando i numeri cambia la stima,
| e la pagina pubblica dice sempre quale metodo è stato usato.
|
| Il percorso di calcolo è quello classico:
|   1. biomassa epigea secca dal diametro del tronco, con equazione
|      allometrica logaritmica  ln(W) = b0 + b1 * ln(D)   [W in kg, D in cm]
|   2. correzione per alberi isolati, che a parità di diametro hanno meno
|      massa legnosa di quelli cresciuti in bosco
|   3. aggiunta della parte ipogea (radici) con il rapporto radici/fusto
|   4. frazione di carbonio sulla sostanza secca
|   5. conversione carbonio -> anidride carbonica (rapporto fra le masse
|      molecolari, 44/12)
|
| L'assorbimento medio annuo si ricava dividendo l'anidride carbonica
| accumulata per l'età dell'albero: è una media sulla vita della pianta, non
| il valore dell'anno in corso, e senza età non viene mostrato.
|
*/

return [

    // Nome del modello, mostrato al cittadino accanto al valore
    'modello' => env('CO2_MODELLO',
        'equazioni allometriche generalizzate per gruppi di specie (Jenkins et al., 2003), '
        .'frazione di carbonio e rapporto radici/fusto da Linee guida IPCC 2006, '
        .'correzione per alberi isolati secondo Nowak (1994)'),

    // ln(W) = b0 + b1 * ln(D). Due gruppi: bastano per un censimento urbano
    'gruppi' => [
        'latifoglie' => ['b0' => -2.4800, 'b1' => 2.4835],
        'conifere' => ['b0' => -2.5356, 'b1' => 2.4349],
    ],

    // Generi trattati come conifere; tutto il resto è latifoglia
    'conifere' => [
        'abies', 'araucaria', 'calocedrus', 'cedrus', 'chamaecyparis', 'cryptomeria',
        'cupressus', 'ginkgo', 'juniperus', 'larix', 'metasequoia', 'picea', 'pinus',
        'pseudotsuga', 'sequoia', 'sequoiadendron', 'taxodium', 'taxus', 'thuja', 'tsuga',
    ],

    // Alberi isolati: meno massa legnosa a parità di diametro
    'correzione_isolati' => 0.8,

    // Radici in rapporto alla parte aerea (foreste temperate, IPCC 2006)
    'rapporto_radici' => 0.26,

    // Carbonio sulla sostanza secca (IPCC 2006)
    'frazione_carbonio' => 0.47,

    // Da carbonio ad anidride carbonica: 44/12
    'co2_per_carbonio' => 3.6667,

    // Oltre questo diametro la stima non viene mostrata: fuori dal campo di
    // validità delle equazioni, il numero sarebbe solo un'estrapolazione
    'diametro_massimo_cm' => 250,

];
