<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const data = ref(null);
const loading = ref(false);
const pageError = ref('');

const WO_STATUS = {
    draft: 'Bozza',
    planned: 'Pianificato',
    assigned: 'Assegnato',
    in_progress: 'In corso',
    suspended: 'Sospeso',
    completed: 'Completato',
    cancelled: 'Annullato',
};
const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

// Da "2026-08" a "ago 26": etichette corte sotto le colonne
function monthLabel(key) {
    const [y, m] = key.split('-');
    return `${MONTHS[Number(m) - 1]} ${y.slice(2)}`;
}

// Le mappe {etichetta: numero} diventano righe con barra proporzionale
function bars(map) {
    const entries = Object.entries(map ?? {});
    const max = Math.max(1, ...entries.map(([, n]) => n));
    return entries.map(([label, n]) => ({ label, n, pct: Math.round(n / max * 100) }));
}

// Serie mensile per le colonne: altezza percentuale sul massimo
function columns(map) {
    const entries = Object.entries(map ?? {});
    const max = Math.max(1, ...entries.map(([, n]) => n));
    return entries.map(([key, n]) => ({ key, label: monthLabel(key), n, pct: Math.round(n / max * 100) }));
}

const fmt = (n) => (Number(n) || 0).toLocaleString('it-IT');

async function load() {
    loading.value = true;
    pageError.value = '';
    try {
        const res = await axios.get('/api/v1/stats/overview');
        data.value = res.data.data;
    } catch {
        pageError.value = 'Caricamento non riuscito: ricarica la pagina.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head title="Statistiche" />
    <AppLayout>
        <div class="mx-auto max-w-6xl px-4 py-6">
            <div class="mb-4">
                <h1 class="text-xl font-semibold">Statistiche</h1>
                <p class="text-sm text-gray-500">I numeri d'insieme del patrimonio e dell'attività; il dettaglio resta nelle pagine dedicate</p>
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>

            <div v-if="data" class="space-y-5">
                <!-- Censimento -->
                <section v-if="data.census" class="rounded-xl border border-gray-200 bg-white" data-test="stat-censimento">
                    <div class="border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Censimento</h2>
                    </div>
                    <div class="grid gap-6 px-4 py-4 md:grid-cols-2">
                        <div>
                            <div class="mb-3 grid grid-cols-2 gap-3">
                                <div class="rounded-lg bg-gray-50 px-3 py-2">
                                    <div class="text-lg font-semibold" data-test="stat-elementi">{{ fmt(data.census.assets_total) }}</div>
                                    <div class="text-xs text-gray-500">elementi censiti</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-3 py-2">
                                    <div class="text-lg font-semibold">{{ fmt(data.census.areas_total) }}</div>
                                    <div class="text-xs text-gray-500">aree</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-3 py-2">
                                    <div class="text-lg font-semibold">{{ fmt(data.census.trees_total) }}</div>
                                    <div class="text-xs text-gray-500">alberi in piedi</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-3 py-2">
                                    <div class="text-lg font-semibold">{{ fmt(data.census.protected_trees) }}</div>
                                    <div class="text-xs text-gray-500">alberi tutelati o dedicati</div>
                                </div>
                            </div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Per tipologia (prime 10)</h3>
                            <div class="space-y-1">
                                <div v-for="row in bars(data.census.by_type)" :key="row.label" class="flex items-center gap-2 text-sm">
                                    <span class="w-44 truncate" :title="row.label">{{ row.label }}</span>
                                    <span class="h-3 rounded-sm bg-green-700/80" :style="{ width: `${Math.max(2, row.pct * 0.6)}%` }" />
                                    <span class="text-xs text-gray-600">{{ fmt(row.n) }}</span>
                                </div>
                                <p v-if="! bars(data.census.by_type).length" class="text-sm text-gray-400">Nessun elemento censito.</p>
                            </div>
                        </div>
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Alberi per specie (prime 10)</h3>
                            <div class="space-y-1">
                                <div v-for="row in bars(data.census.by_species)" :key="row.label" class="flex items-center gap-2 text-sm">
                                    <span class="w-44 truncate italic" :title="row.label">{{ row.label }}</span>
                                    <span class="h-3 rounded-sm bg-green-700/80" :style="{ width: `${Math.max(2, row.pct * 0.6)}%` }" />
                                    <span class="text-xs text-gray-600">{{ fmt(row.n) }}</span>
                                </div>
                                <p v-if="! bars(data.census.by_species).length" class="text-sm text-gray-400">Nessun albero censito.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- VTA -->
                <section v-if="data.vta" class="rounded-xl border border-gray-200 bg-white" data-test="stat-vta">
                    <div class="border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Stabilità (VTA)</h2>
                    </div>
                    <div class="grid gap-6 px-4 py-4 md:grid-cols-2">
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Alberi per classe (ultima valutazione)</h3>
                            <div class="space-y-1">
                                <div v-for="row in bars(data.vta.by_class)" :key="row.label" class="flex items-center gap-2 text-sm">
                                    <span class="w-16">{{ row.label }}</span>
                                    <span class="h-3 rounded-sm" :class="['D', 'C/D'].includes(row.label) ? 'bg-red-600/80' : 'bg-green-700/80'" :style="{ width: `${Math.max(2, row.pct * 0.7)}%` }" />
                                    <span class="text-xs text-gray-600">{{ fmt(row.n) }}</span>
                                </div>
                                <p v-if="! bars(data.vta.by_class).length" class="text-sm text-gray-400">Nessuna valutazione registrata.</p>
                            </div>
                        </div>
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Valutazioni per anno</h3>
                            <div class="flex h-28 items-end gap-2" data-test="stat-vta-anni">
                                <div v-for="col in columns(data.vta.per_year)" :key="col.key" class="flex flex-1 flex-col items-center gap-1">
                                    <span class="text-xs text-gray-600">{{ fmt(col.n) }}</span>
                                    <div class="w-full rounded-t-sm bg-green-700/80" :style="{ height: `${Math.max(2, col.pct * 0.8)}px` }" />
                                    <span class="text-[10px] text-gray-500">{{ col.key }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Lavori -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="stat-lavori">
                    <div class="border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Lavori</h2>
                    </div>
                    <div class="grid gap-6 px-4 py-4 md:grid-cols-2">
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Ordini per stato</h3>
                            <div class="space-y-1">
                                <div v-for="row in bars(data.works.by_status)" :key="row.label" class="flex items-center gap-2 text-sm">
                                    <span class="w-28">{{ WO_STATUS[row.label] ?? row.label }}</span>
                                    <span class="h-3 rounded-sm bg-green-700/80" :style="{ width: `${Math.max(2, row.pct * 0.6)}%` }" />
                                    <span class="text-xs text-gray-600">{{ fmt(row.n) }}</span>
                                </div>
                                <p v-if="! bars(data.works.by_status).length" class="text-sm text-gray-400">Nessun ordine di lavoro.</p>
                            </div>
                        </div>
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Completati negli ultimi 12 mesi</h3>
                            <div class="flex h-28 items-end gap-1">
                                <div v-for="col in columns(data.works.completed_by_month)" :key="col.key" class="flex flex-1 flex-col items-center gap-1">
                                    <span class="text-[10px] text-gray-600">{{ col.n || '' }}</span>
                                    <div class="w-full rounded-t-sm bg-green-700/80" :style="{ height: `${Math.max(2, col.pct * 0.8)}px` }" />
                                    <span class="text-[9px] text-gray-500">{{ col.label }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Segnalazioni -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="stat-segnalazioni">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Segnalazioni</h2>
                        <p class="text-xs text-gray-500">
                            {{ fmt(data.issues.open_now) }} aperte ora
                            <template v-if="data.issues.avg_resolution_days !== null">
                                · risoluzione media {{ String(data.issues.avg_resolution_days).replace('.', ',') }} giorni
                            </template>
                        </p>
                    </div>
                    <div class="px-4 py-4">
                        <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">
                            Aperte (grigio) e risolte (verde) negli ultimi 12 mesi
                        </h3>
                        <div class="flex h-28 items-end gap-1">
                            <div v-for="(col, i) in columns(data.issues.opened_by_month)" :key="col.key" class="flex flex-1 flex-col items-center gap-1">
                                <div class="flex w-full items-end justify-center gap-0.5">
                                    <div class="w-1/2 rounded-t-sm bg-gray-400/70" :style="{ height: `${Math.max(2, col.pct * 0.8)}px` }" :title="`aperte: ${col.n}`" />
                                    <div class="w-1/2 rounded-t-sm bg-green-700/80" :style="{ height: `${Math.max(2, (columns(data.issues.resolved_by_month)[i]?.pct ?? 0) * 0.8)}px` }" :title="`risolte: ${columns(data.issues.resolved_by_month)[i]?.n ?? 0}`" />
                                </div>
                                <span class="text-[9px] text-gray-500">{{ col.label }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Fitosanitari -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="stat-fito">
                    <div class="border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Trattamenti fitosanitari</h2>
                    </div>
                    <div class="grid gap-6 px-4 py-4 md:grid-cols-2">
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Trattamenti per anno</h3>
                            <div class="flex h-28 items-end gap-2">
                                <div v-for="col in columns(data.phyto.per_year)" :key="col.key" class="flex flex-1 flex-col items-center gap-1">
                                    <span class="text-xs text-gray-600">{{ fmt(col.n) }}</span>
                                    <div class="w-full rounded-t-sm bg-green-700/80" :style="{ height: `${Math.max(2, col.pct * 0.8)}px` }" />
                                    <span class="text-[10px] text-gray-500">{{ col.key }}</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <h3 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Prodotti più usati</h3>
                            <div class="space-y-1">
                                <div v-for="row in bars(data.phyto.by_product)" :key="row.label" class="flex items-center gap-2 text-sm">
                                    <span class="w-44 truncate" :title="row.label">{{ row.label }}</span>
                                    <span class="h-3 rounded-sm bg-green-700/80" :style="{ width: `${Math.max(2, row.pct * 0.6)}%` }" />
                                    <span class="text-xs text-gray-600">{{ fmt(row.n) }}</span>
                                </div>
                                <p v-if="! bars(data.phyto.by_product).length" class="text-sm text-gray-400">Nessun trattamento registrato.</p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
