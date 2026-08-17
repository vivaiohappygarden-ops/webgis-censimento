// Etichette italiane degli stati di un elemento censito: stesse parole
// dell'API (app/Support/AssetStatus.php), un solo posto da aggiornare.
export const STATUS_LABELS = {
    active: 'Attivo',
    dead: 'Morto in piedi',
    stump: 'Ceppaia',
    removed: 'Abbattuto/Rimosso',
    dismissed: 'Dismesso',
};

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? (status || '—');
}
