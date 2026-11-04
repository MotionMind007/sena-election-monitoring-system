<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MapLoading from '@/Components/MapLoading.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref } from 'vue';

const PapuaRegionMap = defineAsyncComponent({
    loader: () => import('@/Components/PapuaRegionMap.vue'),
    loadingComponent: MapLoading,
    delay: 100,
});

interface DashboardStats {
    total: number;
    submitted: number;
    verified: number;
    review: number;
    revision: number;
    unreported: number;
    assigned: number;
}

interface DemoPreview {
    updated_at: string | null;
    stats: Omit<DashboardStats, 'assigned'>;
    regions: Array<{ id: number; name: string; level: string; total: number; submitted: number; verified: number }>;
    candidates: Array<{ id: number; label: string; initials: string; photo_url: string | null; votes: number; direct_party_votes: number; candidate_votes: number; percentage: number; tone: string }>;
    result_mode: 'party' | 'candidate';
    legislative_candidates: Array<{ id: number; name: string; number: string; photo_url: string | null; party: string; dapil_id: number | null; dapil: string; votes: number; percentage: number }>;
    votes: { valid: number; invalid: number; total: number };
    trend: Array<{ label: string; tone: string; values: number[] }>;
    activities: Array<{ title: string; detail: string; time: string; tone: string }>;
    monitoring: Array<{ tps: string; area: string; votes: Array<number | null>; status: string; tone: string; updated: string }>;
}

interface RegionFilters {
    root: { id: number; name: string; level: string } | null;
    selected: { regency_id: number | null; district_id: number | null; village_id: number | null };
    regencies: Array<{ id: number; name: string; level: string }>;
    districts: Array<{ id: number; name: string; level: string }>;
    villages: Array<{ id: number; name: string; level: string }>;
}

const props = defineProps<{
    event: { id: number; name: string; year: number; status: string; contest?: string | null; scope: { id: number; name: string; level: string } } | null;
    eventOptions: Array<{ id: number; name: string; year: number; status: string }>;
    contestOptions: Array<{ id: number; name: string; status: string }>;
    selectedContestId: number | null;
    breadcrumbs: Array<{ id: number; name: string; level: string }>;
    regionFilters: RegionFilters;
    stats: DashboardStats;
    coverage: Array<{ name: string; total: number; assigned: number }>;
    monitoring: Array<{ code: string; village: string; location?: string | null; status: string; assigned: boolean }>;
    activities: Array<{ action: string; actor: string; time?: string | null }>;
    demoPreview?: DemoPreview | null;
}>();

const formatNumber = (value: number | null) => value === null ? '—' : new Intl.NumberFormat('id-ID').format(value);
const percentage = (value: number, total: number) => total ? (value / total) * 100 : 0;
const switchEvent = (event: Event) => {
    const eventId = Number((event.target as HTMLSelectElement).value);

    router.get(route('dashboard'), { event: eventId }, { preserveScroll: true });
};
const switchContest = (event: Event) => {
    const contest = Number((event.target as HTMLSelectElement).value);

    router.get(route('dashboard'), { event: props.event?.id, contest }, { preserveScroll: true });
};
const drillRegion = (regionId: number) => {
    router.get(route('dashboard'), { event: props.event?.id, contest: props.selectedContestId, region: regionId }, { preserveScroll: true });
};
const filterRegion = (event: Event) => drillRegion(Number((event.target as HTMLSelectElement).value));
const regencyFilterValue = computed(() => props.regionFilters.selected.regency_id ?? props.regionFilters.root?.id ?? '');
const districtFilterValue = computed(() => props.regionFilters.selected.district_id ?? props.regionFilters.selected.regency_id ?? '');
const villageFilterValue = computed(() => props.regionFilters.selected.village_id ?? props.regionFilters.selected.district_id ?? '');
const openBreadcrumb = (regionId: number) => drillRegion(regionId);
const monitoringStatus = ref('all');
const selectedDapilId = ref<number | 'all'>('all');
const monitoringStatuses = computed(() => Array.from(new Set(props.demoPreview?.monitoring.map((item) => item.status) ?? [])));
const filteredMonitoring = computed(() => props.demoPreview?.monitoring.filter((item) => monitoringStatus.value === 'all' || item.status === monitoringStatus.value) ?? []);
const dapilOptions = computed(() => {
    const candidates = props.demoPreview?.legislative_candidates ?? [];
    return Array.from(new Map(candidates.filter((candidate) => candidate.dapil_id).map((candidate) => [candidate.dapil_id, candidate.dapil])).entries())
        .map(([id, name]) => ({ id: id as number, name }))
        .sort((a, b) => a.name.localeCompare(b.name, 'id'));
});
const filteredLegislativeCandidates = computed(() => (props.demoPreview?.legislative_candidates ?? []).filter((candidate) => selectedDapilId.value === 'all' || candidate.dapil_id === selectedDapilId.value));
const polyline = (values: number[]) => values.map((value, index) => `${(index / (values.length - 1)) * 300},${112 - value}`).join(' ');
const regionColor = (value: number) => {
    if (value >= 85) return 'linear-gradient(135deg, rgba(16,185,129,.5), rgba(6,78,59,.22))';
    if (value >= 75) return 'linear-gradient(135deg, rgba(34,211,238,.4), rgba(14,116,144,.18))';
    if (value >= 65) return 'linear-gradient(135deg, rgba(250,204,21,.38), rgba(133,77,14,.18))';
    return 'linear-gradient(135deg, rgba(251,146,60,.4), rgba(154,52,18,.18))';
};

const statCards = computed(() => {
    const stats = props.demoPreview?.stats ?? props.stats;

    return [
        { label: 'Total TPS', value: stats.total, percent: 100, note: '100% dari target', tone: 'blue', icon: '▦' },
        { label: 'Laporan Masuk', value: stats.submitted, percent: percentage(stats.submitted, stats.total), note: `${percentage(stats.submitted, stats.total).toFixed(1)}%`, tone: 'cyan', icon: '▤' },
        { label: 'Terverifikasi', value: stats.verified, percent: percentage(stats.verified, stats.total), note: `${percentage(stats.verified, stats.total).toFixed(1)}%`, tone: 'teal', icon: '✓' },
        { label: 'Dalam Review', value: stats.review, percent: percentage(stats.review, stats.total), note: `${percentage(stats.review, stats.total).toFixed(1)}%`, tone: 'amber', icon: '◷' },
        { label: 'Perlu Revisi', value: stats.revision, percent: percentage(stats.revision, stats.total), note: `${percentage(stats.revision, stats.total).toFixed(1)}%`, tone: 'rose', icon: '△' },
        { label: 'Belum Melapor', value: stats.unreported, percent: percentage(stats.unreported, stats.total), note: `${percentage(stats.unreported, stats.total).toFixed(1)}%`, tone: 'slate', icon: '▥' },
    ];
});

const tones: Record<string, string> = {
    blue: 'from-blue-500/25 text-blue-300 border-blue-400/25',
    cyan: 'from-cyan-500/25 text-cyan-300 border-cyan-400/25',
    teal: 'from-emerald-500/25 text-emerald-300 border-emerald-400/25',
    amber: 'from-orange-500/25 text-orange-300 border-orange-400/25',
    rose: 'from-rose-500/25 text-rose-300 border-rose-400/25',
    slate: 'from-slate-500/25 text-slate-300 border-slate-400/25',
};

const candidateTones: Record<string, string> = {
    blue: 'from-blue-500/40 to-cyan-400/10 text-blue-300',
    orange: 'from-orange-500/40 to-amber-400/10 text-orange-300',
    violet: 'from-violet-500/40 to-fuchsia-400/10 text-violet-300',
    emerald: 'from-emerald-500/40 to-teal-400/10 text-emerald-300',
    rose: 'from-rose-500/40 to-pink-400/10 text-rose-300',
};

const statusTones: Record<string, string> = {
    emerald: 'bg-emerald-400', amber: 'bg-amber-400', blue: 'bg-blue-400', rose: 'bg-rose-400', orange: 'bg-orange-400', violet: 'bg-violet-400', slate: 'bg-slate-400',
};

const donutStyle = computed(() => {
    const stats = props.demoPreview?.stats ?? props.stats;
    const verified = percentage(stats.verified, stats.total) * 3.6;
    const review = verified + percentage(stats.review, stats.total) * 3.6;
    const revision = review + percentage(stats.revision, stats.total) * 3.6;

    return {
        background: `conic-gradient(#16d9ad 0deg ${verified}deg, #fb923c ${verified}deg ${review}deg, #f43f5e ${review}deg ${revision}deg, #cbd5e1 ${revision}deg 360deg)`,
    };
});
</script>

<template>
    <Head title="Dashboard" />
    <AuthenticatedLayout>
        <section class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Ringkasan internal · Hasil terverifikasi</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">Dashboard Real Count</h1>
                <p class="mt-1 text-sm text-slate-400">Ringkasan hasil penghitungan suara dan progres pelaporan per kontes.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Pilih event
                    <select :value="event?.id" class="mt-1 block max-w-72 rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2 text-xs normal-case text-slate-200" @change="switchEvent">
                        <option v-for="option in eventOptions" :key="option.id" :value="option.id">{{ option.name }} · {{ option.year }}</option>
                    </select>
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Pilih kontes
                    <select :value="selectedContestId ?? undefined" class="mt-1 block rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2 text-xs normal-case text-slate-200" @change="switchContest"><option v-for="option in contestOptions" :key="option.id" :value="option.id">{{ option.name }}</option></select>
                </label>
                <div class="pb-2 text-right text-[11px] text-slate-500"><p class="text-emerald-300">● Data terakhir diperbarui</p><p class="mt-1 text-slate-300">{{ demoPreview?.updated_at ?? 'Belum tersedia' }}</p></div>
            </div>
        </section>

        <section v-if="event && regionFilters.root" class="glass-panel mt-4 rounded-xl p-3">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><div><p class="text-xs font-semibold text-white">Filter wilayah</p><p class="mt-0.5 text-[10px] text-slate-500">Seluruh ringkasan dan perolehan suara mengikuti wilayah terendah yang dipilih.</p></div><span class="rounded-lg bg-blue-400/10 px-2.5 py-1 text-[10px] font-semibold text-blue-300">Scope: {{ event.scope.name }}</span></div>
            <div class="grid gap-2 md:grid-cols-3">
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Kabupaten/Kota
                    <select :value="regencyFilterValue" class="mt-1 block w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs normal-case text-slate-200" @change="filterRegion">
                        <option v-if="regionFilters.root.level === 'province'" :value="regionFilters.root.id">Semua Kabupaten/Kota</option>
                        <option v-for="region in regionFilters.regencies" :key="region.id" :value="region.id">{{ region.name }}</option>
                    </select>
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Distrik
                    <select :value="districtFilterValue" :disabled="!regionFilters.selected.regency_id" class="mt-1 block w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs normal-case text-slate-200 disabled:cursor-not-allowed disabled:opacity-40" @change="filterRegion">
                        <option v-if="regionFilters.selected.regency_id" :value="regionFilters.selected.regency_id">Semua Distrik</option>
                        <option v-for="region in regionFilters.districts" :key="region.id" :value="region.id">{{ region.name }}</option>
                    </select>
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Kelurahan/Kampung
                    <select :value="villageFilterValue" :disabled="!regionFilters.selected.district_id" class="mt-1 block w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs normal-case text-slate-200 disabled:cursor-not-allowed disabled:opacity-40" @change="filterRegion">
                        <option v-if="regionFilters.selected.district_id" :value="regionFilters.selected.district_id">Semua Kelurahan/Kampung</option>
                        <option v-for="region in regionFilters.villages" :key="region.id" :value="region.id">{{ region.name }}</option>
                    </select>
                </label>
            </div>
        </section>

        <div class="glass-panel mt-4 flex items-center gap-3 overflow-x-auto rounded-xl px-3 py-2 text-xs text-slate-400">
            <template v-for="(crumb, index) in breadcrumbs" :key="crumb.id"><button type="button" :class="index === breadcrumbs.length - 1 ? 'border-violet-400/40 bg-violet-500/20 font-semibold text-violet-200' : 'border-white/10 bg-white/[.03]'" class="shrink-0 rounded-lg border px-4 py-2" @click="openBreadcrumb(crumb.id)">{{ crumb.name }}</button><span v-if="index < breadcrumbs.length - 1">›</span></template>
            <span class="shrink-0">› TPS</span>
            <span class="ml-auto shrink-0 rounded-full bg-emerald-400/10 px-2.5 py-1 font-semibold text-emerald-300">● {{ event?.status ?? 'TIDAK AKTIF' }}</span>
        </div>

        <section class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <article v-for="stat in statCards" :key="stat.label" :class="tones[stat.tone]" class="glass-panel relative overflow-hidden rounded-xl border bg-gradient-to-br to-transparent p-4">
                <div class="flex items-start justify-between"><div><p class="text-xs text-slate-300">{{ stat.label }}</p><p class="mt-1 text-2xl font-bold text-white">{{ formatNumber(stat.value) }}</p></div><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-current/10 text-xl">{{ stat.icon }}</span></div>
                <p class="mt-2 text-[11px] text-current">{{ stat.note }}</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-950/60"><div class="h-full rounded-full bg-current" :style="{ width: `${stat.percent}%` }" /></div>
            </article>
        </section>

        <template v-if="demoPreview">
            <section class="mt-3 grid gap-3 xl:grid-cols-12">
                <article id="peta-wilayah" class="glass-panel scroll-mt-24 rounded-xl p-4 xl:col-span-4">
                    <div class="flex items-center justify-between"><h2 class="text-sm font-bold text-white">Peta Progres Verifikasi</h2><span class="text-[10px] text-slate-500">{{ event?.scope.level === 'province' ? 'Klik wilayah untuk drill-down' : event?.scope.name }}</span></div>
                    <PapuaRegionMap class="mt-4" :scope="event!.scope" :regions="demoPreview.regions" :summary="demoPreview.stats" @select="drillRegion" />
                    <div class="mt-4 flex flex-wrap gap-3 text-[10px] text-slate-400"><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-emerald-500" />≥80%</span><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-cyan-400" />60–79%</span><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-yellow-400" />40–59%</span><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-orange-400" />20–39%</span><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-rose-500" />&lt;20%</span><span><i class="mr-1 inline-block h-2 w-2 rounded-full bg-slate-600" />Belum ada TPS</span></div>
                </article>

                <article v-if="demoPreview.result_mode === 'party'" class="glass-panel overflow-hidden rounded-xl xl:col-span-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-4 py-3"><div><h2 class="text-sm font-bold text-white">Perolehan Suara Caleg</h2><p class="mt-1 text-[10px] text-slate-500">Diurutkan berdasarkan suara.</p></div><select v-model="selectedDapilId" class="rounded-lg border border-white/10 bg-slate-950/60 px-2.5 py-1.5 text-[10px] text-slate-200"><option value="all">Semua Dapil</option><option v-for="dapil in dapilOptions" :key="dapil.id" :value="dapil.id">{{ dapil.name }}</option></select></div>
                    <div class="max-h-[340px] overflow-auto"><table class="w-full min-w-[500px] text-left text-[10px]"><thead class="sticky top-0 bg-[#10243d] text-slate-500"><tr><th class="px-3 py-2">No.</th><th class="px-3 py-2">Caleg</th><th class="px-3 py-2">Partai</th><th class="px-3 py-2 text-right">Suara</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="candidate in filteredLegislativeCandidates" :key="candidate.id"><td class="px-3 py-2 text-slate-400">{{ candidate.number }}</td><td class="px-3 py-2"><div class="flex items-center gap-2"><img v-if="candidate.photo_url" :src="candidate.photo_url" :alt="`Foto ${candidate.name}`" class="h-8 w-8 rounded-lg border border-white/10 object-cover object-top" loading="lazy" @error="($event.currentTarget as HTMLImageElement).style.display = 'none'"><span v-else class="flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-[9px] font-bold text-slate-400">{{ candidate.number }}</span><span class="font-semibold text-white">{{ candidate.name }}</span></div><p class="mt-1 pl-10 text-[9px] text-slate-500">{{ candidate.dapil }}</p></td><td class="px-3 py-2 text-cyan-300">{{ candidate.party }}</td><td class="px-3 py-2 text-right font-bold text-white">{{ formatNumber(candidate.votes) }}<span class="block text-[9px] font-normal text-slate-500">{{ candidate.percentage }}%</span></td></tr><tr v-if="!filteredLegislativeCandidates.length"><td colspan="4" class="px-3 py-10 text-center text-slate-500">Belum ada caleg pada Dapil ini.</td></tr></tbody></table></div>
                </article>

                <article :class="demoPreview.result_mode === 'party' ? 'xl:col-span-4' : 'xl:col-span-5'" class="glass-panel rounded-xl p-4">
                    <div class="flex items-center justify-between"><h2 class="text-sm font-bold text-white">{{ demoPreview.result_mode === 'party' ? 'Perolehan Suara Partai' : 'Perolehan Suara Terverifikasi' }}</h2><span class="rounded-lg border border-white/10 px-2 py-1 text-[10px] text-slate-400">{{ demoPreview.result_mode === 'party' ? 'Lambang + seluruh caleg' : 'Hasil aktif' }}</span></div>
                    <div v-if="demoPreview.result_mode === 'party'" class="mt-3 overflow-x-auto rounded-xl border border-white/10">
                        <table class="w-full min-w-[560px] text-left text-[10px]"><thead class="bg-white/[.03] text-slate-500"><tr><th class="px-3 py-2">Partai</th><th class="px-3 py-2 text-right">Lambang</th><th class="px-3 py-2 text-right">Caleg</th><th class="px-3 py-2 text-right">Total</th><th class="px-3 py-2 text-right">%</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="party in demoPreview.candidates" :key="party.id"><td class="px-3 py-2.5"><div class="flex items-center gap-2"><img v-if="party.photo_url" :src="party.photo_url" :alt="`Logo ${party.label}`" class="h-6 w-6 rounded object-contain" loading="lazy"><span v-else class="flex h-6 w-6 items-center justify-center rounded bg-white/5 text-[9px] font-bold text-slate-300">{{ party.initials }}</span><span class="font-semibold text-white">{{ party.label }}</span></div></td><td class="px-3 py-2.5 text-right text-slate-300">{{ formatNumber(party.direct_party_votes) }}</td><td class="px-3 py-2.5 text-right text-slate-300">{{ formatNumber(party.candidate_votes) }}</td><td class="px-3 py-2.5 text-right font-bold text-white">{{ formatNumber(party.votes) }}</td><td class="px-3 py-2.5 text-right text-cyan-300">{{ party.percentage }}%</td></tr><tr v-if="!demoPreview.candidates.length"><td colspan="5" class="px-3 py-7 text-center text-slate-500">Belum ada partai peserta aktif pada kontes ini.</td></tr></tbody></table>
                    </div>
                    <div v-else class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="candidate in demoPreview.candidates" :key="candidate.label" :class="candidateTones[candidate.tone]" class="overflow-hidden rounded-xl border border-white/10 bg-gradient-to-br p-3">
                            <div class="relative flex h-24 items-center justify-center overflow-hidden rounded-lg bg-slate-950/35">
                                <span class="flex h-12 w-12 items-center justify-center rounded-full border border-current/30 bg-current/10 text-base font-black">{{ candidate.initials }}</span>
                                <img
                                    v-if="candidate.photo_url"
                                    :src="candidate.photo_url"
                                    :alt="`Foto ${candidate.label}`"
                                    class="absolute inset-0 h-full w-full object-cover object-top"
                                    loading="lazy"
                                    @error="($event.currentTarget as HTMLImageElement).style.display = 'none'"
                                >
                            </div>
                            <p class="mt-2 text-[10px] font-semibold text-slate-200">{{ candidate.label }}</p><p class="mt-1 text-lg font-bold text-white">{{ formatNumber(candidate.votes) }}</p><p class="text-xs font-bold text-current">{{ candidate.percentage }}%</p>
                            <div class="mt-2 h-1.5 rounded-full bg-slate-950/60"><div class="h-full rounded-full bg-current" :style="{ width: `${candidate.percentage * 2}%` }" /></div>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-3 divide-x divide-white/10 rounded-xl border border-white/10 bg-slate-950/20 p-3 text-xs"><div><p class="text-[10px] text-slate-500">Suara Sah</p><p class="mt-1 font-bold text-white">{{ formatNumber(demoPreview.votes.valid) }}</p></div><div class="pl-3"><p class="text-[10px] text-slate-500">Tidak Sah</p><p class="mt-1 font-bold text-white">{{ formatNumber(demoPreview.votes.invalid) }}</p></div><div class="pl-3"><p class="text-[10px] text-slate-500">Total Suara</p><p class="mt-1 font-bold text-white">{{ formatNumber(demoPreview.votes.total) }}</p></div></div>
                </article>

                <article :class="demoPreview.result_mode === 'party' ? 'xl:col-span-12' : 'xl:col-span-3'" class="glass-panel rounded-xl p-4">
                    <div class="flex items-center justify-between"><h2 class="text-sm font-bold text-white">Tren Perolehan</h2><span class="text-[10px] text-slate-500">Berdasarkan waktu verifikasi</span></div>
                    <div class="mt-3 flex flex-wrap gap-3"><span v-for="line in demoPreview.trend" :key="line.label" class="text-[9px] text-slate-400"><i class="mr-1 inline-block h-2 w-2 rounded-full" :style="{ background: line.tone }" />{{ line.label }}</span></div>
                    <svg v-if="demoPreview.trend.length" viewBox="0 0 300 130" class="mt-2 h-44 w-full" role="img" aria-label="Grafik tren perolehan suara terverifikasi">
                        <g stroke="rgba(148,163,184,.16)" stroke-width="1"><line v-for="y in [20, 50, 80, 110]" :key="y" x1="0" :y1="y" x2="300" :y2="y" /></g>
                        <polyline v-for="line in demoPreview.trend" :key="line.label" :points="polyline(line.values)" fill="none" :stroke="line.tone" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div v-if="demoPreview.trend.length" class="flex justify-between text-[9px] text-slate-600"><span>Awal</span><span>Verifikasi terbaru</span></div>
                    <div v-else class="mt-4 flex h-44 items-center justify-center rounded-xl border border-dashed border-white/10 text-center text-xs text-slate-500">Tren akan tampil setelah ada hasil yang diverifikasi.</div>
                </article>
            </section>

            <section class="mt-3 grid gap-3 xl:grid-cols-12">
                <article class="glass-panel overflow-hidden rounded-xl xl:col-span-6">
                    <div class="flex items-center justify-between border-b border-white/10 px-4 py-3"><h2 class="text-sm font-bold text-white">Progres per Wilayah</h2><span class="text-[10px] text-blue-300">{{ demoPreview.regions.length }} wilayah</span></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[650px] text-left text-[10px]"><thead class="bg-white/[.03] text-slate-500"><tr><th class="px-4 py-2">#</th><th class="px-3 py-2">Wilayah</th><th class="px-3 py-2 text-right">TPS</th><th class="px-3 py-2 text-right">Masuk</th><th class="px-3 py-2 text-right">Verifikasi</th><th class="px-4 py-2">Progres</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="(region, index) in demoPreview.regions" :key="region.id" class="cursor-pointer hover:bg-white/[.03]" @click="drillRegion(region.id)"><td class="px-4 py-2 text-slate-500">{{ index + 1 }}</td><td class="px-3 py-2 font-medium text-white">{{ region.name }}</td><td class="px-3 py-2 text-right text-slate-300">{{ region.total }}</td><td class="px-3 py-2 text-right text-slate-300">{{ region.submitted }}</td><td class="px-3 py-2 text-right text-slate-300">{{ region.verified }}</td><td class="px-4 py-2"><div class="flex items-center gap-2"><div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-950/60"><div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-cyan-300" :style="{ width: `${percentage(region.verified, region.total)}%` }" /></div><span class="text-slate-400">{{ percentage(region.verified, region.total).toFixed(1) }}%</span></div></td></tr></tbody></table></div>
                </article>

                <article class="glass-panel rounded-xl p-4 xl:col-span-3">
                    <h2 class="text-sm font-bold text-white">Status TPS</h2>
                    <div class="mt-5 flex items-center gap-5"><div class="relative h-32 w-32 shrink-0 rounded-full" :style="donutStyle"><div class="absolute inset-5 flex flex-col items-center justify-center rounded-full bg-[#0a1930]"><strong class="text-xl text-white">{{ formatNumber(demoPreview.stats.total) }}</strong><span class="text-[10px] text-slate-400">TPS</span></div></div><div class="space-y-3 text-[10px]"><p><i class="mr-2 inline-block h-2.5 w-2.5 rounded-full bg-emerald-400" />Terverifikasi <strong class="block pl-5 text-white">{{ formatNumber(demoPreview.stats.verified) }}</strong></p><p><i class="mr-2 inline-block h-2.5 w-2.5 rounded-full bg-orange-400" />Review <strong class="block pl-5 text-white">{{ demoPreview.stats.review }}</strong></p><p><i class="mr-2 inline-block h-2.5 w-2.5 rounded-full bg-rose-500" />Revisi <strong class="block pl-5 text-white">{{ demoPreview.stats.revision }}</strong></p><p><i class="mr-2 inline-block h-2.5 w-2.5 rounded-full bg-slate-300" />Belum <strong class="block pl-5 text-white">{{ demoPreview.stats.unreported }}</strong></p></div></div>
                </article>

                <article class="glass-panel rounded-xl p-4 xl:col-span-3">
                    <div class="flex items-center justify-between"><h2 class="text-sm font-bold text-white">Aktivitas Terbaru</h2><span class="text-[10px] text-blue-300">Lihat semua →</span></div>
                    <div class="mt-4 space-y-3"><div v-for="activity in demoPreview.activities" :key="activity.title" class="flex gap-3"><span :class="statusTones[activity.tone]" class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-opacity-15 text-[10px] text-white">●</span><div class="min-w-0"><p class="truncate text-xs font-semibold text-white">{{ activity.title }}</p><p class="truncate text-[10px] text-slate-400">{{ activity.detail }}</p></div><span class="ml-auto shrink-0 text-[9px] text-slate-600">{{ activity.time }}</span></div></div>
                </article>
            </section>

            <section class="glass-panel mt-3 overflow-hidden rounded-xl">
                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3"><div><h2 class="text-sm font-bold text-white">Monitoring TPS</h2><p class="mt-1 text-[10px] text-slate-500">Status laporan untuk kontes dan wilayah terpilih.</p></div><select v-model="monitoringStatus" class="rounded-lg border border-white/10 bg-slate-950/60 px-3 py-1.5 text-[10px] text-slate-300"><option value="all">Semua status</option><option v-for="status in monitoringStatuses" :key="status" :value="status">{{ status }}</option></select></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-[10px]"><thead class="bg-white/[.03] text-slate-500"><tr><th class="px-4 py-2">TPS</th><th class="px-3 py-2">Wilayah</th><th v-for="candidate in demoPreview.candidates" :key="candidate.id" class="px-3 py-2">{{ candidate.initials }}</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Update</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="item in filteredMonitoring" :key="item.tps"><td class="px-4 py-2 font-semibold text-white">{{ item.tps }}</td><td class="px-3 py-2 text-slate-300">{{ item.area }}</td><td v-for="(vote, index) in item.votes" :key="index" class="px-3 py-2 text-slate-300">{{ formatNumber(vote) }}</td><td class="px-3 py-2"><span class="flex items-center gap-1.5 text-slate-300"><i :class="statusTones[item.tone]" class="h-2 w-2 rounded-full" />{{ item.status }}</span></td><td class="px-3 py-2 text-slate-500">{{ item.updated }}</td></tr><tr v-if="filteredMonitoring.length === 0"><td :colspan="demoPreview.candidates.length + 4" class="px-4 py-8 text-center text-slate-500">Tidak ada TPS dengan status ini.</td></tr></tbody></table></div>
            </section>
        </template>

        <section v-else class="glass-panel mt-4 rounded-xl p-8 text-center"><p class="font-semibold text-white">Dashboard belum tersedia</p><p class="mt-2 text-sm text-slate-400">Pilih event yang memiliki kontes untuk menampilkan ringkasan.</p></section>
        <p class="mt-4 text-center text-[10px] text-slate-600">Perolehan suara hanya berasal dari hasil aktif yang telah disetujui Super Admin · Dashboard internal, bukan publikasi resmi KPU.</p>
    </AuthenticatedLayout>
</template>
