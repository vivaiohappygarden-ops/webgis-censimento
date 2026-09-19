@extends('sito.layout')

@php
    $firmatario = \App\Support\SitoDati::firmatario();
@endphp

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">Stabilità VTA</p>
            <h1 id="titolo-pagina">Dimostrare di aver vigilato</h1>
        </div>
        <div class="c-4-13">
            <p class="guida">
                La valutazione visiva di stabilità (VTA, Visual Tree Assessment) documenta le
                condizioni di un albero e le osservazioni del tecnico. Non elimina il rischio:
                lo rende conosciuto, valutato e tracciato nel tempo.
            </p>
        </div>
    </div>
</section>

{{-- Le cinque tappe, collegate: qui la sequenza e' vera --}}
<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-tappe">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Come si svolge</p>
            <h2 id="titolo-tappe">Dall'osservazione al ricontrollo</h2>
        </div>
        <ol class="tappe" style="--tappe: 5;">
            <li class="tappa">
                <span class="tappa-segno" aria-hidden="true"></span>
                <h3>Osservazione in campo</h3>
                <p>Chioma, fusto, colletto e area radicale si esaminano dal suolo. Le osservazioni entrano nella scheda dell'albero, con fotografie dove servono.</p>
            </li>
            <li class="tappa">
                <span class="tappa-segno" aria-hidden="true"></span>
                <h3>Approfondimenti strumentali</h3>
                <p>Quando l'esame visivo non basta a decidere, si indica la necessità di indagini strumentali. Sono un'attività distinta, con tempi e costi propri.</p>
            </li>
            <li class="tappa">
                <span class="tappa-segno" aria-hidden="true"></span>
                <h3>Documentazione</h3>
                <p>Per ogni albero esaminato: le osservazioni, la classe attribuita, gli interventi suggeriti con la loro urgenza, la data del ricontrollo.</p>
            </li>
            <li class="tappa">
                <span class="tappa-segno" aria-hidden="true"></span>
                <h3>Sottoscrizione</h3>
                <p>
                    @if ($firmatario)
                        La documentazione tecnica è sottoscritta da {{ $firmatario }}.
                    @else
                        La documentazione tecnica viene sottoscritta dal professionista incaricato, secondo la natura dell'attività e le competenze richieste.
                    @endif
                </p>
            </li>
            <li class="tappa">
                <span class="tappa-segno" aria-hidden="true"></span>
                <h3>Scadenzario</h3>
                <p>Le date dei ricontrolli entrano in uno scadenzario. Ogni verifica successiva si aggiunge alla precedente, senza sovrascriverla.</p>
            </li>
        </ol>
    </div>
</section>

<section class="sezione" aria-labelledby="titolo-documenti">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Che cosa consegniamo</p>
            <h2 id="titolo-documenti">Documenti che reggono a un controllo</h2>
        </div>
        <div class="c-5-13">
            <ul class="elenco-semplice prosa">
                <li><strong>Una scheda per ogni albero esaminato</strong>, con la classe attribuita e le osservazioni, ciascuna con la sua fotografia.</li>
                <li><strong>Gli interventi suggeriti e la loro urgenza</strong>: che cosa fare, entro quando.</li>
                <li><strong>La data del ricontrollo</strong>, albero per albero.</li>
                <li><strong>La relazione tecnica sottoscritta</strong>, con numero di protocollo e data di emissione.</li>
                <li><strong>L'elenco degli alberi per cui servono approfondimenti strumentali</strong>, quando l'esame visivo non basta.</li>
            </ul>
            <p class="nota" style="margin-top: var(--s4);">
                Nessuna valutazione elimina il rischio che un albero ceda. Quello che la
                documentazione dimostra è che il rischio è stato osservato, valutato da un
                tecnico e gestito con una decisione tracciabile.
            </p>
        </div>
    </div>
</section>

{{-- Cinque parole che nei capitolati si confondono --}}
<section class="sezione sezione-bianca filo-sopra" aria-labelledby="titolo-parole">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Per intendersi</p>
            <h2 id="titolo-parole">Parole che non sono sinonimi</h2>
        </div>
        <dl class="definizioni" style="margin-top: var(--s5);">
            <div>
                <dt>Censimento</dt>
                <dd>L'inventario del patrimonio: dove sono gli alberi, di che specie, con quali misure e in quali condizioni al momento del rilievo. Non esprime un giudizio di stabilità.</dd>
            </div>
            <div>
                <dt>Controllo visivo</dt>
                <dd>L'osservazione periodica dello stato di un albero, per accorgersi di cambiamenti. Segnala che cosa merita una valutazione.</dd>
            </div>
            <div>
                <dt>Valutazione di stabilità</dt>
                <dd>L'esame condotto con metodo VTA da un tecnico, che attribuisce una classe, indica interventi e urgenza e fissa il ricontrollo. È documentata e sottoscritta.</dd>
            </div>
            <div>
                <dt>Indagine strumentale</dt>
                <dd>Un approfondimento con strumenti, richiesto quando l'esame visivo non basta a decidere. Ha tempi, costi e competenze propri.</dd>
            </div>
            <div>
                <dt>Certificazione</dt>
                <dd>Un'attestazione formale di conformità a una norma o a uno schema, rilasciata da chi ne ha titolo. Una valutazione di stabilità non è una certificazione, e non va presentata come tale.</dd>
            </div>
        </dl>
    </div>
</section>

<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-scadenzario">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Dopo la consegna</p>
            <h2 id="titolo-scadenzario">Lo scadenzario e la tracciabilità</h2>
        </div>
        <div class="c-5-13 prosa">
            <p>
                Le verifiche non si esauriscono con la consegna: ogni albero ha la sua data di
                controllo successivo, che dipende dalla classe attribuita. Il Comune riceve
                l'elenco delle scadenze e sa in anticipo che cosa va rivisto e quando, invece di
                accorgersene dopo.
            </p>
            <p>
                Ogni ricontrollo si aggiunge alla storia dell'albero senza cancellare quello
                che c'era: chi legge la scheda vede la sequenza delle valutazioni, chi le ha
                fatte e che cosa è cambiato da una all'altra. È la tracciabilità che serve
                quando bisogna ricostruire una decisione a distanza di anni.
            </p>
        </div>
    </div>
</section>

@include('sito.parti.chiusura', [
    'titolo' => 'Ci sono alberi che vi preoccupano?',
    'testo' => 'Di solito si parte da quelli: le alberature stradali più vecchie, i viali delle scuole, i parchi più frequentati. Un sopralluogo dice quanti sono e con che urgenza vanno guardati.',
])

@endsection
