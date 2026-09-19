@extends('sito.layout')

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">Conformità ai capitolati e ai CAM</p>
            <h1 id="titolo-pagina">I dati nel formato che il capitolato chiede</h1>
        </div>
        <div class="c-1-9">
            <p class="guida">
                Il tracciato di consegna viene adeguato alle specifiche tecniche del capitolato
                e ai requisiti applicabili alla singola procedura. Non esiste un unico formato
                valido per tutti i capitolati: esiste quello giusto per il vostro.
            </p>
        </div>
    </div>
</section>

{{-- Il principio, in grande --}}
<section class="sezione banda su-scuro" aria-labelledby="titolo-principio">
    @include('sito.parti.linee')
    <div class="contenitore griglia">
        <div class="c-1-8">
            <p class="occhiello">Il principio</p>
            <h2 id="titolo-principio" class="banda-frase">Il dato è del Comune<span class="punto">.</span></h2>
        </div>
        <div class="c-9-13" style="align-self: end;">
            <p class="guida">
                Formati aperti, nessun archivio chiuso, nessun vincolo verso di noi. Alla
                consegna l'ente ha tutto quello che ha pagato, e continua a usarlo anche
                quando il servizio finisce.
            </p>
        </div>
    </div>
</section>

<section class="sezione" aria-labelledby="titolo-consegna">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Il pacchetto di consegna</p>
            <h2 id="titolo-consegna">Che cosa c'è dentro</h2>
        </div>
        <div class="c-5-13">
            <p class="guida">
                I dati vengono organizzati e consegnati in modo utilizzabile dal Comune e
                coerente con quanto richiesto dal capitolato. Dove il capitolato richiama i
                Criteri Ambientali Minimi (CAM) per il verde pubblico, la consegna ne segue le
                indicazioni sui dati.
            </p>
            <ul class="campi" style="margin-top: var(--s4);">
                <li>Struttura ordinata del dato</li>
                <li>Georeferenziazione di ogni elemento</li>
                <li>Identificativi univoci</li>
                <li>Documentazione fotografica collegata</li>
                <li>Interoperabilità con i sistemi dell'ente</li>
                <li>Formati aperti</li>
                <li>Esportazione completa</li>
                <li>Aggiornabilità nel tempo</li>
                <li>Proprietà del dato all'ente</li>
                <li>Consegna completa, non un estratto</li>
                <li>Continuità d'uso al termine del servizio</li>
            </ul>
        </div>
    </div>
</section>

<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-perche">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Perché conta</p>
            <h2 id="titolo-perche">Un file generico fa rifare il lavoro all'ufficio</h2>
        </div>
        <div class="c-5-13 prosa">
            <p>
                Un capitolato che chiede un tracciato preciso e riceve una tabella qualsiasi
                costringe l'ufficio a rimetterci mano: rinominare campi, riproiettare
                coordinate, ricostruire codifiche. Consegnare già nel formato richiesto
                significa che il dato entra direttamente nei sistemi del Comune.
            </p>
            <p>
                Per questo il tracciato si concorda prima del rilievo, nella definizione
                dell'ambito, e si verifica prima della consegna: la conformità non è una
                dichiarazione, è un controllo fatto sul capitolato della singola procedura.
            </p>
            <p class="nota">
                Non dichiariamo una conformità automatica o assoluta, e non citiamo norme che non
                abbiamo verificato sul vostro capitolato. Se la bozza c'è già, la leggiamo
                insieme.
            </p>
        </div>
    </div>
</section>

@include('sito.parti.chiusura', [
    'titolo' => 'Avete un capitolato da scrivere?',
    'testo' => 'Possiamo leggere la bozza e dire quali voci di consegna hanno senso e quali no. Evita di scrivere requisiti che poi nessuno può rispettare.',
])

@endsection
