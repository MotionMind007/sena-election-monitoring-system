<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type RegionTree = { id: number; name: string; level: string; parent_id?: number | null; parent?: RegionTree | null };
type Region = RegionTree & { source: string; source_version?: string; children_count: number; polling_stations_count: number };
type ImportBatch = { id: number; source: string; source_version: string; original_name: string; status: string; summary: { rows: number; new: number; existing: number } };
type PollingStation = { id: number; location_label: string; source: string; region: RegionTree };
type PaginationLink = { url: string | null; label: string; active: boolean };
type PaginatedRegions = { data: Region[]; current_page: number; last_page: number; from: number | null; to: number | null; total: number; links: PaginationLink[] };
type RegionFilters = { q: string; level: string; regency_id: string | number; source: string };
type FilterOptions = { regencies: RegionTree[]; sources: string[] };
type ManualRegionOptions = { regencies: RegionTree[]; districts: RegionTree[] };

const props = defineProps<{
    regions: PaginatedRegions;
    regionsForSelection: RegionTree[];
    pollingStations: PollingStation[];
    imports: ImportBatch[];
    filters: RegionFilters;
    filterOptions: FilterOptions;
    manualRegionOptions: ManualRegionOptions;
}>();

const page = usePage();
const importForm = useForm<{ source: string; source_version: string; file: File | null }>({ source: 'CSV', source_version: 'Input CSV', file: null });
const manualRegionForm = useForm({ level: '', parent_id: '' as string | number, name: '' });
const tpsForm = useForm({ name: '', province_id: '' as string | number, regency_id: '' as string | number, district_id: '' as string | number, region_id: '' as string | number });
const regionFilters = useForm<RegionFilters>({ ...props.filters });
const editingRegionId = ref<number | null>(null);
const editRegionForm = useForm({ name: '' });

const preview = () => importForm.post(route('admin.geography-imports.preview'), { forceFormData: true, onSuccess: () => importForm.reset('file') });
const commit = (id: number) => router.post(route('admin.geography-imports.commit', id));
const storeManualRegion = () => manualRegionForm.post(route('admin.geography.store'), { preserveScroll: true, onSuccess: () => manualRegionForm.reset() });
const storePollingStation = () => tpsForm.post(route('admin.polling-stations.store'), { preserveScroll: true, onSuccess: () => tpsForm.reset() });
const beginEdit = (region: Region) => { editingRegionId.value = region.id; editRegionForm.name = region.name; editRegionForm.clearErrors(); };
const cancelEdit = () => { editingRegionId.value = null; editRegionForm.reset(); };
const saveEdit = (region: Region) => editRegionForm.patch(route('admin.geography.update', region.id), { preserveScroll: true, onSuccess: cancelEdit });
const removeRegion = (region: Region) => {
    if (window.confirm(`Hapus ${region.name}? Wilayah yang memiliki turunan, TPS, atau dipakai event tidak dapat dihapus.`)) {
        router.delete(route('admin.geography.destroy', region.id), { preserveScroll: true });
    }
};
const applyFilters = () => regionFilters.get(route('admin.geography.index'), { preserveState: true, preserveScroll: true, replace: true });

const resetFilters = () => {
    regionFilters.q = '';
    regionFilters.level = '';
    regionFilters.regency_id = '';
    regionFilters.source = '';
    applyFilters();
};

const paginationLabel = (label: string) => {
    const normalized = label.toLowerCase();
    if (normalized.includes('previous') || normalized.includes('sebelumnya')) return '← Sebelumnya';
    if (normalized.includes('next') || normalized.includes('berikutnya')) return 'Berikutnya →';
    return label;
};

const levelLabel = (level: string) => ({ province: 'Provinsi', regency: 'Kabupaten', city: 'Kota', district: 'Distrik', village: 'Kelurahan/Kampung' }[level] ?? level);

const manualParentOptions = computed(() => {
    if (manualRegionForm.level === 'district') return props.manualRegionOptions.regencies;
    if (manualRegionForm.level === 'village') return props.manualRegionOptions.districts;
    return [];
});
const manualNeedsParent = computed(() => manualRegionForm.level !== '');
const manualParentLabel = computed(() => {
    if (manualRegionForm.level === 'district') return 'Kabupaten/Kota induk';
    if (manualRegionForm.level === 'village') return 'Distrik induk';
    return 'Induk wilayah';
});

const tpsProvinces = computed(() => props.regionsForSelection.filter((region) => region.level === 'province'));
const tpsRegencies = computed(() => props.regionsForSelection.filter((region) => ['regency', 'city'].includes(region.level) && region.parent_id === Number(tpsForm.province_id)));
const tpsDistricts = computed(() => props.regionsForSelection.filter((region) => region.level === 'district' && region.parent_id === Number(tpsForm.regency_id)));
const tpsVillages = computed(() => props.regionsForSelection.filter((region) => region.level === 'village' && region.parent_id === Number(tpsForm.district_id)));

const pollingStationPath = (station: PollingStation) => {
    const names: string[] = [];
    let region: RegionTree | null | undefined = station.region;
    while (region) {
        names.push(region.name);
        region = region.parent;
    }
    return names.reverse().join(' / ');
};

watch(() => regionFilters.level, () => { regionFilters.source = ''; });
watch(() => manualRegionForm.level, () => { manualRegionForm.parent_id = ''; });
watch(() => tpsForm.province_id, () => { tpsForm.regency_id = ''; tpsForm.district_id = ''; tpsForm.region_id = ''; });
watch(() => tpsForm.regency_id, () => { tpsForm.district_id = ''; tpsForm.region_id = ''; });
watch(() => tpsForm.district_id, () => { tpsForm.region_id = ''; });
</script>

<template>
    <Head title="Wilayah & TPS" />
    <AuthenticatedLayout>
        <div class="mb-6">
            <p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Master data</p>
            <h1 class="mt-1 text-2xl font-bold text-white">Wilayah & TPS</h1>
            <p class="mt-1 text-sm text-slate-400">Bangun hierarki wilayah secara manual atau melalui CSV, lalu tambahkan master TPS pada kelurahan/kampung.</p>
        </div>
        <div v-if="page.props.flash.success" class="mb-4 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ page.props.flash.success }}</div>

        <div class="grid gap-5 xl:grid-cols-2">
            <form class="glass-panel rounded-2xl p-5" @submit.prevent="preview">
                <h2 class="font-bold text-white">Import master wilayah</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">CSV tanpa kode wilayah. Header: <code>provinsi,kabupaten_kota,distrik,kelurahan_kampung</code>. Setiap baris berisi jalur wilayah dari atas ke bawah.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="text-xs text-slate-400">Sumber<select v-model="importForm.source" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="CSV">CSV internal</option><option value="BPS">BPS</option><option value="KPU">KPU</option></select></label>
                    <label class="text-xs text-slate-400">Keterangan sumber<input v-model="importForm.source_version" required placeholder="Contoh: Master wilayah 2029" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white" /></label>
                </div>
                <label class="mt-3 block text-xs text-slate-400">File CSV<input type="file" accept=".csv,text/csv" required class="mt-2 block w-full rounded-xl border border-dashed border-white/15 bg-slate-950/30 p-3 text-xs text-slate-400 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-500/15 file:px-3 file:py-2 file:text-blue-300" @change="importForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
                <p v-if="Object.keys(importForm.errors).length" class="mt-3 text-xs text-rose-300">{{ Object.values(importForm.errors)[0] }}</p>
                <button :disabled="importForm.processing" class="focus-ring mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-500 disabled:opacity-50">Validasi & preview</button>
            </form>

            <form class="glass-panel rounded-2xl p-5" @submit.prevent="storePollingStation">
                <h2 class="font-bold text-white">Tambah TPS</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">Buat master TPS satu per satu, lalu tentukan lokasi wilayahnya secara bertingkat.</p>
                <label class="mt-4 block text-xs text-slate-400">Nama TPS<input v-model="tpsForm.name" required maxlength="100" placeholder="Contoh: TPS 001" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white placeholder:text-slate-600" /></label>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="text-xs text-slate-400">Provinsi<select v-model="tpsForm.province_id" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>Pilih provinsi</option><option v-for="region in tpsProvinces" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Kabupaten/Kota<select v-model="tpsForm.regency_id" required :disabled="!tpsForm.province_id" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white disabled:opacity-40"><option value="" disabled>Pilih kabupaten/kota</option><option v-for="region in tpsRegencies" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Distrik<select v-model="tpsForm.district_id" required :disabled="!tpsForm.regency_id" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white disabled:opacity-40"><option value="" disabled>Pilih distrik</option><option v-for="region in tpsDistricts" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Kelurahan/Kampung<select v-model="tpsForm.region_id" required :disabled="!tpsForm.district_id" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white disabled:opacity-40"><option value="" disabled>Pilih kelurahan/kampung</option><option v-for="region in tpsVillages" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                </div>
                <p v-if="Object.keys(tpsForm.errors).length" class="mt-3 text-xs text-rose-300">{{ Object.values(tpsForm.errors)[0] }}</p>
                <button :disabled="tpsForm.processing || !tpsForm.region_id" class="focus-ring mt-4 rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-40">Simpan TPS</button>
            </form>
        </div>

        <form class="glass-panel mt-5 rounded-2xl p-5" @submit.prevent="storeManualRegion">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="font-bold text-white">Tambah distrik atau kampung</h2><p class="mt-1 text-xs leading-5 text-slate-500">Provinsi Papua, 8 kabupaten, dan 1 kota sudah tersedia. Tambahkan distrik lalu kelurahan/kampung di bawahnya.</p></div>
                <span class="rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-amber-300">Super Admin</span>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <label class="text-xs text-slate-400">Jenis wilayah<select v-model="manualRegionForm.level" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>Pilih jenis wilayah</option><option value="district">Distrik</option><option value="village">Kelurahan/Kampung</option></select></label>
                <label class="text-xs text-slate-400">{{ manualParentLabel }}<select v-model="manualRegionForm.parent_id" :required="manualNeedsParent" :disabled="!manualNeedsParent" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white disabled:cursor-not-allowed disabled:opacity-40"><option value="" disabled>{{ manualNeedsParent ? 'Pilih induk wilayah' : 'Pilih jenis wilayah dahulu' }}</option><option v-for="region in manualParentOptions" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                <label class="text-xs text-slate-400">Nama wilayah<input v-model="manualRegionForm.name" required maxlength="150" placeholder="Contoh: Distrik Sentani" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white placeholder:text-slate-600" /></label>
            </div>
            <p v-if="Object.keys(manualRegionForm.errors).length" class="mt-3 text-xs text-rose-300">{{ Object.values(manualRegionForm.errors)[0] }}</p>
            <button :disabled="manualRegionForm.processing || !manualRegionForm.level || (manualNeedsParent && !manualRegionForm.parent_id)" class="focus-ring mt-4 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40">Simpan wilayah</button>
        </form>

        <section class="glass-panel mt-5 overflow-hidden rounded-2xl">
            <div class="border-b border-white/10 px-5 py-4">
                <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="font-bold text-white">Master wilayah</h2><p class="mt-1 text-xs text-slate-500">Menampilkan {{ regions.from ?? 0 }}–{{ regions.to ?? 0 }} dari {{ regions.total }} wilayah.</p></div><p class="text-xs text-slate-500">Halaman {{ regions.current_page }} dari {{ regions.last_page }}</p></div>
                <form class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.5fr)_1fr_1.2fr_1fr_auto]" @submit.prevent="applyFilters">
                    <label class="text-xs text-slate-400">Cari nama wilayah<input v-model="regionFilters.q" type="search" placeholder="Contoh: Sentani" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white placeholder:text-slate-600" /></label>
                    <label class="text-xs text-slate-400">Level<select v-model="regionFilters.level" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="">Semua level</option><option value="province">Provinsi</option><option value="regency">Kabupaten</option><option value="city">Kota</option><option value="district">Distrik</option><option value="village">Kelurahan/Kampung</option></select></label>
                    <label class="text-xs text-slate-400">Kabupaten/Kota<select v-model="regionFilters.regency_id" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="">Semua kabupaten/kota</option><option v-for="region in filterOptions.regencies" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                    <label class="text-xs text-slate-400">Sumber<select v-model="regionFilters.source" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="">Semua sumber</option><option v-for="source in filterOptions.sources" :key="source" :value="source">{{ source }}</option></select></label>
                    <div class="flex items-end gap-2"><button :disabled="regionFilters.processing" class="focus-ring rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">Terapkan</button><button type="button" class="focus-ring rounded-xl border border-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300" @click="resetFilters">Reset</button></div>
                </form>
            </div>
            <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-xs"><thead class="bg-white/[.025] text-slate-500"><tr><th class="px-5 py-3">Nama</th><th class="px-5 py-3">Level</th><th class="px-5 py-3">Induk</th><th class="px-5 py-3">Anak</th><th class="px-5 py-3">TPS</th><th class="px-5 py-3">Sumber</th><th class="px-5 py-3 text-right">Aksi</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="region in regions.data" :key="region.id" class="text-slate-300"><td class="px-5 py-3 font-medium text-white"><form v-if="editingRegionId === region.id" class="flex items-center gap-2" @submit.prevent="saveEdit(region)"><input v-model="editRegionForm.name" maxlength="150" class="focus-ring w-52 rounded-lg border border-white/10 bg-slate-950/50 px-2.5 py-1.5 text-xs text-white" /><button class="rounded-lg bg-emerald-600 px-2 py-1.5 font-bold text-white" :disabled="editRegionForm.processing">Simpan</button><button type="button" class="rounded-lg border border-white/10 px-2 py-1.5" @click="cancelEdit">Batal</button></form><span v-else>{{ region.name }}</span></td><td class="px-5 py-3">{{ levelLabel(region.level) }}</td><td class="px-5 py-3 text-slate-500">{{ region.parent?.name ?? '—' }}</td><td class="px-5 py-3">{{ region.children_count }}</td><td class="px-5 py-3">{{ region.polling_stations_count }}</td><td class="px-5 py-3 text-slate-500">{{ region.source }}</td><td class="px-5 py-3 text-right"><div v-if="editingRegionId !== region.id" class="flex justify-end gap-2"><button class="rounded-lg border border-white/10 px-2.5 py-1.5 text-blue-300 hover:border-blue-400/40" @click="beginEdit(region)">Ubah</button><button class="rounded-lg border border-rose-400/20 px-2.5 py-1.5 text-rose-300 hover:border-rose-400/50" @click="removeRegion(region)">Hapus</button></div></td></tr><tr v-if="regions.data.length === 0"><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada master wilayah. Tambahkan manual atau import CSV.</td></tr></tbody></table></div>
            <nav v-if="regions.last_page > 1" aria-label="Pagination master wilayah" class="flex flex-wrap items-center justify-center gap-1 border-t border-white/10 px-5 py-4"><Link v-for="link in regions.links" :key="link.label" :href="link.url ?? ''" preserve-scroll preserve-state :aria-label="paginationLabel(link.label)" :class="[link.active ? 'bg-blue-600 text-white' : 'border border-white/10 text-slate-400 hover:border-white/20 hover:text-white', !link.url ? 'pointer-events-none opacity-30' : '']" class="min-w-9 rounded-lg px-3 py-2 text-center text-xs transition"><span class="sm:hidden">{{ paginationLabel(link.label).replace('Sebelumnya', '').replace('Berikutnya', '').trim() || paginationLabel(link.label) }}</span><span class="hidden sm:inline">{{ paginationLabel(link.label) }}</span></Link></nav>
        </section>

        <section v-if="pollingStations.length" class="glass-panel mt-5 overflow-hidden rounded-2xl"><div class="border-b border-white/10 px-5 py-4"><h2 class="font-bold text-white">TPS terbaru</h2><p class="mt-1 text-xs text-slate-500">Daftar master TPS yang terakhir ditambahkan.</p></div><div class="divide-y divide-white/5"><article v-for="station in pollingStations" :key="station.id" class="px-5 py-3"><p class="text-sm font-semibold text-white">{{ station.location_label }}</p><p class="mt-1 text-xs text-slate-500">{{ pollingStationPath(station) }}</p></article></div></section>

        <section class="glass-panel mt-5 rounded-2xl p-5"><h2 class="font-bold text-white">Riwayat import</h2><div class="mt-4 space-y-2"><article v-for="batch in imports" :key="batch.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[.025] p-4"><div><p class="text-sm font-semibold text-white">{{ batch.original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ batch.source }} · {{ batch.source_version }} · {{ batch.summary.rows }} wilayah ({{ batch.summary.new }} baru)</p></div><div class="flex items-center gap-2"><span class="rounded-full bg-white/5 px-2 py-1 text-[10px] uppercase text-slate-400">{{ batch.status }}</span><button v-if="batch.status === 'previewed'" class="focus-ring rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white" @click="commit(batch.id)">Commit</button></div></article><p v-if="imports.length === 0" class="py-8 text-center text-xs text-slate-600">Belum ada batch import.</p></div></section>
    </AuthenticatedLayout>
</template>
