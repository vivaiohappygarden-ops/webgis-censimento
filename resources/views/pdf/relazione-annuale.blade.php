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
    .nota { margin-top: 12px; font-size: 8.5px; color: #555; }
    .nota li { margin-bottom: 2px; }
    .foto-td { border: none; padding: 4px; width: 50%; text-align: center; }
    .foto-td img { width: 100%; max-height: 240px; object-fit: contain; }
    .didascalia { font-size: 8px; color: #555; margin-top: 2px; }
    .firma { margin-top: 26px; width: 100%; }
    .firma td { border: none; padding: 2px 6px; font-size: 9.5px; }
    .firma .linea { border-top: 1px solid #111; width: 220px; padding-top: 4px; text-align: center; }
</style>
</head>
<body>
    @php
        $n = fn ($v) => number_format((int) $v, 0, ',', '.');
        $dec = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $anno = $relazione['anno'];
        $patrimonio = $relazione['patrimonio'];
        $bilancio = $relazione['bilancio'];
        $lavori = $relazione['lavori'];
        $controlli = $relazione['controlli'];
        $segnalazioni = $relazione['segnalazioni'];
        $fitosanitari = $relazione['fitosanitari'];
        $co2 = $relazione['co2'];
        $foto = $relazione['foto'];
        $esitiVta = [
            'ok' => 'nessun intervento necessario',
            'monitor' => 'da monitorare',
            'prescriptions' => 'con prescrizioni',
            'fell' => 'da abbattere',
        ];
        $esitiIspezioni = [
            'passed' => 'superata',
            'passed_with_remarks' => 'superata con riserve',
            'failed' => 'non superata',
            'not_completed' => 'non completata',
        ];
    @endphp

    <div class="head">
        <h1>Relazione annuale del verde {{ $anno }} - {{ $client->name }}</h1>
        <div class="muted">
            Anno solare {{ $anno }} (1 gennaio - 31 dicembre)
            <br>
            Redatta da {{ $organization?->name }} - stampato il {{ $stampatoIl->format('d/m/Y') }}
        </div>
    </div>

    <h2>Il patrimonio in gestione</h2>
    <table>
        <tr>
            <th style="width: 70%;">Voce</th>
            <th style="width: 30%;" class="num">Numero</th>
        </tr>
        <tr><td>Elementi censiti in gestione</td><td class="num">{{ $n($patrimonio['elementi']) }}</td></tr>
        <tr><td>Alberi</td><td class="num">{{ $n($patrimonio['alberi']) }}</td></tr>
        <tr><td>Specie e varieta' distinte</td><td class="num">{{ $n($patrimonio['varieta']) }}</td></tr>
        <tr><td>Aree verdi</td><td class="num">{{ $n($patrimonio['aree']) }}</td></tr>
        <tr><td>Localita'</td><td class="num">{{ $n($patrimonio['localita']) }}</td></tr>
    </table>

    @if ($bilancio)
        <h2>Bilancio arboreo dell'anno (legge 14 gennaio 2013, n. 10)</h2>
        <table>
            <tr>
                <th style="width: 70%;">Voce</th>
                <th style="width: 30%;" class="num">Alberi</th>
            </tr>
            <tr><td>Consistenza al 1 gennaio {{ $anno }}</td><td class="num">{{ $n($bilancio['initial_count']) }}</td></tr>
            <tr><td>Nuovi impianti nell'anno</td><td class="num">{{ $bilancio['planted_count'] > 0 ? '+' : '' }}{{ $n($bilancio['planted_count']) }}</td></tr>
            <tr><td>Abbattimenti e rimozioni nell'anno</td><td class="num">{{ $bilancio['felled_count'] > 0 ? '-' : '' }}{{ $n($bilancio['felled_count']) }}</td></tr>
            <tr><td>Consistenza al 31 dicembre {{ $anno }}</td><td class="num">{{ $n($bilancio['final_count']) }}</td></tr>
            <tr class="saldo"><td>Variazione dell'anno</td><td class="num">{{ $bilancio['variation'] > 0 ? '+' : '' }}{{ $n($bilancio['variation']) }}</td></tr>
        </table>
        @if (count($bilancio['abbattute_per_specie']))
            <table>
                <tr>
                    <th style="width: 70%;">Specie piu' toccate dagli abbattimenti</th>
                    <th style="width: 30%;" class="num">Abbattuti</th>
                </tr>
                @foreach ($bilancio['abbattute_per_specie'] as $riga)
                    <tr><td>{{ $riga->species_label }}</td><td class="num">{{ (int) $riga->felled }}</td></tr>
                @endforeach
            </table>
        @endif
    @endif

    @if ($lavori)
        <h2>I lavori dell'anno</h2>
        <table>
            <tr>
                <th style="width: 70%;">Voce</th>
                <th style="width: 30%;" class="num">Numero</th>
            </tr>
            <tr><td>Ordini di lavoro completati</td><td class="num">{{ $n($lavori['ordini']) }}</td></tr>
            @if ($lavori['ore'] > 0)
                <tr><td>Ore uomo dai rapportini</td><td class="num">{{ $dec($lavori['ore']) }}</td></tr>
            @endif
            @if ($lavori['sal_emessi'] > 0)
                <tr><td>Stati di avanzamento lavori emessi</td><td class="num">{{ $n($lavori['sal_emessi']) }}</td></tr>
            @endif
        </table>
        <table>
            <tr>
                <th style="width: 50%;">Lavorazione</th>
                <th style="width: 15%;" class="num">Ordini</th>
                <th style="width: 35%;">Quantita' consuntivate</th>
            </tr>
            @foreach ($lavori['per_tipo'] as $riga)
                <tr>
                    <td>{{ $riga['tipo'] }}</td>
                    <td class="num">{{ $n($riga['ordini']) }}</td>
                    <td>
                        @forelse ($riga['quantita'] as $unita => $quantita)
                            {{ $dec($quantita) }} {{ $unita }}@if (! $loop->last), @endif
                        @empty
                            -
                        @endforelse
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($controlli)
        <h2>I controlli dell'anno</h2>
        <table>
            <tr>
                <th style="width: 70%;">Voce</th>
                <th style="width: 30%;" class="num">Numero</th>
            </tr>
            @if ($controlli['vta'])
                <tr><td>Valutazioni di stabilita' (VTA) eseguite</td><td class="num">{{ $n($controlli['vta']['totale']) }}</td></tr>
                <tr><td>di cui con prescrizioni</td><td class="num">{{ $n($controlli['vta']['con_prescrizioni']) }}</td></tr>
                @foreach ($controlli['vta']['esiti'] as $esito => $quanti)
                    <tr><td>esito: {{ $esitiVta[$esito] ?? $esito }}</td><td class="num">{{ $n($quanti) }}</td></tr>
                @endforeach
            @endif
            @if ($controlli['ispezioni'])
                <tr><td>Ispezioni completate</td><td class="num">{{ $n($controlli['ispezioni']['totale']) }}</td></tr>
                @foreach ($controlli['ispezioni']['esiti'] as $esito => $quanti)
                    <tr><td>esito: {{ $esitiIspezioni[$esito] ?? $esito }}</td><td class="num">{{ $n($quanti) }}</td></tr>
                @endforeach
            @endif
        </table>
    @endif

    @if ($segnalazioni)
        <h2>Le segnalazioni dell'anno</h2>
        <table>
            <tr>
                <th style="width: 70%;">Voce</th>
                <th style="width: 30%;" class="num">Numero</th>
            </tr>
            <tr><td>Segnalazioni ricevute</td><td class="num">{{ $n($segnalazioni['ricevute']) }}</td></tr>
            <tr><td>di cui risolte</td><td class="num">{{ $n($segnalazioni['risolte']) }}</td></tr>
            @if ($segnalazioni['ore_prima_risposta'] !== null)
                <tr><td>Tempo medio di presa in carico (ore)</td><td class="num">{{ $dec($segnalazioni['ore_prima_risposta']) }}</td></tr>
            @endif
        </table>
    @endif

    @if ($fitosanitari)
        <h2>I trattamenti fitosanitari dell'anno</h2>
        <table>
            <tr>
                <th style="width: 70%;">Voce</th>
                <th style="width: 30%;" class="num">Numero</th>
            </tr>
            <tr><td>Trattamenti registrati</td><td class="num">{{ $n($fitosanitari['totale']) }}</td></tr>
            @foreach ($fitosanitari['prodotti'] as $riga)
                <tr><td>prodotto: {{ $riga['prodotto'] }}</td><td class="num">{{ $n($riga['trattamenti']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($co2)
        <h2>Anidride carbonica immagazzinata dal patrimonio arboreo</h2>
        <table>
            <tr>
                <th style="width: 70%;">Voce</th>
                <th style="width: 30%;" class="num">Valore</th>
            </tr>
            <tr>
                <td>CO2 immagazzinata (stima su {{ $n($co2['alberi_stimati']) }} alberi con diametro noto su {{ $n($co2['alberi_totali']) }})</td>
                <td class="num">{{ number_format($co2['kg'], 1, ',', '.') }} kg</td>
            </tr>
            @if ($co2['euro'] !== null)
                <tr><td>Controvalore economico ({{ $dec($co2['prezzo']) }} euro/t)</td><td class="num">{{ number_format($co2['euro'], 2, ',', '.') }} euro</td></tr>
            @endif
        </table>
        <div class="nota">
            <strong>Metodo di stima della CO2</strong>: {{ $co2['metodo'] }}.
            La stima riguarda i soli alberi con diametro del tronco noto (o ricavabile
            dalla circonferenza) e si riferisce al patrimonio in gestione alla data di stampa.
            @if ($co2['fonte'])
                Prezzo applicato per il controvalore: {{ $dec($co2['prezzo']) }} euro per tonnellata ({{ $co2['fonte'] }}).
            @endif
        </div>
    @endif

    @if ($foto)
        <h2>Documentazione fotografica dell'anno</h2>
        <table style="border: none;">
            @foreach (array_chunk($foto['foto'], 2) as $coppia)
                <tr>
                    @foreach ($coppia as $f)
                        <td class="foto-td">
                            <img src="{{ $f['data'] }}" alt="">
                            <div class="didascalia">{{ $f['didascalia'] }}</div>
                        </td>
                    @endforeach
                    @if (count($coppia) === 1)
                        <td class="foto-td"></td>
                    @endif
                </tr>
            @endforeach
        </table>
        @if ($foto['totale'] > count($foto['foto']))
            <div class="nota">
                Nell'anno risultano {{ $n($foto['totale']) }} fotografie sugli elementi del
                committente: se ne stampano {{ count($foto['foto']) }}, le piu' recenti.
            </div>
        @endif
    @endif

    <div class="nota">
        <strong>Nota sul metodo</strong>
        <ul>
            <li>La relazione riguarda l'anno solare {{ $anno }} e i soli dati del
                committente indicato, come registrati nel programma alla data di stampa.</li>
            <li>Il patrimonio in gestione e la stima della CO2 sono una fotografia alla
                data di stampa; le schede abbattute o dismesse non vi rientrano.</li>
            <li>Il bilancio arboreo segue la legge 10/2013: un albero e' presente dalla
                messa a dimora (o dal censimento, se la data non e' nota) fino alla data
                di abbattimento registrata.</li>
            <li>Le sezioni senza dati nell'anno non vengono stampate.</li>
        </ul>
    </div>

    <table class="firma">
        <tr>
            <td style="width: 50%;">{{ $luogoData }}</td>
            <td style="width: 50%;">Il tecnico incaricato</td>
        </tr>
        <tr>
            <td></td>
            <td><div class="linea">timbro e firma</div></td>
        </tr>
    </table>
</body>
</html>
