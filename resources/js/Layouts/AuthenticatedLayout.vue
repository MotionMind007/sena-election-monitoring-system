<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const menuOpen = ref(false);
const user = computed(() => page.props.auth.user);
interface CurrentEvent {
    id: number;
    name: string;
    year: number;
}

const currentEvent = computed<CurrentEvent | null>(() => (page.props.event ?? page.props.currentEvent ?? null) as CurrentEvent | null);
const dashboardHref = computed(() => currentEvent.value?.id
    ? route('dashboard', { event: currentEvent.value.id })
    : route('dashboard'));
const mapHref = computed(() => currentEvent.value?.id
    ? route('map.index', { event: currentEvent.value.id })
    : route('map.index'));
const roleLabel = computed(() => ({ super_admin: 'Super Admin', coordinator: 'Koordinator', witness: 'Saksi' }[user.value.role]));

const primaryItems = [
    { label: 'Input Laporan', symbol: '+', roles: ['super_admin', 'coordinator', 'witness'], routeName: 'reports.index' },
    { label: 'Dashboard', symbol: '⌂', roles: ['super_admin', 'coordinator', 'witness'] },
    { label: 'Peta Wilayah', symbol: '◇', roles: ['super_admin', 'coordinator'] },
    { label: 'Monitoring TPS', symbol: '◎', roles: ['super_admin', 'coordinator'], routeName: 'monitoring.index' },
    { label: 'Approval Center', symbol: '✓', roles: ['super_admin'] },
].map((item) => item.label === 'Approval Center' ? { ...item, roles: ['super_admin', 'coordinator'], routeName: 'verification.index' } : item);

const adminItems = [
    { label: 'Event Pemilihan', symbol: '□', routeName: 'admin.events.index' },
    { label: 'Wilayah & TPS', symbol: '⌖', routeName: 'admin.geography.index' },
    { label: 'Penugasan', symbol: '♙', routeName: 'admin.assignments.index' },
    { label: 'Operasional', symbol: '!', routeName: 'admin.operations.index' },
    { label: 'Audit Log', symbol: '≡', routeName: 'admin.audit.index' },
];
</script>

<template>
    <div class="min-h-screen bg-transparent text-slate-100">
        <div v-if="menuOpen" class="fixed inset-0 z-30 bg-slate-950/70 backdrop-blur-sm lg:hidden" @click="menuOpen = false" />

        <aside :class="menuOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-white/10 bg-[#061326]/95 px-4 py-5 shadow-2xl backdrop-blur-xl transition-transform lg:translate-x-0">
            <Link :href="dashboardHref" class="focus-ring flex items-center gap-3 rounded-xl px-2">
                <ApplicationLogo class="h-10 w-10" />
                <div><p class="text-lg font-bold tracking-[.08em] text-white">SENA</p><p class="text-[11px] text-slate-400">Real Count Internal</p></div>
            </Link>

            <nav class="mt-9 flex-1 overflow-y-auto">
                <p class="px-3 text-[10px] font-semibold uppercase tracking-[.18em] text-slate-600">Hasil & monitoring</p>
                <div class="mt-2 space-y-1">
                    <template v-for="item in primaryItems" :key="item.label">
                        <Link v-if="item.roles.includes(user.role)" :href="item.label === 'Dashboard' ? dashboardHref : item.label === 'Peta Wilayah' ? mapHref : item.routeName ? route(item.routeName, currentEvent?.id ? { event: currentEvent.id } : {}) : '#'" :class="(item.label === 'Dashboard' && route().current('dashboard')) || (item.label === 'Peta Wilayah' && route().current('map.index')) || (item.routeName && route().current(item.routeName)) ? 'border-blue-400/40 bg-blue-600/25 text-white shadow-[inset_3px_0_0_#3190ff]' : 'border-transparent text-slate-400 hover:bg-white/5 hover:text-white'" class="focus-ring flex items-center gap-3 rounded-lg border px-3 py-2.5 text-sm font-medium">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 text-blue-300">{{ item.symbol }}</span>{{ item.label }}
                        </Link>
                    </template>
                </div>

                <template v-if="user.role === 'super_admin'">
                    <p class="mt-8 px-3 text-[10px] font-semibold uppercase tracking-[.18em] text-slate-600">Data & operasional</p>
                    <div class="mt-2 space-y-1">
                        <Link v-for="item in adminItems" :key="item.label" :href="item.routeName ? route(item.routeName) : '#'" :class="item.routeName && route().current(item.routeName) ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'" class="focus-ring flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 text-slate-400">{{ item.symbol }}</span>{{ item.label }}
                        </Link>
                    </div>
                </template>
            </nav>

            <div class="glass-panel mt-4 rounded-xl p-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-cyan-400 text-sm font-bold">{{ user.name.charAt(0) }}</div>
                    <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-white">{{ user.name }}</p><p class="text-xs text-slate-400">{{ roleLabel }}</p></div>
                    <Link :href="route('logout')" method="post" as="button" class="focus-ring rounded-lg p-2 text-slate-500 hover:bg-white/5 hover:text-white" aria-label="Keluar">↗</Link>
                </div>
            </div>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 border-b border-white/10 bg-[#071426]/80 px-4 py-3 backdrop-blur-xl sm:px-6 lg:px-7">
                <div class="flex items-center gap-3">
                    <button class="focus-ring rounded-lg p-2 text-slate-300 lg:hidden" aria-label="Buka navigasi" @click="menuOpen = true">☰</button>
                    <div class="relative hidden max-w-xl flex-1 md:block">
                        <span class="absolute left-3 top-2.5 text-slate-500">⌕</span>
                        <input disabled placeholder="Cari TPS, wilayah, atau laporan…" class="w-full rounded-xl border border-white/10 bg-white/[.035] py-2.5 pl-9 pr-4 text-sm text-slate-300 placeholder:text-slate-600" />
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <div class="hidden rounded-xl border border-white/10 bg-white/[.035] px-4 py-2 sm:block">
                            <p class="text-[10px] uppercase tracking-wider text-slate-500">Event aktif</p><p class="max-w-48 truncate text-xs font-semibold text-slate-300">{{ currentEvent ? `${currentEvent.name} · ${currentEvent.year}` : 'Belum dikonfigurasi' }}</p>
                        </div>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-white/[.035] text-slate-400" aria-label="Notifikasi">♢</span>
                    </div>
                </div>
            </header>

            <main class="p-4 sm:p-6 lg:p-7"><slot /></main>
        </div>
    </div>
</template>
