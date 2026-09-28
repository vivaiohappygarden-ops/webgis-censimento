{{-- Intestazione dell'organizzazione in cima a ogni documento stampato: ragione
     sociale, recapiti e logo di chi emette il documento (mai della piattaforma).
     Riceve tenantId; le righe vuote non si stampano. --}}
@php $intestazione = \App\Services\Pdf\Intestazione::per($tenantId ?? null); @endphp
@if ($intestazione)
<table class="intestazione" style="border: none; border-collapse: collapse; width: 100%; margin: 0 0 8px 0; border-bottom: 1px solid #888;">
    <tr>
        @if ($intestazione['logo'])
        <td style="border: none; padding: 0 8px 6px 0; width: 24mm; vertical-align: top;"><img src="{{ $intestazione['logo'] }}" alt="" style="max-height: 18mm; max-width: 24mm;"></td>
        @endif
        <td style="border: none; padding: 0 0 6px 0; vertical-align: top;">
            <div style="font-size: 12pt; font-weight: bold;">{{ $intestazione['nome'] }}</div>
            @foreach ($intestazione['righe'] as $riga)
            <div style="font-size: 8pt; color: #555;">{{ $riga }}</div>
            @endforeach
        </td>
    </tr>
</table>
@endif
