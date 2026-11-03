<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ canResetPassword?: boolean; status?: string }>();

const form = useForm({ email: '', password: '', remember: false });

const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <GuestLayout>
        <Head title="Masuk" />

        <div class="mb-7 text-center">
            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-blue-400">Portal petugas</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-white">Selamat datang kembali</h1>
            <p class="mt-2 text-sm leading-6 text-slate-400">Masuk menggunakan akun yang diberikan administrator.</p>
        </div>

        <div v-if="status" class="mb-5 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ status }}</div>

        <form @submit.prevent="submit">
            <div>
                <label for="email" class="text-sm font-medium text-slate-200">Email</label>
                <input id="email" v-model="form.email" type="email" required autofocus autocomplete="username" placeholder="nama@organisasi.id" class="focus-ring mt-2 block w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-sm text-white placeholder:text-slate-600" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-5">
                <div class="flex items-center justify-between">
                    <label for="password" class="text-sm font-medium text-slate-200">Kata sandi</label>
                    <Link v-if="canResetPassword" :href="route('password.request')" class="focus-ring rounded text-xs text-blue-300 hover:text-blue-200">Lupa kata sandi?</Link>
                </div>
                <input id="password" v-model="form.password" type="password" required autocomplete="current-password" placeholder="Masukkan kata sandi" class="focus-ring mt-2 block w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-sm text-white placeholder:text-slate-600" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <label class="mt-5 flex cursor-pointer items-center gap-3 text-sm text-slate-400">
                <Checkbox v-model:checked="form.remember" name="remember" class="rounded border-white/20 bg-slate-950/60 text-blue-500 focus:ring-blue-400" />
                Ingat saya di perangkat ini
            </label>

            <button type="submit" :disabled="form.processing" class="focus-ring mt-7 flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-950/40 transition hover:brightness-110 disabled:cursor-wait disabled:opacity-60">
                {{ form.processing ? 'Memeriksa…' : 'Masuk ke SENA' }}
            </button>

            <p class="mt-5 text-center text-xs leading-5 text-slate-400">Akses dicatat untuk keamanan. Hubungi administrator jika akun bermasalah.</p>
        </form>
    </GuestLayout>
</template>
