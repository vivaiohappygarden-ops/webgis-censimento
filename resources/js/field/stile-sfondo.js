import { layers, namedFlavor } from '@protomaps/basemaps';

/*
 * Lo stile MapLibre dello sfondo per l'uso senza rete: i livelli "light" di
 * Protomaps (dati OpenStreetMap, schema v4) con le scritte in italiano. I
 * caratteri delle etichette sono quelli ospitati in casa per la mappa del
 * gestionale (DejaVu Sans in public/mappa/font): niente richieste a server
 * esterni, e i glifi restano nella cache del service worker per l'offline.
 * Modulo puro (niente browser): lo provano i test in tests/js.
 */
export const CHIAVE_PROTOCOLLO = 'sfondo-territorio';

export const SORGENTE = 'sfondo';

/** I caratteri Noto previsti dallo stile Protomaps e quelli di casa che li sostituiscono. */
export const CARATTERI = {
    'Noto Sans Devanagari Regular v1': 'DejaVuSans',
    'Noto Sans Medium': 'DejaVuSansBold',
    'Noto Sans Regular': 'DejaVuSans',
    'Noto Sans Italic': 'DejaVuSans',
};

const NOMI_NOTO = new RegExp(Object.keys(CARATTERI).join('|'), 'g');

/** Livelli fatti solo di icone (frecce dei sensi unici, targhe stradali, punti d'interesse): senza sprite non hanno senso. */
export const LIVELLI_SOLO_ICONE = ['roads_oneway', 'roads_shields', 'pois'];

/**
 * I livelli dello sfondo. Sul telefono non c'e' uno sprite: i livelli fatti
 * solo di icone restano fuori e agli altri (i nomi dei Comuni, che hanno un
 * puntino accanto) si tolgono le icone tenendo il testo.
 */
export function livelliSfondo(sorgente = SORGENTE) {
    return layers(sorgente, namedFlavor('light'), { lang: 'it' })
        .filter((livello) => ! LIVELLI_SOLO_ICONE.includes(livello.id))
        .map((livello) => {
            const copia = JSON.parse(JSON.stringify(livello).replace(NOMI_NOTO, (nome) => CARATTERI[nome]));
            for (const blocco of ['layout', 'paint']) {
                for (const chiave of Object.keys(copia[blocco] ?? {})) {
                    if (chiave.startsWith('icon-')) delete copia[blocco][chiave];
                }
            }

            return copia;
        });
}

/**
 * Lo stile completo: la sorgente e' l'archivio PMTiles registrato nel
 * protocollo con la chiave data (un Blob sul telefono o un indirizzo).
 */
export function stileSfondo({ glifi, chiave = CHIAVE_PROTOCOLLO } = {}) {
    return {
        version: 8,
        glyphs: glifi,
        sources: {
            [SORGENTE]: {
                type: 'vector',
                url: `pmtiles://${chiave}`,
                attribution: '© OpenStreetMap contributors · Protomaps',
            },
        },
        layers: livelliSfondo(SORGENTE),
    };
}
