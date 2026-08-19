<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="{{ $indicizzabile ?? false ? 'index,follow' : 'noindex' }}">
<title>@yield('titolo', 'Censimento del verde') - {{ $portale->name() }}</title>
<style>
    :root {
        color-scheme: light;
        --tinta: {{ $portale->color() }};
        --fondo: #f5f6f5;
        --carta: #ffffff;
        --bordo: #d8ded8;
        --testo: #16211a;
        --tenue: #5b6a5f;
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
        margin: 0;
        background: var(--fondo);
        color: var(--testo);
        font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        font-variant-numeric: tabular-nums;
        line-height: 1.5;
        /* Il piè di pagina resta in fondo anche quando il contenuto è poco */
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    main { flex: 1; }
    a { color: var(--tinta); }
    header.testata {
        background: var(--tinta);
        color: #fff;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }
    header.testata img.stemma { height: 40px; width: auto; display: block; }
    header.testata .titoli { line-height: 1.2; }
    header.testata .servizio { font-size: 17px; font-weight: 600; }
    header.testata .ente { font-size: 12px; letter-spacing: 0.09em; text-transform: uppercase; opacity: 0.85; }
    header.testata nav { display: flex; gap: 16px; font-size: 14px; }
    header.testata nav a { color: #fff; text-decoration: none; border-bottom: 1px solid transparent; }
    header.testata nav a:hover { border-bottom-color: rgba(255,255,255,0.7); }
    header.testata form.ricerca { margin-left: auto; display: flex; gap: 8px; }
    header.testata form.ricerca input {
        border: 1px solid rgba(255,255,255,0.5);
        background: rgba(255,255,255,0.12);
        color: #fff;
        border-radius: 8px;
        padding: 6px 10px;
        font: inherit;
        font-size: 14px;
        width: 190px;
    }
    header.testata form.ricerca input::placeholder { color: rgba(255,255,255,0.75); }
    header.testata form.ricerca button {
        border: 1px solid rgba(255,255,255,0.5);
        background: rgba(255,255,255,0.12);
        color: #fff;
        border-radius: 8px;
        padding: 6px 12px;
        font: inherit;
        font-size: 14px;
        cursor: pointer;
    }
    header.testata form.ricerca button:hover { background: rgba(255,255,255,0.22); }
    main { max-width: 980px; margin: 0 auto; padding: 24px 20px 56px; }
    footer.chiusura {
        border-top: 1px solid var(--bordo);
        background: var(--carta);
        padding: 20px;
        font-size: 13px;
        color: var(--tenue);
    }
    footer.chiusura .dentro { max-width: 980px; margin: 0 auto; display: flex; gap: 20px; flex-wrap: wrap; justify-content: space-between; }
    /* Scheda dell'elemento: usata sia come pagina sia dentro il pannello
       laterale della mappa, quindi lo stile sta qui e non nella pagina */
    .scheda { background: var(--carta); border: 1px solid var(--bordo); border-radius: 12px; overflow: hidden; max-width: 620px; }
    .scheda .intestazione { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--bordo); flex-wrap: wrap; }
    .scheda .badge-etichetta { background: var(--tinta); color: #fff; border-radius: 6px; padding: 2px 8px; font-weight: 600; font-size: 14px; }
    .scheda .tipo { font-size: 13px; color: var(--tenue); letter-spacing: 0.04em; }
    .scheda .stato { margin-left: auto; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
    .scheda .foto { position: relative; }
    .scheda .foto img { display: block; width: 100%; height: auto; }
    .scheda .foto .specie {
        position: absolute; left: 12px; bottom: 12px;
        background: rgba(255,255,255,0.88); border-radius: 8px; padding: 6px 10px; font-size: 14px;
    }
    .scheda .specie em { display: block; font-style: italic; }
    .scheda .specie span { color: var(--tenue); font-size: 13px; }
    .scheda .specie-senza-foto { padding: 12px 16px 0; font-size: 14px; }
    .scheda dl { margin: 0; padding: 4px 16px 16px; }
    .scheda .riga { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid #eef1ee; font-size: 14px; }
    .scheda .riga:last-child { border-bottom: 0; }
    .scheda dt { color: var(--tenue); text-transform: uppercase; font-size: 12px; letter-spacing: 0.05em; }
    .scheda dd { margin: 0; text-align: right; }
    .scheda .stima { margin: -8px 16px 12px; font-size: 12px; color: var(--tenue); }
    /* Vincoli che gravano sull'elemento */
    .scheda .vincoli { padding: 4px 16px 12px; border-top: 1px solid var(--bordo); }
    .scheda .vincoli h2 { font-size: 16px; margin: 12px 0 6px; }
    .scheda .vincoli-titolo { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--tenue); margin-bottom: 6px; }
    .scheda .vincolo { font-size: 14px; padding: 5px 0; border-bottom: 1px solid #eef1ee; }
    .scheda .vincolo:last-child { border-bottom: 0; }
    .scheda .vincolo-codice { font-weight: 600; }
    .scheda .vincolo-nome { color: var(--tenue); margin-left: 8px; }
    .scheda .vincolo-ente { display: block; color: var(--tenue); font-size: 12px; }
    .scheda .tutele { padding: 0 16px 16px; }
    .scheda .tutela { display: inline-block; border: 1px solid var(--bordo); border-radius: 999px; padding: 3px 10px; font-size: 12px; color: var(--tenue); margin: 0 6px 6px 0; }
    .scheda .lente {
        position: absolute; inset: 0; margin: auto; width: 44px; height: 44px;
        border-radius: 50%; border: 2px solid rgba(255,255,255,0.9);
        background: rgba(0,0,0,0.35); color: #fff; font-size: 22px; line-height: 1;
        cursor: pointer;
    }
    .scheda .lente:hover { background: rgba(0,0,0,0.5); }
    /* Cronologia degli eventi con gli atti collegati */
    .scheda .cronologia { padding: 4px 16px 16px; border-top: 1px solid var(--bordo); }
    .scheda .cronologia h2 { font-size: 16px; margin: 12px 0 10px; }
    .scheda .evento { padding-left: 14px; border-left: 2px solid var(--bordo); margin-bottom: 16px; position: relative; }
    .scheda .evento::before {
        content: ''; position: absolute; left: -5px; top: 6px;
        width: 8px; height: 8px; border-radius: 50%; background: var(--tinta);
    }
    .scheda .quando { font-size: 13px; color: var(--tenue); }
    .scheda .quando strong { color: var(--testo); }
    .scheda .nota-evento { margin: 4px 0 8px; font-size: 14px; white-space: pre-line; }
    .scheda .foto-evento { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .scheda .foto-evento img {
        width: 84px; height: 84px; object-fit: cover; border-radius: 8px;
        border: 1px solid var(--bordo); cursor: pointer;
    }
    .scheda .atti { background: #f0f3f0; border-radius: 8px; padding: 10px 12px; }
    .scheda .atti-titolo { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--tenue); margin-bottom: 6px; }
    .scheda .atto { font-size: 13px; padding: 5px 0; border-bottom: 1px solid #e3e8e3; }
    .scheda .atto:last-child { border-bottom: 0; }
    .scheda .atto-riga { display: flex; justify-content: space-between; gap: 12px; }
    .scheda .atto-nome { font-weight: 600; }
    .scheda .atto-data { color: var(--tenue); white-space: nowrap; }
    .scheda .atto-ente, .scheda .atto-descrizione { color: var(--tenue); }
    .scheda .azioni { display: flex; flex-direction: column; gap: 8px; padding: 0 16px 16px; }
    .scheda .azione {
        display: block; text-align: center; text-decoration: none;
        background: var(--tinta); color: #fff; border-radius: 8px; padding: 10px 12px;
        font-size: 14px; font-weight: 600;
    }
    .scheda .azione.secondaria { background: transparent; color: var(--tinta); border: 1px solid var(--tinta); font-weight: 500; }

    /* Fotografia a schermo intero */
    #lente-piena {
        position: fixed; inset: 0; z-index: 20; border: 0; padding: 0;
        background: rgba(0,0,0,0.88);
        display: flex; align-items: center; justify-content: center;
    }
    #lente-piena[hidden] { display: none; }
    #lente-piena img { max-width: 96vw; max-height: 92vh; }
    a.indietro { display: inline-block; margin-bottom: 14px; font-size: 14px; text-decoration: none; }
    p.prossimo { color: var(--tenue); font-size: 13px; margin-top: 16px; max-width: 620px; }

    @media (max-width: 640px) {
        header.testata form.ricerca { margin-left: 0; width: 100%; }
        header.testata form.ricerca input { flex: 1; width: auto; min-width: 0; }
    }
</style>
@stack('stile')
</head>
<body>
    <header class="testata">
        @if ($portale->hasLogo())
            <img class="stemma" src="{{ $portale->url('/stemma') }}" alt="Stemma di {{ $portale->name() }}">
        @endif
        <div class="titoli">
            <div class="servizio">Censimento del verde</div>
            <div class="ente">{{ $portale->name() }}</div>
        </div>
        <nav>
            <a href="{{ $portale->url('/') }}">Home</a>
            <a href="{{ $portale->url('/mappa') }}">Mappa</a>
        </nav>
        <form class="ricerca" method="get" action="{{ $portale->url('/cerca') }}" role="search">
            <input
                type="search"
                name="etichetta"
                value="{{ $cercato ?? '' }}"
                maxlength="80"
                placeholder="Cerca per etichetta"
                aria-label="Cerca per numero di etichetta"
            >
            <button type="submit">Cerca</button>
        </form>
    </header>

    <main>
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
        <div class="dentro">
            <div>{{ $portale->footerText() ?: $portale->name() }}</div>
            @if ($portale->contactEmail())
                <div>Contatti: <a href="mailto:{{ $portale->contactEmail() }}">{{ $portale->contactEmail() }}</a></div>
            @endif
            <div><a href="{{ $portale->url('/privacy') }}">Privacy e note legali</a></div>
        </div>
    </footer>
</body>
</html>
