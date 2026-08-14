<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const data = ref(null);
const loading = ref(false);
const pageError = ref('');

const WO_STATUS = {
    planned: 'Pianificato',
    assigned: 'Assegnato',
    in_progress: 'In corso',
    suspended: 'Sospeso',
};
const SEVERITY = { critical: 'Critica', high: 'Alta', medium: 'Media', low: 'Bassa' };
// Le non conformità hanno un vocabolario di gravità tutto loro
const NC_SEVERITY = { minor: 'Lieve', major: 'Grave', critical: 'Critica' };
const NC_STATUS = { open: 'Aperta', action: 'In azione', verified: 'Verificata' };

function formatDate(value) {
    if (! value) return '—';
    const [y, m, d] = String(value).slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

// Le scadenze SLA sono istanti ISO in UTC: la data va letta nel fuso
// italiano, o intorno alla mezzanotte si mostrerebbe il giorno prima
function formatDateTimeRome(value) {
    if (! value) return '—';
    return new Date(value).toLocaleDateString('it-IT', { timeZone: 'Europe/Rome' });
}

function issueDue(issue) {
    const phase = issue.status === 'open' ? issue.sla?.take_charge : issue.sla?.resolve;
    if (! phase) return { label: '—', late: false };
    const late = phase.state === 'overdue';
    const verb = issue.status === 'open' ? 'presa in carico' : 'risoluzione';
    return {
        label: late
            ? (phase.days_late > 0 ? `${verb} in ritardo di ${phase.days_late} g` : `${verb} scaduta oggi`)
            : `${verb} entro ${formatDateTimeRome(phase.due_at)}`,
        late,
    };
}

async function load() {
    loading.value = true;
    pageError.value = '';
    try {
        const res = await axios.get('/api/v1/dashboard/today');
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
    <Head title="Oggi" />

    <AppLayout>
        <div class="p-6">
            <div class="mb-4">
                <h1 class="text-xl font-semibold">Oggi</h1>
                <p class="text-sm text-gray-500">Ciò che richiede attenzione: ritardi e scadenze dei prossimi giorni, in un colpo d'occhio</p>
            </div>

            <p v-if="pageError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ pageError }}</p>

            <div v-if="data" class="space-y-5">
                <!-- Lavori -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="oggi-lavori">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Lavori</h2>
                        <Link href="/lavori" class="text-xs font-medium text-green-800 hover:underline">Vai ai lavori →</Link>
                    </div>
                    <div class="px-4 py-3">
                        <p v-if="data.work_orders.overdue_count || data.work_orders.week_count" class="mb-2 text-xs font-medium" data-test="oggi-lavori-ritardo">
                            <span v-if="data.work_orders.overdue_count" class="text-red-700">{{ data.work_orders.overdue_count }} in ritardo sulla fine prevista</span>
                            <span v-if="data.work_orders.overdue_count && data.work_orders.week_count" class="text-gray-400"> · </span>
                            <span v-if="data.work_orders.week_count" class="text-gray-600">{{ data.work_orders.week_count }} in programma nei prossimi 7 giorni</span>
                        </p>
                        <table v-if="data.work_orders.overdue.length || data.work_orders.week.length" class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="wo in data.work_orders.overdue" :key="wo.id">
                                    <td class="py-1.5 pr-2 font-medium">{{ wo.code }}</td>
                                    <td class="py-1.5 pr-2">{{ wo.title }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ wo.area ?? '—' }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ WO_STATUS[wo.status] ?? wo.status }}</td>
                                    <td class="py-1.5 text-right text-red-700">doveva chiudersi il {{ formatDate(wo.planned_end) }}</td>
                                </tr>
                                <tr v-for="wo in data.work_orders.week" :key="wo.id">
                                    <td class="py-1.5 pr-2 font-medium">{{ wo.code }}</td>
                                    <td class="py-1.5 pr-2">{{ wo.title }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ wo.area ?? '—' }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ WO_STATUS[wo.status] ?? wo.status }}</td>
                                    <td class="py-1.5 text-right text-gray-600">dal {{ formatDate(wo.planned_start) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-400">Nessun lavoro in ritardo né in programma nei prossimi 7 giorni.</p>
                    </div>
                </section>

                <!-- Ispezioni -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="oggi-ispezioni">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Controlli ricorrenti</h2>
                        <Link href="/ispezioni" class="text-xs font-medium text-green-800 hover:underline">Vai alle ispezioni →</Link>
                    </div>
                    <div class="px-4 py-3">
                        <p v-if="data.inspections.overdue_count || data.inspections.due_soon_count" class="mb-2 text-xs font-medium">
                            <span v-if="data.inspections.overdue_count" class="text-red-700">{{ data.inspections.overdue_count }} {{ data.inspections.overdue_count === 1 ? 'scaduto' : 'scaduti' }}</span>
                            <span v-if="data.inspections.overdue_count && data.inspections.due_soon_count" class="text-gray-400"> · </span>
                            <span v-if="data.inspections.due_soon_count" class="text-gray-600">{{ data.inspections.due_soon_count }} in scadenza entro 14 giorni</span>
                        </p>
                        <table v-if="data.inspections.rows.length" class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="row in data.inspections.rows" :key="row.template_id + row.target_id">
                                    <td class="py-1.5 pr-2 font-medium">{{ row.template_name }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ row.target_label }}</td>
                                    <td class="py-1.5 text-right" :class="row.state === 'overdue' ? 'text-red-700' : 'text-gray-600'">
                                        <template v-if="row.state === 'overdue'">in ritardo di {{ -row.days_left }} g (dovuto il {{ formatDate(row.due_date) }})</template>
                                        <template v-else>entro il {{ formatDate(row.due_date) }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-400">Nessun controllo scaduto o in scadenza entro 14 giorni.</p>
                    </div>
                </section>

                <!-- Segnalazioni -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="oggi-segnalazioni">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Segnalazioni con tempi a rischio</h2>
                        <Link href="/segnalazioni" class="text-xs font-medium text-green-800 hover:underline">Vai alle segnalazioni →</Link>
                    </div>
                    <div class="px-4 py-3">
                        <table v-if="data.issues.rows.length" class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="issue in data.issues.rows" :key="issue.id">
                                    <td class="py-1.5 pr-2 font-medium">{{ issue.code }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ SEVERITY[issue.severity] ?? issue.severity }}</td>
                                    <td class="py-1.5 pr-2">{{ issue.description }}</td>
                                    <td class="py-1.5 text-right" :class="issueDue(issue).late ? 'text-red-700' : 'text-gray-600'">
                                        {{ issueDue(issue).label }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-400">Nessuna segnalazione con scadenze superate o imminenti.</p>
                    </div>
                </section>

                <!-- Non conformità -->
                <section class="rounded-xl border border-gray-200 bg-white" data-test="oggi-nc">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Non conformità aperte ({{ data.non_conformities.open_count }})</h2>
                        <Link href="/lavori?vista=qualita" class="text-xs font-medium text-green-800 hover:underline">Vai alla qualità →</Link>
                    </div>
                    <div class="px-4 py-3">
                        <table v-if="data.non_conformities.rows.length" class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="nc in data.non_conformities.rows" :key="nc.id">
                                    <td class="py-1.5 pr-2 font-medium">{{ nc.code }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ NC_SEVERITY[nc.severity] ?? nc.severity }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ NC_STATUS[nc.status] ?? nc.status }}</td>
                                    <td class="py-1.5 pr-2">{{ nc.description }}</td>
                                    <td class="py-1.5 text-right text-gray-600">{{ nc.due_on ? `entro il ${formatDate(nc.due_on)}` : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-400">Nessuna non conformità aperta.</p>
                    </div>
                </section>

                <!-- Patentini e certificati -->
                <section v-if="data.certificates" class="rounded-xl border border-gray-200 bg-white" data-test="oggi-patentini">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Patentini e certificati</h2>
                        <Link href="/patentini" class="text-xs font-medium text-green-800 hover:underline">Vai ai patentini →</Link>
                    </div>
                    <div class="px-4 py-3">
                        <p v-if="data.certificates.expired_count || data.certificates.due_soon_count" class="mb-2 text-xs font-medium">
                            <span v-if="data.certificates.expired_count" class="text-red-700">{{ data.certificates.expired_count }} {{ data.certificates.expired_count === 1 ? 'scaduto' : 'scaduti' }}</span>
                            <span v-if="data.certificates.expired_count && data.certificates.due_soon_count" class="text-gray-400"> · </span>
                            <span v-if="data.certificates.due_soon_count" class="text-gray-600">{{ data.certificates.due_soon_count }} in scadenza entro 60 giorni</span>
                        </p>
                        <table v-if="data.certificates.rows.length" class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="cert in data.certificates.rows" :key="cert.id">
                                    <td class="py-1.5 pr-2 font-medium">{{ cert.holder_name }}</td>
                                    <td class="py-1.5 pr-2">{{ cert.title }}</td>
                                    <td class="py-1.5 text-right" :class="cert.state === 'expired' ? 'text-red-700' : 'text-gray-600'">
                                        <template v-if="cert.state === 'expired'">scaduto il {{ formatDate(cert.expires_on) }}</template>
                                        <template v-else>scade il {{ formatDate(cert.expires_on) }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-400">Nessun patentino o certificato scaduto o in scadenza entro 60 giorni.</p>
                    </div>
                </section>

                <!-- Irrigazione (solo per chi può aprire la pagina dedicata) -->
                <section v-if="data.irrigation" class="rounded-xl border border-gray-200 bg-white" data-test="oggi-irrigazione">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h2 class="text-sm font-semibold">Stagione irrigua</h2>
                        <Link href="/irrigazione" class="text-xs font-medium text-green-800 hover:underline">Vai all'irrigazione →</Link>
                    </div>
                    <div class="px-4 py-3">
                        <table v-if="data.irrigation.rows.length" class="w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="row in data.irrigation.rows" :key="row.id + row.action">
                                    <td class="py-1.5 pr-2 font-medium">{{ row.name }}</td>
                                    <td class="py-1.5 pr-2 text-gray-600">{{ row.area ?? '—' }}</td>
                                    <td class="py-1.5 text-right text-gray-600">
                                        {{ row.action === 'winterize' ? 'da invernare' : 'da riaprire' }} entro il {{ formatDate(row.on_date) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-gray-400">Nessun impianto da aprire o invernare nei prossimi 7 giorni.</p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
