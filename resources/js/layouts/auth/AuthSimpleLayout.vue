<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <main class="auth-scene min-h-svh w-full min-w-0 overflow-x-hidden px-3 py-4 sm:px-8 sm:py-10">
        <div class="mx-auto grid min-h-[calc(100svh-2rem)] w-full min-w-0 max-w-6xl overflow-hidden rounded-[1.4rem] border border-white/70 bg-[#faf8f2]/80 shadow-[0_28px_90px_rgba(29,38,27,.16)] backdrop-blur-xl sm:min-h-[calc(100svh-5rem)] sm:rounded-[2rem] lg:grid-cols-[.92fr_1.08fr]">
            <section class="auth-story relative hidden flex-col justify-between overflow-hidden bg-[#193b32] p-10 text-[#f2eee5] lg:flex xl:p-14">
                <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_20%_20%,rgba(142,156,117,.45),transparent_45%),radial-gradient(ellipse_at_90%_100%,rgba(195,160,91,.22),transparent_42%)]" />
                <div class="absolute -right-32 top-1/4 size-96 rounded-full border border-white/10" />
                <div class="absolute -right-20 top-[31%] size-72 rounded-full border border-white/10" />
                <Link :href="route('home')" class="relative z-10 inline-flex w-fit rounded-2xl bg-[#f2eee5] px-4 py-3 dark:bg-[#193b32]">
                    <img src="/images/ecoloop-logo-light.png" alt="EcoLoop" class="h-9 w-auto object-contain dark:hidden" /><img src="/images/ecoloop-logo-dark.png" alt="EcoLoop" class="hidden h-9 w-auto object-contain dark:block" />
                </Link>
                <div class="relative z-10 max-w-lg py-12">
                    <p class="mb-5 text-xs font-semibold uppercase tracking-[.22em] text-[#c6cfb3]">Pangan bernilai, setiap hari</p>
                    <h2 class="font-serif text-5xl leading-[.98] tracking-tight xl:text-6xl">Selamatkan pangan.<br /><span class="italic text-[#d7c28d]">Jaga nilainya.</span></h2>
                    <p class="mt-6 max-w-md text-sm leading-7 text-white/70">Kelola produk mendekati kedaluwarsa, ambil keputusan lebih awal, dan hubungkan pangan layak dengan mitra donasi.</p>
                    <div class="mt-9 grid gap-3 text-sm text-white/85">
                        <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-full bg-white/10 text-[#d7c28d]">01</span> Pantau masa simpan dan stok</div>
                        <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-full bg-white/10 text-[#d7c28d]">02</span> Susun markdown secara terarah</div>
                        <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-full bg-white/10 text-[#d7c28d]">03</span> Salurkan pangan lewat mitra</div>
                    </div>
                </div>
                <p class="relative z-10 text-xs text-white/45">EcoLoop · Smart inventory management</p>
            </section>
            <section class="flex w-full min-w-0 items-center justify-center px-2 py-6 sm:px-8 sm:py-8 lg:px-12 xl:px-16">
                <div class="auth-form-panel w-full min-w-0 max-w-xl">
                    <Link :href="route('home')" class="mb-6 flex max-w-full justify-center sm:mb-8 lg:hidden"><img src="/images/ecoloop-logo-light.png" alt="EcoLoop" class="h-10 max-w-full w-auto object-contain dark:hidden" /><img src="/images/ecoloop-logo-dark.png" alt="EcoLoop" class="hidden h-10 max-w-full w-auto object-contain dark:block" /></Link>
                    <div class="mb-6 min-w-0 space-y-2 text-center sm:mb-8 lg:text-left">
                        <p class="text-xs font-semibold uppercase tracking-[.18em] text-[#758263]">EcoLoop · Portal</p>
                        <h1 class="max-w-full break-words font-serif text-3xl font-medium leading-tight tracking-tight text-[#193b32] [overflow-wrap:anywhere] sm:text-[2.8rem]">{{ title }}</h1>
                        <p class="min-w-0 break-words text-sm leading-6 text-muted-foreground [overflow-wrap:anywhere]">{{ description }}</p>
                    </div>
                    <slot />
                </div>
            </section>
        </div>
    </main>
</template>
