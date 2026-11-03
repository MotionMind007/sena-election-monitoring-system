<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Submission = { id: number; event: string; contest: string; tps: string; area: string; submitter: string; status: string; version: number; total: number; own: boolean };
type PaginationLink = { url: string | null; label: string; active: boolean };
type PaginatedSubmissions = { data: Submission[]; current_page: number; last_page: number; from: number | null; to: number | null; total: number; links: PaginationLink[] };

const props = defineProps<{ submissions: PaginatedSubmissions; filters: { q: string; status: string }; role: string }>();
const query = ref(props.filters.q);
const status = ref(props.filters.status);
const applyFilters = () => router.get(route('verification.index'), { q: query.value || undefined, status: status.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
const paginationLabel = (label: string) => label.replace('&laquo;', '').replace('&raquo;', '').replace('Previous', 'Sebelumnya').replace('Next', 'Berikutnya').trim();
</script>

<template>
    <Head title="Review & Approval" />
    <AuthenticatedLayout>
        <div><p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Verifikasi</p><h1 class="mt-1 text-2xl font-bold text-white">Review & Approval Center</h1><p class="mt-1 text-sm text-slate-400">{{ role === 'super_admin' ? 'Keputusan final seluruh laporan.' : 'Review laporan lain dalam wilayah koordinasi.' }}</p></div>
        <section class="glass-panel mt-5 overflow-hidden rounded-2xl">
            <div class="flex flex-col gap-3 border-b border-white/10 px-5 py-4 lg:flex-row lg:items-end lg:justify-between">
                <div><h2 class="font-bold text-white">Antrian laporan</h2><p class="mt-1 text-xs text-slate-500">{{ submissions.from ?? 0 }}–{{ submissions.to ?? 0 }} dari {{ submissions.total }} laporan</p></div>
                <form class="grid gap-2 sm:grid-cols-[minmax(220px,1fr)_190px_auto]" @submit.prevent="applyFilters">
                    <label class="text-[10px] text-slate-500">Cari TPS, wilayah, atau pengirim<input v-model="query" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs text-white" placeholder="Contoh: TPS 001"></label>
                    <label class="text-[10px] text-slate-500">Status<select v-model="status" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-xs text-white"><option value="">Semua status</option><option value="submitted">Menunggu review</option><option value="recommended">Direkomendasikan</option><option value="revision_requested">Perlu revisi</option><option value="conflict">Konflik</option><option value="approved">Disetujui</option><option value="rejected">Ditolak</option></select></label>
                    <button class="mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-blue-500" type="submit">Terapkan</button>
                </form>
            </div>
            <div v-if="!submissions.data.length" class="p-12 text-center text-slate-500">Belum ada laporan yang cocok.</div>
            <div v-else class="divide-y divide-white/5"><Link v-for="item in submissions.data" :key="item.id" :href="route('reports.show', item.id)" class="grid gap-2 px-5 py-4 hover:bg-white/[.035] md:grid-cols-[1.5fr_1fr_.8fr_.7fr_auto] md:items-center"><div><p class="text-sm font-bold text-white">{{ item.tps }} · {{ item.area }}</p><p class="text-xs text-slate-500">{{ item.event }} · {{ item.contest }}</p></div><p class="text-xs text-slate-300">{{ item.submitter }}<span v-if="item.own" class="text-amber-300"> · input sendiri</span></p><p class="text-xs text-slate-400">Versi {{ item.version }} · {{ item.total }} suara</p><span class="text-xs font-bold uppercase text-blue-300">{{ item.status.replace('_', ' ') }}</span><span class="text-xs text-blue-300">Periksa →</span></Link></div>
            <nav v-if="submissions.last_page > 1" class="flex flex-wrap items-center justify-center gap-1 border-t border-white/10 px-5 py-4" aria-label="Pagination antrian laporan"><Link v-for="link in submissions.links" :key="link.label" :href="link.url ?? ''" preserve-scroll preserve-state :aria-label="paginationLabel(link.label)" :class="[link.active ? 'bg-blue-600 text-white' : 'border border-white/10 text-slate-400 hover:border-white/20 hover:text-white', !link.url ? 'pointer-events-none opacity-30' : '']" class="min-w-9 rounded-lg px-3 py-2 text-center text-xs transition">{{ paginationLabel(link.label) }}</Link></nav>
        </section>
    </AuthenticatedLayout>
</template>
