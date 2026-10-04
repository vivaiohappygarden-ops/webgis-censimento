<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 18px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px; }
    h3 { font-size: 10px; margin: 10px 0 4px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; font-size: 9.5px; word-wrap: break-word; overflow-wrap: break-word; }
    th { background: #eee; width: 30%; }
    table.lista th { width: auto; font-size: 8.5px; }
    table.lista td { font-size: 8.5px; }
    .num { text-align: right; }
    .planimetria { margin-top: 6px; page-break-inside: avoid; }
    .planimetria img { width: 100%; border: 1px solid #999; }
    .foto-box { width: 46%; border: 1px solid #999; display: inline-block; margin: 0 1.5% 8px 0; text-align: center; color: #999; vertical-align: top; page-break-inside: avoid; }
    .foto-box img { max-width: 100%; max-height: 150px; }
    .foto-did { font-size: 8.5px; color: #333; border-top: 1px solid #ddd; padding: 2px; }
    .legenda { font-size: 8.5px; color: #444; margin-top: 4px; line-height: 1.4; }
</style>
</head>
<body>
    @php
        $data = fn ($v, $ora = false) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone('Europe/Rome')->format($ora ? 'd/m/Y H:i' : 'd/m/Y') : '-';
        $numero = fn ($v, $dec = 1) => $v === null || $v === '' ? '-' : number_format((float) $v, $dec, ',', '.');
    @endphp
    @include('pdf.partials.intestazione', ['tenantId' => $organization?->id])
    <div class="head">
        <h1>Scheda elemento {{ $asset->census_code ?? '' }}</h1>
        <div class="muted">Stampata il {{ ($stampatoIl ?? now('Europe/Rome'))->format('d/m/Y H:i') }}</div>
    </div>

    <table>
        <tr><th>Codice censimento</th><td>{{ $asset->census_code ?? '-' }}</td></tr>
        <tr>
            <th>Tipo (catalogo MD)</th>
            <td>{{ $asset->objectType?->name }} ({{ $asset->objectType?->code }})
                @if ($asset->objectType?->subType) - {{ $asset->objectType->subType->name }}@endif
                @if ($asset->objectType?->subType?->mainType) / {{ $asset->objectType->subType->mainType->name }}@endif
            </td>
        </tr>
        <tr>
            <th>Dove</th>
            <td>
                {{ $asset->area?->name }} ({{ $asset->area?->code }})
                @if ($asset->area?->locality) - {{ $asset->area->locality->name }}@endif
                @if ($asset->area?->locality?->site) - {{ $asset->area->locality->site->name }}@endif
                @if ($asset->area?->locality?->site?->client) - {{ $asset->area->locality->site->client->name }}@endif
            </td>
        </tr>
        {{-- Etichette da AssetStatus, non ricopiate qui: la mappa inline aveva
             gia' divergenze e perfino voci fuori vocabolario ('felled') --}}
        <tr><th>Stato</th><td>{{ \App\Support\AssetStatus::label($asset->status) }}@if (\App\Support\AssetStatus::inArchivio($asset->status)) (scheda in archivio)@endif</td></tr>
        <tr><th>Data del rilievo</th><td>{{ $asset->surveyed_at?->format('d/m/Y') ?? '-' }}</td></tr>
        <tr><th>Ultimo aggiornamento</th><td>{{ $asset->updated_at?->timezone('Europe/Rome')->format('d/m/Y H:i') }}@if ($asset->version) (revisione {{ $asset->version }})@endif</td></tr>
        @if ($asset->notes)
        <tr><th>Note</th><td>{{ $asset->notes }}</td></tr>
        @endif
    </table>

    @if (! empty($posizione))
    <h2>Posizione</h2>
    <table>
        @php
            $tipoGeom = match (strtoupper((string) ($posizione['tipo'] ?? ''))) {
                'POINT', 'MULTIPOINT' => 'Punto',
                'LINESTRING', 'MULTILINESTRING' => 'Linea',
                'POLYGON', 'MULTIPOLYGON' => 'Poligono',
                default => $posizione['tipo'] ?: '-',
            };
            $misure = array_filter([
                $asset->computed_length_m !== null && $tipoGeom === 'Linea' ? 'lunghezza '.$numero($asset->computed_length_m, 1).' m' : null,
                $asset->computed_area_sqm !== null && $tipoGeom === 'Poligono' ? 'superficie '.$numero($asset->computed_area_sqm, 1).' m²' : null,
                $asset->computed_perimeter_m !== null && $tipoGeom === 'Poligono' ? 'perimetro '.$numero($asset->computed_perimeter_m, 1).' m' : null,
            ]);
        @endphp
        <tr><th>Geometria</th><td>{{ $tipoGeom }}@if ($misure) - {{ implode(', ', $misure) }}@endif</td></tr>
        @if ($posizione['lat'] !== null)
        <tr><th>Coordinate geografiche (WGS84)</th><td>{{ number_format($posizione['lat'], 6, ',', '') }} N - {{ number_format($posizione['lon'], 6, ',', '') }} E</td></tr>
        <tr><th>Coordinate piane (EPSG:{{ $posizione['srid'] }})</th><td>E {{ $numero($posizione['est'], 2) }} - N {{ $numero($posizione['nord'], 2) }}</td></tr>
        @else
        <tr><th>Coordinate</th><td>Elemento senza geometria</td></tr>
        @endif
        @if ($asset->gps_accuracy_m !== null)
        <tr><th>Precisione GPS del rilievo</th><td>{{ $numero($asset->gps_accuracy_m, 1) }} m</td></tr>
        @endif
        @if (($posizione['vincoli'] ?? collect())->isNotEmpty())
        <tr><th>Vincoli</th><td>@foreach ($posizione['vincoli'] as $v){{ $v->code }} {{ $v->name }}@if ($v->authority) ({{ $v->authority }})@endif @if (! $loop->last); @endif @endforeach</td></tr>
        @endif
    </table>
    @if (! empty($posizione['planimetria']))
    @php $pl = $posizione['planimetria']; @endphp
    <div class="planimetria">
        <img src="data:image/png;base64,{{ base64_encode($pl['png']) }}" alt="Planimetria">
        <div class="legenda">
            Planimetria schematica nel sistema metrico EPSG:{{ $pl['srid'] }}, orientata a nord, finestra di {{ $numero($pl['metri_larghezza'], 0) }} x {{ $numero($pl['metri_altezza'], 0) }} m, senza sfondo cartografico.
            In verde l'elemento{{ $asset->tree && $asset->tree->crown_diameter_m ? ' con la chioma a misura' : '' }}; in grigio {{ $pl['vicini'] === 1 ? 'l\'elemento vicino censito' : $pl['vicini'].' elementi vicini censiti' }}{{ $pl['etichette'] ? ' con il numero del cartellino' : ' (etichette omesse per densità)' }};
            tratteggiati i confini {{ $pl['aree'] === 1 ? 'dell\'area di gestione' : 'delle '.$pl['aree'].' aree di gestione' }}.
        </div>
    </div>
    @endif
    @endif

    @if ($asset->tree && in_array('dendro', $sezioni))
    <h2>Dati dendrometrici e agronomici</h2>
    <table>
        <tr><th>Genere e specie</th><td>{{ trim(($asset->tree->genus ?? '').' '.($asset->tree->species ?? '')) ?: '-' }}@if ($asset->tree->cultivar) '{{ $asset->tree->cultivar }}'@endif</td></tr>
        @if ($asset->tree->family)<tr><th>Famiglia</th><td>{{ $asset->tree->family }}</td></tr>@endif
        @if ($asset->tree->common_name)<tr><th>Nome comune</th><td>{{ $asset->tree->common_name }}</td></tr>@endif
        @if ($asset->tree->plant_number)<tr><th>Numero pianta</th><td>{{ $asset->tree->plant_number }}</td></tr>@endif
        <tr><th>Altezza</th><td>{{ $asset->tree->height_m !== null ? $asset->tree->height_m.' m' : '-' }}</td></tr>
        <tr><th>Diametro del fusto</th><td>{{ $asset->tree->dbh_cm !== null ? $asset->tree->dbh_cm.' cm' : '-' }}</td></tr>
        <tr><th>Diametro della chioma</th><td>{{ $asset->tree->crown_diameter_m !== null ? $asset->tree->crown_diameter_m.' m' : '-' }}</td></tr>
        <tr><th>Altezza primo palco</th><td>{{ $asset->tree->crown_insertion_m !== null ? $asset->tree->crown_insertion_m.' m' : '-' }}</td></tr>
        @if ($asset->tree->age_years_est !== null)<tr><th>Età</th><td>{{ $asset->tree->age_years_est }} anni{{ $asset->tree->age_qualifier ? ' ('.$asset->tree->age_qualifier.')' : '' }}</td></tr>@endif
        @if ($asset->tree->age_class)<tr><th>Fase fisiologica</th><td>{{ $asset->tree->age_class }}</td></tr>@endif
        @if ($asset->tree->vegetative_state)<tr><th>Stato vegetativo</th><td>{{ $asset->tree->vegetative_state }}</td></tr>@endif
        @if ($asset->tree->social_position)<tr><th>Posizione sociale</th><td>{{ $asset->tree->social_position }}</td></tr>@endif
        @if ($asset->tree->growth_site)<tr><th>Sito di crescita</th><td>{{ $asset->tree->growth_site }}</td></tr>@endif
        @if ($asset->tree->target)<tr><th>Bersaglio (frequentazione)</th><td>{{ $asset->tree->target }}</td></tr>@endif
        @if ($asset->tree->is_monumental)<tr><th>Monumentale</th><td>sì @if ($asset->tree->monumental_ref)({{ $asset->tree->monumental_ref }})@endif</td></tr>@endif
        @if ($asset->tree->is_dedicated && ($asset->tree->dedicated_to['name'] ?? null))<tr><th>Dedicato a</th><td>{{ $asset->tree->dedicated_to['name'] }}@if ($asset->tree->dedicated_to['occasion'] ?? null) ({{ $asset->tree->dedicated_to['occasion'] }})@endif</td></tr>@endif
    </table>
    @endif

    @if (in_array('vta', $sezioni) && $asset->tree)
    <h2>Valutazioni di stabilità</h2>
    @if (($valutazioni ?? collect())->isEmpty())
    <div class="legenda">Nessuna valutazione registrata per questo albero.</div>
    @else
    <table class="lista">
        <thead>
            <tr><th style="width:13%">Data</th><th style="width:9%">Classe</th><th style="width:20%">Rilevatore</th><th>Prescrizioni</th><th style="width:15%">Prossima verifica</th><th style="width:11%">Perizia</th></tr>
        </thead>
        <tbody>
        @foreach ($valutazioni as $v)
            <tr>
                <td>{{ $v->assessed_on?->format('d/m/Y') ?? '-' }}</td>
                <td>{{ $v->failure_class ?? '-' }}</td>
                <td>{{ $v->assessor_external ?: ($v->assessor?->name ?? '-') }}</td>
                <td>{{ $v->prescriptions ?: '-' }}</td>
                <td>{{ $v->next_check_due?->format('d/m/Y') ?? '-' }}</td>
                <td>{{ $v->validated_at ? 'validata' : ($v->report_issued_at ? 'emessa' : '-') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @if ($assessment)
    <h3>Ultima valutazione ({{ $assessment->assessed_on?->format('d/m/Y') }})</h3>
    <table>
        <tr><th>Classe di propensione al cedimento</th><td>{{ $assessment->failure_class ?? '-' }}</td></tr>
        @if ($assessment->outcome)<tr><th>Esito</th><td>{{ \App\Models\TreeAssessment::ESITI[$assessment->outcome] ?? $assessment->outcome }}</td></tr>@endif
        @if (is_array($assessment->targets) && count($assessment->targets))<tr><th>Bersagli</th><td>{{ implode('; ', array_map(fn ($t) => is_array($t) ? implode(' ', array_filter($t)) : (string) $t, $assessment->targets)) }}</td></tr>@endif
        @if ($assessment->prescriptions)<tr><th>Prescrizioni</th><td>{{ $assessment->prescriptions }}</td></tr>@endif
        @if ($assessment->next_check_due)<tr><th>Prossima verifica entro</th><td>{{ $assessment->next_check_due->format('d/m/Y') }}</td></tr>@endif
    </table>
    @endif
    @endif
    @endif

    @if (in_array('lavori', $sezioni))
    <h2>Lavori e segnalazioni</h2>
    @if ($lavori['ordini']->isEmpty())
    <div class="legenda">Nessun ordine di lavoro collega questo elemento.</div>
    @else
    <table class="lista">
        <thead>
            <tr><th style="width:14%">Ordine</th><th>Titolo</th><th style="width:12%">Stato</th><th style="width:19%">Periodo previsto</th><th style="width:14%">Squadra</th><th style="width:20%">Su questo elemento</th></tr>
        </thead>
        <tbody>
        @foreach ($lavori['ordini'] as $o)
            @php $riga = $lavori['righe'][$o->id] ?? null; @endphp
            <tr>
                <td>{{ $o->code }}</td>
                <td>{{ $o->title }}@if ($o->workType) ({{ $o->workType->name }})@endif</td>
                <td>{{ \App\Models\WorkOrder::STATUS_LABELS[$o->status] ?? $o->status }}</td>
                <td>{{ $o->planned_start ? $data($o->planned_start) : '-' }}@if ($o->planned_end) - {{ $data($o->planned_end) }}@endif</td>
                <td>{{ $o->team?->name ?? '-' }}</td>
                <td>{{ implode(' - ', array_filter([$riga?->workType?->name, $riga?->planned_quantity !== null ? $numero($riga->planned_quantity, 2).' '.($riga->unit ?? '') : null, $riga?->notes])) ?: '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
    @if ($lavori['segnalazioni']->isNotEmpty())
    <h3>Segnalazioni</h3>
    <table class="lista">
        <thead>
            <tr><th style="width:14%">Codice</th><th style="width:13%">Data</th><th style="width:16%">Categoria</th><th style="width:10%">Gravità</th><th style="width:13%">Stato</th><th>Descrizione</th><th style="width:14%">Lavoro</th></tr>
        </thead>
        <tbody>
        @foreach ($lavori['segnalazioni'] as $s)
            <tr>
                <td>{{ $s->code }}</td>
                <td>{{ $data($s->created_at) }}</td>
                <td>{{ $s->category ?: '-' }}</td>
                <td>{{ $s->severity ?: '-' }}</td>
                <td>{{ \App\Services\Assets\CronologiaElemento::STATO_SEGNALAZIONE[$s->status] ?? $s->status }}</td>
                <td>{{ \Illuminate\Support\Str::limit((string) $s->description, 140) }}</td>
                <td>{{ $s->workOrder?->code ?? '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
    @endif

    @if (in_array('attributi', $sezioni) && ! empty($asset->attributes) && count($asset->attributes))
    <h2>Attributi del tipo</h2>
    <table>
        @foreach ($asset->attributes as $key => $value)
        <tr>
            <th>{{ $fields[$key]->label ?? $key }}</th>
            <td>
                @if (($fields[$key]->field_type ?? null) === 'boolean')
                    {{ $value ? 'sì' : 'no' }}
                @elseif (is_array($value))
                    {{ implode(', ', $value) }}
                @else
                    {{ $value }}
                @endif
            </td>
        </tr>
        @endforeach
    </table>
    @endif

    @if (! empty($benefici) && (! empty($benefici['servizi']['voci']) || ! empty($benefici['co2'])))
    <h2>Benefici ambientali (stima)</h2>
    <table class="lista">
        <thead><tr><th>Voce</th><th class="num" style="width:22%">Valore</th><th class="num" style="width:18%">Controvalore</th></tr></thead>
        <tbody>
        @if (! empty($benefici['co2']))
            <tr><td>CO2 immagazzinata (stima)</td><td class="num">{{ $numero($benefici['co2']['co2_kg'] ?? null, 0) }} kg</td><td class="num">{{ isset($benefici['co2']['valore_euro']) ? $numero($benefici['co2']['valore_euro'], 2).' €' : '-' }}</td></tr>
            @if (($benefici['co2']['annuo_kg'] ?? null) !== null)
            <tr><td>CO2 assorbita in un anno (stima)</td><td class="num">{{ $numero($benefici['co2']['annuo_kg'], 1) }} kg/anno</td><td class="num">-</td></tr>
            @endif
        @endif
        @foreach ($benefici['servizi']['voci'] ?? [] as $voce)
            <tr><td>{{ $voce['etichetta'] }}</td><td class="num">{{ $numero($voce['valore'], 1) }} {{ $voce['unita'] }}</td><td class="num">{{ isset($voce['euro']) && $voce['euro'] !== null ? $numero($voce['euro'], 2).' €' : '-' }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="legenda">
        Stime calcolate con i modelli dichiarati nelle impostazioni del programma{{ ! empty($benefici['co2']['metodo']) ? ' (CO2: '.$benefici['co2']['metodo'].')' : '' }}{{ ! empty($benefici['servizi']['metodo']) ? ' (altri servizi: '.$benefici['servizi']['metodo'].')' : '' }}: sono stime da misure dendrometriche, non misure dirette, e vanno verificate prima di pubblicarle.
    </div>
    @endif

    @if (! empty($cronologia))
    <h2>Cronologia</h2>
    @if (empty($cronologia['eventi']))
    <div class="legenda">Nessun fatto registrato.</div>
    @else
    <table class="lista">
        <thead><tr><th style="width:13%">Data</th><th style="width:27%">Che cosa</th><th>Dettagli</th></tr></thead>
        <tbody>
        @foreach ($cronologia['eventi'] as $e)
            <tr>
                <td>{{ $data($e['data'] ?? null) }}</td>
                <td>{{ $e['titolo'] }}</td>
                <td>{{ $e['dettaglio'] ?: '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @if (($cronologia['totale'] ?? 0) > count($cronologia['eventi']))
    <div class="legenda">Sono riportati i {{ count($cronologia['eventi']) }} fatti più recenti su {{ $cronologia['totale'] }}: la cronologia completa è nella scheda a video.</div>
    @endif
    @endif
    @endif

    @if (count($foto) || $fotoNota)
    <h2>Documentazione fotografica</h2>
    @foreach ($foto as $i => $f)
        <div class="foto-box">
            <img src="{{ $f['data'] }}" alt="Foto {{ $i + 1 }}">
            @php
                $didascalia = 'Foto '.($i + 1);
                if ($f['categoria']) {
                    $didascalia .= ' - '.$f['categoria'];
                }
                if ($f['scattata']) {
                    $didascalia .= ' - scattata il '.$f['scattata'];
                }
            @endphp
            <div class="foto-did">{{ $didascalia }}</div>
        </div>
    @endforeach
    @if ($fotoNota)
        <div class="legenda">{{ $fotoNota }}</div>
    @endif
    @endif
</body>
</html>
