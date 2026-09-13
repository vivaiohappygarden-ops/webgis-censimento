@extends('sito.layout')

@section('titolo', 'Privacy e note legali')
@section('descrizione', 'Come sono trattati i dati su questo sito: nessun cookie, nessuna statistica sui visitatori, nessuna risorsa caricata da terzi.')

@php
    $azienda = config('sito.azienda');
    $c = config('sito.contatti');
    $titolare = $azienda['ragione_sociale'];
@endphp

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Note legali</span>
        <h1 style="max-width: 22ch;">Privacy e note legali</h1>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore prosa">
        <h2>Cosa raccoglie questo sito</h2>
        <p>
            Niente. Questo sito non usa cookie, non installa strumenti di statistica e non
            profila chi lo visita. Non c'è alcun banner da accettare perché non c'è niente da
            autorizzare.
        </p>
        <p>
            Anche i caratteri tipografici sono ospitati sul nostro server: aprendo queste
            pagine il browser non contatta nessun altro fornitore.
        </p>

        <h2 style="margin-top: var(--s5);">Se ci scrivete</h2>
        <p>
            Quando ci scrivete per email o telefonate, i dati che ci comunicate (nome, ente,
            recapiti, contenuto del messaggio) vengono usati soltanto per rispondere alla
            richiesta e per l'eventuale rapporto contrattuale che ne segue. Non vengono
            comunicati a terzi né usati per invii commerciali.
        </p>
        <p>
            Potete chiedere in qualunque momento di accedere ai vostri dati, correggerli o
            farli cancellare
            @if ($c['email'])
                scrivendo a <a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>.
            @else
                usando i recapiti indicati nella pagina dei contatti.
            @endif
        </p>

        <h2 style="margin-top: var(--s5);">Registri del server</h2>
        <p>
            Il server conserva per un periodo limitato i registri tecnici degli accessi
            (indirizzo IP, data e ora, pagina richiesta), come fa qualunque server web. Servono
            alla sicurezza e alla diagnosi dei guasti, non a costruire profili.
        </p>

        <h2 style="margin-top: var(--s5);">Portali dei Comuni</h2>
        <p>
            I portali pubblici che pubblichiamo per i Comuni seguono la stessa regola: nessun
            cookie e nessuna statistica sui visitatori. I dati mostrati appartengono all'ente,
            che decide che cosa rendere pubblico.
        </p>

        @if ($titolare)
            <h2 style="margin-top: var(--s5);">Titolare del trattamento</h2>
            <p>
                {{ $titolare }}{{ $azienda['sede'] ? ', '.$azienda['sede'] : '' }}{{ $azienda['piva'] ? ' — P. IVA '.$azienda['piva'] : '' }}.
            </p>
        @endif
    </div>
</section>

@endsection
