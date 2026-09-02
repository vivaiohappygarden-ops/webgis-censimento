<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 16px 0 4px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    .bozza { border: 1.5px solid #b45309; color: #b45309; display: inline-block; padding: 2px 8px; font-weight: bold; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 3px 5px; text-align: left; vertical-align: top; font-size: 9px; word-wrap: break-word; }
    th { background: #eee; }
    td.num, th.num { text-align: right; }
    tr.totale td { background: #f4f4f4; font-weight: bold; }
    .nota-riga { color: #555; font-size: 8px; }
    .firma { margin-top: 36px; }
    .firma .linea { margin-top: 28px; border-top: 1px solid #111; width: 260px; padding-top: 3px; font-size: 9px; }
    .nota { margin-top: 12px; font-size: 8.5px; color: #555; }
</style>
</head>
<body>
    @php
        $eur = fn ($v) => number_format((float) $v, 2, ',', '.');
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    @endphp

    <div class="head">
        <h1>Stato di avanzamento lavori @if ($sal->code){{ $sal->code }}@endif</h1>
        <div class="muted">
            {{ $sal->client?->name }}
            — periodo dal {{ $sal->period_from->format('d/m/Y') }} al {{ $sal->period_to->format('d/m/Y') }}
            <br>
            Redatto da {{ $organization?->name }}
            @if ($sal->status === 'bozza')
                — stampato il {{ $dataDocumento->format('d/m/Y') }}
            @else
                — validato il {{ $dataDocumento->format('d/m/Y') }}@if ($sal->validator) da {{ $sal->validator->name }}@endif
            @endif
            @if ($sal->status === 'fatturato')
                {{-- Gli estremi della fattura registrata (il documento fiscale
                     lo emette il commercialista: qui se ne riportano i riferimenti) --}}
                — fattura{{ $sal->invoice_ref ? ' '.$sal->invoice_ref : '' }}{{ $sal->invoiced_at ? ' del '.$sal->invoiced_at->format('d/m/Y') : '' }}{{ $sal->payment_due_at ? ', scadenza di pagamento '.$sal->payment_due_at->format('d/m/Y') : '' }}
            @endif
        </div>
        @if ($sal->status === 'bozza')
            <div class="bozza">BOZZA — documento non validato</div>
        @endif
    </div>

    <h2>Lavori rendicontati</h2>
    <table>
        <tr>
            <th style="width: 42%;">Lavoro</th>
            <th style="width: 12%;" class="num">Quantità</th>
            <th style="width: 8%;">Unità</th>
            <th style="width: 13%;" class="num">Prezzo unit. (euro)</th>
            <th style="width: 15%;" class="num">Imponibile (euro)</th>
            <th style="width: 10%;" class="num">IVA %</th>
        </tr>
        @foreach ($sal->items as $riga)
            <tr>
                <td>{{ $riga->descrizione }}@if ($riga->nota)<div class="nota-riga">{{ $riga->nota }}</div>@endif</td>
                <td class="num">{{ $riga->quantity !== null ? $num($riga->quantity) : '—' }}</td>
                <td>{{ $riga->unit ?? '—' }}</td>
                <td class="num">{{ $riga->unit_price !== null ? $eur($riga->unit_price) : '—' }}</td>
                <td class="num">{{ $eur($riga->imponibile) }}</td>
                <td class="num">{{ $pct($riga->vat_rate) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Riepilogo</h2>
    <table>
        <tr><th style="width: 70%;">Voce</th><th style="width: 30%;" class="num">Euro</th></tr>
        <tr><td>Imponibile dei lavori</td><td class="num">{{ $eur($totali['imponibile_righe']) }}</td></tr>
        @if ($totali['spese_generali'] > 0)
            <tr><td>Spese generali ({{ $pct($sal->overhead_pct) }}%)</td><td class="num">{{ $eur($totali['spese_generali']) }}</td></tr>
            <tr><td>Imponibile totale</td><td class="num">{{ $eur($totali['imponibile']) }}</td></tr>
        @endif
        @foreach ($totali['per_aliquota'] as $voce)
            <tr><td>IVA {{ $pct($voce['aliquota']) }}% (su {{ $eur($voce['imponibile']) }})</td><td class="num">{{ $eur($voce['iva']) }}</td></tr>
        @endforeach
        <tr class="totale"><td>Totale documento</td><td class="num">{{ $eur($totali['totale']) }}</td></tr>
    </table>

    @if ($sal->notes)
        <h2>Note</h2>
        <p>{{ $sal->notes }}</p>
    @endif

    <div class="nota">
        Documento redatto sui lavori completati nel periodo indicato, con quantità
        consuntivate e prezzi del listino applicato a ciascun ordine. L'IVA è
        applicata riga per riga{{ $totali['spese_generali'] > 0 ? '; le spese generali sono ripartite sulle aliquote in proporzione agli imponibili' : '' }}.
        Il presente SAL non costituisce fattura.
    </div>

    <div class="firma">
        {{ $luogoData }}
        <div class="linea">Firma</div>
    </div>
</body>
</html>
