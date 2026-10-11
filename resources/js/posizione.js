/*
 * La posizione del dispositivo, presa bene (dal 11/10/2026).
 *
 * Il committente: "la posizione GPS spesso e volentieri non me la fa
 * funzionare, ne' da Chrome su Windows ne' da Safari su iPhone". Le cause
 * vere sono poche e si spiegano: il permesso negato una volta e ricordato dal
 * browser, i servizi di localizzazione spenti nel telefono o in Windows, il
 * GPS che all'aperto impiega anche mezzo minuto a partire mentre il programma
 * aspettava dieci secondi e poi rinunciava, il sito aperto in http.
 *
 * Qui la posizione si prende cosi': si chiede subito una stima rapida (reti
 * Wi-Fi, ultima posizione nota) e insieme si resta in ascolto del GPS fino a
 * trenta secondi, tenendo la lettura piu' precisa; appena la precisione e'
 * buona ci si ferma, altrimenti alla fine vale la migliore arrivata, e chi
 * aspetta puo' accettare prima quella che c'e'. Ogni errore ha una spiegazione
 * in italiano con che cosa fare su quel dispositivo.
 *
 * Modulo senza dipendenze dal browser al caricamento: le prove in Node gli
 * passano una geolocalizzazione finta.
 */

/** Sotto questa precisione (metri) la lettura e' buona e non si aspetta oltre. */
export const PRECISIONE_BUONA = 15;

export const TEMPO_MASSIMO = 30000;

export function piattaforma(ua = navigatorUa()) {
    if (/iPhone|iPad|iPod/i.test(ua)) return 'ios';
    if (/Android/i.test(ua)) return 'android';
    if (/Windows/i.test(ua)) return 'windows';
    if (/Macintosh|Mac OS X/i.test(ua)) return 'mac';

    return 'altro';
}

function navigatorUa() {
    return typeof navigator !== 'undefined' ? navigator.userAgent ?? '' : '';
}

function contestoSicuro() {
    return typeof window === 'undefined' ? true : window.isSecureContext !== false;
}

/**
 * Perche' la posizione non puo' arrivare, prima ancora di chiederla: manca
 * l'API o il sito e' in http. Null se si puo' provare.
 */
export function impedimento({ geolocation = typeof navigator !== 'undefined' ? navigator.geolocation : undefined, sicuro = contestoSicuro() } = {}) {
    if (! sicuro) {
        return 'La posizione non arriva perché il sito è aperto in http: il browser la fornisce solo sui siti sicuri (https, il lucchetto accanto all\'indirizzo).';
    }
    if (! geolocation) {
        return 'Questo browser non fornisce la posizione del dispositivo.';
    }

    return null;
}

const COME_CONSENTIRE = {
    ios: 'Su iPhone: Impostazioni > Privacy e sicurezza > Localizzazione, accendi "Localizzazione" e in "Siti web Safari" scegli "Mentre usi l\'app" con "Posizione esatta". '
        + 'Se Safari aveva chiesto il permesso e si è risposto no: tocca "AA" accanto all\'indirizzo > Impostazioni sito web > Posizione > Consenti (oppure Impostazioni > Safari > Posizione).',
    android: 'Su Android: Impostazioni > Posizione, accendila; poi in Chrome tocca il lucchetto accanto all\'indirizzo > Autorizzazioni > Posizione > Consenti e ricarica la pagina.',
    windows: 'Su Windows: Impostazioni > Privacy e sicurezza > Posizione, accendi "Servizi di localizzazione" e "Consenti alle app desktop di accedere alla posizione". '
        + 'Poi in Chrome clicca il lucchetto accanto all\'indirizzo > Autorizzazioni > Posizione > Consenti e ricarica la pagina.',
    mac: 'Su Mac: Impostazioni di Sistema > Privacy e sicurezza > Localizzazione, accendi il browser; poi nel browser consenti la posizione al sito (lucchetto accanto all\'indirizzo) e ricarica la pagina.',
    altro: 'Consenti la posizione al sito nelle impostazioni del browser (di solito dal lucchetto accanto all\'indirizzo) e ricarica la pagina.',
};

const SENZA_SEGNALE = {
    windows: 'Il computer non ha un GPS: Chrome stima la posizione dalle reti Wi-Fi vicine, e serve che in Windows i Servizi di localizzazione siano accesi. Con il solo cavo di rete, o in una stanza senza Wi-Fi, la posizione può mancare: in ufficio è normale. Prendila dalla mappa o inserisci le coordinate.',
    mac: 'Il computer stima la posizione dalle reti Wi-Fi vicine: con il Wi-Fi spento può mancare. Prendila dalla mappa o inserisci le coordinate.',
    ios: 'Il telefono non riesce a stabilire dove si trova: controlla che la Localizzazione sia accesa, esci all\'aperto e riprova fra qualche secondo.',
    android: 'Il telefono non riesce a stabilire dove si trova: controlla che la Posizione sia accesa (meglio in modalità "alta precisione"), esci all\'aperto e riprova.',
    altro: 'Il dispositivo non riesce a stabilire dove si trova: controlla che la localizzazione sia accesa e riprova all\'aperto.',
};

/**
 * Il messaggio per chi usa il programma, con che cosa fare su quel
 * dispositivo. err e' un GeolocationPositionError (code 1, 2, 3) o un errore
 * nostro ('contesto', 'annullata').
 */
export function spiegaErrore(err, { ua = navigatorUa(), sicuro = contestoSicuro() } = {}) {
    const dove = piattaforma(ua);
    if (! sicuro) {
        return impedimento({ geolocation: {}, sicuro: false });
    }
    const codice = err?.code ?? err?.codice;
    if (codice === 'annullata') {
        return 'Rilevamento annullato.';
    }
    if (codice === 'contesto') {
        return err.message;
    }
    // La frase del browser (in inglese) in coda: a chi legge una schermata
    // dice la causa esatta, che le parole nostre riassumono
    const dettaglio = typeof err?.message === 'string' && err.message !== '' && ! /^(Permesso negato|Tempo scaduto|Rilevamento)/.test(err.message)
        ? ` Dettaglio del browser: ${err.message}`
        : '';
    if (codice === 1) {
        // Il permesso del sito da solo non basta: anche il telefono o il
        // computer devono consentire la localizzazione al browser, e il
        // browser dice "negata" in tutti e due i casi
        return 'Il browser non ha potuto usare la posizione. Il permesso dato al sito da solo non basta: anche il telefono o il computer devono consentire la localizzazione al browser (succede spesso con il sito già autorizzato). '
            .concat(COME_CONSENTIRE[dove], dettaglio);
    }
    if (codice === 2) {
        return SENZA_SEGNALE[dove] + dettaglio;
    }
    if (codice === 3) {
        // Il browser ha chiesto il permesso e nessuno ha risposto (o la finestra
        // e' stata chiusa): la posizione non arriva e nessun errore lo dice
        const senzaRisposta = err?.permesso === 'prompt'
            ? 'Il browser non ha avuto risposta alla richiesta di usare la posizione: se compare la finestra "consentire la posizione?", scegli Consenti'
                + (dove === 'ios' ? ' (su iPhone anche "Posizione esatta")' : '') + '. '
            : '';

        return senzaRisposta
            + 'Nessuna posizione entro il tempo massimo: all\'aperto il GPS impiega anche mezzo minuto a partire, e al chiuso spesso non arriva. '
            + 'Riprova, oppure prendi la posizione dalla mappa.'
            + dettaglio;
    }

    return 'Posizione non disponibile in questo momento: riprova, oppure prendila dalla mappa.';
}

/**
 * Prende la posizione: una stima rapida subito, poi il GPS fino al tempo
 * massimo tenendo la lettura piu' precisa. Restituisce un controllo con la
 * promessa, "accetta" (vale la migliore arrivata finora) e "annulla".
 *
 * suAggiornamento riceve { lat, lon, accuracy, trascorsi } a ogni lettura
 * migliore e ogni secondo che passa (con la migliore finora, o null).
 */
export function prendiPosizione({
    geolocation = typeof navigator !== 'undefined' ? navigator.geolocation : undefined,
    permissions = typeof navigator !== 'undefined' ? navigator.permissions : undefined,
    sicuro = contestoSicuro(),
    precisione = PRECISIONE_BUONA,
    tempoMassimo = TEMPO_MASSIMO,
    suAggiornamento = () => {},
    ora = () => Date.now(),
} = {}) {
    let risolvi;
    let rifiuta;
    const promessa = new Promise((res, rej) => { risolvi = res; rifiuta = rej; });
    const inizio = ora();
    let migliore = null;
    let ultimoErrore = null;
    let chiuso = false;
    let idAscolto = null;
    let orologio = null;
    let scadenza = null;

    const lettura = (pos) => ({
        lat: pos.coords.latitude,
        lon: pos.coords.longitude,
        accuracy: Math.round((pos.coords.accuracy ?? 9999) * 10) / 10,
        timestamp: pos.timestamp ?? ora(),
    });
    const chiudi = () => {
        if (chiuso) return;
        chiuso = true;
        clearInterval(orologio);
        clearTimeout(scadenza);
        if (idAscolto !== null) {
            try { geolocation.clearWatch(idAscolto); } catch { /* niente */ }
        }
    };
    const statoPermesso = async () => {
        try {
            return (await permissions?.query?.({ name: 'geolocation' }))?.state ?? null;
        } catch {
            return null;
        }
    };
    const concludi = () => {
        if (chiuso) return;
        chiudi();
        if (migliore) {
            risolvi({ ...migliore, trascorsi: ora() - inizio });

            return;
        }
        if (ultimoErrore) {
            rifiuta(ultimoErrore);

            return;
        }
        // Nessuna lettura e nessun errore: di solito la richiesta di permesso
        // e' rimasta senza risposta. Lo si dice, se il browser lo sa
        statoPermesso().then((stato) => {
            if (stato === 'denied') rifiuta({ code: 1, message: 'Permesso negato.' });
            else rifiuta({ code: 3, permesso: stato, message: 'Tempo scaduto senza una posizione.' });
        });
    };
    const arrivata = (pos) => {
        if (chiuso) return;
        const l = lettura(pos);
        if (! migliore || l.accuracy < migliore.accuracy) {
            migliore = l;
            suAggiornamento({ ...migliore, trascorsi: ora() - inizio });
        }
        if (migliore.accuracy <= precisione) concludi();
    };
    const fallita = (err) => {
        if (chiuso) return;
        // Il permesso negato non si aspetta: va spiegato subito
        if (err?.code === 1) {
            chiudi();
            rifiuta(err);

            return;
        }
        ultimoErrore = err;
    };

    const motivo = impedimento({ geolocation, sicuro });
    if (motivo) {
        chiudi();
        rifiuta({ code: 'contesto', message: motivo });

        return { promessa, accetta: () => {}, annulla: () => {} };
    }

    // Se il browser sa gia' che il permesso e' negato, non si fa aspettare
    const avvia = () => {
        if (chiuso) return;
        try {
            // Una stima rapida: reti vicine o ultima posizione nota
            geolocation.getCurrentPosition(arrivata, fallita, { enableHighAccuracy: false, maximumAge: 120000, timeout: 8000 });
        } catch (e) {
            fallita({ code: 2, message: String(e) });
        }
        try {
            idAscolto = geolocation.watchPosition(arrivata, fallita, { enableHighAccuracy: true, maximumAge: 0, timeout: tempoMassimo });
        } catch (e) {
            fallita({ code: 2, message: String(e) });
        }
        orologio = setInterval(() => {
            if (chiuso) return;
            suAggiornamento(migliore ? { ...migliore, trascorsi: ora() - inizio } : { lat: null, lon: null, accuracy: null, trascorsi: ora() - inizio });
        }, 1000);
        scadenza = setTimeout(concludi, tempoMassimo);
    };

    let avviato = false;
    const avviaUnaVolta = () => {
        if (avviato) return;
        avviato = true;
        avvia();
    };
    if (permissions?.query) {
        Promise.resolve()
            .then(() => permissions.query({ name: 'geolocation' }))
            .then((stato) => {
                if (stato?.state === 'denied') {
                    chiudi();
                    rifiuta({ code: 1, message: 'Permesso negato.' });
                } else {
                    avviaUnaVolta();
                }
            })
            .catch(avviaUnaVolta);
    } else {
        avviaUnaVolta();
    }

    return {
        promessa,
        accetta: () => { if (migliore) concludi(); },
        annulla: () => {
            if (chiuso) return;
            chiudi();
            rifiuta({ code: 'annullata', message: 'Rilevamento annullato.' });
        },
    };
}

/**
 * Lo stato della posizione su questo dispositivo, prima di chiederla: pagina
 * sicura, API presente, permesso del sito secondo il browser ('granted',
 * 'denied', 'prompt', oppure null se il browser non lo dice), piattaforma.
 * Serve alla carta "Posizione del dispositivo" dell'app di campo: da una
 * schermata si capisce quale dei tre cancelli e' chiuso.
 */
export async function diagnostica({
    geolocation = typeof navigator !== 'undefined' ? navigator.geolocation : undefined,
    permissions = typeof navigator !== 'undefined' ? navigator.permissions : undefined,
    sicuro = contestoSicuro(),
    ua = navigatorUa(),
} = {}) {
    let permesso = null;
    try {
        permesso = (await permissions?.query?.({ name: 'geolocation' }))?.state ?? null;
    } catch {
        permesso = null;
    }

    return { sicuro: Boolean(sicuro), api: Boolean(geolocation), permesso, piattaforma: piattaforma(ua) };
}

const NOMI_PIATTAFORMA = { ios: 'iPhone o iPad', android: 'Android', windows: 'Windows', mac: 'Mac', altro: 'altro' };

/** "iPhone o iPad", "Windows"... per la piattaforma rilevata. */
export function nomePiattaforma(codice) {
    return NOMI_PIATTAFORMA[codice] ?? NOMI_PIATTAFORMA.altro;
}

/** Che cosa dice il browser del permesso del sito, in italiano. */
export function testoPermesso(stato) {
    if (stato === 'granted') return 'concesso al sito';
    if (stato === 'denied') return 'negato: il browser lo ricorda e non chiede più';
    if (stato === 'prompt') return 'da chiedere: il browser mostra la finestra alla prossima richiesta';

    return 'il browser non lo dice (succede con Safari)';
}

/** "precisione 12 m", o "" se manca. */
export function testoPrecisione(metri) {
    if (metri == null || ! Number.isFinite(metri)) return '';
    const m = metri >= 100 ? Math.round(metri) : Math.round(metri * 10) / 10;

    return `precisione ${String(m).replace('.', ',')} m`;
}
