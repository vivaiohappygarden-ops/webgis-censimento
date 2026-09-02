/**
 * Prove del modulo puro del "giro del giorno" (resources/js/field/giro.js).
 *
 * Il progetto non ha un test runner JavaScript: si usa quello incorporato in
 * Node. Per lanciarle:
 *
 *     node --test tests/js/giro.test.mjs
 *
 * Non serve alcuna dipendenza ne' build: il modulo sotto prova non importa
 * nulla, proprio perche' deve girare identico anche qui.
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    distanzaKm, eDelGiorno, formattaDistanza, ordinaGiro, puntoDaGeoJson,
} from '../../resources/js/field/giro.js';

// Un grado di latitudine vale ovunque ~111,195 km (2 * pi * 6371 / 360):
// serve come riferimento indipendente per controllare la haversine
const KM_PER_GRADO_LAT = (2 * Math.PI * 6371) / 360;

test('distanza su coordinate note: un grado di latitudine', () => {
    const d = distanzaKm({ lat: 45, lon: 9 }, { lat: 46, lon: 9 });
    assert.ok(Math.abs(d - KM_PER_GRADO_LAT) < 0.01, `attesi ~${KM_PER_GRADO_LAT}, avuti ${d}`);
});

test('distanza zero fra punti coincidenti', () => {
    assert.equal(distanzaKm({ lat: 45.4642, lon: 9.19 }, { lat: 45.4642, lon: 9.19 }), 0);
});

test('distanza sulla longitudine si accorcia col coseno della latitudine', () => {
    // A 60 gradi di latitudine un grado di longitudine vale meta' di uno all'equatore
    const d = distanzaKm({ lat: 60, lon: 9 }, { lat: 60, lon: 10 });
    assert.ok(Math.abs(d - KM_PER_GRADO_LAT / 2) < 0.2, `attesi ~${KM_PER_GRADO_LAT / 2}, avuti ${d}`);
});

test('tre punti in linea: il giro li percorre in ordine di vicinanza', () => {
    // Partenza a sud, lavori a 0,03 / 0,01 / 0,02 gradi piu' a nord (mischiati)
    const lavori = [
        { id: 'C', punto: { lat: 45.03, lon: 9 } },
        { id: 'A', punto: { lat: 45.01, lon: 9 } },
        { id: 'B', punto: { lat: 45.02, lon: 9 } },
    ];
    const giro = ordinaGiro({ lat: 45.0, lon: 9 }, lavori);

    assert.deepEqual(giro.map((t) => t.id), ['A', 'B', 'C']);
    // Ogni passo e' di 0,01 gradi di latitudine: ~1,112 km
    for (const tappa of giro) {
        assert.ok(Math.abs(tappa.distanzaKm - KM_PER_GRADO_LAT / 100) < 0.001,
            `passo ${tappa.id}: ${tappa.distanzaKm}`);
    }
});

test('senza partenza il giro comincia dal primo lavoro, con distanza ignota', () => {
    const lavori = [
        { id: 'B', punto: { lat: 45.02, lon: 9 } },
        { id: 'C', punto: { lat: 45.03, lon: 9 } },
        { id: 'A', punto: { lat: 45.00, lon: 9 } },
    ];
    const giro = ordinaGiro(null, lavori);

    // Prima tappa: il primo dell'elenco (B), non il piu' a sud
    assert.equal(giro[0].id, 'B');
    assert.equal(giro[0].distanzaKm, null);
    // Dalle tappe successive si riparte dal vicino-piu'-vicino a B:
    // C dista 0,01 gradi, A 0,02
    assert.deepEqual(giro.slice(1).map((t) => t.id), ['C', 'A']);
    assert.ok(giro[1].distanzaKm > 0);
});

test('i lavori senza punto vanno in coda, mai persi', () => {
    const lavori = [
        { id: 'ignoto1', punto: null },
        { id: 'B', punto: { lat: 45.02, lon: 9 } },
        { id: 'ignoto2', punto: null },
        { id: 'A', punto: { lat: 45.01, lon: 9 } },
    ];
    const giro = ordinaGiro({ lat: 45.0, lon: 9 }, lavori);

    assert.deepEqual(giro.map((t) => t.id), ['A', 'B', 'ignoto1', 'ignoto2']);
    assert.equal(giro[2].distanzaKm, null);
    assert.equal(giro[3].distanzaKm, null);
    assert.equal(giro.length, lavori.length);
});

test('giro vuoto con elenco vuoto', () => {
    assert.deepEqual(ordinaGiro({ lat: 45, lon: 9 }, []), []);
});

test('eDelGiorno: in corso sempre, chiusi mai, gli altri per periodo previsto', () => {
    const oggi = '2026-09-02';
    assert.equal(eDelGiorno({ status: 'in_progress' }, oggi), true);
    assert.equal(eDelGiorno({ status: 'assigned', planned_start: '2026-09-02T00:00:00.000000Z' }, oggi), true);
    assert.equal(eDelGiorno({ status: 'assigned', planned_start: '2026-09-01', planned_end: '2026-09-05' }, oggi), true);
    assert.equal(eDelGiorno({ status: 'assigned', planned_start: '2026-09-03' }, oggi), false);
    assert.equal(eDelGiorno({ status: 'assigned', planned_start: '2026-09-01' }, oggi), false);
    assert.equal(eDelGiorno({ status: 'assigned' }, oggi), false);
    assert.equal(eDelGiorno({ status: 'completed', planned_start: '2026-09-02' }, oggi), false);
    assert.equal(eDelGiorno({ status: 'cancelled', planned_start: '2026-09-02' }, oggi), false);
});

test('puntoDaGeoJson: punto, linea, poligono e casi degeneri', () => {
    assert.deepEqual(
        puntoDaGeoJson({ type: 'Point', coordinates: [9.19, 45.46] }),
        { lon: 9.19, lat: 45.46 },
    );
    // Linea: il primo vertice
    assert.deepEqual(
        puntoDaGeoJson({ type: 'LineString', coordinates: [[9.1, 45.1], [9.2, 45.2]] }),
        { lon: 9.1, lat: 45.1 },
    );
    // Quadrato chiuso: la media dei vertici (senza contare due volte la chiusura)
    const quadrato = {
        type: 'Polygon',
        coordinates: [[[9, 45], [9.2, 45], [9.2, 45.2], [9, 45.2], [9, 45]]],
    };
    const centro = puntoDaGeoJson(quadrato);
    assert.ok(Math.abs(centro.lon - 9.1) < 1e-9);
    assert.ok(Math.abs(centro.lat - 45.1) < 1e-9);

    assert.equal(puntoDaGeoJson(null), null);
    assert.equal(puntoDaGeoJson({ type: 'Point', coordinates: null }), null);
    assert.equal(puntoDaGeoJson({ type: 'GeometryCollection', coordinates: [] }), null);
});

test('formattaDistanza: metri sotto il chilometro, virgola italiana sopra', () => {
    assert.equal(formattaDistanza(0.35), '350 m');
    assert.equal(formattaDistanza(0.9994), '999 m');
    assert.equal(formattaDistanza(1.23), '1,2 km');
    assert.equal(formattaDistanza(9.94), '9,9 km');
    assert.equal(formattaDistanza(12.6), '13 km');
    assert.equal(formattaDistanza(null), '');
});
