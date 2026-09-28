import assert from 'node:assert/strict';
import { test } from 'node:test';
import { areaPiana, lunghezzaPiana, proietta, testoCoordinate } from '../../resources/js/proiezione.js';

// Il punto letto nella barra di stato di un altro programma GIS su Padova
// (WGS84 45° 24' 38,25" N, 11° 53' 19,88" E): UTM 32N X 726052,59 Y 5032627,46
const PADOVA = [11 + 53 / 60 + 19.88 / 3600, 45 + 24 / 60 + 38.25 / 3600];

test('la proiezione UTM 32N torna con un programma GIS al centimetro', () => {
    const { est, nord } = proietta(PADOVA[0], PADOVA[1], 7791);
    assert.ok(Math.abs(est - 726052.59) < 0.05, `est ${est}`);
    assert.ok(Math.abs(nord - 5032627.46) < 0.05, `nord ${nord}`);
});

test('sul meridiano centrale l\'est e\' il falso est e il nord cresce con la latitudine', () => {
    const a = proietta(9, 45, 7791);
    assert.ok(Math.abs(a.est - 500000) < 1e-6);
    const b = proietta(9, 46, 7791);
    assert.ok(b.nord > a.nord + 111000 && b.nord < a.nord + 111400, `${b.nord - a.nord}`);
    // Fuso Italia: falso est 7 000 000 sul meridiano 12
    assert.ok(Math.abs(proietta(12, 42, 7794).est - 7000000) < 1e-6);
});

test('un grado di longitudine a 45 gradi nord misura circa 78,8 km e un quadrato di 100 m fa 10 000 m2', () => {
    const km = lunghezzaPiana([[9, 45], [10, 45]], 7791);
    assert.ok(km > 78700 && km < 78900, `${km}`);
    // Quadrato di 100 m attorno a un punto, in coordinate geografiche
    const lat = 45.4652;
    const lon = 9.1905;
    const dLat = 100 / 111320;
    const dLon = 100 / (111320 * Math.cos((lat * Math.PI) / 180));
    const area = areaPiana([[lon, lat], [lon + dLon, lat], [lon + dLon, lat + dLat], [lon, lat + dLat]], 7791);
    assert.ok(Math.abs(area - 10000) < 40, `${area}`);
    assert.equal(areaPiana([[9, 45], [9.001, 45]]), 0);
});

test('il testo delle coordinate porta i due sistemi e ripiega sul 7791 se il sistema non c\'e\'', () => {
    const t = testoCoordinate(PADOVA[0], PADOVA[1], 7791);
    assert.equal(t.wgs84, '45.410625° N  11.888856° E');
    assert.match(t.metrico, /^E 726\.052,59  N 5\.032\.627,4\d$/);
    assert.equal(t.sistema, 'RDN2008 / UTM zona 32N (EPSG:7791)');
    assert.equal(testoCoordinate(12, 42, 9999).sistema, 'RDN2008 / UTM zona 32N (EPSG:7791)');
});
