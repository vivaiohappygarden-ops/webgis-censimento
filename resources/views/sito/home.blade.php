@extends('sito.layout')

@php
    $portaleEsempio = \App\Support\SitoDati::gruppo('portale_esempio');
@endphp

@section('contenuto')

{{-- Apertura: il messaggio a sinistra, a destra la tavola (mappa stilizzata e
     targhetta di un albero). Nessuna fotografia: la composizione tecnica fa
     il lavoro dell'immagine, e non aspetta una foto per sembrare finita. --}}
<section class="apertura su-scuro" aria-labelledby="titolo-pagina">
    @include('sito.parti.linee')
    <div class="contenitore apertura-griglia">
        <div>
            <p class="occhiello">Per Comuni ed enti pubblici</p>
            <h1 id="titolo-pagina">Ogni albero identificato. Ogni controllo documentato.</h1>
            <p class="guida">
                Censiamo il patrimonio arboreo, organizziamo le informazioni tecniche e
                consegniamo al Comune dati consultabili, aggiornabili e pronti per la
                gestione futura.
            </p>
            <div class="inviti">
                <a class="invito" href="{{ $u('contatti') }}">Richiedi un sopralluogo</a>
            </div>
        </div>
        <div class="tavola">
            @include('sito.parti.mappa', ['tono' => 'scuro'])
            @include('sito.parti.targhetta')
        </div>
    </div>
    {{-- Il cartiglio: quattro fatti tecnici, tutti veri --}}
    <div class="cartiglio">
        <div class="contenitore">
            <dl>
                <div><dt>Sistema di riferimento</dt><dd>RDN2008, il sistema geodetico nazionale</dd></div>
                <div><dt>Codifiche</dt><dd>Catalogo Modello Dati v2.1, 387 codici</dd></div>
                <div><dt>Formati di consegna</dt><dd>Aperti ed esportabili, secondo il capitolato</dd></div>
                <div><dt>Portale pubblico</dt><dd>Senza cookie e senza profilazione</dd></div>
            </dl>
        </div>
    </div>
</section>

{{-- Il messaggio centrale, messo in grande --}}
<section class="sezione" aria-labelledby="titolo-messaggio">
    <div class="contenitore griglia">
        <div class="c-1-4"><p class="occhiello">Il punto di partenza</p></div>
        <div class="c-4-13">
            <h2 id="titolo-messaggio" class="affermazione">Dal censimento alla consultazione pubblica: ogni albero diventa un dato verificabile, aggiornabile e di proprietà del Comune.</h2>
        </div>
    </div>
</section>

{{-- I tre principi, in colonne sfalsate --}}
<section class="sezione sezione-avorio filo-sopra" aria-labelledby="titolo-principi">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Tre principi</p>
            <h2 id="titolo-principi">Quello che non cambia, da un capitolato all'altro</h2>
        </div>
        <div class="principi" style="margin-top: var(--s5);">
            <article class="principio">
                <h3>Il dato resta al Comune</h3>
                <p>
                    Alla consegna l'ente riceve i propri dati in formati aperti ed esportabili,
                    non un archivio chiuso dentro un programma. Se domani cambia fornitore, il
                    lavoro fatto non si perde.
                </p>
            </article>
            <article class="principio">
                <h3>Ogni albero ha una storia</h3>
                <p>
                    Specie, misure, condizioni osservate, interventi eseguiti con la loro data,
                    prossima verifica prevista: ogni scheda conserva la sequenza dei controlli.
                    È quello che serve quando bisogna dimostrare di aver vigilato.
                </p>
            </article>
            <article class="principio">
                <h3>I cittadini possono consultarlo</h3>
                <p>
                    Un portale pubblico per ogni Comune: mappa del patrimonio arboreo, scheda di
                    ogni albero, ricerca per numero di cartellino. Il Comune sceglie che cosa
                    pubblicare; i cittadini lo consultano dal telefono.
                </p>
            </article>
        </div>
    </div>
</section>

{{-- Le quattro consegne: righe di un registro, nell'ordine in cui arrivano --}}
<section class="sezione" aria-labelledby="titolo-consegne">
    <div class="contenitore">
        <div class="testa">
            <p class="occhiello">Che cosa riceve il Comune</p>
            <h2 id="titolo-consegne">Quattro consegne, nell'ordine in cui arrivano</h2>
        </div>
        <ol class="registro">
            <li class="voce">
                <span class="voce-indice" aria-hidden="true">1</span>
                <h3>Censimento e cartellinatura</h3>
                <p>
                    Ogni albero posizionato, schedato sul posto e identificato con un cartellino
                    numerato a codice QR. Il livello di dettaglio si definisce con l'ente,
                    secondo il capitolato.
                </p>
                <a class="piu" href="{{ $u('censimento') }}">Come si svolge il rilievo</a>
            </li>
            <li class="voce">
                <span class="voce-indice" aria-hidden="true">2</span>
                <h3>Valutazioni di stabilità</h3>
                <p>
                    Valutazione visiva VTA con le osservazioni documentate, gli eventuali
                    approfondimenti strumentali, la documentazione sottoscritta e la data del
                    ricontrollo.
                </p>
                <a class="piu" href="{{ $u('stabilita-vta') }}">Che cosa viene documentato</a>
            </li>
            <li class="voce">
                <span class="voce-indice" aria-hidden="true">3</span>
                <h3>Portale pubblico</h3>
                <p>
                    Il patrimonio arboreo consultabile dai cittadini: mappa, scheda di ogni
                    albero, ricerca per numero del cartellino e codice QR. Senza cookie e senza
                    profilazione.
                </p>
                <a class="piu" href="{{ $u('portale-cittadini') }}">Che cosa vede il cittadino</a>
            </li>
            <li class="voce">
                <span class="voce-indice" aria-hidden="true">4</span>
                <h3>Consegna dei dati conforme al capitolato</h3>
                <p>
                    Dati georeferenziati, identificativi univoci, fotografie collegate, formati
                    aperti ed esportabili. Il tracciato si adegua alle specifiche della singola
                    procedura.
                </p>
                <a class="piu" href="{{ $u('conformita-cam') }}">Come avviene la consegna</a>
            </li>
        </ol>
    </div>
</section>

{{-- La prova: un portale vero, solo se ne e' stato indicato uno (con il
     consenso del Comune). Senza indirizzo l'intera sezione non esiste. --}}
@if (isset($portaleEsempio['url']))
    <section class="sezione sezione-scura su-scuro" aria-labelledby="titolo-esempio">
        <div class="contenitore griglia">
            <div class="c-1-8">
                <p class="occhiello">Da guardare</p>
                <h2 id="titolo-esempio">Un portale vero, non uno schermo finto</h2>
                <p class="guida">
                    @if (isset($portaleEsempio['nome']))
                        Il portale pubblico {{ $portaleEsempio['nome'] }} è online:
                    @else
                        Un portale pubblico che abbiamo consegnato è online:
                    @endif
                    si apre dal telefono, si cerca un numero di cartellino, si legge la scheda
                    dell'albero.
                </p>
            </div>
            <div class="c-9-13 inviti" style="align-self: center;">
                <a class="invito invito-secondario" href="{{ $portaleEsempio['url'] }}" rel="noopener">Apri il portale</a>
            </div>
        </div>
    </section>
@endif

@include('sito.parti.chiusura', [
    'titolo' => 'Parliamone su un caso concreto',
    'testo' => 'Il preventivo dipende da quanto verde c\'è e dal dettaglio richiesto. Il modo più rapido per averlo è un sopralluogo: si guarda il patrimonio, si concorda l\'ambito, e tempi e costi diventano numeri.',
])

@endsection
