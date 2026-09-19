@extends('sito.layout')

@php
    $c = \App\Support\SitoDati::gruppo('contatti');
    $territorio = \App\Support\SitoDati::testo('azienda.territorio');
    $haRecapiti = isset($c['telefono']) || isset($c['email']) || isset($c['pec']) || isset($c['orari']) || $territorio !== null;
@endphp

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">Contatti</p>
            <h1 id="titolo-pagina">Richiedi un sopralluogo</h1>
        </div>
        <div class="c-4-13">
            <p class="guida">
                Per un preventivo serve sapere che cosa c'è da censire. Bastano poche righe:
                l'ente, l'ambito che vi interessa (alberature stradali, parchi, scuole) e, se lo
                sapete, quanti alberi più o meno. Rispondiamo con le domande che mancano.
            </p>
        </div>
    </div>
</section>

<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-recapiti">
    <div class="contenitore griglia" style="align-items: start;">
        <div class="c-1-6">
            <p class="occhiello">Recapiti</p>
            <h2 id="titolo-recapiti">Dove scriverci</h2>
            @if ($haRecapiti)
                {{-- Ogni voce vuota sparisce del tutto: niente etichette senza valore,
                     niente trattini, niente "da definire" --}}
                <dl class="recapiti" style="margin-top: var(--s4);">
                    @if (isset($c['telefono']))
                        <div class="recapito">
                            <dt>Telefono</dt>
                            <dd><a href="{{ \App\Support\SitoDati::telefonoHref($c['telefono']) }}">{{ $c['telefono'] }}</a></dd>
                        </div>
                    @endif
                    @if (isset($c['email']))
                        <div class="recapito">
                            <dt>Posta elettronica</dt>
                            <dd><a href="mailto:{{ $c['email'] }}?subject=Richiesta%20di%20sopralluogo">{{ $c['email'] }}</a></dd>
                        </div>
                    @endif
                    @if (isset($c['pec']))
                        <div class="recapito">
                            <dt>Posta elettronica certificata</dt>
                            <dd><a href="mailto:{{ $c['pec'] }}?subject=Richiesta%20di%20sopralluogo">{{ $c['pec'] }}</a></dd>
                        </div>
                    @endif
                    @if (isset($c['orari']))
                        <div class="recapito">
                            <dt>Orari</dt>
                            <dd>{{ $c['orari'] }}</dd>
                        </div>
                    @endif
                    @if ($territorio !== null)
                        <div class="recapito">
                            <dt>Territorio servito</dt>
                            <dd>{{ $territorio }}</dd>
                        </div>
                    @endif
                </dl>
            @endif
        </div>

        <div class="c-7-13">
            <p class="occhiello">Che cosa succede dopo</p>
            <h2>Tre passaggi, in quest'ordine</h2>
            <ol class="registro registro-compatto" style="margin-top: var(--s4);">
                <li class="voce">
                    <span class="voce-indice" aria-hidden="true">1</span>
                    <h3>Primo contatto</h3>
                    <p>Poche righe o una telefonata per capire l'ambito e se ha senso vedersi.</p>
                </li>
                <li class="voce">
                    <span class="voce-indice" aria-hidden="true">2</span>
                    <h3>Sopralluogo</h3>
                    <p>Si guarda il patrimonio insieme all'ufficio tecnico e si concorda che cosa rilevare e con quale dettaglio.</p>
                </li>
                <li class="voce">
                    <span class="voce-indice" aria-hidden="true">3</span>
                    <h3>Preventivo tecnico ed economico</h3>
                    <p>Con numeri: quanti elementi, che dettaglio, in quanto tempo, a che costo, e con quale tracciato di consegna.</p>
                </li>
            </ol>
        </div>
    </div>
</section>

<section class="sezione" aria-label="Nota sul trattamento dei dati">
    <div class="contenitore">
        <p class="nota">
            Scrivendoci, i vostri dati vengono usati soltanto per rispondere alla richiesta e
            non vengono comunicati a terzi. Questo sito non ha moduli e non usa cookie:
            <a href="{{ $u('privacy') }}">privacy e note legali</a>.
        </p>
    </div>
</section>

@endsection
