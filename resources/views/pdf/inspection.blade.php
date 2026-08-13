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
    .valore { margin-top: 3px; color: #333; }
    th { background: #eee; }
    .esito { font-size: 12px; font-weight: bold; margin-top: 10px; }
    .firma { margin-top: 36px; }
    .firma td { border: none; padding-top: 24px; }
    .linea { border-top: 1px solid #111; width: 220px; }
</style>
</head>
<body>
    <div class="head">
        <h1>Verbale di ispezione</h1>
        <div class="muted">{{ $organization?->name }}</div>
    </div>

    <table>
        <tr>
            <th style="width: 30%">Modello di controllo</th>
            <td>{{ $inspection->template?->name }}@if ($inspection->template?->code) ({{ $inspection->template->code }})@endif</td>
        </tr>
        @if ($inspection->template?->standard_ref)
        <tr><th>Norma di riferimento</th><td>{{ $inspection->template->standard_ref }}</td></tr>
        @endif
        <tr><th>Versione del modello</th><td>{{ $inspection->template_version }}</td></tr>
        <tr>
            <th>Oggetto ispezionato</th>
            <td>{{ $inspection->asset?->census_code ?? $inspection->area?->name ?? '-' }}</td>
        </tr>
        <tr><th>Ispettore</th><td>{{ $inspection->inspector?->name ?? '-' }}</td></tr>
        <tr><th>Data e ora</th><td>{{ $inspection->completed_at?->timezone('Europe/Rome')->format('d/m/Y H:i') }}</td></tr>
    </table>

    <h2>Checklist</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 4%">N.</th>
                <th>Controllo</th>
                <th style="width: 12%">Risposta</th>
                <th style="width: 34%">Nota</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    {{ $row['question'] }}
                    @if ($row['long'] !== null)<div class="valore">{{ $row['long'] }}</div>@endif
                </td>
                <td>{{ $row['short'] ?? 'v. sotto la domanda' }}</td>
                <td>{{ $row['note'] ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p class="esito">
        Esito: {{ ['passed' => 'SUPERATA', 'passed_with_remarks' => 'SUPERATA CON RISERVE', 'failed' => 'NON SUPERATA'][$inspection->outcome] ?? $inspection->outcome }}
    </p>

    @if ($nonConformities->isNotEmpty())
    <h2>Non conformità aperte da questa ispezione</h2>
    <table>
        <thead><tr><th style="width: 18%">Codice</th><th>Descrizione</th><th style="width: 12%">Gravità</th></tr></thead>
        <tbody>
            @foreach ($nonConformities as $nc)
            <tr>
                <td>{{ $nc->code }}</td>
                <td>{{ $nc->description }}</td>
                <td>{{ ['minor' => 'lieve', 'major' => 'grave', 'critical' => 'critica'][$nc->severity] ?? $nc->severity }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <table class="firma">
        <tr>
            <td>Data: ____ / ____ / ________</td>
            <td>
                <div class="linea"></div>
                Firma dell'ispettore
            </td>
        </tr>
    </table>
</body>
</html>
