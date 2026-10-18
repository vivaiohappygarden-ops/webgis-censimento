// Diametro e circonferenza del tronco si completano a vicenda.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CAMPI_TRONCO, circonferenzaDaDiametro, diametroDaCirconferenza, legaTronco, numeroMisura } from '../../resources/js/dendrometria.js';

test('la circonferenza e\' pi greco per il diametro, al centimetro', () => {
    assert.equal(circonferenzaDaDiametro(30), 94);
    assert.equal(circonferenzaDaDiametro('30'), 94);
    assert.equal(circonferenzaDaDiametro(1), 3);
    assert.equal(circonferenzaDaDiametro(12.5), 39);
    assert.equal(circonferenzaDaDiametro(0), null);
    assert.equal(circonferenzaDaDiametro(''), null);
    assert.equal(circonferenzaDaDiametro('abc'), null);
});

test('il diametro e\' la circonferenza diviso pi greco, al decimo', () => {
    assert.equal(diametroDaCirconferenza(100), 31.8);
    assert.equal(diametroDaCirconferenza('94'), 29.9);
    assert.equal(diametroDaCirconferenza('15,7'), 5);
    assert.equal(diametroDaCirconferenza(-3), null);
    assert.equal(diametroDaCirconferenza(null), null);
});

test('il numero scritto: virgola, spazi, vuoto', () => {
    assert.equal(numeroMisura(' 12,5 '), 12.5);
    assert.equal(numeroMisura(7), 7);
    assert.equal(numeroMisura(''), null);
    assert.equal(numeroMisura(undefined), null);
    assert.deepEqual(CAMPI_TRONCO, ['dbh_cm', 'trunk_circumference_cm']);
});

test('scritto un campo del tronco l\'altro si riscrive; svuotato, l\'altro resta', () => {
    // Il campo scritto lo aggiorna v-model, come nel modulo: qui lo si imita
    const misure = { dbh_cm: null, trunk_circumference_cm: null, height_m: 12 };
    const scrivi = (campo, valore) => { misure[campo] = valore; legaTronco(misure, campo, valore); };
    scrivi('dbh_cm', 30);
    assert.equal(misure.trunk_circumference_cm, 94);
    scrivi('trunk_circumference_cm', 100);
    assert.equal(misure.dbh_cm, 31.8);
    // Si svuota il diametro: la circonferenza scritta resta
    scrivi('dbh_cm', '');
    assert.equal(misure.trunk_circumference_cm, 100);
    // Un valore a zero non riscrive l'altro campo
    scrivi('dbh_cm', 31.8);
    scrivi('trunk_circumference_cm', 0);
    assert.equal(misure.dbh_cm, 31.8);
    // Gli altri campi non c'entrano
    scrivi('trunk_circumference_cm', 100);
    legaTronco(misure, 'height_m', '15');
    assert.deepEqual(misure, { dbh_cm: 31.8, trunk_circumference_cm: 100, height_m: 12 });
});
