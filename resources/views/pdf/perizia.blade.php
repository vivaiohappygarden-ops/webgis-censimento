<!DOCTYPE html>
{{-- BOZZA in attesa di approvazione: layout della perizia di valutazione di
     stabilità. Nessuna rotta la usa ancora: la funzione si attiva dopo
     l'approvazione del committente. --}}
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 24px; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 16px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px; }
    .muted { color: #555; }
    .head { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
    .prof { font-size: 9.5px; line-height: 1.5; }
    .prof strong { font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; font-size: 9.5px; word-wrap: break-word; }
    th { background: #eee; width: 32%; }
    table.cols th { width: auto; }
    .classe { border: 2px solid #111; padding: 8px 10px; margin-top: 8px; }
    .classe .lettera { font-size: 22px; font-weight: bold; }
    .legenda { font-size: 8.5px; color: #444; margin-top: 4px; line-height: 1.4; }
    .firma { margin-top: 28px; width: 100%; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; }
    .firma .linea { border-top: 1px solid #111; width: 220px; padding-top: 4px; text-align: center; }
    .foto-box { width: 46%; height: 170px; border: 1px solid #999; display: inline-block; margin-right: 2%; text-align: center; color: #999; vertical-align: top; }
    .foto-box img { max-width: 100%; max-height: 168px; }
    .footer { position: fixed; bottom: 8px; left: 24px; right: 24px; font-size: 8px; color: #666; border-top: 1px solid #ccc; padding-top: 3px; }
    .page-break { page-break-before: always; }
</style>
</head>
<body>
    <div class="footer">
        Perizia di stabilità {{ $riferimento }} - {{ $asset->census_code }} -
        emessa il {{ $emessaIl->format('d/m/Y') }} - pagina <span class="pagenum"></span>
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

    <h2>2. Dati dendrometrici</h2>
    <table class="cols">
        <tr>
            <th>Altezza (m)</th><th>Diametro a 1,30 m (cm)</th><th>Circonferenza (cm)</th>
            <th>Diametro chioma (m)</th><th>Inserzione chioma (m)</th><th>Classe di età</th>
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

    <h2>3. Analisi visiva: difetti riscontrati</h2>
    <table>
        @forelse ($difetti as $parte => $elenco)
        <tr><th>{{ $parte }}</th><td>{{ $elenco ?: 'Nessun difetto significativo riscontrato' }}</td></tr>
        @empty
        <tr><td colspan="2">Nessun difetto significativo riscontrato.</td></tr>
        @endforelse
    </table>

    <h2>4. Bersagli e contesto</h2>
    <table>
        <tr><th>Bersagli presenti</th><td>{{ $bersagli ?: '-' }}</td></tr>
    </table>

    @if ($analisi->isNotEmpty())
    <h2>5. Analisi strumentali</h2>
    <table class="cols">
        <tr><th>Strumento</th><th>Data</th><th>Quota (cm)</th><th>Esiti principali</th></tr>
        @foreach ($analisi as $a)
        <tr>
            <td>{{ $a->instrument_type }}@if ($a->instrument_model) ({{ $a->instrument_model }})@endif</td>
            <td>{{ $a->measured_at?->format('d/m/Y') }}</td>
            <td>{{ $a->measurement_height_cm ?? '-' }}</td>
            <td>{{ $a->summary_text }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    <h2>{{ $analisi->isNotEmpty() ? '6' : '5' }}. Classe di propensione al cedimento</h2>
    <div class="classe">
        <span class="lettera">{{ $assessment->failure_class }}</span> -
        {{ $classiDescrizione[$assessment->failure_class] ?? '' }}
    </div>
    <div class="legenda">
        Classi FRC: A trascurabile - B bassa - C moderata (monitoraggio e/o interventi) -
        C/D elevata (interventi prioritari; se non attuabili, abbattimento) - D estrema (abbattimento).
    </div>

    <h2>{{ $analisi->isNotEmpty() ? '7' : '6' }}. Prescrizioni e periodicità di ricontrollo</h2>
    <table>
        <tr><th>Interventi prescritti</th><td>{{ $assessment->prescriptions ?: 'Nessuno' }}</td></tr>
        <tr><th>Prossimo controllo entro</th><td>{{ $assessment->next_check_due?->format('d/m/Y') ?? '-' }}</td></tr>
    </table>

    <h2>{{ $analisi->isNotEmpty() ? '8' : '7' }}. Documentazione fotografica</h2>
    @if ($fotoDataUri)
        <div class="foto-box"><img src="{{ $fotoDataUri }}" alt="Foto esemplare"></div>
    @else
        <div class="foto-box" style="line-height: 170px;">FOTO NON DISPONIBILE</div>
    @endif

    <h2>{{ $analisi->isNotEmpty() ? '9' : '8' }}. Conclusioni</h2>
    <p style="line-height: 1.5;">{{ $conclusioni }}</p>

    <table class="firma">
        <tr>
            <td style="width: 55%;">Luogo e data: ____________________________</td>
            <td class="linea">Il tecnico<br>{{ $professionista['nome'] }}</td>
        </tr>
    </table>
    <p class="muted" style="font-size: 8.5px; margin-top: 14px;">
        La presente valutazione fotografa le condizioni dell'esemplare alla data del sopralluogo
        con il metodo VTA (Visual Tree Assessment). La validità è subordinata al rispetto delle
        prescrizioni e della periodicità di ricontrollo indicate; eventi meteorici eccezionali
        o danni sopravvenuti impongono una nuova valutazione.
    </p>
</body>
</html>
