<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const sections = [
    { id: 'in-breve', label: 'In breve' },
    { id: 'oggi', label: 'Il cruscotto Oggi' },
    { id: 'censimento', label: 'Il censimento' },
    { id: 'alberi', label: 'Alberi e VTA' },
    { id: 'lavori', label: 'I lavori' },
    { id: 'fitosanitari', label: 'Registro fitosanitari' },
    { id: 'patentini', label: 'Patentini e certificati' },
    { id: 'statistiche', label: 'Statistiche' },
    { id: 'qualita', label: 'Qualità e ispezioni' },
    { id: 'segnalazioni', label: 'Le segnalazioni' },
    { id: 'portale-pubblico', label: 'Il portale pubblico' },
    { id: 'gestionale', label: 'Il gestionale giardini' },
    { id: 'campo', label: 'L\'app di campo' },
    { id: 'territorio', label: 'Territorio e catalogo' },
    { id: 'consegna', label: 'La consegna CAM' },
    { id: 'utenti', label: 'Gli utenti' },
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
                <!-- L'indice laterale su telefono toglierebbe metà schermo al testo -->
                <nav class="hidden w-56 shrink-0 md:block">
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
                            <strong>Cliente</strong>: dal suo portale vede solo il proprio territorio —
                            aree, elementi censiti, lavori completati e segnalazioni, senza dati economici —
                            e può inviare una richiesta (descrizione, urgenza e fino a 3 foto) che arriva
                            subito tra le segnalazioni da gestire, seguendone poi lo stato e l'esito.
                        </p>
                    </section>

                    <section id="oggi">
                        <h2>Il cruscotto Oggi</h2>
                        <p class="mt-2">
                            La pagina <strong>Oggi</strong> raccoglie in un colpo d'occhio ciò che richiede
                            attenzione: lavori in ritardo o in programma nei prossimi 7 giorni, controlli
                            ricorrenti scaduti o in scadenza, segnalazioni con i tempi di risposta a rischio,
                            non conformità aperte, patentini e certificati in scadenza e impianti di
                            irrigazione da aprire o invernare. Ogni sezione porta con un clic alla pagina di
                            dettaglio. È la prima pagina da guardare al mattino.
                        </p>
                        <p class="mt-2">
                            Le stesse voci arrivano anche <strong>via email ogni mattina alle 6:30</strong> ad
                            amministratori e tecnici; se non c'è nulla da segnalare, nessuna email. Chi non
                            vuole riceverla si esclude dalla pagina <strong>Utenti</strong> (casella "Riceve il
                            riepilogo email delle scadenze"). L'invio richiede la casella di posta configurata
                            sul server (si fa alla messa online).
                        </p>
                    </section>

                    <section id="censimento">
                        <h2>Il censimento</h2>
                        <p class="mt-2">
                            La pagina <strong>Mappa</strong> mostra gli elementi censiti sul territorio; la pagina
                            <strong>Censimento</strong> è l'elenco completo, con ricerca per codice e note.
                            Ogni elemento ha una scheda con il tipo (dal catalogo ministeriale), le misure, le foto,
                            gli attributi del suo tipo e la storia delle modifiche.
                            Il pulsante <strong>"Esporta CSV"</strong> scarica l'elenco (con i filtri attivi)
                            in un file da aprire direttamente in Excel.
                        </p>
                        <h3>Se due persone modificano la stessa scheda</h3>
                        <p>
                            Vince chi salva per primo; al secondo il sistema chiede di ricaricare la scheda
                            aggiornata prima di riprovare. Nessuna modifica va persa in silenzio.
                        </p>
                        <h3>Stampe e pagina pubblica</h3>
                        <p>
                            Dalla scheda di un elemento si scaricano la <strong>scheda PDF</strong> (per
                            fascicoli e consegne) e, per gli alberi, il <strong>cartellino con codice QR</strong>
                            da stampare e fissare in campo. Attivando la <strong>pagina pubblica</strong>,
                            chi inquadra il QR vede una pagina informativa con foto e dati essenziali,
                            senza bisogno di accesso; si può disattivare in qualunque momento e il vecchio
                            collegamento smette di funzionare. Il verbale di ispezione in PDF si scarica
                            dal dettaglio dell'ispezione.
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
                        <h3>La perizia di stabilità in PDF</h3>
                        <p>
                            Da ogni valutazione, con il pulsante <strong>Perizia PDF</strong> nella scheda
                            dell'albero, esce il documento completo da firmare e consegnare al committente:
                            identificazione, contesto, dati dendrometrici, difetti parte per parte, analisi
                            strumentali, classe di propensione al cedimento, prescrizioni, foto e mappa.
                        </p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>
                                Serve la <strong>classe di propensione al cedimento</strong>: senza, la perizia
                                non viene emessa, perché è la conclusione tecnica che si firma.
                            </li>
                            <li>
                                I campi lasciati vuoti si stampano come "non rilevato" o "parte non esaminata":
                                il documento non dichiara mai controlli che non sono stati fatti.
                            </li>
                            <li>
                                Alla prima stampa la perizia riceve un <strong>numero di protocollo</strong>
                                (PER-anno-numero) e una data di emissione, che non cambiano più: ristampare
                                dà lo stesso documento.
                            </li>
                            <li>
                                Se correggi una valutazione già stampata, il documento corretto esce con un
                                numero e una data nuovi, così il numero già consegnato resta legato a quel testo.
                            </li>
                            <li>
                                L'intestazione con nome, titolo e iscrizione all'albo si compila una volta sola
                                nella pagina <strong>Utenti</strong>.
                            </li>
                        </ul>
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
                        <p class="mt-2">
                            Nella vista <strong>Preventivi</strong> si prepara un'offerta per un cliente: lavorazioni
                            dal catalogo (o voci libere) con quantità e prezzi indicati a mano, totale con IVA e
                            PDF pronto da inviare.
                            Il flusso è bozza, inviato, accettato o rifiutato: un preventivo accettato diventa
                            un ordine di lavoro con un clic, e ogni documento resta agli atti.
                        </p>
                    </section>

                    <section id="fitosanitari">
                        <h2>Registro dei trattamenti fitosanitari</h2>
                        <p class="mt-2">
                            La pagina <strong>Fitosanitari</strong> è il quaderno di campagna: ogni trattamento si
                            registra con data, area (ed eventualmente il singolo albero, per esempio in endoterapia),
                            coltura o vegetazione trattata, prodotto con numero di registrazione, principio attivo,
                            avversità combattuta, metodo, quantità, acqua, superficie, tempo di rientro e operatore.
                            Il pulsante
                            <strong>Registro PDF</strong> stampa il registro dell'anno scelto (anche di una sola
                            area), pronto da esibire a un controllo: le annotazioni vanno completate entro trenta
                            giorni dal trattamento e il registro va conservato almeno tre anni.
                        </p>
                        <p class="mt-2">
                            Il registro è <strong>unico per azienda</strong>, non per singola persona: anche se i
                            trattamenti li fanno più operatori, in fondo firma solo il titolare dell'azienda (o un
                            suo delegato con delega scritta); chi ha eseguito ogni intervento risulta comunque riga
                            per riga nel campo "Eseguito da". Un trattamento eseguito da un contoterzista va
                            controfirmato da lui per singolo intervento.
                        </p>
                    </section>

                    <section id="patentini">
                        <h2>Patentini e certificati</h2>
                        <p class="mt-2">
                            La pagina <strong>Patentini</strong> è lo scadenzario di abilitazioni e documenti:
                            patentino fitosanitario, abilitazione al trattore, corsi sicurezza, taratura
                            dell'irroratrice, assicurazioni. Ogni riga ha intestatario (una persona dell'app o un
                            nome libero, anche "Azienda"), numero, ente di rilascio e date; lo stato dice subito
                            cosa è <strong>scaduto</strong> (rosso) o <strong>in scadenza</strong> entro 60 giorni
                            (ambra). Le scadenze più vicine compaiono anche nel cruscotto <strong>Oggi</strong>.
                            Al rinnovo basta aggiornare la data di scadenza.
                        </p>
                    </section>

                    <section id="statistiche">
                        <h2>Statistiche</h2>
                        <p class="mt-2">
                            La pagina <strong>Statistiche</strong> riunisce i numeri d'insieme: consistenze del
                            censimento per tipologia e per specie, alberi per classe di stabilità e valutazioni per
                            anno, ordini di lavoro per stato e completati mese per mese, segnalazioni aperte e
                            risolte con il tempo medio di risoluzione, trattamenti fitosanitari per anno e per
                            prodotto. Sono grafici semplici (barre e colonne) pensati per leggere l'andamento,
                            non per sostituire le pagine di dettaglio.
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

                    <section id="portale-pubblico">
                        <h2>Il portale pubblico del committente</h2>
                        <p class="mt-2">
                            È un sito consultabile da chiunque, con lo stemma e i colori dell'ente, dove il
                            cittadino trova gli alberi del proprio territorio: una mappa, la ricerca per numero
                            di cartellino e la scheda del singolo albero. Si accende <strong>un committente alla
                            volta</strong>: finché non lo accendi, il suo indirizzo risponde "pagina non trovata".
                        </p>

                        <h3>Accenderlo</h3>
                        <p class="mt-2">
                            Tutto si fa dalla pagina <strong>Territorio</strong>: seleziona il committente e
                            compila il riquadro "Portale pubblico".
                        </p>
                        <ol class="mt-2 list-decimal space-y-1 pl-5">
                            <li>Spunta <strong>Portale pubblico attivo</strong>.</li>
                            <li>Scegli il <strong>nome nell'indirizzo</strong> (per esempio <em>mentana</em>). Se lo
                                lasci vuoto viene ricavato dal nome del committente. Sotto al campo compare
                                l'indirizzo completo che avrà il portale, mentre lo scrivi.</li>
                            <li>Scrivi il <strong>nome mostrato in pubblico</strong>, scegli il <strong>colore</strong>
                                dell'intestazione e carica lo <strong>stemma</strong>.</li>
                            <li>Indica l'<strong>email per le segnalazioni</strong> dei cittadini: senza, il pulsante
                                "Segnala un problema" non compare.</li>
                            <li>Scrivi il <strong>testo di benvenuto</strong> e la riga in fondo alla pagina.</li>
                            <li>Compila <strong>Privacy e note legali</strong>: titolare del trattamento e, se l'ente
                                ce l'ha, l'indirizzo della dichiarazione di accessibilità.</li>
                            <li>Salva e usa <strong>"Apri il portale"</strong> per vederlo come lo vede un cittadino.</li>
                        </ol>

                        <h3>L'indirizzo del portale</h3>
                        <p class="mt-2">
                            Ci sono due forme di indirizzo, e cambiano solo per come è configurato il server:
                        </p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li><strong>Con il dominio dei portali collegato:</strong>
                                <em>mentana.tuodominio.it</em>, il nome del Comune davanti. È la forma da dare
                                agli enti.</li>
                            <li><strong>Senza dominio collegato:</strong> <em>indirizzo-del-gestionale/comune/mentana</em>.
                                Funziona uguale, ma è un indirizzo di collaudo: si vede che è "dentro" un altro sito.</li>
                        </ul>
                        <p class="mt-2">
                            Il dominio si compra una volta sola e vale per tutti i Comuni: nel pannello del gestore
                            del dominio serve un solo record jolly che punta al server, poi sul server si lancia
                            <strong>deploy/set-portal-domain.sh</strong> con il nome del dominio. Da quel momento ogni
                            Comune nuovo è immediato: lo accendi da qui e l'indirizzo funziona subito, lucchetto
                            compreso. Se il dominio non è ancora collegato, il riquadro del portale te lo dice e
                            riporta il comando esatto. I passaggi per esteso sono in
                            <em>docs/DEPLOY-ARUBA.md</em>, capitolo 6.3.
                        </p>
                        <p class="mt-2">
                            L'indirizzo di collaudo resta valido anche dopo: è comodo per far vedere un portale a
                            un ente prima che il suo nome sia pubblicato nel DNS.
                        </p>

                        <h3>Lavorare su tanti elementi insieme</h3>
                        <p class="mt-2">
                            Negli elenchi <strong>Censimento</strong> e <strong>Lavori</strong> ogni riga ha
                            una casella a sinistra, e in cima c'e' quella che le seleziona tutte. Appena
                            selezioni qualcosa compare una barra verde con le azioni disponibili.
                        </p>
                        <p class="mt-2">
                            Dal <strong>Censimento</strong> puoi, su tutti gli elementi selezionati in una
                            volta: <strong>nasconderli o rimetterli sul portale</strong> pubblico,
                            applicare la stessa <strong>data di rilievo</strong> (comodo dopo una giornata
                            di campagna), e <strong>collegarli a un ordine di lavoro</strong> gia' aperto.
                            Dai <strong>Lavori</strong> puoi segnare come completati piu' ordini insieme.
                        </p>
                        <p class="mt-2">
                            Prima seleziona con i filtri, poi spunta: la selezione si azzera quando cambi
                            filtro o pagina, cosi' non ti ritrovi ad agire su righe che non stai piu'
                            vedendo.
                        </p>
                        <p class="mt-2">
                            <strong>Quello che non riesce te lo dice.</strong> Se fra i venti ordini
                            selezionati tre non si potevano chiudere, compare un riquadro giallo con
                            l'elenco di quali e il motivo. Non vengono forzati e non spariscono in
                            silenzio: un'azione di gruppo che salta righe senza dirlo ti fa credere di aver
                            finito un lavoro rimasto a meta'.
                        </p>
                        <p class="mt-2">
                            Due cose <strong>non</strong> si fanno in blocco, di proposito: cambiare
                            <strong>specie e misure</strong>, che sono dati del singolo albero, e registrare
                            un <strong>abbattimento</strong>, che ha il suo pulsante nella scheda perche'
                            deve scrivere anche la data. Farli in blocco sarebbe il modo piu' rapido di
                            rovinare un censimento.
                        </p>

                        <h3>Validare e chiudere una perizia</h3>
                        <p class="mt-2">
                            Finche' non la validi, la perizia e' una <strong>bozza di lavoro</strong>: la
                            correggi quante volte vuoi, e il PDF che scarichi porta scritto che non fa fede.
                            Quando il lavoro e' finito, dalla scheda dell'albero premi
                            <strong>"Valida e chiudi"</strong>.
                        </p>
                        <p class="mt-2">
                            Da quel momento la perizia diventa un <strong>atto</strong>: prende numero di
                            protocollo e data, porta il nome di chi l'ha validata, e il contenuto tecnico
                            <strong>non e' piu' modificabile ne' cancellabile</strong>. Il blocco e' nel
                            database, non solo nella schermata: non c'e' strada per aggirarlo, ed e'
                            proprio questo che lo rende una garanzia da poter dichiarare al committente.
                        </p>
                        <p class="mt-2">
                            <strong>Se sbagli, non si torna indietro</strong>, e va bene cosi': una perizia
                            errata non si cancella, si supera. Registri una nuova perizia sullo stesso
                            albero e quella recente vale. E' come si fa con qualunque atto tecnico, ed e'
                            piu' difendibile di una correzione silenziosa.
                        </p>
                        <p class="mt-2">
                            In fondo al documento validato compare l'<strong>impronta del contenuto</strong>,
                            una sigla calcolata dai dati della perizia. Serve a dimostrare, anche fra anni,
                            che la copia che qualcuno ha in mano e' esattamente quella validata quel giorno:
                            se anche una virgola fosse diversa, l'impronta non tornerebbe.
                        </p>
                        <p class="mt-2">
                            Una cosa si puo' ancora cambiare dopo la validazione: se la perizia si vede sul
                            <strong>portale pubblico</strong>. Non e' contenuto tecnico, e' una scelta del
                            Comune su cosa mostrare ai cittadini.
                        </p>

                        <h3>Il bilancio arboreo da consegnare al Comune</h3>
                        <p class="mt-2">
                            La legge 10/2013 chiede al sindaco di rendere noto, prima della fine del
                            mandato, quanti alberi sono stati piantati e quanti abbattuti. Dalla pagina
                            <strong>VTA</strong>, nel riquadro "Bilancio arboreo", scegli il
                            <strong>committente</strong> e il periodo, poi <strong>"Scarica il
                            documento"</strong>: esce un PDF con la consistenza iniziale e finale, gli
                            impianti, gli abbattimenti, il dettaglio per specie, i posti d'impianto e
                            lo spazio per timbro e firma.
                        </p>
                        <p class="mt-2">
                            Scegli sempre un committente prima di scaricarlo: lasciando "Tutti" il
                            documento somma tutti i Comuni insieme, e un numero cosi' l'ente non lo
                            puo' usare. Quella forma serve a te, per il quadro d'insieme dell'impresa.
                        </p>
                        <p class="mt-2">
                            In fondo al documento c'e' la <strong>nota sul metodo di calcolo</strong>:
                            dice come sono stati contati gli alberi (dalla data di messa a dimora, o da
                            quella di censimento se la prima non e' nota) e avverte che i posti
                            d'impianto sono una fotografia del giorno di stampa. E' la parte che rende
                            il documento difendibile davanti a un ufficio tecnico: non toglierla.
                        </p>

                        <h3>Il prefisso delle etichette</h3>
                        <p class="mt-2">
                            Ogni committente ha un prefisso di due-sei lettere (MEN, GUI) e una numerazione che
                            <strong>riparte da uno</strong>: i suoi elementi prendono MEN-0001, MEN-0002 e così via.
                            Il codice lo assegna il programma, non l'operatore, anche quando i rilievi rientrano
                            dall'app di campo dopo essere stati fatti senza rete. Se elimini un elemento il suo
                            numero non viene riassegnato: il cartellino potrebbe essere già applicato in campo.
                            Se scrivi tu un codice a mano (per esempio importando dalla Regione), quello vince.
                        </p>

                        <h3>Che cosa vede il cittadino</h3>
                        <p class="mt-2">
                            La <strong>home</strong> con i numeri del patrimonio; la <strong>mappa</strong> a tutto
                            schermo con tre sfondi (stradale, satellite, scura) e la legenda a quattro stati
                            (sano, in cura, da potare, in verifica); la <strong>scheda dell'albero</strong> con foto
                            ingrandibile, specie, data del rilievo, zona, coordinate cliccabili, misure, vincoli,
                            cronologia degli interventi e i pulsanti "Raggiungi l'elemento" e "Segnala un problema".
                        </p>
                        <p class="mt-2">
                            Gli <strong>alberi abbattuti spariscono</strong> dal portale ma restano nel gestionale,
                            e il numero degli abbattimenti non compare in pubblico: al suo posto la home mostra
                            quanti alberi sono stati curati e quanti potati. Le <strong>note interne</strong> e le
                            <strong>foto dei difetti</strong> non escono mai.
                        </p>

                        <h3>Nascondere un singolo albero</h3>
                        <p class="mt-2">
                            Nella scheda dell'elemento, sotto l'intestazione, c'è una riga che dice se è visibile
                            sul portale, con il pulsante <strong>"Nascondi dal portale"</strong>. Serve per i casi
                            particolari: il portale pubblica tutto il resto.
                        </p>

                        <h3>Pubblicare gli atti nella cronologia</h3>
                        <p class="mt-2">
                            La cronologia pubblica di un albero è vuota finché non decidi tu cosa pubblicare.
                        </p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li><strong>Ordine di lavoro</strong>: nella pagina Lavori apri l'ordine e spunta
                                "Pubblica come atto sul portale del committente". A lavoro completato compare nella
                                cronologia degli alberi interessati come "Ordine di servizio N. ...". La spunta si
                                può mettere e togliere anche dopo che l'ordine è chiuso.</li>
                            <li><strong>Perizia di stabilità</strong>: nel modulo della valutazione, spunta
                                "Pubblica come atto sul portale". Compare come "Relazione tecnica N. ..." con la
                                data, le prescrizioni e la prossima verifica prevista.</li>
                        </ul>

                        <h3>Le foto degli interventi</h3>
                        <p class="mt-2">
                            Nell'app di campo, dentro un lavoro in corso, c'è il pulsante <strong>"Foto del
                            lavoro"</strong>: la foto resta agganciata a quell'ordine e compare nella cronologia
                            pubblica accanto all'intervento. Se l'ordine tocca più elementi, prima si sceglie a
                            quale albero si riferisce. Una foto agganciata a un lavoro non pubblicato non è
                            raggiungibile da nessuno.
                        </p>

                        <h3>I vincoli</h3>
                        <p class="mt-2">
                            Sempre in <strong>Territorio</strong>, sotto il riquadro del portale, c'è l'elenco dei
                            vincoli del committente: codice breve (per esempio <em>art.28 PTPR</em>), descrizione,
                            ente che lo impone e il <strong>PDF</strong> da caricare. Il vincolo si collega poi agli
                            alberi in due modi: a mano, dalla scheda dell'elemento (riquadro "Vincoli"), oppure
                            <strong>per posizione</strong>, se il vincolo ha un perimetro: il pulsante "ricalcola"
                            trova da solo tutti gli elementi che ci ricadono dentro. Il ricalcolo non tocca mai i
                            collegamenti fatti a mano.
                        </p>

                        <h3>La stima dell'anidride carbonica</h3>
                        <p class="mt-2">
                            Si accende con la spunta <strong>"Mostra la stima dell'anidride carbonica"</strong> nel
                            riquadro del portale. Il calcolo parte dal <strong>diametro del tronco</strong>: senza
                            quel dato la stima non compare. L'<strong>assorbimento medio annuo</strong> richiede
                            anche l'<strong>età stimata</strong>. Sotto al valore la pagina dichiara sempre con
                            quale metodo è stato ottenuto. Tienila spenta finché il tecnico non ha verificato
                            coefficienti e fonti: è l'unico numero del portale che si può contestare.
                        </p>

                        <h3>Prima di dare l'indirizzo a un ente</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Stemma, nome pubblico, colore e testo di benvenuto compilati.</li>
                            <li>Email per le segnalazioni indicata.</li>
                            <li>Titolare del trattamento compilato: senza, la pagina della privacy lo dichiara
                                mancante.</li>
                            <li>Prefisso delle etichette impostato, se vuoi la numerazione per Comune.</li>
                            <li>Un giro di prova sul portale: home, ricerca di un'etichetta vera, mappa, scheda.</li>
                        </ul>
                    </section>

                    <section id="gestionale">
                        <h2>Il gestionale giardini (WordPress)</h2>
                        <p class="mt-2">
                            Se nella stessa area segui sia il censimento sia la manutenzione, dalla scheda di un
                            elemento puoi <strong>inviare al gestionale WordPress</strong> un "intervento da fare"
                            o "da preventivare": titolo del problema, descrizione, priorità e fino a 5 fotografie.
                            La scheda arriva al gestionale come segnalazione, con codice, specie, comune, area e
                            posizione dell'elemento. L'invio passa da una coda con ritentativi automatici e un
                            doppio invio non crea doppioni; lo stato si vede nella scheda dell'elemento.
                            Indirizzo e gettone del collegamento si impostano nella pagina
                            <strong>Utenti</strong> (solo amministratore), con il pulsante "Prova collegamento".
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
                        <p class="mt-2">
                            La pagina <strong>Irrigazione</strong> tiene il registro degli impianti di ogni area:
                            settori con portata e programma settimanale, stagione di apertura e chiusura, stato
                            (in esercizio, invernato, fuori servizio). Il pulsante
                            <strong>"Genera ordine di manutenzione"</strong> crea un ordine di lavoro in bozza
                            già intestato all'area, da programmare dalla pagina Lavori.
                            Nella scheda dell'impianto si registrano anche le <strong>letture del contatore</strong>
                            idrico: l'applicazione calcola il consumo tra una lettura e l'altra e lo confronta
                            con la stima settimanale del programma, così un consumo anomalo (per esempio una
                            perdita) salta subito all'occhio.
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

                    <section id="utenti">
                        <h2>Gli utenti</h2>
                        <p class="mt-2">
                            Dalla pagina <strong>Utenti</strong> l'amministratore crea gli account per squadra e
                            clienti: si sceglie il ruolo (amministratore, tecnico, operatore o cliente del portale,
                            quest'ultimo collegato a un cliente in anagrafica) e il sistema genera una
                            <strong>password provvisoria</strong>, mostrata una sola volta, da comunicare
                            all'interessato. Un utente che non deve più accedere si <strong>disattiva</strong>
                            (la sua storia resta); "Nuova password" rimedia a una password dimenticata.
                            Non è possibile disattivare sé stessi né l'ultimo amministratore attivo.
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
