@extends('sito.layout')

@section('titolo', 'Il portale pubblico per i cittadini')
@section('descrizione', 'Ogni Comune riceve un portale pubblico con il proprio stemma: mappa del verde, scheda di ogni albero, ricerca per numero di cartellino, codice QR sulla pianta. Senza cookie.')

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Per i cittadini</span>
        <h1 style="max-width: 22ch;">Il portale pubblico del Comune</h1>
        <p class="guida" style="margin-top: var(--s3);">
            Ogni Comune che lo richiede riceve un proprio sito pubblico, all'indirizzo
            <span class="dominio">nomedelcomune.{{ config('sito.base_host') ?: 'censimentoalberature.it' }}</span>,
            con lo stemma e i colori dell'ente.
        </p>
        @if (config('sito.portale_esempio.url'))
            <div class="inviti">
                <a class="invito invito-secondario" href="{{ config('sito.portale_esempio.url') }}" rel="noopener">
                    Apri un portale vero
                </a>
            </div>
        @endif
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <span class="occhiello">Cosa ci trova il cittadino</span>
        <h2 style="max-width: 24ch;">Quattro cose, tutte dal telefono</h2>

        <div class="griglia griglia-2" style="margin-top: var(--s5);">
            <div class="blocco">
                <h3>La mappa del verde</h3>
                <p>Ogni elemento censito al suo posto, con il colore del proprio stato.</p>
            </div>
            <div class="blocco">
                <h3>La scheda del singolo albero</h3>
                <p>Specie, misure, data del rilievo, interventi eseguiti.</p>
            </div>
            <div class="blocco">
                <h3>La ricerca per numero</h3>
                <p>Si legge il numero sul cartellino, si digita, si apre la scheda.</p>
            </div>
            <div class="blocco">
                <h3>Il codice QR</h3>
                <p>Inquadrando il cartellino la scheda si apre da sola, senza digitare niente.</p>
            </div>
        </div>
    </div>
</section>

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Cosa decide il Comune</span>
        <h2 style="max-width: 26ch;">Non esce niente in automatico</h2>
        <p class="prosa" style="margin-top: var(--s3);">
            Ogni intervento, ogni perizia e ogni vincolo viene pubblicato <strong>solo se il
            Comune lo decide</strong>. I singoli alberi si possono nascondere. Le note interne
            e le fotografie dei difetti non escono mai.
        </p>
        <div class="scheda scheda-rilievo" style="margin-top: var(--s5); max-width: var(--misura);">
            <h3>Nessun cookie, nessun banner</h3>
            <p style="margin-top: var(--s2);">
                Il portale non usa cookie e non raccoglie statistiche sui visitatori: non c'è
                niente da far accettare a chi lo apre, e niente da dichiarare. Anche i caratteri
                tipografici sono ospitati sul nostro server, quindi il browser del cittadino non
                contatta nessun altro.
            </p>
        </div>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <span class="occhiello">Se il Comune vuole</span>
        <h2 style="max-width: 26ch;">I benefici ambientali, dichiarati come stime</h2>
        <p class="prosa" style="margin-top: var(--s3);">
            Su richiesta il portale mostra quanta anidride carbonica ha immagazzinato il
            patrimonio arboreo, e quanto ossigeno, polveri sottili e pioggia trattiene in un
            anno. Sono <strong>stime dichiarate come tali</strong>, con il metodo di calcolo
            scritto in chiaro nella pagina e il numero di alberi su cui è stato applicato: si
            possono comunicare senza esporsi.
        </p>
        <p class="prosa" style="margin-top: var(--s3);">
            Ogni gruppo di stime si accende separatamente, e di partenza sono tutte spente.
        </p>
    </div>
</section>

<section class="sezione">
    <div class="contenitore">
        <h2 style="max-width: 24ch;">Il portale è compreso nel censimento</h2>
        <p class="guida" style="margin-top: var(--s3);">
            Non è un modulo da acquistare a parte: nasce dagli stessi dati del rilievo, e si
            accende quando il Comune è pronto a mostrarli.
        </p>
        <div class="inviti">
            <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
        </div>
    </div>
</section>

@endsection
