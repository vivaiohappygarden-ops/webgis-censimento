<script setup>
import { onMounted, reactive, ref } from 'vue';
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

// Le richieste inviate dal cliente e il modulo per farne una nuova
const requests = ref([]);
const form = reactive({ description: '', area_id: '', severity: 'medium', busy: false, error: '', sent: false });
const photoInput = ref(null);

async function load() {
    try {
        const response = await axios.get('/api/v1/portal/overview');
        data.value = response.data;
        loadError.value = false;
        if (response.data?.linked) {
            await loadRequests();
        }
    } catch {
        loadError.value = true;
    }
}

async function loadRequests() {
    try {
        const { data: r } = await axios.get('/api/v1/portal/requests');
        requests.value = r.data;
    } catch {
        // La panoramica resta utilizzabile anche se l'elenco richieste non arriva
    }
}

async function submitRequest() {
    form.busy = true;
    form.error = '';
    form.sent = false;
    try {
        const body = new FormData();
        body.append('description', form.description.trim());
        if (form.area_id) body.append('area_id', form.area_id);
        body.append('severity', form.severity);
        const files = [...(photoInput.value?.files ?? [])];
        if (files.length > 3) {
            form.error = 'Puoi allegare al massimo 3 foto.';
            form.busy = false;
            return;
        }
        files.forEach((f) => body.append('photos[]', f));
        await axios.post('/api/v1/portal/requests', body);
        form.description = '';
        form.area_id = '';
        form.severity = 'medium';
        if (photoInput.value) photoInput.value.value = '';
        form.sent = true;
        await loadRequests();
    } catch (err) {
        form.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Invio non riuscito: riprova.';
    } finally {
        form.busy = false;
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

                <h2 class="mb-2 text-sm font-semibold">Invia una richiesta</h2>
                <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4" data-test="portal-request-form">
                    <p class="mb-3 text-xs text-gray-500">
                        Hai notato un problema sul tuo verde? Descrivilo qui, con qualche foto se puoi:
                        arriva subito a chi se ne occupa e qui sotto ne segui lo stato.
                    </p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="block text-xs md:col-span-2">
                            <span class="text-gray-500">Descrizione del problema *</span>
                            <textarea v-model="form.description" rows="3" maxlength="2000" data-test="req-description" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm" placeholder="es. Ramo spezzato pericolante sul vialetto d'ingresso" />
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Area interessata</span>
                            <select v-model="form.area_id" data-test="req-area" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                <option value="">Non so / non specificata</option>
                                <option v-for="a in data.areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Quanto è urgente secondo te?</span>
                            <select v-model="form.severity" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                                <option value="low">Poco: quando capita</option>
                                <option value="medium">Normale</option>
                                <option value="high">Molto: c'è un pericolo</option>
                            </select>
                        </label>
                        <label class="block text-xs md:col-span-2">
                            <span class="text-gray-500">Foto (fino a 3, massimo 8 MB l'una)</span>
                            <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" multiple data-test="req-photos" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                    </div>
                    <p v-if="form.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="req-error">{{ form.error }}</p>
                    <p v-if="form.sent" class="mt-3 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-900" data-test="req-sent">Richiesta inviata: la trovi qui sotto e ti aggiorneremo sullo stato.</p>
                    <button
                        class="mt-3 rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="form.busy || ! form.description.trim()"
                        data-test="req-send"
                        @click="submitRequest"
                    >{{ form.busy ? 'Invio…' : 'Invia la richiesta' }}</button>
                </div>

                <h2 class="mb-2 text-sm font-semibold">Le tue richieste</h2>
                <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
                    <table class="w-full text-sm" data-test="portal-requests">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-4 py-2.5 font-medium">Codice</th>
                                <th class="px-4 py-2.5 font-medium">Inviata il</th>
                                <th class="px-4 py-2.5 font-medium">Stato</th>
                                <th class="px-4 py-2.5 font-medium">Descrizione</th>
                                <th class="px-4 py-2.5 font-medium">Foto</th>
                                <th class="px-4 py-2.5 font-medium">Esito</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="r in requests" :key="r.id" data-test="req-row">
                                <td class="px-4 py-2 font-medium">{{ r.code }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ fmtDate(r.created_at) }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUS[r.status]?.cls">
                                        {{ STATUS[r.status]?.label ?? r.status }}
                                    </span>
                                </td>
                                <td class="max-w-64 truncate px-4 py-2" :title="r.description">{{ r.description }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ r.photos_count || '—' }}</td>
                                <td class="max-w-48 truncate px-4 py-2 text-gray-600" :title="r.resolution_notes ?? ''">{{ r.resolution_notes ?? '—' }}</td>
                            </tr>
                            <tr v-if="! requests.length">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-400">Nessuna richiesta inviata finora.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2 class="mb-2 text-sm font-semibold">Ultimi lavori completati</h2>
                <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
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
                <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
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
                <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
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
