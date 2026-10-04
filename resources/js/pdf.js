// Apertura delle stampe PDF dall'API: un link diretto mostrerebbe JSON
// grezzo a sessione scaduta, qui invece si torna al login e gli errori
// arrivano a video in italiano.
//
// Opzioni: method e body per le stampe che mandano dati (la mappa manda la
// sua immagine); apri=false per avere l'indirizzo del PDF senza aprirlo (chi
// ha gia' aperto una scheda dentro il clic ce lo porta da se').
export async function fetchPdf(url, opzioni = {}) {
    const { method = 'GET', body = null, apri = true } = opzioni;
    let response;
    try {
        const headers = { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' };
        let corpo;
        if (body !== null) {
            headers['Content-Type'] = 'application/json';
            const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
            if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf[1]);
            corpo = JSON.stringify(body);
        }
        response = await fetch(url, { method, headers, body: corpo, credentials: 'same-origin' });
    } catch {
        return { error: 'Stampa non riuscita: problema di rete.', url: null };
    }
    if (response.status === 401 || response.status === 419) {
        window.location.href = '/login';
        return { error: null, url: null };
    }
    if (! response.ok) {
        // Spesso il server sa il motivo esatto (es. "la perizia non si può
        // emettere senza la classe di propensione al cedimento"): mostrarlo
        // vale più di un invito generico a riprovare
        let motivo = null;
        try {
            const corpo = await response.json();
            motivo = Object.values(corpo.errors ?? {})[0]?.[0] ?? corpo.message ?? null;
        } catch {
            // corpo non leggibile: resta il messaggio generico col numero
        }

        return { error: motivo ?? `Stampa non riuscita (errore ${response.status}): aggiorna la pagina e riprova.`, url: null };
    }
    const blobUrl = URL.createObjectURL(await response.blob());
    if (apri) {
        window.open(blobUrl, '_blank');
        setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
    }
    return { error: null, url: blobUrl };
}
