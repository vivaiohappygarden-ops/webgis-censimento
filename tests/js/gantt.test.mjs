/**
 * Prove del modulo puro del diagramma a barre (resources/js/lavori/gantt.js).
 *
 * Il progetto non ha un test runner JavaScript: si usa quello incorporato in
 * Node. Per lanciarle:
 *
 *     node --test tests/js/gantt.test.mjs
 *
 * Non serve alcuna dipendenza ne' build: il modulo sotto prova non importa
 * nulla, proprio perche' deve girare identico anche qui.
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    barra, colonneMesi, fineFinestra, giorniFra, nomeGruppo, posizione, raggruppa, ymd,
} from '../../resources/js/lavori/gantt.js';

const G = (s) => new Date(`${s}T12:00:00`);

test('la finestra di sei mesi finisce l ultimo giorno del sesto mese', () => {
    assert.equal(ymd(fineFinestra(G('2026-09-01'), 6)), '2027-02-28');
    // Anno bisestile: febbraio ha 29 giorni e la finestra deve arrivarci
    assert.equal(ymd(fineFinestra(G('2027-09-01'), 6)), '2028-02-29');
    assert.equal(ymd(fineFinestra(G('2026-01-01'), 12)), '2026-12-31');
});

test('i giorni fra due date contano gli estremi', () => {
    assert.equal(giorniFra(G('2026-09-01'), G('2026-09-01')), 1);
    assert.equal(giorniFra(G('2026-09-01'), G('2026-09-30')), 30);
    // Il cambio dell'ora legale non deve mangiare o regalare un giorno
    assert.equal(giorniFra(G('2026-10-24'), G('2026-10-26')), 3);
});

test('le colonne dei mesi coprono tutta la finestra e i mesi corti sono piu stretti', () => {
    const inizio = G('2026-01-01');
    const fine = fineFinestra(inizio, 3);
    const colonne = colonneMesi(inizio, fine);

    assert.equal(colonne.length, 3);
    const somma = colonne.reduce((t, c) => t + c.larghezza, 0);
    assert.ok(Math.abs(somma - 100) < 0.0001, `le larghezze devono fare 100, fanno ${somma}`);
    // Febbraio (28 giorni) piu' stretto di gennaio (31)
    assert.ok(colonne[1].larghezza < colonne[0].larghezza);
});

test('la barra di un lavoro di un giorno resta visibile', () => {
    const inizio = G('2026-09-01');
    const fine = fineFinestra(inizio, 12);
    const b = barra({ planned_start: '2026-09-10', planned_end: null }, inizio, fine);

    // Un giorno su un anno sarebbe lo 0,27%: sotto la soglia sparirebbe
    assert.ok(b.larghezza >= 0.8);
    assert.equal(b.tagliataPrima, false);
    assert.equal(b.tagliataDopo, false);
});

test('la barra si taglia sulla finestra e lo dichiara', () => {
    const inizio = G('2026-09-01');
    const fine = fineFinestra(inizio, 3); // 30 novembre

    const prima = barra({ planned_start: '2026-07-15', planned_end: '2026-09-20' }, inizio, fine);
    assert.equal(prima.sinistra, 0);
    assert.equal(prima.tagliataPrima, true);
    assert.equal(prima.tagliataDopo, false);

    const dopo = barra({ planned_start: '2026-11-20', planned_end: '2027-03-01' }, inizio, fine);
    assert.equal(dopo.tagliataDopo, true);
    assert.ok(dopo.sinistra + dopo.larghezza <= 100.0001, 'la barra non esce dal diagramma');
});

test('la barra comincia dove comincia il lavoro', () => {
    const inizio = G('2026-09-01');
    const fine = fineFinestra(inizio, 1); // settembre, 30 giorni
    const b = barra({ planned_start: '2026-09-11', planned_end: '2026-09-20' }, inizio, fine);

    // Decimo giorno su trenta: un terzo esatto
    assert.ok(Math.abs(b.sinistra - (10 / 30) * 100) < 0.0001);
    assert.ok(Math.abs(b.larghezza - (10 / 30) * 100) < 0.0001);
});

test('la linea di oggi cade dentro la finestra, o non c e', () => {
    const inizio = G('2026-09-01');
    const fine = fineFinestra(inizio, 1);

    assert.equal(posizione(G('2026-08-31'), inizio, fine), null);
    assert.equal(posizione(G('2026-10-01'), inizio, fine), null);
    assert.equal(posizione(G('2026-09-01'), inizio, fine), 0);
    assert.ok(Math.abs(posizione(G('2026-09-16'), inizio, fine) - 50) < 0.0001);
});

test('i lavori si raggruppano e i "senza" vanno in fondo', () => {
    const ordini = [
        { id: 1, planned_start: '2026-09-20', team: { name: 'Squadra 2' } },
        { id: 2, planned_start: '2026-09-05', team: null },
        { id: 3, planned_start: '2026-09-10', team: { name: 'Squadra 1' } },
        { id: 4, planned_start: '2026-09-01', team: { name: 'Squadra 1' } },
    ];

    const gruppi = raggruppa(ordini, 'team');

    assert.deepEqual(gruppi.map((g) => g.nome), ['Squadra 1', 'Squadra 2', 'Senza squadra']);
    // Dentro il gruppo, in ordine di data
    assert.deepEqual(gruppi[0].ordini.map((o) => o.id), [4, 3]);
});

test('il nome del gruppo dice sempre qualcosa', () => {
    assert.equal(nomeGruppo({ client: { name: 'Comune di Mentana' } }, 'client'), 'Comune di Mentana');
    assert.equal(nomeGruppo({}, 'area'), 'Senza area');
    assert.equal(nomeGruppo({}, 'work_type'), 'Senza lavorazione');
});
