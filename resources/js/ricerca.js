/**
 * Ricerca a parole, la stessa che fa il server (App\Support\RicercaTestuale).
 *
 * Serve agli elenchi che si filtrano nella pagina, senza chiedere niente al
 * server: cercando "rossi mario" deve trovare "Mario Rossi", come altrove.
 *
 * Anche gli accenti non contano, come sul server (estensione unaccent):
 * "citta" trova "Città". Qui si scompone il testo (NFD) e si tolgono i segni
 * diacritici combinati: copre le lettere accentate delle lingue latine, cioè
 * quelle che compaiono in specie, aree e committenti.
 */
const MASSIMO_PAROLE = 6;

/**
 * Il testo senza accenti: "à" scomposto (NFD) diventa "a" più un accento
 * combinato (U+0300..U+036F); tolti i segni resta la lettera nuda.
 */
function senzaAccenti(testo) {
    return testo.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

/** @return {string[]} le parole scritte, in minuscolo e senza accenti */
export function parole(cercato) {
    return senzaAccenti(String(cercato ?? '').trim().toLowerCase())
        .split(/\s+/)
        .filter((p) => p !== '')
        .slice(0, MASSIMO_PAROLE);
}

/**
 * Vero se in uno dei testi si trovano TUTTE le parole cercate, in qualunque
 * ordine e anche in mezzo a una parola più lunga; maiuscole e accenti non
 * contano da nessuna delle due parti.
 *
 * @param {string[]|string} testi campi in cui cercare
 */
export function corrisponde(testi, cercato) {
    const richieste = parole(cercato);
    if (richieste.length === 0) return true;

    const dove = (Array.isArray(testi) ? testi : [testi])
        .filter((t) => t !== null && t !== undefined)
        .map((t) => senzaAccenti(String(t).toLowerCase()));

    return richieste.every((parola) => dove.some((testo) => testo.includes(parola)));
}
