import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contornoSiIncrocia, poligonoAttorno } from '../../resources/js/geometria.js';

// Distanza sulla sfera fra due punti [lon, lat], in metri
function distanza([lon1, lat1], [lon2, lat2]) {
    const r = 6371008.8;
    const g = (x) => (x * Math.PI) / 180;
    const dLat = g(lat2 - lat1);
    const dLon = g(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(g(lat1)) * Math.cos(g(lat2)) * Math.sin(dLon / 2) ** 2;

    return 2 * r * Math.asin(Math.sqrt(a));
}

test('il poligono attorno alla posizione e\' chiuso, regolare e alla distanza chiesta', () => {
    const centro = [12.6381, 42.0347];
    const poligono = poligonoAttorno(centro[0], centro[1], 50);

    assert.equal(poligono.type, 'Polygon');
    const anello = poligono.coordinates[0];
    assert.equal(anello.length, 25);
    assert.deepEqual(anello[0], anello[24]);

    for (const punto of anello.slice(0, 24)) {
        const d = distanza(centro, punto);
        assert.ok(Math.abs(d - 50) < 0.5, `vertice a ${d.toFixed(2)} m invece di 50`);
    }
    assert.equal(contornoSiIncrocia(anello.slice(0, 24)), false);
});

test('il raggio si rispetta anche a latitudini alte e con pochi lati', () => {
    const centro = [11.3426, 46.4983];
    const anello = poligonoAttorno(centro[0], centro[1], 120, 8).coordinates[0];
    assert.equal(anello.length, 9);
    for (const punto of anello.slice(0, 8)) {
        assert.ok(Math.abs(distanza(centro, punto) - 120) < 1);
    }
});

test('l\'anello gira in senso antiorario (esterno GeoJSON)', () => {
    const anello = poligonoAttorno(9.19, 45.46, 30, 12).coordinates[0];
    let doppiaArea = 0;
    for (let i = 0; i < anello.length - 1; i++) {
        doppiaArea += anello[i][0] * anello[i + 1][1] - anello[i + 1][0] * anello[i][1];
    }
    assert.ok(doppiaArea > 0);
});
