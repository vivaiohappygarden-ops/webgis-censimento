<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const permissions = computed(() => user.value?.permissions ?? []);

const can = (permission) => permissions.value.includes(permission);

const nav = computed(() =>
    [
        { label: 'Mappa', href: '/mappa', icon: '🗺️', show: can('assets.view') },
        { label: 'Censimento', href: '/censimento', icon: '🌳', show: can('assets.view') },
        { label: 'Catalogo', href: '/catalogo', icon: '📚', show: can('catalog.view') },
    ].filter((item) => item.show)
);

const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`);

const logout = () => router.post('/logout');
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <aside class="flex w-56 shrink-0 flex-col border-r border-gray-200 bg-white">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-4">
                <span class="text-2xl">🌳</span>
                <div>
                    <div class="text-sm font-semibold leading-tight">WebGIS Censimento</div>
                    <div class="text-xs text-gray-500">{{ user?.organization?.name }}</div>
                </div>
            </div>

            <nav class="flex-1 space-y-1 p-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
                    :class="isActive(item.href)
                        ? 'bg-green-50 text-green-800'
                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
                >
                    <span>{{ item.icon }}</span>
                    <span>{{ item.label }}</span>
                </Link>
            </nav>

            <div class="border-t border-gray-100 p-3">
                <div class="px-2 pb-2">
                    <div class="truncate text-sm font-medium">{{ user?.name }}</div>
                    <div class="truncate text-xs text-gray-500">{{ user?.email }}</div>
                </div>
                <button
                    class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-600 transition hover:bg-red-50 hover:text-red-700"
                    @click="logout"
                >
                    Esci
                </button>
            </div>
        </aside>

        <main class="flex-1 overflow-y-auto">
            <slot />
        </main>
    </div>
</template>
