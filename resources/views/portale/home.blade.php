@extends('portale.layout')

@section('titolo', 'Censimento del verde')

@push('stile')
@if ($estensione && count($sfondi))
    @vite(['resources/js/portale-mappa.js'])
@endif
<style>
    /* La home esce dalla colonna di testo: l'anteprima della mappa vuole
       tutta la larghezza, altrimenti la pagina resta una striscia nel vuoto */
    main { max-width: none; margin: 0; padding: 0; }
    .dentro { max-width: 1180px; margin: 0 auto; padding: 0 20px; }

    .apertura { padding-top: 28px; padding-bottom: 24px; }
    h1 { font-size: 26px; margin: 0 0 6px; letter-spacing: -0.01em; }
    p.benvenuto { max-width: 64ch; color: var(--tenue); margin: 0; white-space: pre-line; }

    /* Ricerca del cartellino: è l'azione per cui il cittadino arriva qui,
       e nella barra in alto era un campo piccolo fra gli altri */
    form.cartellino { margin: 20px 0 0; display: flex; gap: 8px; flex-wrap: wrap; max-width: 560px; }
    form.cartellino input {
        flex: 1 1 220px; min-width: 0;
        border: 1px solid var(--bordo); border-radius: 10px;
        padding: 12px 14px; font: inherit; font-size: 16px; background: var(--carta);
    }
    form.cartellino input:focus { outline: 2px solid var(--tinta); outline-offset: 1px; }
    form.cartellino button {
        border: 0; border-radius: 10px; background: var(--tinta); color: #fff;
        padding: 12px 22px; font: inherit; font-size: 16px; font-weight: 600; cursor: pointer;
    }
    p.spiega-ricerca { color: var(--tenue); font-size: 13px; margin: 8px 0 0; }

    .avviso {
        background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12;
        border-radius: 10px; padding: 12px 16px; margin: 16px 0 0; font-size: 14px; max-width: 560px;
    }

    /* Numeri: solo quelli che dicono qualcosa. Una fila di zeri su un
       censimento appena cominciato fa sembrare vuoto un lavoro che c'è */
    .numeri { display: flex; flex-wrap: wrap; gap: 10px; margin: 22px 0 0; }
    .numero {
        background: var(--carta); border: 1px solid var(--bordo); border-radius: 10px;
        padding: 12px 18px; min-width: 132px;
    }
    .numero .valore { font-size: 24px; font-weight: 600; line-height: 1.15; }
    .numero .voce { font-size: 11px; color: var(--tenue); text-transform: uppercase; letter-spacing: 0.07em; margin-top: 2px; }
    .nota { color: var(--tenue); font-size: 13px; margin: 10px 0 0; max-width: 64ch; }

    /* Anteprima della mappa: si guarda, non si manovra. Un clic porta a
       quella vera, a schermo intero */
    .anteprima {
        position: relative; display: block; margin: 0;
        height: clamp(320px, 46vh, 520px);
        border-top: 1px solid var(--bordo); border-bottom: 1px solid var(--bordo);
        background: var(--carta); text-decoration: none; color: inherit;
    }
    #anteprima-mappa { position: absolute; inset: 0; }
    .anteprima .invito {
        position: absolute; z-index: 2; left: 50%; bottom: 34px; transform: translateX(-50%);
        background: var(--tinta); color: #fff; border-radius: 10px;
        padding: 11px 22px; font-size: 15px; font-weight: 600; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,0,0,0.28);
    }
    .anteprima:hover .invito { filter: brightness(1.12); }
    .anteprima .velo { position: absolute; inset: 0; z-index: 1; }

    /* Legenda dei quattro stati, sotto la mappa */
    .legenda { display: flex; flex-wrap: wrap; gap: 16px; padding: 14px 0 0; font-size: 13px; color: var(--tenue); }
    .legenda span { display: inline-flex; align-items: center; gap: 7px; }
    .legenda i { width: 11px; height: 11px; border-radius: 50%; display: inline-block; border: 1px solid rgba(0,0,0,0.15); }

    .chiusura-testo { padding-top: 4px; padding-bottom: 32px; }
    .dentro .nota:last-child { padding-bottom: 24px; }

    @media (max-width: 640px) {
        .apertura { padding-top: 20px; padding-bottom: 16px; }
        h1 { font-size: 21px; }
        .numero { flex: 1 1 132px; padding: 10px 14px; }
        .numero .valore { font-size: 20px; }
    }
</style>
@endpush

@section('contenuto')
    @php
        // Un numero a zero, in pubblico, non informa: sembra una mancanza.
        // Elementi e alberi si mostrano sempre, il resto solo se c'è
        // Le etichette vanno al singolare quando il numero e' uno: "1 alberi
        // curati" e' il genere di sciatteria che si nota subito
        $riquadri = [
            ['valore' => $statistiche['elementi'], 'uno' => 'Elemento censito', 'molti' => 'Elementi censiti', 'sempre' => true],
            ['valore' => $statistiche['alberi'], 'uno' => 'Albero', 'molti' => 'Alberi', 'sempre' => true],
            ['valore' => $statistiche['varieta'], 'uno' => 'Varietà botanica', 'molti' => 'Varietà botaniche', 'sempre' => false],
            ['valore' => $statistiche['curati'], 'uno' => 'Albero curato', 'molti' => 'Alberi curati', 'sempre' => false],
            ['valore' => $statistiche['potati'], 'uno' => 'Albero potato', 'molti' => 'Alberi potati', 'sempre' => false],
        ];
        $riquadri = array_values(array_filter($riquadri, fn ($r) => $r['sempre'] || $r['valore'] > 0));
        $conCo2 = $portale->mostraCo2() && ($statistiche['co2']['alberi'] ?? 0) > 0;
    @endphp

    <div class="dentro apertura">
        <h1>Il censimento del patrimonio arboreo</h1>

        <p class="benvenuto">{{ $portale->welcomeText()
            ?: 'Ogni albero del territorio ha una scheda con la specie, le misure e gli interventi eseguiti. Si consulta dalla mappa oppure cercando il numero riportato sul cartellino.' }}</p>

        <form class="cartellino" method="get" action="{{ $portale->url('/cerca') }}" role="search">
            <input
                type="search"
                name="etichetta"
                value="{{ $cercato ?? '' }}"
                maxlength="80"
                placeholder="Numero del cartellino"
                aria-label="Numero del cartellino"
            >
            <button type="submit">Cerca l'albero</button>
        </form>

        @if ($nonTrovato)
            <p class="avviso">
                Nessun elemento trovato con l'etichetta &laquo;{{ $cercato }}&raquo;.
                Controlla il numero riportato sul cartellino: si scrive anche solo con le cifre.
            </p>
        @else
            <p class="spiega-ricerca">Il numero è stampato sul cartellino applicato all'albero: bastano le cifre.</p>
        @endif

        <div class="numeri">
            @foreach ($riquadri as $riquadro)
                <div class="numero">
                    <div class="valore">{{ number_format($riquadro['valore'], 0, ',', '.') }}</div>
                    <div class="voce">{{ $riquadro['valore'] === 1 ? $riquadro['uno'] : $riquadro['molti'] }}</div>
                </div>
            @endforeach

            @if ($conCo2)
                <div class="numero">
                    <div class="valore">{{ number_format($statistiche['co2']['kg'] / 1000, 1, ',', '.') }} t<sup>*</sup></div>
                    <div class="voce">Anidride carbonica immagazzinata</div>
                </div>
                {{-- Statistiche in cache calcolate prima dell'aggiornamento
                     non hanno ancora la chiave: il riquadro aspetta il ricalcolo --}}
                @if (($statistiche['co2']['euro'] ?? null) !== null)
                    <div class="numero">
                        <div class="valore">{{ number_format($statistiche['co2']['euro'], 0, ',', '.') }} &euro;<sup>*</sup></div>
                        <div class="voce">Controvalore economico stimato</div>
                    </div>
                @endif
            @endif
        </div>

    </div>

    @if ($estensione && count($sfondi))
        <a class="anteprima" href="{{ $portale->url('/mappa') }}" aria-label="Apri la mappa del verde">
            <div id="anteprima-mappa"></div>
            <span class="velo"></span>
            <span class="invito">Apri la mappa &rarr;</span>
        </a>

        <div class="dentro">
            <div class="legenda">
                @foreach (\App\Services\Portale\PortalState::ETICHETTE as $codice => $etichetta)
                    <span><i style="background: {{ \App\Services\Portale\PortalState::COLORI[$codice] }}"></i>{{ $etichetta }}</span>
                @endforeach
            </div>

            @if ($conCo2)
                <p class="nota">
                    <sup>*</sup> Valore stimato, non misurato, calcolato
                    @if ($statistiche['co2']['alberi'] === 1)
                        su un albero di cui è noto
                    @else
                        su {{ number_format($statistiche['co2']['alberi'], 0, ',', '.') }} alberi di cui è noto
                    @endif
                    il diametro del tronco: {{ config('co2.modello') }}.
                    @if (($statistiche['co2']['euro'] ?? null) !== null)
                        Il controvalore economico applica un prezzo di
                        {{ number_format((float) config('co2.euro_per_tonnellata'), 0, ',', '.') }} euro
                        per tonnellata di anidride carbonica ({{ config('co2.prezzo_fonte') }}).
                    @endif
                </p>
            @endif
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
        <div class="dentro chiusura-testo">
            <p class="nota">La mappa sarà disponibile appena il censimento avrà i primi elementi rilevati sul territorio.</p>
        </div>
    @endif
@endsection
