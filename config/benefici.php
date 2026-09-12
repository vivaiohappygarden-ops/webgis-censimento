<?php

/*
|--------------------------------------------------------------------------
| Altri benefici ambientali degli alberi (oltre all'anidride carbonica)
|--------------------------------------------------------------------------
|
| ATTENZIONE, come per config/co2.php. I coefficienti qui sotto vanno
| verificati e, se serve, sostituiti dal tecnico prima di pubblicare il dato
| sul sito di un ente pubblico: dipendono dal clima e dalla qualità dell'aria
| del posto, e un numero preso da un altro contesto non descrive questo.
| Il programma non inventa formule: applica un modello dichiarato, e il
| modello dichiarato è questo file. Ogni pagina che mostra questi valori
| dichiara sempre il metodo con cui sono stati ottenuti.
|
| Che cosa si stima e come:
|
|   OSSIGENO. Non è una stima a sé: la fotosintesi lega il carbonio fissato
|   all'ossigeno liberato in rapporto fisso fra le masse molecolari
|   (32/44 rispetto all'anidride carbonica). Si calcola quindi sull'ASSORBIMENTO
|   ANNUO di CO2 (config/co2.php), non sulla CO2 immagazzinata: l'ossigeno è
|   un flusso, non un magazzino. Senza età dell'albero non c'è assorbimento
|   annuo, e quindi non c'è nemmeno l'ossigeno.
|
|   POLVERI SOTTILI e PIOGGIA INTERCETTATA. Si calcolano sull'AREA DI CHIOMA
|   proiettata a terra, ricavata dal diametro della chioma censito
|   (area = pi greco * (diametro/2)^2). Senza diametro della chioma non si
|   mostra nulla: meglio nessun dato che un dato finto.
|     - polveri: quantità trattenuta per metro quadrato di chioma in un anno;
|     - pioggia: la chioma trattiene una frazione della pioggia che la
|       raggiunge (1 mm di pioggia su 1 m² = 1 litro), acqua che non finisce
|       subito in fognatura.
|
|   ENERGIA RISPARMIATA. Non si stima, di proposito: dipende da dove l'albero
|   sta rispetto agli edifici (ombra estiva, riparo dal vento), un dato che il
|   censimento non ha. Inventarlo darebbe un numero peggiore di nessun numero.
|
| Controvalori in euro: di serie sono spenti (0). Il danno sanitario delle
| polveri e il costo evitato di depurazione variano moltissimo da luogo a
| luogo; chi li accende deve scriverne anche la fonte, altrimenti gli euro
| restano spenti (stessa regola del prezzo della CO2).
|
*/

return [

    // Nome del modello, mostrato accanto ai valori
    'modello' => env('BENEFICI_MODELLO',
        'ossigeno dal rapporto stechiometrico con il carbonio fissato (Nowak, Hoehn e Crane, 2007); '
        .'polveri trattenute per superficie di chioma (Nowak et al., 2006 e 2013); '
        .'pioggia intercettata dalla chioma secondo Xiao e McPherson (2002)'),

    // Ossigeno liberato per ogni chilogrammo di CO2 assorbita: 32/44
    'ossigeno_per_co2' => 0.7273,

    // Polveri trattenute in un anno, per metro quadrato di chioma proiettata
    // a terra. Valori prudenziali: la deposizione dipende dalla concentrazione
    // in aria e dalla specie, e in città diverse cambia di parecchie volte
    'pm10_g_per_m2_anno' => (float) env('BENEFICI_PM10_G_M2', 6.0),
    'pm25_g_per_m2_anno' => (float) env('BENEFICI_PM25_G_M2', 0.4),

    // Pioggia annua del posto, in millimetri: va impostata con il dato della
    // stazione meteo più vicina, altrimenti la stima dice poco
    'pioggia_mm_anno' => (float) env('BENEFICI_PIOGGIA_MM', 800),

    // Quota della pioggia trattenuta dalla chioma (bagnatura ed evaporazione):
    // in letteratura dal 15% al 30% secondo specie, densità e stagione
    'frazione_intercettazione' => (float) env('BENEFICI_INTERCETTAZIONE', 0.20),

    // Oltre questo diametro di chioma la stima non si mostra: quasi sempre è
    // un errore di digitazione, e un errore moltiplicato per l'area diventa
    // un numero grottesco in prima pagina
    'chioma_massima_m' => 40.0,

    // Controvalori economici. Con 0 (o senza fonte) gli euro non compaiono da
    // nessuna parte e restano le sole quantità fisiche
    'euro_per_kg_pm10' => (float) env('BENEFICI_EURO_KG_PM10', 0),
    'euro_per_kg_pm25' => (float) env('BENEFICI_EURO_KG_PM25', 0),
    'euro_per_m3_pioggia' => (float) env('BENEFICI_EURO_M3_PIOGGIA', 0),

    // Le fonti dei prezzi, dichiarate in pagina accanto ai valori. Senza
    // fonte il relativo controvalore resta spento
    'fonte_prezzo_polveri' => env('BENEFICI_FONTE_PREZZO_POLVERI', ''),
    'fonte_prezzo_pioggia' => env('BENEFICI_FONTE_PREZZO_PIOGGIA', ''),

];
