<template>
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#061326] px-5 py-8 sm:px-8">
        <div class="absolute inset-0 bg-cover bg-center bg-no-repeat" style="background-image: url('/branding/login-background.png')" />
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(7,20,38,.2),rgba(2,8,20,.82))]" />
        <div class="absolute inset-0 bg-slate-950/20 backdrop-blur-[1px]" />

        <section class="glass-panel auth-glass relative z-10 w-full max-w-md rounded-3xl px-6 py-7 shadow-[0_28px_90px_rgba(0,5,18,.5)] sm:px-9 sm:py-8">
            <img
                src="/branding/sena-horizontal.png"
                alt="SENA — Real Count Untuk Kemenangan"
                class="mx-auto h-auto w-52 rounded-xl sm:w-56"
            />
            <div class="mt-6">
                <slot />
            </div>
            <p class="mt-6 text-center text-[11px] leading-5 text-slate-400">
                Internal, sementara, bukan hasil resmi KPU.
            </p>
        </section>
    </main>
</template>
