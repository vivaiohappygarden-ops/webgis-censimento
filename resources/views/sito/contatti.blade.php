@extends('sito.layout')

@section('titolo', 'Contatti')
@section('descrizione', 'Come chiedere un sopralluogo o un preventivo per il censimento del verde urbano e le valutazioni di stabilità.')

@php
    $c = config('sito.contatti');
    $azienda = config('sito.azienda');
    $haRecapiti = (bool) ($c['telefono'] || $c['email'] || $c['pec']);
@endphp

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Contatti</span>
        <h1 style="max-width: 22ch;">Richiedi un sopralluogo</h1>
        <p class="guida" style="margin-top: var(--s3);">
            Per un preventivo serve sapere che cosa c'è da censire. Bastano poche righe:
            il Comune, l'ambito che vi interessa (alberature stradali, parchi, scuole) e,
            se lo sapete, quanti alberi più o meno. Rispondiamo con le domande che mancano.
        </p>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <div class="griglia griglia-2">
            <div>
                <h2>Recapiti</h2>
                @if ($haRecapiti)
                    <ul class="campi" style="margin-top: var(--s4); grid-template-columns: 1fr;">
                        @if ($c['telefono'])
                            <li>
                                <span class="piccolo" style="display: block;">Telefono</span>
                                <a href="tel:{{ preg_replace('/[^+0-9]/', '', $c['telefono']) }}">{{ $c['telefono'] }}</a>
                            </li>
                        @endif
                        @if ($c['email'])
                            <li>
                                <span class="piccolo" style="display: block;">Email</span>
                                <a href="mailto:{{ $c['email'] }}?subject=Richiesta%20di%20sopralluogo">{{ $c['email'] }}</a>
                            </li>
                        @endif
                        @if ($c['pec'])
                            <li>
                                <span class="piccolo" style="display: block;">Posta certificata</span>
                                <a href="mailto:{{ $c['pec'] }}">{{ $c['pec'] }}</a>
                            </li>
                        @endif
                        @if ($c['indirizzo'])
                            <li>
                                <span class="piccolo" style="display: block;">Indirizzo</span>
                                {{ $c['indirizzo'] }}
                            </li>
                        @endif
                        @if ($c['orari'])
                            <li>
                                <span class="piccolo" style="display: block;">Orari</span>
                                {{ $c['orari'] }}
                            </li>
                        @endif
                    </ul>
                @else
                    {{-- Nessun recapito configurato: si dice, non si inventa un
                         numero di telefono. Finche' e' cosi' il sito non
                         andrebbe pubblicato sul dominio. --}}
                    <p class="nota" style="margin-top: var(--s4);">
                        I recapiti non sono ancora stati pubblicati su questo sito.
                    </p>
                @endif
            </div>

            <div>
                <h2>Che cosa succede dopo</h2>
                <ol class="passi" style="margin-top: var(--s4);">
                    <li class="passo">
                        <div>
                            <h3>Una telefonata</h3>
                            <p>Dieci minuti per capire l'ambito e se ha senso vedersi.</p>
                        </div>
                    </li>
                    <li class="passo">
                        <div>
                            <h3>Il sopralluogo</h3>
                            <p>Si guarda il patrimonio insieme all'ufficio tecnico. Non si paga.</p>
                        </div>
                    </li>
                    <li class="passo">
                        <div>
                            <h3>Il preventivo</h3>
                            <p>Con numeri: quanti elementi, che dettaglio, in quanto tempo, a che costo.</p>
                        </div>
                    </li>
                </ol>
            </div>
        </div>
    </div>
</section>

<section class="sezione">
    <div class="contenitore">
        <p class="nota">
            Scrivendoci per email i vostri dati vengono usati solo per rispondere alla
            richiesta e non vengono comunicati a terzi.
            <a class="collegamento" href="{{ $u('privacy') }}">Privacy e note legali</a>.
        </p>
        @if ($azienda['ragione_sociale'])
            <p class="piccolo" style="margin-top: var(--s3);">{{ $azienda['ragione_sociale'] }}</p>
        @endif
    </div>
</section>

@endsection
