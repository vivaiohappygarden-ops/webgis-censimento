// Le proposte di specie dell'app di campo: calcolate sul telefono, al massimo
// otto, con il nome scritto che si completa solo a lasciare il campo.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LIMITE, nomiVoce, normalizza, proposteSpecie, stessoNome, unisciVoci, voceEsatta, vociDaAlberi } from '../../resources/js/field/specie.js';

const VOCI = [
    { id: 1, genus: 'Pinus', species: 'Pinus pinea', common_name: 'Pino domestico', synonyms: ['pino da pinoli', 'pino romano', 'pino italico', 'pino a ombrello'] },
    { id: 2, genus: 'Pinus', species: 'Pinus pinaster', common_name: 'Pino marittimo', synonyms: [] },
    { id: 3, genus: 'Pinus', species: 'Pinus halepensis', common_name: "Pino d'Aleppo", synonyms: [] },
    { id: 4, genus: 'Tilia', species: 'Tilia cordata', common_name: 'Tiglio selvatico', synonyms: ['tiglio a foglie piccole'] },
    { id: 5, genus: 'Tilia', species: 'Tilia platyphyllos', common_name: 'Tiglio nostrano', synonyms: [] },
    { id: 6, genus: 'Acer', species: 'Acer campestre', common_name: 'Acero campestre', synonyms: ['oppio', 'testucchio'] },
    { id: 7, genus: 'Quercus', species: 'Quercus ilex', common_name: 'Leccio', synonyms: ['elce'] },
    { id: 8, genus: 'Celtis', species: 'Celtis australis', common_name: 'Bagolaro', synonyms: ['spaccasassi'] },
    { id: 9, genus: 'Cupressus', species: 'Cupressus sempervirens', common_name: 'Cipresso comune', synonyms: [] },
    { id: 10, genus: 'Platanus', species: 'Platanus x acerifolia', common_name: 'Platano comune', synonyms: ['platano ibrido'] },
    { id: 11, genus: 'Prunus', species: 'Prunus avium', common_name: 'Ciliegio selvatico', synonyms: [] },
    { id: 12, genus: 'Populus', species: 'Populus nigra', common_name: 'Pioppo nero', synonyms: [] },
    { id: 13, genus: 'Pyrus', species: 'Pyrus calleryana', common_name: 'Pero da fiore', synonyms: [] },
    { id: 14, genus: 'Picea', species: 'Picea abies', common_name: 'Abete rosso', synonyms: ['peccio'] },
];
const specie = (lista) => lista.map((v) => v.species);

test('l\'ordine delle proposte: uguale, inizia cosi\', parola che inizia cosi\', poi contiene', () => {
    assert.deepEqual(specie(proposteSpecie(VOCI, 'pino romano')), ['Pinus pinea']);
    // "pino" inizia il nome comune di tre pini: in ordine alfabetico di specie
    assert.deepEqual(specie(proposteSpecie(VOCI, 'pino')), ['Pinus halepensis', 'Pinus pinaster', 'Pinus pinea']);
    // "camp": parola che inizia cosi' in "Acer campestre" (e nel nome comune)
    assert.deepEqual(specie(proposteSpecie(VOCI, 'camp')), ['Acer campestre']);
    // Il nome botanico che inizia cosi' viene prima di una parola in mezzo
    assert.deepEqual(specie(proposteSpecie(VOCI, 'ti')), ['Tilia cordata', 'Tilia platyphyllos']);
    // Piu' parole, anche in ordine diverso e in mezzo
    assert.deepEqual(specie(proposteSpecie(VOCI, 'selvatico tiglio')), ['Tilia cordata']);
    // Sinonimi e accenti non contano
    assert.deepEqual(specie(proposteSpecie(VOCI, 'Spaccasassi')), ['Celtis australis']);
    assert.deepEqual(specie(proposteSpecie(VOCI, 'pino d\u2019aleppo'.replace('\u2019', "'"))), ['Pinus halepensis']);
});

test('sotto le due lettere niente, e mai piu\' del tetto', () => {
    assert.deepEqual(proposteSpecie(VOCI, 'p'), []);
    assert.deepEqual(proposteSpecie(VOCI, '  '), []);
    const tante = Array.from({ length: 40 }, (_, i) => ({ id: 100 + i, species: `Pinus prova${String(i).padStart(2, '0')}`, common_name: 'Pino di prova', synonyms: [] }));
    assert.equal(proposteSpecie(tante, 'pino').length, LIMITE);
    assert.equal(proposteSpecie(tante, 'pino', 3).length, 3);
    assert.equal(LIMITE, 8);
});

test('la voce esatta: nome botanico, comune o sinonimo, da tre lettere', () => {
    assert.equal(voceEsatta(VOCI, 'PINO ROMANO ').id, 1);
    assert.equal(voceEsatta(VOCI, 'Tilia cordata').id, 4);
    assert.equal(voceEsatta(VOCI, 'leccio').id, 7);
    assert.equal(voceEsatta(VOCI, 'pino'), null);
    assert.equal(voceEsatta(VOCI, 'pin'), null);
    assert.equal(voceEsatta(VOCI, 'tig'), null);
    assert.ok(stessoNome('Tilia  cordata', 'tilia cordata'));
    assert.ok(! stessoNome('', ''));
    assert.equal(normalizza('  Pino d\'Aleppo  '), "pino d'aleppo");
    assert.deepEqual(nomiVoce(VOCI[6]), ['Quercus ilex', 'Leccio', 'elce']);
});

test('le specie proprie del telefono entrano senza doppioni, con il genere', () => {
    const alberi = [
        { species: 'Tilia cordata', common_name: 'Tiglio' },           // gia' nel dizionario: non si somma
        { species: 'Quercus robur', common_name: 'Farnia', genus: 'Quercus' },
        { species: 'quercus robur', common_name: 'Farnia' },           // doppione a meno di maiuscole
        { species: 'Aghifoglia', common_name: '' },
        { species: '', common_name: 'Senza specie' },
    ];
    const proprie = vociDaAlberi(alberi, VOCI);
    assert.deepEqual(proprie.map((v) => [v.species, v.common_name, v.genus, v.locale]), [
        ['Quercus robur', 'Farnia', 'Quercus', true],
        ['Aghifoglia', null, 'Aghifoglia', true],
    ]);
    const tutte = unisciVoci(VOCI, alberi);
    assert.equal(tutte.length, VOCI.length + 2);
    assert.deepEqual(specie(proposteSpecie(tutte, 'farnia')), ['Quercus robur']);
    assert.equal(voceEsatta(tutte, 'Farnia').locale, true);
});
