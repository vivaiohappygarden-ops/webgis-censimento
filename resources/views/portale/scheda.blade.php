@php
    $albero = $asset->tree;
    $misura = fn ($v, $u) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',').' '.$u;

    // Il campo specie porta di norma il binomio completo ("Tilia cordata"):
    // anteporre il genere lo raddoppierebbe
    $genere = trim((string) ($albero?->genus ?? ''));
    $specie = trim((string) ($albero?->species ?? ''));
    $binomio = $specie !== '' && $genere !== '' && ! str_starts_with(mb_strtolower($specie), mb_strtolower($genere))
        ? $genere.' '.$specie
        : ($specie !== '' ? $specie : $genere);
    if ($binomio !== '' && $albero?->cultivar) {
        $binomio .= " '".$albero->cultivar."'";
    }

    // La stessa scheda serve due posti: la pagina intera (elemento.blade.php la
    // include passando 'pagina') e il pannello della mappa, largo circa 340 px,
    // dove il controller la rende da sola. Il contesto arriva da chi include,
    // non dalla larghezza della finestra: nel pannello la finestra è larga uguale.
    $contesto = $contesto ?? 'riquadro';

    $codice = trim((string) ($asset->census_code ?? ''));
    $riferimento = $codice !== '' ? $codice : $asset->id;
    $urlFoto = $portale->url('/elemento/'.rawurlencode($riferimento).'/foto');

    $nomeComune = trim((string) ($albero?->common_name ?? ''));
    $tipoNome = trim((string) ($asset->objectType?->name ?? ''));
    // Codice e nome del tipo di catalogo su una riga sola: senza il codice non
    // deve restare uno spazio in testa
    $tipoRiga = trim(trim((string) ($asset->objectType?->code ?? '')).' '.mb_strtoupper($tipoNome));

    // Il titolo grande è il nome che un cittadino riconosce. Senza nome comune
    // sale il nome botanico (in corsivo, come si scrive); senza pianta è il tipo
    // di catalogo: un prato o una panchina non hanno specie né stato di salute.
    $titolo = $nomeComune !== '' ? $nomeComune : null;
    if ($titolo === null && $binomio === '') {
        $titolo = $tipoNome !== '' ? $tipoNome : 'Elemento censito';
    }

    // Le misure sono solo quelle che il rilievo ha davvero preso: l'elenco si
    // costruisce qui e la grafica non presume quante siano (due o dieci).
    // Ogni voce: [etichetta, valore già scritto con la sua unità, spiegazione].
    $misure = [];
    if ($albero?->height_m) {
        $misure[] = ['Altezza', $misura($albero->height_m, 'm'), 'Dal piede della pianta alla cima della chioma.'];
    }
    if ($albero?->crown_diameter_m) {
        $misure[] = ['Diametro della chioma', $misura($albero->crown_diameter_m, 'm'), 'La larghezza del fogliame vista da terra.'];
    }
    if ($albero?->dbh_cm) {
        $misure[] = ['Diametro del tronco', $misura($albero->dbh_cm, 'cm'), 'Misurato a 1,30 m da terra, come in tutti i censimenti degli alberi: è l\'altezza convenzionale che rende confrontabili le misure.'];
    } elseif ($albero?->trunk_circumference_cm) {
        $misure[] = ['Circonferenza del tronco', $misura($albero->trunk_circumference_cm, 'cm'), 'Misurata a 1,30 m da terra, girando il metro intorno al fusto.'];
    }
    if ($albero?->age_years_est) {
        // Un'età registrata come precisa (data di impianto nota, conteggio degli
        // anelli) non si presenta al pubblico come una stima
        $misure[] = $albero->age_qualifier === 'precisa'
            ? ['Età', $albero->age_years_est.' anni', null]
            : ['Età stimata', 'circa '.$albero->age_years_est.' anni', 'Un ordine di grandezza ricavato dal tronco, non una data di nascita.'];
    }
    if ($albero?->planted_on) {
        $misure[] = ['Messo a dimora', $albero->planted_on->format('Y'), null];
    }

    $famiglia = trim((string) ($albero?->family ?? ''));
    $haTutele = (bool) ($albero?->is_monumental || $albero?->is_protected) || ! empty($vincoli);
@endphp

@once
<style>
/* ======================= SCHEDA DELL'ELEMENTO ==========================
   Perché il foglio di stile sta qui e non nel layout: questa vista viaggia
   anche da sola, senza <head>, quando il pannello della mappa se la carica.
   La direttiva "once" qui sopra lo stampa una volta sola, se mai una pagina
   arrivasse a mostrare più schede insieme.

   Le misure grandi sono fluide sul CONTENITORE (unità cqi), non sulla
   finestra: nel pannello della mappa la finestra è larga 1600 px ma la
   scheda ne ha 340, e la scala del layout — che è fluida sulla finestra —
   ci sfonderebbe dentro un titolo da 96 px. Ogni valore è dichiarato due
   volte: prima fisso, poi in cqi. Un browser che non conosce i contenitori
   tiene il primo e legge la scheda nella sua forma stretta, che è giusta.
   ===================================================================== */
.scheda {
    container-type: inline-size;

    /* Corpi propri della scheda, tarati sulla sua larghezza */
    --sc-cartellino: 42px;
    --sc-cartellino: clamp(38px, 13cqi, 84px);
    --sc-nome: 28px;
    --sc-nome: clamp(26px, 8.4cqi, 46px);
    --sc-titolo: 21px;
    --sc-titolo: clamp(20px, 4.6cqi, 28px);
    --sc-valore: 24px;
    --sc-valore: clamp(22px, 6cqi, 34px);
    --sc-stima: 36px;
    --sc-stima: clamp(32px, 9cqi, 56px);

    /* Margine laterale e stacco fra le sezioni, sempre sulla stessa misura */
    --sc-lato: 20px;
    --sc-lato: clamp(18px, 5cqi, 36px);
    --sc-vuoto: 28px;
    --sc-vuoto: clamp(24px, 5cqi, 44px);

    background: var(--carta);
    color: var(--inchiostro);
    border: 1px solid var(--filo);
    border-radius: var(--raggio);
    overflow: hidden;
    max-width: none;
}
/* A tutta pagina la scheda è un foglio centrato: oltre i 760 px una colonna
   di testo smette di essere leggibile */
.scheda.scheda-pagina { max-width: 760px; margin-left: auto; margin-right: auto; }

.scheda .lato { padding-left: var(--sc-lato); padding-right: var(--sc-lato); }
/* Le proprietà lunghe (non la scorciatoia) perché queste regole scavalcano
   quelle del layout, che usa `padding` in una riga sola */
.scheda .sezione { padding-top: var(--sc-vuoto); padding-bottom: var(--sc-vuoto); }
.scheda .sezione + .sezione { border-top: 1px solid var(--filo); }
/* Un filetto anche fra l'identità e la prima sezione, quando non c'è in mezzo
   il blocco scuro delle tutele a fare da stacco */
.scheda .identita + .sezione { border-top: 1px solid var(--filo); }
/* L'occhiello non porta margini suoi: lo stacco lo dà il titolo che segue */
.scheda .sc-occhiello { margin: 0; }
.scheda .titolo-sezione {
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-titolo);
    line-height: 1.12;
    letter-spacing: -0.008em;
    color: var(--bosco);
    margin: var(--s-1) 0 0;
    text-wrap: balance;
}
.scheda .guida-sezione {
    margin: var(--s-2) 0 0;
    font-size: var(--t-etichetta);
    line-height: 1.62;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}

/* ------------------------------------------------------------ apertura
   La fotografia (o il disegno) tocca i bordi del foglio; sopra, il numero
   del cartellino. Il velo è NERO, non tinto: una fotografia non prende il
   colore del Comune. È anche l'unico modo di garantire il contrasto sopra
   una fotografia che non abbiamo mai visto: dove sta il testo il nero è al
   90%, quindi il bianco legge almeno 7:1 anche sul cielo più chiaro. Per
   questo qui il testo è bianco e non d'oro: l'oro sopra una fotografia
   qualsiasi non arriverebbe a 4,5:1. */
.scheda .foto {
    position: relative;
    margin: 0;
    background: var(--notte);
    /* Il velo cresce dal basso: sotto questa altezza sborderebbe sopra la
       fotografia e il foglio, che taglia quel che esce, mangerebbe il numero.
       Una fotografia più bassa lascia vedere il fondo scuro in alto: è voluto. */
    min-height: 280px;
}
.scheda .foto img {
    display: block;
    width: 100%;
    height: auto;
    /* Un ritratto d'albero molto alto non deve occupare tutto lo schermo */
    max-height: 56vh;
    object-fit: cover;
    object-position: center;
}
.scheda .foto .disegno { display: block; width: 100%; height: auto; }
/* Il disegno non è una fotografia: non ha una sua proporzione da rispettare,
   quindi riempie esattamente il riquadro (preserveAspectRatio="slice" nel
   disegno stesso) e l'altezza la decide la scheda. */
.scheda .foto-disegno { height: 380px; height: clamp(380px, 82cqi, 560px); }
/* Sul disegno il velo può essere più basso: il fondo lo abbiamo disegnato noi
   e non c'è una fotografia da salvare dal buio, quindi si lascia vedere */
.scheda .foto-disegno .velo { padding-top: 40px; }
.scheda .foto-disegno .disegno { position: absolute; inset: 0; width: 100%; height: 100%; }
.scheda .velo {
    position: absolute;
    left: 0; right: 0; bottom: 0;
    padding: 64px var(--sc-lato) var(--s-3);
    padding-top: clamp(56px, 14cqi, 104px);
    /* Il nero pieno arriva fino all'80% dell'altezza del velo: il testo sta
       tutto lì dentro, la sfumatura resta nel solo spazio vuoto in alto */
    background: linear-gradient(to top,
        rgba(0, 0, 0, 0.90) 0%,
        rgba(0, 0, 0, 0.90) 80%,
        rgba(0, 0, 0, 0) 100%);
    color: #fff;
}
.scheda .velo-occhiello {
    margin: 0;
    font-size: var(--t-occhiello);
    line-height: 1.2;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #fff;
}
.scheda .cartellino {
    margin: var(--s-1) 0 0;
    font-family: var(--titolo);
    font-weight: 350;
    font-size: var(--sc-cartellino);
    line-height: 0.98;
    letter-spacing: 0.02em;
    color: #fff;
    font-variant-numeric: tabular-nums lining-nums;
}
/* Senza cartellino non si stampa un numero finto: si dice che non c'è */
.scheda .cartellino-muto { font-size: var(--sc-titolo); letter-spacing: 0; font-style: italic; }
.scheda .filo-oro {
    display: block;
    width: 72px;
    max-width: 40%;
    height: 2px;
    margin-top: var(--s-2);
    background: var(--oro);
}
.scheda .velo-piede {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--s-1) var(--s-2);
    margin: var(--s-2) 0 0;
    font-size: var(--t-etichetta);
    line-height: 1.35;
    color: #fff;
}
.scheda .velo-nota {
    margin: var(--s-2) 0 0;
    font-size: var(--t-occhiello);
    line-height: 1.55;
    color: #fff;
}
/* La lente sta in alto a destra, fuori dal velo e fuori dal soggetto. Il
   tondo è scuro e neutro: sta sopra una fotografia, e una fotografia non
   prende il colore del Comune. */
.scheda .lente {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 2;
    width: 48px; height: 48px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.9);
    background: rgba(0, 0, 0, 0.74);
    color: #fff;
    font-family: var(--testo);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
}
.scheda .lente:hover { background: rgba(0, 0, 0, 0.88); }

/* ------------------------------------------------------------ identità */
.scheda .identita { padding-top: var(--sc-vuoto); padding-bottom: var(--sc-vuoto); }
.scheda .tipo {
    display: block;
    font-size: var(--t-occhiello);
    line-height: 1.2;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--inchiostro-2);
}
.scheda .nome {
    margin: var(--s-2) 0 0;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-nome);
    line-height: 1.06;
    letter-spacing: -0.017em;
    color: var(--bosco);
    text-wrap: balance;
    hyphens: auto;
}
.scheda .specie { margin: var(--s-2) 0 0; }
.scheda .specie em {
    display: block;
    font-family: var(--titolo);
    font-style: italic;
    font-weight: 400;
    font-size: var(--sc-titolo);
    line-height: 1.12;
    letter-spacing: -0.008em;
    color: var(--oro-scuro);
}
.scheda .specie span {
    display: block;
    margin-top: var(--s-1);
    font-size: var(--t-etichetta);
    line-height: 1.35;
    color: var(--inchiostro-2);
}
/* Il nome botanico da solo fa da titolo: allora è lui a portare il corpo
   grande, e resta comunque in corsivo */
.scheda .nome-botanico em { font-size: var(--sc-nome); line-height: 1.06; letter-spacing: -0.017em; }

/* -------------------------------------------------------------- misure
   Il valore grande e la sua etichetta sotto. Numero e unità stanno in un
   solo pezzo di testo ("6,5 m"): è la forma in cui il portale li pubblica
   da sempre e in cui i controlli li cercano, e spezzarli in due caselle
   per rimpicciolire l'unità li dividerebbe anche nel testo della pagina. */
.scheda .misure { margin: var(--s-3) 0 0; padding: 0; display: grid; grid-template-columns: 1fr; }
.scheda .misura {
    /* column-reverse: si legge etichetta-poi-valore (che è come si dice),
       si vede valore-poi-etichetta (che è come si guarda) */
    display: flex;
    flex-direction: column-reverse;
    padding: var(--s-2) 0;
    border-top: 1px solid var(--filo);
}
.scheda .misura dt {
    margin-top: var(--s-1);
    font-size: var(--t-etichetta);
    line-height: 1.35;
    letter-spacing: 0;
    text-transform: none;
    color: var(--inchiostro-2);
}
.scheda .misura dd {
    margin: 0;
    text-align: left;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-valore);
    line-height: 1;
    letter-spacing: -0.016em;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}
.scheda .misura-spiega {
    display: block;
    margin-top: 6px;
    font-size: var(--t-occhiello);
    line-height: 1.55;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}

/* ------------------------------------------------------- tutele e vincoli
   L'unico blocco scuro del foglio. Quando ci sono, contano più di tutto:
   è quello che protegge insieme l'albero e il Comune. */
.scheda .sigillo {
    background: var(--bosco);
    color: var(--chiaro);
    padding-top: var(--sc-vuoto);
    padding-bottom: var(--sc-vuoto);
}
.scheda .sigillo .sc-occhiello { color: #fff; }
.scheda .sigillo h2 {
    margin: var(--s-1) 0 0;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-titolo);
    line-height: 1.12;
    letter-spacing: -0.008em;
    color: #fff;
}
.scheda .tutele { padding: 0; margin-top: var(--s-3); }
.scheda .tutela {
    display: block;
    margin: 0 0 var(--s-1);
    padding: 0;
    border: 0;
    border-radius: 0;
    font-size: var(--t-corpo);
    line-height: 1.35;
    color: #fff;
}
.scheda .vincoli { padding: 0; margin-top: var(--s-3); border-top: 0; }
.scheda .vincolo {
    padding: var(--s-2) 0;
    border-bottom: 0;
    border-top: 1px solid rgba(255, 255, 255, 0.28);
}
.scheda .vincolo-codice, .scheda .vincolo a { font-weight: 600; color: #fff; }
.scheda .vincolo a { position: relative; text-decoration: none; border-bottom: 1px solid rgba(255, 255, 255, 0.6); }
/* Il codice apre il documento del vincolo: il segno resta dentro la frase, la
   zona da toccare arriva ai 44 px che vuole un pollice */
.scheda .vincolo a::after {
    content: "";
    position: absolute;
    left: -6px; right: -6px; top: 50%;
    height: 44px;
    transform: translateY(-50%);
}
.scheda .vincolo a:hover { border-bottom-color: #fff; }
.scheda .vincolo-nome { display: block; margin-left: 0; margin-top: 4px; color: var(--chiaro); }
/* L'ente che ha posto il vincolo si legge come tutto il resto del blocco: il
   --chiaro-2 con cui era scritto è il colore dei filetti sui fondi scuri (3:1),
   e su un bosco verde scendeva a 3,1:1, sotto il minimo del testo. */
.scheda .vincolo-ente {
    display: block;
    margin-top: 4px;
    font-size: var(--t-etichetta);
    line-height: 1.35;
    color: var(--chiaro);
}

/* ------------------------------------------------ contributo ambientale */
/* Le stime stanno in colonna sul telefono e affiancate appena c'è spazio:
   auto-fit, così tre valori o due si dispongono da soli */
.scheda .stime { display: grid; grid-template-columns: 1fr; gap: var(--s-3); margin-top: var(--s-3); }
.scheda .valore-stimato + .valore-stimato { padding-top: var(--s-3); border-top: 1px solid var(--filo); }
.scheda .valore-stimato .cifra {
    margin: 0;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-stima);
    line-height: 0.98;
    letter-spacing: -0.024em;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}
/* L'unità di misura non è una cifra: resta nel carattere del testo e piccola,
   o "kg/anno" peserebbe quanto il numero */
.scheda .valore-stimato .unita {
    font-family: var(--testo);
    font-size: var(--t-corpo);
    font-weight: 500;
    line-height: 1.35;
    letter-spacing: 0;
    color: var(--inchiostro-2);
    margin-left: 0.28em;
}
.scheda .valore-stimato .dice {
    margin: var(--s-1) 0 0;
    font-size: var(--t-etichetta);
    line-height: 1.62;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}
.scheda .stima {
    margin: var(--s-3) 0 0;
    padding: 0;
    font-size: var(--t-occhiello);
    line-height: 1.55;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}

/* --------------------------------------------------------- cronologia
   Il cuore della scheda: si deve leggere come un racconto. Un filo verticale
   tiene insieme le tappe, il pallino d'oro segna la data. */
.scheda .cronologia { padding-left: var(--sc-lato); padding-right: var(--sc-lato); border-top: 1px solid var(--filo); }
.scheda .cronologia h2 {
    margin: var(--s-1) 0 0;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-titolo);
    line-height: 1.12;
    letter-spacing: -0.008em;
    color: var(--bosco);
}
.scheda .tappe { margin: var(--s-3) 0 0; padding: 0; list-style: none; }
.scheda .evento {
    position: relative;
    margin: 0;
    padding: 0 0 var(--s-4) 26px;
    border-left: 0;
}
.scheda .evento::before {
    /* il filo, non il pallino: il pallino è l'elemento .bollo */
    content: "";
    position: absolute;
    left: 5px; top: 12px; bottom: 0;
    width: 1px;
    background: var(--filo-2);
}
.scheda .evento:last-child { padding-bottom: 0; }
.scheda .evento:last-child::before { display: none; }
.scheda .bollo {
    position: absolute;
    left: 0; top: 6px;
    width: 11px; height: 11px;
    border-radius: 50%;
    background: var(--oro);
    outline: 4px solid var(--carta);
}
.scheda .quando {
    margin: 0;
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--sc-titolo);
    line-height: 1;
    letter-spacing: -0.008em;
    text-transform: none;
    color: var(--bosco);
    font-variant-numeric: tabular-nums lining-nums;
}
.scheda .fatto {
    display: block;
    margin-top: var(--s-1);
    font-size: var(--t-occhiello);
    font-weight: 500;
    line-height: 1.35;
    letter-spacing: 0.16em;
    color: var(--inchiostro-2);
}
/* L'annotazione del tecnico è la sua voce: resta com'è stata scritta, a capo
   compresi, e si vede che è una citazione */
.scheda .nota-evento {
    margin: var(--s-2) 0 0;
    padding-left: var(--s-2);
    border-left: 2px solid var(--filo-2);
    font-size: var(--t-corpo);
    line-height: 1.5;
    color: var(--inchiostro);
    white-space: pre-line;
    text-wrap: pretty;
}
.scheda .foto-evento { display: flex; gap: var(--s-1); flex-wrap: wrap; margin: var(--s-2) 0 0; }
/* Le miniature sono pulsanti, non immagini cliccabili: così si aprono anche
   con la tastiera e il fuoco si vede */
.scheda .foto-evento button {
    width: 76px; height: 76px;
    padding: 0;
    overflow: hidden;
    border: 1px solid var(--filo);
    border-radius: var(--raggio);
    background: var(--avorio-2);
    cursor: pointer;
}
.scheda .foto-evento img { display: block; width: 100%; height: 100%; object-fit: cover; }
.scheda .atti { background: transparent; border-radius: 0; padding: 0; margin-top: var(--s-3); }
.scheda .atti-titolo { margin-bottom: var(--s-2); color: var(--oro-scuro); }
.scheda .atto {
    padding: var(--s-2);
    font-size: var(--t-etichetta);
    border: 1px solid var(--filo);
    border-left: 2px solid var(--inchiostro-2);
    border-radius: var(--raggio);
    background: var(--avorio-2);
}
.scheda .atto + .atto { margin-top: var(--s-1); }
.scheda .atto-riga { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px var(--s-2); }
.scheda .atto-nome, .scheda .atto-riga a { font-size: var(--t-corpo); line-height: 1.35; font-weight: 600; }
.scheda .atto-data { color: var(--inchiostro-2); white-space: nowrap; font-variant-numeric: tabular-nums; }
.scheda .atto-ente, .scheda .atto-descrizione { color: var(--inchiostro-2); line-height: 1.5; margin-top: 4px; }

/* ------------------------------------------------------- dove si trova */
.scheda .dato { padding: var(--s-2) 0; border-top: 1px solid var(--filo); }
.scheda .dato-nome {
    display: block;
    font-size: var(--t-occhiello);
    line-height: 1.2;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--inchiostro-2);
}
.scheda .dato-valore { display: block; margin-top: var(--s-1); font-size: var(--t-corpo); line-height: 1.35; }

.scheda .azioni { display: flex; flex-direction: column; padding: 0; margin-top: var(--s-3); gap: var(--s-1); }
/* Le due azioni in fondo alla scheda: la prima piena, la seconda a filo.
   Hanno la stessa forma dei pulsanti del portale (.sc-bottone), ma la scheda
   viaggia anche da sola dentro il pannello della mappa e non può contare su
   una classe dichiarata altrove. */
.scheda .azione {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    padding: 8px 20px;
    border-radius: var(--raggio);
    background: var(--bosco);
    color: var(--chiaro);
    border: 1px solid var(--bosco);
    font-family: var(--testo);
    font-size: var(--t-occhiello);
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    text-align: center;
    text-decoration: none;
}
.scheda .azione:hover { background: var(--notte); border-color: var(--notte); }
.scheda .azione.secondaria {
    background: transparent;
    color: var(--bosco);
    /* un bordo che si deve vedere vuole almeno 3:1, e --inchiostro-2 ne ha 4,5 */
    border: 1px solid var(--inchiostro-2);
    font-weight: 500;
}
.scheda .azione.secondaria:hover { border-color: var(--bosco); }
.scheda .azioni-nota {
    margin: var(--s-2) 0 0;
    font-size: var(--t-occhiello);
    line-height: 1.55;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}
.scheda .azioni-nota a { color: var(--bosco); }

/* --------------------------------------------------- quando c'è spazio
   Da 520 px in su la scheda respira: le misure vanno a due colonne e le due
   azioni stanno in riga. Sotto, e su ogni browser senza contenitori, resta
   la forma stretta: è quella giusta nel pannello della mappa e sul telefono. */
@container (min-width: 520px) {
    .scheda .foto { min-height: 320px; }
    .scheda .misure { grid-template-columns: 1fr 1fr; column-gap: var(--s-4); }
    .scheda .azioni { flex-direction: row; }
    .scheda .azioni .azione { flex: 1 1 0; }
    .scheda .foto-evento button { width: 96px; height: 96px; }
    .scheda .stime { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: var(--s-4); }
    .scheda .valore-stimato + .valore-stimato { padding-top: 0; border-top: 0; }
}

/* ------------------------------------------------------------------ stampa
   Un cittadino che stampa la scheda vuole il foglio, non i pulsanti: la lente
   e le due azioni non hanno senso sulla carta. Il bordo si scurisce: il
   filetto chiaro, in bianco e nero, non lascia traccia. */
@media print {
    .scheda { border-color: #999; }
    .scheda .lente, .scheda .azioni, .scheda .azioni-nota { display: none; }
}
</style>
@endonce

<article class="scheda{{ $contesto === 'pagina' ? ' scheda-pagina' : '' }}">

    {{-- Apertura: la fotografia se c'è, altrimenti un disegno che si dichiara
         tale. Sopra, il numero stampato sul cartellino. --}}
    <figure class="foto{{ $hasFoto ? '' : ' foto-disegno' }}">
        @if ($hasFoto)
            <img src="{{ $urlFoto }}" alt="Fotografia dell'elemento">
            <button type="button" class="lente" data-ingrandisci="{{ $urlFoto }}" aria-label="Ingrandisci la fotografia">+</button>
        @elseif ($albero)
            {{-- Disegno della chioma: masse piatte e nessuna ombra fotografica,
                 perché non sembri una fotografia che non è arrivata. Una sola
                 luce, da destra in alto, come in tutto il portale. --}}
            <svg class="disegno" viewBox="0 0 600 480" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
                <rect width="600" height="480" fill="var(--luce)"/>
                <circle cx="498" cy="96" r="34" fill="var(--luce-2)"/>
                <path d="M0 336 C 92 320 148 330 212 326 C 302 320 380 334 470 326 C 520 322 560 330 600 324 L600 480 L0 480 Z" fill="var(--fogliame-lontano)"/>
                <path d="M0 378 C 122 364 260 376 360 372 C 462 368 540 378 600 372 L600 480 L0 480 Z" fill="var(--fogliame-medio)"/>
                <ellipse cx="252" cy="394" rx="132" ry="16" fill="var(--fogliame-vicino)" opacity="0.28"/>
                <path d="M286 206 C 288 288 284 344 272 394 L 332 394 C 320 344 316 288 318 220 Z" fill="var(--corteccia-2)"/>
                <path d="M302 206 C 302 288 304 344 308 394 L 332 394 C 320 344 316 288 318 220 Z" fill="var(--corteccia)"/>
                <ellipse cx="300" cy="190" rx="182" ry="112" fill="var(--fogliame-medio)"/>
                <circle cx="230" cy="200" r="106" fill="var(--fogliame-vicino)"/>
                <circle cx="354" cy="186" r="118" fill="var(--fogliame-vicino)"/>
                <circle cx="296" cy="154" r="90" fill="var(--fogliame-vicino)"/>
                <circle cx="392" cy="156" r="78" fill="var(--luce-2)" opacity="0.42"/>
                <circle cx="434" cy="202" r="44" fill="var(--luce-2)" opacity="0.3"/>
            </svg>
        @else
            {{-- Non è un albero: al posto della chioma il ritaglio di carta del
                 censimento, con il segno del punto rilevato. --}}
            <svg class="disegno" viewBox="0 0 600 480" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
                <rect width="600" height="480" fill="var(--m-fondo)"/>
                <path d="M0 78 C 140 54 260 100 388 78 C 480 62 540 82 600 70" fill="none" stroke="var(--m-iso)" stroke-width="3"/>
                <path d="M0 332 C 132 310 268 354 392 330 C 486 312 546 332 600 322" fill="none" stroke="var(--m-iso)" stroke-width="3"/>
                <path d="M62 136 L 236 100 L 268 240 L 94 278 Z" fill="var(--m-parco)" stroke="var(--m-orlo)" stroke-width="4"/>
                <path d="M346 92 L 452 72 L 470 160 L 364 180 Z" fill="var(--m-casa)"/>
                <path d="M392 218 L 512 196 L 528 274 L 408 296 Z" fill="var(--m-casa)"/>
                <path d="M0 186 L 600 118" fill="none" stroke="var(--m-orlo)" stroke-width="12"/>
                <circle cx="300" cy="182" r="34" fill="none" stroke="var(--carta)" stroke-width="8"/>
                <circle cx="300" cy="182" r="34" fill="none" stroke="var(--bosco)" stroke-width="4"/>
                <circle cx="300" cy="182" r="11" fill="var(--bosco)"/>
            </svg>
        @endif

        <figcaption class="velo">
            @if ($codice !== '')
                <p class="velo-occhiello">Cartellino numero</p>
                <p class="cartellino sc-num">{{ $codice }}</p>
            @else
                <p class="velo-occhiello">Elemento censito</p>
                <p class="cartellino cartellino-muto">Senza cartellino</p>
            @endif
            <span class="filo-oro" aria-hidden="true"></span>

            @if ($albero || $asset->surveyed_at)
                <p class="velo-piede">
                    @if ($albero)
                        {{-- Lo stato racconta la salute di una pianta: su un prato o un
                             arredo non avrebbe senso --}}
                        <span class="sc-targhetta" style="--c: {{ \App\Services\Portale\PortalState::colore($stato) }}">{{ \App\Services\Portale\PortalState::etichetta($stato) }}</span>
                    @endif
                    @if ($asset->surveyed_at)
                        <span>rilevato il <span class="sc-num">{{ $asset->surveyed_at->format('d/m/Y') }}</span></span>
                    @endif
                </p>
            @endif

            @unless ($hasFoto)
                <p class="velo-nota">Disegno. Per questo elemento non è pubblicata una fotografia.</p>
            @endunless
        </figcaption>
    </figure>

    {{-- Identità: il nome che si riconosce, poi quello botanico. --}}
    <header class="identita lato">
        @if ($tipoRiga !== '')
            <span class="tipo">{{ $tipoRiga }}</span>
        @endif

        @if ($titolo !== null)
            <h1 class="nome">{{ $titolo }}</h1>
            @if ($binomio !== '' || $famiglia !== '')
                <p class="specie">
                    @if ($binomio !== '')<em>{{ $binomio }}</em>@endif
                    @if ($famiglia !== '')<span>famiglia {{ $famiglia }}</span>@endif
                </p>
            @endif
        @else
            {{-- Senza nome comune il titolo è il nome botanico: resta in corsivo,
                 come si scrive un nome di specie --}}
            <h1 class="specie nome-botanico"><em>{{ $binomio }}</em></h1>
            @if ($famiglia !== '')
                <p class="specie"><span>famiglia {{ $famiglia }}</span></p>
            @endif
        @endif

        @if (! empty($misure))
            <dl class="misure">
                @foreach ($misure as [$etichettaMisura, $valoreMisura, $spiegaMisura])
                    <div class="misura">
                        <dt>
                            {{ $etichettaMisura }}
                            @if ($spiegaMisura)<span class="misura-spiega">{{ $spiegaMisura }}</span>@endif
                        </dt>
                        <dd class="sc-num">{{ $valoreMisura }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </header>

    @if ($haTutele)
        <aside class="sigillo lato">
            <p class="sc-occhiello">Tutela</p>
            <h2>Che cosa protegge questo elemento</h2>

            @if ($albero?->is_monumental || $albero?->is_protected)
                <div class="tutele">
                    @if ($albero->is_monumental)
                        <span class="tutela">{{ 'Albero monumentale'.($albero->monumental_ref ? ' – '.$albero->monumental_ref : '') }}</span>
                    @endif
                    @if ($albero->is_protected)
                        <span class="tutela">{{ 'Soggetto a tutela'.($albero->protection_ref ? ' – '.$albero->protection_ref : '') }}</span>
                    @endif
                </div>
            @endif

            @if (! empty($vincoli))
                <div class="vincoli">
                    <div class="vincoli-titolo sc-occhiello">Vincoli</div>
                    @foreach ($vincoli as $vincolo)
                        <div class="vincolo">
                            @if ($vincolo->document_id)
                                <a href="{{ $portale->url('/vincolo/'.$vincolo->id.'/documento') }}" target="_blank" rel="noopener">{{ $vincolo->code }}</a>
                            @else
                                <span class="vincolo-codice">{{ $vincolo->code }}</span>
                            @endif
                            @if ($vincolo->name)
                                <span class="vincolo-nome">{{ $vincolo->name }}</span>
                            @endif
                            @if ($vincolo->authority)
                                <span class="vincolo-ente">{{ $vincolo->authority }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </aside>
    @endif

    @if (! empty($co2))
        <section class="sezione lato">
            <p class="sc-occhiello sc-occhiello-oro">Valori stimati, non misurati</p>
            <h2 class="titolo-sezione">Il contributo di questa pianta</h2>

            <div class="stime">
                <div class="valore-stimato">
                    <p class="cifra sc-num">{{ number_format($co2['co2_kg'], 0, ',', '.') }}<span class="unita">kg</span></p>
                    <p class="dice">Anidride carbonica immagazzinata<span class="sc-ast">*</span></p>
                </div>
                @if ($co2['annuo_kg'] !== null)
                    <div class="valore-stimato">
                        <p class="cifra sc-num">{{ number_format($co2['annuo_kg'], 1, ',', '.') }}<span class="unita">kg/anno</span></p>
                        <p class="dice">Assorbimento medio annuo<span class="sc-ast">*</span></p>
                    </div>
                @endif
                @if (($co2['valore_euro'] ?? null) !== null)
                    <div class="valore-stimato">
                        <p class="cifra sc-num">{{ number_format($co2['valore_euro'], 0, ',', '.') }}<span class="unita">euro</span></p>
                        <p class="dice">Controvalore economico stimato<span class="sc-ast">*</span></p>
                    </div>
                @endif
            </div>

            <p class="stima">
                <span class="sc-ast">*</span> Valore stimato, non misurato: {{ $co2['metodo'] }}.
                @if (($co2['valore_euro'] ?? null) !== null)
                    Il controvalore economico applica un prezzo di
                    {{-- Un prezzo con i decimali si dichiara con i decimali: quello
                         stampato deve essere esattamente quello applicato --}}
                    {{ number_format($co2['prezzo_tonnellata'], fmod($co2['prezzo_tonnellata'], 1.0) == 0.0 ? 0 : 2, ',', '.') }} euro
                    per tonnellata di anidride carbonica ({{ $co2['prezzo_fonte'] }}).
                @endif
            </p>
        </section>
    @endif

    @if (! empty($cronologia))
        <section class="cronologia sezione">
            <p class="sc-occhiello sc-occhiello-oro">La cura di questo elemento</p>
            <h2>Cronologia eventi</h2>
            <p class="guida-sezione">Ogni intervento resta qui con la sua data, quello che è stato annotato in campo e l'atto pubblico che lo ha disposto.</p>

            <ol class="tappe">
                @foreach ($cronologia as $evento)
                    <li class="evento">
                        <span class="bollo" aria-hidden="true"></span>
                        <p class="quando sc-num">{{ $evento['data']->format('d/m/Y') }}</p>
                        <strong class="fatto">{{ $evento['titolo'] }}</strong>

                        @if ($evento['nota'] !== '')
                            <p class="nota-evento">{{ $evento['nota'] }}</p>
                        @endif

                        @if (! empty($evento['foto']))
                            <div class="foto-evento">
                                @foreach ($evento['foto'] as $idFoto)
                                    @php $urlFotoEvento = $portale->url('/elemento/'.rawurlencode($riferimento).'/foto/'.$idFoto); @endphp
                                    <button type="button" data-ingrandisci="{{ $urlFotoEvento }}" aria-label="Ingrandisci la fotografia dell'intervento">
                                        <img src="{{ $urlFotoEvento }}" alt="">
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($evento['atti']))
                            <div class="atti">
                                <div class="atti-titolo sc-occhiello">Atti pubblici collegati all'evento</div>
                                @foreach ($evento['atti'] as $atto)
                                    <div class="atto">
                                        <div class="atto-riga">
                                            @if ($atto['url'])
                                                <a href="{{ $atto['url'] }}">{{ $atto['tipo'] }} N. {{ $atto['numero'] }}</a>
                                            @else
                                                <span class="atto-nome">{{ $atto['tipo'] }} N. {{ $atto['numero'] }}</span>
                                            @endif
                                            <span class="atto-data sc-num">{{ $atto['data']->format('d/m/Y') }}</span>
                                        </div>
                                        <div class="atto-ente">{{ $atto['ente'] }}</div>
                                        @if ($atto['descrizione'])
                                            <div class="atto-descrizione">{{ $atto['descrizione'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <section class="sezione lato">
        <p class="sc-occhiello sc-occhiello-oro">Dove si trova</p>
        <h2 class="titolo-sezione">{{ $asset->area?->name ?: 'Posizione rilevata' }}</h2>

        @if ($asset->area?->locality?->name)
            <div class="dato">
                <span class="dato-nome">Località</span>
                <span class="dato-valore">{{ $asset->area->locality->name }}</span>
            </div>
        @endif
        <div class="dato">
            <span class="dato-nome">Coordinate</span>
            <span class="dato-valore sc-num">
                @if ($urlPosizione)
                    <a class="sc-collegamento" href="{{ $urlPosizione }}" target="_blank" rel="noopener nofollow">{{ number_format($lat, 6, '.', '') }}, {{ number_format($lon, 6, '.', '') }}</a>
                @else
                    {{ number_format($lat, 6, '.', '') }}, {{ number_format($lon, 6, '.', '') }}
                @endif
            </span>
        </div>

        @if ($urlNavigazione || $urlSegnalazione)
            <div class="azioni">
                @if ($urlNavigazione)
                    <a class="azione" href="{{ $urlNavigazione }}" target="_blank" rel="noopener nofollow">Raggiungi l'elemento</a>
                @endif
                @if ($urlSegnalazione)
                    <a class="azione secondaria" href="{{ $urlSegnalazione }}">Segnala un problema</a>
                @endif
            </div>
            @if ($urlNavigazione)
                <p class="azioni-nota">Il primo collegamento apre le mappe del telefono sul punto rilevato.</p>
            @endif
            @if ($urlSegnalazione)
                <p class="azioni-nota">
                    La segnalazione apre un messaggio già intestato a
                    <a class="sc-collegamento" href="mailto:{{ $portale->contactEmail() }}">{{ $portale->contactEmail() }}</a>,
                    con il numero del cartellino già scritto: descriva che cosa ha visto e, se può, alleghi una fotografia.
                </p>
            @endif
        @endif
    </section>
</article>
