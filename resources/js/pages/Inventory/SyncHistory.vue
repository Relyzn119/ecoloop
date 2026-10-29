<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, CheckCircle2, Clock3, History, PackageCheck, Percent, RefreshCw, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

interface SyncEvent {
    product_id: number;
    product_name: string;
    sku: string;
    batch_code: string;
    unit: string;
    quantity: number;
    stock_before: number;
    stock_after: number;
    stock_change: number;
    expiration_date: string;
    store_code: string | null;
    scenario?: string;
    partner_name?: string;
    notifications_queued?: boolean;
}

interface DiscountRecommendation {
    product_name: string;
    sku: string;
    batch_code: string;
    quantity_available: number;
    unit: string;
    expiration_date: string;
    recommended_percentage: number;
    recommended_price: number;
    status: string;
    demo?: boolean;
}

interface ProductSnapshot {
    id: number;
    name: string;
    sku: string;
    quantity: number;
    unit: string;
    status: string;
}

interface DonationApprovalEvent {
    batch_id: number;
    batch_code: string;
    product_id: number;
    product_name: string;
    quantity_available: number;
    unit: string;
    expiration_date: string;
    manager_actions: number;
}

interface DonationPreAlertEvent extends DonationApprovalEvent {}

interface SyncRun {
    id: number;
    trigger_type: 'auto' | 'sync_now' | 'manual_cli';
    status: 'completed' | 'skipped' | 'failed' | 'running';
    started_at: string;
    completed_at: string | null;
    summary: Record<string, number | string>;
    error_message: string | null;
    details: {
        events?: { received?: SyncEvent[]; sold?: SyncEvent[]; demo_received?: SyncEvent[]; donated?: SyncEvent[]; donation_approvals?: DonationApprovalEvent[]; donation_prealerts?: DonationPreAlertEvent[]; discount_approvals?: DiscountRecommendation[] };
        updated_products?: ProductSnapshot[];
        discount_recommendations?: DiscountRecommendation[];
        skip_reason?: string;
    };
}

interface PaginationLink { url: string | null; label: string; active: boolean }

const props = defineProps<{
    runs: { data: SyncRun[]; links: PaginationLink[]; current_page: number; last_page: number; total: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Riwayat Sinkronisasi', href: '/inventory/sync-history' },
];

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);
const triggerLabel = (trigger: SyncRun['trigger_type']) => ({ auto: 'Auto Sync', sync_now: 'Sync Now', manual_cli: 'Sinkronisasi CLI' })[trigger];
const dateTime = (value: string | null) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const formatQuantity = (value: number, unit: string) => `${new Intl.NumberFormat('id-ID').format(value)} ${unit}`;
const statusLabel = (status: string) => ({ active: 'Aktif', discounted: 'Diskon aktif', donated: 'Habis didonasikan', sold_out: 'Habis terjual', disposed: 'Dikeluarkan' }[status] || status);
</script>

<template>
    <Head title="Riwayat Sinkronisasi Inventory" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="mx-auto w-full max-w-6xl space-y-6 p-5 md:p-8">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider text-sky-700 dark:text-sky-300">Audit Inventory</p>
                    <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold tracking-tight text-foreground"><History class="size-6" /> Riwayat Sinkronisasi</h1>
                    <p class="mt-2 max-w-3xl text-sm text-muted-foreground">Lihat setiap produk dan batch yang berubah saat Auto Sync atau Sync Now. Jumlah stok menunjukkan perubahan sebelum dan sesudah proses; rekomendasi diskon tetap menunggu persetujuan manager/kasir.</p>
                </div>
                <Link href="/inventory" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-sidebar-border px-4 text-sm font-semibold hover:bg-muted"><RefreshCw class="size-4" /> Kembali ke Inventory</Link>
            </header>

            <div v-if="flashSuccess" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200" role="status">{{ flashSuccess }}</div>
            <div v-if="flashError" class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-800 dark:text-red-200" role="alert">{{ flashError }}</div>

            <section v-if="runs.data.length" class="space-y-4" aria-label="Daftar proses sinkronisasi">
                <article v-for="run in runs.data" :key="run.id" class="overflow-hidden rounded-xl border border-sidebar-border bg-card shadow-sm">
                    <details>
                        <summary class="flex cursor-pointer list-none flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 rounded-lg p-2" :class="run.status === 'completed' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : run.status === 'failed' ? 'bg-red-500/10 text-red-700 dark:text-red-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'">
                                    <CheckCircle2 v-if="run.status === 'completed'" class="size-5" />
                                    <XCircle v-else-if="run.status === 'failed'" class="size-5" />
                                    <Clock3 v-else class="size-5" />
                                </div>
                                <div>
                                    <h2 class="font-semibold text-foreground">{{ triggerLabel(run.trigger_type) }} <span class="text-muted-foreground">· #{{ run.id }}</span></h2>
                                    <p class="mt-1 text-xs text-muted-foreground">Mulai {{ dateTime(run.started_at) }} <span v-if="run.completed_at">· Selesai {{ dateTime(run.completed_at) }}</span></p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="rounded-full bg-sky-500/10 px-2.5 py-1 font-medium text-sky-800 dark:text-sky-200">+{{ run.summary.received_units || 0 }} diterima</span>
                                <span class="rounded-full bg-orange-500/10 px-2.5 py-1 font-medium text-orange-800 dark:text-orange-200">−{{ run.summary.sold_units || 0 }} terjual</span>
                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 font-medium text-emerald-800 dark:text-emerald-200">{{ run.summary.donated_units || 0 }} didonasikan</span>
                                <span class="rounded-full bg-amber-500/10 px-2.5 py-1 font-medium text-amber-800 dark:text-amber-200">{{ run.summary.donation_approvals_pending || 0 }} menunggu konfirmasi</span>
                                <span v-if="run.summary.donation_prealerts_pending" class="rounded-full bg-amber-500/10 px-2.5 py-1 font-medium text-amber-800 dark:text-amber-200">{{ run.summary.donation_prealerts_pending }} pre-alert donasi besok</span>
                                <span class="rounded-full bg-violet-500/10 px-2.5 py-1 font-medium text-violet-800 dark:text-violet-200">{{ run.summary.discount_recommendations || 0 }} rekomendasi diskon</span>
                            </div>
                        </summary>

                        <div class="space-y-5 border-t border-sidebar-border px-4 py-5 sm:px-5">
                            <p v-if="run.status === 'skipped'" class="rounded-lg bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200">Sinkronisasi dilewati: {{ run.details.skip_reason || run.summary.reason || 'Auto Sync sedang dijeda atau proses berdekatan.' }}</p>
                            <p v-if="run.status === 'failed'" class="rounded-lg bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-200">Sinkronisasi gagal: {{ run.error_message || 'Periksa log aplikasi untuk detail.' }}</p>

                            <section v-if="run.details.updated_products?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><PackageCheck class="size-4 text-sky-700" /> Produk dengan stok terbaru</h3>
                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    <div v-for="product in run.details.updated_products" :key="product.id" class="rounded-lg border border-sidebar-border p-3 text-sm">
                                        <p class="font-semibold">{{ product.name }}</p>
                                        <p class="mt-0.5 text-xs text-muted-foreground">{{ product.sku }} · stok akhir {{ formatQuantity(product.quantity, product.unit) }}</p>
                                        <p class="mt-1 text-xs">Status: {{ statusLabel(product.status) }}</p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="run.details.events?.received?.length || run.details.events?.demo_received?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><ArrowUpRight class="size-4 text-sky-700" /> Stok masuk dari sinkronisasi</h3>
                                <div v-for="event in [...(run.details.events?.received || []), ...(run.details.events?.demo_received || [])]" :key="`in-${event.batch_code}`" class="rounded-lg bg-sky-500/5 p-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">{{ event.product_name }} <span class="font-normal text-muted-foreground">· {{ event.sku }} · Batch {{ event.batch_code }}</span></p><span class="font-semibold text-sky-800 dark:text-sky-200">+{{ formatQuantity(event.quantity, event.unit) }}</span></div>
                                    <p class="mt-1 text-xs text-muted-foreground">Stok {{ formatQuantity(event.stock_before, event.unit) }} → {{ formatQuantity(event.stock_after, event.unit) }} · Exp {{ event.expiration_date }} · Toko {{ event.store_code || '—' }}<span v-if="event.scenario === 'demo_discount_candidate'"> · Batch demo untuk uji persetujuan diskon</span><span v-else-if="event.scenario === 'demo_donation_prealert'"> · Batch demo pre-alert donasi (3 hari lagi)</span></p>
                                </div>
                            </section>

                            <section v-if="run.details.events?.sold?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><ArrowDownRight class="size-4 text-orange-700" /> Pengurangan stok dari transaksi POS simulasi</h3>
                                <div v-for="event in (run.details.events?.sold || [])" :key="`sold-${event.batch_code}`" class="rounded-lg bg-orange-500/5 p-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">{{ event.product_name }} <span class="font-normal text-muted-foreground">· {{ event.sku }} · Batch {{ event.batch_code }}</span></p><span class="font-semibold text-orange-800 dark:text-orange-200">−{{ formatQuantity(event.quantity, event.unit) }}</span></div>
                                    <p class="mt-1 text-xs text-muted-foreground">Stok {{ formatQuantity(event.stock_before, event.unit) }} → {{ formatQuantity(event.stock_after, event.unit) }} · Exp {{ event.expiration_date }}</p>
                                </div>
                            </section>

                            <section v-if="run.details.events?.donated?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><ArrowDownRight class="size-4 text-emerald-700" /> Donasi otomatis</h3>
                                <div v-for="event in (run.details.events?.donated || [])" :key="`donated-${event.batch_code}`" class="rounded-lg p-3 text-sm" :class="event.success ? 'bg-emerald-500/5' : 'bg-red-500/5'">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">{{ event.product_name }} <span class="font-normal text-muted-foreground">· {{ event.sku }} · Batch {{ event.batch_code }}</span></p><span v-if="event.success" class="font-semibold text-emerald-800 dark:text-emerald-200">−{{ formatQuantity(event.quantity, event.unit) }}</span><span v-else class="font-semibold text-red-800 dark:text-red-200">Donasi gagal</span></div>
                                    <p v-if="event.success" class="mt-1 text-xs text-muted-foreground">Stok {{ formatQuantity(event.stock_before, event.unit) }} → {{ formatQuantity(event.stock_after, event.unit) }} · Exp {{ event.expiration_date }} · Mitra {{ event.partner_name || '—' }} · Notifikasi {{ event.notifications_queued ? 'diantrekan' : 'perlu diperiksa' }}</p>
                                </div>
                            </section>

                            <section v-if="run.details.events?.donation_approvals?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><Clock3 class="size-4 text-amber-700" /> Menunggu konfirmasi donasi di Telegram</h3>
                                <div v-for="event in run.details.events.donation_approvals" :key="`donation-approval-${event.batch_id}`" class="rounded-lg bg-amber-500/5 p-3 text-sm">
                                    <p class="font-semibold">{{ event.product_name }} <span class="font-normal text-muted-foreground">· Batch {{ event.batch_code }}</span></p>
                                    <p class="mt-1 text-xs text-muted-foreground">Stok batch {{ formatQuantity(event.quantity_available, event.unit) }} · Exp {{ event.expiration_date }} · {{ event.manager_actions }} tombol persetujuan dikirim ke akun manager/kasir terhubung.</p>
                                    <p class="mt-1 text-xs font-medium text-amber-800 dark:text-amber-200">Belum didonasikan dan belum ada stok yang berubah.</p>
                                </div>
                            </section>

                            <section v-if="run.details.events?.donation_prealerts?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><Clock3 class="size-4 text-amber-700" /> Pre-alert donasi untuk besok</h3>
                                <div v-for="event in run.details.events.donation_prealerts" :key="'donation-prealert-' + event.batch_id" class="rounded-lg bg-amber-500/5 p-3 text-sm">
                                    <p class="font-semibold">{{ event.product_name }} <span class="font-normal text-muted-foreground">· Batch {{ event.batch_code }}</span></p>
                                    <p class="mt-1 text-xs text-muted-foreground">Stok {{ formatQuantity(event.quantity_available, event.unit) }} · Kedaluwarsa {{ event.expiration_date }} (3 hari lagi) · {{ event.manager_actions }} notifikasi pre-alert.</p>
                                    <p class="mt-1 text-xs font-medium text-amber-800 dark:text-amber-200">Belum didonasikan dan belum ada stok yang berubah. Persetujuan sekarang menjadwalkan donasi setelah masuk jendela 2 hari.</p>
                                </div>
                            </section>

                            <section v-if="run.details.discount_recommendations?.length" class="space-y-2">
                                <h3 class="flex items-center gap-2 text-sm font-semibold"><Percent class="size-4 text-violet-700" /> Rekomendasi diskon menunggu persetujuan</h3>
                                <div v-for="recommendation in run.details.discount_recommendations" :key="`discount-${recommendation.batch_code}`" class="rounded-lg bg-violet-500/5 p-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">{{ recommendation.product_name }} <span class="font-normal text-muted-foreground">· {{ recommendation.sku }} · Batch {{ recommendation.batch_code }}</span><span v-if="recommendation.demo" class="ml-2 rounded-full bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold text-sky-700">Demo Sync Now</span></p><span class="font-semibold text-violet-800 dark:text-violet-200">{{ recommendation.recommended_percentage }}% · {{ new Intl.NumberFormat('id-ID').format(recommendation.recommended_price) }} / unit</span></div>
                                    <p class="mt-1 text-xs text-muted-foreground">{{ formatQuantity(recommendation.quantity_available, recommendation.unit) }} tersedia · Exp {{ recommendation.expiration_date }} · Status {{ recommendation.status }}</p>
                                </div>
                            </section>

                            <p v-if="!run.details.events?.received?.length && !run.details.events?.demo_received?.length && !run.details.events?.sold?.length && !run.details.events?.donated?.length && !run.details.events?.donation_approvals?.length && !run.details.discount_recommendations?.length && run.status === 'completed'" class="text-sm text-muted-foreground">Proses selesai tanpa perubahan stok atau rekomendasi diskon pada batch yang tercatat.</p>
                        </div>
                    </details>
                </article>

                <nav v-if="runs.last_page > 1" class="flex flex-wrap justify-end gap-2" aria-label="Paginasi riwayat sinkronisasi">
                    <Link v-for="(link, index) in runs.links" :key="index" :href="link.url || '/inventory/sync-history'" class="rounded-md border border-sidebar-border px-3 py-2 text-xs" :class="[link.active ? 'bg-muted font-semibold' : '', !link.url ? 'pointer-events-none opacity-40' : 'hover:bg-muted']" v-html="link.label" />
                </nav>
            </section>

            <section v-else class="rounded-xl border border-dashed border-sidebar-border bg-card p-10 text-center">
                <History class="mx-auto size-10 text-muted-foreground" />
                <h2 class="mt-3 font-semibold">Belum ada riwayat sinkronisasi</h2>
                <p class="mt-1 text-sm text-muted-foreground">Jalankan Sync Now atau tunggu proses Auto Sync berikutnya; setiap perubahan stok akan tampil di sini.</p>
                <Link href="/inventory" class="mt-4 inline-flex rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-800">Buka Inventory</Link>
            </section>
        </main>
    </AppLayout>
</template>
