<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Event = { id: number; name: string; year: number; contests: Array<{ id: number; name: string }> };
const props = defineProps<{ events: Event[]; alerts: Array<{ label: string; count: number; tone: string }>; runtime: Record<string, string> }>();
const eventId = ref(props.events[0]?.id ?? null);
const event = computed(() => props.events.find((item) => item.id === Number(eventId.value)));
const contestId = ref(event.value?.contests[0]?.id ?? null);
const exportHref = computed(() => eventId.value && contestId.value ? route('admin.exports.results', { event: eventId.value, contest: contestId.value }) : '#');
const selectEvent = () => contestId.value = event.value?.contests[0]?.id ?? null;
</script>
<template><Head title="Operasional"/><AuthenticatedLayout><div><p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">Phase 7</p><h1 class="mt-1 text-2xl font-bold text-white">Operasional & Alert</h1><p class="mt-1 text-sm text-slate-400">Export internal, antrian risiko, dan status runtime aplikasi.</p></div>
<section class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><article v-for="alert in alerts" :key="alert.label" class="glass-panel rounded-2xl border border-white/10 p-5"><p class="text-xs text-slate-400">{{ alert.label }}</p><p class="mt-2 text-3xl font-black text-white">{{ alert.count }}</p></article></section>
<div class="mt-5 grid gap-5 xl:grid-cols-2"><section class="glass-panel rounded-2xl p-5"><h2 class="font-bold text-white">Export hasil terverifikasi</h2><p class="mt-1 text-xs text-slate-500">CSV hanya memuat hasil aktif yang telah disetujui Super Admin.</p><div class="mt-4 grid gap-3"><label class="text-xs text-slate-400">Event<select v-model="eventId" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white" @change="selectEvent"><option v-for="item in events" :key="item.id" :value="item.id">{{ item.name }} · {{ item.year }}</option></select></label><label class="text-xs text-slate-400">Kontes<select v-model="contestId" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white"><option v-for="contest in event?.contests ?? []" :key="contest.id" :value="contest.id">{{ contest.name }}</option></select></label><a :href="exportHref" :class="!contestId ? 'pointer-events-none opacity-40' : ''" class="rounded-xl bg-emerald-500 px-4 py-3 text-center text-sm font-bold text-slate-950">Download CSV</a></div></section>
<section class="glass-panel rounded-2xl p-5"><h2 class="font-bold text-white">Runtime</h2><dl class="mt-4 divide-y divide-white/5 text-sm"><div v-for="(value, key) in runtime" :key="key" class="flex justify-between py-3"><dt class="capitalize text-slate-500">{{ key }}</dt><dd class="font-mono text-slate-200">{{ value }}</dd></div></dl><div class="mt-4 rounded-xl border border-blue-400/20 bg-blue-400/10 p-4 text-xs leading-5 text-blue-100">Backup terjadwal setiap 02:00 melalui scheduler. Queue worker dan scheduler wajib dijalankan sebagai service pada production.</div></section></div></AuthenticatedLayout></template>
