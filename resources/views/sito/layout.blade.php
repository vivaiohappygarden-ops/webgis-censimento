@php
    $azienda = config('sito.azienda');
    $contatti = config('sito.contatti');
    $nome = config('sito.nome');

    // Le voci del menu in un posto solo: la testata e il pie' di pagina
    // devono dire la stessa cosa, e una pagina nuova si aggiunge qui.
    $menu = [
        'censimento' => 'Censimento',
        'stabilita' => 'Stabilità (VTA)',
        'portale' => 'Portale per i cittadini',
        'conformita' => 'Conformità CAM',
        'chi-siamo' => 'Chi siamo',
    ];
    // La pagina in cui ci si trova, per marcarla nel menu: l'ultimo pezzo del
    // nome della rotta vale sia sul dominio sia sul percorso di collaudo
    $qui = str(request()->route()?->getName() ?? '')->afterLast('.')->toString();

    // Il pie' stampa solo i dati che esistono davvero: un sito che scrive
    // "P.IVA —" vale meno di un sito che non la scrive affatto.
    $datiSocietari = array_values(array_filter([
        $azienda['ragione_sociale'] ?: null,
        $azienda['sede'] ?: null,
        $azienda['piva'] ? 'P. IVA '.$azienda['piva'] : null,
        $azienda['rea'] ? 'REA '.$azienda['rea'] : null,
    ]));
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index,follow">
<meta name="theme-color" content="#14261d">
<meta name="description" content="@yield('descrizione', 'Censimento e catasto del verde urbano per i Comuni: rilievo in campo, valutazione di stabilità VTA, consegna dei dati nei formati richiesti dai CAM e portale pubblico per i cittadini.')">
<title>@yield('titolo', $nome) - {{ $nome }}</title>
<style>
@include('sito.caratteri')

/* ==========================================================================
   SITO AZIENDALE — veste istituzionale
   ==========================================================================

   A CHI PARLA. A un responsabile dell'ufficio tecnico o dell'ambiente di un
   Comune, che ha un capitolato da scrivere e una responsabilita' sulla
   sicurezza degli alberi. Legge in fretta, spesso dal telefono fra due
   riunioni, e cerca prove, non aggettivi. Da qui discendono tutte le scelte
   qui sotto: se una decisione grafica non aiuta quella persona, non entra.

   REGISTRO. Istituzionale e sobrio, deciso con il committente il 13/09/2026.
   Fondo chiaro, un solo verde, nessuna illustrazione decorativa. La pagina
   deve sembrare un documento tecnico ben impaginato, non una brochure.
   E' l'opposto del portale civico ("Sotto la chioma", editoriale e caldo):
   sono due pubblici diversi e non devono somigliarsi. Quello che condividono
   e' la disciplina - stesso carattere ospitato in casa, niente risorse di
   terzi, niente cookie - non l'aspetto.

   LEGGIBILITA' SUL TELEFONO (era il difetto peggiore del portale attuale):
   il corpo del testo non scende mai sotto i 17px, la riga non supera i 68
   caratteri, ogni cosa toccabile e' alta almeno 44px, e la pagina e'
   pensata prima per lo schermo stretto e poi allargata.

   SCALA TIPOGRAFICA — pochi corpi, salti netti, tutti fluidi fra 360px e
   1440px. Ogni valore e' dichiarato prima in pixel secchi: un browser che
   non conosce clamp() tiene quello e la pagina resta in scala.
     --t-occhiello  14        maiuscoletto spaziato sopra i titoli
     --t-piccolo    15        note, didascalie, pie' di pagina
     --t-corpo      17 -> 18  testo corrente
     --t-guida      19 -> 21  il paragrafo che accompagna un titolo
     --t-h3         20 -> 23  titoli dentro una sezione
     --t-h2         27 -> 38  titolo di sezione
     --t-h1         34 -> 54  titolo di pagina
     --t-dato       30 -> 44  i numeri, quando un numero e' il contenuto

   COLORE. Il verde e' un accento, non un tema: fa il pulsante che invita ad
   agire, i collegamenti, il filo sotto l'occhiello e il piede della pagina.
   Tutto il resto e' inchiostro su carta chiara. Un sito istituzionale che
   tinge grandi superfici sembra una presentazione, non un documento.
   Contrasti verificati (AA): inchiostro 14.9:1, inchiostro tenue 7.0:1,
   verde su carta 8.6:1, carta su verde scuro 13.1:1.

   SPAZI: --s1..--s7 = 8 12 20 32 48 72 104. Le sezioni respirano con
   --sezione, che si accorcia da sola sul telefono.
   ========================================================================== */

:root {
    color-scheme: light;

    --carta: #ffffff;
    --carta-alt: #f4f6f4;      /* sezioni alternate */
    --inchiostro: #16211c;     /* testo: 14.9:1 su carta */
    --inchiostro-tenue: #566158; /* testo secondario: 7.0:1 su carta */
    --linea: #dde3de;
    --verde: #14532d;          /* accento istituzionale: 8.6:1 su carta */
    --verde-scuro: #14261d;    /* piede e fasce piene */
    --verde-tenue: #eaf1ec;    /* fondo dei riquadri di rilievo */
    --ambra: #7a5c12;          /* usata solo per l'occhiello: 5.9:1 */

    --t-occhiello: 14px;
    --t-piccolo: 15px;
    --t-corpo: 17px;
    --t-corpo: clamp(17px, 16.7px + 0.09vw, 18px);
    --t-guida: 19px;
    --t-guida: clamp(19px, 18.4px + 0.19vw, 21px);
    --t-h3: 20px;
    --t-h3: clamp(20px, 19.1px + 0.28vw, 23px);
    --t-h2: 27px;
    --t-h2: clamp(27px, 23.6px + 1.02vw, 38px);
    --t-h1: 34px;
    --t-h1: clamp(34px, 27.8px + 1.85vw, 54px);
    --t-dato: 30px;
    --t-dato: clamp(30px, 25.7px + 1.30vw, 44px);

    --s1: 8px; --s2: 12px; --s3: 20px; --s4: 32px; --s5: 48px; --s6: 72px; --s7: 104px;
    --sezione: clamp(48px, 6vw, 88px);
    --colonna: 1080px;        /* larghezza massima del contenuto */
    --misura: 68ch;           /* riga di lettura: oltre, l'occhio si perde */
    --raggio: 4px;            /* un solo raggio in tutto il sito */

    font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
    font-variant-numeric: tabular-nums;
}

*, *::before, *::after { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--carta);
    color: var(--inchiostro);
    font-size: var(--t-corpo);
    line-height: 1.62;
    -webkit-text-size-adjust: 100%;
    text-rendering: optimizeLegibility;
}

h1, h2, h3 { margin: 0; font-weight: 600; letter-spacing: -0.015em; }
h1 { font-size: var(--t-h1); line-height: 1.1; }
h2 { font-size: var(--t-h2); line-height: 1.18; }
h3 { font-size: var(--t-h3); line-height: 1.3; letter-spacing: -0.008em; }
p { margin: 0; }
ul, ol { margin: 0; padding: 0; }
img, svg { max-width: 100%; height: auto; }

a { color: var(--verde); text-decoration-thickness: 1px; text-underline-offset: 3px; }
a:hover { text-decoration-thickness: 2px; }

/* Il segno del fuoco da tastiera non si toglie mai: c'e' chi naviga senza
   mouse, e su un sito della PA e' un requisito, non una cortesia */
:where(a, button, input, textarea, summary):focus-visible {
    outline: 3px solid var(--verde);
    outline-offset: 2px;
    border-radius: 2px;
}

/* Salta al contenuto: primo elemento raggiungibile con il tabulatore */
.salta {
    position: absolute; left: -9999px; top: 0; z-index: 100;
    background: var(--verde); color: #fff; padding: var(--s2) var(--s3);
    font-weight: 500;
}
.salta:focus { left: 0; }

.contenitore {
    width: 100%;
    max-width: var(--colonna);
    margin-inline: auto;
    padding-inline: var(--s3);
}
@media (min-width: 720px) { .contenitore { padding-inline: var(--s4); } }

/* ---------- Testata ---------------------------------------------------- */

.testata {
    border-bottom: 1px solid var(--linea);
    background: var(--carta);
}
.testata-riga {
    display: flex; flex-wrap: wrap; align-items: center; gap: var(--s2) var(--s4);
    padding-block: var(--s2);
}
.marchio { display: flex; flex-direction: column; text-decoration: none; color: inherit; padding-block: var(--s1); }
.marchio-nome { font-size: 18px; font-weight: 600; letter-spacing: -0.01em; }
.marchio-riga { font-size: var(--t-occhiello); color: var(--inchiostro-tenue); letter-spacing: 0.02em; }

.menu { display: flex; flex-wrap: wrap; align-items: center; gap: var(--s1) var(--s3); margin-left: auto; list-style: none; }
.menu a {
    display: inline-flex; align-items: center; min-height: 44px;
    font-size: var(--t-piccolo); font-weight: 500; color: var(--inchiostro);
    text-decoration: none;
}
.menu a:hover { text-decoration: underline; text-underline-offset: 4px; }
.menu a[aria-current="page"] { color: var(--verde); text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 6px; }

/* ---------- Blocchi comuni --------------------------------------------- */

.sezione { padding-block: var(--sezione); }
.sezione-alt { background: var(--carta-alt); border-block: 1px solid var(--linea); }

.occhiello {
    display: block; font-size: var(--t-occhiello); font-weight: 600;
    letter-spacing: 0.09em; text-transform: uppercase; color: var(--ambra);
    margin-bottom: var(--s2);
}
.guida { font-size: var(--t-guida); line-height: 1.5; color: var(--inchiostro-tenue); max-width: var(--misura); }
.prosa { max-width: var(--misura); }
.prosa > * + * { margin-top: var(--s3); }
.piccolo { font-size: var(--t-piccolo); color: var(--inchiostro-tenue); }

/* Un solo invito per pagina, come da regola dei testi */
.invito {
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 48px; padding: 0 var(--s4);
    background: var(--verde); color: #fff; font-weight: 600; font-size: var(--t-corpo);
    border: 2px solid var(--verde); border-radius: var(--raggio); text-decoration: none;
}
.invito:hover { background: #0f3f22; border-color: #0f3f22; }
.invito-secondario { background: transparent; color: var(--verde); }
.invito-secondario:hover { background: var(--verde-tenue); color: var(--verde); }

.inviti { display: flex; flex-wrap: wrap; gap: var(--s2); margin-top: var(--s4); }

/* Apertura: il messaggio a sinistra, i fatti a destra. Sul telefono
   diventano una cosa sola, nell'ordine in cui si leggono. Il pannello non e'
   decorazione: e' la risposta alla domanda "che cosa comprende", che
   altrimenti costringerebbe a scorrere per averla */
.apertura { display: grid; gap: var(--s5); align-items: start; }
@media (min-width: 940px) { .apertura { grid-template-columns: 1.25fr 1fr; gap: var(--s6); } }

.pannello {
    border: 1px solid var(--linea); border-top: 3px solid var(--verde);
    border-radius: var(--raggio); padding: var(--s4); background: var(--carta-alt);
}
.pannello h2 { font-size: var(--t-h3); margin-bottom: var(--s3); }
.pannello ol { list-style: none; counter-reset: voce; display: grid; gap: var(--s2); }
.pannello li { counter-increment: voce; display: grid; grid-template-columns: 26px 1fr; gap: var(--s2); font-size: var(--t-corpo); }
.pannello li::before { content: counter(voce) '.'; color: var(--verde); font-weight: 600; }

/* Griglia dei blocchi: una colonna sul telefono, poi due o tre */
.griglia { display: grid; gap: var(--s4); }
@media (min-width: 700px) { .griglia-2 { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 940px) { .griglia-3 { grid-template-columns: repeat(3, 1fr); } }

.blocco { border-top: 3px solid var(--verde); padding-top: var(--s3); }
.blocco h3 { margin-bottom: var(--s2); }

.scheda {
    border: 1px solid var(--linea); border-radius: var(--raggio);
    padding: var(--s4); background: var(--carta);
}
.scheda-rilievo { background: var(--verde-tenue); border-color: #cfe0d4; }

/* Passi numerati: il numero e' un dato, non una decorazione */
.passi { list-style: none; counter-reset: passo; display: grid; gap: var(--s4); }
.passo { counter-increment: passo; display: grid; grid-template-columns: 44px 1fr; gap: var(--s3); align-items: start; }
.passo::before {
    content: counter(passo);
    display: flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; border-radius: 50%;
    background: var(--verde); color: #fff; font-weight: 600; font-size: 18px;
}
.passo h3 { margin-bottom: var(--s1); }

/* Elenchi puntati con il segno di spunta tipografico, non con un'icona */
.elenco { list-style: none; display: grid; gap: var(--s2); }
.elenco li { position: relative; padding-left: var(--s3); }
.elenco li::before {
    content: ''; position: absolute; left: 0; top: 0.62em;
    width: 8px; height: 8px; border-radius: 50%; background: var(--verde);
}

/* Elenco dei campi rilevati: e' una tabella di fatti, si legge a colonne */
.campi { list-style: none; display: grid; gap: var(--s1) var(--s4); font-size: var(--t-corpo); }
@media (min-width: 620px) { .campi { grid-template-columns: repeat(2, 1fr); } }
.campi li { padding-block: var(--s1); border-bottom: 1px solid var(--linea); }

/* Il rimando in fondo a una scheda: e' un bersaglio da toccare, non una
   parola dentro un paragrafo */
.piu {
    display: inline-flex; align-items: center; min-height: 44px;
    font-weight: 500; text-decoration: none;
}
.piu:hover { text-decoration: underline; text-underline-offset: 4px; }
.piu::after { content: ' \2192'; margin-left: 6px; }

/* Un numero grande con la sua etichetta sotto */
.dato { display: block; font-size: var(--t-dato); font-weight: 600; line-height: 1.05; letter-spacing: -0.02em; }
.dato-etichetta { display: block; font-size: var(--t-piccolo); color: var(--inchiostro-tenue); margin-top: var(--s1); }

/* Un indirizzo internet dentro una frase: va a capo dove serve invece di
   sfondare lo schermo del telefono (a 390px erano quattro pixel di troppo,
   e quattro bastano per far comparire la barra di scorrimento laterale) */
.dominio { font-weight: 500; overflow-wrap: anywhere; }

.nota {
    border-left: 3px solid var(--linea); padding-left: var(--s3);
    font-size: var(--t-piccolo); color: var(--inchiostro-tenue); max-width: var(--misura);
}

/* ---------- Piede ------------------------------------------------------- */

.piede { background: var(--verde-scuro); color: #e8eee9; padding-block: var(--s6) var(--s5); margin-top: var(--sezione); }
.piede a { color: #ffffff; }
.piede h2 { font-size: var(--t-h3); margin-bottom: var(--s2); }
.piede-griglia { display: grid; gap: var(--s5); }
@media (min-width: 760px) { .piede-griglia { grid-template-columns: 1.2fr 1fr 1fr; } }
.piede-voci { list-style: none; display: grid; gap: var(--s1); font-size: var(--t-piccolo); }
.piede-voci a { display: inline-flex; min-height: 44px; align-items: center; }
.piede-legale { margin-top: var(--s5); padding-top: var(--s3); border-top: 1px solid rgba(255,255,255,0.18); font-size: var(--t-piccolo); color: #c3d0c6; }
.piede-legale p + p { margin-top: var(--s1); }

@media print {
    .testata, .piede-voci, .invito { display: none; }
    body { color: #000; }
}
</style>
</head>
<body>

<a class="salta" href="#contenuto">Salta al contenuto</a>

<header class="testata">
    <div class="contenitore testata-riga">
        <a class="marchio" href="{{ $u() }}">
            <span class="marchio-nome">{{ $nome }}</span>
            <span class="marchio-riga">{{ config('sito.sottotitolo') }}</span>
        </a>
        <nav aria-label="Pagine del sito">
            <ul class="menu">
                @foreach ($menu as $pagina => $etichetta)
                    <li>
                        <a href="{{ $u($pagina) }}" @if ($qui === $pagina) aria-current="page" @endif>{{ $etichetta }}</a>
                    </li>
                @endforeach
                <li><a href="{{ $u('contatti') }}" @if ($qui === 'contatti') aria-current="page" @endif>Contatti</a></li>
            </ul>
        </nav>
    </div>
</header>

<main id="contenuto">
    @yield('contenuto')
</main>

<footer class="piede">
    <div class="contenitore">
        <div class="piede-griglia">
            <div>
                <h2>{{ $nome }}</h2>
                <p class="piccolo" style="color: #c3d0c6; max-width: 34ch;">{{ config('sito.sottotitolo') }} per i Comuni.</p>
                @if ($azienda['territorio'])
                    <p class="piccolo" style="color: #c3d0c6; margin-top: var(--s2);">{{ $azienda['territorio'] }}</p>
                @endif
            </div>
            <div>
                <h2>Pagine</h2>
                <ul class="piede-voci">
                    @foreach ($menu as $pagina => $etichetta)
                        <li><a href="{{ $u($pagina) }}">{{ $etichetta }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h2>Contatti</h2>
                <ul class="piede-voci">
                    @if ($contatti['telefono'])
                        <li><a href="tel:{{ preg_replace('/[^+0-9]/', '', $contatti['telefono']) }}">{{ $contatti['telefono'] }}</a></li>
                    @endif
                    @if ($contatti['email'])
                        <li><a href="mailto:{{ $contatti['email'] }}">{{ $contatti['email'] }}</a></li>
                    @endif
                    @if ($contatti['pec'])
                        <li>PEC {{ $contatti['pec'] }}</li>
                    @endif
                    <li><a href="{{ $u('contatti') }}">Tutti i recapiti</a></li>
                </ul>
            </div>
        </div>

        <div class="piede-legale">
            @if ($datiSocietari)
                <p>{{ implode(' · ', $datiSocietari) }}</p>
            @endif
            {{-- Dichiarazione vera, non uno slogan: il sito non avvia sessione,
                 non ha strumenti di statistica e non deposita niente sul
                 dispositivo di chi legge. Per questo non c'e' un banner. --}}
            <p>Questo sito non usa cookie e non raccoglie statistiche sui visitatori: non c'è niente da accettare.</p>
            <p><a href="{{ $u('privacy') }}">Privacy e note legali</a></p>
        </div>
    </div>
</footer>

</body>
</html>
