// Etichette italiane degli stati di un ordine di lavoro.
//
// Stanno qui e non dentro una pagina perche' servono a piu' schermate: la
// pagina Lavori e la scheda della localita'. Duplicarle vorrebbe dire, prima
// o poi, chiamare la stessa cosa in due modi diversi in due punti del
// programma.
export const WORK_STATUS_LABELS = {
    draft: 'Bozza',
    planned: 'Pianificato',
    assigned: 'Assegnato',
    in_progress: 'In corso',
    suspended: 'Sospeso',
    completed: 'Completato',
    cancelled: 'Annullato',
};

export const workStatusLabel = (stato) => WORK_STATUS_LABELS[stato] ?? stato;
