/*
 * Le proposte di specie nell'app di campo, dal dizionario scaricato sul
 * telefono (dal 11/10/2026).
 *
 * Prima il campo aveva una <datalist> con tutti i nomi conosciuti (fino a
 * 800 voci fra nomi botanici, comuni e regionali): Safari su iPhone la
 * ripassava a ogni lettera e la pagina si bloccava ("mentre digito la specie
 * si blocca il sito"). Qui le proposte le calcoliamo noi, al massimo LIMITE,
 * e il campo resta un campo di testo normale.
 *
 * Modulo senza browser al caricamento: le prove in Node gli passano le voci.
 */
import { parole } from '../ricerca.js';

/** Quante proposte al massimo sotto il campo. */
export const LIMITE = 8;

/** Sotto queste lettere non si propone niente. */
export const MINIMO_LETTERE = 2;

/** Minuscolo, senza accenti, spazi ridotti: la forma in cui si confrontano i nomi. */
export function normalizza(testo) {
    return String(testo ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ');
}

/** Vero se i due nomi sono lo stesso a meno di maiuscole, accenti e spazi. */
export function stessoNome(a, b) {
    return normalizza(a) !== '' && normalizza(a) === normalizza(b);
}

/** I nomi con cui una voce si lascia trovare: botanico, comune, sinonimi. */
export function nomiVoce(voce) {
    return [voce?.species, voce?.common_name, ...(Array.isArray(voce?.synonyms) ? voce.synonyms : [])]
        .map((n) => String(n ?? '').trim())
        .filter((n) => n !== '');
}

/**
 * La voce il cui nome (botanico, comune o sinonimo) e' esattamente il testo
 * scritto, oppure null. Serve a completare genere e altro nome quando si lascia
 * il campo: "pino romano" e' Pinus pinea.
 */
export function voceEsatta(voci, testo) {
    const scritto = normalizza(testo);
    if (scritto.length < 3) return null;

    return (voci ?? []).find((v) => nomiVoce(v).some((n) => normalizza(n) === scritto)) ?? null;
}

/**
 * Le proposte per il testo scritto, in ordine: un nome uguale, un nome che
 * inizia cosi', una parola che inizia cosi', poi le voci in cui compaiono tutte
 * le parole scritte (anche in mezzo). A parita' in ordine alfabetico; al
 * massimo `limite`.
 */
export function proposteSpecie(voci, testo, limite = LIMITE) {
    const scritto = normalizza(testo);
    if (scritto.length < MINIMO_LETTERE) return [];
    const richieste = parole(scritto);

    const punteggio = (voce) => {
        const nomi = nomiVoce(voce).map(normalizza);
        let migliore = Infinity;
        for (const nome of nomi) {
            let p = Infinity;
            if (nome === scritto) p = 0;
            else if (nome.startsWith(scritto)) p = 1;
            else if (nome.split(' ').some((parola) => parola.startsWith(scritto))) p = 2;
            if (p < migliore) migliore = p;
        }
        // "Contiene" solo da tre lettere: con due, "ti" pescherebbe anche Celtis
        if (migliore === Infinity && scritto.length >= 3 && richieste.every((r) => nomi.some((n) => n.includes(r)))) {
            migliore = 3;
        }

        return migliore;
    };

    return (voci ?? [])
        .map((voce) => ({ voce, p: punteggio(voce) }))
        .filter((r) => r.p !== Infinity)
        .sort((a, b) => a.p - b.p || String(a.voce.species ?? '').localeCompare(String(b.voce.species ?? ''), 'it'))
        .slice(0, limite)
        .map((r) => r.voce);
}

/**
 * Le specie gia' scritte sugli alberi di questo telefono che il dizionario non
 * conosce, come voci proprie ("locale"): valgono anche senza rete e portano
 * con se' il nome comune e il genere con cui sono state scritte.
 */
export function vociDaAlberi(alberi, dizionario = []) {
    const note = new Set((dizionario ?? []).map((v) => normalizza(v?.species)));
    const viste = new Map();
    for (const albero of alberi ?? []) {
        const species = String(albero?.species ?? '').trim();
        const chiave = normalizza(species);
        if (chiave === '' || note.has(chiave) || viste.has(chiave)) continue;
        viste.set(chiave, {
            id: `locale:${chiave}`,
            species,
            common_name: String(albero.common_name ?? '').trim() || null,
            genus: String(albero.genus ?? '').trim() || species.split(' ')[0],
            synonyms: [],
            locale: true,
        });
    }

    return [...viste.values()];
}

/** Il dizionario piu' le specie proprie del telefono, senza doppioni. */
export function unisciVoci(dizionario, alberi) {
    return [...(dizionario ?? []), ...vociDaAlberi(alberi, dizionario)];
}
