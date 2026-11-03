<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type MonitoringRow = { tps: string; area: string; votes: Array<number | null>; status: string; tone: string; updated: string };
type Candidate = { id: number; label: string; initials: string };

const props = defineProps<{
    event: { id: number; name: string; year: number; status: string; scope: { id: number; name: string; level: string } } | null;
    eventOptions: Array<{ id: number; name: string; year: number; status: string }>;
    contestOptions: Array<{ id: number; name: string; status: string }>;
    selectedContestId: number | null;
    demoPreview?: { monitoring: MonitoringRow[]; candidates: Candidate[]; stats: { total: number; submitted: number; verified: number; review: number; revision: number; unreported: number } } | null;
}>();

const query = ref('');
const selectedStatus = ref('all');
const rows = computed(() => props.demoPreview?.monitoring ?? []);
const candidates = computed(() => props.demoPreview?.candidates ?? []);
const statuses = computed(() => Array.from(new Set(rows.value.map((row) => row.status))));
const filteredRows = computed(() => {
    const needle = query.value.toLocaleLowerCase('id-ID').trim();
    return rows.value.filter((row) => (selectedStatus.value === 'all' || row.status === selectedStatus.value)
        && (!needle || `${row.tps} ${row.area}`.toLocaleLowerCase('id-ID').includes(needle)));
});
const statusCount = (status: string) => rows.value.filter((row) => row.status === status).length;
const formatNumber = (value: number | null) => value === null ? '—' : new Intl.NumberFormat('id-ID').format(value);
const statusClass = (tone: string) => ({
    emerald: 'border-emerald-400/20 bg-emerald-400/10 text-emerald-300', blue: 'border-blue-400/20 bg-blue-400/10 text-blue-300',
    amber: 'border-amber-400/20 bg-amber-400/10 text-amber-300', orange: 'border-orange-400/20 bg-orange-400/10 text-orange-300',
    rose: 'border-rose-400/20 bg-rose-400/10 text-rose-300', slate: 'border-slate-400/20 bg-slate-400/10 text-slate-300',
}[tone] ?? 'border-blue-400/20 bg-blue-400/10 text-blue-300');
const navigate = (parameters: Record<string, number | undefined>) => router.get(route('monitoring.index'), parameters, { preserveScroll: true });
const switchEvent = (event: Event) => navigate({ event: Number((event.target as HTMLSelectElement).value) });
const switchContest = (event: Event) => navigate({ event: props.event?.id, contest: Number((event.target as HTMLSelectElement).value) });
</script>

<template>
    <Head title="Monitoring TPS" />
    <AuthenticatedLayout>
        <div class="space-y-5">
            <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div><p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Hasil & monitoring</p><h1 class="mt-1 text-2xl font-bold text-white">Monitoring TPS</h1><p class="mt-1 text-sm text-slate-400">Pantau kelengkapan laporan dan status verifikasi setiap TPS.</p></div>
                <div class="grid gap-3 sm:grid-cols-2 xl:w-[620px]">
                    <label class="text-xs text-slate-400">Event<select class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/70 px-3 py-2.5 text-sm text-white" :value="event?.id" @change="switchEvent"><option v-for="option in eventOptions" :key="option.id" :value="option.id">{{ option.name }} · {{ option.year }}</option></select></label>
                    <label class="text-xs text-slate-400">Kontes<select class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/70 px-3 py-2.5 text-sm text-white" :value="selectedContestId" :disabled="!contestOptions.length" @change="switchContest"><option v-for="option in contestOptions" :key="option.id" :value="option.id">{{ option.name }}</option></select></label>
                </div>
            </header>

            <template v-if="event && demoPreview">
                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="item in [
                        { label: 'Total TPS', value: rows.length, tone: 'text-blue-300' },
                        { label: 'Belum Melapor', value: statusCount('Belum Melapor'), tone: 'text-rose-300' },
                        { label: 'Menunggu Proses', value: rows.filter((row) => ['Menunggu Review', 'Menunggu Approval', 'Draft'].includes(row.status)).length, tone: 'text-amber-300' },
                        { label: 'Terverifikasi', value: statusCount('Terverifikasi'), tone: 'text-emerald-300' },
                    ]" :key="item.label" class="glass-panel rounded-2xl p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ item.label }}</p><p class="mt-2 text-3xl font-bold" :class="item.tone">{{ formatNumber(item.value) }}</p></article>
                </section>

                <section class="glass-panel overflow-hidden rounded-2xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 p-4 lg:flex-row lg:items-end lg:justify-between">
                        <div><h2 class="font-bold text-white">Daftar TPS</h2><p class="mt-1 text-xs text-slate-500">{{ event.scope.name }} · {{ filteredRows.length }} dari {{ rows.length }} TPS</p></div>
                        <div class="grid gap-2 sm:grid-cols-[minmax(230px,1fr)_190px]">
                            <label class="text-[10px] text-slate-500">Cari TPS atau wilayah<input v-model="query" placeholder="Contoh: TPS 001 atau Sentani" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs text-white placeholder:text-slate-600"></label>
                            <label class="text-[10px] text-slate-500">Status<select v-model="selectedStatus" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs text-white"><option value="all">Semua status</option><option v-for="status in statuses" :key="status" :value="status">{{ status }}</option></select></label>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[820px] text-left text-xs">
                            <thead class="bg-white/[.03] text-slate-500"><tr><th class="px-5 py-3">TPS</th><th class="px-4 py-3">Wilayah</th><th v-for="candidate in candidates" :key="candidate.id" class="px-3 py-3 text-right">{{ candidate.initials }}</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Update</th><th class="px-5 py-3 text-right">Aksi</th></tr></thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="row in filteredRows" :key="row.tps" class="hover:bg-white/[.025]"><td class="px-5 py-3 font-bold text-white">{{ row.tps }}</td><td class="px-4 py-3 text-slate-400">{{ row.area }}</td><td v-for="(vote, index) in row.votes" :key="index" class="px-3 py-3 text-right font-mono text-slate-300">{{ formatNumber(vote) }}</td><td class="px-4 py-3"><span class="rounded-full border px-2.5 py-1 text-[10px] font-semibold" :class="statusClass(row.tone)">{{ row.status }}</span></td><td class="px-4 py-3 text-slate-500">{{ row.updated }}</td><td class="px-5 py-3 text-right"><Link :href="route('reports.index', { event: event.id })" class="font-semibold text-blue-300 hover:text-blue-200">Lihat laporan →</Link></td></tr>
                                <tr v-if="!filteredRows.length"><td :colspan="5 + candidates.length" class="px-5 py-14 text-center text-slate-500">Tidak ada TPS yang cocok dengan filter.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
            <section v-else class="glass-panel rounded-2xl p-12 text-center"><h2 class="font-bold text-white">Monitoring belum tersedia</h2><p class="mt-2 text-sm text-slate-500">Buat event, kontes, dan TPS terlebih dahulu.</p></section>
        </div>
    </AuthenticatedLayout>
</template>
