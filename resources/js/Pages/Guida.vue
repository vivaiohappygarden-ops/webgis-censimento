<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const sections = [
    { id: 'in-breve', label: 'In breve' },
    { id: 'censimento', label: 'Il censimento' },
    { id: 'alberi', label: 'Alberi e VTA' },
    { id: 'lavori', label: 'I lavori' },
    { id: 'qualita', label: 'Qualità e ispezioni' },
    { id: 'segnalazioni', label: 'Le segnalazioni' },
    { id: 'campo', label: 'L\'app di campo' },
    { id: 'territorio', label: 'Territorio e catalogo' },
    { id: 'consegna', label: 'La consegna CAM' },
    { id: 'domande', label: 'Domande frequenti' },
];
const active = ref('in-breve');

function goTo(id) {
    active.value = id;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <Head title="Guida" />

    <AppLayout>
        <div class="p-6" data-test="guide">
            <div class="mb-4">
                <h1 class="text-xl font-semibold">Guida all'uso</h1>
                <p class="text-sm text-gray-500">Come si usa il sistema, spiegato in parole semplici. Per approfondire, ogni pagina ha comunque messaggi ed etichette che guidano il lavoro.</p>
            </div>

            <div class="flex gap-6">
                <nav class="w-56 shrink-0">
                    <ul class="sticky top-4 space-y-1 text-sm">
                        <li v-for="s in sections" :key="s.id">
                            <button
                                class="w-full rounded-lg px-3 py-1.5 text-left"
                                :class="active === s.id ? 'bg-green-700 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                @click="goTo(s.id)"
                            >{{ s.label }}</button>
                        </li>
                    </ul>
                </nav>

                <div class="min-w-0 flex-1 space-y-8 text-sm leading-6 text-gray-700 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-gray-900 [&_h3]:mt-3 [&_h3]:font-semibold [&_h3]:text-gray-900">
                    <section id="in-breve">
                        <h2>In breve</h2>
                        <p class="mt-2">
                            Il sistema tiene insieme tre cose: il <strong>censimento del verde</strong> (che cosa c'è e dove),
                            i <strong>lavori</strong> (che cosa va fatto, chi lo fa, quanto vale) e la <strong>qualità</strong>
                            (controlli, ispezioni, segnalazioni). Tutto quello che si fa in campo con il telefono
                            funziona anche senza connessione e si allinea da solo appena torna la rete.
                        </p>
                        <h3>I ruoli</h3>
                        <p>
                            <strong>Amministratore</strong>: vede e gestisce tutto, compresi listini e catalogo
                            (gli account degli utenti si preparano in fase di attivazione, con l'assistenza).
                            <strong>Tecnico</strong>: gestisce censimento, lavori, ispezioni e segnalazioni.
                            <strong>Operatore</strong>: lavora dal telefono con l'app di campo; vede i lavori assegnati
                            alla sua squadra e registra rilievi, foto, consuntivi, ispezioni e segnalazioni.
                            <strong>Cliente</strong>: consultazione (in arrivo con il portale dedicato).
                        </p>
                    </section>

                    <section id="censimento">
                        <h2>Il censimento</h2>
                        <p class="mt-2">
                            La pagina <strong>Mappa</strong> mostra gli elementi censiti sul territorio; la pagina
                            <strong>Censimento</strong> è l'elenco completo, con ricerca per codice e note.
                            Ogni elemento ha una scheda con il tipo (dal catalogo ministeriale), le misure, le foto,
                            gli attributi del suo tipo e la storia delle modifiche.
                        </p>
                        <h3>Se due persone modificano la stessa scheda</h3>
                        <p>
                            Vince chi salva per primo; al secondo il sistema chiede di ricaricare la scheda
                            aggiornata prima di riprovare. Nessuna modifica va persa in silenzio.
                        </p>
                    </section>

                    <section id="alberi">
                        <h2>Alberi e VTA</h2>
                        <p class="mt-2">
                            La pagina <strong>VTA</strong> raccoglie il catasto alberi: scheda completa per pianta,
                            valutazioni di stabilità con difetti e classi di propensione al cedimento, analisi
                            strumentali con referto, scadenzario delle rivalutazioni, alberi monumentali o dedicati,
                            e il bilancio arboreo (messi a dimora, abbattuti, sostituiti).
                        </p>
                    </section>

                    <section id="lavori">
                        <h2>I lavori</h2>
                        <p class="mt-2">
                            Ogni intervento è un <strong>ordine di lavoro</strong> con un codice (ODL-anno-numero),
                            gli elementi coinvolti, la squadra assegnata e uno stato che avanza:
                            bozza, pianificato, assegnato, in corso, sospeso, completato (o annullato).
                            L'<strong>agenda</strong> mostra la settimana per squadra; il <strong>rendiconto</strong>
                            riepiloga i lavori completati per cliente e periodo, con i valori calcolati dal listino.
                        </p>
                        <h3>Listini e valore</h3>
                        <p>
                            Nella pagina <strong>Listini</strong> si definiscono le voci (prezzo per unità di misura,
                            spese generali, oneri di sicurezza). Se all'ordine è collegato un listino, il consuntivo
                            registrato in campo viene valorizzato da solo; il rendiconto segnala i casi in cui manca
                            qualcosa (nessun listino, nessuna voce, quantità mancante).
                        </p>
                    </section>

                    <section id="qualita">
                        <h2>Qualità e ispezioni</h2>
                        <p class="mt-2">
                            Il verbale di controllo di fine lavoro si compila dal dettaglio dell'ordine, nella pagina
                            <strong>Lavori</strong>; la vista Qualità della stessa pagina raccoglie le
                            <strong>non conformità</strong> (aperta, azione correttiva, verificata, chiusa).
                            Nella pagina <strong>Ispezioni</strong> si preparano i <strong>modelli di checklist</strong>
                            (per aree o per elementi, con domande OK/KO, testi e numeri) e si registrano le esecuzioni:
                            l'esito è calcolato dalle risposte e ogni voce negativa può aprire da sola una non conformità.
                        </p>
                        <h3>Lo scadenzario</h3>
                        <p>
                            Se un modello ha una periodicità (per esempio: area giochi ogni 90 giorni), la scheda
                            <strong>Scadenzario</strong> mostra per ogni area o elemento l'ultima ispezione e la data
                            entro cui ripeterla, con i ritardi in cima e in rosso.
                        </p>
                    </section>

                    <section id="segnalazioni">
                        <h2>Le segnalazioni</h2>
                        <p class="mt-2">
                            Una segnalazione (di un collega o di un cliente) ha un codice SEG, una gravità e uno stato:
                            aperta, presa in carico, risolta oppure archiviata. In base alla gravità il sistema fissa
                            due scadenze: entro quando va <strong>presa in carico</strong> (da 1 giorno per le critiche
                            a 10 per quelle di gravità bassa) ed entro quando va <strong>risolta</strong> (da 3 a 30 giorni).
                            La colonna Tempi e i filtri mostrano subito che cosa è fuori tempo massimo.
                            Da una segnalazione si genera l'ordine di lavoro con un solo pulsante.
                        </p>
                    </section>

                    <section id="campo">
                        <h2>L'app di campo (pagina Campo)</h2>
                        <p class="mt-2">
                            Pensata per il telefono, funziona <strong>anche senza connessione</strong>: prima di uscire
                            conviene aprire la scheda Sync e premere "Scarica i dati di lavoro". Da quel momento
                            l'operatore può censire elementi, misurare, fotografare, associare tag, compilare
                            consuntivi dei lavori assegnati, eseguire ispezioni su checklist e aprire segnalazioni:
                            tutto resta in coda sul dispositivo e parte da solo appena torna la rete.
                        </p>
                        <h3>Se un invio viene rifiutato</h3>
                        <p>
                            La scheda Sync elenca gli invii che il server non ha accettato, con il motivo scritto
                            chiaro. Si può riprovare (dopo aver aggiornato i dati) oppure scartare l'invio:
                            in ogni caso il dispositivo si riallinea e nulla resta a metà.
                        </p>
                    </section>

                    <section id="territorio">
                        <h2>Territorio e catalogo</h2>
                        <p class="mt-2">
                            La pagina <strong>Territorio</strong> organizza clienti, sedi (i comuni) e località;
                            le aree di gestione si disegnano dalla <strong>Mappa</strong> e ogni elemento censito
                            appartiene a un'area. La pagina <strong>Catalogo</strong>
                            contiene i tipi di oggetto del Modello Dati ministeriale (387 tipi, non modificabili) e i
                            <strong>campi personalizzati</strong> che si possono aggiungere a ciascun tipo
                            (per esempio la larghezza delle siepi), con la possibilità di collegarli ai campi
                            dei tracciati di consegna.
                        </p>
                    </section>

                    <section id="consegna">
                        <h2>La consegna CAM</h2>
                        <p class="mt-2">
                            Per lavorare con i Comuni serve consegnare il censimento nel formato del
                            "Modello dati per il censimento del verde urbano" (richiesto dai CAM).
                            Dalla pagina <strong>Censimento</strong> si può esportare un singolo layer
                            (scegliendo categoria e formato) oppure premere <strong>"Consegna completa"</strong>:
                            un unico pacchetto con tutti i layer non vuoti, nominati con il codice ISTAT del comune
                            (o con l'identificativo dell'organizzazione, se i comuni gestiti sono più d'uno),
                            la cartella FOTO con le immagini e un riepilogo dei conteggi.
                            L'import fa il percorso inverso e accetta i file di altri sistemi nello stesso formato,
                            con una prova a vuoto (analisi) prima di scrivere qualunque cosa.
                        </p>
                    </section>

                    <section id="domande">
                        <h2>Domande frequenti</h2>
                        <h3>Il telefono era offline e ho censito 50 elementi: che succede?</h3>
                        <p>
                            Restano sul dispositivo, numerati in ordine. Appena c'è rete l'app li invia da sola,
                            nell'ordine giusto: il contatore della coda nella scheda Sync scende a zero quando è
                            tutto consegnato, e il Registro attività segnala gli invii che il server non ha accettato.
                        </p>
                        <h3>Chi vede che cosa?</h3>
                        <p>
                            Ogni organizzazione vede solo i propri dati. Dentro l'organizzazione, i permessi
                            seguono i ruoli: per esempio i listini li gestisce chi coordina, non l'operatore.
                        </p>
                        <h3>Ho sbagliato: posso eliminare?</h3>
                        <p>
                            Le eliminazioni sono "morbide": il dato scompare dalle viste ma resta in archivio,
                            con la storia delle modifiche. Le cose ormai chiuse (ordini completati, segnalazioni
                            risolte, ispezioni registrate) non si modificano: fanno da registro.
                        </p>
                        <h3>Serve aiuto in più?</h3>
                        <p>
                            I messaggi dell'applicazione sono scritti in italiano e dicono come rimediare;
                            solo alcuni controlli automatici di compilazione dei moduli possono comparire in inglese.
                            Se qualcosa non torna, il punto di partenza è sempre: aggiornare la pagina,
                            sincronizzare il dispositivo e riprovare.
                        </p>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
