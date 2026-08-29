@extends('portale.layout')

@section('titolo', 'Censimento del verde')

{{-- La home è a tutta larghezza: la copertina, la fascia degli stati e
     l'anteprima della mappa sono fasce orizzontali, non una colonna di testo --}}
@section('classe-pagina', 'pagina-piena')

@push('stile')
@if ($estensione && count($sfondi))
    @vite(['resources/js/portale-mappa.js'])
@endif
<style>
/* ==========================================================================
   HOME — "Sotto la chioma"
   Colori, corpi, spazi e componenti stanno tutti nel layout: qui si scrive
   solo l'impianto delle sei fasce e i due disegni che sono di questa pagina.
   ========================================================================== */

/* Gli occhielli sono paragrafi: senza questo si portano dietro il margine
   di fine capoverso e il filetto della testata di sezione non resta a filo */
p.sc-occhiello { margin: 0; }

/* ------------------------------------------------------------- copertina
   La testata scura del layout e questa fascia hanno lo stesso fondo: la
   pagina comincia con un unico blocco di notte in cui si apre il cielo del
   disegno. Il testo NON sta mai sopra l'illustrazione: il disegno finisce
   nella notte piena e il testo comincia lì sotto. È l'unico modo di
   garantire il contrasto con qualunque colore scelga il Comune, e di
   reggere un testo di benvenuto lungo il doppio senza che si sfondi. */
.copertina { position: relative; overflow: hidden; }
.copertina-figura {
    display: block;
    width: 100%;
    /* il rapporto è quello del viewBox: così il disegno non si ritaglia mai
       nel caso normale. Il minimo tiene la fascia visibile sul telefono, il
       massimo le impedisce di mangiarsi lo schermo su un monitor largo */
    aspect-ratio: 1440 / 460;
    min-height: 170px;
    max-height: 440px;
}
.copertina-figura svg { display: block; width: 100%; height: 100%; }
.copertina-dentro { padding-top: var(--s-5); padding-bottom: var(--s-7); }
.copertina-titolo { color: #fff; margin-top: var(--s-2); max-width: 18ch; }
.copertina-benvenuto {
    margin: var(--s-4) 0 0;
    max-width: 58ch;
    font-size: var(--t-guida);
    line-height: 1.5;
    color: var(--chiaro);
    /* il testo del Comune arriva com'è stato scritto, a capo compresi */
    white-space: pre-line;
    text-wrap: pretty;
}

/* ---- il numero del cartellino: è l'azione per cui il cittadino arriva qui,
   e nella vecchia home era un campo piccolo fra gli altri. Niente scatola:
   un filo d'oro da bordo a bordo, come l'incisione sulla targhetta.
   Il corpo è quello di un titolo di sezione, non i 120px della bozza: a
   360px un campo da 120px mostrerebbe quattro cifre e nient'altro. */
.cerca { margin-top: var(--s-6); max-width: 760px; }
.cerca-etichetta {
    display: block;
    font-size: var(--t-occhiello);
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--oro-chiaro);
}
.cerca-campo {
    display: block;
    width: 100%;
    margin-top: var(--s-1);
    min-height: 56px;
    padding: 0 0 10px;
    border: 0;
    border-bottom: 2px solid var(--oro-chiaro);
    border-radius: 0;
    background: transparent;
    color: #fff;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--t-h2);
    line-height: 1.12;
    letter-spacing: 0.02em;
    font-variant-numeric: tabular-nums lining-nums;
    -webkit-appearance: none;
    appearance: none;
}
.cerca-campo:focus { border-bottom-color: #fff; }
/* L'esempio dentro al campo: --chiaro perche' la tavolozza lo garantisce
   leggibile sulla notte con qualunque colore scelga il Comune */
.cerca-campo::placeholder { color: var(--chiaro); opacity: 1; letter-spacing: 0.06em; }
.cerca-azioni {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--s-2) var(--s-5);
    margin-top: var(--s-3);
}
.cerca-invio { flex: none; }
.cerca-nota {
    flex: 1 1 280px;
    margin: 0;
    font-size: var(--t-etichetta);
    line-height: 1.62;
    color: var(--chiaro);
}
.vie { display: flex; flex-wrap: wrap; gap: 0 var(--s-5); margin-top: var(--s-2); }
.vie a {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    font-size: var(--t-etichetta);
    text-decoration: none;
    color: var(--chiaro);
    border-bottom: 1px solid var(--chiaro-2);
    align-self: flex-start;
}
.vie a:hover { color: #fff; border-bottom-color: #fff; }
.avviso {
    max-width: 62ch;
    margin: var(--s-3) 0 0;
    padding: var(--s-2) var(--s-3);
    border-left: 3px solid var(--oro-chiaro);
    background: var(--notte-fondo);
    color: var(--chiaro);
    font-size: var(--t-etichetta);
    line-height: 1.62;
}

/* -------------------------------------------------- che cosa si misura */
.misure-griglia { display: grid; gap: var(--s-6); align-items: start; }
@media (min-width: 900px) {
    .misure-griglia { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--s-7); }
}
.misure-testo h2 { margin-top: var(--s-2); }
.misure-testo .sc-guida { margin-top: var(--s-3); }
.misure-testo .sc-nota {
    margin-top: var(--s-3);
    padding-top: var(--s-3);
    border-top: 1px solid var(--filo);
}
.tavola { display: block; width: 100%; max-width: 520px; height: auto; margin: 0 auto; }
.quote { list-style: none; margin: var(--s-5) 0 0; padding: 0; }
/* La quota si spiega qui, in HTML, non dentro il disegno: una scritta dentro
   l'SVG si rimpicciolisce insieme al disegno e a 360px finirebbe sotto i
   13px. Il segno a sinistra ripete il tratto usato nella tavola, ed è quello
   che lega la voce alla sua quota. */
.quota {
    display: grid;
    grid-template-columns: 26px minmax(0, 1fr);
    gap: var(--s-2);
    align-items: start;
    padding: var(--s-2) 0;
    border-top: 1px solid var(--filo);
}
.quota:last-child { border-bottom: 1px solid var(--filo); }
.quota-segno { width: 26px; height: 32px; flex: none; }
.quota-nome {
    display: block;
    font-size: var(--t-etichetta);
    font-weight: 500;
    line-height: 1.35;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--inchiostro);
}
.quota-glossa {
    display: block;
    margin-top: 4px;
    font-size: var(--t-etichetta);
    line-height: 1.62;
    color: var(--inchiostro-2);
}

/* ------------------------------------------------- come sta il verde */
.censito h2 { margin-top: var(--s-3); }
.censito-data { margin-top: var(--s-2); }

/* Un alberello per elemento, del colore del proprio stato. Nella bozza sono
   una fila lungo una strada: qui la fila va a capo, perché a 360px ventisei
   sagome in una riga sola sarebbero larghe cinque pixel l'una. La posizione
   non dice niente (non è una mappa), la quantità sì. */
.filare {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px 6px;
    margin: var(--s-5) 0 0;
}
.alberello { display: block; width: clamp(20px, 2.4vw, 30px); }
.alberello svg { display: block; width: 100%; height: auto; }
.alberello .sagoma { fill: var(--c); }
.alberello .ombra { fill: var(--inchiostro); opacity: 0.12; }

/* Oltre il tetto degli alberelli le sagome diventerebbero un tappeto
   illeggibile: si passa alla fascia proporzionale, e le quantità si leggono
   scritte nell'elenco qui sotto. */
.fascia {
    display: flex;
    height: 28px;
    margin: var(--s-5) 0 0;
    border-radius: var(--raggio);
    overflow: hidden;
}
.fascia > span { flex-basis: 0; min-width: 10px; background: var(--c); }

.stati {
    display: grid;
    gap: var(--s-4) var(--s-6);
    margin: var(--s-5) 0 0;
    padding-top: var(--s-4);
    border-top: 1px solid var(--filo);
    list-style: none;
}
@media (min-width: 760px) { .stati { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.stato-nome {
    display: flex;
    align-items: center;
    gap: var(--s-1);
    font-size: var(--t-etichetta);
    font-weight: 500;
    line-height: 1.35;
    color: var(--inchiostro);
}
.stato-quanti {
    margin-left: auto;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--t-h3);
    line-height: 1;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}
.stato-voce p { margin: var(--s-1) 0 0; font-size: var(--t-etichetta); line-height: 1.62; color: var(--inchiostro-2); }

/* I numeri del patrimonio: non sei riquadri uguali, ma un elenco quotato —
   la cifra a destra della sua colonna, il nome e la glossa accanto. */
.voci { list-style: none; margin: var(--s-6) 0 0; padding: 0; }
.voci li {
    display: grid;
    grid-template-columns: minmax(64px, 92px) minmax(0, 1fr);
    gap: var(--s-3);
    align-items: baseline;
    padding: var(--s-2) 0;
    border-top: 1px solid var(--filo);
}
.voci li:last-child { border-bottom: 1px solid var(--filo); }
.voce-cifra {
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--t-h3);
    line-height: 1.12;
    letter-spacing: -0.008em;
    text-align: right;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}
.voce-nome b { display: block; font-weight: 500; font-size: var(--t-etichetta); line-height: 1.35; color: var(--inchiostro); }
.voce-nome span { display: block; margin-top: 4px; font-size: var(--t-etichetta); line-height: 1.62; color: var(--inchiostro-2); }

.stime { margin: var(--s-6) 0 0; padding-top: var(--s-4); border-top: 1px solid var(--filo); }
.stima { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--s-1) var(--s-4); }
.stima + .stima { margin-top: var(--s-4); }
.stima-valore {
    flex: none;
    min-width: 5ch;
    margin: 0;
    font-family: var(--titolo);
    font-weight: 300;
    font-size: var(--t-h2);
    line-height: 1.06;
    letter-spacing: -0.018em;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}
.stima-unita { font-family: var(--testo); font-size: var(--t-corpo); font-weight: 500; color: var(--inchiostro-2); margin-left: 0.28em; }
.stima-nome { flex: 1 1 280px; margin: 0; font-size: var(--t-etichetta); line-height: 1.62; color: var(--inchiostro-2); }
/* l'asterisco vive in coda alla frase: appeso alla cifra da 54px sarebbe un
   segno da 33px, e non e' un accento, e' un rimando */
.stima-nome .sc-ast { font-size: var(--t-occhiello); }

/* ---------------------------------------------------- anteprima mappa */
.sezione-mappa h2 { margin-top: var(--s-3); }
.sezione-mappa .sc-guida { margin-top: var(--s-3); max-width: 62ch; }
/* L'anteprima si guarda, non si manovra: un clic porta alla mappa vera */
.anteprima {
    position: relative;
    display: block;
    margin: var(--s-6) 0 0;
    height: clamp(300px, 46vh, 520px);
    background: var(--carta);
    border-top: 1px solid var(--filo-2);
    border-bottom: 1px solid var(--filo-2);
    text-decoration: none;
    color: inherit;
}
#anteprima-mappa { position: absolute; inset: 0; }
/* Le attribuzioni di chi fornisce la carta sono un obbligo verso di lui, non
   una postilla: anche qui stanno ai 13 px minimi del portale, come nella
   pagina della mappa. MapLibre le stampa a 12 px di suo. */
.anteprima .maplibregl-ctrl-attrib,
.anteprima .maplibregl-ctrl-attrib-inner { font-size: var(--t-occhiello); line-height: 1.35; }
.anteprima .maplibregl-ctrl-attrib a { color: var(--bosco); }
/* Un vetro trasparente sopra la carta: senza, il dito che tocca la mappa
   toccherebbe la tela di MapLibre invece del collegamento che la avvolge */
.anteprima .velo { position: absolute; inset: 0; z-index: 1; }
.anteprima .invito {
    position: absolute;
    z-index: 2;
    left: 50%;
    bottom: var(--s-4);
    transform: translateX(-50%);
    white-space: nowrap;
    box-shadow: var(--ombra);
}
.legenda {
    display: flex;
    flex-wrap: wrap;
    gap: 0 var(--s-5);
    margin: var(--s-3) 0 0;
    padding: 0;
    list-style: none;
    font-size: var(--t-etichetta);
    color: var(--chiaro);
}
.legenda li { display: inline-flex; align-items: center; gap: var(--s-1); min-height: 36px; }
.mappa-assente { margin: var(--s-4) 0 0; max-width: 62ch; color: var(--chiaro); }

/* ------------------------------------------- come lavoriamo / segnalare */
.servizio-griglia { display: grid; gap: var(--s-6); margin-top: var(--s-5); }
@media (min-width: 900px) {
    .servizio-griglia { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--s-7); }
}
.blocchi { margin-top: var(--s-3); }
.blocchi > div { padding: var(--s-3) 0; border-top: 1px solid var(--filo); }
.blocchi > div:last-child { border-bottom: 1px solid var(--filo); }
/* prima la regola di tutti i paragrafi, poi quella del nome: nel blocco del
   pericolo immediato c'e' un numero in mezzo, e un "p + p" salterebbe
   l'ultimo capoverso lasciandolo del corpo sbagliato */
.blocchi p { margin: var(--s-1) 0 0; font-size: var(--t-etichetta); line-height: 1.62; color: var(--inchiostro-2); }
.blocchi .blocco-nome { margin: 0; font-size: var(--t-etichetta); font-weight: 500; line-height: 1.35; color: var(--inchiostro); }
/* Il pericolo immediato non è una voce come le altre: si stacca dal filetto */
.urgenza { padding-left: var(--s-3); border-left: 2px solid var(--inchiostro); }
.urgenza .numero {
    display: block;
    margin: var(--s-1) 0 4px;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--t-h3);
    line-height: 1.12;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}

/* ------------------------------------------------ nota sul metodo e coda */
.coda h2 { margin-top: var(--s-2); }
.metodo-nota {
    margin: var(--s-3) 0 0;
    padding-top: var(--s-3);
    border-top: 1px solid var(--chiaro-2);
    max-width: 92ch;
    font-size: var(--t-etichetta);
    line-height: 1.68;
    color: var(--chiaro);
}
@media (min-width: 900px) { .metodo-nota { columns: 2; column-gap: var(--s-7); } }
/* L'asterisco che apre la nota: un <sup> lasciato al browser scenderebbe a
   11,7px, sotto il minimo di 13 che questo portale si e' dato */
.metodo-nota > sup {
    font-size: var(--t-occhiello);
    font-weight: 600;
    line-height: 0;
    color: var(--oro-chiaro);
}
.coda .cerca { margin-top: var(--s-7); }
.coda-chiusa { margin: var(--s-6) 0 0; max-width: 62ch; color: var(--chiaro); font-size: var(--t-etichetta); line-height: 1.62; }

/* ------------------------------------------- stop dei gradienti dei disegni
   I colori dei gradienti SVG si prendono dai token del layout con una
   classe: così non c'è un solo colore scritto a mano in questa pagina. */
.s-cielo-0 { stop-color: var(--luce); }
.s-cielo-1 { stop-color: var(--luce-2); }
.s-cielo-2 { stop-color: var(--fogliame-lontano); }
.s-cielo-3 { stop-color: var(--fogliame-medio); }
.s-sole-0 { stop-color: #ffffff; }
.s-sole-1 { stop-color: var(--luce); }
.s-velo-0 { stop-color: var(--notte); stop-opacity: 0; }
.s-velo-1 { stop-color: var(--notte); stop-opacity: 0.9; }
.s-velo-2 { stop-color: var(--notte); stop-opacity: 1; }
.s-scorza-0 { stop-color: var(--corteccia-2); }
.s-scorza-1 { stop-color: var(--corteccia); }
.s-scorza-2 { stop-color: var(--luce-2); }
.piano-lontano { fill: var(--fogliame-lontano); }
.piano-medio { fill: var(--fogliame-medio); }
.piano-vicino { fill: var(--fogliame-vicino); }
.raggi { fill: var(--luce); }
.chioma-base { fill: var(--fogliame-medio); }
.chioma-luce { fill: var(--fogliame-lontano); opacity: 0.75; }
.chioma-ombra { fill: var(--fogliame-vicino); opacity: 0.4; }
.terreno { stroke: var(--filo-2); }
.ombra-terra { fill: var(--inchiostro); opacity: 0.1; }
.quota-filo { stroke: var(--inchiostro-2); }
.quota-aiuto { stroke: var(--filo-2); }
.quota-punta { fill: var(--inchiostro-2); }
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

        // Il campo del cartellino e' un filo d'oro senza scatola: bello, ma da
        // solo sembra una riga vuota. L'esempio dentro al campo gli ridà la
        // forma di un campo e insegna come si scrive il numero.
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
        // sole spiegazioni dei quattro stati, senza figura.
        $stati = is_array($statistiche['stati'] ?? null) ? $statistiche['stati'] : [];
        $sommaStati = array_sum($stati);

        // Fino a sessanta elementi si disegna un alberello per elemento; oltre,
        // le sagome diventerebbero un tappeto illeggibile e si passa alla
        // fascia proporzionale. La soglia è il punto in cui, a 360px di
        // larghezza, gli alberelli scendono sotto i venti pixel.
        $tettoAlberelli = 60;
        $sequenza = [];
        $fascia = [];

        if ($sommaStati > 0 && $sommaStati <= $tettoAlberelli) {
            // Lo stato più numeroso fa da fondo, gli altri si distribuiscono a
            // passo regolare: nessun raggruppamento per colore, che darebbe
            // l'idea di una zona malata che nei dati non c'è.
            $ordinati = $stati;
            arsort($ordinati);
            $fondo = array_key_first($ordinati);
            $sequenza = array_fill(0, $sommaStati, $fondo);

            $minoranza = [];
            foreach ($stati as $stato => $quanti) {
                if ($stato !== $fondo) {
                    $minoranza = array_merge($minoranza, array_fill(0, $quanti, $stato));
                }
            }
            if ($minoranza !== []) {
                $passo = $sommaStati / count($minoranza);
                foreach ($minoranza as $k => $stato) {
                    $sequenza[(int) floor(($k + 0.5) * $passo)] = $stato;
                }
            }
        } elseif ($sommaStati > $tettoAlberelli) {
            $fascia = array_filter($stati, fn ($quanti) => $quanti > 0);
        }

        $forme = ['alb-a', 'alb-b', 'alb-c', 'alb-d'];
        $scale = ['0.92', '1', '1.08'];

        $conCo2 = $portale->mostraCo2() && ($statistiche['co2']['alberi'] ?? 0) > 0;
        $conEuro = $conCo2
            && ($statistiche['co2']['euro'] ?? null) !== null
            && ($statistiche['co2']['prezzo'] ?? null) !== null;

        // La data che si mostra è quella dell'ULTIMO RILIEVO registrato, non
        // quella di oggi: "aggiornato al" deve dire da quando il registro non
        // si muove, altrimenti un censimento fermo da tre anni si presenterebbe
        // come aggiornato stamattina. Dove nessun elemento ha una data di
        // rilievo non si scrive niente.
        $aggiornato = $statistiche['ultimo_rilievo'] ?? null;
        $aggiornato = $aggiornato ? \Illuminate\Support\Carbon::parse($aggiornato)->format('d/m/Y') : null;
    @endphp

    {{-- Le quattro sagome d'albero, dichiarate una volta e richiamate con
         <use>: quattro portamenti diversi, perché una fila di sagome tutte
         uguali si riconosce subito come un motivo ripetuto --}}
    <svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;overflow:hidden">
        <defs>
            <path id="alb-a" d="M-1.4 0 L-1.4 -12.6 C-6.2 -13.6 -8.6 -17.6 -7.4 -21.4
                C-9.4 -24.4 -6.8 -28.6 -3.2 -28 C-3.4 -32.2 1 -34.4 3.6 -31.6
                C7.2 -32 9.4 -28.2 7.8 -25 C10.4 -21.2 8.4 -15.4 3.4 -13.2 L1.4 -12.6 L1.4 0 Z"/>
            <path id="alb-b" d="M-1.6 0 L-1.6 -10.6 C-8.4 -11.4 -13.4 -14.6 -12.4 -18.6
                C-15.4 -20.8 -13 -26 -8.8 -25.4 C-8 -30 -1.6 -32.4 1.2 -29.6
                C5.4 -32 10.6 -29 10.6 -24.8 C14.4 -23.6 14.6 -18.4 10.8 -17.2
                C9.6 -13 4.8 -11.2 1.6 -10.6 L1.6 0 Z"/>
            <path id="alb-c" d="M-1.5 0 L-1.5 -15.6 C-9.2 -16.4 -14.2 -19 -13 -22.6
                C-15.6 -24.8 -12.6 -29 -8.4 -27.8 C-6.4 -31.6 -0.4 -32.6 2.4 -30
                C6.4 -31.8 11.4 -29 10.8 -24.8 C14.4 -23.4 13 -19 9 -17.8
                C6.8 -15.6 4 -15.2 1.5 -15.6 L1.5 0 Z"/>
            <path id="alb-d" d="M-1.2 0 L-1.2 -9.6 C-6.2 -11.6 -8.2 -15.8 -6.4 -18.8
                C-8.2 -22 -5.4 -25.2 -2.4 -24.4 C-2.2 -28.2 -0.2 -31.2 1.6 -33.4
                C3 -30.2 4.2 -27 4.8 -23.8 C8 -23.8 9.4 -20.2 7.2 -18.2
                C8.8 -14.6 6 -11.2 1.2 -9.6 L1.2 0 Z"/>
        </defs>
    </svg>

    {{-- =================================================== 1. COPERTINA --}}
    <section class="copertina sc-scuro">
        {{-- La volta del bosco in controluce: il sole in alto a destra, tre
             piani di fogliame che si schiariscono verso il fondo (prospettiva
             aerea), il fusto che scende e si perde nella notte. La stessa luce
             torna nella tavola quotata qui sotto: è lo stesso albero. --}}
        <div class="copertina-figura" aria-hidden="true">
            <svg viewBox="0 0 1440 460" preserveAspectRatio="xMidYMax slice" focusable="false">
                <defs>
                    <radialGradient id="co-cielo" cx="0.78" cy="0.1" r="0.95">
                        <stop offset="0" class="s-cielo-0"/>
                        <stop offset="0.3" class="s-cielo-1"/>
                        <stop offset="0.68" class="s-cielo-2"/>
                        <stop offset="1" class="s-cielo-3"/>
                    </radialGradient>
                    <radialGradient id="co-sole" cx="0.5" cy="0.5" r="0.5">
                        <stop offset="0" class="s-sole-0" stop-opacity="0.95"/>
                        <stop offset="0.42" class="s-sole-1" stop-opacity="0.34"/>
                        <stop offset="1" class="s-sole-1" stop-opacity="0"/>
                    </radialGradient>
                    {{-- il velo: il disegno finisce in notte piena, ed è su
                         quella notte che comincia il testo --}}
                    <linearGradient id="co-velo" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0.26" class="s-velo-0"/>
                        <stop offset="0.82" class="s-velo-1"/>
                        <stop offset="1" class="s-velo-2"/>
                    </linearGradient>
                    {{-- il riquadro dei filtri lascia margine: senza, gli
                         oggetti sfocati escono tagliati a squadra --}}
                    <filter id="co-morbido" x="-40%" y="-40%" width="180%" height="180%" color-interpolation-filters="sRGB">
                        <feGaussianBlur stdDeviation="7"/>
                    </filter>
                    <filter id="co-lontano" x="-40%" y="-40%" width="180%" height="180%" color-interpolation-filters="sRGB">
                        <feGaussianBlur stdDeviation="15"/>
                    </filter>
                </defs>

                <rect width="1440" height="460" fill="url(#co-cielo)"/>
                <circle cx="1112" cy="126" r="184" fill="url(#co-sole)"/>
                <circle cx="1112" cy="126" r="40" fill="#ffffff" opacity="0.62"/>

                {{-- i raggi scendono verso sinistra: di lì viene la luce, e di
                     lì cadono tutte le ombre del portale --}}
                <g class="raggi" filter="url(#co-morbido)" opacity="0.34">
                    <path d="M1104 96 L640 460 L470 460 Z" opacity="0.5"/>
                    <path d="M1126 104 L960 460 L850 460 Z" opacity="0.38"/>
                    <path d="M1090 88 L300 460 L170 460 Z" opacity="0.26"/>
                </g>

                <g class="piano-lontano" filter="url(#co-lontano)" opacity="0.92">
                    <path d="M-60 -60 A80 80 0 0 1 70 -10 A96 96 0 0 1 210 46 A56 56 0 0 0 306 16
                        A104 104 0 0 1 440 78 A78 78 0 0 1 556 50 A48 48 0 0 0 650 84
                        A98 98 0 0 1 776 120 A64 64 0 0 1 890 92 A46 46 0 0 0 986 124
                        A92 92 0 0 1 1120 84 A68 68 0 0 1 1238 116 A52 52 0 0 0 1340 80
                        A94 94 0 0 1 1500 46 L1500 -60 Z"/>
                </g>

                <g class="piano-medio" filter="url(#co-morbido)" opacity="0.95">
                    <path d="M-60 -120 A88 88 0 0 1 80 -60 A104 104 0 0 1 228 4 A60 60 0 0 0 330 -28
                        A112 112 0 0 1 470 40 A84 84 0 0 1 592 8 A52 52 0 0 0 692 46
                        A106 106 0 0 1 824 86 A70 70 0 0 1 944 54 A50 50 0 0 0 1046 90
                        A100 100 0 0 1 1186 46 A74 74 0 0 1 1310 82 A56 56 0 0 0 1418 42
                        A100 100 0 0 1 1500 -10 L1500 -120 Z"/>
                </g>

                <g class="piano-vicino">
                    {{-- il fusto attraversa la fascia e si perde nel velo --}}
                    <path d="M962 60 C966 140, 970 240, 968 340 C967 400, 962 440, 956 460
                        L1042 460 C1036 440, 1032 400, 1031 340 C1030 240, 1028 140, 1026 60 Z"/>
                    <path d="M966 168 C918 156, 872 134, 828 106 C806 92, 784 82, 764 78
                        L760 92 C782 98, 802 108, 822 122 C866 152, 912 176, 962 190 Z"/>
                    <path d="M1028 232 C1078 220, 1124 200, 1166 174 C1188 160, 1210 152, 1230 148
                        L1226 134 C1204 138, 1180 148, 1158 162 C1116 190, 1072 210, 1024 220 Z"/>
                    <path d="M-60 -180 A96 96 0 0 1 90 -110 A112 112 0 0 1 250 -40 A64 64 0 0 0 360 -78
                        A120 120 0 0 1 512 0 A90 90 0 0 1 640 -34 A56 56 0 0 0 748 8
                        A114 114 0 0 1 890 52 A76 76 0 0 1 1018 16 A54 54 0 0 0 1126 56
                        A108 108 0 0 1 1276 10 A80 80 0 0 1 1404 48 A60 60 0 0 0 1500 6
                        L1500 -180 Z"/>
                </g>

                <rect width="1440" height="460" fill="url(#co-velo)"/>
            </svg>
        </div>

        <div class="sc-contenitore copertina-dentro">
            <p class="sc-occhiello sc-occhiello-luce">Censimento del verde pubblico</p>
            <h1 class="sc-h1 copertina-titolo">{{ $portale->name() }},<br><em>albero per albero.</em></h1>

            <p class="copertina-benvenuto">{{ $portale->welcomeText()
                ?: 'Ogni albero del territorio ha una scheda con la specie, le misure e gli interventi eseguiti. Si consulta dalla mappa oppure cercando il numero riportato sul cartellino.' }}</p>

            {{-- L'azione principale della pagina: il numero letto sul
                 cartellino applicato alla pianta --}}
            <form class="cerca" method="get" action="{{ $portale->url('/cerca') }}" role="search">
                <label class="cerca-etichetta" for="cartellino">Numero del cartellino</label>
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
                <div class="cerca-azioni">
                    <button class="sc-bottone-oro cerca-invio" type="submit">Cerca l'albero</button>
                    <p class="cerca-nota">Il numero è stampato sul cartellino applicato all'albero:
                        {{ $soloCifre ? 'bastano le cifre, senza spazi.' : 'si scrive per intero, come sta sul cartellino.' }}</p>
                </div>
            </form>

            @if ($nonTrovato)
                <p class="avviso" role="status">
                    Nessun elemento trovato con l'etichetta &laquo;{{ $cercato }}&raquo;.
                    Controlla il numero riportato sul cartellino{{ $soloCifre ? ': si scrive anche solo con le cifre.' : '.' }}
                </p>
            @endif

            <div class="vie">
                <a href="{{ $portale->url('/mappa') }}">Oppure guarda la mappa del verde</a>
                @if ($urlSegnala)
                    <a href="#segnalare">Segnala un problema</a>
                @endif
            </div>
        </div>
    </section>

    {{-- ========================================= 2. CHE COSA SI MISURA --}}
    <section class="misure sc-avorio sc-sezione" id="misure">
        <div class="sc-contenitore misure-griglia">
            <div class="misure-testo">
                <p class="sc-occhiello sc-occhiello-oro">Che cosa si misura</p>
                <h2 class="sc-h2">Lo stesso albero, misurato sempre allo stesso modo</h2>
                <p class="sc-guida">
                    Il tecnico non guarda la pianta a occhio: la misura. Sono le stesse quote per tutti
                    gli alberi del censimento, ed è per questo che si possono confrontare fra loro e negli anni.
                </p>
                <p class="sc-nota">
                    È lo stesso albero della copertina, qui ripreso di giorno. Le quote segnate sul disegno
                    sono quelle che si leggono in ogni scheda, insieme alla posizione rilevata sul posto.
                </p>
            </div>

            <div class="misure-tavola">
                <svg class="tavola" viewBox="0 0 520 560" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
                    <defs>
                        {{-- la scorza: ombra a sinistra, luce a destra, come il
                             sole della copertina. La corteccia è sganciata dal
                             colore del Comune: nessuna corteccia è viola. --}}
                        <linearGradient id="tv-scorza" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" class="s-scorza-0"/>
                            <stop offset="0.62" class="s-scorza-1"/>
                            <stop offset="1" class="s-scorza-2"/>
                        </linearGradient>
                        <path id="tv-chioma-sagoma" d="M254 84
                            A62 62 0 0 1 328 108 A54 54 0 0 1 384 146 A48 48 0 0 1 406 200
                            A46 46 0 0 1 382 252 A44 44 0 0 1 330 288 A50 50 0 0 1 266 302
                            A50 50 0 0 1 204 292 A46 46 0 0 1 150 266 A46 46 0 0 1 112 212
                            A48 48 0 0 1 130 152 A54 54 0 0 1 182 108 A58 58 0 0 1 254 84 Z"/>
                        <clipPath id="tv-dentro-chioma"><use href="#tv-chioma-sagoma"/></clipPath>
                        {{-- luce e ombra della chioma si sfumano: senza, l'orlo
                             della lente chiara si vede come un taglio --}}
                        <filter id="tv-morbido" x="-40%" y="-40%" width="180%" height="180%" color-interpolation-filters="sRGB">
                            <feGaussianBlur stdDeviation="18"/>
                        </filter>
                        <marker id="tv-punta" viewBox="0 0 10 10" refX="9" refY="5"
                                markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path class="quota-punta" d="M0 1 L9 5 L0 9 Z"/>
                        </marker>
                    </defs>

                    {{-- l'ombra cade a sinistra: il sole sta in alto a destra --}}
                    <ellipse class="ombra-terra" cx="176" cy="474" rx="188" ry="15"/>

                    <use href="#tv-chioma-sagoma" class="chioma-base"/>
                    <g clip-path="url(#tv-dentro-chioma)" filter="url(#tv-morbido)">
                        <ellipse class="chioma-luce" cx="352" cy="106" rx="150" ry="118"/>
                        <ellipse class="chioma-ombra" cx="140" cy="304" rx="152" ry="112"/>
                    </g>

                    <path d="M237 196 C235 258, 234 330, 234 390 C234 420, 230 448, 224 470
                             L276 470 C270 448, 266 420, 266 390 C266 330, 265 258, 263 196 Z"
                          fill="url(#tv-scorza)"/>
                    <path d="M226 470 C212 462, 197 460, 185 462 C199 451, 216 451, 229 458 Z" fill="url(#tv-scorza)"/>
                    <path d="M274 470 C288 462, 303 461, 315 463 C301 452, 284 451, 271 458 Z" fill="url(#tv-scorza)"/>

                    {{-- la proiezione della chioma a terra --}}
                    <ellipse class="quota-aiuto" cx="250" cy="470" rx="160" ry="20"
                             fill="none" stroke-width="1" stroke-dasharray="5 6"/>

                    {{-- il terreno, con il tratteggio del piano di campagna --}}
                    <g class="terreno" stroke-width="1" fill="none">
                        <path d="M20 470 H500"/>
                        <path d="M34 470 26 482M62 470 54 482M90 470 82 482M430 470 422 482M458 470 450 482M486 470 478 482"/>
                    </g>

                    {{-- QUOTA: altezza totale --}}
                    <path class="quota-aiuto" d="M254 84 H486" fill="none" stroke-width="1" stroke-dasharray="4 6"/>
                    <line class="quota-filo" x1="486" y1="84" x2="486" y2="470" stroke-width="1"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>

                    {{-- QUOTA: diametro del tronco, misurato a 1,30 m da terra --}}
                    <g class="quota-aiuto" stroke-width="1" fill="none">
                        <path d="M234 392 V446"/><path d="M266 392 V446"/>
                    </g>
                    <g class="quota-filo" stroke-width="1">
                        <line x1="174" y1="412" x2="232" y2="412" marker-end="url(#tv-punta)"/>
                        <line x1="326" y1="412" x2="268" y2="412" marker-end="url(#tv-punta)"/>
                    </g>
                    {{-- l'altezza convenzionale della misura, dal terreno --}}
                    <line class="quota-filo" x1="168" y1="412" x2="168" y2="470" stroke-width="1"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>

                    {{-- QUOTA: diametro della chioma, uguale alla sua proiezione --}}
                    <g class="quota-aiuto" stroke-width="1" fill="none">
                        <path d="M90 478 V532"/><path d="M410 478 V532"/>
                    </g>
                    <line class="quota-filo" x1="90" y1="524" x2="410" y2="524" stroke-width="1"
                          marker-start="url(#tv-punta)" marker-end="url(#tv-punta)"/>
                </svg>

                <ul class="quote">
                    <li class="quota">
                        <svg class="quota-segno" viewBox="0 0 26 32" aria-hidden="true" focusable="false">
                            <path class="quota-filo" d="M13 5 V27" stroke-width="1.4" fill="none"/>
                            <path class="quota-punta" d="M13 2 l4 6 h-8 Z"/>
                            <path class="quota-punta" d="M13 30 l4 -6 h-8 Z"/>
                        </svg>
                        <span>
                            <span class="quota-nome">Altezza totale</span>
                            <span class="quota-glossa">dal piede della pianta alla cima, in metri.</span>
                        </span>
                    </li>
                    <li class="quota">
                        <svg class="quota-segno" viewBox="0 0 26 32" aria-hidden="true" focusable="false">
                            <path class="quota-aiuto" d="M7 8 V24M19 8 V24" stroke-width="1.2" fill="none"/>
                            <path class="quota-filo" d="M1 16 H25" stroke-width="1.4" fill="none"/>
                            <path class="quota-punta" d="M7 16 l6 -4 v8 Z"/>
                            <path class="quota-punta" d="M19 16 l-6 -4 v8 Z"/>
                        </svg>
                        <span>
                            <span class="quota-nome">Diametro del tronco</span>
                            <span class="quota-glossa">misurato a 1,30 m da terra, l'altezza convenzionale usata ovunque: è la misura che permette di confrontare due piante.</span>
                        </span>
                    </li>
                    <li class="quota">
                        <svg class="quota-segno" viewBox="0 0 26 32" aria-hidden="true" focusable="false">
                            <ellipse class="quota-aiuto" cx="13" cy="16" rx="11" ry="6"
                                     fill="none" stroke-width="1.4" stroke-dasharray="3 4"/>
                        </svg>
                        <span>
                            <span class="quota-nome">Proiezione della chioma</span>
                            <span class="quota-glossa">quanto terreno copre la chioma: è la stessa misura del suo diametro.</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    {{-- ==================================== 3. COME STA IL VERDE CENSITO --}}
    <section class="censito sc-carta sc-sezione" id="come-sta">
        <div class="sc-contenitore">
            <div class="sc-testa-sezione">
                <p class="sc-occhiello sc-occhiello-oro">Come sta il verde censito</p>
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

            @if ($sequenza !== [])
                <div class="filare" aria-hidden="true">
                    @foreach ($sequenza as $indice => $stato)
                        @php
                            // forma e statura si scelgono in modo ripetibile
                            // dall'indice: il filare non cambia a ogni ricarica
                            $impronta = crc32('alberello'.$indice);
                        @endphp
                        <span class="alberello" style="--c: var(--stato-{{ $stato }})">
                            <svg viewBox="-17 -36 34 40" focusable="false">
                                <ellipse class="ombra" cx="-3" cy="1.4" rx="9" ry="1.8"/>
                                <use class="sagoma" href="#{{ $forme[$impronta % 4] }}"
                                     transform="scale({{ $scale[intdiv($impronta, 4) % 3] }})"/>
                            </svg>
                        </span>
                    @endforeach
                </div>
            @elseif ($fascia !== [])
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
            @else
                <p class="sc-nota" style="margin-top: var(--s-5)">
                    Il rilievo sul territorio è in corso: i primi elementi compariranno qui appena saranno registrati.
                </p>
            @endif

            @if ($conCo2)
                <div class="stime">
                    <div class="stima">
                        <p class="stima-valore">{{ number_format($statistiche['co2']['kg'] / 1000, 1, ',', '.') }}<span class="stima-unita">t</span></p>
                        <p class="stima-nome">Anidride carbonica immagazzinata dal patrimonio arboreo<a class="sc-ast" href="#nota-metodo">*</a></p>
                    </div>
                    {{-- La riga in euro esce solo dove un prezzo per tonnellata
                         è configurato. Prezzo e fonte viaggiano nello stesso
                         risultato in cache del numero, così la nota dichiara il
                         prezzo con cui il valore è stato davvero calcolato --}}
                    @if ($conEuro)
                        <div class="stima">
                            <p class="stima-valore">{{ number_format($statistiche['co2']['euro'], 0, ',', '.') }}<span class="stima-unita">euro</span></p>
                            <p class="stima-nome">Controvalore economico stimato di quelle tonnellate sul mercato delle quote di emissione<a class="sc-ast" href="#nota-metodo">*</a>: non è il valore degli alberi.</p>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- ============================================= 4. LA MAPPA PUBBLICA --}}
    <section class="sezione-mappa sc-scuro sc-sezione" id="mappa">
        <div class="sc-contenitore">
            <div class="sc-testa-sezione">
                <p class="sc-occhiello sc-occhiello-luce">La mappa pubblica</p>
                <span class="sc-filo"></span>
            </div>
            <h2 class="sc-h2">Il verde visto dall'alto</h2>
            <p class="sc-guida">
                Ogni elemento censito è al suo posto, con il colore del proprio stato.
                Toccando un punto si apre la scheda: specie, misure, controlli e lavori registrati.
            </p>
        </div>

        @if ($estensione && count($sfondi))
            <a class="anteprima" href="{{ $portale->url('/mappa') }}" aria-label="Apri la mappa del verde">
                <div id="anteprima-mappa"></div>
                <span class="velo"></span>
                <span class="invito sc-bottone">Apri la mappa &rarr;</span>
            </a>

            <div class="sc-contenitore">
                <ul class="legenda">
                    @foreach (\App\Services\Portale\PortalState::ETICHETTE as $codice => $etichetta)
                        <li><span class="sc-pallino" style="--c: var(--stato-{{ $codice }})"></span>{{ $etichetta }}</li>
                    @endforeach
                </ul>
            </div>

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
            <div class="sc-contenitore">
                <p class="mappa-assente">La mappa sarà disponibile appena il censimento avrà i primi elementi rilevati sul territorio.</p>
            </div>
        @endif
    </section>

    {{-- ================================ 5. COME LAVORIAMO / SEGNALARE --}}
    <section class="servizio sc-avorio sc-sezione" id="come-lavoriamo">
        <div class="sc-contenitore">
            <div class="sc-testa-sezione">
                <p class="sc-occhiello sc-occhiello-oro">Chi controlla, che cosa cambia lo stato, a chi si scrive</p>
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
                            <span class="numero">112</span>
                            {{-- Nessuna promessa sugli orari dell'ufficio, che questo programma
                                 non conosce: si dice solo di non affidare un'emergenza alla posta --}}
                            <p>La posta elettronica non è un canale di emergenza: potrebbe essere letta
                               solo nei giorni e negli orari di ufficio.</p>
                        </div>
                        <div>
                            <p class="blocco-nome">Segnalazione ordinaria</p>
                            @if ($posta)
                                <p>Ramo basso, ceppaia, radice che solleva il marciapiede, pianta che sembra
                                   sofferente: scrivere a
                                   <a class="sc-collegamento" href="{{ $urlSegnala }}">{{ $posta }}</a>
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

    {{-- ================================= 6. NOTA SUL METODO E CHIUSURA --}}
    <section class="coda sc-scuro sc-sezione">
        <div class="sc-contenitore">
            @if ($conCo2)
                <div id="nota-metodo">
                    <p class="sc-occhiello sc-occhiello-luce">Nota sul metodo</p>
                    <h2 class="sc-h3">Da dove viene il valore segnato con l'asterisco</h2>
                    <p class="metodo-nota">
                        <sup>*</sup> Valore stimato, non misurato, calcolato
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
                    </p>
                </div>
            @endif

            {{-- Prima di chiudere, la pagina ripropone la sola cosa da fare:
                 chi è arrivato in fondo leggendo non deve risalire per cercare --}}
            <form class="cerca" method="get" action="{{ $portale->url('/cerca') }}" role="search">
                <label class="cerca-etichetta" for="cartellino-coda">Numero del cartellino</label>
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
                <div class="cerca-azioni">
                    <button class="sc-bottone-oro cerca-invio" type="submit">Cerca l'albero</button>
                    <p class="cerca-nota">{{ $soloCifre
                        ? 'Bastano le cifre lette sul cartellino, senza spazi.'
                        : 'Il codice letto sul cartellino, scritto per intero.' }}</p>
                </div>
            </form>

            <p class="coda-chiusa">
                Il censimento non è mai finito: le schede si aggiornano a ogni controllo e a ogni lavoro
                concluso. Quello che si legge qui è lo stesso registro su cui lavorano i tecnici.
            </p>
        </div>
    </section>
@endsection
