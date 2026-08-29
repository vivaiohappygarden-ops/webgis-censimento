@extends('portale.layout')

@section('titolo', 'Privacy e note legali')

@push('stile')
<style>
    /* Una pagina di sole parole: la colonna si stringe alla misura di lettura
       e i corpi sono quelli del portale, non misure proprie di questa pagina */
    .testo { max-width: 70ch; }
    .testo h2 { margin: var(--s-5) 0 var(--s-1); }
    .testo p { margin: 0 0 var(--s-2); }
    .testo ul { margin: 0 0 var(--s-2); padding-left: var(--s-3); }
    .testo li { margin-bottom: 6px; }
    /* Il testo scritto dall'ente arriva com'è stato composto, a capo compresi */
    .proprio { white-space: pre-line; }
    /* Manca un dato che l'ente deve mettere prima di aprire il portale: non è
       un errore del programma, è una cosa da fare. Il segno è il filetto
       d'oro dei richiami, non un colore d'allarme preso da fuori tavolozza. */
    .mancante {
        padding: var(--s-2) var(--s-3);
        border-left: 3px solid var(--oro-scuro);
        background: var(--avorio-2);
        color: var(--inchiostro);
        font-size: var(--t-etichetta);
        line-height: 1.62;
    }
</style>
@endpush

@section('contenuto')
    <a class="indietro" href="{{ $portale->url('/') }}">&larr; Torna alla home</a>

    <h1 class="sc-h2">Privacy e note legali</h1>

    <div class="testo">
        <h2 class="sc-h3">Titolare del trattamento</h2>
        @if ($titolare)
            <p class="proprio">{{ $titolare }}</p>
        @else
            <p class="mancante">
                Il titolare del trattamento non è ancora stato indicato. Va compilato dall'ente
                prima di rendere pubblico questo portale.
            </p>
        @endif

        <h2 class="sc-h3">Dati trattati da questo sito</h2>
        <p>
            Questo portale pubblica il censimento del patrimonio arboreo del territorio: specie,
            misure, posizione e interventi eseguiti sugli alberi. Sono dati sul verde pubblico,
            non su persone.
        </p>
        <ul>
            <li>Il sito <strong>non usa cookie</strong> e non apre alcuna sessione sul dispositivo di chi lo consulta.</li>
            <li>Il sito <strong>non usa strumenti di statistica o di profilazione</strong> di terze parti.</li>
            <li>I caratteri con cui è composto sono ospitati su questo stesso server: nessuna pagina
                chiede niente a un fornitore esterno.</li>
            <li>Le fotografie pubblicate vengono rigenerate dal server prima di essere mostrate: i dati
                nascosti nel file originale, comprese le coordinate satellitari del telefono che ha
                scattato la foto, non vengono pubblicati.</li>
            <li>Come ogni sito, il server registra negli archivi tecnici l'indirizzo di rete di chi
                consulta le pagine, per sicurezza e diagnosi dei guasti.</li>
            <li>Le mappe di sfondo sono fornite da servizi esterni: dove compare una carta il
                browser contatta direttamente quei servizi. Succede sulla pagina della mappa e
                anche in prima pagina, dove la carta si vede in piccolo.</li>
        </ul>

        @if ($testoEnte)
            <h2 class="sc-h3">Informativa dell'ente</h2>
            <p class="proprio">{{ $testoEnte }}</p>
        @endif

        <h2 class="sc-h3">Esattezza dei dati</h2>
        <p>
            I dati provengono dai rilievi in campo e vengono aggiornati nel tempo. I valori
            contrassegnati come stimati sono calcolati con modelli dichiarati sulla pagina e non
            sostituiscono una misura diretta. Per segnalare un errore si può usare il pulsante di
            segnalazione presente sulla scheda di ogni elemento.
        </p>

        @if ($accessibilita)
            <h2 class="sc-h3">Accessibilità</h2>
            <p>
                <a class="sc-collegamento" href="{{ $accessibilita }}" target="_blank" rel="noopener">Dichiarazione di accessibilità</a>
            </p>
        @endif
    </div>
@endsection
