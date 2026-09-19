@extends('sito.layout')

@php
    $azienda = \App\Support\SitoDati::gruppo('azienda');
    $c = \App\Support\SitoDati::gruppo('contatti');
    $titolare = $azienda['ragione_sociale'] ?? null;
    $recapitiTitolare = array_filter([
        isset($c['pec']) ? 'PEC '.$c['pec'] : null,
        $c['email'] ?? null,
        $c['telefono'] ?? null,
    ]);
@endphp

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">Note legali</p>
            <h1 id="titolo-pagina">Privacy e note legali</h1>
        </div>
        <div class="c-4-13">
            <p class="guida">
                Questo sito presenta un servizio. Non raccoglie dati di chi lo legge, e questa
                pagina spiega perché non c'è niente da accettare.
            </p>
        </div>
    </div>
</section>

<section class="sezione sezione-avorio filo-sopra" aria-label="Informativa">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Informativa</p>
        </div>
        <div class="c-5-13 prosa">
            <h2 style="margin-top: 0;">Che cosa raccoglie questo sito</h2>
            <p>Niente. In particolare, questo sito:</p>
            <ul>
                <li>non utilizza moduli: non c'è niente da compilare né da inviare;</li>
                <li>non utilizza strumenti di profilazione;</li>
                <li>non utilizza sistemi di statistica sui visitatori;</li>
                <li>non imposta cookie, né tecnici né di terzi;</li>
                <li>non carica risorse da siti terzi: anche i caratteri tipografici sono ospitati sul nostro server, quindi aprendo queste pagine il vostro browser non contatta nessun altro.</li>
            </ul>
            <p>
                Per questo non c'è un banner: <strong>nessun cookie, niente da accettare</strong>.
                La dichiarazione è verificata tecnicamente, non è una formula di cortesia.
            </p>

            <h2>Registri del server</h2>
            <p>
                Il server può conservare per un periodo limitato i registri tecnici degli
                accessi (indirizzo IP, data e ora, pagina richiesta), come qualunque server web.
                Servono alla sicurezza e alla diagnosi dei guasti, non a costruire profili.
            </p>

            <h2>Se ci scrivete</h2>
            <p>
                Quando ci scrivete per posta elettronica o certificata, i dati che ci comunicate
                (nome, ente, recapiti, contenuto del messaggio) vengono usati soltanto per
                rispondere alla richiesta e per l'eventuale rapporto che ne segue. Non vengono
                comunicati a terzi né usati per invii commerciali.
            </p>
            <p>
                Potete chiedere in qualunque momento di accedere ai vostri dati, correggerli o
                farli cancellare, scrivendo ai recapiti del titolare indicati sotto.
            </p>

            <h2>Portali dei Comuni</h2>
            <p>
                I collegamenti verso i portali comunali del patrimonio arboreo conducono a siti
                autonomi, pubblicati per conto di ciascun ente. Ogni portale ha la propria
                informativa, e i dati che mostra appartengono al Comune, che decide che cosa
                rendere pubblico.
            </p>

            @if ($titolare)
                <h2>Titolare del trattamento</h2>
                <p>
                    {{ $titolare }}{{ isset($azienda['sede']) ? ', '.$azienda['sede'] : '' }}{{ isset($azienda['piva']) ? ', partita IVA '.$azienda['piva'] : '' }}.
                    @if ($recapitiTitolare)
                        Recapiti: {{ implode(', ', $recapitiTitolare) }}.
                    @endif
                </p>
            @endif
        </div>
    </div>
</section>

@endsection
