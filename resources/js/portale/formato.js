/*
 * Formati e dizionari del portale del Comune (dal 26/09/2026), scritti una
 * volta sola per la pagina, la mappa e la scheda dell'elemento.
 */
import { CHIP } from '@/nuovo/stile';

/** Lo stato a quattro voci deciso dal committente (18/08/2026), con i colori della mappa pubblica. */
export const STATI = {
    sano: { etichetta: 'Sano', colore: '#15803d', chip: CHIP.ok },
    cura: { etichetta: 'In cura', colore: '#0369a1', chip: CHIP.info },
    potare: { etichetta: 'Da potare', colore: '#b45309', chip: CHIP.attenzione },
    verifica: { etichetta: 'In verifica', colore: '#b91c1c', chip: CHIP.errore },
    altro: { etichetta: 'Altri elementi', colore: '#6b7280', chip: CHIP.neutra },
};

export const STATO_LAVORO = {
    planned: CHIP.info,
    assigned: CHIP.info,
    in_progress: CHIP.attenzione,
    suspended: CHIP.neutra,
    completed: CHIP.ok,
    cancelled: CHIP.neutra,
    draft: CHIP.neutra,
};

export const STATO_RICHIESTA = {
    open: { etichetta: 'Aperta', chip: CHIP.errore },
    in_charge: { etichetta: 'In carico', chip: CHIP.attenzione },
    resolved: { etichetta: 'Risolta', chip: CHIP.ok },
    dismissed: { etichetta: 'Archiviata', chip: CHIP.neutra },
};

export const URGENZA = { low: 'Bassa', medium: 'Media', high: 'Alta', critical: 'Critica' };

export function formatData(valore) {
    if (! valore) return '—';
    const [a, m, g] = String(valore).slice(0, 10).split('-');

    return `${g}/${m}/${a}`;
}

export function num(v, decimali = 1) {
    if (v === null || v === undefined || v === '') return null;

    return Number(v).toLocaleString('it-IT', { maximumFractionDigits: decimali });
}

export function misura(v, unita, decimali = 1) {
    const n = num(v, decimali);

    return n === null ? '—' : `${n} ${unita}`;
}

export const oggiIso = () => new Date().toISOString().slice(0, 10);

/** "3 alberi" / "1 albero", con la cifra in evidenza a cura di chi la stampa. */
export function conta(n, uno, molti) {
    return `${num(n, 0)} ${n === 1 ? uno : molti}`;
}
