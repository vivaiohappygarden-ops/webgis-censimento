<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { messaggioErrore } from '@/avvisi';

/**
 * Viste salvate: i filtri correnti dell'elenco memorizzati con un nome.
 *
 * La pagina passa i filtri correnti e riceve "applica" quando l'utente
 * sceglie una vista. La predefinita viene applicata da sola all'apertura.
 */
const props = defineProps({
    pagina: { type: String, required: true },
    // I filtri correnti della pagina, pronti da salvare
    filtri: { type: Object, required: true },
    // Applicare la predefinita al montaggio: la pagina lo spegne quando
    // rimonta il componente (cambio scheda interna) e i filtri a video
    // sono gia' quelli scelti a mano dall'utente, da non sovrascrivere
    auto: { type: Boolean, default: true },
});

const emit = defineEmits(['applica']);

const viste = ref([]);
const scelta = ref('');
const salvataggio = reactive({ aperto: false, nome: '', condivisa: false, inCorso: false });
const errore = ref('');

async function carica(applicaPredefinita = false) {
    try {
        const { data } = await axios.get('/api/v1/viste', { params: { pagina: props.pagina } });
        viste.value = data.data;
        if (applicaPredefinita) {
            // Solo la PROPRIA predefinita: quella di un collega e' una sua scelta
            const predefinita = viste.value.find((v) => v.predefinita && v.mia);
            if (predefinita) {
                scelta.value = predefinita.id;
                emit('applica', predefinita.filtri);
            }
        }
    } catch {
        // Le viste sono una comodita': se non arrivano, l'elenco funziona lo stesso
    }
}

function applica() {
    const vista = viste.value.find((v) => v.id === scelta.value);
    if (vista) emit('applica', vista.filtri);
}

async function salva() {
    if (! salvataggio.nome.trim()) return;
    salvataggio.inCorso = true;
    errore.value = '';
    try {
        const { data } = await axios.post('/api/v1/viste', {
            pagina: props.pagina,
            nome: salvataggio.nome,
            filtri: props.filtri,
            condivisa: salvataggio.condivisa,
        });
        salvataggio.aperto = false;
        salvataggio.nome = '';
        salvataggio.condivisa = false;
        await carica();
        scelta.value = data.data.id;
    } catch (err) {
        errore.value = messaggioErrore(err, 'Salvataggio non riuscito');
    } finally {
        salvataggio.inCorso = false;
    }
}

const vistaScelta = () => viste.value.find((v) => v.id === scelta.value);

async function commutaPredefinita() {
    const vista = vistaScelta();
    if (! vista?.mia) return;
    try {
        await axios.patch(`/api/v1/viste/${vista.id}`, { predefinita: ! vista.predefinita });
        await carica();
    } catch (err) {
        errore.value = messaggioErrore(err, 'Operazione non riuscita');
    }
}

async function elimina() {
    const vista = vistaScelta();
    if (! vista?.mia) return;
    if (! window.confirm(`Eliminare la vista "${vista.nome}"?`)) return;
    try {
        await axios.delete(`/api/v1/viste/${vista.id}`);
        scelta.value = '';
        await carica();
    } catch (err) {
        errore.value = messaggioErrore(err, 'Eliminazione non riuscita');
    }
}

onMounted(() => carica(props.auto));
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <select
            v-model="scelta"
            data-test="viste-scelta"
            class="w-full rounded-lg border border-gray-300 px-2 py-2 text-sm sm:w-auto"
            @change="applica"
        >
            <option value="">Viste salvate…</option>
            <option v-for="v in viste" :key="v.id" :value="v.id">
                {{ v.nome }}{{ v.predefinita ? ' (predefinita)' : '' }}{{ ! v.mia ? ` — di ${v.di}` : '' }}
            </option>
        </select>

        <template v-if="vistaScelta()?.mia">
            <button
                class="rounded-lg border border-gray-300 px-2 py-2 text-xs text-gray-700 hover:bg-gray-50"
                :title="vistaScelta().predefinita ? 'Non aprire più da sola' : 'Applica da sola all\'apertura della pagina'"
                data-test="viste-predefinita"
                @click="commutaPredefinita"
            >{{ vistaScelta().predefinita ? 'Togli predefinita' : 'Predefinita' }}</button>
            <button
                class="rounded-lg border border-red-300 px-2 py-2 text-xs text-red-700 hover:bg-red-50"
                data-test="viste-elimina"
                @click="elimina"
            >Elimina</button>
        </template>

        <button
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
            data-test="viste-salva"
            @click="salvataggio.aperto = ! salvataggio.aperto"
        >Salva vista</button>

        <div v-if="salvataggio.aperto" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <input
                v-model="salvataggio.nome"
                placeholder="Nome della vista (es. Tigli da spollonare)"
                maxlength="80"
                data-test="viste-nome"
                class="w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm sm:w-64"
                @keydown.enter="salva"
            >
            <label class="flex items-center gap-1.5 text-xs text-gray-600">
                <input v-model="salvataggio.condivisa" type="checkbox">
                Condivisa con i colleghi
            </label>
            <button
                class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
                :disabled="salvataggio.inCorso || ! salvataggio.nome.trim()"
                data-test="viste-conferma"
                @click="salva"
            >Salva</button>
        </div>

        <p v-if="errore" class="w-full text-xs text-red-700">{{ errore }}</p>
    </div>
</template>
