<?php

/*
|--------------------------------------------------------------------------
| Sito aziendale (il dominio nudo, senza prefisso)
|--------------------------------------------------------------------------
|
| Tutti i dati modificabili del sito stanno qui, in un file solo, e le
| pagine li leggono attraverso App\Support\SitoDati: niente e' scritto due
| volte, e quello che e' vuoto (o di soli spazi) NON viene stampato. Ne'
| etichette vuote, ne' trattini, ne' "da definire".
|
| Regola: il programma non inventa fatti sull'azienda. Anni di esperienza,
| alberi censiti, Comuni serviti, referenze, certificazioni, professionisti,
| portali realizzati: finche' una voce resta vuota, la sua riga o la sua
| sezione semplicemente non compare. Meglio una pagina piu' corta che una
| pagina che dice il falso a un ufficio tecnico comunale.
|
| I valori si cambiano dal file .env del server (chiavi SITO_*, le chiede
| deploy/set-sito-domain.sh --dati) oppure scrivendo qui i valori di serie.
| I dati societari di serie sono quelli verificati e forniti dal committente
| il 19/09/2026 (DAMA S.R.L.). La coerenza del file la controlla
| SitoDati::problemi(), che la prova SitoAziendaleTest vuole vuota.
|
*/

return [

    /*
    | Il dominio di produzione: quello nudo, senza prefisso e senza schema.
    | Vuoto = il sito completo risponde SOLO dal percorso di collaudo /sito,
    | con noindex e senza canonical: la radice del dominio non pubblica
    | niente finche' non lo si decide. E' la leva con cui si apre il sito.
    | I portali dei Comuni restano sui loro sottodomini (PORTAL_BASE_HOST).
    */
    'base_host' => env('SITO_BASE_HOST', ''),

    /* Percorso di collaudo /sito: attivo anche in produzione, sempre noindex. */
    'path_fallback' => (bool) env('SITO_PATH_FALLBACK', true),

    /*
    | Come si chiama il servizio nelle pagine (il marchio in testata). Non e'
    | la ragione sociale: quella sta sotto e compare in "Chi siamo" e nel pie'.
    */
    'nome' => env('SITO_NOME', 'Censimento Alberature'),
    'sottotitolo' => env('SITO_SOTTOTITOLO', 'Censimento e catasto del verde urbano'),

    /*
    | Dati societari. Compaiono nel pie' di pagina, in "Chi siamo" e come
    | titolare del trattamento nell'informativa. Il capitale e' "sottoscritto"
    | e non "versato": il versamento non e' stato verificato, quindi non si
    | scrive. Amministratore e compagine sociale non servono e non si mostrano.
    */
    'azienda' => [
        'ragione_sociale' => env('SITO_RAGIONE_SOCIALE', 'DAMA S.R.L.'),
        'sede' => env('SITO_SEDE', 'Via Crescenzio 58, 00193 Roma (RM), Italia'),
        'codice_fiscale' => env('SITO_CODICE_FISCALE', '17947161000'),
        'piva' => env('SITO_PIVA', '17947161000'),
        'registro_imprese' => env('SITO_REGISTRO_IMPRESE', 'Registro delle Imprese di Roma'),
        'rea' => env('SITO_REA', 'RM-1751463'),
        'capitale_sociale' => env('SITO_CAPITALE_SOCIALE', '€ 1.000,00 sottoscritto'),
        // Una riga sul territorio servito ("Operiamo nel Lazio e nelle regioni
        // vicine"). Vuota: il sito non promette una copertura che non c'e'.
        'territorio' => env('SITO_TERRITORIO', ''),
        // Da quanto si opera nel settore, scritto come lo si vuole leggere.
        'esperienza' => env('SITO_ESPERIENZA', ''),
    ],

    /*
    | Recapiti. Ogni riga vuota sparisce dalla pagina Contatti e dal pie'.
    | Al 19/09/2026 e' verificata la sola PEC.
    */
    'contatti' => [
        'telefono' => env('SITO_TELEFONO', ''),
        'email' => env('SITO_EMAIL', ''),
        'pec' => env('SITO_PEC', 'dama25@pec.it'),
        'orari' => env('SITO_ORARI', ''),
        // Indirizzo dell'ufficio, se diverso dalla sede legale
        'indirizzo' => env('SITO_INDIRIZZO', ''),
    ],

    /*
    | Chi sottoscrive la documentazione tecnica delle valutazioni di stabilita',
    | con il suo titolo. Vuoto: la pagina VTA usa la formula prudente ("il
    | professionista incaricato, secondo la natura dell'attivita' e le
    | competenze richieste") senza nomi ne' titoli.
    */
    'perizie' => [
        'firmatario' => env('SITO_PERIZIE_FIRMATARIO', ''),
        'titolo' => env('SITO_PERIZIE_TITOLO', ''),
    ],

    /*
    | Un portale comunale vero da far aprire a chi legge: compare in home e
    | nella pagina del portale solo se l'indirizzo e' compilato (con il
    | consenso del Comune a essere mostrato come esempio).
    */
    'portale_esempio' => [
        'url' => env('SITO_PORTALE_ESEMPIO_URL', ''),
        'nome' => env('SITO_PORTALE_ESEMPIO_NOME', ''),
    ],

    /*
    | Voci di "Chi siamo" che si mostrano solo se compilate. Non hanno una
    | chiave .env: si scrivono qui, e restano vuote finche' non sono vere.
    |   professionisti: ['dott. agr. Nome Cognome, Ordine di ... n. ...', ...]
    |   referenze:      [['ente' => 'Comune di ...', 'lavoro' => '...', 'anno' => '2025'], ...]
    |   lavori:         [['titolo' => '...', 'descrizione' => '...', 'anno' => '2025'], ...]
    | Solo committenti che hanno autorizzato la citazione.
    */
    'professionisti' => [],
    'referenze' => [],
    'lavori' => [],

];
