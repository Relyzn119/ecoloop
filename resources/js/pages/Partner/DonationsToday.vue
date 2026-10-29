<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, PackageSearch } from 'lucide-vue-next';

interface Donation {
    id: number;
    product_name: string;
    sku: string;
    batch_code: string;
    quantity: number;
    unit: string;
    occurred_at: string | null;
    stock_decision_pending: boolean;
}

const props = defineProps<{
    donations: {
        data: Donation[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();

const dateTime = (value: string | null) => value
    ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Donasi Hari Ini" />
    <AppLayout :breadcrumbs="[{ title: 'Donasi Hari Ini', href: '/partner/donations/today' }]">
        <main class="space-y-6 p-6 lg:p-10">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-2">
                    <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">Portal Donation Partner</p>
                    <h1 class="text-3xl font-bold">Produk donasi hari ini</h1>
                    <p class="max-w-3xl text-sm text-muted-foreground">Aktivitas donasi yang tercatat hari ini. Gunakan informasi batch dan jumlah untuk koordinasi pengambilan dengan supermarket.</p>
                </div>
                <Link href="/partner/donations/history" class="rounded-lg border px-4 py-2 text-sm font-semibold hover:bg-muted">Riwayat Donasi</Link>
            </header>

            <section v-if="donations.data.length" class="overflow-x-auto rounded-2xl border bg-card shadow-sm">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
                        <tr><th class="px-4 py-3">Produk</th><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Jumlah</th><th class="px-4 py-3">Waktu (WIB)</th><th class="px-4 py-3">Status pencatatan stok</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="donation in donations.data" :key="donation.id">
                            <td class="px-4 py-4"><p class="font-semibold">{{ donation.product_name }}</p><p class="text-xs text-muted-foreground">SKU {{ donation.sku }}</p></td>
                            <td class="px-4 py-4">{{ donation.batch_code }}</td>
                            <td class="px-4 py-4 font-semibold">{{ donation.quantity }} {{ donation.unit }}</td>
                            <td class="px-4 py-4">{{ dateTime(donation.occurred_at) }}</td>
                            <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="donation.stock_decision_pending ? 'bg-amber-500/10 text-amber-700' : 'bg-emerald-500/10 text-emerald-700'">{{ donation.stock_decision_pending ? 'Menunggu rekonsiliasi' : 'Tercatat' }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-else class="rounded-2xl border border-dashed p-10 text-center">
                <PackageSearch class="mx-auto size-10 text-muted-foreground" />
                <h2 class="mt-3 font-semibold">Belum ada produk donasi tercatat hari ini</h2>
                <p class="mt-1 text-sm text-muted-foreground">Produk donasi akan muncul di sini setelah tercatat oleh EcoLoop.</p>
            </section>

            <nav v-if="donations.links.length > 3" class="flex flex-wrap gap-2" aria-label="Navigasi halaman">
                <Link v-for="(link, index) in donations.links" :key="index" :href="link.url || '#'" preserve-scroll class="rounded-md border px-3 py-2 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : 'bg-background'" v-html="link.label" />
            </nav>
            <div class="flex items-center gap-2 text-xs text-muted-foreground"><CalendarDays class="size-4" />{{ donations.total }} catatan donasi hari ini</div>
        </main>
    </AppLayout>
</template>
