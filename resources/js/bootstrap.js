import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.headers.common['Accept'] = 'application/json';

/*
 * Richieste che non si arrendono al primo intoppo.
 *
 * Un rifiuto passeggero (tetto di richieste al minuto superato, processo PHP
 * momentaneamente occupato, un istante di rete assente) lasciava la pagina
 * vuota: l'unico rimedio era ricaricare a mano, anche due o tre volte. Qui la
 * richiesta viene ripetuta da sola, con attese crescenti, prima di dichiarare
 * il fallimento a chi sta usando il programma.
 */

// Solo esiti che significano "riprova più tardi": un 404 o un 422 non
// migliorano ripetendoli, e ripeterli allungherebbe soltanto l'attesa
const ESITI_PASSEGGERI = [408, 425, 429, 502, 503, 504];

// Attese fra un tentativo e l'altro: tre ritentativi al massimo
const ATTESE_MS = [500, 1500, 3500];
const ATTESA_MASSIMA_MS = 10000;

// Una lettura non deve restare appesa all'infinito: meglio un errore
// (che viene ripetuto da solo) di una rotella che gira per minuti
const SCADENZA_LETTURA_MS = 30000;

const lettura = (config) => ['get', 'head', 'options'].includes((config?.method ?? 'get').toLowerCase());

/*
 * Ripetere una lettura è sempre innocuo. Una scrittura si ripete solo quando
 * il server l'ha respinta prima di eseguirla - il 429 scatta nel conteggio
 * delle richieste, prima che il programma tocchi i dati - mai dopo un 502 o un
 * 504, che potrebbero averla già eseguita: si rischierebbe un doppione.
 */
function ripetibile(errore) {
    if (axios.isCancel && axios.isCancel(errore)) return false;

    // Senza rete il ritentativo è tempo perso: l'app di campo lavora offline e
    // ha una sua coda, non deve aspettare tre tentativi per accorgersene
    if (navigator.onLine === false) return false;

    const stato = errore.response?.status;
    if (stato === undefined) return lettura(errore.config); // rete caduta o richiesta scaduta
    if (stato === 429) return true;

    return lettura(errore.config) && ESITI_PASSEGGERI.includes(stato);
}

// Se il server dice quanto aspettare (Retry-After) si rispetta la sua
// indicazione; il pizzico di casualità sfasa i tentativi di richieste partite
// insieme, che altrimenti tornerebbero a bussare tutte nello stesso istante
function attesa(errore, tentativiFatti) {
    const indicata = Number(errore.response?.headers?.['retry-after']);
    if (Number.isFinite(indicata) && indicata > 0) {
        return Math.min(indicata * 1000, ATTESA_MASSIMA_MS);
    }

    return ATTESE_MS[Math.min(tentativiFatti, ATTESE_MS.length - 1)] + Math.floor(Math.random() * 250);
}

const pausa = (ms) => new Promise((esegui) => setTimeout(esegui, ms));

axios.interceptors.request.use((config) => {
    if (config.timeout === undefined && lettura(config)) {
        config.timeout = SCADENZA_LETTURA_MS;
    }

    return config;
});

/*
 * Ritorno alla pagina di accesso quando la sessione è scaduta.
 *
 * Va fatto una volta sola: se il programma continuasse a rimandare
 * all'accesso mentre la pagina di accesso a sua volta rimanda dentro (perché
 * la sessione web c'è ancora e a mancare è solo il riconoscimento sulle
 * chiamate ai dati), il browser rimbalzerebbe all'infinito. Al secondo
 * tentativo ravvicinato si preferisce mostrare l'avviso e fermarsi.
 */
const CHIAVE_RIENTRO = 'webgis:rientro-accesso';
const DISTANZA_RIENTRI_MS = 30000;

function rimandaAllAccesso() {
    try {
        const ultimo = Number(window.sessionStorage.getItem(CHIAVE_RIENTRO) ?? 0);
        // Il segno non si cancella quando una richiesta va a buon fine: si
        // lascia scadere da solo. Cancellarlo riaprirebbe la porta al
        // rimbalzo, perché basterebbe una chiamata riuscita in mezzo a due
        // rifiuti per far ripartire i rimandi.
        if (Date.now() - ultimo < DISTANZA_RIENTRI_MS) return false;
        window.sessionStorage.setItem(CHIAVE_RIENTRO, String(Date.now()));
    } catch {
        // Navigazione privata o memoria del browser non disponibile: si prova
        // comunque, ma senza rete di sicurezza contro il rimbalzo
    }

    window.location.href = '/login';

    return true;
}

axios.interceptors.response.use(null, async (errore) => {
    const config = errore.config;

    // senzaRitentativi: per le chiamate in cui l'attesa è peggio dell'errore
    if (! config || config.senzaRitentativi) return Promise.reject(errore);

    // Sessione scaduta: inutile ripetere, e inutile chiedere a chi lavora di
    // uscire e rientrare a mano. Si torna alla pagina di accesso.
    // L'app di campo è esclusa di proposito: ha una sua coda di lavoro non
    // ancora inviato e avvisa da sé, non va sbalzata fuori mentre si è in
    // cantiere.
    const stato = errore.response?.status;
    const percorso = window.location.pathname;
    if ((stato === 401 || stato === 419)
        && percorso !== '/login'
        && ! percorso.startsWith('/operatore')
        && rimandaAllAccesso()) {
        return Promise.reject(errore);
    }

    config.tentativiFatti = config.tentativiFatti ?? 0;
    if (config.tentativiFatti >= ATTESE_MS.length || ! ripetibile(errore)) {
        return Promise.reject(errore);
    }

    await pausa(attesa(errore, config.tentativiFatti));
    config.tentativiFatti += 1;

    return axios(config);
});
