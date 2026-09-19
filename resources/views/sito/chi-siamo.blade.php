@extends('sito.layout')

@php
    $azienda = \App\Support\SitoDati::gruppo('azienda');
    $dati = \App\Support\SitoDati::datiSocietari();
    $professionisti = \App\Support\SitoDati::elenco('professionisti');
    $referenze = \App\Support\SitoDati::elenco('referenze');
    $lavori = \App\Support\SitoDati::elenco('lavori');
    // Dove e da quando: due righe che compaiono solo se compilate
    $dove = array_filter([
        'Territorio servito' => $azienda['territorio'] ?? null,
        'Esperienza' => $azienda['esperienza'] ?? null,
    ]);
@endphp

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">{{ $azienda['ragione_sociale'] ?? 'Chi siamo' }}</p>
            <h1 id="titolo-pagina">Con chi avete a che fare</h1>
        </div>
        <div class="c-4-13">
            <p class="guida">
                Un fornitore tecnico per il censimento e il controllo del patrimonio arboreo
                pubblico: dati societari in chiaro e un modo di lavorare che si vede in quello
                che consegniamo.
            </p>
        </div>
    </div>
</section>

@if ($dati)
    <section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-dati">
        <div class="contenitore griglia">
            <div class="c-1-4">
                <p class="occhiello">Dati societari</p>
                <h2 id="titolo-dati">In chiaro</h2>
            </div>
            <dl class="dati c-5-13">
                @foreach ($dati as $etichetta => $valore)
                    <div><dt>{{ $etichetta }}</dt><dd>{{ $valore }}</dd></div>
                @endforeach
            </dl>
        </div>
    </section>
@endif

@if ($dove)
    <section class="sezione filo-sopra" aria-labelledby="titolo-dove">
        <div class="contenitore griglia">
            <div class="c-1-4">
                <p class="occhiello">Dove e da quando</p>
                <h2 id="titolo-dove">Il nostro campo di lavoro</h2>
            </div>
            <dl class="dati c-5-13">
                @foreach ($dove as $etichetta => $valore)
                    <div><dt>{{ $etichetta }}</dt><dd>{{ $valore }}</dd></div>
                @endforeach
            </dl>
        </div>
    </section>
@endif

<section class="sezione {{ $dati || $dove ? '' : 'sezione-avorio' }} filo-sopra" aria-labelledby="titolo-abitudini">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Come lavoriamo</p>
            <h2 id="titolo-abitudini">Tre abitudini che si vedono nel lavoro consegnato</h2>
        </div>
        <div class="principi" style="margin-top: var(--s5);">
            <article class="principio">
                <h3>Definire prima il dato da raccogliere</h3>
                <p>
                    Prima del rilievo si stabilisce con l'ente che cosa registrare e con quale
                    dettaglio. Quello che non è previsto non si improvvisa in campo, e quello che
                    manca resta vuoto: un dato non rilevato non diventa uno zero.
                </p>
            </article>
            <article class="principio">
                <h3>Verificare prima di consegnare</h3>
                <p>
                    Coerenza fra scheda, posizione e fotografia, codifiche, completezza: i dati
                    si controllano prima della consegna. Il Comune riceve un archivio che regge
                    a un controllo, non una bozza.
                </p>
            </article>
            <article class="principio">
                <h3>Lasciare all'ente dati leggibili e riutilizzabili</h3>
                <p>
                    Formati aperti, identificativi stabili, un documento che descrive che cosa
                    c'è dentro. Il lavoro deve servire anche a chi verrà dopo, dentro l'ente o
                    fuori.
                </p>
            </article>
        </div>
    </div>
</section>

@if ($professionisti)
    <section class="sezione sezione-bianca filo-sopra" aria-labelledby="titolo-professionisti">
        <div class="contenitore griglia">
            <div class="c-1-4">
                <p class="occhiello">Chi firma</p>
                <h2 id="titolo-professionisti">Professionisti incaricati</h2>
            </div>
            <ul class="elenco-semplice c-5-13 prosa">
                @foreach ($professionisti as $voce)
                    <li>{{ $voce }}</li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

@if ($referenze)
    <section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-referenze">
        <div class="contenitore griglia">
            <div class="c-1-4">
                <p class="occhiello">Chi ci ha già scelto</p>
                <h2 id="titolo-referenze">Referenze</h2>
            </div>
            <ul class="elenco-semplice c-5-13 prosa">
                @foreach ($referenze as $r)
                    <li>
                        <strong>{{ $r['ente'] }}</strong>{{ isset($r['anno']) ? ' · '.$r['anno'] : '' }}
                        @if (isset($r['lavoro']))
                            <span style="display: block;">{{ $r['lavoro'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

@if ($lavori)
    <section class="sezione filo-sopra" aria-labelledby="titolo-lavori">
        <div class="contenitore griglia">
            <div class="c-1-4">
                <p class="occhiello">Lavori eseguiti</p>
                <h2 id="titolo-lavori">Quello che abbiamo consegnato</h2>
            </div>
            <ul class="elenco-semplice c-5-13 prosa">
                @foreach ($lavori as $l)
                    <li>
                        <strong>{{ $l['titolo'] }}</strong>{{ isset($l['anno']) ? ' · '.$l['anno'] : '' }}
                        @if (isset($l['descrizione']))
                            <span style="display: block;">{{ $l['descrizione'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

@include('sito.parti.chiusura', [
    'titolo' => 'Il modo più semplice per conoscerci è un sopralluogo',
    'testo' => 'Si guarda il patrimonio insieme all\'ufficio tecnico, si concorda l\'ambito e si capisce se ha senso lavorare insieme. Da lì nasce il preventivo.',
])

@endsection
