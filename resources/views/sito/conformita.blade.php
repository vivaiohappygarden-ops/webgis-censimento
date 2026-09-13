@extends('sito.layout')

@section('titolo', 'Conformità CAM e consegna dei dati')
@section('descrizione', 'Consegna nei formati previsti dai Capitolati Ambientali Minimi: struttura dei campi, codifiche del catalogo ministeriale, sistema di riferimento nazionale, formati aperti.')

@section('contenuto')

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Conformità</span>
        <h1 style="max-width: 24ch;">I dati nel formato che il capitolato chiede</h1>
        <p class="guida" style="margin-top: var(--s3);">
            La consegna segue i Capitolati Ambientali Minimi per il verde pubblico: struttura
            dei campi, codifiche del catalogo ministeriale, sistema di riferimento nazionale.
        </p>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <span class="occhiello">Il pacchetto di consegna</span>
        <h2 style="max-width: 24ch;">Che cosa contiene</h2>

        <ul class="elenco" style="margin-top: var(--s4); max-width: var(--misura);">
            <li><strong>Dati geografici in formato aperto</strong>: GeoJSON e shapefile, per macro-categoria.</li>
            <li><strong>Codifiche del catalogo ministeriale</strong>, non nomi inventati da noi.</li>
            <li><strong>Sistema di riferimento nazionale</strong> RDN2008, nel fuso corretto.</li>
            <li><strong>Le fotografie collegate</strong> a ciascun elemento.</li>
            <li><strong>Un documento che descrive il pacchetto</strong>: cosa contiene, con che criteri, a che data.</li>
        </ul>
    </div>
</section>

<section class="sezione">
    <div class="contenitore">
        <span class="occhiello">Perché conta</span>
        <h2 style="max-width: 26ch;">Un file generico fa rifare il lavoro all'ufficio</h2>
        <p class="prosa" style="margin-top: var(--s3);">
            Un capitolato che chiede i CAM e riceve una tabella qualsiasi costringe l'ufficio a
            rimetterci mano: rinominare campi, riproiettare coordinate, ricostruire codifiche.
            Consegnare già nel formato giusto significa che il dato entra direttamente nei
            sistemi del Comune e nelle comunicazioni alla Regione.
        </p>

        <div class="scheda scheda-rilievo" style="margin-top: var(--s5); max-width: var(--misura);">
            <h3>Il dato è del Comune</h3>
            <p style="margin-top: var(--s2);">
                Formati aperti, nessun blocco, nessun riscatto. Se il Comune cambia fornitore,
                porta con sé tutto quello che ha pagato. È la ragione per cui consegniamo in
                standard e non in un archivio nostro.
            </p>
        </div>
    </div>
</section>

<section class="sezione sezione-alt">
    <div class="contenitore">
        <h2 style="max-width: 26ch;">Avete un capitolato da scrivere?</h2>
        <p class="guida" style="margin-top: var(--s3);">
            Possiamo leggere la bozza e dire quali voci di consegna hanno senso e quali no.
            Non costa niente e evita di scrivere requisiti che poi nessuno può rispettare.
        </p>
        <div class="inviti">
            <a class="invito" href="{{ $u('contatti') }}">Scrivici</a>
        </div>
    </div>
</section>

@endsection
