/*
 * Le classi ricorrenti della veste nuova (bozza A, 26/09/2026), scritte una
 * volta sola: pulsanti, etichette di stato, carte. Sono stringhe di classi
 * Tailwind, non componenti, perche' le pagine le usano dentro <Link>,
 * <button> e <a> indifferentemente.
 *
 * Bersagli: 44px sul telefono, 36px con il mouse. Il fuoco da tastiera e'
 * sempre visibile (anello verde).
 */
const FUOCO = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700';

export const BOTTONE = `inline-flex min-h-11 md:min-h-[38px] items-center justify-center rounded-lg border border-green-700 bg-green-700 px-3.5 text-sm font-semibold text-white transition hover:bg-green-800 ${FUOCO}`;

export const BOTTONE_SECONDARIO = `inline-flex min-h-11 md:min-h-[38px] items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 text-sm font-semibold text-gray-900 transition hover:border-gray-400 hover:bg-gray-50 ${FUOCO}`;

export const BOTTONE_PICCOLO = `inline-flex min-h-11 md:min-h-9 items-center justify-center rounded-md border border-green-700 bg-white px-2.5 text-[13px] font-semibold text-green-800 transition hover:bg-green-50 ${FUOCO}`;

export const CARTA = 'rounded-[10px] border border-gray-200 bg-white';

export const ETICHETTA = 'text-xs font-semibold uppercase tracking-wide text-gray-500';

const CHIP_BASE = 'inline-flex min-h-6 items-center whitespace-nowrap rounded-full px-2.5 text-xs font-semibold';

/** Etichetta di stato: neutra, ok, attenzione, errore, informazione. */
export const CHIP = {
    neutra: `${CHIP_BASE} bg-gray-200 text-gray-700`,
    ok: `${CHIP_BASE} bg-green-100 text-green-800`,
    attenzione: `${CHIP_BASE} bg-amber-100 text-amber-800`,
    errore: `${CHIP_BASE} bg-red-100 text-red-800`,
    info: `${CHIP_BASE} bg-blue-100 text-blue-800`,
};

export function plurale(n, uno, molti) {
    return n === 1 ? uno : molti;
}
