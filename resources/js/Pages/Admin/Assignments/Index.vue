<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

type User = { id: number; name: string; email: string; role: 'coordinator' | 'witness' };
type Election = { id: number; name: string; year: number };
type Region = { id: number; code: string; name: string; level: string };
type EventTps = { id: number; election_id: number; polling_station: { code: string; number: number; region: { name: string } } };
type Assignment = { id: number; role: string; user: User; election: Election };

const props = defineProps<{ users: User[]; events: Election[]; regions: Region[]; eventTps: EventTps[]; assignments: Assignment[] }>();
const page = usePage();
const userForm = useForm({ name: '', email: '', role: 'coordinator', password: '', password_confirmation: '' });
const assignmentForm = useForm({ user_id: '', election_id: '', scope_type: 'region', region_id: '', election_polling_station_id: '' });
const targetUser = computed(() => props.users.find((user) => user.id === Number(assignmentForm.user_id)));
const availableTps = computed(() => props.eventTps.filter((tps) => tps.election_id === Number(assignmentForm.election_id)));
watch(targetUser, (user) => {
    assignmentForm.scope_type = user?.role === 'witness' ? 'tps' : 'region';
    assignmentForm.region_id = '';
    assignmentForm.election_polling_station_id = '';
});
const createUser = () => userForm.post(route('admin.users.store'), { onSuccess: () => userForm.reset() });
const assign = () => assignmentForm.post(route('admin.assignments.store'));
</script>

<template>
    <Head title="Penugasan" />
    <AuthenticatedLayout>
        <div class="mb-6"><p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Akses internal</p><h1 class="mt-1 text-2xl font-bold text-white">Pengguna & Penugasan</h1><p class="mt-1 text-sm text-slate-400">Koordinator ditugaskan ke wilayah; saksi ditugaskan langsung ke TPS.</p></div>
        <div v-if="page.props.flash.success" class="mb-4 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ page.props.flash.success }}</div>

        <div class="grid gap-5 xl:grid-cols-2">
            <form class="glass-panel rounded-2xl p-5" @submit.prevent="createUser"><h2 class="font-bold text-white">Buat akun internal</h2><div class="mt-4 grid gap-3"><label class="text-xs text-slate-400">Nama<input v-model="userForm.name" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white" /></label><label class="text-xs text-slate-400">Email<input v-model="userForm.email" type="email" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white" /></label><label class="text-xs text-slate-400">Role<select v-model="userForm.role" class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="coordinator">Koordinator</option><option value="witness">Saksi</option></select></label><div class="grid grid-cols-2 gap-3"><label class="text-xs text-slate-400">Kata sandi sementara<input v-model="userForm.password" type="password" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white" /></label><label class="text-xs text-slate-400">Konfirmasi<input v-model="userForm.password_confirmation" type="password" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white" /></label></div></div><p v-if="Object.keys(userForm.errors).length" class="mt-3 text-xs text-rose-300">{{ Object.values(userForm.errors)[0] }}</p><button :disabled="userForm.processing" class="focus-ring mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">Buat akun</button></form>

            <form class="glass-panel rounded-2xl p-5" @submit.prevent="assign"><h2 class="font-bold text-white">Buat penugasan</h2><div class="mt-4 grid gap-3"><label class="text-xs text-slate-400">Petugas<select v-model="assignmentForm.user_id" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>Pilih petugas</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} · {{ user.role }}</option></select></label><label class="text-xs text-slate-400">Event<select v-model="assignmentForm.election_id" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>Pilih event</option><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }} · {{ event.year }}</option></select></label><template v-if="targetUser?.role === 'coordinator'"><input v-model="assignmentForm.scope_type" type="hidden" value="region" /><label class="text-xs text-slate-400">Wilayah<select v-model="assignmentForm.region_id" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>Pilih wilayah</option><option v-for="region in regions" :key="region.id" :value="region.id">{{ region.code }} — {{ region.name }}</option></select></label></template><template v-else-if="targetUser?.role === 'witness'"><input v-model="assignmentForm.scope_type" type="hidden" value="tps" /><label class="text-xs text-slate-400">TPS<select v-model="assignmentForm.election_polling_station_id" required class="focus-ring mt-2 w-full rounded-xl border border-white/10 bg-slate-950/50 px-3 py-2.5 text-sm text-white"><option value="" disabled>{{ availableTps.length ? 'Pilih TPS' : 'Belum ada TPS pada event' }}</option><option v-for="tps in availableTps" :key="tps.id" :value="tps.id">{{ tps.polling_station.code }} · {{ tps.polling_station.region.name }}</option></select></label></template></div><p v-if="Object.keys(assignmentForm.errors).length" class="mt-3 text-xs text-rose-300">{{ Object.values(assignmentForm.errors)[0] }}</p><button :disabled="assignmentForm.processing || !targetUser" class="focus-ring mt-4 rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-40">Simpan penugasan</button></form>
        </div>

        <section class="glass-panel mt-5 rounded-2xl p-5"><h2 class="font-bold text-white">Penugasan event</h2><div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3"><article v-for="item in assignments" :key="item.id" class="rounded-xl border border-white/10 bg-white/[.025] p-4"><p class="text-sm font-semibold text-white">{{ item.user.name }}</p><p class="mt-1 text-xs text-slate-400">{{ item.role }} · {{ item.election.name }} {{ item.election.year }}</p></article><p v-if="assignments.length === 0" class="py-8 text-xs text-slate-600">Belum ada penugasan.</p></div></section>
    </AuthenticatedLayout>
</template>
