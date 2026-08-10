<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const data = ref(null);

const CLASS_COLORS = {
    'A': 'bg-green-100 text-green-800',
    'B': 'bg-lime-100 text-lime-800',
    'C': 'bg-yellow-100 text-yellow-800',
    'C/D': 'bg-orange-100 text-orange-800',
    'D': 'bg-red-100 text-red-800',
    'n.d.': 'bg-gray-100 text-gray-600',
};

const fmt = (d) => (d ? new Date(d).toLocaleDateString('it-IT') : '—');

onMounted(async () => {
    const res = await axios.get('/api/v1/vta/dashboard');
    data.value = res.data.data;
});
</script>

<template>
    <Head title="Scadenzario VTA" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Scadenzario VTA</h1>
                    <p class="text-sm text-gray-500">Valutazioni di stabilità: stato del patrimonio arboreo e ricontrolli</p>
                </div>
                <div class="flex gap-2">
                    <a href="/api/v1/exports/cam?layer=P1&format=geojson" class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
                        ⬇️ Export CAM P1 (GeoJSON)
                    </a>
                    <a href="/api/v1/exports/cam?layer=P1&format=shapefile" class="rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
                        ⬇️ Shapefile RDN2008
                    </a>
                </div>
            </div>

            <div v-if="data" class="space-y-6">
                <!-- Contatori -->
                <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ data.trees_total }}</div>
                        <div class="text-xs text-gray-500">Alberi censiti</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ data.assessed }}</div>
                        <div class="text-xs text-gray-500">Con almeno una VTA</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold text-gray-500">{{ data.never_assessed }}</div>
                        <div class="text-xs text-gray-500">Mai valutati</div>
                    </div>
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                        <div class="text-2xl font-semibold text-red-700">{{ data.overdue_count }}</div>
                        <div class="text-xs text-red-600">Ricontrolli scaduti</div>
                    </div>
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div class="text-2xl font-semibold text-amber-700">{{ data.upcoming_count }}</div>
                        <div class="text-xs text-amber-600">In scadenza (30 gg)</div>
                    </div>
                </div>

                <!-- Classi -->
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Classi di propensione al cedimento (ultima VTA per albero)</h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <span
                            v-for="(count, cls) in data.by_class"
                            :key="cls"
                            class="rounded-full px-4 py-1.5 text-sm font-semibold"
                            :class="CLASS_COLORS[cls] ?? 'bg-gray-100'"
                        >{{ cls }}: {{ count }}</span>
                        <span v-if="! Object.keys(data.by_class).length" class="text-sm text-gray-400">Nessuna valutazione ancora registrata.</span>
                    </div>
                </div>

                <!-- Scaduti -->
                <div class="rounded-xl border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-red-700">
                        ⚠️ Ricontrolli scaduti ({{ data.overdue_count }})
                    </h2>
                    <table v-if="data.overdue.length" class="w-full text-sm">
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="row in data.overdue" :key="row.tree_id" class="hover:bg-red-50/40">
                                <td class="px-4 py-2 font-medium">{{ row.census_code || '—' }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="row.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[row.failure_class]">{{ row.failure_class }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">VTA {{ fmt(row.assessed_on) }}</td>
                                <td class="px-4 py-2 font-semibold text-red-600">scaduto {{ fmt(row.next_check_due) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="`/censimento/${row.tree_id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-5 text-sm text-gray-400">Nessun ricontrollo scaduto. 👏</p>
                </div>

                <!-- In scadenza -->
                <div class="rounded-xl border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-amber-700">
                        ⏳ In scadenza nei prossimi 30 giorni ({{ data.upcoming_count }})
                    </h2>
                    <table v-if="data.upcoming.length" class="w-full text-sm">
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="row in data.upcoming" :key="row.tree_id" class="hover:bg-amber-50/40">
                                <td class="px-4 py-2 font-medium">{{ row.census_code || '—' }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="row.failure_class" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CLASS_COLORS[row.failure_class]">{{ row.failure_class }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">VTA {{ fmt(row.assessed_on) }}</td>
                                <td class="px-4 py-2 font-medium text-amber-700">entro {{ fmt(row.next_check_due) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="`/censimento/${row.tree_id}`" class="font-medium text-green-700 hover:underline">Apri</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-5 text-sm text-gray-400">Nessuna scadenza nei prossimi 30 giorni.</p>
                </div>
            </div>

            <div v-else class="mt-10 text-center text-gray-400">Caricamento…</div>
        </div>
    </AppLayout>
</template>
