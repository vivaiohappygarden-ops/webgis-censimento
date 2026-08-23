<!DOCTYPE html>
{{-- Perizia di valutazione della stabilità (metodo VTA), approvata dal
     committente il 14/08/2026: identificazione, contesto, dendrometrici,
     giudizio, difetti per parte, analisi strumentali, classe di propensione
     al cedimento, prescrizioni, foto, inquadramento cartografico e conclusioni.

     Regola di redazione: un campo lasciato vuoto dal tecnico si stampa come
     "Non valutato" o "Non rilevato in sede di sopralluogo", MAI come
     un'affermazione di assenza. Il documento è firmato: non può dichiarare
     accertamenti che nessuno ha eseguito. --}}
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 24px 24px 46px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; color: #111; margin: 0; }
    h1 { font-size: 14px; margin: 0 0 2px; }
    h2 { font-size: 11px; margin: 14px 0 5px; border-bottom: 1px solid #999; padding-bottom: 2px; }
    .muted { color: #555; }
    .vuoto { color: #777; font-style: italic; }
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
    .foto-box { width: 46%; border: 1px solid #999; display: inline-block; margin: 0 1.5% 8px 0; text-align: center; color: #999; vertical-align: top; page-break-inside: avoid; }
    .foto-box img { max-width: 100%; max-height: 150px; }
    .foto-did { font-size: 8.5px; color: #333; border-top: 1px solid #ddd; padding: 2px; }
    .strum-esito { font-size: 9px; color: #333; margin-top: 2px; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #666; border-top: 1px solid #ccc; padding-top: 3px; }
    /* Numero di pagina: dompdf sostituisce i contatori a ogni pagina */
    .footer .pag { float: right; }
    .footer .pag:after { content: "Pagina " counter(page); }
    .page-break { page-break-before: always; }
    .chiusura { page-break-inside: avoid; }
</style>
</head>
<body>
    @php
        // Numerazione progressiva: i capitoli che non ci sono non lasciano buchi
        $n = 0;
        $vuoto = fn ($testo) => trim((string) $testo) === '';
    @endphp

    <div class="footer">
        <span class="pag"></span>
        Perizia di stabilità {{ $riferimento }} - {{ $asset->census_code ?: 'elemento senza codice' }} -
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
        <div class="muted">{{ $tipoValutazione }}</div>
    </div>

    <h2>{{ ++$n }}. Identificazione dell'esemplare</h2>
    <table>
        <tr><th>Codice censimento</th><td>{{ $asset->census_code ?: '-' }}</td></tr>
        <tr><th>Specie</th><td>
            @if ($vuoto($tree->species) && $vuoto($tree->genus))
                <span class="vuoto">Non determinata</span>
            @else
                {{ $tree->species ?: $tree->genus }}@if ($tree->common_name) ({{ $tree->common_name }})@endif
            @endif
        </td></tr>
        <tr>
            <th>Ubicazione</th>
            <td>
                {{ $asset->area?->name }}
                @if ($asset->area?->locality) - {{ $asset->area->locality->name }}@endif
                @if ($asset->area?->locality?->site) - {{ $asset->area->locality->site->name }}@endif
            </td>
        </tr>
        <tr><th>Coordinate (WGS84)</th><td>{{ $coordinate }}</td></tr>
        <tr><th>Committente</th><td>{{ $committente ?: '-' }}</td></tr>
        <tr><th>Data del sopralluogo</th><td>{{ $assessment->assessed_on?->format('d/m/Y') ?? '-' }}</td></tr>
        <tr><th>Tipo di valutazione</th><td>{{ $tipoValutazione }}</td></tr>
        <tr><th>Rilievo eseguito da</th><td>
            @if ($vuoto($rilevatore))
                <span class="vuoto">Non indicato</span>
            @else
                {{ $rilevatore }}
            @endif
        </td></tr>
        @if ($tree->is_monumental || $tree->is_protected)
        <tr><th>Vincoli</th><td>
            @if ($tree->is_monumental) Albero monumentale @if ($tree->monumental_ref)({{ $tree->monumental_ref }})@endif @endif
            @if ($tree->is_protected) Esemplare tutelato @if ($tree->protection_ref)({{ $tree->protection_ref }})@endif @endif
        </td></tr>
        @endif
    </table>

    <h2>{{ ++$n }}. Inquadramento del contesto</h2>
    <table class="cols">
        <tr><th>Ambito</th><th>Sito di radicazione</th><th>Disposizione</th><th>Accessibilità</th></tr>
        <tr>
            @foreach (['ambito', 'sito_radicazione', 'disposizione', 'accessibilita'] as $voce)
            <td>
                @if ($vuoto($contesto[$voce] ?? null))<span class="vuoto">Non rilevato</span>@else{{ $contesto[$voce] }}@endif
            </td>
            @endforeach
        </tr>
    </table>
    <table>
        <tr><th>Bersagli presenti</th><td>
            @if ($vuoto($bersagli))<span class="vuoto">Non censiti in sede di sopralluogo</span>@else{{ $bersagli }}@endif
        </td></tr>
        <tr><th>Interferenze</th><td>
            @if ($vuoto($interferenze))<span class="vuoto">Non rilevate in sede di sopralluogo</span>@else{{ $interferenze }}@endif
        </td></tr>
    </table>

    <h2>{{ ++$n }}. Dati dendrometrici</h2>
    <table class="cols">
        <tr>
            <th>Altezza (m)</th><th>Diametro a 1,30 m (cm)</th><th>Circonferenza (cm)</th>
            <th>Diametro chioma (m)</th><th>Altezza primo palco (m)</th><th>Classe di età</th>
        </tr>
        <tr>
            @foreach ([$tree->height_m, $tree->dbh_cm, $tree->trunk_circumference_cm,
                       $tree->crown_diameter_m, $tree->crown_insertion_m, $tree->age_class] as $misura)
            <td>@if ($misura === null || $misura === '')<span class="vuoto">n.r.</span>@else{{ $misura }}@endif</td>
            @endforeach
        </tr>
    </table>
    <div class="legenda">n.r. = misura non rilevata in sede di sopralluogo.</div>

    <h2>{{ ++$n }}. Giudizio complessivo sull'esemplare</h2>
    <table class="cols">
        <tr><th>Fase fisiologica</th><th>Stato vegetativo</th><th>Giudizio sintetico</th></tr>
        <tr>
            @foreach (['fase_fisiologica', 'stato_vegetativo', 'sintetico'] as $voce)
            <td>
                @if ($vuoto($giudizio[$voce] ?? null))<span class="vuoto">Non valutato</span>@else{{ $giudizio[$voce] }}@endif
            </td>
            @endforeach
        </tr>
    </table>
    <table>
        <tr><th>Patologie da quarantena</th><td>
            @if ($vuoto($giudizio['patologie_quarantena'] ?? null))
                <span class="vuoto">Accertamento non eseguito in sede di sopralluogo</span>
            @else
                {{ $giudizio['patologie_quarantena'] }}
            @endif
        </td></tr>
    </table>

    <h2>{{ ++$n }}. Analisi visiva: difetti riscontrati per parte</h2>
    <table>
        @foreach (['Rilevamenti', 'Radici', 'Colletto', 'Fusto', 'Castello', 'Branche', 'Chioma e foglie'] as $parte)
        <tr><th>{{ $parte }}</th><td>
            @if ($vuoto($difetti[$parte] ?? null))
                <span class="vuoto">Parte non esaminata o senza annotazioni</span>
            @else
                {{ $difetti[$parte] }}
            @endif
        </td></tr>
        @endforeach
    </table>
    <div class="legenda">
        L'assenza di annotazioni per una parte non equivale all'accertata assenza di difetti:
        indica che in sede di sopralluogo non sono state registrate osservazioni per quella parte.
    </div>

    @if ($analisi->isNotEmpty())
    <h2>{{ ++$n }}. Analisi strumentali</h2>
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

    <h2>{{ ++$n }}. Classe di propensione al cedimento ed esito</h2>
    <div class="classe">
        <span class="lettera">{{ $assessment->failure_class }}</span> -
        {{ $classiDescrizione[$assessment->failure_class] ?? '' }}
    </div>
    <div class="legenda">
        Classi di propensione al cedimento: A trascurabile - B bassa - C moderata (monitoraggio e/o interventi) -
        C/D elevata (interventi prioritari; se non attuabili, abbattimento) - D estrema (abbattimento).
    </div>
    <table>
        <tr><th>Esito della valutazione</th><td>
            @if ($vuoto($esito))<span class="vuoto">Non espresso</span>@else{{ $esito }}@endif
        </td></tr>
        <tr><th>Integrazione o completamento VTA</th><td>
            @if ($vuoto($integrazioneVta))
                <span class="vuoto">Nessuna integrazione indicata</span>
            @else
                {{ $integrazioneVta }}
            @endif
        </td></tr>
    </table>

    <h2>{{ ++$n }}. Prescrizioni e periodicità di ricontrollo</h2>
    <table>
        <tr><th>Interventi prescritti</th><td>
            @if ($vuoto($assessment->prescriptions))
                <span class="vuoto">Nessun intervento prescritto</span>
            @else
                {{ $assessment->prescriptions }}
            @endif
        </td></tr>
        <tr><th>Priorità</th><td>
            @if ($vuoto($prioritaIntervento))<span class="vuoto">Non indicata</span>@else{{ $prioritaIntervento }}@endif
        </td></tr>
        <tr><th>Prossimo controllo entro</th><td>
            @if ($assessment->next_check_due)
                {{ $assessment->next_check_due->format('d/m/Y') }}
            @else
                <span class="vuoto">Non indicato</span>
            @endif
        </td></tr>
    </table>

    <h2>{{ ++$n }}. Documentazione fotografica</h2>
    @if (count($foto))
        @foreach ($foto as $i => $f)
            <div class="foto-box">
                <img src="{{ $f['data'] }}" alt="Foto {{ $i + 1 }}">
                <div class="foto-did">
                    Foto {{ $i + 1 }}@if ($f['scattata']) - scattata il {{ $f['scattata'] }}@endif
                </div>
            </div>
        @endforeach
    @else
        <p class="vuoto">Nessuna fotografia allegata alla presente perizia.</p>
    @endif

    <h2>{{ ++$n }}. Inquadramento cartografico</h2>
    @if ($mappaDataUri)
        <div class="foto-box" style="width: 94%;"><img src="{{ $mappaDataUri }}" style="max-height: 260px;" alt="Mappa"></div>
        <div class="legenda">
            Estratto cartografico OpenStreetMap centrato sulla posizione dell'esemplare, indicata dal
            simbolo rosso. Dati cartografici (c) OpenStreetMap contributors.
        </div>
    @else
        <p class="vuoto">Estratto cartografico non disponibile al momento dell'emissione.</p>
    @endif

    <div class="chiusura">
        <h2>{{ ++$n }}. Conclusioni</h2>
        <p style="line-height: 1.5;">{{ $conclusioni }}</p>

        <table class="firma">
            <tr>
                <td style="width: 55%;">{{ $luogoData }}</td>
                <td class="linea">Il tecnico incaricato<br>{{ $professionista['nome'] }}</td>
            </tr>
        </table>

        @if ($assessment->validated_at)
            {{-- Chiusura dell'atto: da qui il contenuto non e' piu' modificabile,
                 e l'impronta permette di verificarlo anche a distanza di anni --}}
            <p class="muted" style="font-size: 8.5px; margin-top: 14px; line-height: 1.45;">
                <strong>Documento validato e chiuso</strong> il
                {{ $assessment->validated_at->setTimezone('Europe/Rome')->format('d/m/Y \a\l\l\e H:i') }}
                @if ($assessment->validator) da {{ $assessment->validator->name }} @endif
                - da tale momento il contenuto tecnico non e' piu' modificabile.
                <br>
                Impronta del contenuto (SHA-256):
                {{ implode(' ', str_split(strtoupper(substr($assessment->content_hash, 0, 32)), 8)) }}
                <br>
                L'impronta si ricalcola dai dati della perizia: se corrisponde, questa copia
                riporta esattamente il contenuto validato alla data indicata.
            </p>
        @else
            <p class="muted" style="font-size: 8.5px; margin-top: 14px;">
                <strong>Bozza di lavoro.</strong> Documento non ancora validato: il contenuto
                puo' ancora essere corretto e questa copia non fa fede.
            </p>
        @endif
        <p class="muted" style="font-size: 8.5px; margin-top: 12px; line-height: 1.45;">
            La presente perizia fotografa le condizioni dell'esemplare quali riscontrabili alla data del
            sopralluogo con il metodo VTA (Visual Tree Assessment), fondato sull'analisi visiva dei sintomi
            esterni ed eventualmente integrato da indagini strumentali. Il metodo non consente di escludere
            il cedimento: la valutazione esprime una propensione al cedimento, non una garanzia di stabilità.
            Le conclusioni non si estendono a parti non ispezionabili né a difetti occulti non rilevabili con
            le indagini eseguite, e decadono in caso di eventi meteorici eccezionali, lavori sull'apparato
            radicale o sulla chioma, modifiche del contesto o danni sopravvenuti, che impongono una nuova
            valutazione. La validità è comunque subordinata al rispetto delle prescrizioni e della periodicità
            di ricontrollo indicate. Il documento è redatto per il committente indicato e non può essere
            riprodotto parzialmente.
        </p>
    </div>

</body>
</html>
