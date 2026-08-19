@extends('portale.layout')

@section('titolo', 'Privacy e note legali')

@push('stile')
<style>
    h1 { font-size: 24px; margin: 8px 0 16px; }
    h2 { font-size: 16px; margin: 22px 0 6px; }
    .testo { max-width: 70ch; }
    .testo p { margin: 0 0 12px; }
    .testo ul { margin: 0 0 12px; padding-left: 20px; }
    .testo li { margin-bottom: 4px; }
    .proprio { white-space: pre-line; }
    .mancante {
        background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12;
        border-radius: 10px; padding: 12px 16px; font-size: 14px;
    }
</style>
@endpush

@section('contenuto')
    <a class="indietro" href="{{ $portale->url('/') }}">&larr; Torna alla home</a>

    <h1>Privacy e note legali</h1>

    <div class="testo">
        <h2>Titolare del trattamento</h2>
        @if ($titolare)
            <p class="proprio">{{ $titolare }}</p>
        @else
            <p class="mancante">
                Il titolare del trattamento non è ancora stato indicato. Va compilato dall'ente
                prima di rendere pubblico questo portale.
            </p>
        @endif

        <h2>Dati trattati da questo sito</h2>
        <p>
            Questo portale pubblica il censimento del patrimonio arboreo del territorio: specie,
            misure, posizione e interventi eseguiti sugli alberi. Sono dati sul verde pubblico,
            non su persone.
        </p>
        <ul>
            <li>Il sito <strong>non usa cookie</strong> e non apre alcuna sessione sul dispositivo di chi lo consulta.</li>
            <li>Il sito <strong>non usa strumenti di statistica o di profilazione</strong> di terze parti.</li>
            <li>Le fotografie pubblicate vengono rigenerate dal server prima di essere mostrate: i dati
                nascosti nel file originale, comprese le coordinate satellitari del telefono che ha
                scattato la foto, non vengono pubblicati.</li>
            <li>Come ogni sito, il server registra negli archivi tecnici l'indirizzo di rete di chi
                consulta le pagine, per sicurezza e diagnosi dei guasti.</li>
            <li>Le mappe di sfondo sono fornite da servizi esterni: aprendo la mappa il browser
                contatta direttamente quei servizi.</li>
        </ul>

        @if ($testoEnte)
            <h2>Informativa dell'ente</h2>
            <p class="proprio">{{ $testoEnte }}</p>
        @endif

        <h2>Esattezza dei dati</h2>
        <p>
            I dati provengono dai rilievi in campo e vengono aggiornati nel tempo. I valori
            contrassegnati come stimati sono calcolati con modelli dichiarati sulla pagina e non
            sostituiscono una misura diretta. Per segnalare un errore si può usare il pulsante di
            segnalazione presente sulla scheda di ogni elemento.
        </p>

        @if ($accessibilita)
            <h2>Accessibilità</h2>
            <p>
                <a href="{{ $accessibilita }}" target="_blank" rel="noopener">Dichiarazione di accessibilità</a>
            </p>
        @endif
    </div>
@endsection
