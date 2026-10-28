<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { BadgePercent, CalendarDays, PackageSearch } from 'lucide-vue-next';

interface Offer {
    id: number;
    product_name: string;
    sku: string;
    unit: string;
    batch_code: string;
    quantity_available: number;
    original_price: number;
    discount_price: number;
    discount_percentage: number;
    expiration_date: string;
}

defineProps<{
    offers: {
        data: Offer[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();

const money = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
</script>

<template>
    <Head title="Penawaran Diskon UMKM" />
    <AppLayout :breadcrumbs="[{ title: 'Penawaran Diskon', href: '/umkm/offers' }]">
        <main class="space-y-6 p-6 lg:p-10">
            <header class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">Portal Mitra UMKM</p>
                <h1 class="text-3xl font-bold">Produk bahan baku dengan harga diskon</h1>
                <p class="max-w-3xl text-sm text-muted-foreground">Penawaran tersedia untuk dibeli selama stok masih ada. Hubungi supermarket untuk konfirmasi dan pengambilan.</p>
            </header>

            <section v-if="offers.data.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="offer in offers.data" :key="offer.id" class="rounded-2xl border bg-card p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold">{{ offer.product_name }}</h2>
                            <p class="text-xs text-muted-foreground">SKU {{ offer.sku }} · Batch {{ offer.batch_code }}</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-sm font-semibold text-emerald-700"><BadgePercent class="size-4" />{{ offer.discount_percentage }}%</span>
                    </div>
                    <div class="mt-5 space-y-2 text-sm">
                        <p><span class="font-semibold text-emerald-700">{{ money(offer.discount_price) }}</span> <span class="text-muted-foreground line-through">{{ money(offer.original_price) }}</span> / {{ offer.unit }}</p>
                        <p class="flex items-center gap-2 text-muted-foreground"><PackageSearch class="size-4" /> Tersedia: {{ offer.quantity_available }} {{ offer.unit }}</p>
                        <p class="flex items-center gap-2 text-muted-foreground"><CalendarDays class="size-4" /> Kedaluwarsa: {{ offer.expiration_date }}</p>
                    </div>
                </article>
            </section>

            <section v-else class="rounded-2xl border border-dashed p-10 text-center">
                <PackageSearch class="mx-auto size-10 text-muted-foreground" />
                <h2 class="mt-3 font-semibold">Belum ada penawaran diskon aktif</h2>
                <p class="mt-1 text-sm text-muted-foreground">Kami akan memberi tahu Anda melalui email dan Telegram ketika ada produk diskon.</p>
            </section>

            <nav v-if="offers.links.length > 3" class="flex flex-wrap gap-2" aria-label="Navigasi halaman">
                <Link v-for="(link, index) in offers.links" :key="index" :href="link.url || '#'" preserve-scroll class="rounded-md border px-3 py-2 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : 'bg-background'" v-html="link.label" />
            </nav>
        </main>
    </AppLayout>
</template>
