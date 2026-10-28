<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Clock3 } from 'lucide-vue-next';

defineProps<{
    partner: { name: string; partner_type: 'foundation' | 'umkm' | 'business_owner'; is_active: boolean } | null;
}>();
</script>

<template>
    <Head title="Persetujuan Mitra EcoLoop" />
    <AppLayout :breadcrumbs="[{ title: 'Status pendaftaran', href: '/partner/pending' }]">
        <main class="mx-auto max-w-3xl space-y-6 p-6 lg:p-10">
            <section class="rounded-2xl border border-sidebar-border bg-card p-7 shadow-sm">
                <div class="mb-5 flex size-12 items-center justify-center rounded-full bg-amber-500/10 text-amber-700"><Clock3 class="size-6" /></div>
                <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">{{ partner?.partner_type === 'umkm' ? 'Mitra UMKM EcoLoop' : partner?.partner_type === 'business_owner' ? 'Supermarket / Toko — Pemberi Donasi & Diskon' : 'Mitra Yayasan EcoLoop' }}</p>
                <h1 class="mt-2 text-2xl font-bold text-foreground">{{ partner?.name || 'Pendaftaran mitra' }}{{ partner?.is_active ? ' telah disetujui' : ' sedang menunggu persetujuan' }}</h1>
                <p class="mt-3 text-sm leading-6 text-muted-foreground">
                    Admin EcoLoop akan memeriksa profil organisasi dan mengaktifkan akun. Produk dan fitur EcoLoop tidak dapat diakses
                    sampai akun disetujui.
                </p>
                <div class="mt-6 space-y-3 rounded-xl bg-muted/40 p-4 text-sm">
                    <p class="flex items-center gap-2">
                        <component
                            :is="partner?.is_active ? CheckCircle2 : Clock3"
                            class="size-4"
                            :class="partner?.is_active ? 'text-emerald-600' : 'text-amber-600'"
                        />{{
                            partner?.is_active
                            ? (partner?.partner_type === 'umkm' ? 'Akun telah disetujui. Buka menu Penawaran Diskon untuk melihat produk.' : partner?.partner_type === 'business_owner' ? 'Akun supermarket telah disetujui. Buka dashboard untuk mengelola inventaris, batch, donasi, dan diskon.' : 'Akun telah disetujui. Buka menu Portal Mitra untuk mulai scan.')
                                : 'Persetujuan Admin EcoLoop masih menunggu.'
                        }}
                    </p>
                </div>
            </section>
        </main>
    </AppLayout>
</template>
