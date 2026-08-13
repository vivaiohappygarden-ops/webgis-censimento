<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const STATUS = {
    open: { label: 'Aperta', cls: 'bg-red-100 text-red-800' },
    in_charge: { label: 'In carico', cls: 'bg-amber-100 text-amber-800' },
    resolved: { label: 'Risolta', cls: 'bg-green-100 text-green-800' },
    dismissed: { label: 'Archiviata', cls: 'bg-gray-200 text-gray-600' },
};
const SEVERITY = { low: 'Bassa', medium: 'Media', high: 'Alta', critical: 'Critica' };

const data = ref(null);
const loadError = ref(false);

async function load() {
    try {
        const response = await axios.get('/api/v1/portal/overview');
        data.value = response.data;
        loadError.value = false;
    } catch {
        loadError.value = true;
    }
}

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('it-IT') : '—');
const fmtNum = (n) => (n ?? 0).toLocaleString('it-IT');

onMounted(load);
</script>

<template>
    <Head title="Portale" />

    <AppLayout>
        <div class="p-6" data-test="portal">
            <div class="mb-4">
                <h1 class="text-xl font-semibold">Il tuo verde</h1>
                <p v-if="data?.client?.name" class="text-sm text-gray-500">{{ data.client.name }} · situazione aggiornata del territorio in gestione</p>
            </div>

            <p v-if="loadError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                Caricamento non riuscito.
                <button class="ml-1 font-medium underline" @click="load">Riprova</button>
            </p>

            <p v-if="data && ! data.linked" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-test="portal-unlinked">
                {{ data.message }}
            </p>

            <template v-if="data?.linked">
                <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4" data-test="portal-counts">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ fmtNum(data.counts.areas) }}</div>
                        <div class="text-xs text-gray-500">Aree verdi</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ fmtNum(data.counts.assets) }}</div>
                        <div class="text-xs text-gray-500">Elementi censiti</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ fmtNum(data.counts.completed_orders) }}</div>
                        <div class="text-xs text-gray-500">Lavori completati</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="text-2xl font-semibold">{{ fmtNum(data.counts.open_issues) }}</div>
                        <div class="text-xs text-gray-500">Segnalazioni aperte</div>
                    </div>
                </div>

                <h2 class="mb-2 text-sm font-semibold">Ultimi lavori completati</h2>
                <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <table class="w-full text-sm" data-test="portal-orders">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-4 py-2.5 font-medium">Data</th>
                                <th class="px-4 py-2.5 font-medium">Codice</th>
                                <th class="px-4 py-2.5 font-medium">Intervento</th>
                                <th class="px-4 py-2.5 font-medium">Area</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="o in data.orders" :key="o.code">
                                <td class="px-4 py-2 text-gray-600">{{ fmtDate(o.completed_at) }}</td>
                                <td class="px-4 py-2 font-medium">{{ o.code }}</td>
                                <td class="px-4 py-2">{{ o.title }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ o.area ?? '—' }}</td>
                            </tr>
                            <tr v-if="! data.orders.length">
                                <td colspan="4" class="px-4 py-6 text-center text-gray-400">Nessun lavoro completato finora.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2 class="mb-2 text-sm font-semibold">Segnalazioni sul tuo territorio</h2>
                <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <table class="w-full text-sm" data-test="portal-issues">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-4 py-2.5 font-medium">Codice</th>
                                <th class="px-4 py-2.5 font-medium">Stato</th>
                                <th class="px-4 py-2.5 font-medium">Gravità</th>
                                <th class="px-4 py-2.5 font-medium">Descrizione</th>
                                <th class="px-4 py-2.5 font-medium">Aperta il</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="i in data.issues" :key="i.code">
                                <td class="px-4 py-2 font-medium">{{ i.code }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS[i.status]?.cls">
                                        {{ STATUS[i.status]?.label ?? i.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ SEVERITY[i.severity] ?? i.severity }}</td>
                                <td class="max-w-64 truncate px-4 py-2" :title="i.description">{{ i.description }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ fmtDate(i.created_at) }}</td>
                            </tr>
                            <tr v-if="! data.issues.length">
                                <td colspan="5" class="px-4 py-6 text-center text-gray-400">Nessuna segnalazione sul tuo territorio.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2 class="mb-2 text-sm font-semibold">Le tue aree</h2>
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <table class="w-full text-sm" data-test="portal-areas">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-4 py-2.5 font-medium">Area</th>
                                <th class="px-4 py-2.5 font-medium">Codice</th>
                                <th class="px-4 py-2.5 font-medium">Località</th>
                                <th class="px-4 py-2.5 text-right font-medium">Superficie (mq)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="a in data.areas" :key="a.id">
                                <td class="px-4 py-2 font-medium">{{ a.name }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ a.code ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ [a.locality, a.site].filter(Boolean).join(' · ') || '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ a.area_sqm !== null ? fmtNum(Math.round(a.area_sqm)) : '—' }}</td>
                            </tr>
                            <tr v-if="! data.areas.length">
                                <td colspan="4" class="px-4 py-6 text-center text-gray-400">Nessuna area collegata.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
