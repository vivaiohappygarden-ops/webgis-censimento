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
        'Elenco di tutti gli elementi censiti, con ricerca per numero di cartellino, specie, area o committente e filtri per trovare subito gli alberi con la verifica di stabilità scaduta o mai valutati. Le ricerche più usate si possono salvare.',
        'Mappa con gli elementi divisi per livelli, chiome disegnate alla dimensione reale e numero del cartellino accanto a ogni albero. Dalla mappa si misurano distanze e superfici e si stampa una tavola con scala e legenda. Come sfondo si possono usare l\'ortofoto e la cartografia del Comune.',
        'Organizzazione del territorio in committenti, sedi, località e aree, con superfici calcolate automaticamente, vincoli con il documento allegato e contratti con CIG, CUP e importo.',
        'Catalogo dei 387 tipi di elemento previsti dal Modello Dati ministeriale per il censimento del verde urbano, con la possibilità di aggiungere campi propri.',
        'Stampa del cartellino con codice QR per ogni elemento.',
        'Importazione di censimenti già esistenti in Excel, CSV, GeoJSON o formato CAM, con anteprima prima del salvataggio.',
        'Esportazione in Excel, CSV, GeoJSON e Shapefile, e consegna nel formato CAM con le codifiche ministeriali, il sistema di riferimento RDN2008 e le fotografie.',
    ]],
    ['2.2 Alberi e valutazioni di stabilità', [
        'Scheda di ogni albero con specie, misure, età, stato vegetativo, posizione, bersaglio, tutori e consolidamenti, date di messa a dimora e di abbattimento. Gli alberi monumentali, tutelati o dedicati sono segnalati.',
        'Storia completa di ogni albero, con rilievi, modifiche, valutazioni, lavori, segnalazioni e fotografie in ordine di data e con il nome di chi li ha eseguiti.',
        'Valutazioni di stabilità (VTA) con difetti, bersagli, classe di propensione al cedimento, prescrizioni e data della verifica successiva. Le analisi strumentali si allegano con il loro referto.',
        'Perizia di stabilità in PDF con numero di protocollo, fotografie e spazio per la firma. Una volta validata, la perizia non si può più modificare.',
        'Scadenzario dei ricontrolli, che si trasformano in ordini di lavoro con un clic.',
        'Bilancio arboreo previsto dalla legge 10/2013, stampabile per il periodo richiesto.',
        'Stima dei benefici ambientali di ogni albero (CO2 assorbita, ossigeno prodotto, polveri trattenute, pioggia intercettata), con indicazione del metodo di calcolo. Sul portale pubblico compaiono solo se l\'ente lo decide.',
    ]],
    ['2.3 App di campo', [
        'App per telefono che si installa dal browser e funziona anche senza copertura di rete. I dati vengono inviati appena torna il segnale.',
        'Rilievo di nuovi alberi con posizione GPS, specie, misure, stato vegetativo, fotografie e cartellino.',
        'Creazione di nuove aree direttamente dal campo.',
        'Aggiornamento degli elementi già censiti, con misure, fotografie e lettura del cartellino con la fotocamera o con un lettore esterno.',
        'Elenco dei lavori assegnati alla squadra, con registrazione di ore, quantità, mezzi e materiali. I lavori del giorno sono ordinati per vicinanza.',
        'Compilazione delle ispezioni e invio delle segnalazioni dal posto.',
        'Accesso riservato al personale delle ditte esterne, che vede e rendiconta solo i lavori affidati alla propria squadra.',
    ]],
    ['2.4 Lavori e contabilità', [
        'Pagina "Oggi" con le attività da svolgere in ordine di urgenza: lavori in ritardo, ispezioni scadute, segnalazioni da gestire, patentini e ricontrolli in scadenza. Lo stesso riepilogo arriva ogni mattina per email.',
        'Ordini di lavoro con alberi coinvolti, squadra, priorità e stato di avanzamento. Ogni ordine ha una pagina con quanto fatto e quanto manca, consuntivo, controlli di qualità, mappa e cronologia.',
        'Visualizzazione degli ordini in elenco, in agenda per squadra e nel diagramma di Gantt. L\'agenda si consulta anche dal calendario del telefono.',
        'Listini con prezzi unitari, spese generali e oneri di sicurezza. Le quantità si ricavano direttamente dalla mappa.',
        'Preventivi in PDF che, una volta accettati, diventano ordini di lavoro.',
        'Stati di avanzamento lavori (SAL) con IVA e spese generali, e registrazione di fatturazione e incassi.',
        'Piani di manutenzione pluriennali che generano gli ordini alle scadenze previste.',
        'Gestione di squadre interne e imprese appaltatrici. L\'impresa ha un proprio accesso per vedere i lavori affidati e chiedere di spostarli.',
        'Statistiche sul patrimonio, sulle valutazioni, sui lavori e sulle segnalazioni.',
    ]],
    ['2.5 Controlli e registri', [
        'Ispezioni con liste di controllo per aree o attrezzature, scadenzario e verbale in PDF. Le risposte negative aprono una non conformità. Per le aree gioco sono disponibili le schede degli attrezzi e le liste basate sulla norma EN 1176.',
        'Gestione delle non conformità fino alla chiusura e verbale di controllo qualità a fine lavoro.',
        'Segnalazioni da colleghi, ufficio tecnico o cittadini, con tempi di intervento fissati in base alla gravità.',
        'Registro dei trattamenti fitosanitari, stampabile per anno.',
        'Scadenzario di patentini, abilitazioni e certificati del personale.',
        'Impianti di irrigazione con settori, programmi e letture del contatore, per individuare subito i consumi anomali.',
        'Relazione annuale sul verde per ogni committente e scheda della località con planimetrie.',
    ]],
    ['2.6 Documenti e marche temporali', [
        'Sezione Documenti che raccoglie perizie, verbali, preventivi, SAL ed esportazioni, con la produzione a richiesta di bilancio arboreo, relazione annuale e registro fitosanitari.',
        'Dieci documenti stampabili in PDF: scheda dell\'elemento, cartellino QR, perizia di stabilità, bilancio arboreo, verbale di ispezione, registro fitosanitari, preventivo, SAL, scheda della località e relazione annuale.',
        'Marca temporale su perizie, verbali e registri tramite un servizio accreditato, che ne certifica la data. Il programma conserva il documento marcato e ne verifica l\'integrità. Le marche si acquistano da DAMA S.R.L. a pacchetti (vedi punto 5).',
    ]],
    ['2.7 Portali', [
        '**Portale pubblico del Comune**, con indirizzo proprio, stemma, colori, fotografia di copertina e recapiti dell\'ufficio. Il cittadino inserisce il numero del cartellino, o inquadra il QR, e consulta la scheda dell\'albero con foto, specie, misure e interventi. La mappa mostra lo stato di ogni pianta. È il Comune a decidere cosa pubblicare, e le note interne non vengono mai mostrate. Il portale non usa cookie.',
        '**Area riservata dell\'ufficio tecnico**, da cui il Comune consulta la mappa del proprio territorio, l\'elenco degli alberi, i lavori fatti e in programma, le segnalazioni e le perizie, e invia richieste di intervento.',
        '**Portale delle imprese appaltatrici**, con accesso dedicato ai soli lavori affidati.',
    ]],
    ['2.8 Sicurezza e dati', [
        'Accessi per ruolo (amministratore, tecnico, operatore, cliente, impresa) e possibilità di creare ruoli personalizzati.',
        'Verifica in due passaggi con codice sul telefono, attivabile per tutti gli utenti.',
        'Storico di ogni modifica con data e autore. I dati eliminati restano in archivio.',
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
        riga(['Pacchetto di marche temporali', '[n] marche', '[€ …]']),
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
    p('**Oggetto:** fornitura del programma ArborLab per la gestione del patrimonio verde, comprensiva di attivazione, importazione dei dati, formazione, servizio di hosting e assistenza. [Riferimento: vostra richiesta del …, CIG …]'),

    titolo1('1. Che cos\'è ArborLab'),
    p('ArborLab è il programma di DAMA S.R.L. per censire e gestire il verde pubblico. Raccoglie in un unico archivio alberi, aree verdi e arredi, con misure, fotografie, valutazioni di stabilità e interventi eseguiti. Dallo stesso archivio si preparano le consegne nei formati richiesti dai Criteri Ambientali Minimi, le perizie, gli ordini di lavoro con preventivi e SAL, i registri e il portale per i cittadini.'),
    p('Il programma si usa dal computer in ufficio, dal telefono in campo, anche senza copertura di rete, e tramite i portali dedicati ai cittadini, all\'ufficio tecnico e alle imprese. I dati restano di proprietà dell\'ente e possono essere esportati in qualsiasi momento in formati aperti, senza costi aggiuntivi.'),

    titolo1('2. Oggetto della fornitura'),
    p('La fornitura comprende l\'uso del programma con tutte le funzioni descritte di seguito, per **[numero] utenti** e senza limiti al numero di elementi censiti, per la durata indicata al punto 5.'),
    ...FUNZIONI.flatMap(([t, voci]) => [titolo2(t), ...voci.map(voce)]),

    titolo1('3. Servizi compresi'),
    ...[
        '**Attivazione**: creazione degli utenti e impostazione dei dati dell\'ente.',
        '**Importazione dei dati esistenti** da Excel, CSV, GeoJSON o formato CAM, con verifica insieme all\'ufficio prima del caricamento definitivo.',
        '**Formazione**: [n] ore per l\'ufficio tecnico e [n] ore per gli operatori di campo, [in presenza / da remoto]. Il programma contiene una guida all\'uso e viene fornito un manuale operativo.',
        '**Hosting** su server in Italia, con collegamento protetto, copie di sicurezza giornaliere e aggiornamenti del programma inclusi nel canone.',
        '**Assistenza** telefonica e via email nei giorni lavorativi [dalle … alle …], con risposta entro [n] ore lavorative.',
        '**Portale pubblico del Comune**, attivato quando l\'ente lo ritiene opportuno.',
        '**Ambiente di prova** con dati dimostrativi, per la formazione prima dell\'avvio.',
    ].map(voce),

    titolo1('4. Non compreso'),
    ...[
        'Rilievo in campo, valutazioni di stabilità e stampa dei cartellini, che possono essere oggetto di offerta separata.',
        'Telefoni, lettori di codici e connessione internet.',
        'Personalizzazioni del programma su richiesta, da valutare a parte.',
    ].map(voce),

    titolo1('5. Condizioni economiche'),
    prezzi,
    new Paragraph({ spacing: { before: 200, after: 80 }, children: [new TextRun({ text: 'Durata e pagamento', bold: true, color: SCURO, size: 20 })] }),
    p('Il contratto ha durata di [12] mesi dall\'avvio ed è rinnovabile. L\'attivazione viene fatturata all\'avvio, il canone [annualmente in via anticipata]. Pagamento [a 30 giorni data fattura]. Gli importi si intendono IVA esclusa. Alla scadenza, o in caso di disdetta, l\'ente riceve una copia completa dei propri dati.'),

    titolo1('6. Tempi e avvio'),
    p('Il programma sarà operativo entro [n] giorni lavorativi dalla conferma dell\'ordine. L\'avvio prevede la creazione degli utenti, il caricamento del territorio e dei dati esistenti, la formazione del personale e, quando l\'ente lo desidera, la pubblicazione del portale.'),

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
