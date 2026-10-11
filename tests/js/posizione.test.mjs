import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PRECISIONE_BUONA, impedimento, piattaforma, prendiPosizione, spiegaErrore, testoPrecisione, diagnostica, nomePiattaforma, testoPermesso } from '../../resources/js/posizione.js';

const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const UA_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

/** Una geolocalizzazione finta: le letture arrivano quando il test lo decide. */
function geolocalizzazioneFinta() {
    const ascolti = new Map();
    let prossimoId = 1;
    const chiamateSingole = [];
    return {
        api: {
            getCurrentPosition(ok, errore, opzioni) { chiamateSingole.push({ ok, errore, opzioni }); },
            watchPosition(ok, errore, opzioni) { const id = prossimoId++; ascolti.set(id, { ok, errore, opzioni }); return id; },
            clearWatch(id) { ascolti.delete(id); },
        },
        lettura(accuracy, lat = 45.465, lon = 9.19) {
            const pos = { coords: { latitude: lat, longitude: lon, accuracy }, timestamp: Date.now() };
            for (const a of ascolti.values()) a.ok(pos);
        },
        letturaRapida(accuracy) {
            const pos = { coords: { latitude: 45.4, longitude: 9.1, accuracy }, timestamp: Date.now() };
            for (const c of chiamateSingole) c.ok(pos);
        },
        errore(code) {
            for (const a of ascolti.values()) a.errore({ code, message: 'finto' });
        },
        get inAscolto() { return ascolti.size; },
        chiamateSingole,
    };
}

const attendi = (ms) => new Promise((r) => setTimeout(r, ms));

test('si ferma appena la precisione e\' buona e spegne l\'ascolto', async () => {
    const g = geolocalizzazioneFinta();
    const aggiornamenti = [];
    const c = prendiPosizione({ geolocation: g.api, sicuro: true, tempoMassimo: 5000, suAggiornamento: (a) => aggiornamenti.push(a.accuracy) });
    await attendi(5);
    assert.equal(g.inAscolto, 1, 'si ascolta il GPS');
    assert.equal(g.chiamateSingole.length, 1, 'e si chiede una stima rapida');
    assert.equal(g.chiamateSingole[0].opzioni.enableHighAccuracy, false);
    g.lettura(40);
    g.lettura(60); // peggiore: non conta
    g.lettura(PRECISIONE_BUONA - 5);
    const esito = await c.promessa;
    assert.equal(esito.accuracy, PRECISIONE_BUONA - 5);
    assert.deepEqual(aggiornamenti, [40, PRECISIONE_BUONA - 5]);
    assert.equal(g.inAscolto, 0, 'l\'ascolto si chiude');
});

test('alla scadenza vale la lettura migliore, anche se non precisa', async () => {
    const g = geolocalizzazioneFinta();
    const c = prendiPosizione({ geolocation: g.api, sicuro: true, tempoMassimo: 80 });
    await attendi(5);
    g.letturaRapida(900);
    g.lettura(35);
    const esito = await c.promessa;
    assert.equal(esito.accuracy, 35);
    assert.ok(esito.trascorsi >= 70);
});

test('"accetta" prende subito la migliore finora, "annulla" rifiuta', async () => {
    const g = geolocalizzazioneFinta();
    const c = prendiPosizione({ geolocation: g.api, sicuro: true, tempoMassimo: 5000 });
    await attendi(5);
    g.lettura(70);
    c.accetta();
    assert.equal((await c.promessa).accuracy, 70);

    const g2 = geolocalizzazioneFinta();
    const c2 = prendiPosizione({ geolocation: g2.api, sicuro: true, tempoMassimo: 5000 });
    await attendi(5);
    c2.annulla();
    await assert.rejects(c2.promessa, (e) => e.code === 'annullata');
    assert.equal(g2.inAscolto, 0);
});

test('il permesso negato si spiega subito, con che cosa fare su quel dispositivo', async () => {
    const g = geolocalizzazioneFinta();
    const c = prendiPosizione({ geolocation: g.api, sicuro: true, tempoMassimo: 5000 });
    await attendi(5);
    g.errore(1);
    await assert.rejects(c.promessa, (e) => e.code === 1);
    assert.match(spiegaErrore({ code: 1 }, { ua: UA_IPHONE, sicuro: true }), /Su iPhone: Impostazioni > Privacy e sicurezza > Localizzazione/);
    assert.match(spiegaErrore({ code: 1 }, { ua: UA_WINDOWS, sicuro: true }), /Su Windows: Impostazioni > Privacy e sicurezza > Posizione/);
    assert.match(spiegaErrore({ code: 1 }, { ua: UA_WINDOWS, sicuro: true }), /lucchetto/);
    assert.match(spiegaErrore({ code: 1 }, { ua: UA_WINDOWS, sicuro: true }), /permesso dato al sito da solo non basta/);
    // La frase del browser resta in coda, quella nostra no
    assert.match(spiegaErrore({ code: 1, message: 'User denied Geolocation' }, { ua: UA_WINDOWS, sicuro: true }), /Dettaglio del browser: User denied Geolocation$/);
    assert.doesNotMatch(spiegaErrore({ code: 1, message: 'Permesso negato.' }, { ua: UA_WINDOWS, sicuro: true }), /Dettaglio/);
});

test('se il browser dice gia\' che il permesso e\' negato non si aspetta nemmeno', async () => {
    const g = geolocalizzazioneFinta();
    const permissions = { query: async () => ({ state: 'denied' }) };
    const c = prendiPosizione({ geolocation: g.api, permissions, sicuro: true, tempoMassimo: 5000 });
    await assert.rejects(c.promessa, (e) => e.code === 1);
    assert.equal(g.inAscolto, 0, 'nessun ascolto avviato');
});

test('senza segnale si aspetta fino alla scadenza e poi si spiega', async () => {
    const g = geolocalizzazioneFinta();
    const c = prendiPosizione({ geolocation: g.api, sicuro: true, tempoMassimo: 60 });
    await attendi(5);
    g.errore(2);
    await assert.rejects(c.promessa, (e) => e.code === 2);
    assert.match(spiegaErrore({ code: 2 }, { ua: UA_WINDOWS, sicuro: true }), /non ha un GPS/);
    assert.match(spiegaErrore({ code: 2 }, { ua: UA_IPHONE, sicuro: true }), /esci all'aperto/);
    assert.match(spiegaErrore({ code: 3 }, { ua: UA_IPHONE, sicuro: true }), /mezzo minuto/);
});

test('se la richiesta di permesso resta senza risposta, alla scadenza lo si dice', async () => {
    const g = geolocalizzazioneFinta();
    const permissions = { query: async () => ({ state: 'prompt' }) };
    const c = prendiPosizione({ geolocation: g.api, permissions, sicuro: true, tempoMassimo: 60 });
    await assert.rejects(c.promessa, (e) => e.code === 3 && e.permesso === 'prompt');
    assert.match(spiegaErrore({ code: 3, permesso: 'prompt' }, { ua: UA_IPHONE, sicuro: true }), /non ha avuto risposta.*Posizione esatta/);
    assert.doesNotMatch(spiegaErrore({ code: 3 }, { ua: UA_IPHONE, sicuro: true }), /non ha avuto risposta/);
    // Se nel frattempo il permesso risulta negato, e' quello il messaggio
    const g2 = geolocalizzazioneFinta();
    let stato = 'prompt';
    const c2 = prendiPosizione({ geolocation: g2.api, permissions: { query: async () => ({ state: stato }) }, sicuro: true, tempoMassimo: 60 });
    await attendi(10);
    stato = 'denied';
    await assert.rejects(c2.promessa, (e) => e.code === 1);
});

test('in http e senza API non si prova neppure', async () => {
    assert.match(impedimento({ geolocation: {}, sicuro: false }), /https/);
    assert.match(impedimento({ geolocation: undefined, sicuro: true }), /non fornisce/);
    const c = prendiPosizione({ geolocation: {}, sicuro: false });
    await assert.rejects(c.promessa, (e) => e.code === 'contesto' && /https/.test(e.message));
    assert.match(spiegaErrore({ code: 1 }, { ua: UA_WINDOWS, sicuro: false }), /https/);
});

test('piattaforma e testo della precisione', () => {
    assert.equal(piattaforma(UA_IPHONE), 'ios');
    assert.equal(piattaforma(UA_WINDOWS), 'windows');
    assert.equal(piattaforma('Mozilla/5.0 (Linux; Android 14) Chrome/129'), 'android');
    assert.equal(testoPrecisione(12.34), 'precisione 12,3 m');
    assert.equal(testoPrecisione(850.7), 'precisione 851 m');
    assert.equal(testoPrecisione(null), '');
});

test('la diagnostica dice quale cancello e\' chiuso', async () => {
    const d = await diagnostica({ geolocation: {}, permissions: { query: async () => ({ state: 'denied' }) }, sicuro: true, ua: UA_WINDOWS });
    assert.deepEqual(d, { sicuro: true, api: true, permesso: 'denied', piattaforma: 'windows' });
    // Safari non risponde alla domanda sul permesso: null, non un errore
    const s = await diagnostica({ geolocation: {}, permissions: { query: async () => { throw new TypeError('no'); } }, sicuro: false, ua: UA_IPHONE });
    assert.deepEqual(s, { sicuro: false, api: true, permesso: null, piattaforma: 'ios' });
    const n = await diagnostica({ geolocation: undefined, permissions: undefined, sicuro: true, ua: '' });
    assert.deepEqual(n, { sicuro: true, api: false, permesso: null, piattaforma: 'altro' });
    assert.match(testoPermesso('granted'), /concesso/);
    assert.match(testoPermesso('denied'), /negato/);
    assert.match(testoPermesso('prompt'), /da chiedere/);
    assert.match(testoPermesso(null), /non lo dice/);
    assert.equal(nomePiattaforma('ios'), 'iPhone o iPad');
    assert.equal(nomePiattaforma('boh'), 'altro');
});
