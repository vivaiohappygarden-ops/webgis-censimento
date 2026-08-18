<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({ asset: { type: Object, required: true } });
const emit = defineEmits(['saved', 'close']);

const fields = ref([]);
const saving = ref(false);
const error = ref('');
// La data arriva dal server come data completa: il campo del modulo vuole
// solo il giorno
const soloData = (v) => (v ? String(v).slice(0, 10) : '');

const form = reactive({
    census_code: props.asset.census_code ?? '',
    status: props.asset.status ?? 'active',
    surveyed_at: soloData(props.asset.surveyed_at),
    notes: props.asset.notes ?? '',
    attributes: { ...(props.asset.attributes ?? {}) },
});

onMounted(async () => {
    const { data } = await axios.get('/api/v1/custom-fields', {
        params: { object_type_id: props.asset.object_type.id },
    });
    fields.value = data.data;
    for (const f of fields.value) {
        if (!(f.key in form.attributes)) {
            form.attributes[f.key] = f.field_type === 'multiselect' ? [] : null;
        }
    }
});

async function save() {
    saving.value = true;
    error.value = '';
    try {
        const attributes = Object.fromEntries(
            Object.entries(form.attributes).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length))
        );
        await axios.patch(`/api/v1/assets/${props.asset.id}`, {
            census_code: form.census_code || null,
            status: form.status,
            surveyed_at: form.surveyed_at || null,
            notes: form.notes || null,
            attributes,
            version: props.asset.version,
        });
        emit('saved');
    } catch (err) {
        if (err.response?.status === 409) {
            error.value = 'Conflitto: la scheda è stata modificata da qualcun altro. Ricarica la pagina.';
        } else {
            error.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
                ?? err.response?.data?.message ?? 'Errore nel salvataggio';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="rounded-xl border border-green-200 bg-green-50/40 p-5">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Modifica scheda</h2>
            <button class="text-gray-400 hover:text-gray-600" @click="emit('close')">✕</button>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
            <label class="block text-xs">
                <span class="text-gray-500">Codice censimento</span>
                <input v-model="form.census_code" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Stato</span>
                <!-- Scheda abbattuta: lo stato si cambia solo annullando
                     l'abbattimento, altrimenti resterebbero la data di
                     rimozione e la fine validità di un elemento "attivo" -->
                <select
                    v-model="form.status"
                    :disabled="asset.status === 'removed'"
                    data-test="stato-scheda"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100 disabled:text-gray-500"
                >
                    <option value="active">Attivo</option>
                    <option value="dead">Morto in piedi</option>
                    <option value="stump">Ceppaia</option>
                    <option value="dismissed">Dismesso</option>
                    <option v-if="form.status === 'removed'" value="removed">Abbattuto/Rimosso</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Data del rilievo</span>
                <input
                    v-model="form.surveyed_at"
                    type="date"
                    data-test="data-rilievo"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                >
            </label>
        </div>
        <p v-if="asset.status === 'removed'" class="mt-1 text-xs text-amber-700">
            Elemento abbattuto: per riportarlo in vita usa "Annulla abbattimento" in fondo alla scheda.
        </p>
        <p v-else class="mt-1 text-xs text-gray-500">
            Per un elemento abbattuto o rimosso usa "Registra abbattimento" in fondo alla scheda:
            registra anche la data e aggiorna il bilancio arboreo.
        </p>

        <div v-if="fields.length" class="mt-3">
            <h3 class="text-xs font-semibold uppercase text-gray-400">Attributi del tipo</h3>
            <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-3">
                <label v-for="f in fields" :key="f.id" class="block text-xs">
                    <span class="text-gray-500">{{ f.label }}<span v-if="f.required" class="text-red-500">*</span>
                        <span v-if="f.unit" class="text-gray-400">({{ f.unit }})</span></span>
                    <select
                        v-if="f.field_type === 'select'"
                        v-model="form.attributes[f.key]"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                    >
                        <option :value="null">—</option>
                        <option v-for="o in f.options" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <select
                        v-else-if="f.field_type === 'multiselect'"
                        v-model="form.attributes[f.key]"
                        multiple
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                    >
                        <option v-for="o in f.options" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <input
                        v-else-if="f.field_type === 'boolean'"
                        v-model="form.attributes[f.key]"
                        type="checkbox"
                        class="mt-2 rounded border-gray-300"
                    >
                    <input
                        v-else-if="['integer', 'number'].includes(f.field_type)"
                        v-model.number="form.attributes[f.key]"
                        type="number"
                        :step="f.field_type === 'integer' ? 1 : 'any'"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                    >
                    <input
                        v-else-if="f.field_type === 'date'"
                        v-model="form.attributes[f.key]"
                        type="date"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                    >
                    <textarea
                        v-else-if="f.field_type === 'textarea'"
                        v-model="form.attributes[f.key]"
                        rows="2"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                    />
                    <input
                        v-else
                        v-model="form.attributes[f.key]"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                    >
                </label>
            </div>
        </div>

        <label class="mt-3 block text-xs">
            <span class="text-gray-500">Note</span>
            <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
        </label>

        <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>

        <button
            class="mt-3 rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
            :disabled="saving"
            @click="save"
        >{{ saving ? 'Salvataggio…' : 'Salva modifiche' }}</button>
    </div>
</template>
