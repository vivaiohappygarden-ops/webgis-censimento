/*
 * Le classi ricorrenti della veste del gestionale, scritte una volta sola:
 * pulsanti, targhette di stato, carte, etichette. Sono stringhe di classi
 * Tailwind, non componenti, perche' le pagine le usano dentro <Link>,
 * <button> e <a> indifferentemente.
 *
 * Veste "Lo strumento" (bozza B, scelta dal committente il 09/10/2026):
 * angoli di 2 px, bordi al posto delle ombre, targhette squadrate con il
 * bordo e il carattere a spaziatura fissa al posto delle pillole colorate.
 * I colori e i caratteri stanno in resources/css/app.css.
 *
 * Bersagli: 44px sul telefono, 36px con il mouse. Il fuoco da tastiera e'
 * sempre visibile (anello verde).
 */
const FUOCO = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700';

export const BOTTONE = `inline-flex min-h-11 md:min-h-[38px] items-center justify-center gap-2 rounded-sm border border-green-700 bg-green-700 px-3.5 text-sm font-medium text-white transition hover:bg-green-800 ${FUOCO}`;

export const BOTTONE_SECONDARIO = `inline-flex min-h-11 md:min-h-[38px] items-center justify-center gap-2 rounded-sm border border-gray-900 bg-white px-3.5 text-sm font-medium text-gray-900 transition hover:bg-gray-50 ${FUOCO}`;

export const BOTTONE_PICCOLO = `inline-flex min-h-11 md:min-h-9 items-center justify-center gap-1.5 rounded-sm border border-green-700 bg-white px-2.5 text-[13px] font-medium text-green-700 transition hover:bg-green-50 ${FUOCO}`;

export const CARTA = 'rounded-sm border border-gray-300 bg-white';

/** Titolo di una carta: maiuscoletto spaziato, come l'intestazione di un registro. */
export const TITOLO_CARTA = 'text-[15px] font-semibold uppercase tracking-[0.05em] text-gray-900';

export const ETICHETTA = 'text-xs font-medium uppercase tracking-wider text-gray-500';

/** Cartellini, codici, date e misure: a spaziatura fissa, cosi' si allineano da soli. */
export const CODICE = 'font-mono';

const TARGA_BASE = 'inline-flex min-h-[22px] items-center whitespace-nowrap rounded-sm border border-current px-1.5 font-mono text-[12px] uppercase tracking-[0.04em]';

/** Targhetta di stato: neutra, ok, attenzione, errore, informazione. */
export const CHIP = {
    neutra: `${TARGA_BASE} text-gray-500`,
    ok: `${TARGA_BASE} text-green-700`,
    attenzione: `${TARGA_BASE} text-amber-700`,
    errore: `${TARGA_BASE} text-red-600`,
    info: `${TARGA_BASE} text-blue-700`,
};

/** Targhetta di tipo (Lavoro, VTA, Segnalazione...): bordo tratteggiato, grigia. */
export const TARGA_TIPO = `${TARGA_BASE} border-dashed text-gray-500`;

export function plurale(n, uno, molti) {
    return n === 1 ? uno : molti;
}
