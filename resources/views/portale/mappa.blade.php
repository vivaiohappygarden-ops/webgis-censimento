@extends('portale.layout')

@section('titolo', 'Mappa del verde')

@push('stile')
@vite(['resources/js/portale-mappa.js'])
<style>
    /* La mappa occupa tutto lo spazio sotto l'intestazione */
    body { overflow: hidden; }
    /* La mappa esce dalla colonna di testo: occupa tutta la larghezza */
    main { padding: 0; position: relative; max-width: none; margin: 0; }
    footer.chiusura { display: none; }
    #mappa { position: absolute; inset: 0; }
    #pannello {
        position: absolute;
        top: 12px; left: 12px;
        width: 340px;
        max-width: calc(100% - 24px);
        max-height: calc(100% - 24px);
        overflow-y: auto;
        background: var(--fondo);
        border-radius: 12px;
        box-shadow: 0 6px 24px rgba(0,0,0,0.25);
        z-index: 2;
    }
    #pannello .barra { display: flex; justify-content: flex-end; padding: 6px 8px 0; }
    #pannello .barra button {
        border: 0; background: transparent; font: inherit; font-size: 18px;
        line-height: 1; color: var(--tenue); cursor: pointer; padding: 4px 8px;
    }
    #pannello .scheda { max-width: none; margin: 0 8px 8px; }
    #pannello .attesa { padding: 16px; font-size: 14px; color: var(--tenue); }
    /* Comandi a destra: a sinistra si apre la scheda dell'elemento e li
       coprirebbe */
    #comandi {
        /* 32px dal fondo: sotto ci passa la riga delle attribuzioni */
        position: absolute; right: 12px; bottom: 32px; z-index: 1;
        display: flex; flex-direction: column; gap: 8px; align-items: flex-end;
    }
    .riquadro-mappa {
        background: rgba(255,255,255,0.94);
        border: 1px solid var(--bordo);
        border-radius: 10px;
        padding: 8px 10px;
        font-size: 13px;
    }
    #sfondi { display: flex; gap: 6px; }
    #sfondi button {
        border: 1px solid var(--bordo); background: #fff; border-radius: 8px;
        padding: 5px 10px; font: inherit; font-size: 13px; cursor: pointer; color: var(--testo);
    }
    #sfondi button.attivo { background: var(--tinta); border-color: var(--tinta); color: #fff; }
    .legenda { display: flex; flex-direction: column; gap: 4px; }
    .legenda .voce { display: flex; align-items: center; gap: 7px; }
    .legenda .pallino { width: 11px; height: 11px; border-radius: 50%; border: 1.5px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.25); }
    .vuoto { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; padding: 24px; color: var(--tenue); }

    @media (max-width: 640px) {
        #pannello { top: auto; left: 8px; right: 8px; bottom: 8px; width: auto; max-height: 62%; }
        #comandi { bottom: auto; top: 12px; left: 12px; right: 56px; align-items: flex-start; }
        .legenda { flex-direction: row; flex-wrap: wrap; gap: 4px 12px; }
        #sfondi { flex-wrap: wrap; }
    }
</style>
@endpush

@section('contenuto')
    @if ($estensione === null)
        <div class="vuoto">Non ci sono ancora elementi pubblicati sulla mappa di questo territorio.</div>
    @else
        <div id="mappa"></div>

        <div id="comandi">
            <div id="sfondi" class="riquadro-mappa" style="padding: 6px">
                @foreach ($sfondi as $i => $sfondo)
                    <button type="button" data-sfondo="{{ $sfondo['id'] }}" class="{{ $i === 0 ? 'attivo' : '' }}">
                        {{ $sfondo['nome'] }}
                    </button>
                @endforeach
            </div>
            <div class="riquadro-mappa legenda">
                @foreach ($stati as $stato)
                    <span class="voce">
                        <span class="pallino" style="background: {{ $stato['colore'] }}"></span>
                        {{ $stato['etichetta'] }}
                    </span>
                @endforeach
            </div>
        </div>

        <aside id="pannello" hidden>
            <div class="barra"><button type="button" id="pannello-chiudi" aria-label="Chiudi la scheda">&#10005;</button></div>
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
                'apri' => request()->query('elemento'),
            ];
        @endphp

        <script type="application/json" id="dati-portale">{!! json_encode($datiMappa, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <script>
            window.__portale = JSON.parse(document.getElementById('dati-portale').textContent);
        </script>
    @endif
@endsection
