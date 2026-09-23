/*
 * Controlli di buon senso sulle geometrie disegnate a mano.
 *
 * Un contorno che si incrocia su se stesso (i vertici toccati "a clessidra")
 * verrebbe salvato lo stesso dal database, ma con una superficie che non
 * significa niente: meglio fermarsi prima e dirlo a chi disegna.
 */

function orientamento(p, q, r) {
    const v = (q[1] - p[1]) * (r[0] - q[0]) - (q[0] - p[0]) * (r[1] - q[1]);
    if (v === 0) return 0;

    return v > 0 ? 1 : 2;
}

function sulSegmento(p, q, r) {
    return q[0] <= Math.max(p[0], r[0]) && q[0] >= Math.min(p[0], r[0])
        && q[1] <= Math.max(p[1], r[1]) && q[1] >= Math.min(p[1], r[1]);
}

function segmentiSiIncrociano(p1, q1, p2, q2) {
    const o1 = orientamento(p1, q1, p2);
    const o2 = orientamento(p1, q1, q2);
    const o3 = orientamento(p2, q2, p1);
    const o4 = orientamento(p2, q2, q1);

    if (o1 !== o2 && o3 !== o4) return true;
    if (o1 === 0 && sulSegmento(p1, p2, q1)) return true;
    if (o2 === 0 && sulSegmento(p1, q2, q1)) return true;
    if (o3 === 0 && sulSegmento(p2, p1, q2)) return true;
    if (o4 === 0 && sulSegmento(p2, q1, q2)) return true;

    return false;
}

/**
 * Vero se l'anello chiuso costruito sui punti (senza ripetere il primo)
 * si incrocia su se stesso. I lati adiacenti, che condividono un vertice,
 * non contano come incrocio.
 *
 * @param {Array<[number, number]>} punti
 */
export function contornoSiIncrocia(punti) {
    const n = punti.length;
    if (n < 4) return false;

    const lati = punti.map((p, i) => [p, punti[(i + 1) % n]]);
    for (let i = 0; i < n; i++) {
        for (let j = i + 2; j < n; j++) {
            // Il primo e l'ultimo lato sono adiacenti: condividono punti[0]
            if (i === 0 && j === n - 1) continue;
            if (segmentiSiIncrociano(lati[i][0], lati[i][1], lati[j][0], lati[j][1])) return true;
        }
    }

    return false;
}

/**
 * Il perimetro provvisorio di un'area attorno a una posizione: un poligono
 * regolare di `lati` lati inscritto nel cerchio di raggio `raggioM` metri.
 * Serve in campo quando non si cammina il confine (decisione committente
 * 23/09/2026): l'area nasce "prevista" e l'ufficio la ridisegna. Le
 * coordinate sono [lon, lat] in gradi WGS84; il passaggio da metri a gradi
 * usa il raggio terrestre medio e il coseno della latitudine, piu' che
 * sufficiente per qualche centinaio di metri. Anello antiorario e chiuso,
 * come vuole il GeoJSON.
 *
 * @returns {{type: 'Polygon', coordinates: Array<Array<[number, number]>>}}
 */
export function poligonoAttorno(lon, lat, raggioM, lati = 24) {
    const raggioTerra = 6371008.8;
    const latitudine = Math.max(-89, Math.min(89, lat));
    const dLat = (raggioM / raggioTerra) * (180 / Math.PI);
    const dLon = dLat / Math.cos((latitudine * Math.PI) / 180);
    const anello = [];
    for (let i = 0; i < lati; i++) {
        const angolo = (2 * Math.PI * i) / lati;
        anello.push([
            Number((lon + dLon * Math.cos(angolo)).toFixed(7)),
            Number((latitudine + dLat * Math.sin(angolo)).toFixed(7)),
        ]);
    }
    anello.push(anello[0]);

    return { type: 'Polygon', coordinates: [anello] };
}
