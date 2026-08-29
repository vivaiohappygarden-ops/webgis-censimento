@extends('portale.layout')

@section('titolo', 'Mappa del verde')
@section('classe-pagina', 'pagina-piena')

@php
    // La tavolozza serve anche qui: la carta disegnata dai riquadri (aree,
    // contorni, anello dell'elemento aperto) prende i colori del Comune, e
    // MapLibre vuole colori veri, non variabili CSS.
    $tav = \App\Support\PortalPalette::da($portale->color());

    $posta = $portale->contactEmail();

    // Il segnaposto mostra la forma del numero che si scrive qui. Dove il
    // committente ha un prefisso di etichetta bastano le cifre (PortalSearch
    // lo antepone da sé); dove non ce l'ha il codice va scritto per intero, e
    // un esempio di sole cifre manderebbe fuori strada.
    $prefisso = trim((string) ($portale->client->label_prefix ?? ''));
    $esempioCartellino = $prefisso !== '' ? '0427' : 'codice';

    // Come nel resto del portale la segnalazione è una mail all'ente: qui non
    // c'è sessione, quindi nessun modulo da inviare.
    $urlSegnala = $posta === null ? null : 'mailto:'.$posta.'?'.http_build_query(
        ['subject' => 'Segnalazione dal portale del verde di '.$portale->name()],
        '', '&', PHP_QUERY_RFC3986
    );
@endphp

@push('stile')
@vite(['resources/js/portale-mappa.js'])
<style>
    /* ======================================================================
       MAPPA PUBBLICA — la carta si prende lo schermo e i riquadri galleggiano
       sopra, come nella bozza. Qui non si ridichiara nessun colore e nessun
       corpo: tutto viene dai token del layout.
       ====================================================================== */

    /* Questa non è una pagina che scorre: è una veduta. L'altezza è quella
       vera dello schermo (dvh), perché su Android la barra del browser si
       ritira e con 100vh il piede della carta finirebbe sotto il bordo. */
    body {
        height: 100vh;
        height: 100dvh;
        overflow: hidden;
    }

    /* L'identità del Comune, qui, sta nella barra della mappa. La testata
       comune raddoppierebbe lo stemma e, sul telefono, ruberebbe alla carta
       due righe intere: ogni riga in meno è carta in più. */
    header.testata,
    footer.chiusura { display: none; }

    main {
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    /* La carta e tutto ciò che ci galleggia sopra */
    .m-veduta {
        position: relative;
        flex: 1 1 auto;
        min-height: 0;
    }
    #mappa { position: absolute; inset: 0; }

    /* ------------------------------------------------------------ barra alta
       Sul telefono sta nel flusso, sopra la carta: una barra che galleggia si
       mangerebbe la parte di schermo dove si guarda. Sullo schermo largo
       diventa il riquadro sospeso della bozza, tenuto a sinistra perché in
       alto a destra ci stanno i comandi di ingrandimento. */
    .m-alto {
        z-index: 5;
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: var(--s-1) var(--s-3);
        padding: 10px var(--bordo-pagina) 12px;
    }
    .m-alto :focus-visible { outline-color: var(--oro-chiaro); }

    .m-marchio {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 44px;
        min-width: 0;
        flex: 0 1 auto;
        text-decoration: none;
        color: inherit;
    }
    .m-marchio img {
        height: 38px;
        width: auto;
        flex: none;
        display: block;
    }
    .m-marchio-testo { min-width: 0; }
    .m-marchio-nome {
        display: block;
        font-family: var(--titolo);
        font-size: 20px;
        line-height: 1.34;
        letter-spacing: -0.004em;
        color: #fff;
    }
    .m-marchio-torna {
        display: block;
        margin-top: 2px;
        font-size: var(--t-occhiello);
        line-height: 1.2;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--oro-chiaro);
    }
    .m-marchio:hover .m-marchio-torna { color: #fff; }

    /* La ricerca del cartellino: la stessa della home, in piccolo */
    .m-cerca {
        display: flex;
        align-items: flex-end;
        gap: var(--s-1);
        flex: 1 1 240px;
        min-width: 0;
    }
    .m-cerca-corpo { flex: 1 1 auto; min-width: 0; }
    .m-cerca-etichetta {
        display: block;
        margin-bottom: 5px;
        font-size: var(--t-occhiello);
        line-height: 1.2;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--oro-chiaro);
    }
    .m-cerca-campo {
        width: 100%;
        min-height: 48px;
        padding: 8px 12px;
        border: 1px solid var(--oro-chiaro);
        border-radius: var(--raggio);
        /* Fondo pieno, non velato: il --chiaro sopra il --notte-fondo è
           garantito 4,5:1 dalla tavolozza, un velo lo renderebbe incerto */
        background: var(--notte-fondo);
        color: #fff;
        font-family: var(--testo);
        font-size: var(--t-corpo);
        font-weight: 500;
        letter-spacing: 0.06em;
        font-variant-numeric: tabular-nums lining-nums;
    }
    .m-cerca-campo::placeholder {
        color: var(--chiaro);
        font-weight: 400;
        letter-spacing: 0.18em;
    }
    .m-cerca .sc-bottone-oro { flex: none; padding: 8px 16px; }

    /* --------------------------------------------------------- il cartiglio
       Legenda, simboli, sfondi e note in un solo oggetto, come il cartiglio
       di una carta stampata: così la carta resta guardabile per intero
       invece di essere spiata da uno spiraglio fra tre riquadri. */
    #comandi {
        position: absolute;
        z-index: 4;
        left: var(--s-2);
        /* Sopra la barra di scala della carta, che sta nell'angolo in basso
           a sinistra: legenda sopra, scala sotto, come su una carta stampata */
        bottom: 46px;
        width: min(370px, calc(100% - 2 * var(--s-2)));
        max-height: min(56vh, 560px);
        overflow-y: auto;
        /* Scorrendo la legenda non si deve trascinare anche la carta */
        overscroll-behavior: contain;
        padding: 14px 18px 16px;
        /* Fondo pieno, non velato. La tavolozza garantisce i contrasti sui
           colori pieni; un velo, per quanto fitto, lascia passare la carta e
           con una tinta chiarissima scelta dal Comune il testo scenderebbe
           sotto 4,5:1 proprio sopra un'ortofoto bianca. A staccare il
           riquadro dalla carta bastano il filetto e l'ombra. */
        background: var(--carta);
        border: 1px solid var(--filo);
        border-top: 2px solid var(--oro);
        border-radius: var(--raggio);
        box-shadow: var(--ombra);
        color: var(--inchiostro);
    }

    .m-gruppo { margin-top: var(--s-2); }
    .m-gruppo-nome {
        margin-bottom: 6px;
        font-size: var(--t-occhiello);
        line-height: 1.2;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--inchiostro-2);
    }

    #sfondi { display: flex; flex-wrap: wrap; gap: 6px; }
    #sfondi button {
        min-height: 44px;
        padding: 0 14px;
        border: 1px solid var(--filo-2);
        border-radius: var(--raggio);
        background: var(--carta);
        color: var(--bosco);
        font-family: var(--testo);
        font-size: var(--t-occhiello);
        font-weight: 500;
        line-height: 1.2;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        cursor: pointer;
    }
    #sfondi button:hover { border-color: var(--bosco); }
    #sfondi button.attivo {
        background: var(--bosco);
        border-color: var(--bosco);
        color: var(--chiaro);
    }

    /* Gli stati si ELENCANO: pallino più parola in inchiostro. La parola
       tinta del colore dello stato regge il contrasto solo sulla carta, e
       qui sarebbe quattro volte su quattro al limite: meglio il pallino. */
    .m-stati { margin: 0; padding: 0; list-style: none; }
    .m-stati li {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 30px;
        padding: 3px 0;
        border-bottom: 1px solid var(--filo);
        font-size: var(--t-etichetta);
        line-height: 1.35;
    }
    .m-stati li:last-child { border-bottom: 0; }

    /* Il simbolo della chioma: è la cosa che distingue questa carta dalle
       altre, e va spiegata dove si guarda */
    .m-simbolo {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-top: var(--s-2);
        padding-top: var(--s-2);
        border-top: 1px solid var(--filo);
    }
    .m-simbolo svg { flex: none; display: block; }
    .m-simbolo p { margin: 0; }
    .m-simbolo b { font-weight: 500; color: var(--bosco); }

    /* Il layout mette 16 px sotto ogni <p>: dentro il cartiglio gli spazi li
       decidono i gruppi, altrimenti si sommano e la legenda si allunga */
    #comandi > .sc-occhiello { margin: 0; }
    #comandi .sc-nota { margin: var(--s-2) 0 0; }

    .m-servizio {
        display: flex;
        flex-wrap: wrap;
        gap: 0 var(--s-3);
        margin-top: var(--s-1);
        padding-top: var(--s-1);
        border-top: 1px solid var(--filo);
    }
    .m-servizio a {
        display: inline-flex;
        align-items: center;
        min-height: 44px;
        font-size: var(--t-occhiello);
        letter-spacing: 0.14em;
        text-transform: uppercase;
        text-decoration: none;
        color: var(--inchiostro-2);
        border-bottom: 1px solid transparent;
    }
    .m-servizio a:hover { color: var(--bosco); border-bottom-color: var(--bosco); }

    /* ---------------------------------------------------- pannello dell'albero
       Dentro ci va la scheda condivisa, che è chiara: il pannello le fa da
       cornice chiara con la testata scura, non da scatola scura, altrimenti
       la scheda sembrerebbe incollata sopra un fondo di un altro sito. */
    #pannello {
        position: absolute;
        z-index: 6;
        top: var(--s-2);
        right: var(--s-2);
        bottom: var(--s-2);
        width: min(420px, 40vw);
        min-width: 320px;
        display: flex;
        flex-direction: column;
        background: var(--carta);
        border: 1px solid var(--filo);
        border-radius: var(--raggio);
        box-shadow: var(--ombra);
        overflow: hidden;
    }
    /* Il pannello si apre e si chiude con l'attributo hidden: senza questa
       riga il display:flex qui sopra lo terrebbe sempre aperto */
    #pannello[hidden] { display: none; }

    #pannello .barra {
        flex: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--s-2);
        padding: 6px 6px 6px 18px;
    }
    #pannello .barra .titolo {
        font-size: var(--t-occhiello);
        line-height: 1.2;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--oro-chiaro);
    }
    #pannello .barra :focus-visible { outline-color: var(--oro-chiaro); }
    #pannello-chiudi {
        flex: none;
        width: 48px;
        height: 48px;
        border: 0;
        border-radius: var(--raggio);
        background: transparent;
        color: var(--chiaro);
        font-family: var(--testo);
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
    }
    #pannello-chiudi:hover { background: var(--notte-fondo); color: #fff; }

    #pannello-contenuto {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    /* La scheda non si riscrive: le si toglie solo la cornice, perché la
       cornice adesso è il pannello */
    #pannello .scheda {
        max-width: none;
        border: 0;
        border-radius: 0;
        background: transparent;
    }
    #pannello .attesa {
        margin: 0;
        padding: var(--s-3);
        font-size: var(--t-etichetta);
        color: var(--inchiostro-2);
    }

    .vuoto {
        position: absolute;
        inset: 0;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: var(--s-5) var(--bordo-pagina);
        color: var(--inchiostro-2);
    }

    /* ------------------------------------------------ i comandi di MapLibre
       La carta resta quella che è: qui si rivestono soltanto i suoi comandi,
       perché non stonino con il resto. I selettori partono da .m-veduta per
       stare sopra il foglio di stile della libreria comunque sia caricato. */
    .m-veduta .maplibregl-map { font-family: var(--testo); }
    .m-veduta .maplibregl-ctrl-group {
        background: var(--carta);
        border: 1px solid var(--filo);
        border-radius: var(--raggio);
        box-shadow: var(--ombra);
    }
    /* I 29 px di serie non si toccano con il dito: qui i bersagli sono 44 */
    .m-veduta .maplibregl-ctrl-group button {
        width: 44px;
        height: 44px;
        border-radius: 0;
    }
    .m-veduta .maplibregl-ctrl-group button + button { border-top: 1px solid var(--filo); }
    .m-veduta .maplibregl-ctrl-group button:hover { background: var(--avorio); }
    .m-veduta .maplibregl-ctrl-scale {
        background: var(--carta);
        border: 1px solid var(--filo-2);
        border-top: 0;
        border-radius: 0 0 var(--raggio) var(--raggio);
        color: var(--inchiostro);
        font-size: var(--t-occhiello);
        font-variant-numeric: tabular-nums lining-nums;
        padding: 2px 6px;
    }
    .m-veduta .maplibregl-ctrl-attrib {
        background: var(--carta);
        /* Nessun testo del portale sotto i 13 px, nemmeno le attribuzioni:
           sono un obbligo verso chi fornisce la carta, non una postilla */
        font-size: var(--t-occhiello);
        line-height: 1.35;
    }
    .m-veduta .maplibregl-ctrl-attrib a { color: var(--bosco); }
    .m-veduta .maplibregl-popup-content {
        background: var(--notte);
        color: var(--chiaro);
        border-radius: var(--raggio);
        font-size: var(--t-etichetta);
        line-height: 1.35;
        padding: 8px 12px;
        box-shadow: var(--ombra);
    }
    .m-veduta .maplibregl-popup-anchor-top .maplibregl-popup-tip { border-bottom-color: var(--notte); }
    .m-veduta .maplibregl-popup-anchor-bottom .maplibregl-popup-tip { border-top-color: var(--notte); }
    .m-veduta .maplibregl-popup-anchor-left .maplibregl-popup-tip { border-right-color: var(--notte); }
    .m-veduta .maplibregl-popup-anchor-right .maplibregl-popup-tip { border-left-color: var(--notte); }

    /* ------------------------------------------------------- schermo stretto */
    @media (max-width: 899px) {
        /* Il cartiglio prende la larghezza e si tiene basso: si legge con il
           pollice e lascia libera la metà alta della carta */
        #comandi {
            left: 8px;
            right: 8px;
            bottom: 40px;
            width: auto;
            max-height: 38vh;
            padding: 12px 14px 14px;
        }
        /* Il pannello sale dal basso e si ferma a poco più di metà schermo:
           la pianta scelta si legge senza perdere di vista dov'è */
        #pannello {
            top: auto;
            left: 8px;
            right: 8px;
            bottom: 8px;
            width: auto;
            min-width: 0;
            max-height: 62%;
        }
        /* Sul telefono la scheda si chiude con il pollice, spesso mentre si
           tiene il telefono con la stessa mano: il bersaglio si allarga */
        #pannello-chiudi { width: 72px; }
    }

    /* -------------------------------------------------------- schermo largo
       La barra torna a galleggiare sulla carta, come nella bozza, solo quando
       c'è spazio davvero: sotto i 1200 px il pannello della pianta occupa la
       colonna di destra e una barra sospesa gli finirebbe sotto. Fra 900 e
       1200 px (tablet in orizzontale) resta quindi appoggiata sopra la carta. */
    @media (min-width: 1200px) {
        .m-alto {
            position: absolute;
            top: var(--s-2);
            left: var(--s-2);
            /* Si ferma prima del pannello (420 px + i suoi margini) e lascia
               libero l'angolo in alto a destra, dove MapLibre mette
               ingrandisci, riduci e "dove mi trovo" */
            max-width: min(940px, calc(100% - 468px));
            flex-wrap: nowrap;
            padding: 12px 20px 14px;
            border-radius: var(--raggio);
            /* Il fondo pieno arriva da .sc-scuro: vedi la nota sul cartiglio */
            box-shadow: var(--ombra);
        }
        .m-cerca { flex: 0 1 360px; }
    }
</style>
@endpush

@section('contenuto')
    <div class="m-alto sc-scuro">
        <a class="m-marchio" href="{{ $portale->url('/') }}">
            @if ($portale->hasLogo())
                <img src="{{ $portale->url('/stemma') }}" alt="Stemma di {{ $portale->name() }}">
            @endif
            <span class="m-marchio-testo">
                <span class="m-marchio-nome">{{ $portale->name() }}</span>
                <span class="m-marchio-torna">&#8592; Torna al portale</span>
            </span>
        </a>

        <form class="m-cerca" action="{{ $portale->url('/cerca') }}" method="get">
            <span class="m-cerca-corpo">
                <label class="m-cerca-etichetta" for="m-cartellino">Numero del cartellino</label>
                <input class="m-cerca-campo" id="m-cartellino" name="etichetta" type="text"
                       inputmode="{{ $prefisso !== '' ? 'numeric' : 'text' }}" autocomplete="off"
                       placeholder="{{ $esempioCartellino }}">
            </span>
            <button class="sc-bottone-oro" type="submit">Cerca</button>
        </form>
    </div>

    <div class="m-veduta">
        @if ($estensione === null)
            <p class="vuoto">Non ci sono ancora elementi pubblicati sulla mappa di questo territorio.</p>
        @else
            <div id="mappa"></div>

            <aside id="comandi" aria-label="Come si legge la carta">
                <p class="sc-occhiello sc-occhiello-oro">Come si legge la carta</p>

                <div class="m-gruppo">
                    <p class="m-gruppo-nome" id="sfondi-nome">Sfondo della carta</p>
                    <div id="sfondi" role="group" aria-labelledby="sfondi-nome">
                        @foreach ($sfondi as $i => $sfondo)
                            <button type="button" data-sfondo="{{ $sfondo['id'] }}" class="{{ $i === 0 ? 'attivo' : '' }}">
                                {{ $sfondo['nome'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="m-gruppo">
                    <p class="m-gruppo-nome">Stato della pianta</p>
                    <ul class="m-stati">
                        @foreach ($stati as $stato)
                            <li style="--c: {{ $stato['colore'] }}">
                                <span class="sc-pallino"></span>{{ $stato['etichetta'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="m-simbolo">
                    {{-- Il cerchio della chioma, il punto e la barra di misura:
                         lo stesso segno che si vede sulla carta --}}
                    <svg width="44" height="44" viewBox="0 0 44 44" aria-hidden="true" focusable="false">
                        <circle cx="22" cy="18" r="14" fill="var(--m-parco)" fill-opacity="0.55"
                                stroke="var(--stato-sano)" stroke-width="1.2" stroke-opacity="0.8"/>
                        <circle cx="22" cy="18" r="3.5" fill="var(--stato-sano)" stroke="#ffffff" stroke-width="1.5"/>
                        <g stroke="var(--oro-scuro)" stroke-width="1" fill="none">
                            <path d="M8 39 H36"/>
                            <path d="M8 35.5 V42.5 M36 35.5 V42.5"/>
                        </g>
                    </svg>
                    <p class="sc-nota">Il cerchio attorno al punto è la <b>chioma alla misura vera</b>:
                        occupa sulla carta i metri che l'albero occupa in strada. Si vede sugli alberi
                        di cui è stato rilevato il diametro della chioma, ingrandendo la carta.</p>
                </div>

                <p class="sc-nota">Sulla carta ci sono soltanto gli alberi e le aree del Comune già
                    censiti: i giardini privati e le zone non ancora rilevate non compaiono.</p>

                <nav class="m-servizio" aria-label="Collegamenti di servizio">
                    <a href="{{ $portale->url('/') }}#come-lavoriamo">Come lavoriamo</a>
                    @if ($urlSegnala)
                        <a href="{{ $urlSegnala }}">Segnala un problema</a>
                    @endif
                    <a href="{{ $portale->url('/privacy') }}">Privacy e note legali</a>
                </nav>
            </aside>

            <aside id="pannello" hidden aria-label="Scheda dell'elemento scelto">
                <div class="barra sc-scuro">
                    <span class="titolo">Elemento scelto</span>
                    <button type="button" id="pannello-chiudi" aria-label="Chiudi la scheda">&#10005;</button>
                </div>
                <div id="pannello-contenuto"></div>
            </aside>

            @php
                $datiMappa = [
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
                    // I colori della carta escono dalla tavolozza del Comune:
                    // l'anello dell'elemento aperto è oro (l'accento del
                    // portale) e non più rosso, che è il colore di uno stato
                    'accento' => $tav->colore('oro'),
                    'verde' => $tav->colore('m-parco'),
                    'bosco' => $tav->colore('bosco'),
                    'apri' => request()->query('elemento'),
                ];
            @endphp

            <script type="application/json" id="dati-portale">{!! json_encode($datiMappa, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
            <script>
                window.__portale = JSON.parse(document.getElementById('dati-portale').textContent);
            </script>
        @endif
    </div>
@endsection
