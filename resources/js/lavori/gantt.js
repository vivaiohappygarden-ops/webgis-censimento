/**
 * La matematica del diagramma a barre dei lavori.
 *
 * Sta fuori dal componente, e senza importare niente, per due motivi: si
 * puo' provare con node --test (tests/js/gantt.test.mjs), e i conti su cui
 * si regge il disegno - dove comincia una barra, quanto e' lunga, dove cade
 * la linea di oggi - restano leggibili tutti insieme.
 *
 * Le date si trattano come giorni locali, mai come istanti UTC: a cavallo
 * della mezzanotte un toISOString sposterebbe il giorno.
 */

const MS_PER_GIORNO = 86400000;

const pad = (n) => String(n).padStart(2, '0');

/** Data locale in formato YYYY-MM-DD. */
export function ymd(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Da "2026-09-01" a una data locale a mezzogiorno (immune all'ora legale). */
export function giorno(valore) {
    return new Date(`${String(valore).slice(0, 10)}T12:00:00`);
}

/** Giorni compresi fra due date, estremi inclusi (mai meno di uno). */
export function giorniFra(da, a) {
    const inizio = new Date(da).setHours(12, 0, 0, 0);
    const fine = new Date(a).setHours(12, 0, 0, 0);

    return Math.max(1, Math.round((fine - inizio) / MS_PER_GIORNO) + 1);
}

/** Ultimo giorno della finestra che parte da `inizio` e dura `mesi` mesi. */
export function fineFinestra(inizio, mesi) {
    const d = new Date(inizio);
    d.setDate(1);
    d.setMonth(d.getMonth() + Number(mesi));
    d.setDate(0); // il giorno prima del primo del mese dopo

    return d;
}

/**
 * Le colonne del diagramma: un mese ciascuna, con la larghezza in
 * percentuale proporzionale ai giorni che occupa nella finestra. I mesi
 * corti sono piu' stretti: e' l'unico modo perche' una barra di dieci
 * giorni sia lunga uguale a febbraio e a luglio.
 */
export function colonneMesi(inizio, fine) {
    const totale = giorniFra(inizio, fine);
    const out = [];
    const cursore = new Date(inizio);
    cursore.setDate(1);

    while (cursore <= fine) {
        const primo = new Date(cursore.getFullYear(), cursore.getMonth(), 1);
        const ultimo = new Date(cursore.getFullYear(), cursore.getMonth() + 1, 0);
        const da = primo < inizio ? inizio : primo;
        const a = ultimo > fine ? fine : ultimo;

        out.push({
            chiave: `${cursore.getFullYear()}-${pad(cursore.getMonth() + 1)}`,
            etichetta: cursore.toLocaleDateString('it-IT', { month: 'short', year: '2-digit' }),
            larghezza: (giorniFra(da, a) / totale) * 100,
        });
        cursore.setMonth(cursore.getMonth() + 1);
    }

    return out;
}

/**
 * Dove cade una data nella finestra, in percentuale (null se sta fuori):
 * serve per la linea di oggi.
 */
export function posizione(data, inizio, fine) {
    if (data < new Date(inizio).setHours(0, 0, 0, 0) || data > new Date(fine).setHours(23, 59, 59, 999)) {
        return null;
    }

    return ((giorniFra(inizio, data) - 1) / giorniFra(inizio, fine)) * 100;
}

/**
 * La barra di un ordine: posizione e larghezza in percentuale, piu' le
 * frecce che dicono se il lavoro comincia prima o finisce dopo la finestra.
 *
 * Un ordine senza fine prevista occupa il solo giorno di inizio: stessa
 * regola dell'agenda e del cruscotto Oggi, o i tre conteggi non tornerebbero.
 * La larghezza minima non e' cosmesi: sotto una certa soglia la barra
 * sparirebbe e il lavoro non sarebbe piu' cliccabile.
 */
export function barra(ordine, inizio, fine, larghezzaMinima = 0.8) {
    const totale = giorniFra(inizio, fine);
    const da = giorno(ordine.planned_start);
    const a = giorno(ordine.planned_end ?? ordine.planned_start);
    const daTagliato = da < inizio ? inizio : da;
    const aTagliato = a > fine ? fine : a;

    return {
        sinistra: ((giorniFra(inizio, daTagliato) - 1) / totale) * 100,
        larghezza: Math.max((giorniFra(daTagliato, aTagliato) / totale) * 100, larghezzaMinima),
        tagliataPrima: da < inizio,
        tagliataDopo: a > fine,
    };
}

/** Il nome del gruppo di un ordine, secondo il raggruppamento scelto. */
export function nomeGruppo(ordine, chiave) {
    return {
        team: ordine.team?.name ?? 'Senza squadra',
        area: ordine.area?.name ?? 'Senza area',
        client: ordine.client?.name ?? 'Senza committente',
        work_type: ordine.work_type?.name ?? 'Senza lavorazione',
    }[chiave] ?? 'Senza gruppo';
}

/**
 * Gli ordini raggruppati e messi in fila per data di inizio. I gruppi
 * "Senza ..." vanno in fondo: sono un residuo da sistemare, non una squadra
 * di lavoro, e in cima ruberebbero il posto a quelle vere.
 */
export function raggruppa(ordini, chiave, decora = (o) => o) {
    const mappa = new Map();

    for (const ordine of ordini) {
        const nome = nomeGruppo(ordine, chiave);
        if (! mappa.has(nome)) mappa.set(nome, []);
        mappa.get(nome).push(decora(ordine));
    }

    return [...mappa.entries()]
        .map(([nome, elementi]) => ({
            nome,
            ordini: elementi.sort((a, b) => String(a.planned_start).localeCompare(String(b.planned_start))),
        }))
        .sort((a, b) => (a.nome.startsWith('Senza ') - b.nome.startsWith('Senza '))
            || a.nome.localeCompare(b.nome, 'it'));
}
