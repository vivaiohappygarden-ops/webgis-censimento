<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const mainTypes = ref([]);
const total = ref(0);
const search = ref('');
const open = ref({});

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
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
