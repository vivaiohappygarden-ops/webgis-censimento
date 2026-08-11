<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    teams: { type: Array, default: () => [] },
    personnel: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['open']);

const STATUS_LABELS = {
    draft: 'Bozza', planned: 'Pianificato', assigned: 'Assegnato',
    in_progress: 'In corso', suspended: 'Sospeso', completed: 'Completato', cancelled: 'Annullato',
};
const CHIP_COLORS = {
    draft: 'border-gray-300 bg-gray-50 text-gray-700',
    planned: 'border-blue-300 bg-blue-50 text-blue-900',
    assigned: 'border-indigo-300 bg-indigo-50 text-indigo-900',
    in_progress: 'border-amber-300 bg-amber-50 text-amber-900',
    suspended: 'border-orange-300 bg-orange-50 text-orange-900',
    completed: 'border-green-300 bg-green-50 text-green-900',
    cancelled: 'border-gray-200 bg-gray-100 text-gray-500',
};

// Date in locale (mai toISOString: a cavallo della mezzanotte cambierebbe giorno)
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
function mondayOf(date) {
    const d = new Date(date);
    d.setHours(12, 0, 0, 0);
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    return d;
}
function addDays(date, n) {
    const d = new Date(date);
    d.setDate(d.getDate() + n);
    return d;
}

const weekStart = ref(mondayOf(new Date()));
const todayYmd = ymd(new Date());
const rows = ref([]);
const unplanned = ref([]);
const loading = ref(false);

const days = computed(() => Array.from({ length: 7 }, (_, i) => {
    const date = addDays(weekStart.value, i);
    return {
        ymd: ymd(date),
        weekend: date.getDay() === 0 || date.getDay() === 6,
        label: date.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: '2-digit' }),
    };
}));

const weekLabel = computed(() => {
    const start = weekStart.value;
    const end = addDays(start, 6);
    const fmt = (d, opts) => d.toLocaleDateString('it-IT', opts);
    return `${fmt(start, { day: 'numeric', month: 'long' })} – ${fmt(end, { day: 'numeric', month: 'long', year: 'numeric' })}`;
});

async function reload() {
    loading.value = true;
    try {
        const [win, unp] = await Promise.all([
            axios.get('/api/v1/work-orders', {
                params: { from: days.value[0].ymd, to: days.value[6].ymd, per_page: 100 },
            }),
            axios.get('/api/v1/work-orders', { params: { unplanned: 1, per_page: 100 } }),
        ]);
        rows.value = win.data.data;
        unplanned.value = unp.data.data;
    } finally {
        loading.value = false;
    }
}
defineExpose({ reload });

// Una corsia per squadra, più una per gli ordini del periodo senza squadra
const lanes = computed(() => {
    const teamLanes = props.teams.map((t) => ({
        key: t.id,
        name: t.name,
        orders: rows.value.filter((o) => o.team_id === t.id),
    }));
    const rest = rows.value.filter((o) => ! props.teams.some((t) => t.id === o.team_id));
    if (rest.length) {
        teamLanes.push({ key: 'none', name: 'Senza squadra', orders: rest });
    }
    return teamLanes;
});

function ordersOn(lane, dayYmd) {
    return lane.orders.filter((o) => {
        const start = (o.planned_start ?? '').slice(0, 10);
        const end = (o.planned_end ?? o.planned_start ?? '').slice(0, 10);
        return start !== '' && start <= dayYmd && end >= dayYmd;
    });
}

function moveWeek(n) {
    weekStart.value = addDays(weekStart.value, n * 7);
    reload();
}
function goToday() {
    weekStart.value = mondayOf(new Date());
    reload();
}

// ---- Pianificazione (date, squadra, responsabile) ----
const planner = reactive({
    open: false, busy: false, error: '', order: null,
    form: { planned_start: '', planned_end: '', team_id: '', assigned_to: '' },
});

function openPlanner(order) {
    Object.assign(planner, { open: true, busy: false, error: '', order });
    planner.form = {
        planned_start: (order.planned_start ?? '').slice(0, 10) || todayYmd,
        planned_end: (order.planned_end ?? '').slice(0, 10),
        team_id: order.team_id ?? '',
        assigned_to: order.assigned_to ?? '',
    };
}

async function savePlan() {
    planner.busy = true;
    planner.error = '';
    try {
        await axios.patch(`/api/v1/work-orders/${planner.order.id}`, {
            planned_start: planner.form.planned_start || null,
            planned_end: planner.form.planned_end || null,
            team_id: planner.form.team_id || null,
            assigned_to: planner.form.assigned_to || null,
            version: planner.order.version,
        });
        planner.open = false;
        await reload();
    } catch (err) {
        planner.error = Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.response?.data?.message ?? 'Errore nel salvataggio della pianificazione';
    } finally {
        planner.busy = false;
    }
}

onMounted(reload);
</script>

<template>
    <div>
        <!-- Navigazione settimanale -->
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" data-test="agenda-prev" @click="moveWeek(-1)">←</button>
            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" data-test="agenda-today" @click="goToday">Oggi</button>
            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" data-test="agenda-next" @click="moveWeek(1)">→</button>
            <span class="ml-2 text-sm font-medium" data-test="agenda-week">{{ weekLabel }}</span>
            <span v-if="loading" class="text-xs text-gray-400">Caricamento…</span>
        </div>

        <!-- Griglia squadre x giorni -->
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full table-fixed text-sm" data-test="agenda-grid">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="w-36 px-3 py-2.5 font-medium">Squadra</th>
                        <th
                            v-for="d in days"
                            :key="d.ymd"
                            class="border-l border-gray-50 px-2 py-2.5 text-center font-medium"
                            :class="[d.weekend ? 'bg-gray-50' : '', d.ymd === todayYmd ? 'text-green-800' : '']"
                        >
                            {{ d.label }}
                            <div v-if="d.ymd === todayYmd" class="mx-auto mt-0.5 h-0.5 w-8 bg-green-700" />
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 align-top">
                    <tr v-for="lane in lanes" :key="lane.key">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ lane.name }}</div>
                            <div class="text-xs text-gray-400">{{ lane.orders.length }} {{ lane.orders.length === 1 ? 'ordine' : 'ordini' }} in settimana</div>
                        </td>
                        <td
                            v-for="d in days"
                            :key="d.ymd"
                            class="border-l border-gray-50 px-1.5 py-1.5"
                            :class="d.weekend ? 'bg-gray-50/60' : ''"
                        >
                            <button
                                v-for="o in ordersOn(lane, d.ymd)"
                                :key="o.id"
                                class="mb-1 block w-full truncate rounded border-l-4 px-1.5 py-1 text-left text-[11px] leading-tight"
                                :class="CHIP_COLORS[o.status]"
                                :title="`${o.code} — ${o.title} (${STATUS_LABELS[o.status]})`"
                                data-test="agenda-chip"
                                @click="emit('open', o.id)"
                            >
                                <span class="font-semibold">{{ o.code.replace(/^ODL-\d+-/, 'n. ') }}</span>
                                {{ o.title }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="! lanes.length">
                        <td :colspan="8" class="px-4 py-8 text-center text-gray-400">
                            Nessuna squadra e nessun ordine nel periodo.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Da pianificare -->
        <div class="mt-4 rounded-xl border border-gray-200 bg-white">
            <h2 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold">
                Da pianificare ({{ unplanned.length }})
            </h2>
            <ul class="divide-y divide-gray-50" data-test="agenda-unplanned">
                <li v-for="o in unplanned" :key="o.id" class="flex items-center gap-3 px-4 py-2 text-sm">
                    <button class="min-w-0 flex-1 truncate text-left hover:underline" @click="emit('open', o.id)">
                        <span class="font-medium">{{ o.code }}</span>
                        <span class="ml-2 text-gray-600">{{ o.title }}</span>
                    </button>
                    <span class="text-xs text-gray-500">{{ STATUS_LABELS[o.status] }}</span>
                    <span class="text-xs text-gray-400">{{ o.team?.name ?? 'senza squadra' }}</span>
                    <button
                        v-if="canManage"
                        class="rounded-lg border border-green-700 px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50"
                        data-test="agenda-plan"
                        @click="openPlanner(o)"
                    >Pianifica</button>
                </li>
                <li v-if="! unplanned.length" class="px-4 py-4 text-sm text-gray-400">
                    Nessun ordine in attesa di pianificazione.
                </li>
            </ul>
        </div>

        <!-- Modale di pianificazione -->
        <Teleport to="body">
            <div v-if="planner.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="planner.open = false">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" data-test="agenda-planner">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-400">{{ planner.order.code }}</div>
                            <h2 class="font-semibold">Pianifica il lavoro</h2>
                        </div>
                        <button class="text-gray-400 hover:text-gray-600" @click="planner.open = false">✕</button>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <label class="block text-xs">
                            <span class="text-gray-500">Inizio previsto *</span>
                            <input v-model="planner.form.planned_start" type="date" data-test="plan-start" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Fine prevista</span>
                            <input v-model="planner.form.planned_end" type="date" data-test="plan-end" class="mt-1 w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm">
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Squadra</span>
                            <select v-model="planner.form.team_id" data-test="plan-team" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                <option value="">—</option>
                                <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <label class="block text-xs">
                            <span class="text-gray-500">Responsabile</span>
                            <select v-model="planner.form.assigned_to" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm">
                                <option value="">—</option>
                                <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </label>
                    </div>

                    <p v-if="planner.error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-test="plan-error">{{ planner.error }}</p>

                    <button
                        class="mt-4 w-full rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="planner.busy || ! planner.form.planned_start"
                        data-test="plan-save"
                        @click="savePlan"
                    >{{ planner.busy ? 'Salvataggio…' : 'Salva la pianificazione' }}</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
