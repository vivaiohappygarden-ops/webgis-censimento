@php
    $albero = $asset->tree;
    $etichetta = $asset->census_code ?: 'senza etichetta';
    $misura = fn ($v, $u) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',').' '.$u;

    // Il campo specie porta di norma il binomio completo ("Tilia cordata"):
    // anteporre il genere lo raddoppierebbe
    $genere = trim((string) ($albero?->genus ?? ''));
    $specie = trim((string) ($albero?->species ?? ''));
    $binomio = $specie !== '' && $genere !== '' && ! str_starts_with(mb_strtolower($specie), mb_strtolower($genere))
        ? $genere.' '.$specie
        : ($specie !== '' ? $specie : $genere);
    if ($binomio !== '' && $albero?->cultivar) {
        $binomio .= " '".$albero->cultivar."'";
    }
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
        @php $urlFoto = $portale->url('/elemento/'.rawurlencode($asset->census_code ?: $asset->id).'/foto'); @endphp
        <div class="foto">
            <img src="{{ $urlFoto }}" alt="Fotografia dell'elemento">
            <button type="button" class="lente" data-ingrandisci="{{ $urlFoto }}" aria-label="Ingrandisci la fotografia">+</button>
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
            <dd>
                @if ($urlPosizione)
                    <a href="{{ $urlPosizione }}" target="_blank" rel="noopener nofollow">{{ number_format($lat, 6, '.', '') }}, {{ number_format($lon, 6, '.', '') }}</a>
                @else
                    {{ number_format($lat, 6, '.', '') }}, {{ number_format($lon, 6, '.', '') }}
                @endif
            </dd>
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
        @if ($albero?->age_years_est)
            <div class="riga"><dt>Età stimata</dt><dd>circa {{ $albero->age_years_est }} anni</dd></div>
        @endif
        @if ($albero?->planted_on)
            <div class="riga"><dt>Messo a dimora</dt><dd>{{ $albero->planted_on->format('Y') }}</dd></div>
        @endif
        @if ($albero?->family)
            <div class="riga"><dt>Famiglia botanica</dt><dd>{{ $albero->family }}</dd></div>
        @endif
        @if (! empty($co2))
            <div class="riga">
                <dt>Anidride carbonica immagazzinata<sup>*</sup></dt>
                <dd>{{ number_format($co2['co2_kg'], 0, ',', '.') }} kg</dd>
            </div>
            @if ($co2['annuo_kg'] !== null)
                <div class="riga">
                    <dt>Assorbimento medio annuo<sup>*</sup></dt>
                    <dd>{{ number_format($co2['annuo_kg'], 1, ',', '.') }} kg/anno</dd>
                </div>
            @endif
        @endif
    </dl>

    @if (! empty($co2))
        <p class="stima">
            <sup>*</sup> Valore stimato, non misurato: {{ $co2['metodo'] }}.
        </p>
    @endif

    @if (! empty($vincoli))
        <div class="vincoli">
            <h2>Descrizione</h2>
            <div class="vincoli-titolo">Vincoli</div>
            @foreach ($vincoli as $vincolo)
                <div class="vincolo">
                    @if ($vincolo->document_id)
                        <a href="{{ $portale->url('/vincolo/'.$vincolo->id.'/documento') }}" target="_blank" rel="noopener">{{ $vincolo->code }}</a>
                    @else
                        <span class="vincolo-codice">{{ $vincolo->code }}</span>
                    @endif
                    @if ($vincolo->name)
                        <span class="vincolo-nome">{{ $vincolo->name }}</span>
                    @endif
                    @if ($vincolo->authority)
                        <span class="vincolo-ente">{{ $vincolo->authority }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

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

    @if (! empty($cronologia))
        <div class="cronologia">
            <h2>Cronologia eventi</h2>
            @foreach ($cronologia as $evento)
                <div class="evento">
                    <div class="quando">
                        {{ $evento['data']->format('d/m/Y') }} &middot; <strong>{{ $evento['titolo'] }}</strong>
                    </div>
                    @if ($evento['nota'] !== '')
                        <p class="nota-evento">{{ $evento['nota'] }}</p>
                    @endif

                    @if (! empty($evento['foto']))
                        <div class="foto-evento">
                            @foreach ($evento['foto'] as $idFoto)
                                @php $urlFotoEvento = $portale->url('/elemento/'.rawurlencode($asset->census_code ?: $asset->id).'/foto/'.$idFoto); @endphp
                                <img src="{{ $urlFotoEvento }}" alt="Fotografia dell'intervento" data-ingrandisci="{{ $urlFotoEvento }}">
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($evento['atti']))
                        <div class="atti">
                            <div class="atti-titolo">Atti pubblici collegati all'evento</div>
                            @foreach ($evento['atti'] as $atto)
                                <div class="atto">
                                    <div class="atto-riga">
                                        @if ($atto['url'])
                                            <a href="{{ $atto['url'] }}">{{ $atto['tipo'] }} N. {{ $atto['numero'] }}</a>
                                        @else
                                            <span class="atto-nome">{{ $atto['tipo'] }} N. {{ $atto['numero'] }}</span>
                                        @endif
                                        <span class="atto-data">{{ $atto['data']->format('d/m/Y') }}</span>
                                    </div>
                                    <div class="atto-ente">{{ $atto['ente'] }}</div>
                                    @if ($atto['descrizione'])
                                        <div class="atto-descrizione">{{ $atto['descrizione'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($urlNavigazione || $urlSegnalazione)
        <div class="azioni">
            @if ($urlNavigazione)
                <a class="azione" href="{{ $urlNavigazione }}" target="_blank" rel="noopener nofollow">Raggiungi l'elemento</a>
            @endif
            @if ($urlSegnalazione)
                <a class="azione secondaria" href="{{ $urlSegnalazione }}">Segnala un problema a {{ $portale->contactEmail() }}</a>
            @endif
        </div>
    @endif
</div>
