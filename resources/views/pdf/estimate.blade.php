<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 16px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; font-size: 9.5px; word-wrap: break-word; }
    th { background: #eee; }
    td.num, th.num { text-align: right; }
    .tot td { font-weight: bold; background: #f5f5f5; }
    .firma { margin-top: 30px; width: 100%; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; }
    .firma .linea { border-top: 1px solid #111; width: 220px; padding-top: 4px; text-align: center; }
</style>
</head>
<body>
    <div class="head">
        <h1>Preventivo {{ $estimate->code }}</h1>
        <div class="muted">{{ $organization?->name }} - emesso il {{ $estimate->created_at?->timezone('Europe/Rome')->format('d/m/Y') }}</div>
    </div>

    <table>
        <tr><th style="width: 30%;">Spettabile</th><td>{{ $estimate->client?->name }}</td></tr>
        <tr><th>Oggetto</th><td>{{ $estimate->title }}</td></tr>
        @if ($estimate->area)
        <tr><th>Area di intervento</th><td>{{ $estimate->area->name }}</td></tr>
        @endif
        @if ($estimate->valid_until)
        <tr><th>Validità dell'offerta</th><td>fino al {{ $estimate->valid_until->format('d/m/Y') }}</td></tr>
        @endif
    </table>

    <h2>Voci di preventivo</h2>
    <table>
        <tr>
            <th style="width: 42%;">Descrizione</th>
            <th>Unità</th>
            <th class="num">Quantità</th>
            <th class="num">Prezzo unitario</th>
            <th class="num">Importo</th>
        </tr>
        @foreach ($estimate->items as $item)
        <tr>
            <td>{{ $item->description }}@if ($item->workType) ({{ $item->workType->name }})@endif</td>
            <td>{{ $item->unit }}</td>
            <td class="num">{{ number_format((float) $item->quantity, 2, ',', '.') }}</td>
            <td class="num">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
            <td class="num">{{ number_format((float) $item->quantity * (float) $item->unit_price, 2, ',', '.') }}</td>
        </tr>
        @endforeach
        <tr>
            <td colspan="4" class="num">Imponibile</td>
            <td class="num">{{ number_format($subtotal, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td colspan="4" class="num">IVA {{ rtrim(rtrim(number_format((float) $estimate->vat_percent, 2, ',', '.'), '0'), ',') }}%</td>
            <td class="num">{{ number_format($vat, 2, ',', '.') }}</td>
        </tr>
        <tr class="tot">
            <td colspan="4" class="num">Totale (EUR)</td>
            <td class="num">{{ number_format($total, 2, ',', '.') }}</td>
        </tr>
    </table>

    @if ($estimate->notes)
    <h2>Note e condizioni</h2>
    <p style="line-height: 1.5; white-space: pre-line;">{{ $estimate->notes }}</p>
    @endif

    <table class="firma">
        <tr>
            <td style="width: 50%;">
                Per accettazione (data e firma)<br><br>
                ______________________________
            </td>
            <td class="linea">{{ $organization?->name }}</td>
        </tr>
    </table>
</body>
</html>
