<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { History, PackageSearch } from 'lucide-vue-next';

interface HistoryItem {
    id: number;
    product_name: string;
    sku: string;
    batch_code: string;
    occurred_at: string | null;
    quantity?: number;
    quantity_available?: number;
    unit: string;
    discount_percentage?: number;
    original_price?: number;
    discount_price?: number;
    expiration_date?: string | null;
    stock_decision_pending?: boolean;
}

const props = defineProps<{
    type: 'donation' | 'discount';
    period: 'month' | 'year';
    items: {
        data: HistoryItem[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();

const basePath = props.type === 'donation' ? '/partner/donations/history' : '/umkm/discount-history';
const money = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const dateTime = (value: string | null) => value
    ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head :title="type === 'donation' ? 'Riwayat Donasi' : 'Riwayat Diskon'" />
    <AppLayout :breadcrumbs="[{ title: type === 'donation' ? 'Riwayat Donasi' : 'Riwayat Diskon', href: basePath }]">
        <main class="space-y-6 p-6 lg:p-10">
            <header class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">{{ type === 'donation' ? 'Portal Donation Partner' : 'Portal Mitra UMKM' }}</p>
                <h1 class="text-3xl font-bold">{{ type === 'donation' ? 'Riwayat Produk Donasi' : 'Riwayat Produk Diskon' }}</h1>
                <p class="max-w-3xl text-sm text-muted-foreground">Lihat catatan produk yang didonasikan atau penawaran diskon yang disetujui dalam rentang waktu pilihan.</p>
            </header>

            <div class="flex flex-wrap gap-2" aria-label="Rentang riwayat">
                <Link :href="basePath + '?period=month'" class="rounded-lg border px-4 py-2 text-sm font-semibold" :class="period === 'month' ? 'border-emerald-700 bg-emerald-700 text-white' : 'hover:bg-muted'">1 bulan terakhir</Link>
                <Link :href="basePath + '?period=year'" class="rounded-lg border px-4 py-2 text-sm font-semibold" :class="period === 'year' ? 'border-emerald-700 bg-emerald-700 text-white' : 'hover:bg-muted'">1 tahun terakhir</Link>
            </div>

            <section v-if="items.data.length" class="overflow-x-auto rounded-2xl border bg-card shadow-sm">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
                        <tr v-if="type === 'donation'"><th class="px-4 py-3">Produk</th><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Jumlah</th><th class="px-4 py-3">Waktu (WIB)</th><th class="px-4 py-3">Pencatatan stok</th></tr>
                        <tr v-else><th class="px-4 py-3">Produk</th><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Diskon</th><th class="px-4 py-3">Harga</th><th class="px-4 py-3">Stok saat ini</th><th class="px-4 py-3">Disetujui</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in items.data" :key="item.id">
                            <template v-if="type === 'donation'">
                                <td class="px-4 py-4"><p class="font-semibold">{{ item.product_name }}</p><p class="text-xs text-muted-foreground">SKU {{ item.sku }}</p></td>
                                <td class="px-4 py-4">{{ item.batch_code }}</td>
                                <td class="px-4 py-4 font-semibold">{{ item.quantity }} {{ item.unit }}</td>
                                <td class="px-4 py-4">{{ dateTime(item.occurred_at) }}</td>
                                <td class="px-4 py-4">{{ item.stock_decision_pending ? 'Menunggu rekonsiliasi' : 'Tercatat' }}</td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-4"><p class="font-semibold">{{ item.product_name }}</p><p class="text-xs text-muted-foreground">SKU {{ item.sku }} · Exp {{ item.expiration_date || '—' }}</p></td>
                                <td class="px-4 py-4">{{ item.batch_code }}</td>
                                <td class="px-4 py-4 font-semibold text-emerald-700">{{ item.discount_percentage }}%</td>
                                <td class="px-4 py-4"><p class="font-semibold">{{ money(item.discount_price || 0) }}</p><p class="text-xs text-muted-foreground line-through">{{ money(item.original_price || 0) }}</p></td>
                                <td class="px-4 py-4">{{ item.quantity_available }} {{ item.unit }}</td>
                                <td class="px-4 py-4">{{ dateTime(item.occurred_at) }}</td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-else class="rounded-2xl border border-dashed p-10 text-center">
                <PackageSearch class="mx-auto size-10 text-muted-foreground" />
                <h2 class="mt-3 font-semibold">Belum ada riwayat pada rentang ini</h2>
                <p class="mt-1 text-sm text-muted-foreground">Catatan donasi atau diskon yang sesuai rentang waktu akan tampil di sini.</p>
            </section>

            <nav v-if="items.links.length > 3" class="flex flex-wrap gap-2" aria-label="Navigasi halaman">
                <Link v-for="(link, index) in items.links" :key="index" :href="link.url || '#'" preserve-scroll class="rounded-md border px-3 py-2 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : 'bg-background'" v-html="link.label" />
            </nav>
            <div class="flex items-center gap-2 text-xs text-muted-foreground"><History class="size-4" />{{ items.total }} catatan dalam {{ period === 'month' ? '1 bulan' : '1 tahun' }} terakhir</div>
        </main>
    </AppLayout>
</template>
