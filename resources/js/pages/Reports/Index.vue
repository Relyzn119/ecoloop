<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Download, HeartHandshake, Percent, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ReportRecord {
    id: number;
    occurred_at: string | null;
    product_name: string;
    sku: string;
    batch_code: string;
    quantity: number | null;
    unit: string | null;
    partner_name: string;
    discount_percentage: number | null;
    original_price: number | null;
    discount_price: number | null;
    reasoning: string;
}

const props = defineProps<{
    type: 'donation' | 'discount';
    period: 'daily' | 'monthly' | 'yearly';
    periodValue: string;
    periodStart: string;
    periodEnd: string;
    records: ReportRecord[];
    totals: { transactions: number; quantity_donated: number; average_discount_percentage: number };
}>();

const reportType = ref(props.type);
const reportPeriod = ref(props.period);
const periodValue = ref(props.periodValue);
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Rekap PDF', href: '/reports' },
];

const title = computed(() => reportType.value === 'donation' ? 'Rekap Donasi' : 'Rekap Diskon');
const periodLabel = computed(() => ({ daily: 'Harian', monthly: 'Bulanan', yearly: 'Tahunan' })[props.period]);

function applyFilters(): void {
    const params: Record<string, string> = { type: reportType.value, period: reportPeriod.value };
    if (reportPeriod.value === 'daily') params.date = periodValue.value;
    if (reportPeriod.value === 'monthly') params.month = periodValue.value;
    if (reportPeriod.value === 'yearly') params.year = periodValue.value;
    router.get(route('reports.index'), params, { preserveScroll: true, preserveState: true, replace: true });
}

function resetPeriodValue(): void {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    periodValue.value = reportPeriod.value === 'daily' ? `${year}-${month}-${day}` : reportPeriod.value === 'monthly' ? `${year}-${month}` : String(year);
}

function saveAsPdf(): void {
    window.print();
}

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function formatCurrency(value: number | null): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value ?? 0);
}
</script>

<template>
    <Head :title="`${title} - EcoLoop`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="space-y-6 p-4 sm:p-6 lg:p-8">
            <header class="no-print flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">Rekap Donasi & Diskon</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Pilih jenis dan periode laporan, lalu simpan sebagai PDF.</p>
                </div>
                <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700" @click="saveAsPdf">
                    <Download class="size-4" />Simpan sebagai PDF
                </button>
            </header>

            <section class="no-print grid gap-3 rounded-xl border border-sidebar-border bg-card p-4 shadow-sm sm:grid-cols-4 sm:items-end">
                <label class="space-y-1.5 text-sm font-medium text-foreground">
                    <span>Jenis rekap</span>
                    <select v-model="reportType" class="w-full rounded-lg border-input bg-background text-sm">
                        <option value="donation">Donasi selesai</option>
                        <option value="discount">Diskon diterapkan</option>
                    </select>
                </label>
                <label class="space-y-1.5 text-sm font-medium text-foreground">
                    <span>Periode</span>
                    <select v-model="reportPeriod" class="w-full rounded-lg border-input bg-background text-sm" @change="resetPeriodValue">
                        <option value="daily">Harian</option>
                        <option value="monthly">Bulanan</option>
                        <option value="yearly">Tahunan</option>
                    </select>
                </label>
                <label class="space-y-1.5 text-sm font-medium text-foreground">
                    <span>{{ reportPeriod === 'daily' ? 'Tanggal' : reportPeriod === 'monthly' ? 'Bulan' : 'Tahun' }}</span>
                    <input v-model="periodValue" :type="reportPeriod === 'daily' ? 'date' : reportPeriod === 'monthly' ? 'month' : 'number'" :min="reportPeriod === 'yearly' ? 2000 : undefined" :max="reportPeriod === 'yearly' ? 2100 : undefined" class="w-full rounded-lg border-input bg-background text-sm" />
                </label>
                <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-sidebar-border px-4 py-2.5 text-sm font-semibold text-foreground hover:bg-accent" @click="applyFilters">
                    <Search class="size-4" />Tampilkan rekap
                </button>
            </section>

            <section id="print-report" class="space-y-5 rounded-xl border border-sidebar-border bg-card p-5 shadow-sm sm:p-7">
                <header class="flex flex-col gap-3 border-b border-sidebar-border pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                            <HeartHandshake v-if="props.type === 'donation'" class="size-5" />
                            <Percent v-else class="size-5" />
                            <span class="text-xs font-semibold uppercase tracking-widest">EcoLoop · Laporan Operasional</span>
                        </div>
                        <h2 class="mt-2 text-2xl font-bold text-foreground">{{ props.type === 'donation' ? 'Rekap Donasi' : 'Rekap Diskon' }} {{ periodLabel }}</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Periode {{ props.periodStart }} s.d. {{ props.periodEnd }}</p>
                    </div>
                    <p class="text-xs text-muted-foreground">Dibuat {{ new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date()) }}</p>
                </header>

                <div class="grid gap-3 sm:grid-cols-3">
                    <article class="rounded-lg bg-muted/40 p-4"><p class="text-xs text-muted-foreground">{{ props.type === 'donation' ? 'Transaksi donasi selesai' : 'Diskon diterapkan' }}</p><p class="mt-1 text-2xl font-bold text-foreground">{{ props.totals.transactions }}</p></article>
                    <article v-if="props.type === 'donation'" class="rounded-lg bg-muted/40 p-4"><p class="text-xs text-muted-foreground">Total unit didonasikan</p><p class="mt-1 text-2xl font-bold text-foreground">{{ props.totals.quantity_donated }}</p></article>
                    <article v-else class="rounded-lg bg-muted/40 p-4"><p class="text-xs text-muted-foreground">Rata-rata diskon</p><p class="mt-1 text-2xl font-bold text-foreground">{{ props.totals.average_discount_percentage }}%</p></article>
                    <article class="rounded-lg bg-muted/40 p-4"><p class="text-xs text-muted-foreground">Jenis laporan</p><p class="mt-1 text-lg font-semibold text-foreground">{{ props.type === 'donation' ? 'Donasi selesai' : 'Diskon disetujui' }}</p></article>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b-2 border-sidebar-border text-xs uppercase tracking-wide text-muted-foreground">
                                <th class="px-3 py-3">Waktu</th><th class="px-3 py-3">Produk / SKU</th><th class="px-3 py-3">Batch</th>
                                <th v-if="props.type === 'donation'" class="px-3 py-3">Jumlah</th>
                                <th v-if="props.type === 'donation'" class="px-3 py-3">Mitra</th>
                                <th v-if="props.type === 'discount'" class="px-3 py-3">Diskon</th>
                                <th v-if="props.type === 'discount'" class="px-3 py-3">Harga awal</th>
                                <th v-if="props.type === 'discount'" class="px-3 py-3">Harga diskon</th>
                                <th class="px-3 py-3">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr v-for="record in props.records" :key="record.id">
                                <td class="whitespace-nowrap px-3 py-3 text-foreground">{{ formatDate(record.occurred_at) }}</td>
                                <td class="px-3 py-3"><p class="font-medium text-foreground">{{ record.product_name }}</p><p class="font-mono text-xs text-muted-foreground">{{ record.sku }}</p></td>
                                <td class="px-3 py-3 text-foreground">{{ record.batch_code }}</td>
                                <td v-if="props.type === 'donation'" class="px-3 py-3 font-semibold text-foreground">{{ record.quantity }} {{ record.unit }}</td>
                                <td v-if="props.type === 'donation'" class="px-3 py-3 text-foreground">{{ record.partner_name }}</td>
                                <td v-if="props.type === 'discount'" class="px-3 py-3 text-foreground">{{ record.discount_percentage }}%</td>
                                <td v-if="props.type === 'discount'" class="whitespace-nowrap px-3 py-3 text-foreground">{{ formatCurrency(record.original_price) }}</td>
                                <td v-if="props.type === 'discount'" class="whitespace-nowrap px-3 py-3 text-foreground">{{ formatCurrency(record.discount_price) }}</td>
                                <td class="max-w-64 px-3 py-3 text-xs text-muted-foreground">{{ record.reasoning }}</td>
                            </tr>
                            <tr v-if="props.records.length === 0"><td :colspan="props.type === 'donation' ? 6 : 7" class="px-3 py-12 text-center text-sm text-muted-foreground">Tidak ada {{ props.type === 'donation' ? 'donasi selesai' : 'diskon diterapkan' }} pada periode ini.</td></tr>
                        </tbody>
                    </table>
                </div>
                <footer class="border-t border-sidebar-border pt-3 text-xs text-muted-foreground">Laporan ini diambil dari catatan aktivitas EcoLoop. Donasi yang dihitung adalah transaksi dengan status selesai.</footer>
            </section>
        </main>
    </AppLayout>
</template>

<style>
@media print {
    @page { size: landscape; margin: 12mm; }
    body * { visibility: hidden !important; }
    #print-report, #print-report * { visibility: visible !important; }
    #print-report { position: absolute; inset: 0 auto auto 0; width: 100%; border: 0 !important; box-shadow: none !important; padding: 0 !important; }
    .no-print { display: none !important; }
}
</style>
