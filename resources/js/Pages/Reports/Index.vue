<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

type EventOption = { id: number; name: string; year: number; type: string; scope_level?: string | null };
type PartyRef = { name: string; abbreviation: string };
type Participant = {
    id: number;
    participant_type: 'pair' | 'party' | 'candidate';
    ballot_number: string;
    display_name: string;
    has_photo: boolean;
    electoral_district_id: number | null;
    electoral_district_name?: string | null;
    dapil_ids: number[];
    party?: PartyRef | null;
    supporting_parties: PartyRef[];
};
type Contest = { id: number; name: string; type: string; participants: Participant[] };
type Dapil = { id: number; name: string; region_ids: number[] };
type Station = {
    id: number;
    code: string;
    number: number;
    village_id: number;
    village: string;
    district_id: number;
    district: string;
    regency_id: number;
    regency: string;
};
type Submission = { id: number; contest: string; tps: string; area: string; status: string; version: number; entry_mode: string; submitter: string; total_votes?: number; submitted_at?: string };
type LocationOption = { id: number; name: string };
type RecapRegion = { id: number; name: string; level: string };
type RecapPreviewItem = { participant_id: number; ballot_number: string; display_name: string; ballot_dimension: string; automatic_votes: number };
type RecapPreview = {
    region: RecapRegion;
    verified_tps_count: number;
    automatic: { valid_votes: number; invalid_votes: number; total_votes: number };
    items: RecapPreviewItem[];
    latest: null | {
        id: number;
        version: number;
        has_discrepancy: boolean;
        current_has_discrepancy: boolean;
        current_delta_total: number;
        snapshot_stale: boolean;
        official: { valid_votes: number; invalid_votes: number; total_votes: number };
        automatic: { valid_votes: number; invalid_votes: number; total_votes: number };
        items: Array<{ participant_id: number; official_votes: number; automatic_votes: number; delta: number }>;
    };
};
type RecapHistory = {
    id: number; contest: string; region: string; level: string; version: number; has_discrepancy: boolean;
    automatic_total_votes: number; official_total_votes: number; delta: number; verified_tps_count: number; submitter: string; snapshot_at: string;
};

const props = defineProps<{
    events: EventOption[];
    event: EventOption | null;
    contests: Contest[];
    electoralDistricts: Dapil[];
    stations: Station[];
    submissions: Submission[];
    recapRegions: { district: RecapRegion[]; regency: RecapRegion[]; province: RecapRegion[] };
    recapComparisons: RecapHistory[];
    canViewRecaps: boolean;
    canManageRecaps: boolean;
}>();

const calculationLevel = ref<'tps' | 'district' | 'regency' | 'province'>('tps');
const contestId = ref<number | string>(props.contests[0]?.id ?? '');
const selectedDapilId = ref<number | string>('');
const regencyId = ref<number | string>('');
const districtId = ref<number | string>('');
const villageId = ref<number | string>('');
const stationId = ref<number | string>('');
const recapRegionId = ref<number | string>('');
const recapPreview = ref<RecapPreview | null>(null);
const recapLoading = ref(false);
const recapError = ref('');
let recapAbort: AbortController | null = null;

function newIdempotencyKey(): string {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}-4000-8000-${Math.random().toString(16).slice(2)}`;
}

const recapForm = useForm({
    election_id: props.event?.id ?? 0,
    election_contest_id: Number(contestId.value) || 0,
    region_id: 0,
    idempotency_key: newIdempotencyKey(),
    items: [] as Array<{ participant_id: number; official_votes: number }>,
    official_invalid_votes: 0,
    evidence: null as File | null,
    notes: '',
});

const isPileg = computed(() => props.event?.type === 'pileg');
const selectedContest = computed(() => props.contests.find((item) => item.id === Number(contestId.value)) ?? null);
const selectedDapil = computed(() => props.electoralDistricts.find((item) => item.id === Number(selectedDapilId.value)) ?? null);
const stationsForDapil = computed(() => {
    if (!isPileg.value) return props.stations;
    if (!selectedDapil.value) return [];
    return props.stations.filter((station) => selectedDapil.value?.region_ids.includes(station.district_id) || selectedDapil.value?.region_ids.includes(station.village_id));
});

function locationOptions(stations: Station[], idKey: 'regency_id' | 'district_id' | 'village_id', nameKey: 'regency' | 'district' | 'village'): LocationOption[] {
    return [...new Map(stations.map((station) => [station[idKey], { id: station[idKey], name: station[nameKey] }])).values()].sort((left, right) => left.name.localeCompare(right.name, 'id-ID'));
}

const regencyOptions = computed(() => locationOptions(stationsForDapil.value, 'regency_id', 'regency'));
const districtOptions = computed(() => locationOptions(stationsForDapil.value.filter((station) => station.regency_id === Number(regencyId.value)), 'district_id', 'district'));
const villageOptions = computed(() => locationOptions(stationsForDapil.value.filter((station) => station.district_id === Number(districtId.value)), 'village_id', 'village'));
const stationOptions = computed(() => stationsForDapil.value.filter((station) => station.village_id === Number(villageId.value)));
const selectedStation = computed(() => props.stations.find((station) => station.id === Number(stationId.value)) ?? null);
const recapRegionOptions = computed(() => {
    if (calculationLevel.value === 'tps') return [];
    const options = props.recapRegions[calculationLevel.value] ?? [];
    if (calculationLevel.value === 'district' && isPileg.value) {
        const dapil = selectedDapil.value;
        return dapil ? options.filter((region) => dapil.region_ids.includes(region.id)) : [];
    }
    return options;
});
const officialValidVotes = computed(() => recapForm.items.reduce((total, item) => total + (Number(item.official_votes) || 0), 0));
const officialTotalVotes = computed(() => officialValidVotes.value + (Number(recapForm.official_invalid_votes) || 0));

const visibleParticipants = computed(() => {
    const participants = selectedContest.value?.participants ?? [];
    if (!isPileg.value) return participants.filter((item) => item.participant_type === 'pair');
    const dapil = selectedDapil.value;
    if (!dapil) return [];
    return participants.filter((item) => item.participant_type === 'candidate'
        ? item.electoral_district_id === dapil.id
        : item.participant_type === 'party' && item.dapil_ids.includes(dapil.id));
});
const partyParticipants = computed(() => visibleParticipants.value.filter((item) => item.participant_type === 'party'));
const candidateParticipants = computed(() => visibleParticipants.value.filter((item) => item.participant_type !== 'party'));
const createHref = computed(() => props.event && calculationLevel.value === 'tps' && contestId.value && stationId.value
    ? route('reports.create', { event: props.event.id, contest: contestId.value, tps: stationId.value })
    : '#');

function resetVillage(): void {
    villageId.value = villageOptions.value[0]?.id ?? '';
    stationId.value = stationOptions.value[0]?.id ?? '';
}
function resetDistrict(): void {
    districtId.value = districtOptions.value[0]?.id ?? '';
    resetVillage();
}
function resetRegency(): void {
    regencyId.value = regencyOptions.value[0]?.id ?? '';
    resetDistrict();
}
function changeVillage(): void { stationId.value = stationOptions.value[0]?.id ?? ''; }
function changeDapil(): void { resetRegency(); resetRecapRegion(); }
function switchEvent(target: Event): void { router.get(route('reports.index'), { event: Number((target.target as HTMLSelectElement).value) }); }
function statusLabel(status: string): string { return status === 'draft' ? 'Draft' : 'Terkirim'; }
function formatNumber(value: number): string { return new Intl.NumberFormat('id-ID').format(value); }
function resetRecapRegion(): void {
    const nextRegionId = recapRegionOptions.value[0]?.id ?? '';
    if (recapRegionId.value === nextRegionId) {
        void loadRecapPreview();
    } else {
        recapRegionId.value = nextRegionId;
    }
}
async function loadRecapPreview(): Promise<void> {
    recapAbort?.abort();
    recapPreview.value = null;
    recapError.value = '';
    if (!props.event || calculationLevel.value === 'tps' || !contestId.value || !recapRegionId.value) return;
    recapAbort = new AbortController();
    recapLoading.value = true;
    try {
        const url = route('recap-comparisons.preview', { event: props.event.id, contest: Number(contestId.value), region: Number(recapRegionId.value) });
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: recapAbort.signal });
        if (!response.ok) throw new Error(response.status === 403 || response.status === 404 ? 'Wilayah rekap tidak dapat diakses.' : 'Snapshot rekap gagal dimuat.');
        recapPreview.value = await response.json() as RecapPreview;
        recapForm.election_id = props.event.id;
        recapForm.election_contest_id = Number(contestId.value);
        recapForm.region_id = Number(recapRegionId.value);
        recapForm.items = recapPreview.value.items.map((item) => ({ participant_id: item.participant_id, official_votes: 0 }));
        recapForm.official_invalid_votes = 0;
        recapForm.clearErrors();
    } catch (error) {
        if ((error as Error).name !== 'AbortError') recapError.value = (error as Error).message;
    } finally {
        recapLoading.value = false;
    }
}
function selectEvidence(event: Event): void {
    recapForm.evidence = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function submitRecap(): void {
    if (!props.event || !recapPreview.value) return;
    recapForm.post(route('recap-comparisons.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            recapForm.idempotency_key = newIdempotencyKey();
            recapForm.evidence = null;
        },
    });
}

watch(calculationLevel, resetRecapRegion);
watch(contestId, () => {
    recapForm.election_contest_id = Number(contestId.value) || 0;
    resetRecapRegion();
});
watch(recapRegionId, () => void loadRecapPreview());
onUnmounted(() => recapAbort?.abort());

if (!isPileg.value) resetRegency();
</script>

<template>
    <Head title="Input Laporan" />
    <AuthenticatedLayout>
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Pelaporan & rekapitulasi internal</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Input Laporan</h1>
                <p class="mt-1 text-sm text-slate-400">Input suara hanya dari TPS. Rekap tingkat berikutnya dihitung otomatis dari hasil yang disetujui.</p>
            </div>
            <label class="text-xs text-slate-400">Event
                <select :value="event?.id" class="mt-2 block w-full min-w-72 rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-sm text-white lg:w-auto" @change="switchEvent">
                    <option v-for="item in events" :key="item.id" :value="item.id">{{ item.name }} · {{ item.year }}</option>
                </select>
            </label>
        </div>

        <template v-if="event">
            <section class="glass-panel mt-5 rounded-2xl p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-blue-400">1 · Konteks pemilihan</p><h2 class="mt-1 font-bold text-white">{{ event.name }}</h2></div>
                    <span class="rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-1 text-[10px] font-bold uppercase text-cyan-200">{{ isPileg ? 'Pileg' : 'Pilkada' }}</span>
                </div>
                <div class="mt-4 grid gap-4" :class="isPileg ? 'md:grid-cols-2' : 'md:grid-cols-1'">
                    <label class="text-xs text-slate-400">Kontes
                        <select v-model="contestId" :disabled="!contests.length" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white disabled:opacity-40">
                            <option v-if="!contests.length" value="">Belum ada kontes aktif</option>
                            <option v-for="item in contests" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </label>
                    <label v-if="isPileg" class="text-xs text-slate-400">Daerah pemilihan (dapil)
                        <select v-model="selectedDapilId" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white" @change="changeDapil">
                            <option value="" disabled>Pilih dapil</option>
                            <option v-for="dapil in electoralDistricts" :key="dapil.id" :value="dapil.id">{{ dapil.name }}</option>
                        </select>
                    </label>
                </div>

                <div class="mt-5 border-t border-white/10 pt-5">
                    <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-bold text-white">{{ isPileg ? 'Peserta pada dapil' : 'Pasangan calon' }}</h3><span class="text-[10px] text-slate-500">{{ visibleParticipants.length }} peserta</span></div>
                    <p v-if="isPileg && !selectedDapil" class="mt-3 rounded-xl border border-dashed border-white/10 px-4 py-6 text-center text-xs text-slate-500">Pilih dapil untuk menampilkan partai dan calon legislatif.</p>
                    <p v-else-if="!visibleParticipants.length" class="mt-3 rounded-xl border border-dashed border-amber-400/20 bg-amber-400/5 px-4 py-6 text-center text-xs text-amber-200">Peserta belum dikonfigurasi untuk pilihan ini.</p>

                    <div v-if="partyParticipants.length" class="mt-3">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Partai peserta</p>
                        <div class="flex flex-wrap gap-2"><span v-for="party in partyParticipants" :key="party.id" class="rounded-lg border border-violet-400/20 bg-violet-400/10 px-3 py-2 text-xs font-semibold text-violet-100"><b class="mr-2 text-violet-300">{{ party.ballot_number }}</b>{{ party.display_name }}</span></div>
                    </div>
                    <div v-if="candidateParticipants.length" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <article v-for="participant in candidateParticipants" :key="participant.id" class="flex min-w-0 gap-3 rounded-xl border border-white/10 bg-white/[.025] p-3">
                            <div class="flex h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-blue-500/10">
                                <img v-if="participant.has_photo" :src="route('participants.photo', participant.id)" :alt="participant.display_name" class="h-full w-full object-cover object-top">
                                <span v-else class="m-auto text-sm font-black text-blue-300">{{ participant.ballot_number }}</span>
                            </div>
                            <div class="min-w-0"><p class="text-[10px] text-slate-500">Nomor {{ participant.ballot_number }}</p><p class="truncate text-sm font-bold text-white">{{ participant.display_name }}</p><p class="mt-1 truncate text-[10px] text-cyan-300">{{ participant.party?.abbreviation || participant.supporting_parties.map((party) => party.abbreviation).join(', ') || 'Independen/nonpartai' }}</p></div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="glass-panel mt-5 rounded-2xl p-5">
                <div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-blue-400">2 · Tingkat perhitungan</p><h2 class="mt-1 font-bold text-white">Pilih sumber laporan</h2></div>
                <select v-model="calculationLevel" :disabled="!selectedContest" class="mt-4 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white disabled:opacity-40 md:max-w-md">
                    <option value="tps">TPS · Input suara sumber</option>
                    <option v-if="selectedContest && canViewRecaps && recapRegions.district.length" value="district">Distrik · Rekap otomatis & pembanding</option>
                    <option v-if="selectedContest && canViewRecaps && recapRegions.regency.length" value="regency">Kabupaten/Kota · Rekap otomatis & pembanding</option>
                    <option v-if="selectedContest && canViewRecaps && recapRegions.province.length" value="province">Provinsi · Rekap otomatis & pembanding</option>
                </select>
                <p v-if="!selectedContest" class="mt-3 text-xs text-amber-300">Aktifkan dan konfigurasi kontes beserta pesertanya sebelum membuat laporan atau rekap pembanding.</p>
                <div v-if="calculationLevel !== 'tps'" class="mt-4 rounded-xl border border-cyan-400/20 bg-cyan-400/[.07] p-4">
                    <p class="text-sm font-bold text-cyan-100">Tidak ada input suara ulang pada tingkat ini.</p>
                    <p class="mt-1 text-xs leading-5 text-cyan-100/70">Angka dihitung dari hasil TPS yang telah disetujui Super Admin. Dokumen rekap tingkat ini digunakan sebagai pembanding; selisih akan ditandai tanpa mengubah total sumber.</p>
                </div>
            </section>

            <section v-if="selectedContest && calculationLevel === 'tps'" class="glass-panel mt-5 rounded-2xl p-5">
                <div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-blue-400">3 · Lokasi laporan</p><h2 class="mt-1 font-bold text-white">Pilih TPS</h2></div>
                <div v-if="isPileg && !selectedDapil" class="mt-4 rounded-xl border border-dashed border-white/10 px-4 py-8 text-center text-xs text-slate-500">Pilih dapil terlebih dahulu.</div>
                <div v-else class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label class="text-xs text-slate-400">Kabupaten/Kota<select v-model="regencyId" :disabled="!regencyOptions.length" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white disabled:opacity-40" @change="resetDistrict"><option v-for="item in regencyOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Distrik<select v-model="districtId" :disabled="!districtOptions.length" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white disabled:opacity-40" @change="resetVillage"><option v-for="item in districtOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Kampung/Kelurahan<select v-model="villageId" :disabled="!villageOptions.length" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white disabled:opacity-40" @change="changeVillage"><option v-for="item in villageOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Nama/nomor TPS<select v-model="stationId" :disabled="!stationOptions.length" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm font-semibold text-white disabled:opacity-40"><option v-for="item in stationOptions" :key="item.id" :value="item.id">{{ item.code }}</option></select></label>
                </div>

                <div v-if="selectedStation" class="mt-4 flex flex-col gap-4 rounded-xl border border-emerald-400/15 bg-emerald-400/[.06] p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="text-sm font-bold text-white">{{ selectedStation.code }}</p><p class="mt-1 text-xs text-slate-400">{{ selectedStation.village }} · {{ selectedStation.district }} · {{ selectedStation.regency }}</p></div>
                    <Link :href="createHref" :class="createHref === '#' ? 'pointer-events-none opacity-40' : ''" class="shrink-0 rounded-xl bg-blue-600 px-5 py-3 text-center text-sm font-bold text-white hover:bg-blue-500">Periksa status TPS →</Link>
                </div>
                <p v-else-if="!stationsForDapil.length && (!isPileg || selectedDapil)" class="mt-4 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4 text-xs text-amber-200">Belum ada TPS aktif dalam scope yang dipilih.</p>
            </section>

            <section v-else-if="canViewRecaps" class="glass-panel mt-5 rounded-2xl p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-cyan-400">3 · Rekap pembanding</p><h2 class="mt-1 font-bold text-white">Bandingkan dokumen resmi dengan hasil TPS</h2></div>
                    <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-[10px] font-bold text-emerald-300">Snapshot immutable</span>
                </div>

                <div v-if="isPileg && calculationLevel === 'district' && !selectedDapil" class="mt-4 rounded-xl border border-dashed border-white/10 px-4 py-8 text-center text-xs text-slate-500">Pilih dapil terlebih dahulu untuk menentukan distrik dan peserta yang berlaku.</div>
                <template v-else>
                    <label class="mt-4 block text-xs text-slate-400">Wilayah rekap
                        <select v-model="recapRegionId" :disabled="!recapRegionOptions.length" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white disabled:opacity-40 md:max-w-xl">
                            <option v-if="!recapRegionOptions.length" value="">Belum ada wilayah dalam scope</option>
                            <option v-for="region in recapRegionOptions" :key="region.id" :value="region.id">{{ region.name }}</option>
                        </select>
                    </label>

                    <div v-if="recapLoading" class="mt-4 rounded-xl border border-white/10 px-4 py-8 text-center text-xs text-slate-400">Menghitung snapshot TPS terverifikasi…</div>
                    <div v-else-if="recapError" class="mt-4 rounded-xl border border-rose-400/20 bg-rose-400/[.07] p-4 text-xs text-rose-200">{{ recapError }}</div>
                    <template v-else-if="recapPreview">
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <article class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-[10px] uppercase text-slate-500">TPS terverifikasi</p><p class="mt-2 text-xl font-black text-white">{{ formatNumber(recapPreview.verified_tps_count) }}</p></article>
                            <article class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-[10px] uppercase text-slate-500">Suara sah otomatis</p><p class="mt-2 text-xl font-black text-cyan-200">{{ formatNumber(recapPreview.automatic.valid_votes) }}</p></article>
                            <article class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-[10px] uppercase text-slate-500">Tidak sah otomatis</p><p class="mt-2 text-xl font-black text-amber-200">{{ formatNumber(recapPreview.automatic.invalid_votes) }}</p></article>
                            <article class="rounded-xl border border-blue-400/20 bg-blue-400/[.06] p-4"><p class="text-[10px] uppercase text-blue-300">Total otomatis</p><p class="mt-2 text-xl font-black text-white">{{ formatNumber(recapPreview.automatic.total_votes) }}</p></article>
                        </div>

                        <div v-if="recapPreview.latest" :class="recapPreview.latest.current_has_discrepancy ? 'border-rose-400/25 bg-rose-400/[.06]' : 'border-emerald-400/25 bg-emerald-400/[.06]'" class="mt-4 rounded-xl border p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-white">Pembanding terakhir · versi {{ recapPreview.latest.version }}</p><span :class="recapPreview.latest.current_has_discrepancy ? 'text-rose-300' : 'text-emerald-300'" class="text-xs font-bold">{{ recapPreview.latest.current_has_discrepancy ? 'Ada selisih saat ini' : 'Sesuai hasil saat ini' }}</span></div>
                            <p class="mt-1 text-xs text-slate-400">Total resmi {{ formatNumber(recapPreview.latest.official.total_votes) }} · hasil TPS saat ini {{ formatNumber(recapPreview.automatic.total_votes) }} · selisih {{ formatNumber(recapPreview.latest.current_delta_total) }}</p>
                            <p v-if="recapPreview.latest.snapshot_stale" class="mt-2 text-[10px] font-semibold text-amber-300">Hasil TPS berubah sejak versi ini dibuat. Simpan versi pembanding baru setelah dokumen resmi terbaru tersedia.</p>
                        </div>

                        <form v-if="canManageRecaps && recapPreview.items.length" class="mt-5" @submit.prevent="submitRecap">
                            <div class="overflow-x-auto rounded-xl border border-white/10">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="bg-white/[.04] text-slate-400"><tr><th class="px-4 py-3">Peserta</th><th class="px-4 py-3 text-right">Hasil TPS</th><th class="px-4 py-3">Angka rekap resmi</th><th class="px-4 py-3 text-right">Selisih</th></tr></thead>
                                    <tbody class="divide-y divide-white/5">
                                        <tr v-for="(item, index) in recapPreview.items" :key="item.participant_id">
                                            <td class="px-4 py-3"><p class="font-semibold text-white">{{ item.ballot_number }} · {{ item.display_name }}</p><p class="mt-1 text-[10px] uppercase text-slate-500">{{ item.ballot_dimension }}</p></td>
                                            <td class="px-4 py-3 text-right font-semibold text-cyan-200">{{ formatNumber(item.automatic_votes) }}</td>
                                            <td class="px-4 py-3"><input v-model.number="recapForm.items[index].official_votes" required type="number" min="0" max="99999999999" class="w-40 rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-right text-white"></td>
                                            <td :class="Number(recapForm.items[index]?.official_votes || 0) - item.automatic_votes === 0 ? 'text-emerald-300' : 'text-rose-300'" class="px-4 py-3 text-right font-bold">{{ formatNumber(Number(recapForm.items[index]?.official_votes || 0) - item.automatic_votes) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t border-white/10 bg-white/[.025]">
                                        <tr><td class="px-4 py-3 font-semibold text-white">Suara tidak sah</td><td class="px-4 py-3 text-right text-amber-200">{{ formatNumber(recapPreview.automatic.invalid_votes) }}</td><td class="px-4 py-3"><input v-model.number="recapForm.official_invalid_votes" required type="number" min="0" max="99999999999" class="w-40 rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-right text-white"></td><td class="px-4 py-3 text-right font-bold text-slate-300">{{ formatNumber(Number(recapForm.official_invalid_votes || 0) - recapPreview.automatic.invalid_votes) }}</td></tr>
                                        <tr><td class="px-4 py-3 font-black text-white">Total</td><td class="px-4 py-3 text-right font-black text-white">{{ formatNumber(recapPreview.automatic.total_votes) }}</td><td class="px-4 py-3 font-black text-white">{{ formatNumber(officialTotalVotes) }}</td><td :class="officialTotalVotes === recapPreview.automatic.total_votes ? 'text-emerald-300' : 'text-rose-300'" class="px-4 py-3 text-right font-black">{{ formatNumber(officialTotalVotes - recapPreview.automatic.total_votes) }}</td></tr>
                                    </tfoot>
                                </table>
                            </div>

                            <p v-if="recapForm.errors.items" class="mt-2 text-xs text-rose-300">{{ recapForm.errors.items }}</p>
                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <label class="text-xs text-slate-400">Dokumen rekap resmi <span class="text-rose-300">*</span><input required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-2 block w-full rounded-xl border border-dashed border-white/15 bg-slate-950/50 p-3 text-xs text-slate-300" @change="selectEvidence"></label>
                                <label class="text-xs text-slate-400">Catatan<textarea v-model="recapForm.notes" rows="3" maxlength="2000" class="mt-2 block w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white" placeholder="Nomor berita acara atau penjelasan selisih"></textarea></label>
                            </div>
                            <p v-if="recapForm.errors.evidence" class="mt-2 text-xs text-rose-300">{{ recapForm.errors.evidence }}</p>
                            <p v-if="recapForm.hasErrors && !recapForm.errors.items && !recapForm.errors.evidence" class="mt-2 text-xs text-rose-300">Periksa kembali data rekap yang dimasukkan.</p>
                            <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-400/15 bg-amber-400/[.05] p-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs leading-5 text-amber-100/80">Penyimpanan membuat versi immutable baru. Angka resmi tidak mengganti atau menambah suara TPS.</p>
                                <button type="submit" :disabled="recapForm.processing || !recapForm.evidence" class="shrink-0 rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-slate-950 disabled:cursor-not-allowed disabled:opacity-40">{{ recapForm.processing ? 'Menyimpan…' : 'Simpan pembanding' }}</button>
                            </div>
                        </form>
                        <div v-else-if="!recapPreview.items.length" class="mt-5 rounded-xl border border-amber-400/20 bg-amber-400/[.06] p-4 text-xs text-amber-200">Peserta belum dikonfigurasi pada kontes ini. Rekap pembanding belum dapat disimpan.</div>
                        <div v-else class="mt-5 rounded-xl border border-white/10 bg-white/[.025] p-4 text-xs text-slate-400">Mode baca koordinator. Hanya Super Admin yang dapat menyimpan versi rekap pembanding.</div>
                    </template>
                </template>
            </section>

            <section class="glass-panel mt-5 rounded-2xl p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-blue-400">Alur rekapitulasi</p><h2 class="mt-1 font-bold text-white">Satu sumber suara, agregasi berjenjang</h2></div><span class="rounded-full bg-emerald-400/10 px-3 py-1 text-[10px] font-bold text-emerald-300">Anti hitung ganda</span></div>
                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-blue-400/25 bg-blue-400/[.08] p-4"><p class="text-[10px] font-bold text-blue-300">01 · TPS</p><p class="mt-2 text-sm font-bold text-white">Input & persetujuan</p><p class="mt-1 text-[10px] leading-4 text-slate-400">Sumber angka dan foto bukti.</p></article>
                    <article class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-[10px] font-bold text-cyan-300">02 · Distrik</p><p class="mt-2 text-sm font-bold text-white">TPS disetujui</p><p class="mt-1 text-[10px] leading-4 text-slate-400">Rekap resmi menjadi pembanding.</p></article>
                    <article class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-[10px] font-bold text-cyan-300">03 · Kabupaten/Kota</p><p class="mt-2 text-sm font-bold text-white">Rekap distrik</p><p class="mt-1 text-[10px] leading-4 text-slate-400">Selisih pembanding ditandai.</p></article>
                    <article class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-[10px] font-bold text-cyan-300">04 · Provinsi</p><p class="mt-2 text-sm font-bold text-white">Rekap kabupaten/kota</p><p class="mt-1 text-[10px] leading-4 text-slate-400">Tidak menambah suara baru.</p></article>
                </div>
            </section>
        </template>

        <section class="glass-panel mt-5 overflow-hidden rounded-2xl">
            <div class="border-b border-white/10 px-5 py-4"><h2 class="font-bold text-white">Riwayat laporan dalam scope</h2><p class="mt-1 text-xs text-slate-500">Laporan terkirim tidak dapat ditimpa. Koreksi akan menjadi versi baru.</p></div>
            <div v-if="submissions.length === 0" class="px-6 py-14 text-center text-sm text-slate-500">Belum ada laporan pada event ini.</div>
            <div v-else class="divide-y divide-white/5">
                <Link v-for="item in submissions" :key="item.id" :href="route('reports.show', item.id)" class="grid gap-2 px-5 py-4 hover:bg-white/[.035] md:grid-cols-[1.4fr_1fr_.7fr_.7fr_auto] md:items-center">
                    <div><p class="text-sm font-semibold text-white">{{ item.tps }} · {{ item.area }}</p><p class="text-xs text-slate-500">{{ item.contest }} · oleh {{ item.submitter }}</p></div>
                    <p class="text-xs text-slate-400">{{ item.entry_mode === 'admin_proxy' ? 'Input proxy admin' : 'Input langsung' }}</p>
                    <p class="text-xs text-slate-400">Versi {{ item.version }}</p>
                    <span :class="item.status === 'draft' ? 'text-amber-300' : 'text-emerald-300'" class="text-xs font-semibold">{{ statusLabel(item.status) }}</span>
                    <span class="text-xs text-blue-300">Detail →</span>
                </Link>
            </div>
        </section>

        <section v-if="canViewRecaps" class="glass-panel mt-5 overflow-hidden rounded-2xl">
            <div class="border-b border-white/10 px-5 py-4"><h2 class="font-bold text-white">Riwayat rekap pembanding</h2><p class="mt-1 text-xs text-slate-500">Setiap versi menyimpan snapshot agregasi TPS dan checksum dokumen resmi.</p></div>
            <div v-if="recapComparisons.length === 0" class="px-6 py-12 text-center text-sm text-slate-500">Belum ada rekap pembanding pada event ini.</div>
            <div v-else class="divide-y divide-white/5">
                <article v-for="item in recapComparisons" :key="item.id" class="grid gap-3 px-5 py-4 md:grid-cols-[1.4fr_.7fr_.8fr_.8fr_auto] md:items-center">
                    <div><p class="text-sm font-semibold text-white">{{ item.region }} · versi {{ item.version }}</p><p class="mt-1 text-xs text-slate-500">{{ item.contest }} · {{ item.verified_tps_count }} TPS · {{ item.snapshot_at }}</p></div>
                    <span :class="item.has_discrepancy ? 'text-rose-300' : 'text-emerald-300'" class="text-xs font-bold">{{ item.has_discrepancy ? 'Ada selisih' : 'Sesuai' }}</span>
                    <p class="text-xs text-slate-400">TPS {{ formatNumber(item.automatic_total_votes) }}<br>Resmi {{ formatNumber(item.official_total_votes) }}</p>
                    <p :class="item.delta === 0 ? 'text-emerald-300' : 'text-rose-300'" class="text-xs font-bold">Selisih {{ formatNumber(item.delta) }}</p>
                    <a :href="route('recap-comparisons.evidence', item.id)" class="text-xs font-semibold text-blue-300 hover:text-blue-200">Unduh bukti →</a>
                </article>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
