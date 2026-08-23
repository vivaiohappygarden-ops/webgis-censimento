import { ref } from 'vue';
import { avvisoCaricamento } from '@/avvisi';

/*
 * Caricamenti che non falliscono in silenzio.
 *
 * Prima, se una richiesta andava male, l'elenco restava semplicemente vuoto:
 * chi usava il programma non aveva modo di distinguere "non ci sono dati" da
 * "i dati non sono arrivati", e l'unico rimedio era ricaricare la pagina a
 * mano. Qui il fallimento diventa un avviso leggibile con il pulsante per
 * ripetere l'ultima operazione.
 */
export function usaCaricamento() {
    const avviso = ref('');
    const riprovaInCorso = ref(false);
    let ultimo = null;

    async function carica(azione) {
        ultimo = azione;
        avviso.value = '';
        try {
            await azione();

            return true;
        } catch (errore) {
            avviso.value = avvisoCaricamento(errore);

            return false;
        }
    }

    async function riprova() {
        if (! ultimo) return;
        riprovaInCorso.value = true;
        try {
            await carica(ultimo);
        } finally {
            riprovaInCorso.value = false;
        }
    }

    return { avviso, riprovaInCorso, carica, riprova };
}
