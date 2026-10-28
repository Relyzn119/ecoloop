<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Mail, MapPin, Phone, Store } from 'lucide-vue-next';

interface StoreItem {
    id: number;
    name: string;
    contact_person: string;
    phone: string;
    email: string;
    address: string;
}

defineProps<{
    portalType: 'donation' | 'umkm';
    stores: {
        data: StoreItem[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();
</script>

<template>
    <Head title="Daftar Supermarket dan Toko" />
    <AppLayout :breadcrumbs="[{ title: 'Daftar Supermarket/Toko', href: portalType === 'umkm' ? '/umkm/stores' : '/partner/stores' }]">
        <main class="space-y-6 p-6 lg:p-10">
            <header class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">{{ portalType === 'umkm' ? 'Portal Mitra UMKM' : 'Portal Donation Partner' }}</p>
                <h1 class="text-3xl font-bold">Supermarket/Toko Pemberi Donasi dan Diskon</h1>
                <p class="max-w-3xl text-sm text-muted-foreground">Kontak bisnis aktif untuk menanyakan ketersediaan produk, jadwal pengambilan, atau informasi penawaran.</p>
            </header>

            <section v-if="stores.data.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="store in stores.data" :key="store.id" class="space-y-4 rounded-2xl border bg-card p-5 shadow-sm">
                    <div class="flex items-start gap-3">
                        <span class="rounded-xl bg-emerald-500/10 p-2 text-emerald-700"><Store class="size-5" /></span>
                        <div><h2 class="text-lg font-semibold">{{ store.name }}</h2><p class="text-sm text-muted-foreground">{{ store.contact_person }}</p></div>
                    </div>
                    <div class="space-y-2 text-sm">
                        <a :href="'tel:' + store.phone" class="flex items-center gap-2 text-foreground hover:text-emerald-700"><Phone class="size-4 shrink-0" />{{ store.phone || 'Nomor telepon belum tersedia' }}</a>
                        <a v-if="store.email" :href="'mailto:' + store.email" class="flex items-center gap-2 break-all text-muted-foreground hover:text-emerald-700"><Mail class="size-4 shrink-0" />{{ store.email }}</a>
                        <p class="flex items-start gap-2 text-muted-foreground"><MapPin class="mt-0.5 size-4 shrink-0" /><span>{{ store.address || 'Alamat belum dicantumkan' }}</span></p>
                    </div>
                </article>
            </section>

            <section v-else class="rounded-2xl border border-dashed p-10 text-center">
                <Store class="mx-auto size-10 text-muted-foreground" />
                <h2 class="mt-3 font-semibold">Belum ada supermarket/toko aktif</h2>
                <p class="mt-1 text-sm text-muted-foreground">Daftar akan terisi setelah akun pemberi donasi disetujui Admin EcoLoop.</p>
            </section>

            <nav v-if="stores.links.length > 3" class="flex flex-wrap gap-2" aria-label="Navigasi halaman">
                <Link v-for="(link, index) in stores.links" :key="index" :href="link.url || '#'" preserve-scroll class="rounded-md border px-3 py-2 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : 'bg-background'" v-html="link.label" />
            </nav>
        </main>
    </AppLayout>
</template>
