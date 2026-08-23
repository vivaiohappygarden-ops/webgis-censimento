/*
 * Messaggi di errore uniformi per i caricamenti.
 *
 * Il numero dell'errore compare di proposito: è la sola informazione che
 * permette di capire, da una schermata inviata da chi usa il programma, se il
 * server ha rifiutato per troppe richieste, per un permesso mancante o perché
 * non era raggiungibile.
 */
export function avvisoCaricamento(errore) {
    const stato = errore?.response?.status;

    if (stato === undefined) {
        return 'Dati non caricati: server non raggiungibile o connessione assente. Riprova.';
    }
    if (stato === 429) {
        return 'Dati non caricati: troppe richieste in pochi secondi. Attendi un momento e riprova.';
    }
    if (stato === 401 || stato === 419) {
        return 'La sessione è scaduta: esci e accedi di nuovo.';
    }
    if (stato === 403) {
        return 'Non hai il permesso di vedere questi dati.';
    }

    return `Dati non caricati (errore ${stato}). Riprova.`;
}

/** Primo errore di validazione, o il messaggio del server, o un ripiego. */
export function messaggioErrore(errore, predefinito = 'Errore imprevisto') {
    return Object.values(errore?.response?.data?.errors ?? {})[0]?.[0]
        ?? errore?.response?.data?.message
        ?? predefinito;
}
