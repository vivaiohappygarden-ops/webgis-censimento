@php
    $albero = $asset->tree;
    $binomio = trim(($albero?->genus ?? '').' '.($albero?->species ?? ''));
    $etichetta = $asset->census_code ?: 'senza etichetta';
    $misura = fn ($v, $u) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',').' '.$u;
@endphp

<div class="scheda">
    <div class="intestazione">
        <span class="badge-etichetta">{{ $etichetta }}</span>
        <span class="tipo">{{ $asset->objectType?->code }} {{ mb_strtoupper($asset->objectType?->name ?? '') }}</span>
        @if ($albero)
            {{-- Lo stato racconta la salute di una pianta: su un prato o un
                 arredo non avrebbe senso --}}
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
        <div class="specie-senza-foto">
            @if ($binomio !== '')<em>{{ $binomio }}</em>@endif
            @if ($albero?->common_name)<span>{{ $albero->common_name }}</span>@endif
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
            <div class="riga"><dt>Diametro del tronco</dt><dd>{{ $misura($albero->dbh_cm, 'cm') }}</dd></div>
        @elseif ($albero?->trunk_circumference_cm)
            <div class="riga"><dt>Circonferenza del tronco</dt><dd>{{ $misura($albero->trunk_circumference_cm, 'cm') }}</dd></div>
        @endif
        @if ($albero?->height_m)
            <div class="riga"><dt>Altezza</dt><dd>{{ $misura($albero->height_m, 'm') }}</dd></div>
        @endif
        @if ($albero?->crown_diameter_m)
            <div class="riga"><dt>Diametro della chioma</dt><dd>{{ $misura($albero->crown_diameter_m, 'm') }}</dd></div>
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
