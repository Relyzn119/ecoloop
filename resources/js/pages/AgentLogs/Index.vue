<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    Bot,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Code2,
    Copy,
    Cpu,
    Filter,
    HeartHandshake,
    Percent,
    RefreshCw,
    RotateCcw,
    Search,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Tag,
    TrendingDown,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ProductInfo {
    id: number;
    name: string;
    sku: string;
    category?: string;
    original_price?: number;
    discount_price?: number | null;
    status?: string;
    expiration_date?: string;
}

interface PartnerInfo {
    id?: number | null;
    name: string;
    contact_person?: string | null;
    email?: string | null;
    phone?: string | null;
}

interface AgentLogItem {
    id: number;
    product_id: number | null;
    partner_id: number | null;
    action_type: 'discount_applied' | 'donation_requested' | 'alert_sent' | 'manual_override';
    reasoning: string;
    payload: Record<string, any>;
    status: 'success' | 'failed' | 'pending';
    created_at: string;
    created_at_human: string;
    product?: ProductInfo | null;
    partner?: PartnerInfo | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedLogs {
    data: AgentLogItem[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

interface StatsInfo {
    total_logs: number;
    discounts_count: number;
    donations_count: number;
    alerts_count: number;
    overrides_count: number;
    success_rate: number;
}

interface FilterProps {
    search: string;
    action_type: string;
    status: string;
    date: string;
}

interface PartnerOption {
    id: number;
    name: string;
}

const props = defineProps<{
    logs: PaginatedLogs;
    filters: FilterProps;
    stats: StatsInfo;
    partners: PartnerOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Audit Trail', href: '/agent-logs' },
];

const page = usePage();
const flashSuccess = computed(() => (page.props as any).flash?.success);
const flashError = computed(() => (page.props as any).flash?.error);

// ─── Filter & Search State ──────────────────────────────────────────────────
const search = ref(props.filters.search || '');
const selectedActionType = ref(props.filters.action_type || 'all');
const selectedStatus = ref(props.filters.status || 'all');
const selectedDate = ref(props.filters.date || 'all');
const isSimulating = ref(false);
const isRefreshing = ref(false);

let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

const applyFilters = () => {
    router.get(
        '/agent-logs',
        {
            search: search.value || undefined,
            action_type: selectedActionType.value !== 'all' ? selectedActionType.value : undefined,
            status: selectedStatus.value !== 'all' ? selectedStatus.value : undefined,
            date: selectedDate.value !== 'all' ? selectedDate.value : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const handleSearchInput = () => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }
    searchDebounceTimer = setTimeout(() => {
        applyFilters();
    }, 350);
};

const clearSearch = () => {
    search.value = '';
    applyFilters();
};

const resetAllFilters = () => {
    search.value = '';
    selectedActionType.value = 'all';
    selectedStatus.value = 'all';
    selectedDate.value = 'all';
    applyFilters();
};

const hasActiveFilters = computed(() => {
    return search.value !== '' || selectedActionType.value !== 'all' || selectedStatus.value !== 'all' || selectedDate.value !== 'all';
});

// ─── AI Simulation & Refresh ────────────────────────────────────────────────
const runSimulation = () => {
    isSimulating.value = true;
    router.post(
        '/agent-logs/simulate',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isSimulating.value = false;
            },
        },
    );
};

const refreshLogs = () => {
    isRefreshing.value = true;
    router.reload({
        only: ['logs', 'stats'],
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
};

// ─── JSON Inspection Modal ──────────────────────────────────────────────────
const selectedLogForJson = ref<AgentLogItem | null>(null);
const isJsonModalOpen = ref(false);
const jsonViewMode = ref<'code' | 'summary'>('code');
const hasCopiedJson = ref(false);

const openJsonModal = (log: AgentLogItem) => {
    selectedLogForJson.value = log;
    jsonViewMode.value = 'code';
    hasCopiedJson.value = false;
    isJsonModalOpen.value = true;
};

const closeJsonModal = () => {
    isJsonModalOpen.value = false;
    selectedLogForJson.value = null;
};

const formattedJsonString = computed(() => {
    if (!selectedLogForJson.value) return '';
    return JSON.stringify(selectedLogForJson.value.payload, null, 2);
});

const copyJsonToClipboard = async () => {
    if (!formattedJsonString.value) return;
    try {
        await navigator.clipboard.writeText(formattedJsonString.value);
        hasCopiedJson.value = true;
        setTimeout(() => {
            hasCopiedJson.value = false;
        }, 2200);
    } catch (e) {
        console.error('Failed to copy to clipboard', e);
    }
};

// Syntax-highlighted HTML generator for raw JSON
const syntaxHighlightedJson = computed(() => {
    if (!selectedLogForJson.value) return '';
    const json = JSON.stringify(selectedLogForJson.value.payload, null, 2);
    return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, (match) => {
        let cls = 'text-amber-400 dark:text-amber-300'; // number
        if (/^"/.test(match)) {
            if (/:$/.test(match)) {
                cls = 'text-sky-600 dark:text-sky-300 font-semibold'; // key
            } else {
                cls = 'text-emerald-600 dark:text-emerald-400'; // string value
            }
        } else if (/true|false/.test(match)) {
            cls = 'text-violet-600 dark:text-violet-400 font-medium'; // boolean
        } else if (/null/.test(match)) {
            cls = 'text-rose-500 font-medium'; // null
        }
        return `<span class="${cls}">${match}</span>`;
    });
});

// Detect AI model from payload
const getModelBadge = (payload: Record<string, any>) => {
    if (payload.model) return payload.model;
    if (payload.gemini || payload.gemini_version) return 'Gemini 2.5 Flash';
    if (payload.groq || payload.groq_model) return 'Groq LLaMA 3.3';
    if (payload.n8n || payload.webhook) return 'n8n Autonomous Workflow';
    if (payload.rule) return 'EcoLoop Heuristic Engine';
    return 'Autonomous Daemon';
};

// ─── Manual Override Modal ──────────────────────────────────────────────────
const selectedLogForOverride = ref<AgentLogItem | null>(null);
const isOverrideModalOpen = ref(false);
const overrideAction = ref<'revert' | 'modify_discount' | 'reassign_partner'>('revert');
const overrideReason = ref('');
const overrideDiscountPercentage = ref<number>(30);
const overridePartnerId = ref<number | ''>('');
const isSubmittingOverride = ref(false);
const overrideErrorMessage = ref('');

const openOverrideModal = (log: AgentLogItem) => {
    selectedLogForOverride.value = log;
    overrideReason.value = '';
    overrideErrorMessage.value = '';

    if (log.action_type === 'discount_applied') {
        overrideAction.value = 'revert';
        overrideDiscountPercentage.value = log.payload?.discount_percentage || 25;
    } else if (log.action_type === 'donation_requested') {
        overrideAction.value = 'revert';
        overridePartnerId.value = props.partners[0]?.id || '';
    } else {
        overrideAction.value = 'revert';
    }

    isOverrideModalOpen.value = true;
};

const closeOverrideModal = () => {
    isOverrideModalOpen.value = false;
    selectedLogForOverride.value = null;
    overrideErrorMessage.value = '';
};

const previewModifiedPrice = computed(() => {
    if (!selectedLogForOverride.value?.product?.original_price) return 0;
    const orig = selectedLogForOverride.value.product.original_price;
    const pct = overrideDiscountPercentage.value || 0;
    return Math.max(0, orig * (1 - pct / 100)).toFixed(2);
});

const submitManualOverride = () => {
    if (!selectedLogForOverride.value) return;

    if (!overrideReason.value.trim() || overrideReason.value.trim().length < 5) {
        overrideErrorMessage.value = 'Please provide an override justification with at least 5 characters.';
        return;
    }

    if (overrideAction.value === 'modify_discount') {
        if (!overrideDiscountPercentage.value || overrideDiscountPercentage.value < 1 || overrideDiscountPercentage.value > 99) {
            overrideErrorMessage.value = 'Discount percentage must be between 1% and 99%.';
            return;
        }
    }

    if (overrideAction.value === 'reassign_partner' && !overridePartnerId.value) {
        overrideErrorMessage.value = 'Please choose a donation partner to reassign.';
        return;
    }

    isSubmittingOverride.value = true;
    overrideErrorMessage.value = '';

    router.post(
        `/agent-logs/${selectedLogForOverride.value.id}/override`,
        {
            override_action: overrideAction.value,
            reason: overrideReason.value.trim(),
            discount_percentage: overrideAction.value === 'modify_discount' ? overrideDiscountPercentage.value : undefined,
            partner_id: overrideAction.value === 'reassign_partner' ? overridePartnerId.value : undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeOverrideModal();
            },
            onError: (errors) => {
                const firstErr = Object.values(errors)[0];
                overrideErrorMessage.value = typeof firstErr === 'string' ? firstErr : 'Failed to apply manual override.';
            },
            onFinish: () => {
                isSubmittingOverride.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Autonomous AI Audit Trail - EcoLoop" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
            <!-- Header Section with Live Status Badge and Actions -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white shadow-md shadow-emerald-500/20"
                        >
                            <Cpu class="size-5" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">AI Agent Audit Trail</h1>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                                >
                                    <span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                                    Autonomous Ledger
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground sm:text-sm">
                                Transparent decision verification for competition evaluation, inspection of LLM payloads, and manual operator
                                overrides.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2.5 self-start sm:self-auto">
                    <button
                        type="button"
                        :disabled="isSimulating"
                        @click="runSimulation"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-emerald-600/30 transition-all hover:bg-emerald-500 hover:shadow-md hover:shadow-emerald-600/40 active:translate-y-0.5 disabled:opacity-60"
                        title="Simulate autonomous AI evaluation for expiring inventory"
                    >
                        <Sparkles v-if="!isSimulating" class="size-4" />
                        <RefreshCw v-else class="size-4 animate-spin" />
                        <span>{{ isSimulating ? 'Evaluating Inventory...' : 'Simulate AI Scan' }}</span>
                    </button>

                    <button
                        type="button"
                        :disabled="isRefreshing"
                        @click="refreshLogs"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-neutral-200 bg-white px-3.5 py-2 text-sm font-medium text-neutral-700 shadow-sm transition hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        title="Refresh agent log stream"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': isRefreshing }" />
                        <span class="hidden sm:inline">Refresh</span>
                    </button>
                </div>
            </div>

            <!-- Flash Alert Messages -->
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div
                    v-if="flashSuccess"
                    class="flex items-center gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-800 shadow-sm dark:text-emerald-300"
                >
                    <CheckCircle2 class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span class="font-medium">{{ flashSuccess }}</span>
                </div>
            </transition>

            <div
                v-if="flashError"
                class="flex items-center gap-3 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-800 shadow-sm dark:text-rose-300"
            >
                <AlertCircle class="size-5 shrink-0 text-rose-600 dark:text-rose-400" />
                <span class="font-medium">{{ flashError }}</span>
            </div>

            <!-- Header Statistics Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Card 1: Total Decisions -->
                <div
                    class="relative overflow-hidden rounded-2xl border border-neutral-200/80 bg-white/70 p-4 shadow-sm backdrop-blur-sm dark:border-neutral-800 dark:bg-neutral-900/70"
                >
                    <div class="flex items-center justify-between text-neutral-500 dark:text-neutral-400">
                        <span class="text-xs font-medium uppercase tracking-wider">Total Actions</span>
                        <Cpu class="size-4 text-neutral-600 dark:text-neutral-300" />
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">{{ stats.total_logs }}</span>
                        <span class="text-xs text-muted-foreground">recorded</span>
                    </div>
                </div>

                <!-- Card 2: Markdowns Applied -->
                <div
                    class="relative overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-50/50 to-white/70 p-4 shadow-sm backdrop-blur-sm dark:border-emerald-500/30 dark:from-emerald-950/20 dark:to-neutral-900/70"
                >
                    <div class="flex items-center justify-between text-emerald-600 dark:text-emerald-400">
                        <span class="text-xs font-medium uppercase tracking-wider">Markdowns</span>
                        <TrendingDown class="size-4" />
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">{{ stats.discounts_count }}</span>
                        <span class="text-xs text-emerald-600/70 dark:text-emerald-400/70">auto sales</span>
                    </div>
                </div>

                <!-- Card 3: Donations Dispatched -->
                <div
                    class="relative overflow-hidden rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-50/50 to-white/70 p-4 shadow-sm backdrop-blur-sm dark:border-sky-500/30 dark:from-sky-950/20 dark:to-neutral-900/70"
                >
                    <div class="flex items-center justify-between text-sky-600 dark:text-sky-400">
                        <span class="text-xs font-medium uppercase tracking-wider">Donations</span>
                        <HeartHandshake class="size-4" />
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tracking-tight text-sky-700 dark:text-sky-300">{{ stats.donations_count }}</span>
                        <span class="text-xs text-sky-600/70 dark:text-sky-400/70">partner feeds</span>
                    </div>
                </div>

                <!-- Card 4: Alerts Dispatched -->
                <div
                    class="relative overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-50/50 to-white/70 p-4 shadow-sm backdrop-blur-sm dark:border-amber-500/30 dark:from-amber-950/20 dark:to-neutral-900/70"
                >
                    <div class="flex items-center justify-between text-amber-600 dark:text-amber-400">
                        <span class="text-xs font-medium uppercase tracking-wider">Alerts</span>
                        <AlertTriangle class="size-4" />
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tracking-tight text-amber-700 dark:text-amber-300">{{ stats.alerts_count }}</span>
                        <span class="text-xs text-amber-600/70 dark:text-amber-400/70">broadcasted</span>
                    </div>
                </div>

                <!-- Card 5: Manual Overrides -->
                <div
                    class="relative overflow-hidden rounded-2xl border border-violet-500/20 bg-gradient-to-br from-violet-50/50 to-white/70 p-4 shadow-sm backdrop-blur-sm dark:border-violet-500/30 dark:from-violet-950/20 dark:to-neutral-900/70"
                >
                    <div class="flex items-center justify-between text-violet-600 dark:text-violet-400">
                        <span class="text-xs font-medium uppercase tracking-wider">Overrides</span>
                        <ShieldAlert class="size-4" />
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tracking-tight text-violet-700 dark:text-violet-300">{{ stats.overrides_count }}</span>
                        <span class="text-xs text-violet-600/70 dark:text-violet-400/70">human in loop</span>
                    </div>
                </div>

                <!-- Card 6: Success Rate -->
                <div
                    class="relative overflow-hidden rounded-2xl border border-teal-500/20 bg-gradient-to-br from-teal-50/50 to-white/70 p-4 shadow-sm backdrop-blur-sm dark:border-teal-500/30 dark:from-teal-950/20 dark:to-neutral-900/70"
                >
                    <div class="flex items-center justify-between text-teal-600 dark:text-teal-400">
                        <span class="text-xs font-medium uppercase tracking-wider">Reliability</span>
                        <CheckCircle2 class="size-4" />
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tracking-tight text-teal-700 dark:text-teal-300">{{ stats.success_rate }}%</span>
                        <span class="text-xs text-teal-600/70 dark:text-teal-400/70">success rate</span>
                    </div>
                </div>
            </div>

            <!-- Filter Controls Bar -->
            <div class="flex flex-col gap-3 rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <!-- Search Input -->
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            v-model="search"
                            @input="handleSearchInput"
                            type="text"
                            placeholder="Search by SKU, product name, partner, or reasoning keywords..."
                            class="w-full rounded-xl border border-neutral-200 bg-neutral-50 py-2 pl-10 pr-9 text-sm text-neutral-900 transition placeholder:text-muted-foreground focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100"
                        />
                        <button
                            v-if="search"
                            @click="clearSearch"
                            type="button"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground transition hover:text-foreground"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <!-- Dropdown Filters Group -->
                    <div class="flex flex-wrap items-center gap-2.5">
                        <!-- Action Type Filter -->
                        <div class="flex items-center gap-1.5">
                            <Tag class="size-3.5 text-muted-foreground" />
                            <select
                                v-model="selectedActionType"
                                @change="applyFilters"
                                class="rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-medium text-neutral-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-200 sm:text-sm"
                            >
                                <option value="all">All Action Types</option>
                                <option value="discount_applied">Discounts Applied</option>
                                <option value="donation_requested">Donation Requests</option>
                                <option value="alert_sent">Alerts Dispatched</option>
                                <option value="manual_override">Manual Overrides</option>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div class="flex items-center gap-1.5">
                            <Filter class="size-3.5 text-muted-foreground" />
                            <select
                                v-model="selectedStatus"
                                @change="applyFilters"
                                class="rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-medium text-neutral-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-200 sm:text-sm"
                            >
                                <option value="all">All Statuses</option>
                                <option value="success">Success</option>
                                <option value="failed">Failed</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>

                        <!-- Date Filter -->
                        <div class="flex items-center gap-1.5">
                            <Calendar class="size-3.5 text-muted-foreground" />
                            <select
                                v-model="selectedDate"
                                @change="applyFilters"
                                class="rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-medium text-neutral-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-200 sm:text-sm"
                            >
                                <option value="all">All Time</option>
                                <option value="today">Today Only</option>
                                <option value="7days">Past 7 Days</option>
                                <option value="30days">Past 30 Days</option>
                            </select>
                        </div>

                        <!-- Reset Filters Button -->
                        <button
                            v-if="hasActiveFilters"
                            @click="resetAllFilters"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-xl border border-neutral-200 bg-white px-2.5 py-2 text-xs font-medium text-rose-600 transition hover:bg-rose-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-rose-400 dark:hover:bg-rose-950/30"
                        >
                            <X class="size-3.5" />
                            <span>Reset Filters</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Audit Log Table -->
            <div class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b border-neutral-200 bg-neutral-50/80 text-xs font-semibold uppercase tracking-wider text-neutral-600 dark:border-neutral-800 dark:bg-neutral-950/50 dark:text-neutral-400"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3.5 sm:px-6">Timestamp & Model</th>
                                <th scope="col" class="px-4 py-3.5">Product SKU / Name</th>
                                <th scope="col" class="px-4 py-3.5">Action Type</th>
                                <th scope="col" class="min-w-[280px] px-4 py-3.5">Autonomous Reasoning</th>
                                <th scope="col" class="px-4 py-3.5 text-center">Status</th>
                                <th scope="col" class="px-4 py-3.5 text-right sm:px-6">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/80">
                            <!-- Empty State -->
                            <tr v-if="logs.data.length === 0">
                                <td colspan="6" class="px-6 py-16 text-center">
                                    <div
                                        class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-neutral-100 text-neutral-400 dark:bg-neutral-800"
                                    >
                                        <Bot class="size-7" />
                                    </div>
                                    <h3 class="mt-4 text-base font-semibold text-neutral-900 dark:text-neutral-100">
                                        No AI agent logs match your filters
                                    </h3>
                                    <p class="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                                        Try broadening your search criteria or trigger a live simulation scan to populate autonomous entries.
                                    </p>
                                    <div class="mt-5 flex items-center justify-center gap-3">
                                        <button
                                            v-if="hasActiveFilters"
                                            @click="resetAllFilters"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-neutral-300 bg-white px-3.5 py-2 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                        >
                                            <RotateCcw class="size-3.5" />
                                            <span>Clear Filters</span>
                                        </button>
                                        <button
                                            @click="runSimulation"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-emerald-500"
                                        >
                                            <Sparkles class="size-3.5" />
                                            <span>Simulate AI Scan</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Data Rows -->
                            <tr v-for="log in logs.data" :key="log.id" class="transition-colors hover:bg-neutral-50/70 dark:hover:bg-neutral-800/40">
                                <!-- Timestamp & Engine Model -->
                                <td class="whitespace-nowrap px-4 py-4 align-top sm:px-6">
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-1.5 text-xs font-semibold text-neutral-900 dark:text-neutral-100">
                                            <Clock class="size-3 text-muted-foreground" />
                                            <span>{{ log.created_at_human }}</span>
                                        </div>
                                        <span class="mt-0.5 font-mono text-[11px] text-muted-foreground" :title="log.created_at">
                                            {{ log.created_at ? new Date(log.created_at).toLocaleString() : 'Just now' }}
                                        </span>
                                        <div class="mt-1.5 flex items-center gap-1">
                                            <span
                                                class="inline-flex items-center rounded-md bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                            >
                                                {{ getModelBadge(log.payload) }}
                                            </span>
                                            <span
                                                v-if="log.payload.is_overridden"
                                                class="inline-flex items-center rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-950/60 dark:text-violet-300"
                                                title="This autonomous action was manually adjusted"
                                            >
                                                Overridden
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Product Info -->
                                <td class="px-4 py-4 align-top">
                                    <div v-if="log.product" class="flex flex-col">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono text-xs font-bold text-neutral-900 dark:text-neutral-100">
                                                {{ log.product.sku }}
                                            </span>
                                            <span
                                                v-if="log.product.category"
                                                class="rounded-md bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400"
                                            >
                                                {{ log.product.category }}
                                            </span>
                                        </div>
                                        <span
                                            class="mt-0.5 line-clamp-1 text-sm font-medium text-neutral-800 dark:text-neutral-200"
                                            :title="log.product.name"
                                        >
                                            {{ log.product.name }}
                                        </span>
                                        <div class="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                            <span v-if="log.product.discount_price">
                                                <span class="text-muted-foreground/70 line-through"
                                                    >${{ Number(log.product.original_price).toFixed(2) }}</span
                                                >
                                                <span class="ml-1 font-semibold text-emerald-600 dark:text-emerald-400"
                                                    >${{ Number(log.product.discount_price).toFixed(2) }}</span
                                                >
                                            </span>
                                            <span v-else-if="log.product.original_price"> ${{ Number(log.product.original_price).toFixed(2) }} </span>
                                            <span v-if="log.product.expiration_date" class="text-[11px]">
                                                • Exp: {{ log.product.expiration_date }}
                                            </span>
                                        </div>
                                    </div>
                                    <div v-else class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <div class="flex size-6 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                                            <Cpu class="size-3.5 text-neutral-500" />
                                        </div>
                                        <div>
                                            <span class="font-semibold text-neutral-700 dark:text-neutral-300">System Daemon</span>
                                            <p class="text-[10px] text-muted-foreground">Inventory cycle monitor</p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Action Type Badge -->
                                <td class="whitespace-nowrap px-4 py-4 align-top">
                                    <!-- Discount Applied -->
                                    <div v-if="log.action_type === 'discount_applied'" class="flex flex-col items-start gap-1">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                                        >
                                            <Percent class="size-3.5" />
                                            <span>Discount Applied</span>
                                        </span>
                                        <span
                                            v-if="log.payload.discount_percentage"
                                            class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400"
                                        >
                                            -{{ log.payload.discount_percentage }}% Markdown
                                        </span>
                                    </div>

                                    <!-- Donation Requested -->
                                    <div v-else-if="log.action_type === 'donation_requested'" class="flex flex-col items-start gap-1">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-sky-500/20 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300"
                                        >
                                            <HeartHandshake class="size-3.5" />
                                            <span>Donation Reserved</span>
                                        </span>
                                        <span
                                            v-if="log.partner?.name || log.payload.partner_name || log.payload.recipient"
                                            class="line-clamp-1 max-w-[140px] text-[11px] font-medium text-sky-600 dark:text-sky-400"
                                        >
                                            → {{ log.partner?.name || log.payload.partner_name || log.payload.recipient }}
                                        </span>
                                    </div>

                                    <!-- Alert Sent -->
                                    <div v-else-if="log.action_type === 'alert_sent'" class="flex flex-col items-start gap-1">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-500/20 bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                                        >
                                            <AlertTriangle class="size-3.5" />
                                            <span>Alert Broadcasted</span>
                                        </span>
                                        <span class="text-[11px] text-amber-600/90 dark:text-amber-400/90"> Inventory alert </span>
                                    </div>

                                    <!-- Manual Override -->
                                    <div v-else-if="log.action_type === 'manual_override'" class="flex flex-col items-start gap-1">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-violet-500/20 bg-violet-500/10 px-2.5 py-1 text-xs font-semibold text-violet-700 dark:text-violet-300"
                                        >
                                            <ShieldAlert class="size-3.5" />
                                            <span>Manual Override</span>
                                        </span>
                                        <span v-if="log.payload.performed_by" class="text-[11px] font-medium text-violet-600 dark:text-violet-400">
                                            By {{ log.payload.performed_by }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Reasoning Summary -->
                                <td class="px-4 py-4 align-top">
                                    <div class="flex flex-col gap-1.5">
                                        <p class="text-xs font-normal leading-relaxed text-neutral-800 dark:text-neutral-200 sm:text-sm">
                                            {{ log.reasoning }}
                                        </p>

                                        <!-- Highlight Chips from Payload -->
                                        <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                                            <span
                                                v-if="log.payload.rule"
                                                class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-[10px] dark:bg-neutral-800"
                                            >
                                                rule: {{ log.payload.rule }}
                                            </span>
                                            <span
                                                v-if="log.payload.confidence"
                                                class="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400"
                                            >
                                                {{ Math.round(Number(log.payload.confidence) * 100) }}% confidence
                                            </span>
                                            <span
                                                v-if="log.payload.override_reason"
                                                class="rounded bg-violet-500/10 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:text-violet-300"
                                            >
                                                Overridden: "{{ log.payload.override_reason }}"
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td class="whitespace-nowrap px-4 py-4 text-center align-top">
                                    <span
                                        v-if="log.status === 'success'"
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                                    >
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                        Success
                                    </span>
                                    <span
                                        v-else-if="log.status === 'failed'"
                                        class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-400"
                                    >
                                        <span class="size-1.5 rounded-full bg-rose-500"></span>
                                        Failed
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-400"
                                    >
                                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                                        Pending
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="whitespace-nowrap px-4 py-4 text-right align-top sm:px-6">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Inspect JSON Payload Button -->
                                        <button
                                            @click="openJsonModal(log)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-neutral-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-neutral-700 shadow-sm transition hover:bg-neutral-50 active:translate-y-0.5 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700/80"
                                            title="Inspect raw JSON payload from AI engine"
                                        >
                                            <Code2 class="size-3.5 text-sky-600 dark:text-sky-400" />
                                            <span>Inspect JSON</span>
                                        </button>

                                        <!-- Manual Override Button -->
                                        <button
                                            v-if="log.product_id && log.action_type !== 'manual_override'"
                                            @click="openOverrideModal(log)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-violet-200 bg-violet-50/50 px-2.5 py-1.5 text-xs font-semibold text-violet-700 shadow-sm transition hover:bg-violet-100 active:translate-y-0.5 dark:border-violet-900/60 dark:bg-violet-950/30 dark:text-violet-300 dark:hover:bg-violet-900/50"
                                            title="Manually revert or adjust this automated decision"
                                        >
                                            <RotateCcw class="size-3.5 text-violet-600 dark:text-violet-400" />
                                            <span>Override</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div
                    v-if="logs.total > 0"
                    class="flex flex-col gap-3 border-t border-neutral-200 px-4 py-4 dark:border-neutral-800 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                >
                    <div class="text-xs text-muted-foreground">
                        Showing
                        <span class="font-semibold text-foreground">{{ logs.from || 0 }}</span>
                        to
                        <span class="font-semibold text-foreground">{{ logs.to || 0 }}</span>
                        of
                        <span class="font-semibold text-foreground">{{ logs.total }}</span>
                        audit entries
                    </div>

                    <div class="flex items-center gap-1">
                        <template v-for="(link, index) in logs.links" :key="index">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                :class="[
                                    'inline-flex items-center justify-center rounded-lg px-3 py-1.5 text-xs font-medium transition',
                                    link.active
                                        ? 'bg-emerald-600 text-white shadow-sm'
                                        : 'border border-neutral-200 bg-white text-neutral-700 hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                ]"
                                preserve-scroll
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span
                                v-else
                                class="inline-flex items-center justify-center px-2 py-1.5 text-xs text-muted-foreground/60"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- MODAL: Inspect JSON Payload Formatted Code Viewer                   -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div
            v-if="isJsonModalOpen && selectedLogForJson"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm transition-all"
        >
            <div
                class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-2xl dark:border-neutral-800 dark:bg-neutral-900"
                @click.stop
            >
                <!-- Modal Header -->
                <div
                    class="flex items-center justify-between border-b border-neutral-200 bg-neutral-50/70 px-6 py-4 dark:border-neutral-800 dark:bg-neutral-950/40"
                >
                    <div class="flex items-center gap-3">
                        <div class="flex size-9 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-400">
                            <Code2 class="size-5" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Raw AI Decision Payload</h3>
                                <span
                                    class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400"
                                >
                                    #LOG-{{ selectedLogForJson.id }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">Verified payload from {{ getModelBadge(selectedLogForJson.payload) }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            @click="copyJsonToClipboard"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-neutral-200 bg-white px-3 py-1.5 text-xs font-semibold text-neutral-700 shadow-sm transition hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                        >
                            <Check v-if="hasCopiedJson" class="size-3.5 text-emerald-500" />
                            <Copy v-else class="size-3.5" />
                            <span>{{ hasCopiedJson ? 'Copied!' : 'Copy JSON' }}</span>
                        </button>

                        <button
                            @click="closeJsonModal"
                            type="button"
                            class="rounded-xl p-2 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        >
                            <X class="size-5" />
                        </button>
                    </div>
                </div>

                <!-- Modal Sub-Header: Payload Metadata Chips -->
                <div
                    class="grid grid-cols-2 gap-3 border-b border-neutral-200 bg-neutral-50/40 px-6 py-3 dark:border-neutral-800 dark:bg-neutral-950/20 sm:grid-cols-4"
                >
                    <div>
                        <span class="text-[10px] font-bold uppercase text-muted-foreground">Action Type</span>
                        <p class="mt-0.5 text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                            {{ selectedLogForJson.action_type }}
                        </p>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase text-muted-foreground">Target Product</span>
                        <p class="mt-0.5 truncate text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                            {{ selectedLogForJson.product ? selectedLogForJson.product.sku : 'System Routine' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase text-muted-foreground">Decision Rule</span>
                        <p class="mt-0.5 truncate font-mono text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                            {{ selectedLogForJson.payload.rule || 'expiring_threshold' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase text-muted-foreground">Execution Status</span>
                        <p class="mt-0.5 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                            {{ selectedLogForJson.status }}
                        </p>
                    </div>
                </div>

                <!-- Code Canvas Body -->
                <div class="flex-1 overflow-auto bg-neutral-950 p-6 font-mono text-xs text-neutral-200">
                    <pre class="whitespace-pre-wrap leading-relaxed"><code v-html="syntaxHighlightedJson"></code></pre>
                </div>

                <!-- Modal Footer -->
                <div
                    class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50/70 px-6 py-3.5 dark:border-neutral-800 dark:bg-neutral-950/40"
                >
                    <span class="text-xs text-muted-foreground"> Payload size: {{ formattedJsonString.length }} bytes </span>
                    <button
                        @click="closeJsonModal"
                        type="button"
                        class="rounded-xl bg-neutral-900 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:opacity-90 dark:bg-neutral-100 dark:text-neutral-900"
                    >
                        Close Viewer
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- MODAL: Manual Operator Override                                    -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div
            v-if="isOverrideModalOpen && selectedLogForOverride"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm transition-all"
        >
            <div
                class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-2xl dark:border-neutral-800 dark:bg-neutral-900"
                @click.stop
            >
                <!-- Modal Header -->
                <div
                    class="flex items-center justify-between border-b border-neutral-200 bg-violet-50/40 px-6 py-4 dark:border-neutral-800 dark:bg-violet-950/20"
                >
                    <div class="flex items-center gap-3">
                        <div class="flex size-9 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400">
                            <ShieldAlert class="size-5" />
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-neutral-900 dark:text-neutral-100">
                                Manual Override: Log #{{ selectedLogForOverride.id }}
                            </h3>
                            <p class="text-xs text-muted-foreground">Adjust or revert automated decisions with competition audit accountability.</p>
                        </div>
                    </div>
                    <button
                        @click="closeOverrideModal"
                        type="button"
                        class="rounded-xl p-2 text-neutral-400 transition hover:bg-neutral-100 dark:hover:bg-neutral-800"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="flex-1 space-y-5 overflow-y-auto p-6">
                    <!-- Context Summary Card -->
                    <div class="rounded-xl border border-neutral-200 bg-neutral-50/70 p-3.5 text-xs dark:border-neutral-800 dark:bg-neutral-950/50">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="font-mono font-bold text-neutral-900 dark:text-neutral-100">
                                    {{ selectedLogForOverride.product?.sku }}
                                </span>
                                <p class="mt-0.5 font-medium text-neutral-700 dark:text-neutral-300">
                                    {{ selectedLogForOverride.product?.name }}
                                </p>
                            </div>
                            <span class="rounded bg-neutral-200 px-2 py-0.5 text-[10px] font-semibold dark:bg-neutral-800">
                                Current: {{ selectedLogForOverride.action_type }}
                            </span>
                        </div>
                        <p class="mt-2 line-clamp-2 text-[11px] italic text-muted-foreground">"{{ selectedLogForOverride.reasoning }}"</p>
                    </div>

                    <!-- Error Alert inside modal -->
                    <div
                        v-if="overrideErrorMessage"
                        class="flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-700 dark:text-rose-300"
                    >
                        <AlertCircle class="size-4 shrink-0" />
                        <span>{{ overrideErrorMessage }}</span>
                    </div>

                    <!-- Override Action Selector -->
                    <div>
                        <label class="mb-2 block text-xs font-semibold text-neutral-700 dark:text-neutral-300"> Select Override Strategy </label>
                        <div class="space-y-2">
                            <!-- Revert Option -->
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                :class="
                                    overrideAction === 'revert'
                                        ? 'border-emerald-500 bg-emerald-50/30 dark:bg-emerald-950/20'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40'
                                "
                            >
                                <input
                                    type="radio"
                                    name="override_action"
                                    value="revert"
                                    v-model="overrideAction"
                                    class="mt-0.5 text-emerald-600 focus:ring-emerald-500"
                                />
                                <div>
                                    <span class="text-xs font-bold text-neutral-900 dark:text-neutral-100"> Revert Decision to Normal Stock </span>
                                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                                        Cancels discount or donation reservation, restoring product to active inventory.
                                    </p>
                                </div>
                            </label>

                            <!-- Modify Discount Option (if applicable) -->
                            <label
                                v-if="selectedLogForOverride.action_type === 'discount_applied'"
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                :class="
                                    overrideAction === 'modify_discount'
                                        ? 'border-emerald-500 bg-emerald-50/30 dark:bg-emerald-950/20'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40'
                                "
                            >
                                <input
                                    type="radio"
                                    name="override_action"
                                    value="modify_discount"
                                    v-model="overrideAction"
                                    class="mt-0.5 text-emerald-600 focus:ring-emerald-500"
                                />
                                <div>
                                    <span class="text-xs font-bold text-neutral-900 dark:text-neutral-100"> Adjust Markdown Percentage </span>
                                    <p class="mt-0.5 text-[11px] text-muted-foreground">Change the dynamic discount rate to a custom percentage.</p>
                                </div>
                            </label>

                            <!-- Reassign Partner Option (if applicable) -->
                            <label
                                v-if="selectedLogForOverride.action_type === 'donation_requested'"
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                :class="
                                    overrideAction === 'reassign_partner'
                                        ? 'border-sky-500 bg-sky-50/30 dark:bg-sky-950/20'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/40'
                                "
                            >
                                <input
                                    type="radio"
                                    name="override_action"
                                    value="reassign_partner"
                                    v-model="overrideAction"
                                    class="mt-0.5 text-sky-600 focus:ring-sky-500"
                                />
                                <div>
                                    <span class="text-xs font-bold text-neutral-900 dark:text-neutral-100"> Reassign Donation Partner </span>
                                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                                        Route the surplus batch to an alternate verified non-profit charity.
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Dynamic field: Discount Percentage Slider & Preview -->
                    <div
                        v-if="overrideAction === 'modify_discount'"
                        class="space-y-2 rounded-xl border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-950"
                    >
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-neutral-700 dark:text-neutral-300">New Discount Rate:</span>
                            <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">{{ overrideDiscountPercentage }}% OFF</span>
                        </div>
                        <input type="range" min="5" max="90" step="5" v-model.number="overrideDiscountPercentage" class="w-full accent-emerald-600" />
                        <div class="mt-1 flex justify-between text-[11px] text-muted-foreground">
                            <span>Original: ${{ Number(selectedLogForOverride.product?.original_price || 0).toFixed(2) }}</span>
                            <span class="font-semibold text-foreground">New Price: ${{ previewModifiedPrice }}</span>
                        </div>
                    </div>

                    <!-- Dynamic field: Reassign Partner Dropdown -->
                    <div v-if="overrideAction === 'reassign_partner'" class="space-y-1.5">
                        <label class="block text-xs font-semibold text-neutral-700 dark:text-neutral-300"> Select New Donation Partner </label>
                        <select
                            v-model="overridePartnerId"
                            class="w-full rounded-xl border border-neutral-200 bg-white px-3.5 py-2 text-xs text-neutral-900 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100 sm:text-sm"
                        >
                            <option value="" disabled>Choose verified recipient...</option>
                            <option v-for="partner in partners" :key="partner.id" :value="partner.id">
                                {{ partner.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Justification / Reason Textarea -->
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            Override Rationale / Justification <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            v-model="overrideReason"
                            rows="3"
                            placeholder="State the regulatory or operational reason for modifying this autonomous decision..."
                            class="w-full rounded-xl border border-neutral-200 bg-white p-3 text-xs text-neutral-900 transition placeholder:text-muted-foreground focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100 sm:text-sm"
                        ></textarea>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div
                    class="flex items-center justify-end gap-2.5 border-t border-neutral-200 bg-neutral-50/70 px-6 py-4 dark:border-neutral-800 dark:bg-neutral-950/40"
                >
                    <button
                        @click="closeOverrideModal"
                        type="button"
                        class="rounded-xl border border-neutral-200 bg-white px-4 py-2 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-300"
                    >
                        Cancel
                    </button>
                    <button
                        :disabled="isSubmittingOverride"
                        @click="submitManualOverride"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-violet-500 active:translate-y-0.5 disabled:opacity-60"
                    >
                        <RefreshCw v-if="isSubmittingOverride" class="size-3.5 animate-spin" />
                        <ShieldCheck v-else class="size-3.5" />
                        <span>{{ isSubmittingOverride ? 'Executing Override...' : 'Apply Manual Override' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
