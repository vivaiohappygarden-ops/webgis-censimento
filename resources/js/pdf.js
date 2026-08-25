// Apertura delle stampe PDF dall'API: un link diretto mostrerebbe JSON
// grezzo a sessione scaduta, qui invece si torna al login e gli errori
// arrivano a video in italiano.
export async function fetchPdf(url) {
    let response;
    try {
        response = await fetch(url, { headers: { Accept: 'application/pdf' } });
    } catch {
        return { error: 'Stampa non riuscita: problema di rete.' };
    }
    if (response.status === 401 || response.status === 419) {
        window.location.href = '/login';
        return { error: null };
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

        return { error: motivo ?? `Stampa non riuscita (errore ${response.status}): aggiorna la pagina e riprova.` };
    }
    const blobUrl = URL.createObjectURL(await response.blob());
    window.open(blobUrl, '_blank');
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
    return { error: null };
}
