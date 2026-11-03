<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Participant = { id: number; ballot_number: string; display_name: string; photo_path?: string | null };
type Draft = { id: number; idempotency_key: string; items: Array<{ participant_id: number; votes: number }>; invalid_votes: number; source_name?: string; source_method?: string; source_notes?: string; evidence_count: number };
type OcrSuggestion = { id: number; confidence: number; numbers: number[] };
const props = defineProps<{ event: { id: number; name: string; year: number }; contest: { id: number; name: string; participants: Participant[] }; station: { id: number; code: string; village: string; district?: string; regency?: string }; draft: Draft | null; isProxy: boolean; ocrSuggestion: OcrSuggestion | null }>();
const previousVotes = new Map(props.draft?.items.map((item) => [item.participant_id, item.votes]) ?? []);
const form = useForm({
    election_id: props.event.id, election_contest_id: props.contest.id, election_polling_station_id: props.station.id,
    intent: 'draft', idempotency_key: props.draft?.idempotency_key ?? crypto.randomUUID(),
    items: props.contest.participants.map((participant) => ({ participant_id: participant.id, votes: previousVotes.get(participant.id) ?? 0 })),
    invalid_votes: props.draft?.invalid_votes ?? 0, evidence: [] as File[], source_name: props.draft?.source_name ?? '', source_method: props.draft?.source_method ?? '', source_notes: props.draft?.source_notes ?? '',
    ocr_job_id: null as number | null, ocr_confirmed: false,
});
const validVotes = computed(() => form.items.reduce((total, item) => total + Number(item.votes || 0), 0));
const totalVotes = computed(() => validVotes.value + Number(form.invalid_votes || 0));
const qualityMessage = ref<string | null>(null);
const pickFile = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.evidence = [];
    qualityMessage.value = null;
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
        qualityMessage.value = 'Ukuran foto melebihi batas 10 MB.';
        return;
    }
    try {
        const bitmap = await createImageBitmap(file);
        const shortEdge = Math.min(bitmap.width, bitmap.height);
        const longEdge = Math.max(bitmap.width, bitmap.height);
        bitmap.close();
        if (shortEdge < 720 || longEdge < 1280) {
            qualityMessage.value = 'Foto terlalu kecil untuk diperiksa. Gunakan minimal 1280 × 720 piksel.';
            return;
        }
        form.evidence = [file];
    } catch {
        qualityMessage.value = 'Foto tidak dapat dibaca. Pilih JPG, PNG, atau WebP yang valid.';
    }
};
const applyOcr = () => {
    if (!props.ocrSuggestion) return;
    props.contest.participants.forEach((_, index) => form.items[index].votes = props.ocrSuggestion!.numbers[index] ?? 0);
    form.invalid_votes = props.ocrSuggestion.numbers[props.contest.participants.length] ?? 0;
    form.ocr_job_id = props.ocrSuggestion.id;
    form.ocr_confirmed = false;
};
const submit = (intent: 'draft' | 'submit') => { form.intent = intent; form.post(route('reports.store'), { forceFormData: true, preserveScroll: true }); };
</script>

<template>
    <Head title="Form Laporan TPS" />
    <AuthenticatedLayout>
        <div class="mx-auto max-w-3xl">
            <Link :href="route('reports.index', { event: event.id })" class="text-xs text-blue-300">← Kembali ke laporan</Link>
            <div class="mt-4"><p class="text-xs font-semibold uppercase tracking-[.2em] text-blue-400">{{ event.name }} · {{ event.year }}</p><h1 class="mt-1 text-2xl font-bold text-white">{{ station.code }} · {{ station.village }}</h1><p class="mt-1 text-sm text-slate-400">{{ station.district }} · {{ station.regency }} · {{ contest.name }}</p></div>

            <form class="mt-5 space-y-4" @submit.prevent="submit('submit')">
                <section v-if="isProxy" class="glass-panel rounded-2xl border border-amber-400/20 p-5">
                    <h2 class="font-bold text-white">Provenance input proxy</h2><p class="mt-1 text-xs text-amber-200/80">Wajib diisi karena laporan dimasukkan atas data saksi dari lapangan.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-xs text-slate-400">Nama sumber/saksi<input v-model="form.source_name" required class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white" /></label><label class="text-xs text-slate-400">Data diterima melalui<select v-model="form.source_method" required class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white"><option value="" disabled>Pilih metode</option><option value="raw_photo">Foto/data mentah</option><option value="physical_form">Form fisik</option></select></label></div>
                    <label class="mt-4 block text-xs text-slate-400">Catatan sumber<textarea v-model="form.source_notes" rows="2" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-white" /></label>
                </section>

                <section v-if="ocrSuggestion" class="glass-panel rounded-2xl border border-cyan-400/20 p-5"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-bold text-white">Saran OCR tersedia</h2><p class="mt-1 text-xs text-slate-400">Confidence {{ ocrSuggestion.confidence.toFixed(1) }}% · Urutan angka: {{ ocrSuggestion.numbers.join(', ') }}</p></div><button type="button" class="rounded-xl bg-cyan-600 px-4 py-2.5 text-xs font-bold text-white" @click="applyOcr">Gunakan sebagai saran</button></div><div v-if="form.ocr_job_id" class="mt-4 rounded-xl border border-amber-400/20 bg-amber-400/10 p-3"><label class="flex items-start gap-3 text-xs text-amber-100"><input v-model="form.ocr_confirmed" type="checkbox" class="mt-0.5" required><span>Saya sudah membandingkan semua angka dengan foto asli dan mengoreksi bila diperlukan. OCR tidak menentukan hasil final.</span></label></div></section>

                <section class="glass-panel rounded-2xl p-5"><h2 class="font-bold text-white">Perolehan suara</h2><div class="mt-4 space-y-3"><label v-for="(participant, index) in contest.participants" :key="participant.id" class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/[.025] p-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-500/15 text-sm font-bold text-blue-300">{{ participant.ballot_number }}</span><span class="min-w-0 flex-1 text-sm font-semibold text-white">{{ participant.display_name }}</span><input v-model.number="form.items[index].votes" type="number" min="0" inputmode="numeric" required :aria-label="`Suara ${participant.display_name}`" class="w-28 rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-right text-base font-bold text-white" /></label></div></section>

                <section class="glass-panel rounded-2xl p-5"><h2 class="font-bold text-white">Rekapitulasi</h2><div class="mt-4 grid grid-cols-3 gap-3 text-center"><div class="rounded-xl bg-white/5 p-3"><p class="text-[10px] text-slate-500">Suara sah</p><p class="mt-1 text-lg font-bold text-white">{{ validVotes.toLocaleString('id-ID') }}</p></div><label class="rounded-xl bg-white/5 p-3 text-[10px] text-slate-500">Tidak sah<input v-model.number="form.invalid_votes" type="number" min="0" inputmode="numeric" required class="mt-1 w-full bg-transparent text-center text-lg font-bold text-white outline-none" /></label><div class="rounded-xl bg-blue-500/10 p-3"><p class="text-[10px] text-blue-300">Total</p><p class="mt-1 text-lg font-bold text-white">{{ totalVotes.toLocaleString('id-ID') }}</p></div></div></section>

                <section class="glass-panel rounded-2xl p-5"><h2 class="font-bold text-white">Foto bukti</h2><p class="mt-1 text-xs text-slate-500">Tepat 1 foto JPG, PNG, atau WebP. Maksimum 10 MB. Foto asli disimpan privat; pratinjau dikompresi agar pemeriksaan tetap cepat.</p><input type="file" accept="image/jpeg,image/png,image/webp" :disabled="Boolean(draft?.evidence_count)" class="mt-4 block w-full rounded-xl border border-dashed border-white/15 bg-white/[.025] p-4 text-xs text-slate-400 disabled:cursor-not-allowed disabled:opacity-50" @change="pickFile"><p v-if="draft?.evidence_count" class="mt-2 text-xs text-emerald-300">Foto bukti sudah tersimpan pada draft. Unduh foto asli tersedia setelah draft dibuka.</p><p v-if="qualityMessage" class="mt-2 text-xs text-rose-300">{{ qualityMessage }}</p><p v-if="form.evidence.length" class="mt-2 text-xs text-blue-300">Foto lolos pemeriksaan awal dan siap dikirim.</p></section>

                <div v-if="Object.keys(form.errors).length" class="rounded-xl border border-rose-400/20 bg-rose-400/10 p-4 text-sm text-rose-200">{{ Object.values(form.errors)[0] }}</div>
                <div class="grid grid-cols-2 gap-3 rounded-2xl border border-white/10 bg-[#071426]/90 p-3 backdrop-blur-xl"><button type="button" :disabled="form.processing" class="rounded-xl border border-white/15 px-4 py-3 text-sm font-bold text-slate-200" @click="submit('draft')">Simpan draft</button><button type="submit" :disabled="form.processing" class="rounded-xl bg-emerald-500 px-4 py-3 text-sm font-bold text-slate-950">Kirim laporan</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
