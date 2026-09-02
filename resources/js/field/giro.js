/**
 * Giro del giorno: ordinamento dei lavori per vicinanza sul percorso
 * (vicino-piu'-vicino), calcolato interamente sul telefono.
 *
 * Modulo PURO, senza dipendenze e senza accesso a rete o database: le stesse
 * funzioni girano nel browser offline e nei test eseguibili con
 * `node --test tests/js/giro.test.mjs`. L'ordine prodotto e' solo un
 * SUGGERIMENTO di percorso: non tocca i dati, ne' locali ne' sul server.
 */

const RAGGIO_TERRA_KM = 6371;

/**
 * Distanza in linea d'aria fra due punti {lat, lon}, in chilometri
 * (haversine). Non e' la distanza stradale: basta per decidere quale lavoro
 * e' "il piu' vicino"; la strada vera la fa l'app di navigazione.
 */
export function distanzaKm(a, b) {
    const rad = (gradi) => (gradi * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLon = rad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2
        + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;

    return 2 * RAGGIO_TERRA_KM * Math.asin(Math.sqrt(h));
}

/**
 * Punto rappresentativo {lat, lon} da una geometria GeoJSON, o null.
 *
 * E' il RIPIEGO per i dati scaricati prima che il server calcolasse il punto
 * del lavoro (point_lon/point_lat nel bootstrap): per un poligono si fa la
 * media dei vertici dell'anello esterno, che su forme molto concave puo'
 * cadere fuori dall'area — per ordinare un giro va comunque bene. Il punto
 * "vero" (ST_PointOnSurface) arriva dal server al prossimo scarico.
 */
export function puntoDaGeoJson(geometria) {
    if (! geometria || ! Array.isArray(geometria.coordinates)) return null;
    const c = geometria.coordinates;
    switch (geometria.type) {
        case 'Point': return aPunto(c);
        case 'MultiPoint':
        case 'LineString': return aPunto(c[0]);
        case 'MultiLineString': return aPunto(c[0]?.[0]);
        case 'Polygon': return mediaAnello(c[0]);
        case 'MultiPolygon': return mediaAnello(c[0]?.[0]);
        default: return null;
    }
}

function aPunto(coppia) {
    if (! Array.isArray(coppia) || ! Number.isFinite(coppia[0]) || ! Number.isFinite(coppia[1])) return null;

    return { lon: coppia[0], lat: coppia[1] };
}

function mediaAnello(anello) {
    if (! Array.isArray(anello) || anello.length < 3) return null;
    // L'ultimo vertice di un anello GeoJSON ripete il primo: contarlo due
    // volte sposterebbe la media verso il punto di partenza del disegno
    const vertici = anello.slice(0, -1).map(aPunto).filter(Boolean);
    if (! vertici.length) return null;

    return {
        lon: vertici.reduce((s, v) => s + v.lon, 0) / vertici.length,
        lat: vertici.reduce((s, v) => s + v.lat, 0) / vertici.length,
    };
}

/**
 * Vero se il lavoro appartiene al giro di oggi (`oggi` = 'AAAA-MM-GG').
 * Regola: un lavoro in corso e' sempre del giorno; per gli altri conta il
 * periodo previsto (inizio..fine, o il solo giorno di inizio). I chiusi e
 * gli annullati non entrano mai, anche se il periodo copre oggi.
 */
export function eDelGiorno(lavoro, oggi) {
    if (lavoro.status === 'completed' || lavoro.status === 'cancelled') return false;
    if (lavoro.status === 'in_progress') return true;
    if (! lavoro.planned_start) return false;
    // Le date arrivano come ISO ('2026-09-02T00:00:00.000000Z' o '2026-09-02'):
    // i primi dieci caratteri sono il giorno, confrontabile come testo
    const inizio = String(lavoro.planned_start).slice(0, 10);
    const fine = lavoro.planned_end ? String(lavoro.planned_end).slice(0, 10) : inizio;

    return inizio <= oggi && oggi <= fine;
}

/**
 * Ordina i lavori col vicino-piu'-vicino a partire da `partenza`
 * ({lat, lon} o null se il GPS e' negato o assente).
 *
 * Ogni voce di `lavori` deve portare `punto` ({lat, lon} o null); il resto
 * della voce viene restituito intatto. Il risultato e' l'elenco nell'ordine
 * del giro, ognuno con `distanzaKm` dal passo precedente (null per la prima
 * tappa senza partenza). I lavori senza punto vanno IN CODA, sempre con
 * distanzaKm null: non spariscono mai dall'elenco.
 */
export function ordinaGiro(partenza, lavori) {
    const resto = lavori.filter((l) => l.punto);
    const senzaPunto = lavori.filter((l) => ! l.punto);

    const giro = [];
    let corrente = partenza ?? null;
    while (resto.length) {
        let scelto = 0;
        let dist = null;
        if (corrente) {
            dist = distanzaKm(corrente, resto[0].punto);
            for (let i = 1; i < resto.length; i += 1) {
                const d = distanzaKm(corrente, resto[i].punto);
                if (d < dist) {
                    dist = d;
                    scelto = i;
                }
            }
        }
        // Senza partenza si parte dal primo lavoro dell'elenco (l'interfaccia
        // lo dichiara) e la prima distanza resta ignota
        const [lavoro] = resto.splice(scelto, 1);
        giro.push({ ...lavoro, distanzaKm: dist });
        corrente = lavoro.punto;
    }

    return [...giro, ...senzaPunto.map((l) => ({ ...l, distanzaKm: null }))];
}

/** "350 m" sotto il chilometro, "1,2 km" fino a 10, poi chilometri interi. */
export function formattaDistanza(km) {
    if (km == null || ! Number.isFinite(km)) return '';
    if (km < 0.9995) return `${Math.round(km * 1000)} m`;
    if (km < 9.95) return `${km.toFixed(1).replace('.', ',')} km`;

    return `${Math.round(km)} km`;
}
