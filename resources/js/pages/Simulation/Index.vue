<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Bot, CalendarClock, HeartHandshake, Package, Percent, RefreshCw, Tag } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface InventoryBatch {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    category: string;
    batch_code: string | null;
    quantity_received: number;
    quantity_available: number;
    quantity_donated: number;
    quantity_sold: number;
    unit: string;
    original_price: number;
    discount_price: number | null;
    status: string;
    source: string;
    store_code: string | null;
    source_synced_at: string | null;
    expiration_date: string;
    days_remaining: number;
}

interface DiscountApprovalItem {
    id: number;
    product_name: string;
    sku: string;
    batch_code: string | null;
    quantity: number;
    expiration_date: string;
    recommended_percentage: number;
    recommended_price: number;
    reasoning: string;
    created_at: string | null;
}

interface AgentLogItem {
    id: number;
    action_type: string;
    reasoning: string;
    status: string;
    created_at_human: string;
    product?: { id: number; name: string; sku: string } | null;
    partner?: { id: number | null; name: string } | null;
}

const props = defineProps<{
    batches: InventoryBatch[];
    pendingApprovals: DiscountApprovalItem[];
    recentLogs: AgentLogItem[];
    lastUpdatedAt: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'AI Simulation', href: '/simulation' },
];

const isRefreshing = ref(false);
const availableUnits = computed(() => props.batches.reduce((total, batch) => total + batch.quantity_available, 0));
const donatedUnits = computed(() => props.batches.reduce((total, batch) => total + batch.quantity_donated, 0));
const lastUpdatedLabel = computed(() => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(props.lastUpdatedAt)));

function refreshData(): void {
    router.reload({
        only: ['batches', 'pendingApprovals', 'recentLogs', 'lastUpdatedAt'],
        onStart: () => {
            isRefreshing.value = true;
        },
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
}

function expiryLabel(days: number): string {
    if (days < 0) return `Expired ${Math.abs(days)}d ago`;
    if (days === 0) return 'Expires today';
    return `${days}d remaining`;
}

function statusClass(status: string): string {
    if (status === 'donated') return 'bg-violet-500/10 text-violet-700 dark:text-violet-300';
    if (status === 'discounted') return 'bg-amber-500/10 text-amber-700 dark:text-amber-300';
    if (status === 'disposed') return 'bg-neutral-500/10 text-neutral-700 dark:text-neutral-300';

    return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
}
</script>

<template>
    <Head title="AI Simulation - Latest Inventory" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="space-y-6 p-4 sm:p-6 lg:p-8">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">AI Simulation</h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Latest stock lots, autonomous agent activity, and discount recommendations awaiting approval.</p>
                    <p class="mt-1 text-xs text-neutral-400">Updated {{ lastUpdatedLabel }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :disabled="isRefreshing"
                        aria-label="Refresh latest inventory data"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-60"
                        @click="refreshData"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': isRefreshing }" />
                        <span>{{ isRefreshing ? 'Refreshing…' : 'Refresh data' }}</span>
                    </button>
                    <Link href="/inventory" class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border bg-card px-4 py-2.5 text-sm font-medium text-foreground shadow-sm transition hover:bg-accent">
                        <Package class="size-4" />
                        <span>Open inventory</span>
                    </Link>
                </div>
            </header>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Inventory summary">
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground"><span>Tracked lots</span><Package class="size-4" /></div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ batches.length }}</p>
                </article>
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground"><span>Available stock</span><CalendarClock class="size-4" /></div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ availableUnits }}</p>
                    <p class="text-xs text-muted-foreground">units across displayed lots</p>
                </article>
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground"><span>Donated stock</span><HeartHandshake class="size-4" /></div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ donatedUnits }}</p>
                    <p class="text-xs text-muted-foreground">units across displayed lots</p>
                </article>
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground"><span>Discount approvals</span><Percent class="size-4" /></div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ pendingApprovals.length }}</p>
                    <p class="text-xs text-muted-foreground">waiting for a manager or cashier</p>
                </article>
            </section>

            <section class="overflow-hidden rounded-xl border border-sidebar-border bg-card shadow-sm">
                <div class="flex items-center justify-between border-b border-sidebar-border px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-foreground">Inventory lots</h2>
                        <p class="text-xs text-muted-foreground">One row per receiving batch, including remaining and diverted quantities.</p>
                    </div>
                    <Link href="/inventory" class="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                        Manage inventory <ArrowRight class="size-4" />
                    </Link>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-left text-sm">
                        <thead class="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3">Product</th><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Received</th><th class="px-4 py-3">Available</th><th class="px-4 py-3">Donated / sold</th><th class="px-4 py-3">Expiration</th><th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/60">
                            <tr v-for="batch in batches" :key="batch.id" class="hover:bg-muted/20">
                                <td class="px-5 py-3"><p class="font-medium text-foreground">{{ batch.name }}</p><p class="font-mono text-xs text-muted-foreground">{{ batch.sku }} · {{ batch.category }}</p></td>
                                <td class="px-4 py-3 font-mono text-xs text-foreground">
                                    {{ batch.batch_code || '—' }}
                                    <p class="font-sans text-[11px] text-muted-foreground">
                                        {{ batch.source === 'central_sync' ? 'Supermarket POS · ' + batch.store_code : batch.source }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-foreground">{{ batch.quantity_received }} {{ batch.unit }}</td>
                                <td class="px-4 py-3 font-semibold text-foreground">{{ batch.quantity_available }} {{ batch.unit }}</td>
                                <td class="px-4 py-3 text-foreground">{{ batch.quantity_donated }} / {{ batch.quantity_sold }}</td>
                                <td class="px-4 py-3"><p class="text-foreground">{{ batch.expiration_date }}</p><p class="text-xs text-muted-foreground">{{ expiryLabel(batch.days_remaining) }}</p></td>
                                <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold capitalize" :class="statusClass(batch.status)">{{ batch.status.replace('_', ' ') }}</span></td>
                            </tr>
                            <tr v-if="batches.length === 0"><td colspan="7" class="px-5 py-12 text-center text-sm text-muted-foreground">No inventory batches yet. Add a product in Inventory to begin tracking.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <section class="rounded-xl border border-sidebar-border bg-card shadow-sm">
                    <div class="flex items-center gap-2 border-b border-sidebar-border px-5 py-4">
                        <Percent class="size-4 text-amber-600 dark:text-amber-400" />
                        <div><h2 class="font-semibold text-foreground">Discount recommendations</h2><p class="text-xs text-muted-foreground">AI suggestions are pending; no price is changed until approval.</p></div>
                    </div>
                    <div v-if="pendingApprovals.length" class="divide-y divide-sidebar-border/60">
                        <article v-for="approval in pendingApprovals" :key="approval.id" class="space-y-2 px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div><p class="font-medium text-foreground">{{ approval.product_name }}</p><p class="font-mono text-xs text-muted-foreground">{{ approval.sku }} · {{ approval.batch_code || 'Batch' }}</p></div>
                                <span class="rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300">Pending approval</span>
                            </div>
                            <p class="text-sm text-foreground">{{ approval.quantity }} units · expires {{ approval.expiration_date }}</p>
                            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">AI recommendation: {{ approval.recommended_percentage }}% · {{ formatCurrency(approval.recommended_price) }}</p>
                            <p class="text-xs text-muted-foreground">{{ approval.reasoning }}</p>
                        </article>
                    </div>
                    <p v-else class="px-5 py-10 text-center text-sm text-muted-foreground">No discount recommendations are waiting for approval.</p>
                </section>

                <section class="rounded-xl border border-sidebar-border bg-card shadow-sm">
                    <div class="flex items-center gap-2 border-b border-sidebar-border px-5 py-4">
                        <Bot class="size-4 text-emerald-700 dark:text-emerald-400" />
                        <div><h2 class="font-semibold text-foreground">Recent agent activity</h2><p class="text-xs text-muted-foreground">Latest recorded decisions and inventory actions.</p></div>
                    </div>
                    <div v-if="recentLogs.length" class="divide-y divide-sidebar-border/60">
                        <article v-for="log in recentLogs" :key="log.id" class="px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2"><Tag class="size-3.5 text-muted-foreground" /><span class="text-sm font-semibold capitalize text-foreground">{{ log.action_type.replace('_', ' ') }}</span></div>
                                <span class="text-xs text-muted-foreground">{{ log.created_at_human }}</span>
                            </div>
                            <p class="mt-1 text-sm text-foreground">{{ log.product?.name || 'Inventory event' }}<span v-if="log.partner?.name"> · {{ log.partner.name }}</span></p>
                            <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ log.reasoning }}</p>
                        </article>
                    </div>
                    <div v-else class="flex flex-col items-center gap-2 px-5 py-10 text-center text-sm text-muted-foreground"><Bot class="size-6" /><p>No agent activity has been recorded.</p></div>
                    <div class="border-t border-sidebar-border px-5 py-3 text-right"><Link href="/agent-logs" class="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400">Open audit trail <ArrowRight class="inline size-4" /></Link></div>
                </section>
            </div>
        </main>
    </AppLayout>
</template>
