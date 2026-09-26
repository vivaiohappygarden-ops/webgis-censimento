<script setup>
import { computed, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BOTTONE, BOTTONE_SECONDARIO } from '@/nuovo/stile';

/*
 * La modifica dei dati dell'albero, una sezione per volta: "misure" (altezza,
 * diametri, chioma, eta', stato vegetativo) oppure "identita" (nomi, fase,
 * posizione, tutele). Si salva con la stessa chiamata della scheda precedente
 * (PATCH assets/{id} con il blocco tree e la versione): i campi che non si
 * toccano restano quelli che sono.
 */
const props = defineProps({
    asset: { type: Object, required: true },
    sezione: { type: String, required: true }, // 'misure' | 'identita'
});
const emit = defineEmits(['saved', 'close']);

const agronomia = usePage().props.agronomia ?? {};
const soloData = (v) => (v ? String(v).slice(0, 10) : '');

const albero = reactive({
    ...(props.asset.tree ?? {}),
    planted_on: soloData(props.asset.tree?.planted_on),
    dedicated_to: { name: '', occasion: '', date: '', ...(props.asset.tree?.dedicated_to ?? {}) },
});

const MISURE = [
    { chiave: 'height_m', etichetta: 'Altezza (m)', tipo: 'number', passo: '0.1' },
    { chiave: 'dbh_cm', etichetta: 'Diametro del tronco a 1,30 m (cm)', tipo: 'number', passo: '1' },
    { chiave: 'trunk_circumference_cm', etichetta: 'Circonferenza del tronco (cm)', tipo: 'number', passo: '1' },
    { chiave: 'trunk_count', etichetta: 'Numero di fusti', tipo: 'number', passo: '1' },
    { chiave: 'crown_diameter_m', etichetta: 'Diametro della chioma (m)', tipo: 'number', passo: '0.1' },
    { chiave: 'crown_insertion_m', etichetta: 'Inserzione della chioma (m)', tipo: 'number', passo: '0.1' },
    { chiave: 'age_years_est', etichetta: 'Età stimata (anni)', tipo: 'number', passo: '1' },
    { chiave: 'age_qualifier', etichetta: "Qualificatore dell'età", tipo: 'select', voci: 'qualificatore_eta' },
    { chiave: 'vegetative_state', etichetta: 'Stato vegetativo', tipo: 'select', voci: 'stato_vegetativo' },
];
const IDENTITA = [
    { chiave: 'genus', etichetta: 'Genere', tipo: 'text' },
    { chiave: 'species', etichetta: 'Specie', tipo: 'text' },
    { chiave: 'cultivar', etichetta: 'Cultivar', tipo: 'text' },
    { chiave: 'family', etichetta: 'Famiglia', tipo: 'text' },
    { chiave: 'common_name', etichetta: 'Nome comune', tipo: 'text' },
    { chiave: 'age_class', etichetta: 'Fase fisiologica', tipo: 'select', voci: 'fase_fisiologica' },
    { chiave: 'social_position', etichetta: 'Posizione sociale', tipo: 'select', voci: 'posizione_sociale' },
    { chiave: 'growth_site', etichetta: 'Sito di crescita', tipo: 'select', voci: 'sito_di_crescita' },
    { chiave: 'target', etichetta: 'Bersaglio', tipo: 'select', voci: 'bersaglio' },
    { chiave: 'planted_on', etichetta: 'Data di impianto', tipo: 'date' },
];
const campi = computed(() => (props.sezione === 'misure' ? MISURE : IDENTITA));

// Un valore fuori dizionario (per esempio da un vecchio import) resta
// visibile come voce in piu', invece di sparire dalla tendina
const vociDi = (nome, attuale) => {
    const voci = [...(agronomia[nome] ?? [])];
    if (attuale && ! voci.includes(attuale)) voci.push(attuale);

    return voci;
};

const salvataggio = ref(false);
const errore = ref('');

async function salva() {
    salvataggio.value = true;
    errore.value = '';
    try {
        await axios.patch(`/api/v1/assets/${props.asset.id}`, {
            version: props.asset.version,
            tree: {
                genus: albero.genus || null,
                species: albero.species || null,
                cultivar: albero.cultivar || null,
                family: albero.family || null,
                common_name: albero.common_name || null,
                age_years_est: albero.age_years_est || null,
                age_qualifier: albero.age_qualifier || null,
                social_position: albero.social_position || null,
                growth_site: albero.growth_site || null,
                target: albero.target || null,
                height_m: albero.height_m || null,
                dbh_cm: albero.dbh_cm || null,
                trunk_circumference_cm: albero.trunk_circumference_cm || null,
                crown_diameter_m: albero.crown_diameter_m || null,
                crown_insertion_m: albero.crown_insertion_m || null,
                age_class: albero.age_class || null,
                trunk_count: albero.trunk_count || 1,
                vegetative_state: albero.vegetative_state || null,
                is_monumental: !! albero.is_monumental,
                monumental_ref: albero.is_monumental ? (albero.monumental_ref || null) : null,
                is_protected: !! albero.is_protected,
                protection_ref: albero.is_protected ? (albero.protection_ref || null) : null,
                is_dedicated: !! albero.is_dedicated,
                dedicated_to: albero.is_dedicated
                    ? { name: albero.dedicated_to.name || null, occasion: albero.dedicated_to.occasion || null, date: albero.dedicated_to.date || null }
                    : {},
                has_stake: !! albero.has_stake,
                has_bracing: !! albero.has_bracing,
                planted_on: albero.planted_on || null,
                removed_on: soloData(albero.removed_on) || null,
                removal_reason: albero.removed_on ? (albero.removal_reason || null) : null,
            },
        });
        emit('saved');
    } catch (err) {
        errore.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        salvataggio.value = false;
    }
}
</script>

<template>
    <form class="rounded-lg border border-green-200 bg-green-50/40 p-3" :data-test="`modulo-albero-${props.sezione}`" @submit.prevent="salva">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label v-for="c in campi" :key="c.chiave" class="block text-xs">
                <span class="text-gray-600">{{ c.etichetta }}</span>
                <select v-if="c.tipo === 'select'" v-model="albero[c.chiave]" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm" :data-test="`campo-${c.chiave}`">
                    <option :value="null">—</option>
                    <option v-for="v in vociDi(c.voci, albero[c.chiave])" :key="v" :value="v">{{ v }}</option>
                </select>
                <input
                    v-else
                    v-model="albero[c.chiave]"
                    :type="c.tipo"
                    :step="c.passo"
                    :min="c.tipo === 'number' ? 0 : undefined"
                    class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"
                    :data-test="`campo-${c.chiave}`"
                >
            </label>
        </div>

        <template v-if="props.sezione === 'identita'">
            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <label class="flex min-h-9 items-center gap-2"><input v-model="albero.is_monumental" type="checkbox" class="rounded border-gray-300"> Monumentale</label>
                <label class="flex min-h-9 items-center gap-2"><input v-model="albero.is_protected" type="checkbox" class="rounded border-gray-300"> Soggetto a tutela</label>
                <label class="flex min-h-9 items-center gap-2"><input v-model="albero.is_dedicated" type="checkbox" class="rounded border-gray-300"> Dedicato</label>
                <label class="flex min-h-9 items-center gap-2"><input v-model="albero.has_stake" type="checkbox" class="rounded border-gray-300"> Palo tutore</label>
                <label class="flex min-h-9 items-center gap-2"><input v-model="albero.has_bracing" type="checkbox" class="rounded border-gray-300"> Consolidamento</label>
            </div>
            <div v-if="albero.is_monumental || albero.is_protected" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label v-if="albero.is_monumental" class="block text-xs">
                    <span class="text-gray-600">Riferimento monumentale (L. 10/2013, elenco AMI)</span>
                    <input v-model="albero.monumental_ref" placeholder="es. AMI/03/000123" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm">
                </label>
                <label v-if="albero.is_protected" class="block text-xs">
                    <span class="text-gray-600">Riferimento del vincolo di tutela</span>
                    <input v-model="albero.protection_ref" placeholder="es. vincolo paesaggistico D.Lgs. 42/2004" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm">
                </label>
            </div>
            <div v-if="albero.is_dedicated" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label class="block text-xs"><span class="text-gray-600">Intitolato a</span><input v-model="albero.dedicated_to.name" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
                <label class="block text-xs"><span class="text-gray-600">Occasione</span><input v-model="albero.dedicated_to.occasion" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
                <label class="block text-xs"><span class="text-gray-600">Data della dedica</span><input v-model="albero.dedicated_to.date" type="date" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-sm"></label>
            </div>
        </template>

        <p v-if="errore" class="mt-2 text-sm text-red-700" data-test="modulo-albero-errore">{{ errore }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="submit" :class="BOTTONE" :disabled="salvataggio" data-test="modulo-albero-salva">{{ salvataggio ? 'Salvataggio…' : 'Salva' }}</button>
            <button type="button" :class="BOTTONE_SECONDARIO" @click="emit('close')">Annulla</button>
        </div>
    </form>
</template>
