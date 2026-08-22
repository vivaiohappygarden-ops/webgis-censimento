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
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 3px 5px; text-align: left; vertical-align: top; font-size: 9px; word-wrap: break-word; }
    th { background: #eee; }
    td.num { text-align: right; }
    tr.saldo td { background: #f4f4f4; font-weight: bold; }
    .firma { margin-top: 26px; width: 100%; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; }
    .firma .linea { border-top: 1px solid #111; width: 220px; padding-top: 4px; text-align: center; }
    .nota { margin-top: 12px; font-size: 8.5px; color: #555; }
    .nota li { margin-bottom: 2px; }
</style>
</head>
<body>
    @php
        $periodo = \Illuminate\Support\Carbon::parse($bilancio['from'])->format('d/m/Y')
            .' - '.\Illuminate\Support\Carbon::parse($bilancio['to'])->format('d/m/Y');
        $variazione = $bilancio['variation'];
        $posti = $bilancio['planting_sites'];
    @endphp

    <div class="head">
        <h1>Bilancio arboreo{{ $client ? ' - '.$client->name : '' }}</h1>
        <div class="muted">
            Periodo {{ $periodo }}
            @if (! $client) - tutti i committenti @endif
            <br>
            Legge 14 gennaio 2013, n. 10 - Norme per lo sviluppo degli spazi verdi urbani
            <br>
            Redatto da {{ $organization?->name }} - stampato il {{ now('Europe/Rome')->format('d/m/Y') }}
        </div>
    </div>

    <h2>Consistenza del patrimonio arboreo</h2>
    <table>
        <tr>
            <th style="width: 70%;">Voce</th>
            <th style="width: 30%;" class="num">Alberi</th>
        </tr>
        <tr>
            <td>Consistenza all'inizio del periodo ({{ \Illuminate\Support\Carbon::parse($bilancio['from'])->format('d/m/Y') }})</td>
            <td class="num">{{ number_format($bilancio['initial_count'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Nuovi impianti nel periodo</td>
            <td class="num">{{ $bilancio['planted_count'] > 0 ? '+' : '' }}{{ number_format($bilancio['planted_count'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Abbattimenti e rimozioni nel periodo</td>
            <td class="num">{{ $bilancio['felled_count'] > 0 ? '-' : '' }}{{ number_format($bilancio['felled_count'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Consistenza al termine del periodo ({{ \Illuminate\Support\Carbon::parse($bilancio['to'])->format('d/m/Y') }})</td>
            <td class="num">{{ number_format($bilancio['final_count'], 0, ',', '.') }}</td>
        </tr>
        <tr class="saldo">
            <td>Variazione del periodo</td>
            <td class="num">{{ $variazione > 0 ? '+' : '' }}{{ number_format($variazione, 0, ',', '.') }}</td>
        </tr>
    </table>

    <h2>Dettaglio per specie</h2>
    @if (count($bilancio['by_species']))
        <table>
            <tr>
                <th style="width: 55%;">Specie</th>
                <th style="width: 15%;" class="num">Impiantati</th>
                <th style="width: 15%;" class="num">Abbattuti</th>
                <th style="width: 15%;" class="num">Saldo</th>
            </tr>
            @foreach ($bilancio['by_species'] as $riga)
                @php $saldo = (int) $riga->planted - (int) $riga->felled; @endphp
                <tr>
                    <td>{{ $riga->species_label }}</td>
                    <td class="num">{{ (int) $riga->planted }}</td>
                    <td class="num">{{ (int) $riga->felled }}</td>
                    <td class="num">{{ $saldo > 0 ? '+' : '' }}{{ $saldo }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nessun impianto ne' abbattimento registrato nel periodo.</p>
    @endif

    <h2>Posti d'impianto (situazione alla data di stampa)</h2>
    <table>
        <tr>
            <th style="width: 70%;">Stato</th>
            <th style="width: 30%;" class="num">Numero</th>
        </tr>
        <tr><td>Liberi</td><td class="num">{{ $posti['free'] }}</td></tr>
        <tr><td>Riservati</td><td class="num">{{ $posti['reserved'] }}</td></tr>
        <tr><td>Piantumati</td><td class="num">{{ $posti['planted'] }}</td></tr>
        <tr><td>Non utilizzabili</td><td class="num">{{ $posti['unusable'] }}</td></tr>
        <tr class="saldo">
            <td>Di cui reimpianti a seguito di abbattimento</td>
            <td class="num">{{ $posti['replacements_from_felling'] }}</td>
        </tr>
    </table>

    <div class="nota">
        <strong>Nota sul metodo di calcolo</strong>
        <ul>
            <li>Un albero e' considerato presente dalla data di messa a dimora; dove
                questa non e' nota si assume la data di censimento.</li>
            <li>Un albero cessa di essere presente alla data di abbattimento registrata.</li>
            <li>La consistenza iniziale conta gli alberi gia' presenti prima dell'inizio
                del periodo e non ancora abbattuti a quella data.</li>
            <li>Il dettaglio per specie riporta le prime 50 specie per numero di
                movimenti nel periodo.</li>
            <li>I posti d'impianto sono una fotografia alla data di stampa e non sono
                ricostruiti alla data di fine periodo.</li>
            <li>Il bilancio riflette i dati registrati nel censimento alla data di
                stampa: interventi non ancora inseriti non compaiono.</li>
        </ul>
    </div>

    <table class="firma">
        <tr>
            <td style="width: 50%;">Data ____ / ____ / __________</td>
            <td style="width: 50%;">Il tecnico incaricato</td>
        </tr>
        <tr>
            <td></td>
            <td><div class="linea">timbro e firma</div></td>
        </tr>
    </table>
</body>
</html>
