<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interfaccia del gestionale
    |--------------------------------------------------------------------------
    |
    | Dal 26/09/2026 il gestionale ha due vesti: la "nuova" (bozza A approvata
    | dal committente: sei voci di menu che seguono il lavoro, pagina Oggi
    | come punto di partenza) e la "precedente". Ogni utente puo' scegliere la
    | sua dalle impostazioni; chi non ha scelto vede questa. Per rimettere tutti
    | sulla precedente in un colpo basta INTERFACCIA_PREDEFINITA=precedente nel
    | file .env del server (e il codice vecchio resta comunque nell'etichetta
    | git interfaccia-precedente-2026-09).
    |
    */

    'predefinita' => env('INTERFACCIA_PREDEFINITA', 'nuova'),

];
