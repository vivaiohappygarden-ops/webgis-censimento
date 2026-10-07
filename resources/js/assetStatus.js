// Etichette italiane degli stati di un elemento censito: stesse parole
// dell'API (app/Support/AssetStatus.php), un solo posto da aggiornare.
export const STATUS_LABELS = {
    active: 'Attivo',
    dead: 'Morto in piedi',
    stump: 'Ceppaia',
    damaged: 'Danneggiato',
    out_of_service: 'Fuori servizio',
    removed: 'Abbattuto/Rimosso',
    dismissed: 'Dismesso',
};

// L'archivio del censimento: schede abbattute o dismesse, fuori dal lavoro
// quotidiano ma sempre consultabili e ripristinabili. "Morto in piedi" e
// "ceppaia" NON sono archivio: sono elementi veri ancora da gestire (un morto
// in piedi va abbattuto, una ceppaia va estirpata). Gemello della definizione
// lato server (AssetStatus): si aggiornano insieme.
export const ARCHIVE_STATUSES = ['removed', 'dismissed'];

export function inArchivio(status) {
    return ARCHIVE_STATUSES.includes(status);
}

// Stati che hanno senso solo per la vegetazione (tipo principale 1 del
// catalogo, seconda lettera del codice): una panchina non muore in piedi e
// non lascia una ceppaia. Gemello di AssetStatus::SOLO_VEGETAZIONE.
export const VEGETATION_ONLY_STATUSES = ['dead', 'stump'];

// Gli stati delle attrezzature (panchina rotta, gioco chiuso): restano in
// gestione come un albero morto in piedi, e alla vegetazione non si
// applicano. Gemello di AssetStatus::SOLO_ATTREZZATURE.
export const EQUIPMENT_ONLY_STATUSES = ['damaged', 'out_of_service'];

export function eVegetazione(codiceTipo) {
    return String(codiceTipo ?? '').charAt(1) === '1';
}

export function statoAmmesso(status, codiceTipo) {
    if (VEGETATION_ONLY_STATUSES.includes(status)) return eVegetazione(codiceTipo);
    if (EQUIPMENT_ONLY_STATUSES.includes(status)) return ! eVegetazione(codiceTipo);

    return true;
}

// Gli stati proponibili nella tendina di una scheda: per la vegetazione
// morto in piedi e ceppaia, per il resto danneggiato e fuori servizio; lo
// stato attuale resta sempre in elenco, anche se fuori regola (dati vecchi),
// cosi' la tendina non mente su quello che c'e' scritto
export function statiProponibili(codiceTipo, attuale = null) {
    return Object.keys(STATUS_LABELS).filter((s) => s === attuale || statoAmmesso(s, codiceTipo));
}

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? (status || '—');
}
