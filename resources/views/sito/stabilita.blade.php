@extends('sito.layout')

@section('titolo', 'Valutazione di stabilità degli alberi (VTA)')
@section('descrizione', 'Valutazione di stabilità con metodo VTA: scheda per ogni albero, classe di propensione al cedimento, difetti documentati, interventi e urgenza, data della prossima verifica.')

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Stabilità</span>
        <h1 style="max-width: 22ch;">Dimostrare di aver vigilato</h1>
        <p class="guida" style="margin-top: var(--s3);">
            Un albero in area pubblica che cade è una responsabilità del proprietario. La
            valutazione di stabilità con metodo VTA è lo strumento tecnico con cui il Comune
            documenta di aver vigilato: non elimina il rischio, ma dimostra che è stato
            valutato da un tecnico e gestito.
        </p>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <span class="occhiello">Cosa consegniamo</span>
        <h2 style="max-width: 24ch;">Documenti che reggono a un controllo</h2>

        <ul class="elenco" style="margin-top: var(--s4); max-width: var(--misura);">
            <li>
                <strong>Scheda di valutazione per ogni albero esaminato</strong>, con classe di
                propensione al cedimento e difetti rilevati, ciascuno con la sua fotografia.
            </li>
            <li>
                <strong>Interventi necessari e loro urgenza</strong>: che cosa fare, entro quando.
            </li>
            <li>
                <strong>Data della prossima verifica</strong>, albero per albero.
            </li>
            <li>
                <strong>Relazione tecnica firmata</strong>, con numero di protocollo e data di
                emissione.
            </li>
        </ul>

        @if (config('sito.perizie.firmatario') || config('sito.perizie.titolo'))
            <p class="nota" style="margin-top: var(--s4);">
                Le perizie sono firmate da
                {{ trim(config('sito.perizie.firmatario').' '.config('sito.perizie.titolo')) }}.
            </p>
        @endif
    </div>
</section>

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Dopo la consegna</span>
        <h2 style="max-width: 24ch;">Lo scadenzario</h2>
        <p class="prosa" style="margin-top: var(--s3);">
            Le verifiche non si esauriscono con la consegna: ogni albero ha la sua data di
            controllo successivo, che dipende dalla classe assegnata. Il Comune riceve l'elenco
            delle scadenze e sa in anticipo che cosa va rivisto e quando, invece di accorgersene
            dopo.
        </p>
        <p class="prosa" style="margin-top: var(--s3);">
            Nel programma di gestione ogni scadenza diventa un lavoro programmato: la data
            prescritta dal tecnico entra in agenda, con l'albero collegato e la valutazione
            richiamata. È il modo di non perdere per strada un ricontrollo previsto tre anni
            prima.
        </p>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <h2 style="max-width: 24ch;">Ci sono alberi che vi preoccupano?</h2>
        <p class="guida" style="margin-top: var(--s3);">
            Di solito si parte da quelli: le alberature stradali più vecchie, i viali delle
            scuole, i parchi più frequentati. Un sopralluogo dice quanti sono e con che urgenza
            vanno guardati.
        </p>
        <div class="inviti">
            <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
        </div>
    </div>
</section>

@endsection
