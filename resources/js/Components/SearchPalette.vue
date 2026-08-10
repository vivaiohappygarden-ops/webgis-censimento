<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const open = ref(false);
const query = ref('');
const results = ref(null);
const loading = ref(false);
const inputEl = ref(null);
let debounce = null;

function toggle(state) {
    open.value = state ?? ! open.value;
    if (open.value) {
        query.value = '';
        results.value = null;
        nextTick(() => inputEl.value?.focus());
    }
}

function onKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        toggle();
    } else if (e.key === 'Escape' && open.value) {
        toggle(false);
    }
}

watch(query, (q) => {
    clearTimeout(debounce);
    if (q.trim().length < 2) {
        results.value = null;

        return;
    }
    debounce = setTimeout(async () => {
        loading.value = true;
        try {
            const { data } = await axios.get('/api/v1/search', { params: { q } });
            results.value = data.data;
        } finally {
            loading.value = false;
        }
    }, 250);
});

function go(href) {
    toggle(false);
    router.visit(href);
}

const isEmpty = (r) =>
    r && ! r.tag_match && ! r.assets.length && ! r.areas.length && ! r.localities.length && ! r.catalog_types.length;

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

defineExpose({ toggle });
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 bg-black/30 p-4 pt-24" @click.self="toggle(false)">
            <div class="mx-auto w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4">
                    <span class="text-gray-400">🔎</span>
                    <input
                        ref="inputEl"
                        v-model="query"
                        type="search"
                        placeholder="Cerca codice, albero, area, tag, tipo… (Esc per chiudere)"
                        class="w-full border-0 py-3 text-sm focus:outline-none focus:ring-0"
                    >
                </div>

                <div class="max-h-96 overflow-y-auto p-2 text-sm">
                    <p v-if="loading" class="px-3 py-4 text-gray-400">Ricerca…</p>
                    <p v-else-if="isEmpty(results)" class="px-3 py-4 text-gray-400">Nessun risultato per "{{ query }}".</p>

                    <template v-if="results">
                        <button
                            v-if="results.tag_match"
                            class="flex w-full items-center gap-2 rounded-lg bg-blue-50 px-3 py-2 text-left hover:bg-blue-100"
                            @click="go(`/censimento/${results.tag_match.asset.id}`)"
                        >
                            <span>🏷️</span>
                            <span class="font-medium">Tag {{ results.tag_match.tag_uid }}</span>
                            <span class="text-gray-500">→ {{ results.tag_match.asset.census_code || results.tag_match.asset.type_name }}</span>
                        </button>

                        <template v-if="results.assets.length">
                            <div class="px-3 pb-1 pt-3 text-xs font-semibold uppercase text-gray-400">Elementi censiti</div>
                            <button
                                v-for="a in results.assets"
                                :key="a.id"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left hover:bg-green-50"
                                @click="go(`/censimento/${a.id}`)"
                            >
                                <span>🌳</span>
                                <span class="font-medium">{{ a.census_code || '—' }}</span>
                                <span class="truncate text-gray-500">{{ a.type_name }}</span>
                            </button>
                        </template>

                        <template v-if="results.areas.length">
                            <div class="px-3 pb-1 pt-3 text-xs font-semibold uppercase text-gray-400">Aree</div>
                            <button
                                v-for="a in results.areas"
                                :key="a.id"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left hover:bg-green-50"
                                @click="go('/mappa')"
                            >
                                <span>📐</span>
                                <span class="font-medium">{{ a.name }}</span>
                                <span class="text-gray-400">{{ a.code }}</span>
                            </button>
                        </template>

                        <template v-if="results.localities.length">
                            <div class="px-3 pb-1 pt-3 text-xs font-semibold uppercase text-gray-400">Località</div>
                            <button
                                v-for="l in results.localities"
                                :key="l.id"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left hover:bg-green-50"
                                @click="go('/territorio')"
                            >
                                <span>📍</span>
                                <span class="font-medium">{{ l.name }}</span>
                                <span class="text-gray-400">{{ l.site_name }}</span>
                            </button>
                        </template>

                        <template v-if="results.catalog_types.length">
                            <div class="px-3 pb-1 pt-3 text-xs font-semibold uppercase text-gray-400">Catalogo</div>
                            <button
                                v-for="t in results.catalog_types"
                                :key="t.id"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left hover:bg-green-50"
                                @click="go('/catalogo')"
                            >
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs">{{ t.code }}</code>
                                <span class="truncate">{{ t.name }}</span>
                            </button>
                        </template>
                    </template>

                    <p v-if="! results && ! loading" class="px-3 py-4 text-gray-400">
                        Digita almeno 2 caratteri: codici censimento, tag NFC/QR, aree, località, tipi di catalogo.
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
