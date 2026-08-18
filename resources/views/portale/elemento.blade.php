@extends('portale.layout')

@php
    $albero = $asset->tree;
    $binomio = trim(($albero?->genus ?? '').' '.($albero?->species ?? ''));
    $etichetta = $asset->census_code ?: 'senza etichetta';
@endphp

@section('titolo', $etichetta)

@push('stile')
<style>
    .scheda { background: var(--carta); border: 1px solid var(--bordo); border-radius: 12px; overflow: hidden; max-width: 620px; }
    .intestazione { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--bordo); flex-wrap: wrap; }
    .badge-etichetta { background: var(--tinta); color: #fff; border-radius: 6px; padding: 2px 8px; font-weight: 600; font-size: 14px; }
    .tipo { font-size: 13px; color: var(--tenue); letter-spacing: 0.04em; }
    .stato { margin-left: auto; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
    .foto { position: relative; }
    .foto img { display: block; width: 100%; height: auto; }
    .foto .specie {
        position: absolute; left: 12px; bottom: 12px;
        background: rgba(255,255,255,0.88); border-radius: 8px; padding: 6px 10px; font-size: 14px;
    }
    .foto .specie em { display: block; font-style: italic; }
    .foto .specie span { color: var(--tenue); font-size: 13px; }
    dl { margin: 0; padding: 4px 16px 16px; }
    .riga { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid #eef1ee; font-size: 14px; }
    .riga:last-child { border-bottom: 0; }
    dt { color: var(--tenue); text-transform: uppercase; font-size: 12px; letter-spacing: 0.05em; }
    dd { margin: 0; text-align: right; }
    .tutele { padding: 0 16px 16px; }
    .tutela { display: inline-block; border: 1px solid var(--bordo); border-radius: 999px; padding: 3px 10px; font-size: 12px; color: var(--tenue); margin: 0 6px 6px 0; }
    .indietro { display: inline-block; margin-bottom: 14px; font-size: 14px; text-decoration: none; }
    .prossimo { color: var(--tenue); font-size: 13px; margin-top: 16px; max-width: 620px; }
</style>
@endpush

@section('contenuto')
    <a class="indietro" href="{{ $portale->url('/') }}">&larr; Torna alla home</a>

    <div class="scheda">
        <div class="intestazione">
            <span class="badge-etichetta">{{ $etichetta }}</span>
            <span class="tipo">{{ $asset->objectType?->code }} {{ mb_strtoupper($asset->objectType?->name ?? '') }}</span>
            @if ($albero)
                {{-- Lo stato racconta la salute di una pianta: su un prato o
                     un arredo non avrebbe senso --}}
                <span class="stato" style="color: {{ \App\Services\Portale\PortalState::colore($stato) }}">
                    {{ \App\Services\Portale\PortalState::etichetta($stato) }}
                </span>
            @endif
        </div>

        @if ($hasFoto)
            <div class="foto">
                <img src="{{ $portale->url('/elemento/'.rawurlencode($asset->census_code ?: $asset->id).'/foto') }}" alt="Fotografia dell'elemento">
                @if ($binomio !== '' || $albero?->common_name)
                    <div class="specie">
                        @if ($binomio !== '')<em>{{ $binomio }}</em>@endif
                        @if ($albero?->common_name)<span>{{ $albero->common_name }}</span>@endif
                    </div>
                @endif
            </div>
        @elseif ($binomio !== '' || $albero?->common_name)
            <div style="padding: 12px 16px 0">
                @if ($binomio !== '')<em>{{ $binomio }}</em><br>@endif
                @if ($albero?->common_name)<span style="color: var(--tenue)">{{ $albero->common_name }}</span>@endif
            </div>
        @endif

        <dl>
            @if ($asset->surveyed_at)
                <div class="riga"><dt>Data rilevamento</dt><dd>{{ $asset->surveyed_at->format('d/m/Y') }}</dd></div>
            @endif
            @if ($asset->area?->name)
                <div class="riga">
                    <dt>Zona</dt>
                    <dd>{{ $asset->area->name.($asset->area->locality?->name ? ' – '.$asset->area->locality->name : '') }}</dd>
                </div>
            @endif
            <div class="riga">
                <dt>Coordinate</dt>
                <dd>{{ number_format($lat, 6, '.', '') }}, {{ number_format($lon, 6, '.', '') }}</dd>
            </div>
            @if ($albero?->dbh_cm)
                <div class="riga"><dt>Diametro del tronco</dt><dd>{{ rtrim(rtrim(number_format((float) $albero->dbh_cm, 1, ',', ''), '0'), ',') }} cm</dd></div>
            @elseif ($albero?->trunk_circumference_cm)
                <div class="riga"><dt>Circonferenza del tronco</dt><dd>{{ rtrim(rtrim(number_format((float) $albero->trunk_circumference_cm, 1, ',', ''), '0'), ',') }} cm</dd></div>
            @endif
            @if ($albero?->height_m)
                <div class="riga"><dt>Altezza</dt><dd>{{ rtrim(rtrim(number_format((float) $albero->height_m, 1, ',', ''), '0'), ',') }} m</dd></div>
            @endif
            @if ($albero?->crown_diameter_m)
                <div class="riga"><dt>Diametro della chioma</dt><dd>{{ rtrim(rtrim(number_format((float) $albero->crown_diameter_m, 1, ',', ''), '0'), ',') }} m</dd></div>
            @endif
            @if ($albero?->planted_on)
                <div class="riga"><dt>Messo a dimora</dt><dd>{{ $albero->planted_on->format('Y') }}</dd></div>
            @endif
        </dl>

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
    </div>

    <p class="prossimo">
        Cronologia degli interventi, vincoli e stima dell'anidride carbonica assorbita sono in preparazione.
    </p>
@endsection
