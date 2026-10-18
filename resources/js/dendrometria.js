/*
 * Le misure del tronco legate da una formula (dal 18/10/2026).
 *
 * Richiesta del committente, davanti ai campi "Diametro del tronco a 1,30 m" e
 * "Circonferenza del tronco": "questi calcoli non si possono mettere
 * automatici?". La circonferenza e' pi greco per il diametro: se ne misura una
 * (il metro da' la circonferenza, il cavalletto il diametro) e l'altra si
 * scrive da sola. La circonferenza si arrotonda al centimetro, come si legge
 * sul metro; il diametro al decimo, che e' la precisione della colonna.
 *
 * Modulo senza browser: lo usano i moduli del gestionale e dell'app di campo e
 * le prove in Node.
 */

export const CAMPI_TRONCO = ['dbh_cm', 'trunk_circumference_cm'];

/** Il numero scritto in un campo (anche con la virgola), o null se vuoto o non numerico. */
export function numeroMisura(valore) {
    if (valore === '' || valore === null || valore === undefined) return null;
    const n = typeof valore === 'number' ? valore : Number(String(valore).trim().replace(',', '.'));

    return Number.isFinite(n) ? n : null;
}

/** Circonferenza in cm interi dal diametro in cm, o null senza un diametro positivo. */
export function circonferenzaDaDiametro(diametro) {
    const d = numeroMisura(diametro);
    if (d === null || d <= 0) return null;

    return Math.round(d * Math.PI);
}

/** Diametro in cm con un decimale dalla circonferenza in cm, o null senza una circonferenza positiva. */
export function diametroDaCirconferenza(circonferenza) {
    const c = numeroMisura(circonferenza);
    if (c === null || c <= 0) return null;

    return Math.round((c / Math.PI) * 10) / 10;
}

/**
 * Scritto un valore in uno dei due campi del tronco, l'altro si riscrive dalla
 * formula. Svuotando un campo l'altro resta com'e': non lo si e' toccato.
 * Gli altri campi non c'entrano e si ignorano, cosi' il gestore vale per
 * qualunque campo di un modulo.
 */
export function legaTronco(misure, campo, valore) {
    const n = numeroMisura(valore);
    if (n === null || n <= 0) return misure;
    if (campo === 'dbh_cm') misure.trunk_circumference_cm = circonferenzaDaDiametro(n);
    if (campo === 'trunk_circumference_cm') misure.dbh_cm = diametroDaCirconferenza(n);

    return misure;
}
