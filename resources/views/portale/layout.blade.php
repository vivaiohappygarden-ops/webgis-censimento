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
    .scheda .tutele { padding: 0 16px 16px; }
    .scheda .tutela { display: inline-block; border: 1px solid var(--bordo); border-radius: 999px; padding: 3px 10px; font-size: 12px; color: var(--tenue); margin: 0 6px 6px 0; }
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

    <footer class="chiusura">
        <div class="dentro">
            <div>{{ $portale->footerText() ?: $portale->name() }}</div>
            @if ($portale->contactEmail())
                <div>Contatti: <a href="mailto:{{ $portale->contactEmail() }}">{{ $portale->contactEmail() }}</a></div>
            @endif
        </div>
    </footer>
</body>
</html>
