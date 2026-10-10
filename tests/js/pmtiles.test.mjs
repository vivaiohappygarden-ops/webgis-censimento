import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { test } from 'node:test';
import { PMTiles, zxyToTileId } from 'pmtiles';

/*
 * L'archivio scritto dall'estrattore PHP (tests/Fixtures/sfondo/estratto-php.pmtiles,
 * un ritaglio del pianeta di prova) deve essere letto dalla libreria
 * JavaScript ufficiale, la stessa che MapLibre usa sul telefono.
 */
const byte = readFileSync(new URL('../Fixtures/sfondo/estratto-php.pmtiles', import.meta.url));
const sorgente = {
    getKey: () => 'estratto-php',
    getBytes: async (offset, lunghezza) => ({ data: byte.buffer.slice(byte.byteOffset + offset, byte.byteOffset + Math.min(byte.length, offset + lunghezza)) }),
};

test('la libreria ufficiale legge intestazione, metadati e tessere scritte dal PHP', async () => {
    const archivio = new PMTiles(sorgente);
    const h = await archivio.getHeader();
    assert.equal(h.specVersion, 3);
    assert.equal(h.minZoom, 10);
    assert.equal(h.maxZoom, 15);
    assert.equal(h.tileType, 1, 'tessere vettoriali (MVT)');
    assert.equal(h.tileCompression, 2, 'tessere compresse in gzip, come nel pianeta');
    assert.ok(h.numAddressedTiles > 20 && h.numAddressedTiles === h.numTileEntries + (h.numAddressedTiles - h.numTileEntries));
    assert.ok(h.minLon >= 9.17 - 1e-6 && h.maxLon <= 9.21 + 1e-6, 'il riquadro e\' quello del ritaglio');

    const meta = await archivio.getMetadata();
    assert.equal(meta.name, 'Pianeta di prova (schema Protomaps) (estratto)');

    const tessera = await archivio.getZxy(15, 17220, 11727);
    assert.ok(tessera, 'la tessera del Comune Demo c\'e\'');
    const dati = Buffer.from(tessera.data);
    assert.equal(dati[0], 0x1a, 'la libreria restituisce la tessera gia\' decompressa (protobuf MVT)');
    assert.ok(dati.includes('Comune Demo'));

    // Lo stesso contenuto del pianeta di prova, byte per byte dopo la decompressione
    const pianeta = readFileSync(new URL('../Fixtures/sfondo/pianeta-prova.pmtiles', import.meta.url));
    const archivioPianeta = new PMTiles({ getKey: () => 'pianeta', getBytes: async (o, l) => ({ data: pianeta.buffer.slice(pianeta.byteOffset + o, pianeta.byteOffset + Math.min(pianeta.length, o + l)) }) });
    const originale = await archivioPianeta.getZxy(15, 17220, 11727);
    assert.deepEqual(dati, Buffer.from(originale.data));

    assert.equal(await archivio.getZxy(15, 17150, 11727), undefined, 'una tessera fuori dal ritaglio non c\'e\'');
    assert.ok(zxyToTileId(15, 17220, 11727) > 0);
});
