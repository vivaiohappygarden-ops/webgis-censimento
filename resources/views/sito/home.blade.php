@extends('sito.layout')

@section('titolo', 'Censimento del verde urbano per i Comuni')
@section('descrizione', 'Rilievo in campo, schedatura di ogni albero, valutazione di stabilità VTA e consegna dei dati nei formati richiesti dai CAM. Ogni Comune riceve un portale pubblico per i cittadini.')

@section('contenuto')

{{-- Apertura: il titolo dice che cosa facciamo e per chi, in una riga.
     Nessuna immagine grande davanti: chi arriva qui sta cercando un
     fornitore, non un'atmosfera. --}}
<section class="sezione">
    <div class="contenitore apertura">
        <div>
            <span class="occhiello">Per i Comuni</span>
            <h1 style="max-width: 20ch;">Censimento e catasto del verde urbano</h1>
            <p class="guida" style="margin-top: var(--s3);">
                Rilievo in campo, schedatura di ogni albero, valutazione di stabilità e consegna
                dei dati nei formati richiesti dai Capitolati Ambientali Minimi. Ogni Comune
                riceve anche un portale pubblico dove i cittadini consultano il patrimonio
                arboreo del proprio territorio.
            </p>
            <div class="inviti">
                <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
            </div>
            @if (config('sito.azienda.territorio'))
                <p class="piccolo" style="margin-top: var(--s3);">{{ config('sito.azienda.territorio') }}</p>
            @endif
        </div>

        {{-- I fatti accanto al messaggio: chi legge sa subito che cosa
             comprende il lavoro, senza scorrere --}}
        <aside class="pannello" aria-label="Il servizio in breve">
            <h2>Il servizio, in breve</h2>
            <ol>
                <li><span>Definizione dell'ambito con l'ufficio tecnico</span></li>
                <li><span>Rilievo in campo con GPS, anche senza copertura telefonica</span></li>
                <li><span>Cartellinatura numerata con codice QR</span></li>
                <li><span>Valutazione di stabilità VTA, dove serve</span></li>
                <li><span>Consegna nei formati CAM e portale pubblico per i cittadini</span></li>
            </ol>
        </aside>
    </div>
</section>

{{-- I tre argomenti che contano per un ufficio tecnico, nell'ordine in cui
     contano: la proprietà del dato, la tracciabilità, la trasparenza. --}}
<section class="sezione sezione-alt">
    <div class="contenitore">
        <div class="griglia griglia-3">
            <div class="blocco">
                <h3>Il dato resta al Comune</h3>
                <p>
                    Alla consegna il Comune riceve i propri dati in formati aperti e standard,
                    non un archivio chiuso dentro un programma. Se domani cambia fornitore,
                    il lavoro fatto non si perde.
                </p>
            </div>
            <div class="blocco">
                <h3>Ogni albero ha una storia, non una riga</h3>
                <p>
                    Specie, misure, stato fitosanitario, vincoli che gravano sull'area,
                    interventi eseguiti con data e atto collegato, prossima verifica prevista.
                    È quello che serve quando bisogna dimostrare di aver vigilato.
                </p>
            </div>
            <div class="blocco">
                <h3>I cittadini lo vedono</h3>
                <p>
                    Un portale pubblico per ogni Comune, con lo stemma e i colori dell'ente:
                    mappa del verde, scheda di ogni albero, ricerca per numero di cartellino.
                    Trasparenza che si comunica, senza aprire un ufficio in più.
                </p>
            </div>
        </div>
    </div>
</section>

{{-- Che cosa si riceve, in concreto: la domanda vera di chi deve scrivere un
     capitolato o giustificare una spesa. --}}
<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Che cosa riceve il Comune</span>
        <h2 style="max-width: 24ch;">Quattro consegne, non una promessa</h2>

        <div class="griglia griglia-2" style="margin-top: var(--s5);">
            <div class="scheda">
                <h3>L'inventario del verde</h3>
                <p style="margin-top: var(--s2);">
                    Ogni elemento posizionato con GPS e schedato sul posto, con fotografia:
                    alberi, siepi, prati, aree gioco, arredo. Le misure derivate si calcolano
                    da sole e non si sbagliano.
                </p>
                <p style="margin-top: var(--s3);"><a class="piu" href="{{ $u('censimento') }}">Come lavoriamo in campo</a></p>
            </div>
            <div class="scheda">
                <h3>Le valutazioni di stabilità</h3>
                <p style="margin-top: var(--s2);">
                    Scheda VTA per ogni albero esaminato, classe di propensione al cedimento,
                    difetti documentati con fotografia, interventi e urgenza, data della
                    prossima verifica.
                </p>
                <p style="margin-top: var(--s3);"><a class="piu" href="{{ $u('stabilita') }}">Che cosa consegniamo</a></p>
            </div>
            <div class="scheda">
                <h3>I dati nel formato dei CAM</h3>
                <p style="margin-top: var(--s2);">
                    Struttura dei campi, codifiche del catalogo ministeriale, sistema di
                    riferimento nazionale. Geografici in formato aperto, fotografie collegate,
                    documento che descrive il pacchetto.
                </p>
                <p style="margin-top: var(--s3);"><a class="piu" href="{{ $u('conformita') }}">Conformità e consegna</a></p>
            </div>
            <div class="scheda">
                <h3>Il portale per i cittadini</h3>
                <p style="margin-top: var(--s2);">
                    Un sito pubblico con lo stemma dell'ente: mappa, scheda di ogni albero,
                    ricerca per numero di cartellino, codice QR sulla pianta. Senza cookie
                    e senza banner da accettare.
                </p>
                <p style="margin-top: var(--s3);"><a class="piu" href="{{ $u('portale') }}">Come funziona</a></p>
            </div>
        </div>
    </div>
</section>

{{-- La prova. Un portale vero che si apre e si tocca convince piu' di
     qualunque descrizione: compare solo se ne e' stato indicato uno, con il
     consenso del Comune. --}}
@if (config('sito.portale_esempio.url'))
    <section class="sezione sezione-alt">
        <div class="contenitore">
            <span class="occhiello">Da guardare</span>
            <h2 style="max-width: 22ch;">Un portale vero, non uno schermo finto</h2>
            <p class="guida" style="margin-top: var(--s3);">
                @if (config('sito.portale_esempio.nome'))
                    Il portale pubblico {{ config('sito.portale_esempio.nome') }} è online:
                @else
                    Un portale pubblico che abbiamo consegnato è online:
                @endif
                si apre dal telefono, si cerca un numero di cartellino, si legge la scheda
                dell'albero.
            </p>
            <div class="inviti">
                <a class="invito invito-secondario" href="{{ config('sito.portale_esempio.url') }}" rel="noopener">
                    Apri il portale
                </a>
            </div>
        </div>
    </section>
@endif

{{-- Chiusura: un solo invito, come da regola dei testi. --}}
<section class="sezione">
    <div class="contenitore">
        <h2 style="max-width: 22ch;">Parliamone su un caso concreto</h2>
        <p class="guida" style="margin-top: var(--s3);">
            Il preventivo dipende da quanto verde c'è e da che dettaglio serve. Il modo più
            rapido è un sopralluogo: si guarda il patrimonio, si concorda l'ambito, e i tempi
            e il costo diventano numeri invece che stime.
        </p>
        <div class="inviti">
            <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
        </div>
    </div>
</section>

@endsection
