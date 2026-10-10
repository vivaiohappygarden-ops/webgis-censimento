import axios from 'axios';
import * as maplibregl from 'maplibre-gl';
import { PMTiles, Protocol } from 'pmtiles';
import { CHIAVE_PROTOCOLLO, stileSfondo } from './stile-sfondo';

/*
 * Lo sfondo della mappa sul telefono (dal 10/10/2026): il server prepara un
 * archivio PMTiles del territorio dell'organizzazione (sfondo:prepara), l'app
 * di campo lo scarica una volta nella tabella Dexie "sfondo" e MapLibre lo
 * legge dal Blob locale attraverso il protocollo pmtiles://, con la rete o
 * senza. Niente tessere di OpenStreetMap scaricate in blocco: le loro regole
 * d'uso lo vietano, e qui il server ritaglia un estratto dei dati aperti.
 */
export const CHIAVE_LOCALE = 'territorio';

let protocollo = null;

/** Il protocollo pmtiles:// si registra una volta per pagina. */
export function protocolloPmTiles() {
    if (! protocollo) {
        protocollo = new Protocol();
        maplibregl.addProtocol('pmtiles', protocollo.tile);
    }

    return protocollo;
}

export async function statoSfondoServer() {
    const { data } = await axios.get('/api/v1/sfondo');

    return data.data;
}

export async function sfondoLocale(db) {
    return (await db.sfondo.get(CHIAVE_LOCALE)) ?? null;
}

export async function eliminaSfondoLocale(db) {
    await db.sfondo.delete(CHIAVE_LOCALE);
}

/**
 * Scarica il file intero e lo conserva nel database locale. L'avanzamento
 * arriva in byte letti e totali; il totale puo' mancare se il server non
 * dichiara la lunghezza.
 */
export async function scaricaSfondo(db, stato, avanzamento = () => {}) {
    const risposta = await fetch(stato.url, { credentials: 'same-origin', headers: { Accept: 'application/vnd.pmtiles' } });
    if (! risposta.ok) {
        throw new Error(`Scarico dello sfondo non riuscito (errore ${risposta.status}).`);
    }
    const totale = Number(risposta.headers.get('Content-Length')) || stato.byte || 0;
    const pezzi = [];
    let letti = 0;
    const lettore = risposta.body?.getReader?.();
    if (lettore) {
        while (true) {
            const { done, value } = await lettore.read();
            if (done) break;
            pezzi.push(value);
            letti += value.byteLength;
            avanzamento(letti, totale);
        }
    } else {
        pezzi.push(new Uint8Array(await risposta.arrayBuffer()));
    }
    const blob = new Blob(pezzi, { type: 'application/vnd.pmtiles' });
    if (stato.byte && blob.size !== stato.byte) {
        throw new Error('Lo sfondo scaricato e\' incompleto: riprova quando la rete e\' stabile.');
    }
    // Valori semplici, non gli oggetti reattivi della pagina: IndexedDB clona
    // il record e un Proxy di Vue non si lascia clonare
    const record = {
        key: CHIAVE_LOCALE,
        blob,
        versione: String(stato.versione ?? ''),
        byte: blob.size,
        riquadro: Array.isArray(stato.riquadro) ? Array.from(stato.riquadro, Number) : null,
        generato_il: stato.generato_il ? String(stato.generato_il) : null,
        scaricato_il: new Date().toISOString(),
    };
    await db.sfondo.put(record);

    return record;
}

/** Una sorgente PMTiles che legge a intervalli da un Blob (il file conservato sul telefono). */
export function sorgenteBlob(blob, chiave = CHIAVE_PROTOCOLLO) {
    return {
        getKey: () => chiave,
        getBytes: async (offset, lunghezza) => ({ data: await blob.slice(offset, offset + lunghezza).arrayBuffer() }),
    };
}

/** Registra il Blob nel protocollo e restituisce lo stile pronto per MapLibre. */
export function montaSfondo(blob) {
    protocolloPmTiles().add(new PMTiles(sorgenteBlob(blob)));

    return stileSfondo({ glifi: `${window.location.origin}/mappa/font/{fontstack}/{range}.pbf` });
}

/**
 * I glifi delle etichette passano dal service worker (cache-first): si
 * chiedono una volta con la rete, cosi' le scritte escono anche senza.
 */
export async function scaldaGlifi() {
    const intervalli = ['0-255', '256-511', '512-767', '8192-8447'];
    await Promise.allSettled(['DejaVuSans', 'DejaVuSansBold']
        .flatMap((carattere) => intervalli.map((r) => fetch(`/mappa/font/${carattere}/${r}.pbf`))));
}
