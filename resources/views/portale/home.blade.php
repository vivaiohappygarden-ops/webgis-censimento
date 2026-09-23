@extends('portale.layout')

@section('titolo', 'Censimento del verde')

{{-- La home è a tutta larghezza: le fasce (ricerca, mappa, stato del verde)
     sono bande orizzontali, non una colonna di testo --}}
@section('classe-pagina', 'pagina-piena')

@push('stile')
@if ($estensione && count($sfondi))
    @vite(['resources/js/portale-mappa.js'])
@endif
<style>
/* ==========================================================================
   HOME — veste istituzionale
   Colori, corpi, spazi e componenti stanno tutti nel layout: qui si scrive
   solo l'impianto delle fasce e la tavola delle quote, che è di questa
   pagina.

   ORDINE DELLE FASCE, e perché è questo:
     1 ricerca   - il cittadino arriva qui con un numero in mano, letto sul
                   cartellino o inquadrato con il codice QR. Il campo sta nel
                   primo schermo, prima di qualunque racconto.
     2 mappa     - la seconda domanda ("che cosa c'è vicino a casa mia") si
                   risponde con una carta grande, non con un francobollo.
     3 stato     - quanti elementi, come stanno: i numeri dell'ente.
     4 benefici  - solo se il Comune li ha accesi, e dichiarati come stime.
     5 misure    - che cosa c'è scritto in una scheda.
     6 servizio  - chi controlla, come si segnala un problema.
     7 coda      - la nota sul metodo e di nuovo il campo di ricerca.
   ========================================================================== */

/* Gli occhielli sono paragrafi: senza questo si portano dietro il margine
   di fine capoverso */
.sc-occhiello { margin-top: 0; }

/* ------------------------------------------------------------- 1. RICERCA
   La prima fascia è un atto d'ufficio: titolo, una riga che dice a che cosa
   serve, e il campo. Niente immagine davanti. */
.apertura { padding-top: var(--s-5); padding-bottom: var(--s-6); }
.apertura-titolo { max-width: 22ch; }
/* Anche sugli schermi larghi nome, presentazione, campo del cartellino e le
   due strade restano incolonnati a sinistra, nella parte velata: la meta'
   destra della fotografia deve restare visibile (decisione committente
   23/09/2026: una scatola spostata a destra copriva la veduta del Comune). */
.apertura-benvenuto {
    margin-top: var(--s-2);
    font-size: var(--t-guida);
    line-height: 1.5;
    color: var(--inchiostro-2);
    max-width: var(--riga);
    text-wrap: pretty;
}

/* Il campo del cartellino: una scatola vera, con il suo bordo. La versione
   precedente era un filo sotto il testo e da sola sembrava una riga vuota. */
.cerca {
    margin-top: var(--s-4);
    max-width: 640px;
}
.cerca-etichetta {
    display: block;
    margin-bottom: var(--s-1);
    font-size: var(--t-occhiello);
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--inchiostro-2);
}
.cerca-riga { display: flex; flex-wrap: wrap; gap: var(--s-2); }
.cerca-campo {
    flex: 1 1 240px;
    min-width: 0;
    min-height: 52px;
    padding: 10px 14px;
    border: 1px solid var(--filo-2);
    border-radius: var(--raggio);
    background: #fff;
    color: var(--inchiostro);
    font-family: var(--testo);
    /* mai sotto i 16px: sotto quella misura il telefono ingrandisce da sé la
       pagina appena si tocca il campo, e il portale salta fuori schermo */
    font-size: 18px;
    line-height: 1.3;
}
.cerca-campo::placeholder { color: var(--inchiostro-2); opacity: 0.7; }
.cerca-invio { flex: 0 0 auto; }
.cerca-nota {
    margin: var(--s-1) 0 0;
    font-size: var(--t-etichetta);
    line-height: 1.5;
    color: var(--inchiostro-2);
    max-width: var(--riga);
}

/* L'esito negativo di una ricerca: si vede, ma non grida */
.avviso {
    margin-top: var(--s-3);
    padding: var(--s-2) var(--s-3);
    border-left: 4px solid var(--stato-verifica);
    background: var(--avorio-2);
    font-size: var(--t-corpo);
    max-width: var(--riga);
}

/* Le altre due strade, in riga sotto il campo */
.vie {
    display: flex;
    flex-wrap: wrap;
    gap: 0 var(--s-4);
    margin-top: var(--s-3);
}
.vie a {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    font-size: var(--t-corpo);
    font-weight: 500;
    color: var(--bosco);
    text-decoration: underline;
    text-underline-offset: 0.2em;
}

/* Con la fotografia di copertina (veste mista, 23/09/2026) l'apertura
   diventa la veduta del Comune: la foto sotto, un velo sopra e il testo in
   bianco. Il velo e' NERO, non tinto: una fotografia non prende il colore
   dell'ente, ed e' l'unico modo di garantire il contrasto sopra una foto che
   non abbiamo mai visto. Il campo del cartellino resta il primo oggetto della
   pagina: diventa una scatola bianca appoggiata sulla foto. Senza fotografia
   resta l'apertura qui sopra: niente immagine di riempimento. */
.apertura-foto {
    position: relative;
    isolation: isolate;
    background-color: var(--notte);
    background-size: cover;
    background-position: center;
    color: #fff;
    padding-top: var(--s-7);
    /* spazio per i riquadri dei numeri, che salgono a cavallo della foto */
    padding-bottom: calc(var(--s-7) + 64px);
}
.apertura-foto::before {
    content: "";
    position: absolute;
    inset: 0;
    z-index: -1;
    /* sul telefono il testo occupa tutta la larghezza: velo uniforme, nero
       all'84%, che tiene il bianco sopra 4,5:1 anche su un cielo chiaro */
    background: rgba(0, 0, 0, 0.84);
}
@media (min-width: 900px) {
    /* sullo schermo largo il testo sta nella meta' sinistra: li' il velo
       resta pieno, a destra si apre e lascia vedere la fotografia */
    .apertura-foto::before {
        background:
            linear-gradient(90deg, rgba(0, 0, 0, 0.86) 0%, rgba(0, 0, 0, 0.84) 46%, rgba(0, 0, 0, 0.30) 72%, rgba(0, 0, 0, 0.12) 100%),
            linear-gradient(180deg, rgba(0, 0, 0, 0) 55%, rgba(0, 0, 0, 0.45) 100%);
    }
}
.apertura-foto .sc-occhiello { color: rgba(255, 255, 255, 0.82); }
.apertura-foto .apertura-titolo { color: #fff; }
.apertura-foto .apertura-benvenuto { color: rgba(255, 255, 255, 0.92); }
.apertura-foto .cerca {
    background: var(--carta);
    color: var(--inchiostro);
    padding: var(--s-2) var(--s-3);
    border-radius: var(--raggio);
    box-shadow: var(--ombra-2);
}
.apertura-foto .avviso { color: var(--inchiostro); }
.apertura-foto .vie { gap: var(--s-2); }
.apertura-foto .vie a {
    min-height: 48px;
    padding: 0 18px;
    border: 1px solid rgba(255, 255, 255, 0.75);
    border-radius: var(--raggio);
    color: #fff;
    font-weight: 600;
    text-decoration: none;
}
.apertura-foto .vie a:hover { background: rgba(255, 255, 255, 0.14); }
.apertura-foto :focus-visible { outline-color: #fff; }
.apertura-foto .cerca :focus-visible { outline-color: var(--bosco); }

/* --------------------------------------------------- 1b. I NUMERI GRANDI
   Quattro riquadri subito sotto l'apertura: elementi, alberi, varieta' e la
   stima dell'anidride carbonica quando il Comune l'ha accesa (altrimenti il
   conteggio successivo). Sono i numeri che si leggono da lontano; gli altri
   conteggi restano nella fascia "come sta il verde", e quello che sale qui
   non si ripete la' sotto. Con la fotografia i riquadri salgono a cavallo
   del suo bordo, come nella bozza approvata. */
.numeri { padding-bottom: var(--s-3); }
.numeri:not(.numeri-sopra) { padding-top: var(--s-5); }
.numeri-sopra .sc-contenitore { position: relative; z-index: 2; margin-top: -80px; }
.tessere {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--s-2);
    margin: 0;
    padding: 0;
    list-style: none;
}
@media (min-width: 900px) {
    /* Tante colonne quanti sono i riquadri (--tessere, scritto dalla pagina):
       con due soli numeri la fila non resta mezza vuota */
    .tessere { grid-template-columns: repeat(var(--tessere, 4), minmax(0, 1fr)); gap: var(--s-3); }
}
.tessera {
    background: var(--carta);
    border: 1px solid var(--filo);
    border-top: 4px solid var(--bosco);
    border-radius: var(--raggio);
    box-shadow: var(--ombra-2);
    padding: var(--s-3);
}
@media (max-width: 899px) { .tessera { padding: var(--s-2); } }
.tessera-cifra {
    display: block;
    font-size: var(--t-dato);
    font-weight: 600;
    line-height: 1.05;
    letter-spacing: -0.02em;
    color: var(--bosco);
}
/* L'unita' segue la cifra ma non scende mai sotto i 17px del corpo */
.tessera-unita { font-size: max(17px, 0.45em); font-weight: 600; margin-left: 0.18em; color: var(--inchiostro-2); }
.tessera-nome { display: block; margin-top: var(--s-1); font-weight: 600; line-height: 1.3; }
.tessera-glossa {
    display: block;
    margin-top: 4px;
    font-size: var(--t-etichetta);
    line-height: 1.5;
    color: var(--inchiostro-2);
}

/* --------------------------------------------------------------- 2. MAPPA
   Grande, non un francobollo: è il secondo modo di cercare la propria via. */
.anteprima {
    display: block;
    position: relative;
    margin-top: var(--s-4);
    height: clamp(280px, 42vh, 440px);
    border: 1px solid var(--filo-2);
    border-radius: var(--raggio);
    overflow: hidden;
    text-decoration: none;
}
#anteprima-mappa { position: absolute; inset: 0; }
.anteprima .invito {
    position: absolute;
    right: var(--s-3);
    bottom: var(--s-3);
    z-index: 2;
    box-shadow: var(--ombra);
}
.mappa-assente {
    margin-top: var(--s-3);
    padding: var(--s-4);
    border: 1px dashed var(--filo-2);
    border-radius: var(--raggio);
    color: var(--inchiostro-2);
    max-width: var(--riga);
}
.legenda {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-1) var(--s-4);
    margin: var(--s-3) 0 0;
    padding: 0;
    list-style: none;
    font-size: var(--t-etichetta);
}
.legenda li { display: flex; align-items: center; gap: var(--s-1); }

/* --------------------------------------------------- 3. STATO DEL VERDE */
.censito-data { margin-top: var(--s-1); }

/* La barra delle proporzioni: quanto pesa ogni stato sul totale. Sostituisce
   il filare di alberelli disegnati: qui conta la proporzione, non la figura. */
.fascia {
    display: flex;
    height: 14px;
    margin-top: var(--s-4);
    border-radius: var(--raggio);
    overflow: hidden;
    background: var(--filo);
}
.fascia span { background: var(--c); }

.stati {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--s-3) var(--s-5);
    margin: var(--s-4) 0 0;
    padding: 0;
    list-style: none;
}
.stato-voce { border-top: 1px solid var(--filo); padding-top: var(--s-2); }
.stato-nome {
    display: flex;
    align-items: baseline;
    gap: var(--s-1);
    font-weight: 600;
}
.stato-nome .sc-pallino { align-self: center; }
.stato-quanti { margin-left: auto; font-weight: 600; }
.stato-voce p {
    margin: 4px 0 0;
    font-size: var(--t-etichetta);
    line-height: 1.5;
    color: var(--inchiostro-2);
}

/* I numeri del patrimonio: cifra grande, nome, glossa piccola */
.voci {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--s-4);
    margin: var(--s-6) 0 0;
    padding: 0;
    list-style: none;
}
.voci li { border-top: 2px solid var(--bosco); padding-top: var(--s-2); }
.voce-cifra {
    display: block;
    font-size: var(--t-dato);
    font-weight: 600;
    line-height: 1.05;
    letter-spacing: -0.02em;
    color: var(--bosco);
}
.voce-nome { display: block; margin-top: var(--s-1); }
.voce-nome b { display: block; font-weight: 600; }
.voce-nome span {
    display: block;
    margin-top: 2px;
    font-size: var(--t-etichetta);
    line-height: 1.5;
    color: var(--inchiostro-2);
}

/* ----------------------------------------------------------- 4. BENEFICI
   Una stima guida (l'anidride carbonica) e le altre sotto, più piccole: sono
   dello stesso tipo ma non dello stesso peso. Tutte con l'asterisco. */
.stima-guida {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--s-2) var(--s-4);
    margin-top: var(--s-4);
    padding-bottom: var(--s-4);
    border-bottom: 1px solid var(--filo);
}
.stima-guida .valore {
    font-size: clamp(40px, 5vw, 64px);
    font-weight: 600;
    line-height: 1;
    letter-spacing: -0.025em;
    color: var(--bosco);
}
.stima-guida .unita { font-size: 0.45em; font-weight: 600; margin-left: 0.2em; white-space: nowrap; }
.stima-guida .spiega { flex: 1 1 260px; margin: 0; max-width: 46ch; }
.stima-euro {
    margin-top: var(--s-3);
    font-size: var(--t-etichetta);
    color: var(--inchiostro-2);
    max-width: var(--riga);
}
.stima-euro b { color: var(--inchiostro); font-weight: 600; }

.stime {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--s-4) var(--s-5);
    margin-top: var(--s-4);
}
.stima-valore {
    margin: 0;
    font-size: var(--t-h2);
    font-weight: 600;
    line-height: 1.05;
    letter-spacing: -0.02em;
    color: var(--bosco);
}
/* L'unità non si spezza a metà parola: "m3/anno" e "litri/anno" restano
   interi, o in una colonna stretta finiscono a capo dentro la parola */
.stima-unita { font-size: 0.5em; font-weight: 600; margin-left: 0.18em; white-space: nowrap; }
.stima-nome {
    margin: var(--s-1) 0 0;
    font-size: var(--t-etichetta);
    line-height: 1.5;
    color: var(--inchiostro-2);
}

/* ------------------------------------------------------------- 5. MISURE
   Tavola tecnica, non illustrazione: linee, quote e tre numeri di richiamo.
   Serve a far capire che cosa si legge in una scheda. */
.misure-griglia {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--s-5);
    align-items: start;
}
@media (min-width: 860px) {
    .misure-griglia { grid-template-columns: minmax(0, 1fr) 360px; }
}
.tavola { width: 100%; height: auto; display: block; }
.tavola-linea { fill: none; stroke: var(--inchiostro-2); stroke-width: 1.5; }
.tavola-pianta { fill: var(--chiaro-2); stroke: var(--inchiostro-2); stroke-width: 1.5; }
.tavola-quota { fill: none; stroke: var(--bosco); stroke-width: 1.5; }
.tavola-punta { fill: var(--bosco); }
.tavola-aiuto { fill: none; stroke: var(--filo-2); stroke-width: 1; stroke-dasharray: 4 4; }
.tavola-numero { fill: var(--bosco); }
.tavola-misura {
    fill: var(--inchiostro-2);
    font-family: var(--testo);
    font-size: 12px;
    font-weight: 500;
    text-anchor: end;
    dominant-baseline: central;
}
.tavola-cifra {
    fill: #fff;
    font-family: var(--testo);
    font-size: 13px;
    font-weight: 600;
    text-anchor: middle;
    dominant-baseline: central;
}

.quote { margin: var(--s-4) 0 0; padding: 0; list-style: none; counter-reset: quota; }
.quote li {
    display: flex;
    gap: var(--s-2);
    padding: var(--s-2) 0;
    border-top: 1px solid var(--filo);
}
.quote li::before {
    counter-increment: quota;
    content: counter(quota);
    flex: none;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: var(--bosco);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    line-height: 26px;
    text-align: center;
}
.quote b { display: block; font-weight: 600; }
.quote span {
    display: block;
    margin-top: 2px;
    font-size: var(--t-etichetta);
    line-height: 1.5;
    color: var(--inchiostro-2);
}

/* ----------------------------------------------------------- 6. SERVIZIO */
.servizio-griglia {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--s-6) var(--s-7);
    margin-top: var(--s-4);
}
@media (min-width: 860px) {
    .servizio-griglia { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.blocchi { margin-top: var(--s-3); }
.blocchi > div { padding: var(--s-3) 0; border-top: 1px solid var(--filo); }
.blocchi > div:last-child { padding-bottom: 0; }
.blocco-nome {
    margin: 0 0 4px;
    font-weight: 600;
    color: var(--bosco);
}
.blocchi p:last-child { margin-bottom: 0; }
.blocchi p { max-width: var(--riga); }

/* L'emergenza si distingue dal resto: bordo marcato, nessun colore urlato */
.urgenza {
    border: 1px solid var(--stato-verifica) !important;
    border-radius: var(--raggio);
    padding: var(--s-3) !important;
    background: var(--carta);
}
.urgenza .numero {
    display: block;
    margin: var(--s-1) 0;
    font-size: var(--t-h2);
    font-weight: 600;
    line-height: 1;
    letter-spacing: -0.02em;
    color: var(--inchiostro);
}

/* --------------------------------------------------------------- 7. CODA */
.coda .cerca { margin-top: var(--s-5); }
.coda .cerca-campo { border-color: rgba(255, 255, 255, 0.5); }
.coda .cerca-etichetta, .coda .cerca-nota { color: rgba(255, 255, 255, 0.86); }
.metodo-nota {
    margin-top: var(--s-2);
    font-size: var(--t-etichetta);
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.86);
    max-width: 78ch;
}
.coda-chiusa {
    margin-top: var(--s-5);
    padding-top: var(--s-3);
    border-top: 1px solid rgba(255, 255, 255, 0.28);
    font-size: var(--t-etichetta);
    color: rgba(255, 255, 255, 0.86);
    max-width: var(--riga);
}
</style>
@endpush

@section('contenuto')
    @php
        // La segnalazione è una lettera all'ente, come nella scheda: il portale
        // non raccoglie moduli, non avendo né sessione né cookie. L'indirizzo
        // si ricalcola qui perché le sezioni del figlio sono già composte
        // quando il layout definisce le proprie variabili.
        $posta = $portale->contactEmail();
        $urlSegnala = $posta === null ? null : 'mailto:'.$posta.'?'.http_build_query(
            ['subject' => 'Segnalazione dal portale del verde di '.$portale->name()],
            '', '&', PHP_QUERY_RFC3986
        );

        $numero = fn (int $valore) => number_format($valore, 0, ',', '.');

        // "Bastano le cifre" è vero solo dove il committente ha un prefisso di
        // etichetta: è quello che PortalSearch antepone al numero letto sul
        // cartellino. Senza prefisso il codice va scritto per intero, e la
        // pagina non deve promettere una scorciatoia che non esiste.
        $soloCifre = trim((string) ($portale->client->label_prefix ?? '')) !== '';
        $esempioCartellino = $soloCifre ? '0427' : 'codice';

        $elementi = (int) ($statistiche['elementi'] ?? 0);
        $alberi = (int) ($statistiche['alberi'] ?? 0);

        // Un numero a zero, in pubblico, non informa: sembra una mancanza
        // dell'ente invece di un dato non ancora raccolto. Le etichette vanno
        // al singolare quando il numero è uno: "1 alberi curati" è il genere
        // di sciatteria che si nota subito.
        $voci = [
            [
                'valore' => $elementi,
                'uno' => 'Elemento censito',
                'molti' => 'Elementi censiti',
                'glossa' => 'tutto ciò che il censimento ha rilevato sul territorio: alberi singoli, aree verdi e gli altri elementi in gestione.',
            ],
            [
                'valore' => $alberi,
                'uno' => 'Albero',
                'molti' => 'Alberi',
                'glossa' => 'gli elementi che sono piante singole, ognuna con la propria scheda botanica.',
            ],
            [
                'valore' => (int) ($statistiche['varieta'] ?? 0),
                'uno' => 'Varietà botanica',
                'molti' => 'Varietà botaniche',
                'glossa' => 'specie diverse presenti nel patrimonio: più sono, meno il verde è esposto a una sola malattia.',
            ],
            [
                'valore' => (int) ($statistiche['curati'] ?? 0),
                'uno' => 'Albero curato',
                'molti' => 'Alberi curati',
                'glossa' => 'piante su cui è stato eseguito e concluso almeno un intervento di manutenzione.',
            ],
            [
                'valore' => (int) ($statistiche['potati'] ?? 0),
                'uno' => 'Albero potato',
                'molti' => 'Alberi potati',
                'glossa' => 'piante su cui è stata eseguita almeno una potatura registrata.',
            ],
        ];
        $voci = array_values(array_filter($voci, fn ($v) => $v['valore'] > 0));

        // Che cosa vuol dire ognuno dei quattro stati. Le parole descrivono
        // esattamente la regola scritta in PortalState::sql(), non una
        // promessa di servizio: qui non si dichiarano tempi che il Comune non
        // ha dichiarato.
        $spiegaStato = [
            'sano' => "All'ultimo controllo non sono emersi problemi. L'elemento segue la normale manutenzione: fino al controllo successivo non è previsto altro.",
            'cura' => "C'è un lavoro in corso o già assegnato a una squadra. Non vuol dire che la pianta sia malata: anche una manutenzione ordinaria resta segnata così finché il lavoro non è chiuso.",
            'potare' => 'È prevista una potatura: o è già stata disposta con un ordine di lavoro, oppure è stata prescritta dall\'ultimo controllo.',
            'verifica' => "L'ultimo controllo ha lasciato un dubbio e l'elemento è tornato all'esame di un tecnico. Non vuol dire che sia pericoloso: vuol dire che prima di decidere si accerta.",
        ];

        // I conteggi per stato arrivano dalla stessa cache dei numeri
        // (PortalStats). Il controllo non è per scaramanzia: la pagina si
        // regge anche su un risultato senza questa voce, e allora restano le
        // sole spiegazioni dei quattro stati, senza barra.
        $stati = is_array($statistiche['stati'] ?? null) ? $statistiche['stati'] : [];
        $sommaStati = array_sum($stati);
        // La barra mostra quanto pesa ogni stato: vale a quattro elementi come
        // a quarantamila, e non fa finta che il disegno sia il dato.
        $fascia = $sommaStati > 0 ? array_filter($stati, fn ($quanti) => $quanti > 0) : [];

        $conCo2 = $portale->mostraCo2() && ($statistiche['co2']['alberi'] ?? 0) > 0;
        // Gli altri benefici hanno il loro interruttore, spento di suo: sono
        // stime nuove, e il consenso dato per la CO2 non vale anche per loro
        $beneficiVoci = $portale->mostraBenefici() ? ($statistiche['benefici']['voci'] ?? []) : [];
        $conEuro = $conCo2
            && ($statistiche['co2']['euro'] ?? null) !== null
            && ($statistiche['co2']['prezzo'] ?? null) !== null;

        // I numeri grandi sotto l'apertura: i primi tre conteggi (elementi,
        // alberi, varieta') e, se il Comune l'ha accesa, la stima della CO2
        // come quarto riquadro; altrimenti il quarto e' il conteggio
        // successivo. Quello che sale nei riquadri non si ripete piu' sotto.
        $tessere = [];
        foreach ($voci as $indice => $voce) {
            if (count($tessere) >= ($conCo2 ? 3 : 4)) {
                break;
            }
            $tessere[] = $voce;
            unset($voci[$indice]);
        }
        $voci = array_values($voci);

        $conCopertina = $portale->hasCover();

        // La data che si mostra è quella dell'ULTIMO RILIEVO registrato, non
        // quella di oggi: "aggiornato al" deve dire da quando il registro non
        // si muove, altrimenti un censimento fermo da tre anni si presenterebbe
        // come aggiornato stamattina. Dove nessun elemento ha una data di
        // rilievo non si scrive niente.
        $aggiornato = $statistiche['ultimo_rilievo'] ?? null;
        $aggiornato = $aggiornato ? \Illuminate\Support\Carbon::parse($aggiornato)->format('d/m/Y') : null;
    @endphp

    {{-- ============================================== 1. LA RICERCA --}}
    {{-- Con la fotografia di copertina caricata dal Comune l'apertura diventa
         la sua veduta; senza, resta su fondo chiaro. La struttura e' la
         stessa: il campo del cartellino viene prima di tutto il resto. --}}
    <section class="apertura sc-sezione {{ $conCopertina ? 'apertura-foto' : 'sc-carta' }}"
        @if ($conCopertina) style="background-image: url('{{ $portale->url('/copertina') }}')" @endif>
        <div class="sc-contenitore">
            <div class="apertura-testo">
            {{-- Il nome dell'ente fa da titolo, come in testa a un atto. La
                 versione precedente lo infilava dentro una frase ("Il verde di
                 ...") e con i nomi veri usciva storta: "Il verde di Comune di
                 Mentana". Il nome non si declina: si stampa. --}}
            <p class="sc-occhiello">Censimento del verde pubblico</p>
            <h1 class="sc-h1 apertura-titolo">{{ $portale->name() }}</h1>

            <p class="apertura-benvenuto">{{ $portale->welcomeText()
                ?: 'Ogni albero del territorio ha una scheda con la specie, le misure e gli interventi eseguiti. Si consulta dalla mappa oppure cercando il numero riportato sul cartellino.' }}</p>
            </div>

            <div class="apertura-cerca">
            {{-- L'azione principale della pagina, nel primo schermo: il numero
                 letto sul cartellino applicato alla pianta --}}
            <form class="cerca" method="get" action="{{ $portale->url('/cerca') }}" role="search">
                <label class="cerca-etichetta" for="cartellino">Numero del cartellino</label>
                <div class="cerca-riga">
                    <input
                        class="cerca-campo sc-num"
                        id="cartellino"
                        type="search"
                        placeholder="{{ $esempioCartellino }}"
                        @if ($soloCifre) inputmode="numeric" @endif
                        name="etichetta"
                        value="{{ $cercato ?? '' }}"
                        maxlength="80"
                        autocomplete="off"
                    >
                    <button class="sc-bottone cerca-invio" type="submit">Cerca l'albero</button>
                </div>
                <p class="cerca-nota">Il numero è stampato sul cartellino applicato all'albero:
                    {{ $soloCifre ? 'bastano le cifre, senza spazi.' : 'si scrive per intero, come sta sul cartellino.' }}</p>
            </form>

            @if ($nonTrovato)
                <p class="avviso" role="status">
                    Nessun elemento trovato con l'etichetta &laquo;{{ $cercato }}&raquo;.
                    Controlla il numero riportato sul cartellino{{ $soloCifre ? ': si scrive anche solo con le cifre.' : '.' }}
                </p>
            @endif
            </div>

            {{-- Le altre due strade, sotto il campo --}}
            <div class="vie apertura-vie">
                <a href="{{ $conCopertina ? $portale->url('/mappa') : '#mappa' }}">{{ $conCopertina ? 'Apri la mappa del verde' : 'Oppure guarda la mappa del verde' }}</a>
                @if ($urlSegnala)
                    <a href="#segnalare">Segnala un problema</a>
                @endif
            </div>
        </div>
    </section>

    {{-- ========================================= 1b. I NUMERI GRANDI --}}
    @if ($tessere !== [] || $conCo2)
        <section class="numeri sc-avorio{{ $conCopertina ? ' numeri-sopra' : '' }}" aria-label="Il patrimonio in numeri">
            <div class="sc-contenitore">
                <ul class="tessere" style="--tessere: {{ count($tessere) + ($conCo2 ? 1 : 0) }}">
                    @foreach ($tessere as $voce)
                        <li class="tessera">
                            <span class="tessera-cifra sc-num">{{ $numero($voce['valore']) }}</span>
                            <span class="tessera-nome">{{ $voce['valore'] === 1 ? $voce['uno'] : $voce['molti'] }}</span>
                            <span class="tessera-glossa">{{ $voce['glossa'] }}</span>
                        </li>
                    @endforeach
                    @if ($conCo2)
                        <li class="tessera">
                            <span class="tessera-cifra sc-num">{{ number_format($statistiche['co2']['kg'] / 1000, 1, ',', '.') }}<span class="tessera-unita">t</span><a class="sc-ast" href="#nota-metodo">*</a></span>
                            <span class="tessera-nome">Anidride carbonica immagazzinata</span>
                            <span class="tessera-glossa">stima sugli alberi di cui e' noto il diametro del tronco: il metodo e' in fondo alla pagina.</span>
                        </li>
                    @endif
                </ul>
            </div>
        </section>
    @endif

    {{-- ================================================ 2. LA MAPPA --}}
    <section class="sezione-mappa sc-avorio sc-sezione" id="mappa">
        <div class="sc-contenitore">
            <div class="sc-testa-sezione">
                <p class="sc-occhiello sc-occhiello-ente">La mappa pubblica</p>
                <span class="sc-filo"></span>
            </div>
            <h2 class="sc-h2">Il verde visto dall'alto</h2>
            <p class="sc-guida">
                Ogni elemento censito è al suo posto, con il colore del proprio stato.
                Toccando un punto si apre la scheda: specie, misure, controlli e lavori registrati.
            </p>

            @if ($estensione && count($sfondi))
                <a class="anteprima" href="{{ $portale->url('/mappa') }}" aria-label="Apri la mappa del verde">
                    <div id="anteprima-mappa"></div>
                    <span class="invito sc-bottone">Apri la mappa &rarr;</span>
                </a>

                <ul class="legenda">
                    @foreach (\App\Services\Portale\PortalState::ETICHETTE as $codice => $etichetta)
                        <li><span class="sc-pallino" style="--c: var(--stato-{{ $codice }})"></span>{{ $etichetta }}</li>
                    @endforeach
                </ul>

                @php
                    $datiMappa = [
                        'anteprima' => true,
                        'urlTile' => $portale->url('/mappa').'/{z}/{x}/{y}.pbf',
                        'urlElemento' => $portale->url('/elemento'),
                        'estensione' => $estensione,
                        'centro' => [
                            ($estensione['sw'][0] + $estensione['ne'][0]) / 2,
                            ($estensione['sw'][1] + $estensione['ne'][1]) / 2,
                        ],
                        'zoom' => 15,
                        'sfondi' => $sfondi,
                        'colori' => \App\Services\Portale\PortalState::COLORI,
                        'apri' => null,
                    ];
                @endphp

                <script type="application/json" id="dati-portale">{!! json_encode($datiMappa, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
                <script>
                    window.__portale = JSON.parse(document.getElementById('dati-portale').textContent);
                </script>
            @else
                <p class="mappa-assente">La mappa sarà disponibile appena il censimento avrà i primi elementi rilevati sul territorio.</p>
            @endif
        </div>
    </section>

    {{-- ===================================== 3. COME STA IL VERDE --}}
    <section class="censito sc-carta sc-sezione" id="come-sta">
        <div class="sc-contenitore">
            <div class="sc-testa-sezione">
                <p class="sc-occhiello sc-occhiello-ente">Come sta il verde censito</p>
                <span class="sc-filo"></span>
            </div>

            @if ($elementi > 0)
                <h2 class="sc-h2">{{ $numero($elementi) }} {{ $elementi === 1 ? 'elemento censito, con il suo stato' : 'elementi censiti, ognuno con il suo stato' }}</h2>
            @else
                <h2 class="sc-h2">Il censimento è cominciato</h2>
            @endif

            @if ($aggiornato)
                {{-- la data dice fin dove arriva il registro: è quella del
                     rilievo più recente fra gli elementi pubblicati --}}
                <p class="sc-nota censito-data">Ultimo rilievo registrato il <span class="sc-num">{{ $aggiornato }}</span>.</p>
            @endif

            @if ($fascia !== [])
                <div class="fascia" aria-hidden="true">
                    @foreach ($fascia as $stato => $quanti)
                        <span style="--c: var(--stato-{{ $stato }}); flex-grow: {{ $quanti }}"></span>
                    @endforeach
                </div>
            @endif

            <ul class="stati">
                @foreach (\App\Services\Portale\PortalState::ETICHETTE as $codice => $etichetta)
                    <li class="stato-voce">
                        <span class="stato-nome">
                            <span class="sc-pallino" style="--c: var(--stato-{{ $codice }})"></span>{{ $etichetta }}
                            @if (($stati[$codice] ?? 0) > 0)
                                <span class="stato-quanti sc-num">{{ $numero($stati[$codice]) }}</span>
                            @endif
                        </span>
                        <p>{{ $spiegaStato[$codice] }}</p>
                    </li>
                @endforeach
            </ul>

            @if ($voci !== [])
                <ul class="voci">
                    @foreach ($voci as $voce)
                        <li>
                            <span class="voce-cifra sc-num">{{ $numero($voce['valore']) }}</span>
                            <span class="voce-nome">
                                <b>{{ $voce['valore'] === 1 ? $voce['uno'] : $voce['molti'] }}</b>
                                <span>{{ $voce['glossa'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @elseif ($elementi === 0)
                {{-- Solo quando non c'e' davvero niente: con pochi elementi i
                     conteggi salgono tutti nei riquadri e questa lista resta
                     vuota, ma il rilievo non e' "in corso" --}}
                <p class="sc-nota" style="margin-top: var(--s-5)">
                    Il rilievo sul territorio è in corso: i primi elementi compariranno qui appena saranno registrati.
                </p>
            @endif
        </div>
    </section>

    {{-- ================================ 4. I BENEFICI, SE ACCESI --}}
    @if ($conCo2 || count($beneficiVoci))
        <section class="benefici sc-avorio sc-sezione">
            <div class="sc-contenitore">
                <div class="sc-testa-sezione">
                    <p class="sc-occhiello sc-occhiello-ente">Benefici ambientali, stimati</p>
                    <span class="sc-filo"></span>
                </div>
                <h2 class="sc-h2">Che cosa fa questo patrimonio in un anno</h2>

                @if ($conCo2)
                    {{-- L'anidride carbonica fa da stima guida: è quella che si
                         cita, ed è l'unica che non si riferisce a un anno ma a
                         tutta la vita della pianta --}}
                    <div class="stima-guida">
                        <p class="valore sc-num" style="margin: 0;">{{ number_format($statistiche['co2']['kg'] / 1000, 1, ',', '.') }}<span class="unita">t</span></p>
                        <p class="spiega">Anidride carbonica immagazzinata dal patrimonio arboreo nel corso della sua crescita<a class="sc-ast" href="#nota-metodo">*</a></p>
                    </div>

                    {{-- La riga in euro esce solo dove un prezzo per tonnellata
                         è configurato. Prezzo e fonte viaggiano nello stesso
                         risultato in cache del numero, così la nota dichiara il
                         prezzo con cui il valore è stato davvero calcolato --}}
                    @if ($conEuro)
                        <p class="stima-euro">
                            Controvalore economico stimato di quelle tonnellate sul mercato delle quote di
                            emissione<a class="sc-ast" href="#nota-metodo">*</a>:
                            <b class="sc-num">{{ number_format($statistiche['co2']['euro'], 0, ',', '.') }} euro</b>.
                            Non è il valore degli alberi.
                        </p>
                    @endif
                @endif

                {{-- Gli altri benefici del patrimonio: ossigeno, polveri
                     trattenute, pioggia intercettata. Le voci le compone il
                     servizio (etichetta, valore, unità): qui si stampano --}}
                @if (count($beneficiVoci))
                    <div class="stime">
                        @foreach ($beneficiVoci as $voce)
                            <div class="stima">
                                <p class="stima-valore sc-num">{{ number_format($voce['valore'], $voce['valore'] < 10 ? 1 : 0, ',', '.') }}<span class="stima-unita">{{ $voce['unita'] }}</span></p>
                                <p class="stima-nome">{{ $voce['etichetta'] }} in un anno, su {{ number_format($voce['alberi'], 0, ',', '.') }} {{ $voce['alberi'] === 1 ? 'albero' : 'alberi' }}<a class="sc-ast" href="#nota-metodo">*</a></p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ======================================== 5. CHE COSA SI MISURA --}}
    <section class="misure sc-carta sc-sezione" id="misure">
        <div class="sc-contenitore misure-griglia">
            <div class="misure-testo">
                <div class="sc-testa-sezione">
                    <p class="sc-occhiello sc-occhiello-ente">Che cosa si misura</p>
                    <span class="sc-filo"></span>
                </div>
                <h2 class="sc-h2">Lo stesso albero, misurato sempre allo stesso modo</h2>
                <p class="sc-guida">
                    Il tecnico non guarda la pianta a occhio: la misura. Sono le stesse quote per tutti
                    gli alberi del censimento, ed è per questo che si possono confrontare fra loro e negli anni.
                </p>

                <ol class="quote">
                    <li>
                        <span>
                            <b>Altezza totale</b>
                            <span>Dal piede della pianta alla cima della chioma, in metri.</span>
                        </span>
                    </li>
                    <li>
                        <span>
                            <b>Proiezione della chioma</b>
                            <span>Quanto terreno copre la chioma: è la stessa misura che permette di confrontare due piante.</span>
                        </span>
                    </li>
                    <li>
                        <span>
                            <b>Diametro del tronco</b>
                            <span>Misurato a 1,30 m da terra, l'altezza convenzionale usata da ogni censimento: è quella che rende confrontabili le misure.</span>
                        </span>
                    </li>
                </ol>
            </div>

            {{-- Tavola delle quote. È un disegno tecnico: linee, quote e i tre
                 numeri di richiamo dell'elenco qui accanto. Non rappresenta una
                 pianta vera e non prova a somigliare a una fotografia. --}}
            <figure class="misure-tavola" style="margin: 0;">
                <svg class="tavola" viewBox="0 0 360 306" role="img"
                     aria-label="Disegno con le tre quote rilevate su un albero: altezza totale, proiezione della chioma e diametro del tronco misurato a 1,30 metri da terra.">
                    <defs>
                        <marker id="tv-punta" viewBox="0 0 10 10" refX="9" refY="5"
                                markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path class="tavola-punta" d="M0 0 L10 5 L0 10 z"/>
                        </marker>
                    </defs>

                    {{-- la pianta: chioma e fusto, di sola linea --}}
                    <ellipse class="tavola-pianta" cx="202" cy="118" rx="82" ry="64"/>
                    <path class="tavola-pianta" d="M194 262 L196 168 L208 168 L210 262 Z"/>
                    <line class="tavola-linea" x1="40" y1="262" x2="336" y2="262"/>

                    {{-- 1. altezza totale: da terra alla cima della chioma --}}
                    <line class="tavola-aiuto" x1="64" y1="54" x2="202" y2="54"/>
                    <line class="tavola-quota" x1="64" y1="54" x2="64" y2="262"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>
                    <circle class="tavola-numero" cx="64" cy="158" r="13"/>
                    <text class="tavola-cifra" x="64" y="158">1</text>

                    {{-- 2. proiezione della chioma: la sua larghezza a terra --}}
                    <line class="tavola-aiuto" x1="120" y1="118" x2="120" y2="294"/>
                    <line class="tavola-aiuto" x1="284" y1="118" x2="284" y2="294"/>
                    <line class="tavola-quota" x1="120" y1="290" x2="284" y2="290"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>
                    <circle class="tavola-numero" cx="202" cy="290" r="13"/>
                    <text class="tavola-cifra" x="202" y="290">2</text>

                    {{-- 3. diametro del tronco, preso a 1,30 m da terra --}}
                    <line class="tavola-quota" x1="176" y1="228" x2="228" y2="228"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>
                    <line class="tavola-aiuto" x1="228" y1="228" x2="272" y2="228"/>
                    <circle class="tavola-numero" cx="285" cy="228" r="13"/>
                    <text class="tavola-cifra" x="285" y="228">3</text>
                    <line class="tavola-aiuto" x1="298" y1="228" x2="344" y2="228"/>
                    <line class="tavola-quota" x1="344" y1="228" x2="344" y2="262"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>
                    <text class="tavola-misura" x="338" y="246">1,30 m</text>
                </svg>
            </figure>
        </div>
    </section>

    {{-- ================================ 6. COME LAVORIAMO / SEGNALARE --}}
    <section class="servizio sc-avorio sc-sezione" id="come-lavoriamo">
        <div class="sc-contenitore">
            <div class="sc-testa-sezione">
                <p class="sc-occhiello sc-occhiello-ente">Chi controlla, che cosa cambia lo stato, a chi si scrive</p>
                <span class="sc-filo"></span>
            </div>

            <div class="servizio-griglia">
                <div>
                    <h2 class="sc-h2">Come lavoriamo</h2>
                    <div class="blocchi">
                        <div>
                            <p class="blocco-nome">Che cosa c'è in questo registro</p>
                            <p>Ogni elemento censito ha una scheda con la specie, le misure rilevate sul posto,
                               lo stato e gli interventi registrati. Sono le stesse schede che usano i tecnici:
                               qui se ne vede la parte pubblica.</p>
                        </div>
                        <div>
                            <p class="blocco-nome">Chi controlla</p>
                            <p>I controlli sono eseguiti da tecnici incaricati dall'amministrazione e restano
                               registrati con la data del sopralluogo. Quando è stata programmata, anche la data
                               della prossima verifica è scritta nella scheda dell'elemento.</p>
                        </div>
                        <div>
                            <p class="blocco-nome">Come cambia lo stato</p>
                            <p>Lo stato non è un giudizio scritto a mano: cambia da sé dopo ogni controllo e alla
                               chiusura di ogni lavoro. È la conseguenza di quello che risulta agli atti.</p>
                        </div>
                        <div>
                            <p class="blocco-nome">Chi decide, e dove si legge</p>
                            <p>Le potature e gli abbattimenti sono decisi dall'amministrazione. Quando l'atto è
                               pubblico, il collegamento all'atto sta nella scheda dell'elemento: si legge senza
                               dover chiedere niente a nessuno.</p>
                        </div>
                    </div>
                </div>

                <div id="segnalare">
                    <h2 class="sc-h2">Segnalare un problema</h2>
                    <div class="blocchi">
                        <div class="urgenza">
                            <p class="blocco-nome">Pericolo immediato</p>
                            <p>Albero o ramo caduto, pianta inclinata sulla strada, danni dopo un temporale:
                               chiamare il numero unico di emergenza.</p>
                            <span class="numero sc-num">112</span>
                            {{-- Nessuna promessa sugli orari dell'ufficio, che questo programma
                                 non conosce: si dice solo di non affidare un'emergenza alla posta --}}
                            <p>La posta elettronica non è un canale di emergenza: potrebbe essere letta
                               solo nei giorni e negli orari di ufficio.</p>
                        </div>
                        <div>
                            <p class="blocco-nome">Segnalazione ordinaria</p>
                            @php $telefonoEnte = $portale->contactPhone(); @endphp
                            @if ($posta || $telefonoEnte)
                                {{-- Posta e telefono escono solo se l'ente li ha scritti: con
                                     tutti e due, "scrivere ... oppure telefonare ..." --}}
                                <p>Ramo basso, ceppaia, radice che solleva il marciapiede, pianta che sembra
                                   sofferente:
                                   @if ($posta)
                                       scrivere a <a class="sc-collegamento" href="{{ $urlSegnala }}">{{ $posta }}</a>@if ($telefonoEnte) oppure @endif
                                   @endif
                                   @if ($telefonoEnte)
                                       telefonare al <a class="sc-collegamento sc-num" href="{{ $portale->contactPhoneHref() }}">{{ $telefonoEnte }}</a>
                                       negli orari dell'ufficio
                                   @endif
                                   indicando la via e, se c'è, il numero del cartellino.</p>
                            @else
                                <p>Ramo basso, ceppaia, radice che solleva il marciapiede, pianta che sembra
                                   sofferente: la segnalazione va fatta agli uffici dell'ente, indicando la via
                                   e, se c'è, il numero del cartellino. Su questo portale non è ancora pubblicato
                                   un indirizzo a cui scrivere.</p>
                            @endif
                        </div>
                        <div>
                            <p class="blocco-nome">Che cosa succede dopo</p>
                            <p>La segnalazione arriva all'ufficio che si occupa del verde. Se porta a un
                               intervento, l'intervento compare nella scheda dell'elemento quando viene
                               registrato. I tempi di risposta sono quelli stabiliti dall'ente: questo portale
                               non ne dichiara di propri.</p>
                        </div>
                        <div>
                            <p class="blocco-nome">Come sono trattati i dati</p>
                            <p>Questo portale non ha moduli da compilare e non deposita cookie: la segnalazione
                               è una normale lettera di posta elettronica indirizzata all'ente.
                               <a class="sc-collegamento" href="{{ $portale->url('/privacy') }}">Privacy e note legali</a>.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================= 7. NOTA SUL METODO E CHIUSURA --}}
    <section class="coda sc-scuro sc-sezione">
        <div class="sc-contenitore">
            @if ($conCo2 || count($beneficiVoci))
                <div id="nota-metodo">
                    <p class="sc-occhiello sc-occhiello-luce">Nota sul metodo</p>
                    <h2 class="sc-h3">Da dove vengono i valori segnati con l'asterisco</h2>
                    <p class="metodo-nota">
                        <sup>*</sup> Valori stimati, non misurati.
                        @if ($conCo2)
                            L'anidride carbonica è calcolata
                            @if ($statistiche['co2']['alberi'] === 1)
                                su un albero di cui è noto
                            @else
                                su {{ number_format($statistiche['co2']['alberi'], 0, ',', '.') }} alberi di cui è noto
                            @endif
                            il diametro del tronco: {{ config('co2.modello') }}.
                            @if ($conEuro)
                                Il controvalore economico applica un prezzo di
                                {{ number_format($statistiche['co2']['prezzo'], fmod($statistiche['co2']['prezzo'], 1.0) == 0.0 ? 0 : 2, ',', '.') }} euro
                                per tonnellata di anidride carbonica ({{ $statistiche['co2']['fonte'] }}).
                            @endif
                        @endif
                        @if (count($beneficiVoci))
                            Gli altri benefici sono calcolati sugli alberi che hanno il dato
                            necessario - l'età per l'ossigeno, il diametro della chioma per
                            polveri e pioggia - e ogni voce dice su quanti alberi:
                            {{ $statistiche['benefici']['metodo'] }}.
                        @endif
                    </p>
                </div>
            @endif

            {{-- Prima di chiudere, la pagina ripropone la sola cosa da fare:
                 chi è arrivato in fondo leggendo non deve risalire per cercare --}}
            <form class="cerca" method="get" action="{{ $portale->url('/cerca') }}" role="search">
                <label class="cerca-etichetta" for="cartellino-coda">Numero del cartellino</label>
                <div class="cerca-riga">
                    <input
                        class="cerca-campo sc-num"
                        id="cartellino-coda"
                        type="search"
                        placeholder="{{ $esempioCartellino }}"
                        @if ($soloCifre) inputmode="numeric" @endif
                        name="etichetta"
                        maxlength="80"
                        autocomplete="off"
                    >
                    <button class="sc-bottone-chiaro cerca-invio" type="submit">Cerca l'albero</button>
                </div>
                <p class="cerca-nota">{{ $soloCifre
                    ? 'Bastano le cifre lette sul cartellino, senza spazi.'
                    : 'Il codice letto sul cartellino, scritto per intero.' }}</p>
            </form>

            <p class="coda-chiusa">
                Il censimento non è mai finito: le schede si aggiornano a ogni controllo e a ogni lavoro
                concluso. Quello che si legge qui è lo stesso registro su cui lavorano i tecnici.
            </p>
        </div>
    </section>
@endsection
