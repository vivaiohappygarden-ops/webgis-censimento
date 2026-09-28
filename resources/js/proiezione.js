/*
 * Coordinate piane del sistema di riferimento metrico dell'organizzazione
 * (RDN2008 / ETRS89, EPSG 7791-7794), calcolate in pagina dalla
 * longitudine e latitudine della mappa.
 *
 * Servono alla riga delle coordinate sotto la mappa e alle misure fatte a
 * mano: la banca dati misura lunghezze e superfici in EPSG 7791, e le misure
 * viste sullo schermo devono tornare con quelle. La proiezione e' la
 * trasversa di Mercatore (formule di Kruger, serie fino al quarto ordine,
 * precisione millimetrica dentro il fuso); ETRS89 e WGS84 differiscono di
 * meno di un metro, quindi le coordinate GPS entrano cosi' come sono.
 */

const A = 6378137;
const F = 1 / 298.257222101; // GRS80

export const SISTEMI = {
    7791: { nome: 'RDN2008 / UTM zona 32N', meridiano: 9, k0: 0.9996, falsoEst: 500000, falsoNord: 0 },
    7792: { nome: 'RDN2008 / UTM zona 33N', meridiano: 15, k0: 0.9996, falsoEst: 500000, falsoNord: 0 },
    7793: { nome: 'RDN2008 / UTM zona 34N', meridiano: 21, k0: 0.9996, falsoEst: 500000, falsoNord: 0 },
    7794: { nome: 'RDN2008 / fuso Italia', meridiano: 12, k0: 0.9985, falsoEst: 7000000, falsoNord: 0 },
};

const n = F / (2 - F);
const n2 = n * n;
const n3 = n2 * n;
const n4 = n3 * n;
// Raggio della sfera rettificante e coefficienti della serie
const RAGGIO = (A / (1 + n)) * (1 + n2 / 4 + n4 / 64);
const ALFA = [
    n / 2 - (2 / 3) * n2 + (5 / 16) * n3 + (41 / 180) * n4,
    (13 / 48) * n2 - (3 / 5) * n3 + (557 / 1440) * n4,
    (61 / 240) * n3 - (103 / 140) * n4,
    (49561 / 161280) * n4,
];
const gradi = (x) => (x * Math.PI) / 180;

/**
 * Da [lon, lat] (gradi) a { est, nord } in metri nel sistema chiesto.
 * Fuori dal proprio fuso il risultato resta calcolabile ma meno preciso:
 * la funzione non lo vieta, perche' la mappa puo' essere spostata ovunque.
 */
export function proietta(lon, lat, srid = 7791) {
    const sistema = SISTEMI[srid] ?? SISTEMI[7791];
    const phi = gradi(lat);
    const dl = gradi(lon - sistema.meridiano);
    const e = Math.sqrt(F * (2 - F));
    const t = Math.sinh(Math.atanh(Math.sin(phi)) - e * Math.atanh(e * Math.sin(phi)));
    const xi0 = Math.atan2(t, Math.cos(dl));
    const eta0 = Math.atanh(Math.sin(dl) / Math.sqrt(1 + t * t));
    let xi = xi0;
    let eta = eta0;
    for (let j = 1; j <= 4; j++) {
        xi += ALFA[j - 1] * Math.sin(2 * j * xi0) * Math.cosh(2 * j * eta0);
        eta += ALFA[j - 1] * Math.cos(2 * j * xi0) * Math.sinh(2 * j * eta0);
    }

    return {
        est: sistema.falsoEst + sistema.k0 * RAGGIO * eta,
        nord: sistema.falsoNord + sistema.k0 * RAGGIO * xi,
    };
}

/** Lunghezza piana di una spezzata di punti [lon, lat], in metri. */
export function lunghezzaPiana(punti, srid = 7791) {
    let totale = 0;
    for (let i = 1; i < punti.length; i++) {
        const a = proietta(punti[i - 1][0], punti[i - 1][1], srid);
        const b = proietta(punti[i][0], punti[i][1], srid);
        totale += Math.hypot(b.est - a.est, b.nord - a.nord);
    }

    return totale;
}

/** Area piana (formula di Gauss) del poligono chiuso sui punti [lon, lat], in metri quadrati. */
export function areaPiana(punti, srid = 7791) {
    if (punti.length < 3) return 0;
    const p = punti.map(([lon, lat]) => proietta(lon, lat, srid));
    let somma = 0;
    for (let i = 0; i < p.length; i++) {
        const a = p[i];
        const b = p[(i + 1) % p.length];
        somma += a.est * b.nord - b.est * a.nord;
    }

    return Math.abs(somma) / 2;
}

/** Testo delle coordinate per la riga sotto la mappa. */
export function testoCoordinate(lon, lat, srid = 7791) {
    const { est, nord } = proietta(lon, lat, srid);
    const sistema = SISTEMI[srid] ?? SISTEMI[7791];
    const formato = (v) => v.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return {
        wgs84: `${Math.abs(lat).toFixed(6)}° ${lat >= 0 ? 'N' : 'S'}  ${Math.abs(lon).toFixed(6)}° ${lon >= 0 ? 'E' : 'O'}`,
        metrico: `E ${formato(est)}  N ${formato(nord)}`,
        sistema: `${sistema.nome} (EPSG:${SISTEMI[srid] ? srid : 7791})`,
    };
}
