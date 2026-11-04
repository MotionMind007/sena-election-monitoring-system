<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

type ScopeRegion = { id: number; code: string; name: string; level: string };
type District = { id: number; name: string; parent_id: number; parent?: { id: number; name: string } };
type Party = { id: number; name: string; abbreviation: string; logo_path?: string | null; active: boolean };
type Dapil = { id: number; name: string; regions: District[] };
type ElectionParty = { id: number; ballot_number: string; party: Party; electoral_districts: Dapil[] };
type Participant = { id: number; election_contest_id: number; participant_type: 'pair' | 'party' | 'candidate'; ballot_number: string; display_name: string; display_order: number; active: boolean; photo_path?: string | null; supporting_parties: Party[]; election_party?: ElectionParty | null; electoral_district?: Dapil | null };
type Contest = { id: number; name: string; type: string; status: string; ballot_schema_version?: string | null; participants: Participant[] };
type Election = { id: number; name: string; year: number; type: 'pilkada' | 'pileg'; status: string; scope_region: ScopeRegion; contests: Contest[]; electoral_districts: Dapil[]; election_parties: ElectionParty[] };

const props = defineProps<{ events: Election[]; scopeRegions: ScopeRegion[]; districts: District[]; parties: Party[]; eventStatuses: string[] }>();
const page = usePage();
const form = useForm({ name: '', year: new Date().getFullYear(), type: 'pilkada', scope_region_id: '' as number | string });
const partyForm = useForm({ name: '', abbreviation: '', logo: null as File | null });
const eventStatusValues = ref<Record<number, string>>({});
const contestStatuses = ref<Record<number, string>>({});
const makeDapilForm = () => useForm({ name: '', region_ids: [] as number[] });
const makeElectionPartyForm = () => useForm({ party_id: '' as number | string, ballot_number: '', electoral_district_ids: [] as number[] });
const makeParticipantForm = (contest: Contest) => useForm({
    ballot_number: '', display_name: '', display_order: contest.participants.filter((item) => item.participant_type !== 'party').length + 1,
    active: true, photo: null as File | null, supporting_party_ids: [] as number[], election_party_id: '' as number | string, electoral_district_id: '' as number | string,
});
const dapilForms = reactive<Record<number, ReturnType<typeof makeDapilForm>>>({});
const electionPartyForms = reactive<Record<number, ReturnType<typeof makeElectionPartyForm>>>({});
const participantForms = reactive<Record<number, ReturnType<typeof makeParticipantForm>>>({});
const editingParticipant = ref<Participant | null>(null);
const editParticipantForm = useForm({ ballot_number: '', display_name: '', display_order: 1, active: true, photo: null as File | null, supporting_party_ids: [] as number[], election_party_id: '' as number | string, electoral_district_id: '' as number | string });

watch(() => props.events, (events) => {
    for (const event of events) {
        eventStatusValues.value[event.id] = event.status;
        if (event.type === 'pileg') {
            dapilForms[event.id] ??= makeDapilForm();
            electionPartyForms[event.id] ??= makeElectionPartyForm();
        }
        for (const contest of event.contests) {
            contestStatuses.value[contest.id] = contest.status;
            participantForms[contest.id] ??= makeParticipantForm(contest);
        }
    }
}, { immediate: true });

const selectedScopeLevel = computed(() => props.scopeRegions.find((region) => region.id === Number(form.scope_region_id))?.level);
const scopeLabel = computed(() => ! selectedScopeLevel.value ? 'Provinsi/Kabupaten/Kota' : (selectedScopeLevel.value === 'province' ? 'Provinsi' : 'Kabupaten/Kota'));
const districtsForEvent = (event: Election) => event.scope_region.level === 'province' ? props.districts : props.districts.filter((district) => district.parent_id === event.scope_region.id);
const nonPartyParticipants = (contest: Contest) => contest.participants.filter((item) => item.participant_type !== 'party');
const partyOptionsForDapil = (event: Election, dapilId: number | string) => event.election_parties.filter((item) => item.electoral_districts.some((dapil) => dapil.id === Number(dapilId)));

const submit = () => form.post(route('admin.events.store'), { preserveScroll: true, onSuccess: () => form.reset('name') });
const createParty = () => partyForm.post(route('admin.parties.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => partyForm.reset() });
const createDapil = (eventId: number) => dapilForms[eventId].post(route('admin.electoral-districts.store', eventId), { preserveScroll: true, onSuccess: () => dapilForms[eventId].reset() });
const createElectionParty = (eventId: number) => electionPartyForms[eventId].post(route('admin.election-parties.store', eventId), { preserveScroll: true, onSuccess: () => electionPartyForms[eventId].reset() });
const createParticipant = (contestId: number) => participantForms[contestId].post(route('admin.participants.store', contestId), { forceFormData: true, preserveScroll: true, onSuccess: () => participantForms[contestId].reset() });
const updateEventStatus = (eventId: number) => router.patch(route('admin.events.status', eventId), { status: eventStatusValues.value[eventId] }, { preserveScroll: true });
const updateContestStatus = (contestId: number) => router.patch(route('admin.contests.update', contestId), { status: contestStatuses.value[contestId] }, { preserveScroll: true });
const beginEditParticipant = (participant: Participant) => {
    editingParticipant.value = participant;
    editParticipantForm.clearErrors();
    editParticipantForm.ballot_number = participant.ballot_number;
    editParticipantForm.display_name = participant.display_name;
    editParticipantForm.display_order = participant.display_order;
    editParticipantForm.active = participant.active;
    editParticipantForm.photo = null;
    editParticipantForm.supporting_party_ids = participant.supporting_parties.map((party) => party.id);
    editParticipantForm.election_party_id = participant.election_party?.id ?? '';
    editParticipantForm.electoral_district_id = participant.electoral_district?.id ?? '';
};
const updateParticipant = () => {
    if (! editingParticipant.value) return;
    editParticipantForm.post(route('admin.participants.update', editingParticipant.value.id), { forceFormData: true, preserveScroll: true, onSuccess: () => editingParticipant.value = null });
};
const deleteParticipant = (participant: Participant) => {
    if (! window.confirm(`Hapus ${participant.participant_type === 'candidate' ? 'caleg' : 'paslon'} \"${participant.display_name}\"?`)) return;
    router.delete(route('admin.participants.destroy', participant.id), { preserveScroll: true, onSuccess: () => { if (editingParticipant.value?.id === participant.id) editingParticipant.value = null; } });
};
const deleteEvent = (event: Election) => {
    if (! window.confirm(`Hapus event \"${event.name}\"? Konfigurasi, penugasan, dan tautan TPS event akan dihapus. Master wilayah dan TPS tetap aman.`)) return;
    router.delete(route('admin.events.destroy', event.id), { preserveScroll: true });
};
const setFile = (target: { logo?: File | null; photo?: File | null }, key: 'logo' | 'photo', event: Event) => target[key] = (event.target as HTMLInputElement).files?.[0] ?? null;
</script>

<template>
    <Head title="Event Pemilihan" />
    <AuthenticatedLayout>
        <div class="mb-6">
            <p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Data & operasional</p>
            <h1 class="mt-1 text-2xl font-bold text-white">Event Pemilihan</h1>
            <p class="mt-1 text-sm text-slate-400">Konfigurasi Pilkada, Pileg, dapil, partai, paslon, dan caleg.</p>
        </div>
        <div v-if="page.props.flash.success" class="mb-4 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ page.props.flash.success }}</div>

        <div class="grid gap-5 xl:grid-cols-[340px_minmax(0,1fr)]">
            <aside class="space-y-5">
                <form class="glass-panel rounded-2xl p-5" @submit.prevent="submit">
                    <h2 class="font-bold text-white">Buat event baru</h2>
                    <div class="mt-4 space-y-4">
                        <label class="block text-xs text-slate-400">Nama event<input v-model="form.name" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white" placeholder="Contoh: Pilkada Papua 2029"></label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="text-xs text-slate-400">Tahun<input v-model.number="form.year" required type="number" min="2000" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"></label>
                            <label class="text-xs text-slate-400">Jenis<select v-model="form.type" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="pilkada">Pilkada</option><option value="pileg">Pileg</option></select></label>
                        </div>
                        <label class="block text-xs text-slate-400">Daerah pemilihan ({{ scopeLabel }})<select v-model="form.scope_region_id" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>Pilih provinsi/kabupaten/kota</option><option v-for="region in scopeRegions" :key="region.id" :value="region.id">{{ region.name }}</option></select></label>
                        <p v-if="Object.keys(form.errors).length" class="text-xs text-rose-300">{{ Object.values(form.errors)[0] }}</p>
                        <button :disabled="form.processing" class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white disabled:opacity-50">Simpan sebagai draft</button>
                    </div>
                </form>

                <form class="glass-panel rounded-2xl p-5" @submit.prevent="createParty">
                    <h2 class="font-bold text-white">Master partai</h2>
                    <p class="mt-1 text-xs text-slate-500">Partai cukup dibuat sekali lalu digunakan pada Pilkada dan Pileg.</p>
                    <div class="mt-4 space-y-3">
                        <input v-model="partyForm.name" required placeholder="Nama partai" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white">
                        <input v-model="partyForm.abbreviation" required placeholder="Singkatan" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white">
                        <label class="block text-xs text-slate-400">Logo partai<input type="file" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full text-xs text-slate-400" @change="setFile(partyForm, 'logo', $event)"></label>
                        <p v-if="Object.keys(partyForm.errors).length" class="text-xs text-rose-300">{{ Object.values(partyForm.errors)[0] }}</p>
                        <button :disabled="partyForm.processing" class="w-full rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-bold text-white">Tambah partai</button>
                    </div>
                    <div v-if="parties.length" class="mt-4 grid grid-cols-2 gap-2">
                        <div v-for="party in parties" :key="party.id" class="flex items-center gap-2 rounded-lg border border-white/10 bg-white/[.025] p-2">
                            <img v-if="party.logo_path" :src="route('parties.logo', party.id)" :alt="party.name" class="h-8 w-8 rounded-md object-contain">
                            <span v-else class="flex h-8 w-8 items-center justify-center rounded-md bg-blue-500/10 text-[10px] font-bold text-blue-300">{{ party.abbreviation }}</span>
                            <span class="truncate text-xs text-slate-300">{{ party.name }}</span>
                        </div>
                    </div>
                </form>
            </aside>

            <section class="min-w-0 space-y-5">
                <div v-if="events.length === 0" class="glass-panel rounded-2xl px-6 py-16 text-center"><p class="font-semibold text-slate-300">Belum ada event</p><p class="mt-1 text-xs text-slate-500">Buat event setelah master wilayah tersedia.</p></div>
                <article v-for="event in events" :key="event.id" class="glass-panel rounded-2xl p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div><div class="flex items-center gap-2"><span class="rounded-full bg-blue-500/10 px-2 py-1 text-[10px] font-bold uppercase text-blue-300">{{ event.type }}</span><h2 class="font-bold text-white">{{ event.name }}</h2></div><p class="mt-1 text-xs text-slate-400">{{ event.year }} · {{ event.scope_region.name }}</p></div>
                        <div class="flex flex-wrap justify-end gap-2"><select v-model="eventStatusValues[event.id]" class="rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option v-for="status in eventStatuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option></select><button class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white" @click="updateEventStatus(event.id)">Simpan status</button><button class="rounded-lg border border-rose-400/40 bg-rose-500/10 px-3 py-2 text-xs font-bold text-rose-200 hover:bg-rose-500/20" @click="deleteEvent(event)">Hapus event</button></div>
                    </div>

                    <div v-if="event.type === 'pileg'" class="mt-5 grid gap-4 border-t border-white/10 pt-5 lg:grid-cols-2">
                        <form class="rounded-xl border border-white/10 bg-slate-950/20 p-4" @submit.prevent="createDapil(event.id)">
                            <h3 class="text-sm font-bold text-white">Tambah dapil</h3>
                            <input v-model="dapilForms[event.id].name" required placeholder="Contoh: Kabupaten Jayapura Dapil 1" class="mt-3 w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white">
                            <p class="mt-3 text-[11px] font-semibold text-slate-300">Cakupan distrik</p>
                            <div class="mt-2 max-h-36 space-y-1 overflow-y-auto rounded-lg border border-white/10 p-2"><label v-for="district in districtsForEvent(event)" :key="district.id" class="flex items-center gap-2 rounded px-2 py-1.5 text-xs text-slate-400 hover:bg-white/5"><input v-model="dapilForms[event.id].region_ids" type="checkbox" :value="district.id">{{ district.name }} <span class="text-slate-600">{{ district.parent?.name }}</span></label></div>
                            <p v-if="Object.keys(dapilForms[event.id].errors).length" class="mt-2 text-xs text-rose-300">{{ Object.values(dapilForms[event.id].errors)[0] }}</p>
                            <button class="mt-3 w-full rounded-lg bg-violet-600 px-3 py-2 text-xs font-bold text-white">Simpan dapil</button>
                        </form>

                        <form class="rounded-xl border border-white/10 bg-slate-950/20 p-4" @submit.prevent="createElectionParty(event.id)">
                            <h3 class="text-sm font-bold text-white">Partai peserta Pileg</h3>
                            <div class="mt-3 grid grid-cols-[1fr_90px] gap-2"><select v-model="electionPartyForms[event.id].party_id" required class="rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option value="" disabled>Pilih partai</option><option v-for="party in parties" :key="party.id" :value="party.id">{{ party.name }}</option></select><input v-model="electionPartyForms[event.id].ballot_number" required placeholder="No. urut" class="rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"></div>
                            <p class="mt-3 text-[11px] font-semibold text-slate-300">Dapil yang diikuti</p>
                            <div class="mt-2 space-y-1 rounded-lg border border-white/10 p-2"><label v-for="dapil in event.electoral_districts" :key="dapil.id" class="flex items-center gap-2 px-2 py-1.5 text-xs text-slate-400"><input v-model="electionPartyForms[event.id].electoral_district_ids" type="checkbox" :value="dapil.id">{{ dapil.name }}</label><p v-if="event.electoral_districts.length === 0" class="px-2 py-3 text-xs text-slate-600">Buat dapil terlebih dahulu.</p></div>
                            <p v-if="Object.keys(electionPartyForms[event.id].errors).length" class="mt-2 text-xs text-rose-300">{{ Object.values(electionPartyForms[event.id].errors)[0] }}</p>
                            <button class="mt-3 w-full rounded-lg bg-cyan-700 px-3 py-2 text-xs font-bold text-white">Daftarkan partai</button>
                        </form>
                    </div>

                    <div v-if="event.type === 'pileg' && event.electoral_districts.length" class="mt-4 flex flex-wrap gap-2"><div v-for="dapil in event.electoral_districts" :key="dapil.id" class="rounded-lg border border-white/10 bg-white/[.025] px-3 py-2"><p class="text-xs font-bold text-slate-200">{{ dapil.name }}</p><p class="mt-1 text-[10px] text-slate-500">{{ dapil.regions.map((region) => region.name).join(', ') }}</p></div></div>

                    <div v-for="contest in event.contests" :key="contest.id" class="mt-5 border-t border-white/10 pt-5">
                        <div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="text-sm font-bold text-white">{{ contest.name }}</h3><p class="text-[10px] uppercase tracking-wider text-slate-500">{{ contest.ballot_schema_version }}</p></div><div class="flex gap-2"><select v-model="contestStatuses[contest.id]" class="rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option value="draft">draft</option><option value="active">active</option><option value="closed">closed</option></select><button class="rounded-lg bg-slate-700 px-3 py-2 text-xs font-bold text-white" @click="updateContestStatus(contest.id)">Simpan</button></div></div>

                        <div v-if="nonPartyParticipants(contest).length" class="mt-4 grid gap-3 md:grid-cols-2"><div v-for="participant in nonPartyParticipants(contest)" :key="participant.id" class="flex gap-3 rounded-xl border border-white/10 bg-white/[.025] p-3"><div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-blue-500/10"><img v-if="participant.photo_path" :src="route('participants.photo', participant.id)" :alt="participant.display_name" class="h-full w-full object-cover object-top"><span v-else class="flex h-full items-center justify-center text-xs font-bold text-blue-300">{{ participant.ballot_number }}</span></div><div class="min-w-0 flex-1"><p class="text-xs text-slate-500">Nomor {{ participant.ballot_number }}</p><p class="truncate text-sm font-bold text-white">{{ participant.display_name }}</p><p v-if="participant.participant_type === 'candidate'" class="mt-1 text-xs text-cyan-300">{{ participant.election_party?.party.abbreviation }} · {{ participant.electoral_district?.name }}</p><p v-else class="mt-1 text-xs text-slate-400">{{ participant.supporting_parties.length ? participant.supporting_parties.map((party) => party.abbreviation).join(', ') : 'Independen / nonpartai' }}</p><div class="mt-3 flex gap-2"><button class="rounded-md bg-blue-500/15 px-2 py-1 text-[10px] font-bold text-blue-200" @click="beginEditParticipant(participant)">Edit</button><button class="rounded-md bg-rose-500/10 px-2 py-1 text-[10px] font-bold text-rose-200" @click="deleteParticipant(participant)">Hapus</button></div></div></div></div>

                        <form v-if="editingParticipant && editingParticipant.election_contest_id === contest.id" class="mt-4 rounded-xl border border-blue-400/30 bg-blue-500/[.05] p-4" @submit.prevent="updateParticipant">
                            <div class="flex items-center justify-between gap-3"><h4 class="text-xs font-bold text-blue-100">Edit {{ editingParticipant.participant_type === 'candidate' ? 'caleg' : 'paslon' }}</h4><button type="button" class="text-xs text-slate-400" @click="editingParticipant = null">Batal</button></div>
                            <div class="mt-3 grid gap-2 md:grid-cols-[90px_1fr_80px_1fr]"><label class="text-[10px] text-slate-500">Nomor urut<input v-model="editParticipantForm.ballot_number" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-2 py-2 text-xs text-white"></label><label class="text-[10px] text-slate-500">Nama<input v-model="editParticipantForm.display_name" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-2 py-2 text-xs text-white"></label><label class="text-[10px] text-slate-500">Urutan<input v-model.number="editParticipantForm.display_order" required type="number" min="1" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-2 py-2 text-xs text-white"></label><label class="text-[10px] text-slate-500">Ganti foto<input type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-[10px] text-slate-400" @change="setFile(editParticipantForm, 'photo', $event)"></label></div>
                            <div v-if="event.type === 'pileg'" class="mt-3 grid gap-2 md:grid-cols-2"><label class="text-[10px] text-slate-500">Dapil<select v-model="editParticipantForm.electoral_district_id" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option v-for="dapil in event.electoral_districts" :key="dapil.id" :value="dapil.id">{{ dapil.name }}</option></select></label><label class="text-[10px] text-slate-500">Partai<select v-model="editParticipantForm.election_party_id" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option v-for="entry in partyOptionsForDapil(event, editParticipantForm.electoral_district_id)" :key="entry.id" :value="entry.id">{{ entry.ballot_number }} · {{ entry.party.name }}</option></select></label></div>
                            <div v-else class="mt-3"><p class="text-[10px] font-semibold text-slate-400">Partai pendukung</p><div class="mt-2 flex flex-wrap gap-2"><label v-for="party in parties" :key="party.id" class="rounded-lg border border-white/10 px-2 py-1.5 text-xs text-slate-400"><input v-model="editParticipantForm.supporting_party_ids" type="checkbox" :value="party.id" class="mr-2">{{ party.abbreviation }}</label></div></div>
                            <label class="mt-3 flex items-center gap-2 text-xs text-slate-300"><input v-model="editParticipantForm.active" type="checkbox"> Kandidat aktif</label><p v-if="Object.keys(editParticipantForm.errors).length" class="mt-2 text-xs text-rose-300">{{ Object.values(editParticipantForm.errors)[0] }}</p><button :disabled="editParticipantForm.processing" class="mt-3 rounded-lg bg-blue-600 px-4 py-2 text-xs font-bold text-white">Simpan perubahan</button>
                        </form>

                        <form class="mt-4 rounded-xl border border-dashed border-blue-400/20 p-4" @submit.prevent="createParticipant(contest.id)">
                            <h4 class="text-xs font-bold text-slate-200">{{ event.type === 'pileg' ? 'Tambah caleg' : 'Tambah paslon' }}</h4>
                            <div class="mt-3 grid gap-2 md:grid-cols-[90px_1fr_80px_1fr_auto] md:items-end"><label class="text-[10px] text-slate-500">Nomor urut<input v-model="participantForms[contest.id].ballot_number" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-2 py-2 text-xs text-white"></label><label class="text-[10px] text-slate-500">{{ event.type === 'pileg' ? 'Nama caleg' : 'Nama paslon' }}<input v-model="participantForms[contest.id].display_name" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"></label><label class="text-[10px] text-slate-500">Urutan<input v-model.number="participantForms[contest.id].display_order" required type="number" min="1" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-2 py-2 text-xs text-white"></label><label class="text-[10px] text-slate-500">Foto<input type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-[10px] text-slate-400" @change="setFile(participantForms[contest.id], 'photo', $event)"></label><button class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white">Tambah</button></div>
                            <div v-if="event.type === 'pileg'" class="mt-3 grid gap-2 md:grid-cols-2"><label class="text-[10px] text-slate-500">Dapil<select v-model="participantForms[contest.id].electoral_district_id" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option value="" disabled>Pilih satu dapil</option><option v-for="dapil in event.electoral_districts" :key="dapil.id" :value="dapil.id">{{ dapil.name }}</option></select></label><label class="text-[10px] text-slate-500">Partai<select v-model="participantForms[contest.id].election_party_id" required class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-xs text-white"><option value="" disabled>Pilih partai peserta dapil</option><option v-for="entry in partyOptionsForDapil(event, participantForms[contest.id].electoral_district_id)" :key="entry.id" :value="entry.id">{{ entry.ballot_number }} · {{ entry.party.name }}</option></select></label></div>
                            <div v-else class="mt-3"><p class="text-[10px] font-semibold text-slate-400">Partai pendukung <span class="font-normal text-slate-600">(kosongkan untuk independen)</span></p><div class="mt-2 flex flex-wrap gap-2"><label v-for="party in parties" :key="party.id" class="rounded-lg border border-white/10 px-2 py-1.5 text-xs text-slate-400"><input v-model="participantForms[contest.id].supporting_party_ids" type="checkbox" :value="party.id" class="mr-2">{{ party.abbreviation }}</label></div></div>
                            <p v-if="Object.keys(participantForms[contest.id].errors).length" class="mt-2 text-xs text-rose-300">{{ Object.values(participantForms[contest.id].errors)[0] }}</p>
                        </form>
                    </div>
                </article>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
