@extends('sito.layout')

@section('contenuto')

<section class="sezione" aria-labelledby="titolo-pagina">
    <div class="contenitore griglia">
        <div class="c-1-9">
            <p class="occhiello">Censimento</p>
            <h1 id="titolo-pagina">L'inventario di quello che c'è</h1>
        </div>
        <div class="c-1-9">
            <p class="guida">
                Dove si trova ogni albero, di che specie è, quanto è grande, in che condizioni si
                trova. Senza questo, ogni decisione sul verde pubblico si prende a memoria.
            </p>
        </div>
    </div>
</section>

{{-- Le quattro fasi: qui il numero e' un dato, perche' la sequenza e' vera --}}
<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-fasi">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Come lavoriamo</p>
            <h2 id="titolo-fasi">Quattro fasi, in quest'ordine</h2>
        </div>
        <ol class="fasi">
            <li class="fase">
                <span class="fase-numero" aria-hidden="true">01</span>
                <h3>Definizione dell'ambito</h3>
                <p>
                    Con l'ufficio tecnico si stabilisce che cosa rilevare: alberature stradali,
                    parchi, aree scolastiche, cimiteri. Si concorda il livello di dettaglio, che
                    determina tempi e costo, e il tracciato con cui i dati saranno consegnati.
                </p>
            </li>
            <li class="fase">
                <span class="fase-numero" aria-hidden="true">02</span>
                <h3>Rilievo in campo</h3>
                <p>
                    Ogni albero viene posizionato con il GPS e schedato sul posto, con
                    fotografia. Il rilievo funziona anche senza copertura telefonica: i dati si
                    allineano quando il segnale torna. Nei parchi e nelle aree rurali non è un
                    dettaglio.
                </p>
            </li>
            <li class="fase">
                <span class="fase-numero" aria-hidden="true">03</span>
                <h3>Cartellinatura</h3>
                <p>
                    Ogni albero riceve un cartellino con un numero univoco e un codice QR. La
                    numerazione è progressiva per ente e non viene mai riassegnata: un cartellino
                    applicato resta valido.
                </p>
            </li>
            <li class="fase">
                <span class="fase-numero" aria-hidden="true">04</span>
                <h3>Verifica e consegna</h3>
                <p>
                    I dati vengono controllati, e il Comune riceve l'archivio completo con le
                    fotografie, nel tracciato concordato con il capitolato.
                </p>
            </li>
        </ol>
    </div>
</section>

{{-- Che cosa puo' contenere una scheda: un elenco, non una promessa --}}
<section class="sezione" aria-labelledby="titolo-campi">
    <div class="contenitore griglia">
        <div class="c-1-4">
            <p class="occhiello">Che cosa viene registrato</p>
            <h2 id="titolo-campi">Per ogni albero, secondo il capitolato</h2>
        </div>
        <div class="c-5-13">
            <p class="guida">
                Queste sono le informazioni che una scheda può registrare. Non sono tutte sempre
                previste: il livello di dettaglio viene definito in base al capitolato e alle
                esigenze dell'ente, prima di iniziare il rilievo.
            </p>
            <ul class="campi" style="margin-top: var(--s4);">
                <li>Codice univoco</li>
                <li>Posizione geografica</li>
                <li>Fotografia</li>
                <li>Nome botanico</li>
                <li>Nome comune</li>
                <li>Altezza</li>
                <li>Circonferenza</li>
                <li>Diametro</li>
                <li>Ampiezza della chioma</li>
                <li>Area di appartenenza</li>
                <li>Stato vegetativo</li>
                <li>Condizioni osservate</li>
                <li>Danni e anomalie</li>
                <li>Interventi</li>
                <li>Data del controllo</li>
                <li>Data del ricontrollo</li>
                <li>Documenti collegati</li>
            </ul>
            <p class="nota" style="margin-top: var(--s4);">
                Le misure derivate, come superfici e lunghezze, si calcolano dalla geometria
                rilevata: non si copiano a mano. Un dato non rilevato resta vuoto e non diventa
                uno zero.
            </p>
        </div>
    </div>
</section>

<section class="sezione sezione-bianca filo-sopra" aria-labelledby="titolo-misure">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Le misure in campo</p>
            <h2 id="titolo-misure">Quattro grandezze, prese allo stesso modo su ogni pianta</h2>
            <p class="guida">Così due rilievi a distanza di anni si possono confrontare.</p>
        </div>
        <div style="margin-top: var(--s5);">
            @include('sito.parti.quote')
        </div>
    </div>
</section>

@include('sito.parti.chiusura', [
    'titolo' => 'Stimiamo insieme quanto verde c\'è da censire.',
    'testo' => 'È la prima domanda a cui rispondere: da lì escono l\'ambito, i tempi e il costo. Un sopralluogo basta per avere numeri invece di stime.',
])

@endsection
