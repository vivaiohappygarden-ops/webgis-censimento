/*
 * Le schede delle sezioni della veste nuova che riuniscono pagine di prima:
 * scritte una volta sola, le leggono le pagine nuove e le testate montate
 * sulle pagine precedenti. Ogni scheda porta il permesso che la mostra.
 */
export const SCHEDE_DOCUMENTI = [
    { chiave: 'documenti', label: 'Documenti', href: '/documenti', permesso: ['works.view', 'assets.view'] },
    { chiave: 'fitosanitari', label: 'Registro fitosanitari', href: '/fitosanitari', permesso: ['works.view'] },
    { chiave: 'patentini', label: 'Patentini', href: '/patentini', permesso: ['works.view'] },
    { chiave: 'statistiche', label: 'Statistiche', href: '/statistiche', permesso: ['works.view'] },
];

export const SCHEDE_COMMITTENTI = [
    { chiave: 'committenti', label: 'Committenti', href: '/committenti', permesso: ['clients.view'] },
    { chiave: 'territorio', label: 'Territorio e portali', href: '/territorio', permesso: ['clients.view'] },
];

export const SCHEDE_IMPOSTAZIONI = [
    { chiave: 'impostazioni', label: 'Impostazioni', href: '/impostazioni', permesso: [] },
    { chiave: 'utenti', label: 'Utenti e studio', href: '/utenti', permesso: ['users.manage'] },
    { chiave: 'catalogo', label: 'Catalogo', href: '/catalogo', permesso: ['catalog.view'] },
    { chiave: 'listini', label: 'Listini', href: '/listini', permesso: ['works.view'] },
];

/** Le schede visibili a chi ha almeno uno dei permessi indicati (nessun permesso = tutti). */
export function schedeVisibili(schede, permessi) {
    return schede.filter((s) => s.permesso.length === 0 || s.permesso.some((p) => permessi.includes(p)));
}
