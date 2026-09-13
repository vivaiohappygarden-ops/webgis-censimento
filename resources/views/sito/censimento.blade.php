@extends('sito.layout')

@section('titolo', 'Il censimento del verde')
@section('descrizione', 'Come si svolge un censimento del verde urbano: definizione dell\'ambito, rilievo in campo con GPS anche senza copertura telefonica, cartellinatura con QR, verifica e consegna.')

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Il censimento</span>
        <h1 style="max-width: 22ch;">L'inventario di quello che c'è</h1>
        <p class="guida" style="margin-top: var(--s3);">
            Dove si trova ogni albero, di che specie è, quanto è grande, in che condizioni si
            trova. Senza questo, ogni decisione sul verde pubblico si prende a memoria.
        </p>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <span class="occhiello">Come lavoriamo</span>
        <h2 style="max-width: 24ch;">Quattro fasi, in quest'ordine</h2>

        <ol class="passi" style="margin-top: var(--s5); max-width: 62ch;">
            <li class="passo">
                <div>
                    <h3>Definizione dell'ambito</h3>
                    <p>
                        Con l'ufficio tecnico si stabilisce cosa rilevare: alberature stradali,
                        parchi, aree scolastiche, cimiteri. Si concorda il livello di dettaglio,
                        che determina tempi e costo.
                    </p>
                </div>
            </li>
            <li class="passo">
                <div>
                    <h3>Rilievo in campo</h3>
                    <p>
                        Ogni elemento viene posizionato con GPS e schedato sul posto, con
                        fotografia. Il rilievo funziona <strong>anche senza copertura
                        telefonica</strong>: i dati si allineano da soli quando il segnale
                        torna. Nelle aree rurali e nei parchi non è un dettaglio.
                    </p>
                </div>
            </li>
            <li class="passo">
                <div>
                    <h3>Cartellinatura</h3>
                    <p>
                        Ogni albero riceve un cartellino numerato con codice QR. La numerazione
                        è progressiva per Comune, con un prefisso dedicato, e non viene mai
                        riassegnata: un cartellino applicato resta valido.
                    </p>
                </div>
            </li>
            <li class="passo">
                <div>
                    <h3>Verifica e consegna</h3>
                    <p>
                        I dati vengono controllati, e il Comune riceve l'archivio completo con
                        le fotografie, nei formati previsti dai CAM.
                    </p>
                </div>
            </li>
        </ol>
    </div>
</section>

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Che cosa viene registrato</span>
        <h2 style="max-width: 24ch;">Per ogni albero</h2>

        <ul class="campi" style="margin-top: var(--s4); max-width: 760px;">
            <li>Specie e cultivar</li>
            <li>Circonferenza del tronco</li>
            <li>Altezza</li>
            <li>Ampiezza della chioma</li>
            <li>Data del rilievo</li>
            <li>Area e località</li>
            <li>Coordinate</li>
            <li>Stato fitosanitario</li>
            <li>Difetti rilevati</li>
            <li>Interventi già eseguiti</li>
            <li>Vincoli che gravano sull'area</li>
            <li>Numero del cartellino</li>
        </ul>

        <p class="nota" style="margin-top: var(--s4);">
            Le misure derivate — superfici, lunghezze, perimetri — si calcolano dalla geometria
            rilevata: non si copiano a mano e non si sbagliano.
        </p>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <h2 style="max-width: 22ch;">Quanto verde c'è da censire?</h2>
        <p class="guida" style="margin-top: var(--s3);">
            È la prima domanda a cui rispondere insieme: da lì escono l'ambito, i tempi e il
            costo. Un sopralluogo basta per avere numeri invece di stime.
        </p>
        <div class="inviti">
            <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
        </div>
    </div>
</section>

@endsection
