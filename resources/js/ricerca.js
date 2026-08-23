/**
 * Ricerca a parole, la stessa che fa il server (App\Support\RicercaTestuale).
 *
 * Serve agli elenchi che si filtrano nella pagina, senza chiedere niente al
 * server: cercando "rossi mario" deve trovare "Mario Rossi", come altrove.
 */
const MASSIMO_PAROLE = 6;

/** @return {string[]} le parole scritte, in minuscolo */
export function parole(cercato) {
    return String(cercato ?? '')
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .filter((p) => p !== '')
        .slice(0, MASSIMO_PAROLE);
}

/**
 * Vero se in uno dei testi si trovano TUTTE le parole cercate, in qualunque
 * ordine e anche in mezzo a una parola più lunga.
 *
 * @param {string[]|string} testi campi in cui cercare
 */
export function corrisponde(testi, cercato) {
    const richieste = parole(cercato);
    if (richieste.length === 0) return true;

    const dove = (Array.isArray(testi) ? testi : [testi])
        .filter((t) => t !== null && t !== undefined)
        .map((t) => String(t).toLowerCase());

    return richieste.every((parola) => dove.some((testo) => testo.includes(parola)));
}
