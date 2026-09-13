@extends('sito.layout')

@section('titolo', 'Chi siamo')
@section('descrizione', 'Chi esegue i censimenti del verde urbano e le valutazioni di stabilità: come lavoriamo, con quali strumenti, e che cosa consegniamo.')

@php
    $azienda = config('sito.azienda');
    $referenze = config('sito.referenze', []);
    // Il blocco dei dati societari compare solo se qualcosa e' compilato:
    // una pagina "Chi siamo" con i campi vuoti dice meno di una pagina che
    // parla solo di come si lavora.
    $dati = array_filter([
        'Ragione sociale' => $azienda['ragione_sociale'],
        'Sede' => $azienda['sede'],
        'Partita IVA' => $azienda['piva'],
        'Iscrizione REA' => $azienda['rea'],
        'Territorio servito' => $azienda['territorio'],
        'Esperienza nel settore' => $azienda['esperienza'],
    ]);
@endphp

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Chi siamo</span>
        <h1 style="max-width: 22ch;">Con chi avete a che fare</h1>
        @if ($azienda['ragione_sociale'])
            <p class="guida" style="margin-top: var(--s3);">
                {{ $azienda['ragione_sociale'] }}{{ $azienda['territorio'] ? ' — '.$azienda['territorio'] : '' }}.
            </p>
        @endif
    </div>
</section>

@if ($dati)
    <section class="sezione sezione-alt">
        <div class="contenitore">
            <span class="occhiello">Dati dell'impresa</span>
            <h2 style="max-width: 24ch;">In chiaro</h2>
            <ul class="campi" style="margin-top: var(--s4); max-width: 760px;">
                @foreach ($dati as $etichetta => $valore)
                    <li><span class="piccolo" style="display: block;">{{ $etichetta }}</span>{{ $valore }}</li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="sezione @if (! $dati) sezione-alt @endif">
    <div class="contenitore">
        <span class="occhiello">Come lavoriamo</span>
        <h2 style="max-width: 26ch;">Tre abitudini che si vedono nel lavoro consegnato</h2>

        <div class="griglia griglia-3" style="margin-top: var(--s5);">
            <div class="blocco">
                <h3>Si misura, non si stima</h3>
                <p>
                    Le misure si prendono in campo con lo stesso metodo su ogni pianta, così
                    due rilievi a distanza di anni si possono confrontare. Le grandezze
                    derivate le calcola il programma dalla geometria.
                </p>
            </div>
            <div class="blocco">
                <h3>Quello che manca resta vuoto</h3>
                <p>
                    Un dato non rilevato non diventa uno zero. Nelle schede e nelle relazioni
                    compare come "non rilevato": è l'unico modo perché il documento regga a un
                    controllo.
                </p>
            </div>
            <div class="blocco">
                <h3>Il metodo è sempre dichiarato</h3>
                <p>
                    Dove c'è una stima — l'anidride carbonica, i benefici ambientali — accanto
                    al numero c'è scritto con quale modello è stata ottenuta e su quanti alberi.
                </p>
            </div>
        </div>
    </div>
</section>

@if ($referenze)
    <section class="sezione sezione-alt">
        <div class="contenitore">
            <span class="occhiello">Lavori svolti</span>
            <h2 style="max-width: 24ch;">Referenze</h2>
            <ul class="elenco" style="margin-top: var(--s4); max-width: var(--misura);">
                @foreach ($referenze as $r)
                    <li>
                        <strong>{{ $r['ente'] ?? '' }}</strong>{{ ! empty($r['anno']) ? ' — '.$r['anno'] : '' }}
                        @if (! empty($r['lavoro']))
                            <span style="display: block;">{{ $r['lavoro'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="sezione">
    <div class="contenitore">
        <h2 style="max-width: 24ch;">Volete vedere un lavoro consegnato?</h2>
        <p class="guida" style="margin-top: var(--s3);">
            Il modo più veloce per capire come lavoriamo è guardare un censimento vero e il
            portale che ne è nato.
        </p>
        <div class="inviti">
            <a class="invito" href="{{ $u('contatti') }}">Mettiamoci in contatto</a>
        </div>
    </div>
</section>

@endsection
