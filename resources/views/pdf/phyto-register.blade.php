<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 3px 4px; text-align: left; vertical-align: top; font-size: 8.5px; word-wrap: break-word; }
    th { background: #eee; }
    td.num { text-align: right; }
    .firma { margin-top: 30px; width: 100%; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; }
    .firma .linea { border-top: 1px solid #111; width: 220px; padding-top: 4px; text-align: center; }
    .nota { margin-top: 10px; font-size: 8.5px; color: #555; }
</style>
</head>
<body>
    @php
        $methodLabels = [
            'irrorazione' => 'Irrorazione', 'endoterapia' => 'Endoterapia',
            'iniezione_suolo' => 'Iniezione al suolo', 'esca' => 'Esca', 'altro' => 'Altro',
        ];
    @endphp
    <div class="head">
        <h1>Registro dei trattamenti fitosanitari - anno {{ $year }}</h1>
        <div class="muted">
            {{ $organization?->name }}@if ($area) - area: {{ $area->name }}@endif
            - stampato il {{ now('Europe/Rome')->format('d/m/Y') }}
        </div>
    </div>

    <table>
        <tr>
            <th style="width: 7%;">Data</th>
            <th style="width: 13%;">Area / elemento</th>
            <th style="width: 15%;">Prodotto (n. reg.)</th>
            <th style="width: 12%;">Principio attivo</th>
            <th style="width: 12%;">Avversità</th>
            <th style="width: 9%;">Metodo</th>
            <th style="width: 8%;" class="num">Quantità</th>
            <th style="width: 8%;" class="num">Acqua (l)</th>
            <th style="width: 8%;" class="num">Sup. (mq)</th>
            <th style="width: 6%;" class="num">Rientro (h)</th>
            <th style="width: 12%;">Operatore</th>
        </tr>
        @forelse ($treatments as $t)
        <tr>
            <td>{{ $t->treated_on->format('d/m/Y') }}</td>
            <td>{{ $t->area?->name }}@if ($t->asset) - {{ $t->asset->census_code }}@endif</td>
            <td>{{ $t->product_name }}@if ($t->registration_number) ({{ $t->registration_number }})@endif</td>
            <td>{{ $t->active_substance ?: '-' }}</td>
            <td>{{ $t->adversity }}</td>
            <td>{{ $methodLabels[$t->method] ?? $t->method }}</td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $t->quantity, 3, ',', '.'), '0'), ',') }} {{ $t->unit }}</td>
            <td class="num">{{ $t->water_volume_l !== null ? rtrim(rtrim(number_format((float) $t->water_volume_l, 1, ',', '.'), '0'), ',') : '-' }}</td>
            <td class="num">{{ $t->surface_sqm !== null ? number_format((float) $t->surface_sqm, 0, ',', '.') : '-' }}</td>
            <td class="num">{{ $t->reentry_hours ?? '-' }}</td>
            <td>{{ $t->operator?->name ?: '-' }}</td>
        </tr>
        @empty
        <tr><td colspan="11">Nessun trattamento registrato nell'anno {{ $year }}.</td></tr>
        @endforelse
    </table>

    <p class="nota">
        Registro dei trattamenti ai sensi del DM 22 gennaio 2014 (PAN). Le annotazioni vanno
        completate entro trenta giorni dal trattamento; il registro va conservato almeno tre anni.
    </p>

    <table class="firma">
        <tr>
            <td></td>
            <td class="linea">Firma del titolare o dell'utilizzatore</td>
        </tr>
    </table>
</body>
</html>
