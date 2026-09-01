// Etichette italiane degli stati di un elemento censito: stesse parole
// dell'API (app/Support/AssetStatus.php), un solo posto da aggiornare.
export const STATUS_LABELS = {
    active: 'Attivo',
    dead: 'Morto in piedi',
    stump: 'Ceppaia',
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

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? (status || '—');
}
