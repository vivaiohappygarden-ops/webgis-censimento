<?php

/*
|--------------------------------------------------------------------------
| Sito aziendale (censimentoalberature.it)
|--------------------------------------------------------------------------
|
| I dati dell'azienda stanno qui, non nelle pagine: si cambiano senza
| toccare il codice, e quello che non e' compilato NON viene stampato.
|
| Regola: il programma non inventa fatti sull'azienda. Un anno di attivita',
| una referenza o un titolo professionale scritti "per riempire" su un sito
| che parla a un ufficio tecnico comunale sono un danno, non una spinta.
| Finche' una voce resta vuota, la sua riga o la sua sezione semplicemente
| non compare: meglio una pagina piu' corta che una pagina che dice il falso.
|
| Si compilano dal file .env del server (chiavi SITO_*), oppure scrivendo
| direttamente qui i valori.
|
*/

return [

    /*
    | Il dominio su cui risponde il sito aziendale: quello nudo, senza
    | prefisso. Vuoto = il sito risponde solo dal percorso di collaudo /sito.
    | I portali dei Comuni restano sui loro sottodomini.
    */
    'base_host' => env('SITO_BASE_HOST', env('PORTAL_BASE_HOST')),

    /* Percorso di collaudo, da tenere attivo anche in produzione. */
    'path_fallback' => (bool) env('SITO_PATH_FALLBACK', true),

    /*
    | Come si chiama il servizio nelle pagine. Non e' la ragione sociale:
    | quella sta qui sotto e compare nel pie' di pagina e in "Chi siamo".
    */
    'nome' => env('SITO_NOME', 'Censimento Alberature'),
    'sottotitolo' => env('SITO_SOTTOTITOLO', 'Censimento e catasto del verde urbano'),

    /*
    | Dati dell'azienda. Vuoti finche' non vengono forniti: il pie' di pagina
    | stampa solo le voci compilate, e la pagina "Chi siamo" salta i blocchi
    | senza contenuto.
    */
    'azienda' => [
        'ragione_sociale' => env('SITO_RAGIONE_SOCIALE', ''),
        'sede' => env('SITO_SEDE', ''),
        'piva' => env('SITO_PIVA', ''),
        'rea' => env('SITO_REA', ''),
        // Una riga sul territorio servito: "Operiamo in Lazio e regioni
        // limitrofe". Senza, la home non promette una copertura che non c'e'
        'territorio' => env('SITO_TERRITORIO', ''),
        // Da quanto si opera nel settore, scritto come si vuole leggerlo
        'esperienza' => env('SITO_ESPERIENZA', ''),
    ],

    'contatti' => [
        'telefono' => env('SITO_TELEFONO', ''),
        'email' => env('SITO_EMAIL', ''),
        'pec' => env('SITO_PEC', ''),
        'orari' => env('SITO_ORARI', ''),
        'indirizzo' => env('SITO_INDIRIZZO', ''),
    ],

    /*
    | Chi firma le perizie di stabilita', con il suo titolo. Va scritto quello
    | che e' vero oggi: un ufficio tecnico lo verifica, e una dichiarazione
    | imprecisa qui costa molto piu' del vantaggio che da'. Vuoto: la pagina
    | VTA descrive il metodo senza attribuire firme.
    */
    'perizie' => [
        'firmatario' => env('SITO_PERIZIE_FIRMATARIO', ''),
        'titolo' => env('SITO_PERIZIE_TITOLO', ''),
    ],

    /*
    | Un portale comunale vero da far aprire a chi legge: e' la prova che
    | vale piu' di mezza pagina di descrizione. Indirizzo completo, di un
    | Comune che ha dato il consenso a essere mostrato come esempio.
    */
    'portale_esempio' => [
        'url' => env('SITO_PORTALE_ESEMPIO_URL', ''),
        'nome' => env('SITO_PORTALE_ESEMPIO_NOME', ''),
    ],

    /*
    | Referenze: solo committenti che hanno autorizzato la citazione. Senza
    | autorizzazione si scrive "Comune in provincia di ..." senza nome.
    | Ogni voce: ['ente' => ..., 'lavoro' => ..., 'anno' => ...].
    */
    'referenze' => [],

];
