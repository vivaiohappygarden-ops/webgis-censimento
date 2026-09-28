/**
 * Offerta commerciale ArborLab di DAMA S.R.L., in Word (.docx) da compilare.
 *
 * I campi da compilare sono fra parentesi quadre ed evidenziati in giallo:
 * in Word si trovano con Trova "[" e si tolgono con l'evidenziatore.
 *
 * Per rigenerarla serve il pacchetto npm "docx" (non e' fra le dipendenze del
 * progetto): lo si installa in una cartella qualsiasi e si passa da NODE_PATH.
 *
 *   npm install --prefix /tmp/docxlib docx@9
 *   NODE_PATH=/tmp/docxlib/node_modules node docs/offerta/genera-docx.cjs
 *
 * Scrive docs/offerta/offerta-arborlab.docx. Il testo parla a Comuni e
 * agronomi: niente funzioni interne, niente console della piattaforma, niente
 * collegamento al gestionale del vivaio.
 */
const fs = require('fs');
const path = require('path');
const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, WidthType, BorderStyle,
    AlignmentType, LevelFormat, Header, Footer, PageNumber, ShadingType, TabStopType,
} = require('docx');

const VERDE = '14532D', SCURO = '14261D', TENUE = '566158', AMBRA = '7A5C12', LINEA = 'DDE3DE', CHIARO = 'F3F6F3';
const FONT = 'Arial';

// Testo con i campi fra parentesi quadre evidenziati
function runs(testo, opz = {}) {
    return testo.split(/(\[[^\]]*\])/).filter(Boolean).map((pezzo) =>
        pezzo.startsWith('[')
            ? new TextRun({ text: pezzo, highlight: 'yellow', ...opz })
            : new TextRun({ text: pezzo, ...opz }));
}
// Testo con **grassetto** e campi
function ricco(testo, opz = {}) {
    return testo.split(/(\*\*[^*]+\*\*)/).filter(Boolean).flatMap((pezzo) =>
        pezzo.startsWith('**') ? runs(pezzo.slice(2, -2), { ...opz, bold: true }) : runs(pezzo, opz));
}
const p = (testo, opz = {}) => new Paragraph({ children: ricco(testo, opz.run), spacing: { after: 120 }, ...opz.par });
const titolo1 = (t) => new Paragraph({ children: [new TextRun({ text: t, bold: true, color: SCURO, size: 26 })], spacing: { before: 360, after: 120 }, border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: VERDE, space: 4 } }, keepNext: true });
const titolo2 = (t) => new Paragraph({ children: [new TextRun({ text: t, bold: true, color: SCURO, size: 21 })], spacing: { before: 220, after: 80 }, keepNext: true });
const voce = (t) => new Paragraph({ numbering: { reference: 'elenco', level: 0 }, children: ricco(t), spacing: { after: 60 } });

const bordoNullo = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };
const nessunBordo = { top: bordoNullo, bottom: bordoNullo, left: bordoNullo, right: bordoNullo };
const cella = (children, larghezza, opz = {}) => new TableCell({ children, width: { size: larghezza, type: WidthType.DXA }, margins: { top: 80, bottom: 80, left: 120, right: 120 }, ...opz });

// Larghezza utile A4 con margini di 2 cm: 11906 - 2 * 1134 = 9638
const UTILE = 9638;

// --------------------------------------------------------------- intestazione
const intestazione = new Table({
    width: { size: UTILE, type: WidthType.DXA },
    columnWidths: [4819, 4819],
    borders: { top: bordoNullo, bottom: bordoNullo, left: bordoNullo, right: bordoNullo, insideHorizontal: bordoNullo, insideVertical: bordoNullo },
    rows: [new TableRow({ children: [
        cella([
            new Paragraph({ children: [new TextRun({ text: 'DAMA S.R.L.', bold: true, size: 24, color: SCURO })], spacing: { after: 60 } }),
            ...['Via Crescenzio 58, 00193 Roma (RM)', 'P. IVA e C.F. 17947161000', 'REA RM-1751463', 'Tel. 392 305 1950', 'info@damagroup.it', 'PEC dama25@pec.it']
                .map((r) => new Paragraph({ children: [new TextRun({ text: r, size: 17, color: TENUE })] })),
        ], 4819, { borders: nessunBordo }),
        cella([
            new Paragraph({ children: [new TextRun({ text: 'SPETTABILE', bold: true, size: 15, color: AMBRA, characterSpacing: 20 })], spacing: { after: 60 } }),
            new Paragraph({ children: runs('[Comune di … / Studio …]', { bold: true, size: 21 }) }),
            ...['[Ufficio tecnico / Settore ambiente]', '[Indirizzo]', '[CAP, Città (Provincia)]', '[PEC]', "[Alla c.a. di …]"]
                .map((r) => new Paragraph({ children: runs(r, { size: 18, color: TENUE }) })),
        ], 4819, { borders: { ...nessunBordo, left: { style: BorderStyle.SINGLE, size: 12, color: VERDE } } }),
    ] })],
});

const datiOfferta = new Table({
    width: { size: UTILE, type: WidthType.DXA },
    columnWidths: [3212, 3213, 3213],
    borders: { top: bordoNullo, bottom: bordoNullo, left: bordoNullo, right: bordoNullo, insideHorizontal: bordoNullo, insideVertical: { style: BorderStyle.SINGLE, size: 12, color: 'FFFFFF' } },
    rows: [new TableRow({ children: [['OFFERTA N.', '[anno/numero]'], ['DATA', '[gg/mm/aaaa]'], ['VALIDITÀ', '[60] giorni dalla data']].map(([e, v]) =>
        cella([
            new Paragraph({ children: [new TextRun({ text: e, size: 15, color: TENUE, characterSpacing: 10 })] }),
            new Paragraph({ children: runs(v, { bold: true, size: 20 }) }),
        ], 3212, { shading: { fill: CHIARO, type: ShadingType.CLEAR, color: 'auto' } })) })],
});

// ------------------------------------------------------------------- funzioni
const FUNZIONI = [
    ['2.1 Patrimonio, mappa e territorio', [
        'Elenco del patrimonio con ricerca a parole che ignora gli accenti, filtri per committente, area, tipo e stato, scorciatoie "VTA scaduta", "mai valutati", "senza specie", anteprima di ogni elemento (fotografia, misure, cronologia) senza aprire la scheda, viste salvate e azioni su più schede con anteprima del numero di schede interessate.',
        'Mappa a livelli: albero dei livelli con i tipi presenti e i loro numeri, chiome in scala reale, siepi, recinzioni e percorsi disegnati per famiglia, etichette con il numero del cartellino, lavori e segnalazioni aperti in evidenza, misura di distanze e superfici, stampa con scala e legenda, coordinate nel sistema di riferimento nazionale; sfondi cartografici propri dell\'ente (ortofoto, carta tecnica) tramite WMS o servizi a riquadri.',
        'Organizzazione del territorio in committenti, sedi con codice ISTAT, località e aree disegnate sulla mappa, con superfici e perimetri calcolati automaticamente; vincoli con il documento allegato; contratti con CIG, CUP, importo e durata.',
        'Catalogo dei tipi di elemento del Modello Dati ministeriale per il censimento del verde urbano (387 tipi), con campi propri e tipi personalizzati.',
        'Cartellino stampabile con codice e QR per ogni elemento; pagina pubblica dell\'elemento attivabile singolarmente.',
        'Importazione di censimenti esistenti da Excel, CSV, GeoJSON e consegne CAM di altri fornitori, con abbinamento delle colonne e prova a vuoto con anteprima.',
        'Esportazione in CSV, Excel, GeoJSON e Shapefile; consegna CAM completa con codifiche ministeriali, sistema di riferimento RDN2008, fotografie e riepilogo dei conteggi.',
    ]],
    ['2.2 Catasto alberi e valutazioni di stabilità (VTA)', [
        'Scheda dell\'albero da leggere prima di modificare, una sezione per volta: genere, specie, cultivar e nome comune; diametro, circonferenza, altezza, chioma, numero di fusti; età, stato vegetativo, posizione sociale, bersaglio, sito di crescita; tutori e consolidamenti; date di messa a dimora e di abbattimento; alberi monumentali, tutelati e dedicati.',
        'Cronologia di ogni elemento: rilievo, modifiche, valutazioni, lavori, segnalazioni e fotografie in ordine di data, con l\'autore di ogni operazione; le fotografie eliminate restano consultabili nella cronologia; un abbattimento non cancella la storia.',
        'Valutazioni di stabilità con difetti per parte della pianta, bersagli, classe di propensione al cedimento, esito, prescrizioni e data della prossima verifica; analisi strumentali con referto allegato; intervalli di ricontrollo impostabili per classe.',
        'Perizia di stabilità in PDF con numero di protocollo, data di emissione, fotografie e riga per la firma; validazione che rende il documento immutabile, con impronta di controllo e fotografie fissate alla firma; validazione anche in blocco.',
        'Scadenzario dei ricontrolli con generazione degli ordini di lavoro corrispondenti, senza doppioni.',
        'Bilancio arboreo fra due date secondo la legge 10/2013, con stampa PDF.',
        'Stima dei benefici ambientali (anidride carbonica immagazzinata e assorbimento annuo con controvalore economico, ossigeno, polveri sottili, pioggia intercettata) con metodo dichiarato; pubblicazione solo su decisione dell\'ente.',
    ]],
    ['2.3 App di campo', [
        'Applicazione per telefono che si installa dal browser e lavora anche senza rete: le registrazioni restano in coda sul dispositivo e si inviano da sole al ritorno del segnale.',
        'Rilievo completo in un solo passaggio: posizione GPS con indicazione della precisione, tipo, specie, misure e stato vegetativo; poi fotografie e cartellino. Il codice del censimento è assegnato dal programma.',
        'Apertura di nuove aree direttamente dal campo, anche per un committente nuovo, con perimetro provvisorio non pubblicato finché l\'ufficio non lo definisce.',
        'Aggiornamento di elementi esistenti: misure, fotografie, cartellino (lettura con fotocamera, lettore esterno o digitazione; archivio dei cartellini QR, codici a barre, NFC e RFID), segnalazioni.',
        'Lavori assegnati alla squadra con elementi da lavorare, avvio, sospensione e consuntivo (ore, quantità, mezzi, attrezzature, materiali); giro del giorno ordinato per vicinanza e navigazione fino all\'elemento.',
        'Ispezioni con lista di verifica e segnalazioni compilate sul posto; sincronizzazione con esito chiaro di ogni invio.',
        'Accesso dedicato al personale delle ditte esterne: rendiconta dal campo i soli lavori affidati alla propria squadra, senza accesso al censimento.',
    ]],
    ['2.4 Oggi, lavori ed economia', [
        'Pagina "Oggi" con tutto ciò che richiede attenzione in ordine di urgenza (lavori in ritardo e in arrivo, ispezioni scadute, segnalazioni fuori tempo, non conformità, patentini in scadenza, ricontrolli VTA, stagione irrigua, documenti da chiudere, quanto arrivato dal campo), ognuno con il proprio pulsante; riepilogo per email ogni mattina.',
        'Ordini di lavoro con codice progressivo, elementi coinvolti, squadra, priorità e stato che avanza (bozza, pianificato, assegnato, in corso, sospeso, completato, annullato); pagina di ogni ordine con che cosa si fa, elementi con quantità previste e fatto o da fare, consuntivo, controlli di qualità, mappa degli elementi, documenti collegati e cronologia con ogni cambio di stato.',
        'Viste sugli ordini: elenco con scorciatoia "in ritardo", agenda per giorno e per squadra, diagramma a barre nel tempo (Gantt), rendiconto per committente e periodo, qualità, preventivi, stati di avanzamento, piani.',
        'Quantità calcolate dalla geometria degli elementi (metri quadrati, metri, cadauno) e valorizzate dal listino; listini con prezzi per unità di misura, spese generali e oneri di sicurezza.',
        'Preventivi da catalogo o con voci libere, calcolo dell\'IVA, stampa PDF e trasformazione in ordine di lavoro con un clic.',
        'Stati di avanzamento lavori (SAL) con IVA per riga, spese generali ripartite, validazione, fatturato e incasso registrati, stampa PDF ed elenco dei crediti aperti.',
        'Piani di manutenzione pluriennali con lavorazioni ricorrenti per area, intervallo e finestra stagionale; generazione degli ordini dovuti con anteprima e senza doppioni.',
        'Squadre interne e imprese appaltatrici, con portale dedicato all\'impresa per vedere i lavori affidati e chiedere formalmente una riprogrammazione motivata.',
        'Calendario da abbonamento personale (Google Calendar, iPhone, Outlook) con agenda dei lavori e scadenze; statistiche su patrimonio, stabilità, lavori, segnalazioni e trattamenti.',
    ]],
    ['2.5 Controlli e registri', [
        'Ispezioni con modelli di lista di verifica per aree o per elementi, periodicità e scadenzario, esito calcolato dalle risposte, apertura automatica delle non conformità, verbale in PDF; corredo per le aree gioco con scheda dell\'attrezzo e liste di verifica secondo la struttura della norma EN 1176.',
        'Non conformità con percorso aperta, azione correttiva, verificata, chiusa; verbale di controllo qualità di fine lavoro.',
        'Segnalazioni con codice, gravità e tempi massimi di presa in carico e risoluzione; provenienza da colleghi, dall\'ufficio tecnico o dai cittadini; trasformazione in ordine di lavoro con un pulsante.',
        'Registro dei trattamenti fitosanitari (quaderno di campagna) con stampa annuale in PDF, anche per singola area.',
        'Scadenzario di patentini e certificati (patentino fitosanitario, abilitazioni, corsi sicurezza, taratura dell\'irroratrice, assicurazioni).',
        'Impianti di irrigazione con settori, portate, programmi settimanali, stagione di apertura e chiusura, letture del contatore con confronto fra consumo e stima, generazione dell\'ordine di manutenzione.',
        'Relazione annuale del verde per committente e scheda della località con superfici, piante, documenti e planimetrie delle aree.',
    ]],
    ['2.6 Documenti e marche temporali', [
        'Sezione Documenti che raccoglie in un elenco solo perizie emesse, verbali di ispezione, preventivi, SAL ed esportazioni già fatte, con ricerca, filtri per committente e anno e scorciatoia "da validare"; produzione a richiesta di bilancio arboreo, relazione annuale e registro fitosanitari.',
        'Dieci stampe PDF con lo stesso impianto: scheda dell\'elemento, cartellino QR, perizia di stabilità, bilancio arboreo, verbale di ispezione, registro fitosanitari, preventivo, SAL, scheda della località, relazione annuale. Su ogni foglio una sola data e la riga "Luogo, data" per la firma dove serve.',
        'Marca temporale sulle perizie validate, sui verbali chiusi e sui registri tramite un servizio di marcatura accreditato (protocollo RFC 3161): il programma conserva il PDF esatto e la marca e ne verifica la corrispondenza. Le marche si acquistano a lotti dal fornitore del servizio (vedi punto 4).',
    ]],
    ['2.7 Portali', [
        '**Portale pubblico del Comune**, a un indirizzo proprio, con stemma, colore dell\'ente, fotografia di copertina facoltativa, testo di benvenuto, recapiti dell\'ufficio e informativa. Il cittadino trova il campo del cartellino nel primo schermo, la mappa del verde con lo stato di ogni pianta (sano, in cura, da potare, in verifica), la scheda di ogni albero con fotografia, specie, misure, data del rilievo e atti pubblicati, i vincoli con il documento, i numeri del patrimonio aggiornati automaticamente e le indicazioni per segnalare un problema. Nessun cookie e nessuna richiesta a servizi di terzi. Niente esce in pubblico da solo: ogni lavoro, perizia e vincolo si pubblica su decisione dell\'ente, i singoli alberi si possono nascondere, le note interne e le fotografie dei difetti non escono mai.',
        '**Area riservata dell\'ufficio tecnico**, con accesso personale e sei schede: panoramica con i conteggi, mappa del territorio con lo stato di ogni elemento, patrimonio con filtri e anteprima, lavori in programma e fatti con elementi e fotografie, segnalazioni e richieste con il lavoro che ne è nato, documenti in PDF (perizie emesse e verbali di ispezione). Senza dati economici e senza mai mostrare dati di altri committenti.',
        '**Portale delle imprese appaltatrici**: ingresso dedicato, visibilità dei soli ordini affidati, richiesta formale di riprogrammazione con motivo e data proposta.',
    ]],
    ['2.8 Sicurezza, accessi e dati', [
        'Ruoli di serie (amministratore, tecnico, operatore, cliente, impresa, esecutore) e ruoli su misura con permessi scelti uno per uno; ogni organizzazione vede solo i propri dati.',
        'Verifica in due passaggi con codice dall\'app di autenticazione del telefono, attivabile per utente o resa obbligatoria per tutti; codici di recupero; cambio della propria password.',
        'Registro delle operazioni, eliminazioni che restano in archivio, protezione dalle modifiche contemporanee alla stessa scheda.',
    ]],
];

// ---------------------------------------------------------------- tabella prezzi
const COL = [4400, 2838, 2400];
const riga = (valori, opz = {}) => new TableRow({
    tableHeader: opz.testata,
    children: valori.map((v, i) => cella([new Paragraph({ alignment: i === 2 ? AlignmentType.RIGHT : AlignmentType.LEFT, children: runs(v, { bold: !!(opz.testata || opz.totale), size: 19, color: opz.testata ? SCURO : undefined }) })], COL[i], {
        shading: opz.totale ? { fill: CHIARO, type: ShadingType.CLEAR, color: 'auto' } : undefined,
        borders: { top: bordoNullo, left: bordoNullo, right: bordoNullo, bottom: { style: BorderStyle.SINGLE, size: opz.testata ? 12 : 4, color: opz.testata ? VERDE : LINEA } },
    })),
});
const prezzi = new Table({
    width: { size: UTILE, type: WidthType.DXA },
    columnWidths: COL,
    rows: [
        riga(['Voce', 'Note', 'Importo (IVA esclusa)'], { testata: true }),
        riga(['Attivazione, importazione dei dati e formazione', 'Una tantum', '[€ …]']),
        riga(['Canone annuo di uso del programma, hosting, aggiornamenti e assistenza', 'Per [n] utenti, elementi senza limite', '[€ …]']),
        riga(['Utente aggiuntivo', 'Per anno', '[€ …]']),
        riga(['Portale pubblico di un ulteriore Comune o committente', 'Per anno', '[€ …]']),
        riga(['[Voce facoltativa, es. rilievo in campo o stampa cartellini]', '[Unità di misura]', '[€ …]']),
        riga(['Totale primo anno', 'Attivazione più canone', '[€ …]'], { totale: true }),
    ],
});

const firme = new Table({
    width: { size: UTILE, type: WidthType.DXA },
    columnWidths: [4619, 400, 4619],
    borders: { top: bordoNullo, bottom: bordoNullo, left: bordoNullo, right: bordoNullo, insideHorizontal: bordoNullo, insideVertical: bordoNullo },
    rows: [new TableRow({ children: [
        cella([new Paragraph({ children: [], spacing: { after: 700 } }), new Paragraph({ border: { top: { style: BorderStyle.SINGLE, size: 6, color: '16211C', space: 4 } }, children: [new TextRun({ text: 'DAMA S.R.L.', size: 18, bold: true })] }), new Paragraph({ children: runs('[Nome e qualifica di chi firma]', { size: 18, color: TENUE }) })], 4619, { borders: nessunBordo }),
        cella([new Paragraph('')], 400, { borders: nessunBordo }),
        cella([new Paragraph({ children: [], spacing: { after: 700 } }), new Paragraph({ border: { top: { style: BorderStyle.SINGLE, size: 6, color: '16211C', space: 4 } }, children: [new TextRun({ text: 'Per accettazione', size: 18, bold: true })] }), new Paragraph({ children: runs('[Ente], data, timbro e firma', { size: 18, color: TENUE }) })], 4619, { borders: nessunBordo }),
    ] })],
});

// ------------------------------------------------------------------- documento
const corpo = [
    intestazione,
    new Paragraph({ spacing: { after: 200 }, border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: LINEA, space: 8 } }, children: [] }),
    datiOfferta,
    new Paragraph({ spacing: { before: 400, after: 40 }, children: [new TextRun({ text: 'OFFERTA COMMERCIALE', bold: true, size: 15, color: AMBRA, characterSpacing: 20 })] }),
    new Paragraph({ spacing: { before: 60, after: 40, line: 240, lineRule: 'auto' }, children: [new TextRun({ text: 'ArborLab', bold: true, size: 48, color: '16211C' })] }),
    new Paragraph({ spacing: { after: 240 }, children: [new TextRun({ text: 'Il programma per la gestione del patrimonio verde', size: 26, color: TENUE })] }),
    p('**Oggetto:** fornitura in uso del programma ArborLab per il censimento del verde, il catasto degli alberi e le valutazioni di stabilità, i lavori e la loro contabilità, i registri e i portali per cittadini, ufficio tecnico e imprese; con attivazione, importazione dei dati, formazione, hosting e assistenza. [Riferimento eventuale: richiesta del …, CIG …]'),

    titolo1('1. Che cos\'è ArborLab'),
    p('ArborLab tiene in un solo archivio dove sta ogni albero e ogni elemento del verde, che cosa è, come sta, che cosa gli è stato fatto e quando. Dallo stesso archivio escono le consegne nei formati richiesti dai Capitolati Ambientali Minimi, le perizie di stabilità, gli ordini di lavoro con preventivi e stati di avanzamento, i registri e il portale che i cittadini consultano. Si usa dal computer in ufficio, dal telefono in campo anche senza copertura di rete, e dai portali riservati a chi sta fuori dall\'ufficio.'),
    p('Il dato resta dell\'ente: in qualunque momento si esporta per intero in formati aperti (CSV, Excel, GeoJSON, Shapefile, pacchetto CAM), senza costi aggiuntivi.'),

    titolo1('2. Oggetto della fornitura'),
    p('Uso del programma con tutte le funzioni elencate di seguito, per **[numero] utenti** dell\'ente e senza limite al numero di elementi censiti, per la durata indicata al punto 5.'),
    ...FUNZIONI.flatMap(([t, voci]) => [titolo2(t), ...voci.map(voce)]),

    titolo1('3. Servizi compresi'),
    ...[
        '**Attivazione**: creazione dell\'organizzazione, degli utenti e dei ruoli, impostazione dei dati dell\'ente e di chi firma i documenti, prefisso dei cartellini.',
        '**Importazione dei dati esistenti** da Excel, CSV, GeoJSON o consegne CAM, con verifica congiunta prima della scrittura definitiva.',
        '**Formazione**: [n] ore per l\'ufficio tecnico (gestionale e portali) e [n] ore per gli operatori di campo (app di campo), [in presenza / da remoto]; guida all\'uso dentro il programma e manuale operativo in italiano.',
        '**Hosting** su server in data center italiano, certificato HTTPS, copia di sicurezza giornaliera di dati e fotografie; aggiornamenti del programma compresi nel canone.',
        '**Assistenza** nei giorni lavorativi [dalle … alle …], per email e telefono, con prima risposta entro [n] ore lavorative.',
        '**Portale pubblico del Comune** compreso, attivabile quando il censimento è presentabile; cartellini QR stampabili dal programma.',
        '**Ambiente dimostrativo** con un patrimonio di prova per il collaudo prima della messa in esercizio con i dati reali.',
    ].map(voce),

    titolo1('4. Non compreso'),
    ...[
        'Il rilievo in campo, le valutazioni di stabilità e la stampa fisica dei cartellini, salvo offerta separata.',
        'L\'acquisto delle marche temporali presso un servizio di marcatura accreditato, a carico dell\'ente secondo il proprio fabbisogno [salvo diverso accordo].',
        'Dispositivi (telefoni, lettori di codici) e connettività.',
        'Personalizzazioni oltre i campi e i tipi configurabili dal programma, da quotare a parte.',
    ].map(voce),

    titolo1('5. Condizioni economiche'),
    prezzi,
    new Paragraph({ spacing: { before: 200, after: 80 }, children: [new TextRun({ text: 'Durata e pagamento', bold: true, color: SCURO, size: 20 })] }),
    p('Durata [12] mesi dalla messa in esercizio, rinnovabile alla scadenza. Pagamento [a 30 giorni data fattura]; l\'attivazione si fattura alla messa in esercizio, il canone [in un\'unica soluzione annuale anticipata]. IVA di legge. Alla scadenza, o in caso di recesso, l\'ente riceve l\'esportazione completa dei propri dati nei formati aperti indicati.'),

    titolo1('6. Tempi e avvio'),
    ...[
        '**Attivazione**: organizzazione, utenti e ruoli, dati dell\'ente e di chi firma.',
        '**Territorio**: committenti, sedi, località, aree e vincoli.',
        '**Dati esistenti**: importazione con anteprima e verifica congiunta.',
        '**Formazione e prova** sull\'ambiente dimostrativo, poi sui dati reali.',
        '**Portale pubblico**, quando il censimento è presentabile e l\'ente è d\'accordo.',
    ].map(voce),
    p('Messa in esercizio entro [n] giorni lavorativi dall\'ordine, salvo diversa pianificazione concordata per l\'importazione dei dati esistenti.'),

    titolo1('7. Note'),
    p('[Eventuali condizioni particolari, riferimenti alla procedura di affidamento, trattamento dei dati personali, referente del progetto.]'),

    new Paragraph({ spacing: { before: 400 }, children: runs('[Luogo], [data]') }),
    firme,
];

const doc = new Document({
    creator: 'DAMA S.R.L.',
    title: 'Offerta commerciale ArborLab',
    styles: { default: { document: { run: { font: FONT, size: 20 }, paragraph: { spacing: { line: 276, lineRule: 'auto' } } } } },
    numbering: { config: [{ reference: 'elenco', levels: [{ level: 0, format: LevelFormat.BULLET, text: '–', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 360, hanging: 260 } }, run: { color: VERDE, bold: true } } }] }] },
    sections: [{
        properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 1134, bottom: 1134, left: 1134, right: 1134, header: 567, footer: 567 } } },
        headers: { default: new Header({ children: [new Paragraph({ tabStops: [{ type: TabStopType.RIGHT, position: UTILE }], children: [new TextRun({ text: 'ArborLab, offerta commerciale', bold: true, size: 15, color: '16211C' }), new TextRun({ text: '\tDAMA S.R.L.', size: 15, color: TENUE })] })] }) },
        footers: { default: new Footer({ children: [new Paragraph({ tabStops: [{ type: TabStopType.RIGHT, position: UTILE }], border: { top: { style: BorderStyle.SINGLE, size: 4, color: LINEA, space: 4 } }, children: [
            new TextRun({ text: 'DAMA S.R.L. · Via Crescenzio 58, 00193 Roma · P. IVA 17947161000 · info@damagroup.it', size: 14, color: TENUE }),
            new TextRun({ children: ['\tPagina ', PageNumber.CURRENT, ' di ', PageNumber.TOTAL_PAGES], size: 14, color: TENUE }),
        ] })] }) },
        children: corpo,
    }],
});

Packer.toBuffer(doc).then((buf) => {
    const out = path.join(__dirname, 'offerta-arborlab.docx');
    fs.writeFileSync(out, buf);
    console.log('scritto', out);
});
