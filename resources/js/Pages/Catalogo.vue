<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const canManage = computed(() => (page.props.auth?.user?.permissions ?? []).includes('catalog.manage'));

const mainTypes = ref([]);
const total = ref(0);
const search = ref('');
const open = ref({});

// Editor campi personalizzati (Catalog Manager)
const editing = ref(null); // tipo oggetto in modifica
const fields = ref([]);
const fieldError = ref('');
const fieldForm = reactive({
    key: '', label: '', field_type: 'text', required: false, options: '', unit: '', cam_field: '',
});

const FIELD_TYPES = {
    text: 'Testo', textarea: 'Testo lungo', integer: 'Numero intero', number: 'Numero',
    boolean: 'Sì/No', date: 'Data', select: 'Selezione', multiselect: 'Selezione multipla', url: 'URL',
};

async function openFields(type) {
    editing.value = type;
    fieldError.value = '';
    const { data } = await axios.get('/api/v1/custom-fields', { params: { object_type_id: type.id } });
    fields.value = data.data;
}

async function addField() {
    fieldError.value = '';
    try {
        await axios.post('/api/v1/custom-fields', {
            object_type_id: editing.value.id,
            key: fieldForm.key,
            label: fieldForm.label,
            field_type: fieldForm.field_type,
            required: fieldForm.required,
            unit: fieldForm.unit || null,
            cam_field: fieldForm.cam_field.trim() || null,
            options: ['select', 'multiselect'].includes(fieldForm.field_type)
                ? fieldForm.options.split(',').map((o) => o.trim()).filter(Boolean)
                : [],
        });
        Object.assign(fieldForm, { key: '', label: '', field_type: 'text', required: false, options: '', unit: '', cam_field: '' });
        await openFields(editing.value);
    } catch (err) {
        fieldError.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? 'Errore';
    }
}

async function removeField(field) {
    if (! window.confirm(`Eliminare il campo "${field.label}"?`)) return;
    await axios.delete(`/api/v1/custom-fields/${field.id}`);
    await openFields(editing.value);
}

const GEO_LABEL = { P: 'Punto', L: 'Linea', S: 'Superficie' };
const TP_COLORS = { 1: '#16a34a', 2: '#475569', 3: '#d97706', 4: '#dc2626' };

onMounted(async () => {
    const { data } = await axios.get('/api/v1/catalog');
    mainTypes.value = data.data;
    total.value = data.meta.object_types_count;
    if (mainTypes.value.length) open.value[mainTypes.value[0].id] = true;
});

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return mainTypes.value;

    return mainTypes.value
        .map((m) => ({
            ...m,
            sub_types: m.sub_types
                .map((s) => ({
                    ...s,
                    object_types: s.object_types.filter(
                        (t) => t.code.toLowerCase().includes(q) || t.name.toLowerCase().includes(q)
                    ),
                }))
                .filter((s) => s.object_types.length > 0),
        }))
        .filter((m) => m.sub_types.length > 0);
});

const countTypes = (m) => m.sub_types.reduce((acc, s) => acc + s.object_types.length, 0);
</script>

<template>
    <Head title="Catalogo" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4">
                <h1 class="text-xl font-semibold">Catalogo oggetti</h1>
                <p class="text-sm text-gray-500">
                    {{ total }} tipi oggetto — Modello dati censimento del verde urbano v2.1 (CAM)
                </p>
            </div>

            <input
                v-model="search"
                type="search"
                placeholder="Cerca per codice o descrizione (es. P103108, panchina)…"
                class="mb-4 w-96 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none"
            >

            <div class="space-y-3">
                <div
                    v-for="m in filtered"
                    :key="m.id"
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white"
                >
                    <button
                        class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-gray-50"
                        @click="open[m.id] = !open[m.id]"
                    >
                        <span class="flex items-center gap-2 font-semibold">
                            <span class="inline-block h-3 w-3 rounded-full" :style="{ background: TP_COLORS[m.code] }" />
                            {{ m.code }}. {{ m.name }}
                            <span class="text-xs font-normal text-gray-400">({{ countTypes(m) }} tipi)</span>
                        </span>
                        <span class="text-gray-400">{{ open[m.id] || search ? '▾' : '▸' }}</span>
                    </button>

                    <div v-if="open[m.id] || search" class="divide-y divide-gray-100 border-t border-gray-100">
                        <div v-for="s in m.sub_types" :key="s.id" class="px-4 py-3">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ s.code }} — {{ s.name }}
                            </div>
                            <div class="grid grid-cols-1 gap-1 md:grid-cols-2 xl:grid-cols-3">
                                <div
                                    v-for="t in s.object_types"
                                    :key="t.id"
                                    class="flex items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-green-50/60"
                                >
                                    <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ t.code }}</code>
                                    <span class="flex-1 truncate" :title="t.name">{{ t.name }}</span>
                                    <span class="text-xs text-gray-400">{{ GEO_LABEL[t.allowed_geometry] }}</span>
                                    <button
                                        v-if="canManage"
                                        class="text-xs text-green-700 hover:underline"
                                        title="Campi personalizzati"
                                        @click="openFields(t)"
                                    >campi</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Drawer campi personalizzati -->
            <Teleport to="body">
                <div v-if="editing" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="editing = null">
                    <div class="h-full w-full max-w-md overflow-y-auto bg-white p-5 shadow-2xl">
                        <div class="flex items-start justify-between">
                            <div>
                                <code class="text-xs text-gray-400">{{ editing.code }}</code>
                                <h2 class="font-semibold">{{ editing.name }}</h2>
                                <p class="text-xs text-gray-500">Campi personalizzati della scheda</p>
                            </div>
                            <button class="text-gray-400 hover:text-gray-600" @click="editing = null">✕</button>
                        </div>

                        <ul class="mt-4 space-y-2">
                            <li
                                v-for="f in fields"
                                :key="f.id"
                                class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm"
                            >
                                <span>
                                    <span class="font-medium">{{ f.label }}</span>
                                    <span v-if="f.required" class="text-red-500">*</span>
                                    <span class="ml-1 text-xs text-gray-400">
                                        {{ FIELD_TYPES[f.field_type] }}{{ f.unit ? ` (${f.unit})` : '' }}
                                        — <code>{{ f.key }}</code><template v-if="f.cam_field"> — tracciato CAM: {{ f.cam_field }}</template>
                                    </span>
                                </span>
                                <button class="text-xs text-red-500 hover:underline" @click="removeField(f)">elimina</button>
                            </li>
                            <li v-if="! fields.length" class="py-3 text-center text-sm text-gray-400">
                                Nessun campo personalizzato: la scheda usa solo i dati di base.
                            </li>
                        </ul>

                        <form class="mt-5 space-y-2 rounded-xl bg-green-50/50 p-3" @submit.prevent="addField">
                            <h3 class="text-sm font-semibold">Aggiungi campo</h3>
                            <div class="flex gap-2">
                                <input v-model="fieldForm.label" required placeholder="Etichetta *" class="flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                <input v-model="fieldForm.key" required placeholder="chiave_tecnica *" pattern="[a-z][a-z0-9_]*" class="w-36 rounded-lg border border-gray-300 px-2.5 py-1.5 font-mono text-xs">
                            </div>
                            <div class="flex gap-2">
                                <select v-model="fieldForm.field_type" class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                    <option v-for="(label, value) in FIELD_TYPES" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <input v-model="fieldForm.unit" placeholder="Unità" class="w-20 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                                <input v-model="fieldForm.cam_field" placeholder="Campo CAM (es. LARG_m)" title="All'export CAM questo campo alimenta la colonna indicata del tracciato" data-test="field-cam" class="w-40 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
                            </div>
                            <input
                                v-if="['select', 'multiselect'].includes(fieldForm.field_type)"
                                v-model="fieldForm.options"
                                placeholder="Valori separati da virgola (es. buono, medio, scarso)"
                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                            >
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input v-model="fieldForm.required" type="checkbox" class="rounded border-gray-300"> Obbligatorio
                            </label>
                            <p v-if="fieldError" class="text-sm text-red-600">{{ fieldError }}</p>
                            <button type="submit" class="w-full rounded-lg bg-green-700 py-1.5 text-sm font-medium text-white hover:bg-green-800">
                                Aggiungi campo
                            </button>
                        </form>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
