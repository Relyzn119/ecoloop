<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    ArrowUpRight,
    Bot,
    CheckCircle2,
    Clock,
    Coins,
    ExternalLink,
    Handshake,
    Leaf,
    Package,
    Plus,
    RefreshCw,
    Sparkles,
    Tag,
    X,
    Zap,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface DashboardMetrics {
    total_expiring_soon: number;
    food_saved_kg: number;
    financial_loss_prevented: number;
    financial_loss_prevented_formatted: string;
    co2_emission_reduced_kg: number;
    active_partners_count: number;
}

interface ProductSummary {
    id: number;
    name: string;
    sku: string;
    category: string;
    quantity: number;
    unit: string;
    original_price: number;
    discount_price: number | null;
    expiration_date: string;
    days_left: number;
    status: 'active' | 'discounted' | 'reserved_donation' | 'donated' | 'disposed';
}

interface AgentLogItem {
    id: number;
    action_type: 'discount_applied' | 'donation_requested' | 'alert_sent' | 'manual_override';
    reasoning: string;
    payload: Record<string, any>;
    status: 'success' | 'failed' | 'pending';
    created_at: string;
    created_at_exact?: string;
    product: {
        id: number;
        name: string;
        sku: string;
        category: string;
    } | null;
    partner?: {
        id: number;
        name: string;
        email?: string;
        phone?: string;
    } | null;
    partner_name?: string | null;
}

interface Props {
    metrics: DashboardMetrics;
    recent_agent_logs: AgentLogItem[];
    urgent_products: ProductSummary[];
    mock_ai_enabled: boolean;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Flash notifications & feedback toasts
const page = usePage();
const flash = computed(() => page.props.flash as Record<string, string> | undefined);
const dismissedFlashes = ref<Set<string>>(new Set());
const localFeedback = ref<{ type: 'success' | 'info' | 'error'; message: string } | null>(null);

function dismissFlash(type: string) {
    dismissedFlashes.value.add(type);
}

function isFlashVisible(type: string): boolean {
    return !dismissedFlashes.value.has(type) && !!flash.value?.[type];
}

// Interactive AI Scan Simulation
const isScanning = ref(false);
const quickActionLoading = ref<number | null>(null);

async function triggerAiScan() {
    isScanning.value = true;
    localFeedback.value = null;

    try {
        const response = await fetch('/api/v1/ai/simulate-run', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ days: 3 }),
        });

        const data = await response.json();

        if (data.success) {
            const actionsCount = data.data?.actions_count ?? 0;
            localFeedback.value = {
                type: 'success',
                message: `AI Agent cycle finished: ${actionsCount} automated actions executed successfully.`,
            };

            // Reload Inertia props to sync dashboard state
            router.reload({
                only: ['metrics', 'recent_agent_logs', 'urgent_products'],
            });
        } else {
            localFeedback.value = {
                type: 'error',
                message: data.message || 'AI Scan simulation failed.',
            };
        }
    } catch {
        localFeedback.value = {
            type: 'error',
            message: 'Unable to communicate with EcoLoop AI service.',
        };
    } finally {
        isScanning.value = false;
    }
}

// Quick 1-click Markdown for urgent item
async function quickDiscount(productId: number) {
    quickActionLoading.value = productId;
    try {
        const response = await fetch('/api/v1/inventory/apply-discount', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                product_id: productId,
                discount_percentage: 40,
                reason: 'Operator 1-click clearance markdown from dashboard table.',
            }),
        });

        const data = await response.json();
        if (data.success) {
            localFeedback.value = {
                type: 'success',
                message: `Applied 40% discount to ${data.data?.name}. New price: $${Number(data.data?.discount_price).toFixed(2)}.`,
            };
            router.reload({ only: ['metrics', 'recent_agent_logs', 'urgent_products'] });
        }
    } catch {
        localFeedback.value = {
            type: 'error',
            message: 'Failed to apply discount.',
        };
    } finally {
        quickActionLoading.value = null;
    }
}
</script>

<template>
    <Head title="EcoLoop Agent - Circular Economy Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
            <!-- Flash & System Feedback Notifications -->
            <Transition name="flash">
                <div
                    v-if="localFeedback"
                    class="flex items-center gap-3 rounded-xl border px-4 py-3 text-sm shadow-sm transition"
                    :class="{
                        'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300':
                            localFeedback.type === 'success',
                        'border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/60 dark:text-red-300':
                            localFeedback.type === 'error',
                        'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300':
                            localFeedback.type === 'info',
                    }"
                >
                    <CheckCircle2 v-if="localFeedback.type === 'success'" class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <AlertCircle v-else class="size-5 shrink-0 text-red-600 dark:text-red-400" />
                    <span class="flex-1 font-medium">{{ localFeedback.message }}</span>
                    <button @click="localFeedback = null" class="shrink-0 rounded-md p-1 hover:bg-black/5 dark:hover:bg-white/10">
                        <X class="size-4" />
                    </button>
                </div>
            </Transition>

            <Transition name="flash">
                <div
                    v-if="isFlashVisible('success')"
                    class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                >
                    <CheckCircle2 class="size-5 shrink-0" />
                    <span class="flex-1">{{ flash?.success }}</span>
                    <button @click="dismissFlash('success')" class="shrink-0 rounded-md p-1 hover:bg-emerald-200 dark:hover:bg-emerald-800">
                        <X class="size-4" />
                    </button>
                </div>
            </Transition>

            <!-- Quick Action Bar & Agent Status Banner -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100 sm:text-3xl">Circular Economy Dashboard</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        AI-driven shelf-life forecasting, dynamic markdown clearing, and food waste reduction metrics.
                    </p>
                </div>

                <!-- Quick Action Buttons -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <button
                        type="button"
                        :disabled="isScanning"
                        @click="triggerAiScan"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': isScanning }" />
                        <span>{{ isScanning ? 'Evaluating Shelf-Life...' : 'Trigger AI Scan (Mock/Live)' }}</span>
                    </button>

                    <Link
                        href="/inventory"
                        prefetch
                        class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border bg-card px-3.5 py-2.5 text-sm font-medium text-foreground shadow-sm transition hover:bg-accent"
                    >
                        <Package class="size-4 text-muted-foreground" />
                        <span>View Expiring Products</span>
                    </Link>

                    <Link
                        href="/inventory"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-sidebar-border bg-card px-3.5 py-2.5 text-sm font-medium text-foreground shadow-sm transition hover:bg-accent"
                    >
                        <Plus class="size-4 text-muted-foreground" />
                        <span>Add New Inventory</span>
                    </Link>
                </div>
            </div>

            <!-- KPI Metric Summary Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <!-- 1. Expiring Soon -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-sidebar-border/80 bg-card p-5 shadow-sm transition-all duration-200 hover:shadow-md dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between">
                        <div class="space-y-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Expiring Soon</p>
                            <p class="text-3xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50">
                                {{ metrics.total_expiring_soon }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-amber-500/10 p-3 text-amber-600 dark:text-amber-400">
                            <Clock class="size-5" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between text-xs">
                        <span class="inline-flex items-center gap-1 font-medium text-amber-600 dark:text-amber-400">
                            <AlertTriangle class="size-3.5" />
                            <span>&le; 7 Days Critical Window</span>
                        </span>
                        <Link href="/inventory" class="text-muted-foreground hover:text-foreground">View all &rarr;</Link>
                    </div>
                    <div
                        class="pointer-events-none absolute -right-6 -top-6 size-24 rounded-full bg-amber-500/10 opacity-0 blur-2xl transition-opacity group-hover:opacity-100"
                    />
                </div>

                <!-- 2. Food Waste Prevented -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-sidebar-border/80 bg-card p-5 shadow-sm transition-all duration-200 hover:shadow-md dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between">
                        <div class="space-y-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Food Waste Diverted</p>
                            <p class="text-3xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50">
                                {{ metrics.food_saved_kg }} <span class="text-lg font-medium text-muted-foreground">kg</span>
                            </p>
                        </div>
                        <div class="rounded-xl bg-emerald-500/10 p-3 text-emerald-600 dark:text-emerald-400">
                            <Leaf class="size-5" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between text-xs">
                        <span class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                            <ArrowUpRight class="size-3.5" />
                            <span>Diverted from Landfills</span>
                        </span>
                        <span class="text-muted-foreground">100% Organics Target</span>
                    </div>
                    <div
                        class="pointer-events-none absolute -right-6 -top-6 size-24 rounded-full bg-emerald-500/10 opacity-0 blur-2xl transition-opacity group-hover:opacity-100"
                    />
                </div>

                <!-- 3. Financial Loss Prevented (in IDR) -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-sidebar-border/80 bg-card p-5 shadow-sm transition-all duration-200 hover:shadow-md dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between">
                        <div class="space-y-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Revenue Loss Prevented</p>
                            <p class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50 sm:text-2xl">
                                {{ metrics.financial_loss_prevented_formatted }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-blue-500/10 p-3 text-blue-600 dark:text-blue-400">
                            <Coins class="size-5" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between text-xs">
                        <span class="font-medium text-blue-600 dark:text-blue-400"> Via Dynamic Markdowns &amp; Rescue </span>
                        <span class="text-muted-foreground">IDR Equiv.</span>
                    </div>
                    <div
                        class="pointer-events-none absolute -right-6 -top-6 size-24 rounded-full bg-blue-500/10 opacity-0 blur-2xl transition-opacity group-hover:opacity-100"
                    />
                </div>

                <!-- 4. CO2 Emission Reduced -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-sidebar-border/80 bg-card p-5 shadow-sm transition-all duration-200 hover:shadow-md dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between">
                        <div class="space-y-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">CO2e Emissions Reduced</p>
                            <p class="text-3xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50">
                                {{ metrics.co2_emission_reduced_kg }} <span class="text-lg font-medium text-muted-foreground">kg</span>
                            </p>
                        </div>
                        <div class="rounded-xl bg-teal-500/10 p-3 text-teal-600 dark:text-teal-400">
                            <Sparkles class="size-5" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between text-xs">
                        <span class="font-medium text-teal-600 dark:text-teal-400"> 2.5 kg CO2e / kg food factor </span>
                        <span class="text-muted-foreground">EPA Standard</span>
                    </div>
                    <div
                        class="pointer-events-none absolute -right-6 -top-6 size-24 rounded-full bg-teal-500/10 opacity-0 blur-2xl transition-opacity group-hover:opacity-100"
                    />
                </div>
            </div>

            <!-- Main Content Area: Urgent Items Attention Table & Live Agent Activity Feed -->
            <div class="grid flex-1 gap-6 lg:grid-cols-3">
                <!-- Urgent Items Attention (2 cols) -->
                <div class="flex flex-col rounded-2xl border border-sidebar-border/80 bg-card shadow-sm dark:border-sidebar-border lg:col-span-2">
                    <div class="flex items-center justify-between border-b border-sidebar-border/80 px-6 py-4">
                        <div class="flex items-center gap-2.5">
                            <div class="rounded-lg bg-amber-500/10 p-2 text-amber-600 dark:text-amber-400">
                                <AlertTriangle class="size-4" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold leading-tight text-neutral-900 dark:text-neutral-100">Urgent Clearance Items</h3>
                                <p class="text-xs text-muted-foreground">Products expiring in 1–3 days requiring AI markdown or donation</p>
                            </div>
                        </div>

                        <Link
                            href="/inventory"
                            class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
                        >
                            <span>Open Inventory</span>
                            <ExternalLink class="size-3" />
                        </Link>
                    </div>

                    <!-- Table of Urgent Items -->
                    <div class="flex-1 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-sidebar-border/60 bg-muted/40 text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th class="px-6 py-3">Product / SKU</th>
                                    <th class="px-4 py-3">Category</th>
                                    <th class="px-4 py-3">Quantity</th>
                                    <th class="px-4 py-3">Price</th>
                                    <th class="px-4 py-3">Expires In</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-6 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sidebar-border/60">
                                <tr v-for="item in urgent_products" :key="item.id" class="transition-colors hover:bg-muted/30">
                                    <td class="px-6 py-3.5">
                                        <p class="font-medium text-neutral-900 dark:text-neutral-100">{{ item.name }}</p>
                                        <p class="font-mono text-xs text-muted-foreground">{{ item.sku }}</p>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                            {{ item.category }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 font-medium">{{ item.quantity }} {{ item.unit }}</td>
                                    <td class="px-4 py-3.5">
                                        <div v-if="item.discount_price" class="flex flex-col">
                                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">
                                                ${{ item.discount_price.toFixed(2) }}
                                            </span>
                                            <span class="text-xs text-muted-foreground line-through"> ${{ item.original_price.toFixed(2) }} </span>
                                        </div>
                                        <span v-else class="font-medium"> ${{ item.original_price.toFixed(2) }} </span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="{
                                                'bg-red-500/10 text-red-700 dark:text-red-400': item.days_left <= 1,
                                                'bg-amber-500/10 text-amber-700 dark:text-amber-400': item.days_left === 2,
                                                'bg-yellow-500/10 text-yellow-800 dark:text-yellow-400': item.days_left >= 3,
                                            }"
                                        >
                                            <Clock class="size-3" />
                                            <span>{{ item.days_left <= 0 ? 'Today' : `${item.days_left}d left` }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span
                                            class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium capitalize"
                                            :class="{
                                                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400': item.status === 'discounted',
                                                'bg-violet-500/10 text-violet-700 dark:text-violet-400': item.status === 'reserved_donation',
                                                'bg-blue-500/10 text-blue-700 dark:text-blue-400': item.status === 'active',
                                            }"
                                        >
                                            {{ item.status.replace('_', ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5 text-right">
                                        <button
                                            v-if="item.status === 'active'"
                                            type="button"
                                            :disabled="quickActionLoading === item.id"
                                            @click="quickDiscount(item.id)"
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 dark:bg-emerald-950/50 dark:text-emerald-300 dark:hover:bg-emerald-900/60"
                                        >
                                            <Tag class="size-3" />
                                            <span>{{ quickActionLoading === item.id ? 'Marking...' : '-40%' }}</span>
                                        </button>
                                        <span v-else class="text-xs text-muted-foreground">Handled</span>
                                    </td>
                                </tr>

                                <tr v-if="urgent_products.length === 0">
                                    <td colspan="7" class="py-12 text-center text-sm text-muted-foreground">
                                        No critical expiration items detected. All products have healthy shelf-life buffers.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Live Agent Activity Feed Widget (1 col) -->
                <div class="flex flex-col rounded-2xl border border-sidebar-border/80 bg-card shadow-sm dark:border-sidebar-border">
                    <div class="flex items-center justify-between border-b border-sidebar-border/80 px-6 py-4">
                        <div class="flex items-center gap-2.5">
                            <div class="rounded-lg bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
                                <Bot class="size-4" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold leading-tight text-neutral-900 dark:text-neutral-100">Agent Activity Feed</h3>
                                <p class="text-xs text-muted-foreground">Autonomous decision stream</p>
                            </div>
                        </div>

                        <span class="relative flex size-2.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span class="relative inline-flex size-2.5 rounded-full bg-emerald-500" />
                        </span>
                    </div>

                    <!-- Logs List -->
                    <div class="max-h-[520px] flex-1 space-y-3.5 divide-y divide-sidebar-border/60 overflow-y-auto p-4">
                        <div
                            v-for="log in recent_agent_logs"
                            :key="log.id"
                            class="rounded-xl border border-sidebar-border/60 bg-muted/20 p-3.5 transition hover:bg-muted/40"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold"
                                        :class="{
                                            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400': log.action_type === 'discount_applied',
                                            'bg-violet-500/10 text-violet-700 dark:text-violet-400': log.action_type === 'donation_requested',
                                            'bg-amber-500/10 text-amber-700 dark:text-amber-400': log.action_type === 'alert_sent',
                                            'bg-neutral-500/10 text-neutral-700 dark:text-neutral-400': log.action_type === 'manual_override',
                                        }"
                                    >
                                        <Tag v-if="log.action_type === 'discount_applied'" class="size-3" />
                                        <Handshake v-else-if="log.action_type === 'donation_requested'" class="size-3" />
                                        <Zap v-else class="size-3" />
                                        <span>{{ log.action_type.replace('_', ' ') }}</span>
                                    </span>

                                    <!-- Status Pill -->
                                    <span
                                        class="inline-flex size-1.5 rounded-full"
                                        :class="{
                                            'bg-emerald-500': log.status === 'success',
                                            'bg-amber-500': log.status === 'pending',
                                            'bg-red-500': log.status === 'failed',
                                        }"
                                    />
                                </div>

                                <span class="text-xs text-muted-foreground">{{ log.created_at }}</span>
                            </div>

                            <!-- Target Product / Partner context -->
                            <div v-if="log.product" class="mt-2 flex items-center gap-1.5 text-xs font-medium text-neutral-800 dark:text-neutral-200">
                                <Package class="size-3.5 text-muted-foreground" />
                                <span>{{ log.product.name }}</span>
                                <span class="font-mono text-muted-foreground">({{ log.product.sku }})</span>
                            </div>

                            <div v-if="log.partner_name" class="mt-1 flex items-center gap-1.5 text-xs text-violet-700 dark:text-violet-300">
                                <Handshake class="size-3.5" />
                                <span>Dispatched to: {{ log.partner_name }}</span>
                            </div>

                            <!-- AI Reasoning text -->
                            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                                {{ log.reasoning }}
                            </p>
                        </div>

                        <div v-if="recent_agent_logs.length === 0" class="py-12 text-center text-sm text-muted-foreground">
                            <Bot class="mx-auto mb-2 size-8 opacity-40" />
                            <p>No autonomous logs recorded yet.</p>
                            <p class="text-xs">Click "Trigger AI Scan" to run the evaluation engine.</p>
                        </div>
                    </div>

                    <div class="rounded-b-2xl border-t border-sidebar-border/80 bg-muted/20 p-3 text-center">
                        <Link href="/agent-logs" class="text-xs font-medium text-muted-foreground transition hover:text-foreground">
                            View full audit log stream &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.flash-enter-active,
.flash-leave-active {
    transition: all 0.3s ease;
}
.flash-enter-from {
    opacity: 0;
    transform: translateY(-8px);
}
.flash-leave-to {
    opacity: 0;
    transform: translateX(16px);
}
</style>
