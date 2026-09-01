<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 18px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; font-size: 9.5px; word-wrap: break-word; overflow-wrap: break-word; }
    th { background: #eee; width: 30%; }
    .foto-box { width: 46%; border: 1px solid #999; display: inline-block; margin: 0 1.5% 8px 0; text-align: center; color: #999; vertical-align: top; page-break-inside: avoid; }
    .foto-box img { max-width: 100%; max-height: 150px; }
    .foto-did { font-size: 8.5px; color: #333; border-top: 1px solid #ddd; padding: 2px; }
    .legenda { font-size: 8.5px; color: #444; margin-top: 4px; line-height: 1.4; }
</style>
</head>
<body>
    <div class="head">
        <h1>Scheda elemento {{ $asset->census_code ?? '' }}</h1>
        <div class="muted">{{ $organization?->name }} - stampata il {{ now()->timezone('Europe/Rome')->format('d/m/Y H:i') }}</div>
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
        <tr><th>Ultimo aggiornamento</th><td>{{ $asset->updated_at?->timezone('Europe/Rome')->format('d/m/Y H:i') }}</td></tr>
        @if ($asset->notes)
        <tr><th>Note</th><td>{{ $asset->notes }}</td></tr>
        @endif
    </table>

    @if ($asset->tree && in_array('dendro', $sezioni))
    <h2>Dati dendrometrici e agronomici</h2>
    <table>
        <tr><th>Genere e specie</th><td>{{ trim(($asset->tree->genus ?? '').' '.($asset->tree->species ?? '')) ?: '-' }}@if ($asset->tree->cultivar) '{{ $asset->tree->cultivar }}'@endif</td></tr>
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

    @if ($assessment)
    <h2>Ultima valutazione di stabilità</h2>
    <table>
        <tr><th>Data</th><td>{{ $assessment->assessed_on?->format('d/m/Y') }}</td></tr>
        <tr><th>Classe di propensione al cedimento</th><td>{{ $assessment->failure_class ?? '-' }}</td></tr>
        @if ($assessment->prescriptions)<tr><th>Prescrizioni</th><td>{{ $assessment->prescriptions }}</td></tr>@endif
        @if ($assessment->next_check_due)<tr><th>Prossima verifica entro</th><td>{{ $assessment->next_check_due->format('d/m/Y') }}</td></tr>@endif
    </table>
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
