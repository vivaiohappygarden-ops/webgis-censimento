<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    asset: { type: Object, required: true },
    canUpdate: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

const saving = ref(false);
const error = ref('');

const site = reactive({ ...(props.asset.planting_site ?? {}) });

const STATUSES = {
    free: 'Libero',
    reserved: 'Riservato',
    planted: 'Piantumato',
    unusable: 'Non utilizzabile',
};
const STATUS_COLORS = {
    free: 'bg-blue-100 text-blue-800',
    reserved: 'bg-amber-100 text-amber-800',
    planted: 'bg-green-100 text-green-800',
    unusable: 'bg-gray-200 text-gray-600',
};
const ORIGINS = {
    felling: 'Da abbattimento',
    new_design: 'Nuova progettazione',
    transplant: 'Trapianto',
    other: 'Altro',
};

async function save() {
    saving.value = true;
    error.value = '';
    try {
        await axios.patch(`/api/v1/assets/${props.asset.id}`, {
            version: props.asset.version,
            planting_site: {
                status: site.status,
                planned_species: site.planned_species || null,
                origin: site.origin || null,
                target_season: site.target_season || null,
                notes: site.notes || null,
            },
        });
        emit('saved');
    } catch (err) {
        error.value = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Posto libero (sede di impianto)</h2>
            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="STATUS_COLORS[site.status] ?? 'bg-gray-100'">
                {{ STATUSES[site.status] ?? site.status }}
            </span>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-4">
            <label class="block text-xs">
                <span class="text-gray-500">Stato</span>
                <select v-model="site.status" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option v-for="(label, value) in STATUSES" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Specie prevista</span>
                <input v-model="site.planned_species" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Origine</span>
                <select v-model="site.origin" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                    <option :value="null">—</option>
                    <option v-for="(label, value) in ORIGINS" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Stagione di impianto prevista</span>
                <input v-model="site.target_season" :disabled="! canUpdate" placeholder="es. 2026-2027" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
            </label>
        </div>

        <label class="mt-3 block text-xs">
            <span class="text-gray-500">Note</span>
            <textarea v-model="site.notes" rows="2" :disabled="! canUpdate" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm" />
        </label>

        <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <button
            v-if="canUpdate"
            class="mt-3 rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50"
            :disabled="saving"
            @click="save"
        >{{ saving ? 'Salvataggio…' : 'Salva posto libero' }}</button>
    </div>
</template>
