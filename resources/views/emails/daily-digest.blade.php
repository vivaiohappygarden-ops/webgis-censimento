<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #111; margin: 0; padding: 16px; background: #fff;">
    @php
        $sections = $digest['sections'];
        $fmtDate = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
        $severita = ['critical' => 'critica', 'high' => 'alta', 'medium' => 'media', 'low' => 'bassa'];
        $rowStyle = 'padding: 3px 8px 3px 0; border-bottom: 1px solid #eee; vertical-align: top;';
        $h2 = 'font-size: 15px; margin: 20px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px;';
    @endphp

    <p style="margin: 0 0 4px; font-size: 16px;"><strong>Riepilogo scadenze del {{ $fmtDate($digest['date']) }}</strong></p>
    <p style="margin: 0; color: #555;">{{ $organization->name }} - le stesse voci del cruscotto Oggi, in breve.</p>

    @isset($sections['certificates'])
        <h2 style="{{ $h2 }}">Patentini e certificati ({{ $sections['certificates']['count'] }})</h2>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($sections['certificates']['rows'] as $row)
            <tr>
                <td style="{{ $rowStyle }}">{{ $row['holder_name'] }}</td>
                <td style="{{ $rowStyle }}">{{ $row['title'] }}</td>
                <td style="{{ $rowStyle }} white-space: nowrap; {{ $row['state'] === 'expired' ? 'color: #b91c1c;' : '' }}">
                    {{ $row['state'] === 'expired' ? 'scaduto il' : 'scade il' }} {{ $fmtDate($row['expires_on']) }}
                </td>
            </tr>
            @endforeach
        </table>
    @endisset

    @isset($sections['vta'])
        <h2 style="{{ $h2 }}">Ricontrolli di stabilità VTA ({{ $sections['vta']['count'] }})</h2>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($sections['vta']['rows'] as $row)
            <tr>
                <td style="{{ $rowStyle }}">{{ $row['census_code'] }}</td>
                <td style="{{ $rowStyle }}">classe {{ $row['failure_class'] ?? 'n.d.' }}</td>
                <td style="{{ $rowStyle }} white-space: nowrap; {{ $row['overdue'] ? 'color: #b91c1c;' : '' }}">
                    {{ $row['overdue'] ? 'dovuto entro il' : 'entro il' }} {{ $fmtDate($row['next_check_due']) }}
                </td>
            </tr>
            @endforeach
        </table>
    @endisset

    @isset($sections['inspections'])
        <h2 style="{{ $h2 }}">Controlli ricorrenti ({{ $sections['inspections']['count'] }})</h2>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($sections['inspections']['rows'] as $row)
            <tr>
                <td style="{{ $rowStyle }}">{{ $row['template_name'] }}</td>
                <td style="{{ $rowStyle }}">{{ $row['target_label'] }}</td>
                <td style="{{ $rowStyle }} white-space: nowrap; {{ $row['overdue'] ? 'color: #b91c1c;' : '' }}">
                    {{ $row['overdue'] ? 'in ritardo, dovuto il' : 'entro il' }} {{ $fmtDate($row['due_date']) }}
                </td>
            </tr>
            @endforeach
        </table>
    @endisset

    @isset($sections['issues'])
        <h2 style="{{ $h2 }}">Segnalazioni con tempi a rischio ({{ $sections['issues']['count'] }})</h2>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($sections['issues']['rows'] as $row)
            @php
                $phase = $row['status'] === 'open' ? ($row['sla']['take_charge'] ?? null) : ($row['sla']['resolve'] ?? null);
                $late = ($phase['state'] ?? null) === 'overdue';
            @endphp
            <tr>
                <td style="{{ $rowStyle }} white-space: nowrap;">{{ $row['code'] }}</td>
                <td style="{{ $rowStyle }}">gravità {{ $severita[$row['severity']] ?? $row['severity'] }}</td>
                <td style="{{ $rowStyle }}">{{ $row['description'] }}</td>
                <td style="{{ $rowStyle }} white-space: nowrap; {{ $late ? 'color: #b91c1c;' : '' }}">
                    {{ $late ? 'fuori tempo massimo' : 'scadenza vicina' }}
                </td>
            </tr>
            @endforeach
        </table>
    @endisset

    @isset($sections['work_orders'])
        <h2 style="{{ $h2 }}">Lavori oltre la fine prevista ({{ $sections['work_orders']['count'] }})</h2>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($sections['work_orders']['rows'] as $row)
            <tr>
                <td style="{{ $rowStyle }} white-space: nowrap;">{{ $row['code'] }}</td>
                <td style="{{ $rowStyle }}">{{ $row['title'] }}</td>
                <td style="{{ $rowStyle }} white-space: nowrap; color: #b91c1c;">doveva chiudersi il {{ $fmtDate($row['planned_end']) }}</td>
            </tr>
            @endforeach
        </table>
    @endisset

    <p style="margin: 24px 0 0;">
        <a href="{{ rtrim(config('app.url'), '/') }}/oggi" style="color: #166534;">Apri il cruscotto Oggi</a>
    </p>
    <p style="margin: 12px 0 0; color: #888; font-size: 12px;">
        Ricevi questo riepilogo perché sei amministratore o tecnico di {{ $organization->name }}.
        Per non riceverlo più, l'amministratore può spegnere la preferenza dalla pagina Utenti.
    </p>
</body>
</html>
