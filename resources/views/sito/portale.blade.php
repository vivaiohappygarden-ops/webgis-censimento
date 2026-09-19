@extends('sito.layout')

@php
    $portaleEsempio = \App\Support\SitoDati::gruppo('portale_esempio');
    // L'indirizzo dei portali civici: solo se il dominio dei Comuni e' configurato
    $dominioPortali = trim((string) config('portal.base_host')) ?: null;
@endphp

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">Per i cittadini</p>
            <h1 id="titolo-pagina">Il patrimonio arboreo, consultabile da tutti</h1>
        </div>
        <div class="c-4-13">
            <p class="guida">
                Il portale pubblico fa parte del servizio di censimento: nasce dagli stessi dati
                del rilievo e mostra ai cittadini quello che il Comune decide di pubblicare.
                @if ($dominioPortali)
                    Ogni Comune ha il proprio indirizzo, <span style="font-weight: 600; overflow-wrap: anywhere;">nomedelcomune.{{ $dominioPortali }}</span>, con lo stemma e i colori dell'ente.
                @endif
            </p>
            @if (isset($portaleEsempio['url']))
                <div class="inviti">
                    <a class="invito invito-secondario" href="{{ $portaleEsempio['url'] }}" rel="noopener">Apri un portale vero</a>
                </div>
            @endif
        </div>
    </div>
</section>

{{-- Lo stesso linguaggio visivo del portale: mappa, targhetta, cartellino --}}
<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-funzioni">
    <div class="contenitore griglia" style="align-items: start;">
        <div class="c-1-6">
            <div class="tavola">
                @include('sito.parti.mappa', ['tono' => 'chiaro'])
                @include('sito.parti.targhetta')
            </div>
        </div>
        <div class="c-7-13">
            <p class="occhiello">Che cosa ci trova il cittadino</p>
            <h2 id="titolo-funzioni">Dieci cose, tutte dal telefono</h2>
            <ul class="campi campi-1" style="margin-top: var(--s4);">
                <li>La mappa del patrimonio arboreo, con ogni albero al suo posto</li>
                <li>La scheda del singolo albero: specie, misure, data del rilievo, interventi</li>
                <li>La ricerca per numero del cartellino</li>
                <li>L'accesso dal codice QR stampato sul cartellino</li>
                <li>La consultazione da smartphone, tablet e computer</li>
                <li>I dati da pubblicare, scelti dal Comune</li>
                <li>Nessuna profilazione dei visitatori</li>
                <li>Nessun cookie</li>
                <li>I benefici ambientali presentati come stime, con il metodo dichiarato</li>
                <li>Compreso nel prezzo del censimento, quando previsto dall'offerta</li>
            </ul>
        </div>
    </div>
</section>

<section class="sezione" aria-labelledby="titolo-decide">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Che cosa decide il Comune</p>
            <h2 id="titolo-decide">Non esce niente in automatico</h2>
        </div>
        <div class="c-5-13 prosa">
            <p>
                Ogni intervento, ogni valutazione e ogni vincolo viene pubblicato
                <strong>solo se il Comune lo decide</strong>. I singoli alberi si possono
                nascondere. Le note interne e le fotografie dei difetti non escono mai.
            </p>
            <p>
                Anche le stime dei benefici ambientali (anidride carbonica immagazzinata,
                ossigeno, polveri trattenute, pioggia intercettata) si accendono una per una,
                e di partenza sono tutte spente.
            </p>
            <div class="avviso" style="margin-top: var(--s4);">
                <p>I dati sui benefici ambientali sono stime calcolate secondo la metodologia indicata nel portale. Non costituiscono misurazioni dirette.</p>
            </div>
        </div>
    </div>
</section>

<section class="sezione sezione-bianca filo-sopra" aria-labelledby="titolo-cookie">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Per il cittadino che lo apre</p>
            <h2 id="titolo-cookie">Nessun cookie, nessun banner</h2>
        </div>
        <div class="c-5-13 prosa">
            <p>
                Il portale non usa cookie e non raccoglie statistiche sui visitatori: non c'è
                niente da far accettare a chi lo apre, e niente da dichiarare. Anche i caratteri
                tipografici sono ospitati sul nostro server, quindi il browser del cittadino non
                contatta nessun altro.
            </p>
            <p>
                Questa pagina usa gli stessi colori, la stessa griglia e gli stessi segni
                cartografici del portale: chi passa dal sito al portale di un Comune riconosce
                la mano.
            </p>
        </div>
    </div>
</section>

@include('sito.parti.chiusura', [
    'titolo' => 'Il portale nasce dal censimento',
    'testo' => 'Non è un modulo da acquistare a parte, quando l\'offerta lo prevede: nasce dagli stessi dati del rilievo e si accende quando il Comune è pronto a mostrarli.',
])

@endsection
