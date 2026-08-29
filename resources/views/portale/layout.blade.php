@php
    // La tavolozza si calcola qui, una volta per pagina: tutti i colori del
    // portale nascono dal solo colore scelto dal Comune (App\Support\PortalPalette).
    $tavolozza = \App\Support\PortalPalette::da($portale->color());
    $posta = $portale->contactEmail();

    // La segnalazione dal menu è una mail all'ente, come quella della scheda:
    // il portale non raccoglie moduli, non avendo né sessione né cookie.
    $urlSegnala = $posta === null ? null : 'mailto:'.$posta.'?'.http_build_query(
        ['subject' => 'Segnalazione dal portale del verde di '.$portale->name()],
        '', '&', PHP_QUERY_RFC3986
    );
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="{{ $indicizzabile ?? false ? 'index,follow' : 'noindex' }}">
<meta name="theme-color" content="{{ $tavolozza->colore('notte') }}">
<title>@yield('titolo', 'Censimento del verde') - {{ $portale->name() }}</title>
<style>
{{-- I caratteri sono ospitati sul nostro server: nessuna chiamata a terzi --}}
@include('portale.caratteri')

/* ==========================================================================
   SOTTO LA CHIOMA — veste del portale pubblico del verde
   Le fondamenta: tavolozza, scala tipografica, testata, piede e gli stili
   che le pagine si dividono. Le tre pagine (home, scheda, mappa) non
   ridichiarano né colori né corpi: prendono da qui.

   SCALA TIPOGRAFICA — pochi corpi, salti veri. Ogni valore è fluido fra il
   telefono a 360px e lo schermo largo a 1600px.
     --t-occhiello  13        maiuscoletto spaziato: occhielli, menu, etichette
     --t-etichetta  14        note, didascalie, dati minori
     --t-corpo      16 -> 17  testo corrente
     --t-guida      17 -> 20  il testo che accompagna un titolo
     --t-h3         22 -> 27  nome botanico, titoli dentro una sezione
     --t-h2         28 -> 54  titolo di sezione
     --t-h1         36 -> 96  titolo di pagina, apertura
   Il numero del cartellino non è qui: ha la sua scala dentro il foglio della
   scheda, che è fluido sul contenitore e non sulla finestra.
   Le bozze scendevano a 11 e 12: qui no. Questo portale si legge in strada,
   davanti alla pianta, e nessun testo va sotto i 13px.

   INTERLINEE (tabella unica, non se ne inventano altre)
     serif >= 36: 0.98 | serif 22-35: 1.12 | 20: 1.34
     testo 17: 1.58 prosa, 1.35 se è un valore su una riga
     testo 14: 1.62 prosa, 1.35 se è un'etichetta | 13 maiuscoletto: 1.20
   CRENATURA del serif: h1 -.025em, h2 -.018em, h3 -.008em, 20 -.004em, sotto 0.
   Eccezione dichiarata: le cifre del cartellino tengono la rampa positiva.

   SPAZI  --s-1 .. --s-9 = 8 16 24 32 48 64 88 112 128; gli ultimi cinque si
   accorciano sul telefono. Un solo raggio (3px).

   ORO  ha tre mestieri e basta: l'occhiello che apre una sezione, l'asterisco
   dei valori stimati e il filo sotto il campo di ricerca. Fuori di qui
   smetterebbe di essere un accento.
   ========================================================================== */

:root {
    color-scheme: light;

    /* Il colore scelto dal Comune, così com'è scritto nel gestionale. Nessuna
       regola lo usa — tutti i colori nascono dalla tavolozza qui sotto — ma è
       la sola cosa da cui dipende tutto il resto: scritto qui si legge dal
       browser quando un Comune dice "il mio colore non è questo". */
    --tinta: {{ $portale->color() }};

    /* La tavolozza, già calcolata in PHP: nessun color-mix da eseguire nel
       browser, quindi nessun colore perduto sui browser vecchi degli uffici */
{!! $tavolozza->righeCss('    ') !!}

    /* I quattro stati della pianta non si ritingono mai: sono il codice del
       portale, uguale in ogni Comune (App\Services\Portale\PortalState) */
@foreach (\App\Services\Portale\PortalState::COLORI as $chiave => $colore)
    --stato-{{ $chiave }}: {{ $colore }};
@endforeach

    /* Caratteri. Se il woff2 non arriva la pagina resta leggibile con i
       caratteri di sistema: le due famiglie di ripiego sono dichiarate. */
    --titolo: 'Fraunces', 'Iowan Old Style', Georgia, 'Times New Roman', serif;
    --testo: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;

    /* Scala tipografica. Ogni corpo è dichiarato due volte: prima in pixel
       secchi, poi con clamp(). Un browser che non conosce clamp() tiene il
       primo valore e la pagina resta in scala. */
    --t-occhiello: 13px;
    --t-etichetta: 14px;
    --t-corpo: 16px;
    --t-corpo: clamp(16px, 15.71px + 0.081vw, 17px);
    --t-guida: 18px;
    --t-guida: clamp(17px, 16.13px + 0.242vw, 20px);
    --t-h3: 24px;
    --t-h3: clamp(22px, 20.55px + 0.403vw, 27px);
    --t-h2: 34px;
    --t-h2: clamp(28px, 20.45px + 2.097vw, 54px);
    --t-h1: 46px;
    --t-h1: clamp(36px, 18.58px + 4.839vw, 96px);

    /* Spazi */
    --s-1: 8px;
    --s-2: 16px;
    --s-3: 24px;
    --s-4: 32px;
    --s-5: 40px;
    --s-5: clamp(32px, 3vw, 48px);
    --s-6: 52px;
    --s-6: clamp(40px, 4.4vw, 64px);
    --s-7: 64px;
    --s-7: clamp(48px, 6vw, 88px);
    --s-8: 80px;
    --s-8: clamp(56px, 7.5vw, 112px);
    --s-9: 96px;
    --s-9: clamp(64px, 8.6vw, 128px);

    /* Misure di pagina */
    --bordo-pagina: 24px;
    --bordo-pagina: clamp(20px, 4vw, 40px);
    --misura: 1160px;
    --misura-testo: 940px;
    --raggio: 3px;
    --ombra: 0 30px 72px -34px rgba(0, 0, 0, 0.6);
}

/* ------------------------------------------------------------------ base */
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--avorio);
    color: var(--inchiostro);
    font-family: var(--testo);
    font-size: var(--t-corpo);
    line-height: 1.58;
    /* Un nome botanico o un indirizzo di posta lungo non deve spingere la
       pagina fuori dallo schermo di un telefono da 360px */
    overflow-wrap: break-word;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

img { max-width: 100%; }
h1, h2, h3 { margin: 0; font-weight: 400; }
p { margin: 0 0 var(--s-2); }
a { color: var(--bosco); text-underline-offset: 0.18em; }

/* Il fuoco si vede sempre, e cambia colore secondo il fondo su cui sta */
:focus-visible {
    outline: 3px solid var(--oro-scuro);
    outline-offset: 2px;
}
.testata :focus-visible,
.chiusura :focus-visible,
.sc-scuro :focus-visible {
    outline-color: var(--oro-chiaro);
}

/* Salto al contenuto: primo elemento raggiungibile con il tabulatore */
.sc-salta {
    position: absolute;
    left: -9999px;
    top: 0;
    z-index: 30;
    background: var(--oro-chiaro);
    color: var(--notte-fondo);
    padding: 12px 20px;
    font-size: var(--t-occhiello);
    letter-spacing: 0.16em;
    text-transform: uppercase;
    text-decoration: none;
}
.sc-salta:focus { left: 0; }

/* --------------------------------------------------- impianto di pagina */
/* :where() azzera la specificità: una pagina che ha bisogno di un'altra
   forma (la mappa a tutto schermo) riscrive main senza dover rincorrere
   selettori più forti. */
main { flex: 1 1 auto; width: 100%; min-height: 0; }
main:where(.pagina-stretta) {
    max-width: var(--misura-testo);
    margin: 0 auto;
    padding: var(--s-4) var(--bordo-pagina) var(--s-7);
}
main:where(.pagina-piena) { padding: 0; }

.sc-contenitore {
    width: 100%;
    max-width: var(--misura);
    margin: 0 auto;
    padding-left: var(--bordo-pagina);
    padding-right: var(--bordo-pagina);
}
/* Sulle pagine a colonna stretta si stringono anche testata e piede: se
   restassero larghe, lo stemma partirebbe da un margine e il testo da un
   altro, e la pagina sembrerebbe montata male. */
body.pagina-stretta .sc-contenitore { max-width: var(--misura-testo); }

/* Le fasce orizzontali con cui si compone una pagina */
.sc-scuro { background: var(--notte); color: var(--chiaro); }
.sc-avorio { background: var(--avorio); color: var(--inchiostro); }
.sc-carta { background: var(--carta); color: var(--inchiostro); }
.sc-sezione { padding-top: var(--s-7); padding-bottom: var(--s-7); }

/* ------------------------------------------------------------ tipografia */
.sc-occhiello {
    font-family: var(--testo);
    font-size: var(--t-occhiello);
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--inchiostro-2);
}
.sc-occhiello-oro { color: var(--oro-scuro); }
.sc-occhiello-luce { color: var(--oro-chiaro); }

/* Occhiello e filetto sulla stessa riga: apre ogni sezione */
.sc-testa-sezione { display: flex; align-items: center; gap: var(--s-3); }
.sc-testa-sezione .sc-filo { flex: 1; }
.sc-filo { height: 1px; background: var(--filo); border: 0; }

.sc-h1 {
    font-family: var(--titolo);
    font-weight: 300;
    font-size: var(--t-h1);
    line-height: 0.98;
    letter-spacing: -0.025em;
    color: var(--bosco);
    text-wrap: balance;
}
.sc-h2 {
    font-family: var(--titolo);
    font-weight: 300;
    font-size: var(--t-h2);
    line-height: 1.06;
    letter-spacing: -0.018em;
    color: var(--bosco);
    text-wrap: balance;
}
.sc-h3 {
    font-family: var(--titolo);
    font-weight: 400;
    font-size: var(--t-h3);
    line-height: 1.12;
    letter-spacing: -0.008em;
    color: var(--bosco);
}
/* Sui fondi scuri i titoli si schiariscono: il bosco lì non si leggerebbe */
.sc-scuro .sc-h1, .sc-scuro .sc-h2, .sc-scuro .sc-h3 { color: #fff; }
.sc-h1 em, .sc-h2 em, .sc-h3 em { font-style: italic; font-weight: 400; color: var(--oro-scuro); }
/* Il corsivo d'oro cambia gradino secondo il fondo: l'oro scuro sullo scuro
   non si leggerebbe. La regola vale per tutti e tre i corpi, o un <em> in un
   titolo minore uscirebbe con il gradino sbagliato. */
.sc-scuro :where(.sc-h1, .sc-h2, .sc-h3) em { color: var(--oro-chiaro); }

/* Un titolo senza classe (l'informativa, una pagina di servizio) prende
   comunque il carattere da titoli: non deve sembrare di un altro sito.
   :where() lo tiene a specificità zero, così qualunque classe lo scavalca. */
main :where(h1, h2, h3) { font-family: var(--titolo); color: var(--bosco); }
.sc-scuro :where(h1, h2, h3) { color: #fff; }

.sc-guida {
    font-size: var(--t-guida);
    line-height: 1.5;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}
.sc-scuro .sc-guida { color: var(--chiaro); }
.sc-nota {
    font-size: var(--t-etichetta);
    line-height: 1.62;
    color: var(--inchiostro-2);
    text-wrap: pretty;
}
/* Le cifre incolonnate: misure, conteggi, coordinate */
.sc-num { font-variant-numeric: tabular-nums lining-nums; }

/* L'asterisco dei valori stimati: segno piccolo, bersaglio da 48px */
.sc-ast {
    position: relative;
    color: var(--oro-scuro);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.62em;
    vertical-align: super;
    line-height: 0;
    padding-left: 0.12em;
}
.sc-ast::after {
    content: "";
    position: absolute;
    left: -12px; right: -12px; top: 50%;
    height: 48px;
    transform: translateY(-50%);
}

/* ------------------------------------------------------------ componenti */
/* Due pulsanti in tutto il portale, non uno di più: il pieno per l'azione
   principale sul chiaro, quello d'oro per la stessa azione sullo scuro */
.sc-bottone, .sc-bottone-oro {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    padding: 8px 20px;
    border-radius: var(--raggio);
    font-family: var(--testo);
    font-size: var(--t-occhiello);
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
}
.sc-bottone { background: var(--bosco); color: var(--chiaro); border: 1px solid var(--bosco); }
.sc-bottone:hover { background: var(--notte); border-color: var(--notte); }
/* Il solo pulsante d'oro, e solo sui fondi scuri */
.sc-bottone-oro { background: var(--oro-chiaro); color: var(--notte-fondo); border: 1px solid var(--oro-chiaro); }
.sc-bottone-oro:hover { background: #fff; border-color: #fff; }

/* Lo stato della pianta ha due sole forme in tutto il portale.
   (a) targhetta piena dove si dichiara lo stato di UNA pianta: il bianco sui
       quattro colori sta fra 5,0:1 e 6,5:1. */
.sc-targhetta {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 2px 10px;
    border-radius: var(--raggio);
    background: var(--c, var(--bosco));
    color: #fff;
    font-size: var(--t-occhiello);
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}
/* (b) pallino più parola in inchiostro dove gli stati si ELENCANO */
.sc-pallino {
    width: 9px; height: 9px;
    border-radius: 50%;
    background: var(--c, var(--bosco));
    flex: none;
}

/* Un collegamento dentro una frase, con il bersaglio portato a 44px */
.sc-collegamento {
    position: relative;
    color: var(--bosco);
    text-decoration: none;
    border-bottom: 1px solid var(--filo-2);
}
.sc-collegamento:hover { border-bottom-color: var(--bosco); }
.sc-collegamento::after {
    content: "";
    position: absolute;
    left: -6px; right: -6px; top: 50%;
    height: 44px;
    transform: translateY(-50%);
}

/* ---------------------------------------------------------------- testata
   Fondo scuro: la testata si cuce con la copertina della home, che comincia
   con lo stesso --notte. Poche voci che vanno a capo sul telefono: niente
   menu a scomparsa, niente pulsante da scoprire. */
header.testata {
    background: var(--notte);
    color: var(--chiaro);
}
header.testata .testata-dentro {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--s-1) var(--s-4);
    padding-top: var(--s-2);
    padding-bottom: var(--s-2);
}
header.testata .marchio {
    display: flex;
    align-items: center;
    gap: var(--s-2);
    text-decoration: none;
    color: inherit;
    min-height: 44px;
}
header.testata img.stemma { height: 44px; width: auto; display: block; }
header.testata .marchio-testo { display: flex; flex-direction: column; }
header.testata .marchio-nome {
    font-family: var(--titolo);
    font-size: 20px;
    font-weight: 400;
    line-height: 1.34;
    letter-spacing: -0.004em;
    color: #fff;
}
header.testata .marchio-ruolo {
    margin-top: 2px;
    font-size: var(--t-occhiello);
    line-height: 1.2;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--oro-chiaro);
}
header.testata nav.menu {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0 var(--s-4);
}
header.testata nav.menu a {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    font-size: var(--t-occhiello);
    line-height: 1.2;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    text-decoration: none;
    color: var(--chiaro);
    border-bottom: 1px solid transparent;
}
header.testata nav.menu a:hover { color: #fff; border-bottom-color: var(--oro-chiaro); }

/* ------------------------------------------------------------------ piede */
footer.chiusura {
    background: var(--notte-fondo);
    color: var(--chiaro);
    padding-top: var(--s-5);
    padding-bottom: var(--s-5);
    font-size: var(--t-etichetta);
    line-height: 1.62;
}
footer.chiusura .dentro {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3) var(--s-6);
    justify-content: space-between;
}
footer.chiusura .chiusura-ente { max-width: 46ch; }
footer.chiusura .chiusura-nome { display: block; color: #fff; }
footer.chiusura .chiusura-ruolo {
    display: block;
    margin-top: var(--s-1);
    font-size: var(--t-occhiello);
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--oro-chiaro);
}
footer.chiusura nav.chiusura-voci {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
footer.chiusura nav.chiusura-voci a {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    color: var(--oro-chiaro);
    text-decoration: none;
    border-bottom: 1px solid transparent;
    align-self: flex-start;
}
footer.chiusura nav.chiusura-voci a:hover { color: #fff; border-bottom-color: #fff; }

/* ====================== SCHEDA DELL'ELEMENTO: il contorno =================
   Il foglio della scheda porta il proprio stile dentro la vista che lo
   disegna (resources/views/portale/scheda.blade.php, nel suo blocco che si
   stampa una volta sola): quella
   vista viaggia anche da sola, senza <head>, quando il pannello della mappa
   se la carica, e un foglio scritto qui non la seguirebbe. Qui restano solo
   le due cose che la scheda non può portarsi dietro: la fotografia a schermo
   intero, che è un elemento della pagina, e il ritorno in cima.
   ======================================================================== */

/* Fotografia a schermo intero */
#lente-piena {
    position: fixed;
    inset: 0;
    z-index: 20;
    border: 0;
    padding: 0;
    background: rgba(0, 0, 0, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: zoom-out;
}
#lente-piena[hidden] { display: none; }
#lente-piena img { max-width: 96vw; max-height: 92vh; }

/* Il ritorno in cima a una pagina interna */
a.indietro {
    display: inline-flex;
    align-items: center;
    gap: var(--s-1);
    min-height: 44px;
    margin-bottom: var(--s-2);
    font-size: var(--t-occhiello);
    letter-spacing: 0.16em;
    text-transform: uppercase;
    text-decoration: none;
    color: var(--inchiostro-2);
}
a.indietro:hover { color: var(--bosco); }

/* --------------------------------------------------------------- stampa */
@media print {
    /* Quello che si stampa è il contenuto, non la navigazione. Le regole di
       stampa proprie della scheda stanno nel suo foglio, che viene dopo
       questo: scritte qui perderebbero il confronto con le sue. */
    header.testata, footer.chiusura, .sc-salta { display: none; }
    body { background: #fff; color: #000; }
}
</style>
@stack('stile')
</head>
<body class="@yield('classe-pagina', 'pagina-stretta')">
    <a class="sc-salta" href="#contenuto">Vai al contenuto</a>

    <header class="testata">
        <div class="sc-contenitore testata-dentro">
            <a class="marchio" href="{{ $portale->url('/') }}">
                @if ($portale->hasLogo())
                    <img class="stemma" src="{{ $portale->url('/stemma') }}" alt="Stemma di {{ $portale->name() }}">
                @endif
                <span class="marchio-testo">
                    <span class="marchio-nome">{{ $portale->name() }}</span>
                    <span class="marchio-ruolo">Censimento del verde</span>
                </span>
            </a>
            <nav class="menu" aria-label="Menu del portale">
                <a href="{{ $portale->url('/mappa') }}">Mappa</a>
                <a href="{{ $portale->url('/') }}#come-lavoriamo">Come lavoriamo</a>
                @if ($urlSegnala)
                    <a href="{{ $urlSegnala }}">Segnala un problema</a>
                @endif
            </nav>
        </div>
    </header>

    <main id="contenuto" class="@yield('classe-pagina', 'pagina-stretta')">
        @yield('contenuto')
    </main>

    {{-- Fotografia a schermo intero. La gestione è delegata al documento
         perché la scheda può anche essere caricata dentro la mappa --}}
    <button type="button" id="lente-piena" hidden aria-label="Chiudi la fotografia">
        <img alt="Fotografia dell'elemento">
    </button>
    <script>
        (function () {
            var lente = document.getElementById('lente-piena');
            var immagine = lente.querySelector('img');

            document.addEventListener('click', function (evento) {
                var apri = evento.target.closest('[data-ingrandisci]');
                if (apri) {
                    immagine.src = apri.dataset.ingrandisci;
                    lente.hidden = false;
                    return;
                }
                if (evento.target.closest('#lente-piena')) {
                    lente.hidden = true;
                    immagine.removeAttribute('src');
                }
            });

            document.addEventListener('keydown', function (evento) {
                if (evento.key === 'Escape' && ! lente.hidden) {
                    lente.hidden = true;
                    immagine.removeAttribute('src');
                }
            });
        })();
    </script>

    <footer class="chiusura">
        <div class="sc-contenitore dentro">
            <div class="chiusura-ente">
                <span class="chiusura-nome">{{ $portale->footerText() ?: $portale->name() }}</span>
                <span class="chiusura-ruolo">Censimento del verde pubblico</span>
            </div>
            <nav class="chiusura-voci" aria-label="Collegamenti di servizio">
                <a href="{{ $portale->url('/mappa') }}">Mappa del verde</a>
                @if ($posta)
                    <a href="mailto:{{ $posta }}">{{ $posta }}</a>
                @endif
                <a href="{{ $portale->url('/privacy') }}">Privacy e note legali</a>
            </nav>
        </div>
    </footer>
</body>
</html>
