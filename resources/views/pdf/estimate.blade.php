<!DOCTYPE html>
{{-- Preventivo da mandare al cliente: intestazione con i recapiti, blocco
     destinatario, voci numerate, riquadro dei totali e spazio per la firma
     di accettazione. Carattere DejaVu Sans Mono come le altre stampe. --}}
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26px 26px 46px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 0; }
    h1 { font-size: 15px; margin: 0; letter-spacing: 0.5px; }
    h2 { font-size: 10.5px; margin: 16px 0 4px; text-transform: uppercase; letter-spacing: 0.8px;
         color: #444; border-bottom: 1px solid #999; padding-bottom: 2px; }
    .muted { color: #555; }
    .small { font-size: 8.5px; }

    /* Intestazione: mittente a sinistra, estremi del documento a destra */
    .head { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    .head td { border: none; padding: 0; vertical-align: top; }
    .mittente { font-size: 9.5px; line-height: 1.5; }
    .mittente strong { font-size: 12px; }
    .doc { border: 2px solid #111; padding: 6px 9px; }
    .doc .riga { font-size: 9px; }
    .doc .riga span { color: #555; }

    .bozza { border: 1px dashed #b45309; color: #92400e; padding: 5px 8px; margin: 10px 0;
             font-size: 9.5px; text-align: center; letter-spacing: 1px; }

    /* Destinatario e oggetto */
    .dest { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .dest td { border: none; padding: 0; vertical-align: top; }
    .dest .box { border: 1px solid #bbb; padding: 6px 8px; line-height: 1.5; }
    .dest .box .eti { color: #555; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.6px; }
    .dest .box strong { font-size: 11px; }

    table.voci { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    table.voci th, table.voci td { border: 1px solid #bbb; padding: 4px 6px; text-align: left;
                                   vertical-align: top; font-size: 9.5px; word-wrap: break-word; }
    table.voci th { background: #ececec; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.5px; }
    table.voci tr.alt td { background: #fafafa; }
    /* La regola generica su td perderebbe: qui serve la stessa specificita' */
    table.voci th.num, table.voci td.num, .totali td.num { text-align: right; }
    table.voci th.n, table.voci td.n { text-align: center; color: #555; }

    /* Totali: riquadro a destra, il totale in evidenza */
    .totali { width: 46%; border-collapse: collapse; margin: 8px 0 0 54%; }
    .totali td { border: 1px solid #bbb; padding: 4px 7px; font-size: 9.5px; }
    .totali td.eti { background: #fafafa; color: #333; }
    .totali tr.tot td { border: 2px solid #111; background: #ececec; font-weight: bold; font-size: 11px; }

    .note { line-height: 1.5; white-space: pre-line; }
    .cond { font-size: 8.5px; color: #444; line-height: 1.45; margin-top: 10px; }
    .firma { margin-top: 22px; width: 100%; page-break-inside: avoid; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; vertical-align: bottom; }
    .firma .spazio { height: 52px; }
    .firma .linea { border-top: 1px solid #111; padding-top: 4px; text-align: center; color: #444; font-size: 8.5px; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #666;
              border-top: 1px solid #ccc; padding-top: 3px; }
    .footer .pag { float: right; }
    .footer .pag:after { content: "Pagina " counter(page); }
</style>
</head>
<body>
    <div class="footer">
        <span class="pag"></span>
        Preventivo {{ $estimate->code }} - {{ $organization?->name }}
    </div>

    <table class="head">
        <tr>
            <td style="width: 58%;" class="mittente">
                <strong>{{ $organization?->name }}</strong><br>
                @if ($organization?->vat_number) P. IVA {{ $organization->vat_number }}<br> @endif
                @if ($recapiti) {{ $recapiti }} @endif
            </td>
            <td style="width: 42%;">
                <div class="doc">
                    <h1>PREVENTIVO</h1>
                    <div class="riga"><span>Numero:</span> {{ $estimate->code }}</div>
                    <div class="riga"><span>Data:</span> {{ $estimate->created_at?->timezone('Europe/Rome')->format('d/m/Y') }}</div>
                    <div class="riga">
                        <span>Validità:</span>
                        {{ $estimate->valid_until ? 'fino al '.$estimate->valid_until->format('d/m/Y') : 'da concordare' }}
                    </div>
                </div>
            </td>
        </tr>
    </table>

    @if ($estimate->status === 'draft')
    <div class="bozza">BOZZA - documento non ancora inviato al cliente</div>
    @endif

    <table class="dest">
        <tr>
            <td style="width: 49%; padding-right: 2%;">
                <div class="box">
                    <div class="eti">Spettabile</div>
                    <strong>{{ $estimate->client?->name }}</strong><br>
                    @foreach ($indirizzoCliente as $riga){{ $riga }}<br>@endforeach
                    @if ($estimate->client?->vat_number) P. IVA {{ $estimate->client->vat_number }}<br> @endif
                    @if ($estimate->client?->fiscal_code) C.F. {{ $estimate->client->fiscal_code }} @endif
                </div>
            </td>
            <td style="width: 49%;">
                <div class="box">
                    <div class="eti">Oggetto</div>
                    <strong>{{ $estimate->title }}</strong><br>
                    @if ($estimate->area)
                        Luogo: {{ $estimate->area->name }}@if ($estimate->area->locality) - {{ $estimate->area->locality->name }}@endif
                    @else
                        <span class="muted">Luogo da definire</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <h2>Voci di preventivo</h2>
    <table class="voci">
        <tr>
            <th style="width: 5%;" class="n">N.</th>
            <th style="width: 41%;">Descrizione</th>
            <th style="width: 10%;">Unità</th>
            <th style="width: 12%;" class="num">Quantità</th>
            <th style="width: 15%;" class="num">Prezzo unitario</th>
            <th style="width: 17%;" class="num">Importo</th>
        </tr>
        @foreach ($estimate->items as $i => $item)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">
            <td class="n">{{ $i + 1 }}</td>
            <td>
                {{ $item->description }}
                @if ($item->workType)<br><span class="small muted">{{ $item->workType->name }}</span>@endif
            </td>
            <td>{{ $item->unit }}</td>
            <td class="num">{{ number_format((float) $item->quantity, 2, ',', '.') }}</td>
            <td class="num">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
            <td class="num">{{ number_format((float) $item->quantity * (float) $item->unit_price, 2, ',', '.') }}</td>
        </tr>
        @endforeach
        @if ($estimate->items->isEmpty())
        <tr><td colspan="6" class="muted">Nessuna voce inserita.</td></tr>
        @endif
    </table>

    <table class="totali">
        <tr>
            <td class="eti">Imponibile</td>
            <td class="num">{{ number_format($subtotal, 2, ',', '.') }} EUR</td>
        </tr>
        <tr>
            <td class="eti">IVA {{ rtrim(rtrim(number_format((float) $estimate->vat_percent, 2, ',', '.'), '0'), ',') }}%</td>
            <td class="num">{{ number_format($vat, 2, ',', '.') }} EUR</td>
        </tr>
        <tr class="tot">
            <td>Totale</td>
            <td class="num">{{ number_format($total, 2, ',', '.') }} EUR</td>
        </tr>
    </table>

    @if ($estimate->notes)
    <h2>Note e condizioni</h2>
    <p class="note">{{ $estimate->notes }}</p>
    @endif

    <p class="cond">
        Prezzi in euro, IVA esposta a parte come sopra indicato. L'offerta si intende valida
        @if ($estimate->valid_until) fino al {{ $estimate->valid_until->format('d/m/Y') }} @else per il periodo concordato @endif
        e i lavori si intendono eseguiti a regola d'arte, nel rispetto delle norme di sicurezza
        vigenti. Eventuali opere non descritte nelle voci sopra elencate saranno oggetto di
        preventivo separato. Il presente documento non costituisce fattura.
    </p>

    <table class="firma">
        <tr>
            <td style="width: 50%;"></td>
            {{-- Luogo e data dell'offerta, sopra la firma dell'impresa --}}
            <td style="width: 50%;">{{ $luogoData }}</td>
        </tr>
        <tr>
            <td class="spazio">&nbsp;</td>
            <td class="spazio">&nbsp;</td>
        </tr>
        <tr>
            <td class="linea">Per accettazione: data e firma del committente</td>
            <td class="linea">{{ $organization?->name }}</td>
        </tr>
    </table>
</body>
</html>
