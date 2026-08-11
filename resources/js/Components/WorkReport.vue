<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    clients: { type: Array, default: () => [] },
});
const emit = defineEmits(['open']);

// Nomi degli stati di valorizzazione, dichiarati e non nascosti
const VALUE_STATES = {
    ambiguous: 'Più voci di listino: importo non univoco',
    no_list: 'Senza listino',
    no_item: 'Listino senza voce per la lavorazione',
    no_quantity: 'Nessuna quantità nell\'unità del listino',
};

const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const monthStart = (d) => new Date(d.getFullYear(), d.getMonth(), 1);
const monthEnd = (d) => new Date(d.getFullYear(), d.getMonth() + 1, 0);

const filters = reactive({
    from: ymd(monthStart(new Date())),
    to: ymd(monthEnd(new Date())),
    client_id: '',
});
const report = ref(null);
const loading = ref(false);
const loadError = ref(false);

// Le risposte fuori ordine vanno scartate: vale solo l'interrogazione più recente
let seq = 0;
async function reload() {
    if (! filters.from || ! filters.to) return;
    const mySeq = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get('/api/v1/reports/lavori', { params: {
            from: filters.from,
            to: filters.to,
            ...(filters.client_id ? { client_id: filters.client_id } : {}),
        } });
        if (mySeq !== seq) return;
        report.value = data;
        loadError.value = false;
    } catch {
        if (mySeq === seq) loadError.value = true;
    } finally {
        if (mySeq === seq) loading.value = false;
    }
}
defineExpose({ reload });

function setMonth(offset) {
    const base = new Date();
    const target = new Date(base.getFullYear(), base.getMonth() + offset, 1);
    filters.from = ymd(monthStart(target));
    filters.to = ymd(monthEnd(target));
    reload();
}

const fmtEuro = (v) => (v == null ? '—' : Number(v).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }));
const fmtQty = (v) => Number(v).toLocaleString('it-IT');
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('it-IT') : '—');
const quantitiesLabel = (quantities) => Object.entries(quantities ?? {})
    .map(([unit, qty]) => `${fmtQty(qty)} ${unit || 'senza unità'}`).join(', ') || '—';

const periodLabel = computed(() => `${fmtDate(filters.from)} – ${fmtDate(filters.to)}`);

// Esport CSV con separatore ';' (convenzione italiana per i fogli di calcolo)
function exportCsv() {
    if (! report.value) return;
    const esc = (v) => `"${String(v ?? '').replaceAll('"', '""')}"`;
    const lines = [
        ['Cliente', 'Codice', 'Titolo', 'Lavorazione', 'Squadra', 'Completato il', 'Ore uomo', 'Quantita', 'Importo EUR', 'Nota'].map(esc).join(';'),
    ];
    for (const group of report.value.clients) {
        for (const row of group.orders) {
            lines.push([
                group.client?.name ?? 'Senza cliente',
                row.code,
                row.title,
                row.work_type ?? '',
                row.team ?? '',
                fmtDate(row.completed_at),
                row.man_hours,
                quantitiesLabel(row.quantities),
                row.amount != null ? String(row.amount).replace('.', ',') : '',
                row.value_state === 'ok' ? '' : (VALUE_STATES[row.value_state] ?? row.value_state),
            ].map(esc).join(';'));
        }
    }
    lines.push(['TOTALE', '', '', '', '', '', report.value.total_hours, '', String(report.value.total_amount).replace('.', ','), ''].map(esc).join(';'));

    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `rendiconto-lavori-${filters.from}-${filters.to}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}

onMounted(reload);
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-end gap-2">
            <label class="block text-xs">
                <span class="text-gray-500">Dal</span>
                <input v-model="filters.from" type="date" data-test="report-from" class="mt-1 rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="reload">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Al</span>
                <input v-model="filters.to" type="date" data-test="report-to" class="mt-1 rounded-lg border border-gray-300 px-2.5 py-2 text-sm" @change="reload">
            </label>
            <label class="block text-xs">
                <span class="text-gray-500">Cliente</span>
                <select v-model="filters.client_id" data-test="report-client" class="mt-1 rounded-lg border border-gray-300 px-2 py-2 text-sm" @change="reload">
                    <option value="">Tutti</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm" @click="setMonth(0)">Questo mese</button>
            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm" @click="setMonth(-1)">Mese scorso</button>
            <button
                class="ml-auto rounded-lg border border-green-700 px-3 py-2 text-sm font-medium text-green-700 disabled:opacity-50"
                :disabled="! report || ! report.orders_count"
                data-test="report-csv"
                @click="exportCsv"
            >Esporta CSV</button>
        </div>

        <p v-if="loadError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            Caricamento del rendiconto non riuscito.
            <button class="ml-1 font-medium underline" @click="reload">Riprova</button>
        </p>

        <div v-if="report" class="space-y-4">
            <!-- Totali del periodo -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-test="report-totals">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Lavori completati</div>
                    <div class="mt-1 text-xl font-semibold">{{ report.orders_count }}</div>
                    <div class="text-xs text-gray-400">{{ periodLabel }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Ore uomo</div>
                    <div class="mt-1 text-xl font-semibold">{{ fmtQty(report.total_hours) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Importo da listino</div>
                    <div class="mt-1 text-xl font-semibold" data-test="report-total-amount">{{ fmtEuro(report.total_amount) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Non valorizzati</div>
                    <div class="mt-1 text-xl font-semibold" :class="report.unvalued_count ? 'text-amber-700' : ''">{{ report.unvalued_count }}</div>
                    <div v-if="report.unvalued_count" class="text-xs text-amber-700">importo assente dal totale</div>
                </div>
            </div>

            <!-- Un blocco per cliente -->
            <div v-for="group in report.clients" :key="group.client?.id ?? 'none'" class="overflow-hidden rounded-xl border border-gray-200 bg-white" data-test="report-client-group">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                    <h2 class="text-sm font-semibold">{{ group.client?.name ?? 'Senza cliente' }}</h2>
                    <div class="text-sm">
                        <span class="text-gray-500">{{ fmtQty(group.subtotal_hours) }} ore · </span>
                        <span class="font-semibold">{{ fmtEuro(group.subtotal_amount) }}</span>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-50 text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-2 font-medium">Codice</th>
                            <th class="px-4 py-2 font-medium">Titolo</th>
                            <th class="px-4 py-2 font-medium">Lavorazione</th>
                            <th class="px-4 py-2 font-medium">Completato</th>
                            <th class="px-4 py-2 text-right font-medium">Ore</th>
                            <th class="px-4 py-2 text-right font-medium">Quantità</th>
                            <th class="px-4 py-2 text-right font-medium">Importo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="row in group.orders"
                            :key="row.id"
                            class="cursor-pointer hover:bg-green-50/40"
                            @click="emit('open', row.id)"
                        >
                            <td class="px-4 py-2 font-medium">{{ row.code }}</td>
                            <td class="max-w-56 truncate px-4 py-2">{{ row.title }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ row.work_type ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ fmtDate(row.completed_at) }}</td>
                            <td class="px-4 py-2 text-right">{{ fmtQty(row.man_hours) }}</td>
                            <td class="px-4 py-2 text-right text-gray-600">{{ quantitiesLabel(row.quantities) }}</td>
                            <td class="px-4 py-2 text-right">
                                <span v-if="row.value_state === 'ok'" class="font-medium">{{ fmtEuro(row.amount) }}</span>
                                <span v-else class="text-xs text-amber-700" :title="VALUE_STATES[row.value_state]">{{ VALUE_STATES[row.value_state] ?? row.value_state }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="! report.orders_count" class="rounded-xl border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-400">
                Nessun lavoro completato nel periodo selezionato.
            </p>
        </div>
    </div>
</template>
