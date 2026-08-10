<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const rows = ref([]);
const meta = reactive({ total: 0, current_page: 1, last_page: 1 });
const loading = ref(false);
const filters = reactive({ q: '', status: '', page: 1 });

let debounce = null;

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/assets', {
            params: {
                q: filters.q || undefined,
                status: filters.status || undefined,
                page: filters.page,
                per_page: 25,
            },
        });
        rows.value = data.data;
        meta.total = data.total;
        meta.current_page = data.current_page;
        meta.last_page = data.last_page;
    } finally {
        loading.value = false;
    }
}

watch(() => [filters.q, filters.status], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        filters.page = 1;
        load();
    }, 300);
});

watch(() => filters.page, load);

onMounted(load);

const measure = (row) => {
    if (row.computed_area_sqm) return `${Number(row.computed_area_sqm).toLocaleString('it-IT')} m²`;
    if (row.computed_length_m) return `${Number(row.computed_length_m).toLocaleString('it-IT')} m`;
    return '—';
};
</script>

<template>
    <Head title="Censimento" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Censimento</h1>
                    <p class="text-sm text-gray-500">{{ meta.total.toLocaleString('it-IT') }} elementi censiti</p>
                </div>
            </div>

            <div class="mb-4 flex gap-3">
                <input
                    v-model="filters.q"
                    type="search"
                    placeholder="Cerca per codice o note…"
                    class="w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:outline-none"
                >
                <select v-model="filters.status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">Tutti gli stati</option>
                    <option value="active">Attivo</option>
                    <option value="dismissed">Dismesso</option>
                </select>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Codice</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Stato</th>
                            <th class="px-4 py-3">Misura</th>
                            <th class="px-4 py-3">Aggiornato</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="loading">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">Caricamento…</td>
                        </tr>
                        <tr v-else-if="rows.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">Nessun elemento trovato.</td>
                        </tr>
                        <tr v-for="row in rows" v-else :key="row.id" class="hover:bg-green-50/40">
                            <td class="px-4 py-3 font-medium">{{ row.census_code || '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-400">{{ row.object_type?.code }}</span>
                                {{ row.object_type?.name }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="row.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                >
                                    {{ row.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ measure(row) }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ new Date(row.updated_at).toLocaleDateString('it-IT') }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="`/censimento/${row.id}`" class="font-medium text-green-700 hover:underline">
                                    Apri
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                <button
                    class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                    :disabled="filters.page <= 1"
                    @click="filters.page--"
                >
                    ← Precedente
                </button>
                <span class="text-gray-500">Pagina {{ meta.current_page }} di {{ meta.last_page }}</span>
                <button
                    class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                    :disabled="filters.page >= meta.last_page"
                    @click="filters.page++"
                >
                    Successiva →
                </button>
            </div>
        </div>
    </AppLayout>
</template>
