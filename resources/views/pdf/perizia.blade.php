<!DOCTYPE html>
{{-- Perizia di valutazione della stabilità (metodo VTA), approvata dal
     committente il 14/08/2026: identificazione, contesto, dendrometrici,
     giudizio, difetti per parte, analisi strumentali, classe FRC,
     prescrizioni, foto, inquadramento cartografico e conclusioni. --}}
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 14px 0 5px; border-bottom: 1px solid #999; padding-bottom: 2px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    .prof { font-size: 9.5px; line-height: 1.5; }
    .prof strong { font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 3.5px 6px; text-align: left; vertical-align: top; font-size: 9.5px; word-wrap: break-word; }
    th { background: #eee; width: 32%; }
    table.cols th { width: auto; }
    .classe { border: 2px solid #111; padding: 8px 10px; margin-top: 8px; }
    .classe .lettera { font-size: 22px; font-weight: bold; }
    .legenda { font-size: 8.5px; color: #444; margin-top: 4px; line-height: 1.4; }
    .firma { margin-top: 26px; width: 100%; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; }
    .firma .linea { border-top: 1px solid #111; width: 220px; padding-top: 4px; text-align: center; }
    .foto-box { width: 46%; height: 165px; border: 1px solid #999; display: inline-block; margin: 0 1.5% 8px 0; text-align: center; color: #999; vertical-align: top; }
    .foto-box img { max-width: 100%; max-height: 163px; }
    .strum-esito { font-size: 9px; color: #333; margin-top: 2px; }
    .footer { position: fixed; bottom: 8px; left: 24px; right: 24px; font-size: 8px; color: #666; border-top: 1px solid #ccc; padding-top: 3px; }
    .page-break { page-break-before: always; }
</style>
</head>
<body>
    <div class="footer">
        Perizia di stabilità {{ $riferimento }} - {{ $asset->census_code }} -
        emessa il {{ $emessaIl->format('d/m/Y') }}
    </div>

    <div class="head">
        <table style="border: none;">
            <tr>
                <td style="border: none; padding: 0; width: 60%;" class="prof">
                    <strong>{{ $professionista['nome'] }}</strong><br>
                    {{ $professionista['titolo'] }}<br>
                    @if ($professionista['iscrizione']) {{ $professionista['iscrizione'] }}<br> @endif
                    @if ($professionista['recapiti']) {{ $professionista['recapiti'] }} @endif
                </td>
                <td style="border: none; padding: 0; text-align: right;" class="muted">
                    Perizia n. {{ $riferimento }}<br>
                    Emessa il {{ $emessaIl->format('d/m/Y') }}
                </td>
            </tr>
        </table>
        <h1 style="margin-top: 10px;">Perizia di valutazione della stabilità (metodo VTA)</h1>
        <div class="muted">Valutazione visiva e strumentale della propensione al cedimento</div>
    </div>

    <h2>1. Identificazione dell'esemplare</h2>
    <table>
        <tr><th>Codice censimento</th><td>{{ $asset->census_code ?? '-' }}</td></tr>
        <tr><th>Specie</th><td>{{ $tree->species ?: $tree->genus }}@if ($tree->common_name) ({{ $tree->common_name }})@endif</td></tr>
        <tr>
            <th>Ubicazione</th>
            <td>
                {{ $asset->area?->name }}
                @if ($asset->area?->locality) - {{ $asset->area->locality->name }}@endif
                @if ($asset->area?->locality?->site) - {{ $asset->area->locality->site->name }}@endif
            </td>
        </tr>
        <tr><th>Coordinate (WGS84)</th><td>{{ $coordinate }}</td></tr>
        <tr><th>Committente</th><td>{{ $committente ?? '-' }}</td></tr>
        <tr><th>Data del sopralluogo</th><td>{{ $assessment->assessed_on?->format('d/m/Y') ?? '-' }}</td></tr>
        @if ($tree->is_monumental || $tree->is_protected)
        <tr><th>Vincoli</th><td>
            @if ($tree->is_monumental) Albero monumentale @if ($tree->monumental_ref)({{ $tree->monumental_ref }})@endif @endif
            @if ($tree->is_protected) Esemplare tutelato @if ($tree->protection_ref)({{ $tree->protection_ref }})@endif @endif
        </td></tr>
        @endif
    </table>

    <h2>2. Inquadramento del contesto</h2>
    <table class="cols">
        <tr><th>Ambito</th><th>Sito di radicazione</th><th>Disposizione</th><th>Accessibilità</th></tr>
        <tr>
            <td>{{ $contesto['ambito'] ?? '-' }}</td>
            <td>{{ $contesto['sito_radicazione'] ?? '-' }}</td>
            <td>{{ $contesto['disposizione'] ?? '-' }}</td>
            <td>{{ $contesto['accessibilita'] ?? '-' }}</td>
        </tr>
    </table>
    <table>
        <tr><th>Bersagli presenti</th><td>{{ $bersagli ?: '-' }}</td></tr>
        <tr><th>Interferenze</th><td>{{ $interferenze ?: 'Nessuna rilevata' }}</td></tr>
    </table>

    <h2>3. Dati dendrometrici</h2>
    <table class="cols">
        <tr>
            <th>Altezza (m)</th><th>Diametro a 1,30 m (cm)</th><th>Circonferenza (cm)</th>
            <th>Diametro chioma (m)</th><th>Altezza primo palco (m)</th><th>Classe di età</th>
        </tr>
        <tr>
            <td>{{ $tree->height_m ?? '-' }}</td>
            <td>{{ $tree->dbh_cm ?? '-' }}</td>
            <td>{{ $tree->trunk_circumference_cm ?? '-' }}</td>
            <td>{{ $tree->crown_diameter_m ?? '-' }}</td>
            <td>{{ $tree->crown_insertion_m ?? '-' }}</td>
            <td>{{ $tree->age_class ?? '-' }}</td>
        </tr>
    </table>

    <h2>4. Giudizio complessivo sull'esemplare</h2>
    <table class="cols">
        <tr><th>Fase fisiologica</th><th>Stato vegetativo</th><th>Giudizio sintetico</th></tr>
        <tr>
            <td>{{ $giudizio['fase_fisiologica'] ?? '-' }}</td>
            <td>{{ $giudizio['stato_vegetativo'] ?? '-' }}</td>
            <td>{{ $giudizio['sintetico'] ?? '-' }}</td>
        </tr>
    </table>
    <table>
        <tr><th>Patologie da quarantena</th><td>{{ ($giudizio['patologie_quarantena'] ?? '') ?: 'Non rilevate' }}</td></tr>
    </table>

    <h2>5. Analisi visiva: difetti riscontrati per parte</h2>
    <table>
        <tr><th>Rilevamenti</th><td>{{ $difetti['Rilevamenti'] ?? 'Nessuno' }}</td></tr>
        @foreach (['Radici', 'Colletto', 'Fusto', 'Castello', 'Branche', 'Chioma e foglie'] as $parte)
        <tr><th>{{ $parte }}</th><td>{{ $difetti[$parte] ?? 'Nessun difetto significativo riscontrato' }}</td></tr>
        @endforeach
    </table>

    @if ($analisi->isNotEmpty())
    <h2>6. Analisi strumentali</h2>
    @foreach ($analisi as $a)
    <table class="cols">
        <tr>
            <th style="width: 40%;">{{ $a->label }}@if ($a->strumento) - {{ $a->strumento }}@endif</th>
            <th>Data: {{ $a->measured_at?->format('d/m/Y') ?? '-' }}</th>
            <th>Altezza di misura: {{ $a->altezza !== null ? $a->altezza.' cm' : '-' }}</th>
        </tr>
    </table>
    @if (! empty($a->rows))
    <table class="cols">
        <tr>@foreach (array_keys($a->rows[0]) as $col)<th>{{ $col }}</th>@endforeach</tr>
        @foreach ($a->rows as $row)
        <tr>@foreach ($row as $value)<td>{{ $value }}</td>@endforeach</tr>
        @endforeach
    </table>
    @endif
    @if ($a->summary_text)<div class="strum-esito">{{ $a->summary_text }}</div>@endif
    @endforeach
    @endif

    <h2>7. Classe di propensione al cedimento</h2>
    <div class="classe">
        <span class="lettera">{{ $assessment->failure_class }}</span> -
        {{ $classiDescrizione[$assessment->failure_class] ?? '' }}
    </div>
    <div class="legenda">
        Classi FRC: A trascurabile - B bassa - C moderata (monitoraggio e/o interventi) -
        C/D elevata (interventi prioritari; se non attuabili, abbattimento) - D estrema (abbattimento).
    </div>
    <table>
        <tr><th>Integrazione o completamento VTA</th><td>{{ $integrazioneVta ?: 'Nessuna: la valutazione si ritiene completa' }}</td></tr>
    </table>

    <h2>8. Prescrizioni e periodicità di ricontrollo</h2>
    <table>
        <tr><th>Interventi prescritti</th><td>{{ $assessment->prescriptions ?: 'Nessuno' }}</td></tr>
        <tr><th>Priorità</th><td>{{ $prioritaIntervento ?? '-' }}</td></tr>
        <tr><th>Prossimo controllo entro</th><td>{{ $assessment->next_check_due?->format('d/m/Y') ?? '-' }}</td></tr>
    </table>

    <div class="page-break"></div>

    <h2>9. Documentazione fotografica</h2>
    @for ($i = 0; $i < 4; $i++)
        @if (isset($fotoDataUri[$i]))
            <div class="foto-box"><img src="{{ $fotoDataUri[$i] }}" alt="Foto {{ $i + 1 }}"></div>
        @else
            <div class="foto-box" style="line-height: 165px;">FOTO {{ $i + 1 }} NON DISPONIBILE</div>
        @endif
    @endfor

    <h2>10. Inquadramento cartografico</h2>
    @if ($mappaDataUri)
        <div class="foto-box" style="width: 94%; height: 220px;"><img src="{{ $mappaDataUri }}" style="max-height: 218px;" alt="Mappa"></div>
        <div class="legenda">Estratto cartografico OpenStreetMap con la posizione dell'esemplare. Dati (c) OpenStreetMap contributors.</div>
    @else
        <div class="foto-box" style="width: 94%; height: 120px; line-height: 120px;">ESTRATTO DI MAPPA CON LA POSIZIONE DELL'ESEMPLARE</div>
    @endif

    <h2>11. Conclusioni</h2>
    <p style="line-height: 1.5;">{{ $conclusioni }}</p>

    <table class="firma">
        <tr>
            <td style="width: 55%;">Luogo e data: ____________________________</td>
            <td class="linea">Il tecnico<br>{{ $professionista['nome'] }}</td>
        </tr>
    </table>
    <p class="muted" style="font-size: 8.5px; margin-top: 12px;">
        La presente valutazione fotografa le condizioni dell'esemplare alla data del sopralluogo
        con il metodo VTA (Visual Tree Assessment). La validità è subordinata al rispetto delle
        prescrizioni e della periodicità di ricontrollo indicate; eventi meteorici eccezionali
        o danni sopravvenuti impongono una nuova valutazione.
    </p>
</body>
</html>
