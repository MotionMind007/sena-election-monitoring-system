<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MapLoading from '@/Components/MapLoading.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent } from 'vue';

const PapuaRegionMap = defineAsyncComponent({
    loader: () => import('@/Components/PapuaRegionMap.vue'),
    loadingComponent: MapLoading,
    delay: 100,
});

interface Stats {
    total: number;
    submitted: number;
    verified: number;
    review: number;
    revision: number;
    unreported: number;
}

interface RegionProgress {
    id: number;
    name: string;
    level: string;
    total: number;
    submitted: number;
    verified: number;
}

const props = defineProps<{
    event: { id: number; name: string; year: number; status: string; scope: { id: number; name: string; level: string } } | null;
    eventOptions: Array<{ id: number; name: string; year: number; status: string }>;
    contestOptions: Array<{ id: number; name: string; status: string }>;
    selectedContestId: number | null;
    breadcrumbs: Array<{ id: number; name: string; level: string }>;
    stats: Stats;
    demoPreview?: { stats: Stats; regions: RegionProgress[] } | null;
}>();

const activeStats = computed(() => props.demoPreview?.stats ?? props.stats);
const regions = computed(() => props.demoPreview?.regions ?? []);
const percentage = (value: number, total: number) => total > 0 ? (value / total) * 100 : 0;
const formatNumber = (value: number) => new Intl.NumberFormat('id-ID').format(value);
const navigate = (parameters: Record<string, number | undefined>) => router.get(route('map.index'), parameters, { preserveScroll: true });
const switchEvent = (event: Event) => navigate({ event: Number((event.target as HTMLSelectElement).value) });
const switchContest = (event: Event) => navigate({ event: props.event?.id, contest: Number((event.target as HTMLSelectElement).value) });
const drillRegion = (regionId: number) => navigate({ event: props.event?.id, contest: props.selectedContestId ?? undefined, region: regionId });
</script>

<template>
    <Head title="Peta Wilayah" />
    <AuthenticatedLayout>
        <div class="flex flex-col gap-5">
            <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[.2em] text-cyan-400">Pemetaan hasil internal</p>
                    <h1 class="mt-1 text-2xl font-bold text-white">Peta Wilayah</h1>
                    <p class="mt-1 text-sm text-slate-400">Pantau sebaran laporan dan progres verifikasi berdasarkan wilayah.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:w-[620px]">
                    <label class="text-xs text-slate-400">Event
                        <select class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/70 px-3 py-2.5 text-sm text-white" :value="event?.id" @change="switchEvent">
                            <option v-for="option in eventOptions" :key="option.id" :value="option.id">{{ option.name }} · {{ option.year }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-400">Kontes
                        <select class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/70 px-3 py-2.5 text-sm text-white" :value="selectedContestId" :disabled="!contestOptions.length" @change="switchContest">
                            <option v-for="option in contestOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </select>
                    </label>
                </div>
            </header>

            <template v-if="event && demoPreview">
                <nav class="glass-panel flex flex-wrap items-center gap-2 rounded-xl px-4 py-3 text-xs" aria-label="Hierarki wilayah">
                    <template v-for="(item, index) in breadcrumbs" :key="item.id">
                        <span v-if="index" class="text-slate-600">›</span>
                        <button class="font-semibold text-blue-300 hover:text-blue-200" type="button" @click="drillRegion(item.id)">{{ item.name }}</button>
                    </template>
                    <span class="ml-auto rounded-full border border-white/10 bg-white/5 px-3 py-1 uppercase text-slate-400">{{ event.status }}</span>
                </nav>

                <section class="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(260px,.72fr)]">
                    <article class="glass-panel rounded-2xl p-4 sm:p-5">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <div><h2 class="font-bold text-white">{{ event.scope.name }}</h2><p class="mt-1 text-xs text-slate-500">{{ event.scope.level === 'province' ? 'Klik kabupaten/kota pada peta untuk melihat wilayah tersebut.' : 'Batas administrasi wilayah sesuai scope event terpilih.' }}</p></div>
                            <span class="rounded-lg bg-cyan-400/10 px-3 py-1.5 text-xs font-semibold text-cyan-300">{{ regions.length }} wilayah turunan</span>
                        </div>
                        <PapuaRegionMap :scope="event.scope" :regions="regions" :summary="activeStats" @select="drillRegion" />
                        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-[10px] text-slate-400">
                            <span><i class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full bg-emerald-500" />≥80%</span>
                            <span><i class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full bg-cyan-400" />60–79%</span>
                            <span><i class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full bg-yellow-400" />40–59%</span>
                            <span><i class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full bg-orange-400" />20–39%</span>
                            <span><i class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full bg-rose-500" />&lt;20%</span>
                            <span><i class="mr-1.5 inline-block h-2.5 w-2.5 rounded-full bg-slate-600" />Belum ada TPS</span>
                        </div>
                    </article>

                    <aside class="grid content-start gap-3 sm:grid-cols-2 xl:grid-cols-1">
                        <article v-for="item in [
                            { label: 'Total TPS', value: activeStats.total, detail: 'TPS dalam scope', color: 'text-blue-300' },
                            { label: 'Laporan Masuk', value: activeStats.submitted, detail: `${percentage(activeStats.submitted, activeStats.total).toFixed(1)}% dari TPS`, color: 'text-cyan-300' },
                            { label: 'Terverifikasi', value: activeStats.verified, detail: `${percentage(activeStats.verified, activeStats.total).toFixed(1)}% dari TPS`, color: 'text-emerald-300' },
                            { label: 'Perlu Tindak Lanjut', value: activeStats.review + activeStats.revision, detail: `${activeStats.review} review · ${activeStats.revision} revisi`, color: 'text-orange-300' },
                        ]" :key="item.label" class="glass-panel rounded-2xl p-5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ item.label }}</p>
                            <p class="mt-2 text-3xl font-bold" :class="item.color">{{ formatNumber(item.value) }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ item.detail }}</p>
                        </article>
                    </aside>
                </section>

                <section class="glass-panel overflow-hidden rounded-2xl">
                    <div class="border-b border-white/10 px-5 py-4"><h2 class="font-bold text-white">Progres per Wilayah</h2><p class="mt-1 text-xs text-slate-500">Tabel mengikuti event, kontes, dan scope peta yang dipilih.</p></div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[680px] text-left text-xs">
                            <thead class="bg-white/[.03] text-slate-500"><tr><th class="px-5 py-3">Wilayah</th><th class="px-4 py-3 text-right">TPS</th><th class="px-4 py-3 text-right">Masuk</th><th class="px-4 py-3 text-right">Terverifikasi</th><th class="px-5 py-3">Progres</th></tr></thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="region in regions" :key="region.id" class="cursor-pointer hover:bg-white/[.035]" @click="drillRegion(region.id)">
                                    <td class="px-5 py-3 font-semibold text-white">{{ region.name }}</td><td class="px-4 py-3 text-right text-slate-300">{{ formatNumber(region.total) }}</td><td class="px-4 py-3 text-right text-slate-300">{{ formatNumber(region.submitted) }}</td><td class="px-4 py-3 text-right text-slate-300">{{ formatNumber(region.verified) }}</td>
                                    <td class="px-5 py-3"><div class="flex items-center gap-3"><div class="h-2 w-28 overflow-hidden rounded-full bg-slate-950"><div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-emerald-400" :style="{ width: `${percentage(region.verified, region.total)}%` }" /></div><span class="text-slate-400">{{ percentage(region.verified, region.total).toFixed(1) }}%</span></div></td>
                                </tr>
                                <tr v-if="!regions.length"><td colspan="5" class="px-5 py-10 text-center text-slate-500">Belum ada wilayah turunan dalam scope ini.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <section v-else class="glass-panel rounded-2xl p-12 text-center"><h2 class="text-lg font-bold text-white">Peta belum tersedia</h2><p class="mt-2 text-sm text-slate-500">Buat event dan kontes terlebih dahulu untuk melihat progres wilayah.</p></section>
        </div>
    </AuthenticatedLayout>
</template>
