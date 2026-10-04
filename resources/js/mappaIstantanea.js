// L'inquadratura della mappa a video, come il browser la manda alle stampe:
// l'immagine JPEG (entro una larghezza massima), i confini geografici e
// l'attribuzione dello sfondo. Lo sfondo stradale arriva da server esterni
// che il browser ha gia' interrogato per mostrarlo: il server non li chiama,
// ci disegna sopra. La mappa deve avere preserveDrawingBuffer, altrimenti il
// disegno non si rilegge dal canvas.

/**
 * L'attribuzione dello sfondo letta dallo stile della mappa, quando il
 * controllo a video non l'ha ancora scritta (sorgenti usate dai livelli
 * visibili, senza HTML).
 */
export function attribuzioneDaStile(map) {
    try {
        const stile = map.getStyle();
        const usate = new Set(stile.layers.filter((l) => l.layout?.visibility !== 'none' && l.source).map((l) => l.source));
        const testi = [...usate].map((id) => stile.sources[id]?.attribution).filter(Boolean)
            .map((t) => String(t).replace(/<[^>]+>/g, '').trim()).filter(Boolean);
        return [...new Set(testi)].join(' - ') || null;
    } catch {
        return null;
    }
}

/**
 * L'istantanea della mappa, o null se non si puo' fare (mappa assente,
 * ruotata, inclinata o in movimento: la proiezione piana della planimetria
 * vale solo con il nord in alto; tela vuota). Il canvas si legge subito dopo
 * un disegno (evento "render" provocato da triggerRepaint): e' il momento in
 * cui il buffer e' sicuramente pieno, anche dove il browser non lo conserva.
 */
export function istantaneaMappa(map, contenitore, { larghezzaMassima = 1600 } = {}) {
    if (! map) return Promise.resolve(null);
    try {
        if (Math.abs(map.getBearing()) > 0.01 || Math.abs(map.getPitch()) > 0.01 || map.isMoving()) return Promise.resolve(null);
    } catch {
        return Promise.resolve(null);
    }
    return new Promise((risolvi) => {
        let fatto = false;
        const concludi = (valore) => { if (! fatto) { fatto = true; risolvi(valore); } };
        const leggi = () => concludi(leggiCanvas(map, contenitore, larghezzaMassima));
        try {
            map.once('render', leggi);
            map.triggerRepaint();
        } catch {
            concludi(null);
        }
        // Una mappa che non ridisegna piu' non deve bloccare la stampa
        setTimeout(() => concludi(null), 2000);
    });
}

function leggiCanvas(map, contenitore, larghezzaMassima) {
    try {
        const canvas = map.getCanvas();
        if (! canvas?.width || ! canvas?.height) return null;
        const riduzione = Math.min(1, larghezzaMassima / canvas.width);
        let sorgente = canvas;
        if (riduzione < 1) {
            const tela = document.createElement('canvas');
            tela.width = Math.round(canvas.width * riduzione);
            tela.height = Math.round(canvas.height * riduzione);
            tela.getContext('2d').drawImage(canvas, 0, 0, tela.width, tela.height);
            sorgente = tela;
        }
        const immagine = sorgente.toDataURL('image/jpeg', 0.9);
        if (immagine.length < 1000) return null;
        const b = map.getBounds();
        return {
            immagine,
            bounds: { west: b.getWest(), south: b.getSouth(), east: b.getEast(), north: b.getNorth() },
            larghezza: sorgente.width,
            altezza: sorgente.height,
            attribuzione: contenitore?.querySelector?.('.maplibregl-ctrl-attribution')?.textContent?.trim() || attribuzioneDaStile(map),
        };
    } catch {
        return null;
    }
}
