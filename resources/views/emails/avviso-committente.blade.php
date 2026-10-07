<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 15px; color: #111; margin: 0; padding: 16px; background: #fff;">
    @php
        $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone('Europe/Rome')->format('d/m/Y') : null;
        $riga = 'padding: 4px 10px 4px 0; vertical-align: top; color: #555; white-space: nowrap;';
        $valore = 'padding: 4px 0; vertical-align: top;';
        $specie = $asset->tree?->species ?: $asset->tree?->common_name;
        $copia = $destinatario['origine'] === 'copia';
    @endphp

    @if ($copia)
        <p style="margin: 0 0 12px; padding: 8px 10px; background: #f3f4f6; border: 1px solid #d1d5db; font-size: 13px;">
            Copia per chi ha inviato l'avviso.
            @if ($esiti)
                Destinatari:
                @foreach ($esiti as $e)
                    {{ $e['email'] }}{{ ($e['esito'] ?? '') === 'inviata' ? '' : ' (invio non riuscito)' }}{{ $loop->last ? '.' : ',' }}
                @endforeach
            @endif
        </p>
    @endif

    <p style="margin: 0 0 4px; font-size: 18px;"><strong>Avviso urgente: area da chiudere o interdire</strong></p>
    <p style="margin: 0 0 16px; color: #555;">{{ $organization?->name }} - valutazione di stabilità del {{ $fmt($assessment->assessed_on) }}</p>

    <p style="margin: 0 0 12px; padding: 10px 12px; border-left: 4px solid #b91c1c; background: #fef2f2;">
        {{ $avviso->message }}
    </p>

    <table style="border-collapse: collapse; margin: 0 0 16px;">
        <tr><td style="{{ $riga }}">Albero</td><td style="{{ $valore }}"><strong>{{ $asset->census_code ?: 'senza cartellino' }}</strong>@if ($specie) · <em>{{ $specie }}</em>@endif</td></tr>
        @if ($avviso->area?->name ?? $asset->area?->name)
            <tr><td style="{{ $riga }}">Area</td><td style="{{ $valore }}">{{ $avviso->area?->name ?? $asset->area?->name }}</td></tr>
        @endif
        <tr><td style="{{ $riga }}">Propensione al cedimento</td><td style="{{ $valore }}"><strong>classe {{ $assessment->failure_class ?? 'n.d.' }}</strong>@if ($assessment->failure_class === 'D') (estrema)@elseif ($assessment->failure_class === 'C/D') (elevata)@endif</td></tr>
        @if ($assessment->prescriptions)
            <tr><td style="{{ $riga }}">Prescrizioni</td><td style="{{ $valore }} white-space: pre-line;">{{ $assessment->prescriptions }}</td></tr>
        @endif
        @if ($assessment->prescriptions_due_on)
            <tr><td style="{{ $riga }}">Interventi entro il</td><td style="{{ $valore }}">{{ $fmt($assessment->prescriptions_due_on) }}</td></tr>
        @endif
        <tr><td style="{{ $riga }}">Inviato da</td><td style="{{ $valore }}">{{ $avviso->sender?->name ?? 'il tecnico incaricato' }}, {{ $fmt($avviso->sent_at) }}</td></tr>
    </table>

    @if (! $copia)
        <p style="margin: 0 0 8px;">
            @if ($destinatario['origine'] === 'portale')
                Vi chiediamo di prendere atto di questo avviso dal portale riservato: <a href="{{ $portale }}" style="color: #166534;">{{ $portale }}</a>.
            @else
                Vi chiediamo di prendere atto di questo avviso rispondendo a questa email, oppure dal portale riservato se avete un accesso.
            @endif
        </p>
        <p style="margin: 0 0 16px; color: #555; font-size: 13px;">L'avviso è una misura cautelare in attesa degli interventi prescritti: la chiusura o l'interdizione dell'area è una decisione dell'ente.</p>
    @endif

    <p style="margin: 16px 0 0; border-top: 1px solid #ddd; padding-top: 8px; color: #555; font-size: 13px;">
        <strong style="color: #111;">{{ $intestazione['nome'] ?? $organization?->name }}</strong>
        @foreach ($intestazione['righe'] ?? [] as $r)
            <br>{{ $r }}
        @endforeach
    </p>
</body>
</html>
