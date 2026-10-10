import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CARATTERI, CHIAVE_PROTOCOLLO, livelliSfondo, stileSfondo } from '../../resources/js/field/stile-sfondo.js';

test('lo stile dello sfondo usa solo i caratteri ospitati in casa e nessuna icona', () => {
    const stile = stileSfondo({ glifi: 'https://esempio.test/mappa/font/{fontstack}/{range}.pbf' });
    assert.equal(stile.version, 8);
    assert.equal(stile.sources.sfondo.url, `pmtiles://${CHIAVE_PROTOCOLLO}`);
    assert.ok(stile.layers.length > 40, 'lo stile Protomaps porta decine di livelli');

    const testo = JSON.stringify(stile.layers);
    assert.ok(! /Noto Sans/.test(testo), 'nessun carattere Noto: non e\' ospitato in casa');
    assert.ok(testo.includes('DejaVuSans'), 'le etichette usano i glifi di public/mappa/font');
    assert.ok(! /icon-image/.test(testo), 'niente livelli con icone: sul telefono non c\'e\' uno sprite');
    for (const livello of stile.layers) {
        assert.equal(livello.source ?? 'sfondo', 'sfondo', `il livello ${livello.id} legge dalla sorgente dello sfondo`);
    }
    assert.ok(Object.values(CARATTERI).every((c) => c.startsWith('DejaVuSans')));
});

test('le etichette sono in italiano dove il dato lo prevede', () => {
    const luoghi = livelliSfondo().find((l) => l.id === 'places_locality');
    assert.ok(luoghi, 'il livello dei Comuni c\'e\'');
    assert.ok(JSON.stringify(luoghi.layout['text-field']).includes('name:it'));
});
