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
    tr.totale td { background: #f4f4f4; font-weight: bold; }
    .nota { margin-top: 12px; font-size: 8.5px; color: #555; }
    .nota li { margin-bottom: 2px; }
    .didascalia { font-size: 8.5px; color: #333; margin-top: 2px; }
</style>
</head>
<body>
    @php
        $loc = $scheda['localita'];
        $sup = $scheda['superfici'];
        $mq = fn ($v) => number_format((float) $v, 2, ',', '.');
        $n = fn ($v) => number_format((int) $v, 0, ',', '.');
        // I timestamp sono in UTC: senza il fuso, un documento caricato dopo
        // mezzanotte uscirebbe col giorno prima accanto alla data di stampa
        $data = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone('Europe/Rome')->format('d/m/Y') : '—';
        $elementi = array_sum(array_map(fn ($r) => (int) $r->quanti, $scheda['per_tipo']));
    @endphp

    <div class="head">
        <h1>Scheda della località — {{ $loc['name'] }}@if ($loc['code']) ({{ $loc['code'] }})@endif</h1>
        <div class="muted">
            @if ($loc['committente']){{ $loc['committente']['name'] }}@endif
            @if ($loc['sede']) — sede {{ $loc['sede']['name'] }}@endif
            @if ($comune) — {{ $comune }}@endif
            <br>
            Classificazione ISTAT del verde urbano: {{ $loc['istat_class_label'] ?? 'non classificata' }}
            <br>
            @if ($loc['survey_zone_code'])
                Zona di rilievo: {{ $loc['survey_zone_code'] }}
                <br>
            @endif
            Redatto da {{ $organization?->name }} — stampato il {{ $stampatoIl->format('d/m/Y') }}
        </div>
    </div>

    <h2>Superfici e consistenza</h2>
    <table>
        <tr>
            <th style="width: 70%;">Voce</th>
            <th style="width: 30%;" class="num">Valore</th>
        </tr>
        <tr>
            <td>Superficie totale ({{ $sup['totale_da_perimetro'] ? 'dal perimetro disegnato della località' : 'somma delle aree interne' }})</td>
            <td class="num">{{ $mq($sup['totale_mq']) }} m²</td>
        </tr>
        <tr>
            <td>Superficie gestita (somma delle aree attive)</td>
            <td class="num">{{ $mq($sup['gestita_mq']) }} m²</td>
        </tr>
        <tr>
            <td>Aree ({{ $n($sup['aree_attive']) }} {{ (int) $sup['aree_attive'] === 1 ? 'attiva' : 'attive' }})</td>
            <td class="num">{{ $n($sup['aree']) }}</td>
        </tr>
        <tr class="totale">
            <td>Elementi censiti</td>
            <td class="num">{{ $n($elementi) }}</td>
        </tr>
    </table>

    @if (in_array('planimetrie', $sezioni) && $planimetrie !== null)
    <h2>Planimetrie delle aree</h2>
    @if ($planimetrie['quadro'])
        <img src="data:image/png;base64,{{ $planimetrie['quadro']['png'] }}" style="width: 100%; margin-top: 4px; border: 1px solid #bbb;">
        <div class="didascalia">Quadro d'insieme — le aree della località, numerate come le planimetrie che seguono. Nord in alto.</div>
    @endif
    @foreach ($planimetrie['aree'] as $tavola)
        <img src="data:image/png;base64,{{ $tavola['png'] }}" style="width: 100%; margin-top: 10px; border: 1px solid #bbb;">
        <div class="didascalia">
            Area {{ $tavola['numero'] }} — {{ $tavola['nome'] }}@if ($tavola['codice']) ({{ $tavola['codice'] }})@endif{{ $tavola['stato'] !== 'active' ? ' — non attiva' : '' }}
            · {{ number_format($tavola['mq'], 2, ',', '.') }} m² · {{ $tavola['conteggi'] }}{{ $tavola['etichette'] ? '' : ' · etichette omesse per leggibilità' }}
            @if (($tavola['fuori_inquadratura'] ?? 0) > 0)
                · {{ $tavola['fuori_inquadratura'] }} {{ $tavola['fuori_inquadratura'] === 1 ? 'elemento con coordinate lontane' : 'elementi con coordinate lontane' }}
                dall'area, fuori dall'inquadratura: da verificare nel censimento
            @endif
        </div>
    @endforeach
    @if (($planimetrie['omesse'] ?? 0) > 0)
        <div class="didascalia" style="margin-top: 6px;">
            Le tavole si fermano alle prime {{ count($planimetrie['aree']) }} aree:
            {{ $planimetrie['omesse'] }} {{ $planimetrie['omesse'] === 1 ? 'area resta' : 'aree restano' }}
            senza planimetria (i conteggi della scheda le comprendono comunque).
        </div>
    @endif
    <div class="didascalia" style="margin-top: 6px;">
        Legenda: perimetro dell'area in verde scuro; alberi con chioma sagomata (in scala quando il
        diametro è misurato, simbolo convenzionale più tenue quando non lo è) e punto del fusto;
        superfici campite; elementi lineari in verde oliva; il numero sotto l'elemento è il codice del censimento.
        @if ($planimetrie['sfondo_usato'])
            {{ $planimetrie['attribuzione'] }}.
        @endif
    </div>
    @endif

    @if (in_array('tipi', $sezioni))
    <h2>Elementi censiti per tipo</h2>
    @if (count($scheda['per_tipo']))
        <table>
            <tr>
                <th style="width: 18%;">Codice</th>
                <th style="width: 62%;">Tipo di oggetto</th>
                <th style="width: 20%;" class="num">Quanti</th>
            </tr>
            @foreach ($scheda['per_tipo'] as $riga)
                <tr>
                    <td>{{ $riga->code }}</td>
                    <td>{{ $riga->name }}</td>
                    <td class="num">{{ $n($riga->quanti) }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nessun elemento censito in questa località.</p>
    @endif
    @endif

    @if (in_array('piante', $sezioni))
    <h2>Piante presenti</h2>
    @if (count($scheda['piante']))
        <table>
            <tr>
                <th style="width: 45%;">Nome scientifico</th>
                <th style="width: 35%;">Nome comune</th>
                <th style="width: 20%;" class="num">Quante</th>
            </tr>
            @foreach ($scheda['piante'] as $pianta)
                <tr>
                    <td>{{ $pianta->scientifico }}</td>
                    <td>{{ $pianta->comune ?? '—' }}</td>
                    <td class="num">{{ $n($pianta->quanti) }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nessuna pianta viva censita in questa località.</p>
    @endif
    @endif

    @if (in_array('imprese', $sezioni))
    <h2>Chi ci lavora</h2>
    @if (count($scheda['imprese']))
        <table>
            <tr>
                <th style="width: 70%;">Squadra o responsabile</th>
                <th style="width: 30%;" class="num">Ordini di lavoro</th>
            </tr>
            @foreach ($scheda['imprese'] as $impresa)
                <tr>
                    <td>{{ $impresa->nome }}</td>
                    <td class="num">{{ $n($impresa->lavori) }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nessun lavoro assegnato.</p>
    @endif
    @endif

    @if (in_array('lavori', $sezioni))
    <h2>Lavori recenti</h2>
    @if (count($scheda['lavori']))
        <table>
            <tr>
                <th style="width: 18%;">Codice</th>
                <th style="width: 42%;">Titolo</th>
                <th style="width: 16%;">Stato</th>
                <th style="width: 24%;">Periodo previsto</th>
            </tr>
            @foreach ($scheda['lavori'] as $lavoro)
                <tr>
                    <td>{{ $lavoro->code }}</td>
                    <td>{{ $lavoro->title }}</td>
                    <td>{{ $statiLavoro[$lavoro->status] ?? $lavoro->status }}</td>
                    <td>@if ($lavoro->planned_start){{ $data($lavoro->planned_start) }}@if ($lavoro->planned_end) – {{ $data($lavoro->planned_end) }}@endif @elseif ($lavoro->planned_end)fino al {{ $data($lavoro->planned_end) }}@else — @endif</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nessun lavoro registrato in questa località.</p>
    @endif
    @endif

    @if (in_array('documenti', $sezioni))
    <h2>Documenti allegati</h2>
    @if (count($scheda['documenti']))
        <table>
            <tr>
                <th style="width: 70%;">Titolo</th>
                <th style="width: 30%;">Caricato il</th>
            </tr>
            @foreach ($scheda['documenti'] as $documento)
                <tr>
                    <td>{{ $documento->title }}</td>
                    <td>{{ $data($documento->created_at) }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nessun documento allegato (per esempio il piano di gestione).</p>
    @endif
    @endif

    <div class="nota">
        <strong>Nota sul metodo</strong>
        <ul>
            <li>La superficie totale è quella del perimetro disegnato della località;
                se il perimetro non è stato disegnato, è la somma delle superfici
                delle aree interne (che possono lasciare vuoti o sovrapporsi).</li>
            <li>La superficie gestita è la somma delle sole aree attive: è quella
                che conta per i lavori e per il corrispettivo.</li>
            @if (in_array('planimetrie', $sezioni) && $planimetrie !== null)
            <li>Le planimetrie sono disegnate dalle geometrie registrate sulla mappa
                alla data di stampa; nord in alto, barra della scala su ogni tavola.
                @if ($planimetrie['sfondo_usato'])
                    Lo sfondo cartografico è quello disponibile al momento della stampa.
                @else
                    Senza collegamento alla rete lo sfondo cartografico si omette e resta
                    il disegno tecnico.
                @endif
            </li>
            @endif
            @if (in_array('piante', $sezioni))
            <li>Le piante contano gli alberi censiti non abbattuti, raggruppati per
                nome scientifico (specie o, in mancanza, genere).</li>
            @endif
            @if (in_array('lavori', $sezioni) || in_array('imprese', $sezioni))
            <li>L'elenco dei lavori riporta i 20 più recenti; il conteggio per
                squadra considera tutti gli ordini non annullati.</li>
            @endif
            <li>La scheda riflette i dati registrati alla data di stampa.</li>
        </ul>
    </div>
</body>
</html>
