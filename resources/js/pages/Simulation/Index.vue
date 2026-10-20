<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    ArrowRight,
    ArrowUpDown,
    Bot,
    Calendar,
    CalendarClock,
    Check,
    CheckCircle2,
    Clock,
    Code2,
    Copy,
    Cpu,
    ExternalLink,
    Eye,
    Flame,
    FlaskConical,
    HandHeart,
    HeartHandshake,
    Info,
    Package,
    Percent,
    Play,
    RefreshCw,
    RotateCcw,
    Send,
    ShieldCheck,
    Sparkles,
    Tag,
    TrendingDown,
    Zap,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ProductItem {
    id: number;
    sku: string;
    name: string;
    category: string;
    original_price: number;
    discount_price: number | null;
    quantity: number;
    unit: string;
    status: string;
    expiration_date: string;
    days_remaining: number;
}

interface PartnerItem {
    id: number;
    name: string;
    contact_person?: string | null;
    email?: string | null;
    phone?: string | null;
    impact_score?: number;
}

interface AgentLogItem {
    id: number;
    product_id: number | null;
    partner_id: number | null;
    action_type: string;
    reasoning: string;
    payload: Record<string, any>;
    status: string;
    created_at_human?: string;
    product?: { id: number; name: string; sku: string };
    partner?: { id: number; name: string };
}

interface ConfigInfo {
    has_gemini: boolean;
    gemini_model: string;
    has_groq: boolean;
    groq_model: string;
    has_n8n: boolean;
    n8n_url: string | null;
    mock_mode_default: boolean;
}

interface SimulationResult {
    success: boolean;
    message: string;
    product: ProductItem;
    decision: {
        action: 'discount' | 'donate' | 'none';
        discount_percentage: number;
        recommended_partner_id: number | null;
        reasoning: string;
        provider: string;
    };
    executed_action: {
        type: string;
        label: string;
        status: string;
        discount_percentage?: number;
        original_price?: number;
        new_price?: number;
        partner_id?: number;
        partner_name?: string;
        summary: string;
    };
    agent_log?: AgentLogItem | null;
    n8n?: {
        configured: boolean;
        url: string | null;
        status: string;
        http_code?: number | null;
        payload: Record<string, any>;
        error?: string;
    };
}

const props = defineProps<{
    products: ProductItem[];
    partners: PartnerItem[];
    recentLogs: AgentLogItem[];
    config: ConfigInfo;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'AI Simulation Sandbox', href: '/simulation' },
];

const page = usePage();

// ─── Control State ──────────────────────────────────────────────────────────
const selectedProductId = ref<number | ''>(props.products[0]?.id ?? '');
const selectedDateOffset = ref<number | 'custom'>(1);
const customExpirationDays = ref<number>(3);
const selectedProvider = ref<'auto' | 'gemini' | 'groq' | 'mock'>('auto');
const dispatchN8n = ref<boolean>(props.config.has_n8n);
const forceResetStatus = ref<boolean>(true);

const isEvaluating = ref(false);
const isResetting = ref(false);
const hasCopiedJson = ref(false);
const activeTab = ref<'decision' | 'json' | 'webhook'>('decision');

// Local interactive result container
const liveResult = ref<SimulationResult | null>(
    ((page.props as any).flash?.simulation_result as SimulationResult) || null,
);

// Keep synchronized if page flash updates
watch(
    () => (page.props as any).flash?.simulation_result,
    (newVal) => {
        if (newVal) {
            liveResult.value = newVal;
        }
    },
);

// ─── Selected Product Helper ────────────────────────────────────────────────
const selectedProduct = computed(() => {
    return props.products.find((p) => p.id === Number(selectedProductId.value)) || null;
});

// Computed simulated expiration preview
const simulatedExpirationDate = computed(() => {
    const days = selectedDateOffset.value === 'custom'
        ? customExpirationDays.value
        : Number(selectedDateOffset.value);
    const d = new Date();
    d.setDate(d.getDate() + days);
    return {
        days,
        formatted: d.toISOString().split('T')[0],
        dateObj: d,
    };
});

// ─── Preset Offsets ─────────────────────────────────────────────────────────
const presetOffsets = [
    {
        days: 1,
        title: '1 Day (Urgent Perishable)',
        subtitle: 'Triggers <48h Zero-Waste Donation Protocol',
        expected: 'Immediate Donation',
        badgeColor: 'bg-rose-500/10 text-rose-500 border-rose-500/20',
        icon: Flame,
    },
    {
        days: 4,
        title: '4 Days (Markdown Window)',
        subtitle: 'Triggers 3–7d Clearance Markdown Algorithm',
        expected: 'Clearance Discount',
        badgeColor: 'bg-amber-500/10 text-amber-500 border-amber-500/20',
        icon: Tag,
    },
    {
        days: 14,
        title: '14 Days (Optimal Shelf-Life)',
        subtitle: 'Stock safe. Retained at standard retail price',
        expected: 'No Action Needed',
        badgeColor: 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20',
        icon: ShieldCheck,
    },
];

// ─── Run Evaluation Trigger ─────────────────────────────────────────────────
const runEvaluation = () => {
    if (!selectedProductId.value) return;

    isEvaluating.value = true;
    activeTab.value = 'decision';

    const effectiveOffset = selectedDateOffset.value === 'custom'
        ? customExpirationDays.value
        : Number(selectedDateOffset.value);

    router.post(
        '/simulation/trigger',
        {
            product_id: Number(selectedProductId.value),
            date_offset: effectiveOffset,
            provider: selectedProvider.value,
            dispatch_n8n: dispatchN8n.value,
            force_reset_status: forceResetStatus.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const result = (page.props as any).flash?.simulation_result;
                if (result) {
                    liveResult.value = result;
                }
            },
            onFinish: () => {
                isEvaluating.value = false;
            },
        },
    );
};

// ─── Reset Product Trigger ──────────────────────────────────────────────────
const resetProductState = () => {
    if (!selectedProductId.value) return;

    isResetting.value = true;
    router.post(
        `/simulation/reset/${selectedProductId.value}`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // If the currently inspected result was for this product, reset its status
                if (liveResult.value && liveResult.value.product.id === Number(selectedProductId.value)) {
                    liveResult.value = null;
                }
            },
            onFinish: () => {
                isResetting.value = false;
            },
        },
    );
};

// ─── JSON Copy Helper ───────────────────────────────────────────────────────
const copyResultJson = async () => {
    if (!liveResult.value) return;
    try {
        const payload = liveResult.value.n8n?.payload || liveResult.value;
        await navigator.clipboard.writeText(JSON.stringify(payload, null, 2));
        hasCopiedJson.value = true;
        setTimeout(() => {
            hasCopiedJson.value = false;
        }, 2200);
    } catch (e) {
        console.error('Failed to copy', e);
    }
};

const formatCurrency = (val?: number | null) => {
    if (val === undefined || val === null) return '0';
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(val);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="AI Simulation Sandbox - Live Presentation Demo" />

        <div class="space-y-6 p-4 sm:p-6 lg:p-8">
            <!-- Header Banner -->
            <div
                class="relative overflow-hidden rounded-2xl border border-neutral-200/80 bg-gradient-to-br from-white via-neutral-50/50 to-emerald-50/20 p-6 shadow-sm dark:border-neutral-800/80 dark:from-neutral-900 dark:via-neutral-900/60 dark:to-emerald-950/20"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <span
                                class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400"
                            >
                                <FlaskConical class="h-5 w-5" />
                            </span>
                            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                                AI Simulation Sandbox
                            </h1>
                            <span
                                class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400"
                            >
                                <Zap class="h-3 w-3 animate-pulse" /> Live Pitch Mode
                            </span>
                        </div>
                        <p class="mt-1.5 max-w-2xl text-sm text-neutral-600 dark:text-neutral-400">
                            Perform on-demand autonomous inventory evaluations during live judge pitches. Simulate
                            varying shelf-life conditions, observe real-time AI reasoning, and verify automated n8n
                            webhook triggers.
                        </p>
                    </div>

                    <!-- Cloud Engine Connectivity Pills -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white/80 px-3 py-1.5 text-xs font-medium text-neutral-700 shadow-sm backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/80 dark:text-neutral-300"
                        >
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="config.has_gemini ? 'bg-emerald-500' : 'bg-neutral-400'"
                            ></span>
                            <Bot class="h-3.5 w-3.5 text-neutral-500" />
                            <span>Gemini 1.5:</span>
                            <span :class="config.has_gemini ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-400'">
                                {{ config.has_gemini ? 'Active' : 'Fallback Mock' }}
                            </span>
                        </div>

                        <div
                            class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white/80 px-3 py-1.5 text-xs font-medium text-neutral-700 shadow-sm backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/80 dark:text-neutral-300"
                        >
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="config.has_groq ? 'bg-emerald-500' : 'bg-neutral-400'"
                            ></span>
                            <Cpu class="h-3.5 w-3.5 text-neutral-500" />
                            <span>Groq LLaMA:</span>
                            <span :class="config.has_groq ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-400'">
                                {{ config.has_groq ? 'Ready' : 'Standby' }}
                            </span>
                        </div>

                        <div
                            class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white/80 px-3 py-1.5 text-xs font-medium text-neutral-700 shadow-sm backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/80 dark:text-neutral-300"
                        >
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="config.has_n8n ? 'bg-indigo-500' : 'bg-amber-400'"
                            ></span>
                            <Send class="h-3.5 w-3.5 text-neutral-500" />
                            <span>n8n Webhook:</span>
                            <span :class="config.has_n8n ? 'text-indigo-600 dark:text-indigo-400' : 'text-amber-500'">
                                {{ config.has_n8n ? 'Live Endpoint' : 'Simulated' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Interactive Workbench (2 Columns: Controls on Left, Live Result Box on Right) -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
                <!-- LEFT COLUMN: CONTROLS & DATE OFFSET TOOL -->
                <div class="space-y-6 lg:col-span-5">
                    <!-- Step 1: Target Product Selection -->
                    <div
                        class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-white">
                                <span
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-neutral-900 text-xs font-bold text-white dark:bg-white dark:text-neutral-900"
                                >
                                    1
                                </span>
                                Select Product to Evaluate
                            </label>
                            <span class="text-xs text-neutral-500">{{ products.length }} items available</span>
                        </div>

                        <!-- Dropdown Select -->
                        <div class="relative">
                            <select
                                v-model="selectedProductId"
                                class="w-full rounded-xl border border-neutral-300 bg-white px-3.5 py-2.5 text-sm font-medium text-neutral-900 shadow-sm transition focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:focus:border-emerald-500"
                            >
                                <option
                                    v-for="item in products"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    [{{ item.sku }}] {{ item.name }} • Exp: {{ item.expiration_date }} ({{ item.days_remaining }}d left) • {{ item.status }}
                                </option>
                            </select>
                        </div>

                        <!-- Active Product Quick Inspector -->
                        <div
                            v-if="selectedProduct"
                            class="mt-3.5 rounded-xl border border-neutral-200/80 bg-neutral-50/70 p-3.5 dark:border-neutral-800/80 dark:bg-neutral-800/50"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <h4 class="font-semibold text-neutral-900 dark:text-white">
                                        {{ selectedProduct.name }}
                                    </h4>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                                        <span class="font-mono font-medium">{{ selectedProduct.sku }}</span>
                                        <span>•</span>
                                        <span>{{ selectedProduct.category }}</span>
                                        <span>•</span>
                                        <span>{{ selectedProduct.quantity }} {{ selectedProduct.unit }}</span>
                                    </div>
                                </div>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                    :class="{
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300': selectedProduct.status === 'active',
                                        'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300': selectedProduct.status === 'discounted',
                                        'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300': selectedProduct.status === 'reserved_donation',
                                    }"
                                >
                                    {{ selectedProduct.status.replace('_', ' ') }}
                                </span>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-2 border-t border-neutral-200/60 pt-2.5 text-xs dark:border-neutral-700/60">
                                <div>
                                    <span class="text-neutral-500">Retail Price:</span>
                                    <div class="font-medium text-neutral-900 dark:text-white">
                                        {{ formatCurrency(selectedProduct.original_price) }}
                                        <span v-if="selectedProduct.discount_price" class="text-amber-600 dark:text-amber-400">
                                            → {{ formatCurrency(selectedProduct.discount_price) }}
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <span class="text-neutral-500">Database Expiry:</span>
                                    <div class="font-medium text-neutral-900 dark:text-white">
                                        {{ selectedProduct.expiration_date }}
                                        <span class="font-mono text-neutral-500">({{ selectedProduct.days_remaining }}d)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Simulate Expiration Date Offset Tool -->
                    <div
                        class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-white">
                                <span
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-neutral-900 text-xs font-bold text-white dark:bg-white dark:text-neutral-900"
                                >
                                    2
                                </span>
                                Simulate Expiration Date Offset
                            </label>
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                <CalendarClock class="h-3.5 w-3.5" /> Fast Demo Presets
                            </span>
                        </div>

                        <p class="mb-3 text-xs text-neutral-500">
                            Temporarily override the product's shelf-life in the database to test how the AI reasoning engine
                            responds to different inventory horizons:
                        </p>

                        <!-- Preset Radio Cards -->
                        <div class="space-y-2">
                            <button
                                v-for="preset in presetOffsets"
                                :key="preset.days"
                                type="button"
                                @click="selectedDateOffset = preset.days"
                                class="w-full text-left transition-all duration-150 rounded-xl border p-3 flex items-start justify-between"
                                :class="selectedDateOffset === preset.days
                                    ? 'border-emerald-500 bg-emerald-50/40 ring-2 ring-emerald-500/20 dark:border-emerald-500 dark:bg-emerald-950/20'
                                    : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-800/40 dark:hover:bg-neutral-800'"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-lg"
                                        :class="selectedDateOffset === preset.days
                                            ? 'bg-emerald-500 text-white'
                                            : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'"
                                    >
                                        <component :is="preset.icon" class="h-4 w-4" />
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-neutral-900 dark:text-white">
                                                {{ preset.title }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-neutral-500 mt-0.5">
                                            {{ preset.subtitle }}
                                        </p>
                                    </div>
                                </div>

                                <span
                                    class="shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                    :class="preset.badgeColor"
                                >
                                    {{ preset.expected }}
                                </span>
                            </button>

                            <!-- Custom Offset Option -->
                            <div
                                class="rounded-xl border p-3 transition"
                                :class="selectedDateOffset === 'custom'
                                    ? 'border-emerald-500 bg-emerald-50/40 ring-2 ring-emerald-500/20 dark:border-emerald-500 dark:bg-emerald-950/20'
                                    : 'border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-800/40'"
                            >
                                <div class="flex items-center justify-between">
                                    <label class="flex items-center gap-2 text-xs font-semibold text-neutral-900 dark:text-white cursor-pointer">
                                        <input
                                            type="radio"
                                            name="date_offset"
                                            :checked="selectedDateOffset === 'custom'"
                                            @change="selectedDateOffset = 'custom'"
                                            class="text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span>Custom Offset (Number of Days)</span>
                                    </label>
                                    <div v-if="selectedDateOffset === 'custom'" class="flex items-center gap-2">
                                        <input
                                            v-model.number="customExpirationDays"
                                            type="number"
                                            min="0"
                                            max="90"
                                            class="w-20 rounded-lg border border-neutral-300 px-2 py-1 text-center text-xs font-semibold text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                        <span class="text-xs text-neutral-500">days ahead</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Live Date Computed Preview Bar -->
                        <div class="mt-3.5 flex items-center justify-between rounded-xl bg-neutral-100/90 px-3.5 py-2 text-xs dark:bg-neutral-800/80">
                            <span class="text-neutral-600 dark:text-neutral-400">Target Simulated Expiry Date:</span>
                            <span class="font-mono font-semibold text-neutral-900 dark:text-white">
                                {{ simulatedExpirationDate.formatted }} (in {{ simulatedExpirationDate.days }} day{{ simulatedExpirationDate.days !== 1 ? 's' : '' }})
                            </span>
                        </div>
                    </div>

                    <!-- Step 3: Execution Settings -->
                    <div
                        class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-white">
                                <span
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-neutral-900 text-xs font-bold text-white dark:bg-white dark:text-neutral-900"
                                >
                                    3
                                </span>
                                AI Engine & Dispatch Options
                            </label>
                        </div>

                        <div class="space-y-3">
                            <!-- Provider Radio Group -->
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 transition"
                                    :class="selectedProvider === 'auto'
                                        ? 'border-emerald-500 bg-emerald-50/50 text-emerald-800 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-300'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                >
                                    <input
                                        type="radio"
                                        value="auto"
                                        v-model="selectedProvider"
                                        class="text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <div>
                                        <div class="font-semibold">Auto (Recommended)</div>
                                        <div class="text-[10px] text-neutral-500">Cloud AI with Heuristic Fallback</div>
                                    </div>
                                </label>

                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 transition"
                                    :class="selectedProvider === 'mock'
                                        ? 'border-emerald-500 bg-emerald-50/50 text-emerald-800 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-300'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                >
                                    <input
                                        type="radio"
                                        value="mock"
                                        v-model="selectedProvider"
                                        class="text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <div>
                                        <div class="font-semibold">Deterministic Mock</div>
                                        <div class="text-[10px] text-neutral-500">Rule-based offline engine</div>
                                    </div>
                                </label>

                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 transition"
                                    :class="selectedProvider === 'gemini'
                                        ? 'border-emerald-500 bg-emerald-50/50 text-emerald-800 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-300'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                >
                                    <input
                                        type="radio"
                                        value="gemini"
                                        v-model="selectedProvider"
                                        class="text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <div>
                                        <div class="font-semibold">Gemini 1.5 Flash</div>
                                        <div class="text-[10px] text-neutral-500">Structured JSON Output</div>
                                    </div>
                                </label>

                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 transition"
                                    :class="selectedProvider === 'groq'
                                        ? 'border-emerald-500 bg-emerald-50/50 text-emerald-800 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-300'
                                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                >
                                    <input
                                        type="radio"
                                        value="groq"
                                        v-model="selectedProvider"
                                        class="text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <div>
                                        <div class="font-semibold">Groq LLaMA 3.1</div>
                                        <div class="text-[10px] text-neutral-500">Ultra-fast inference</div>
                                    </div>
                                </label>
                            </div>

                            <!-- n8n Dispatch Toggle -->
                            <div class="flex items-center justify-between rounded-xl border border-neutral-200/80 bg-neutral-50/60 p-3 dark:border-neutral-800/80 dark:bg-neutral-800/40">
                                <label class="flex items-center gap-2.5 cursor-pointer text-xs">
                                    <input
                                        type="checkbox"
                                        v-model="dispatchN8n"
                                        class="h-4 w-4 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <div>
                                        <span class="font-semibold text-neutral-900 dark:text-white">
                                            Trigger n8n Webhook Workflow
                                        </span>
                                        <p class="text-[11px] text-neutral-500">
                                            Dispatches live JSON payload to external automation pipeline
                                        </p>
                                    </div>
                                </label>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                    :class="config.has_n8n ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-400' : 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-300'"
                                >
                                    {{ config.has_n8n ? 'Webhook Configured' : 'Simulated Payload' }}
                                </span>
                            </div>
                        </div>

                        <!-- Prominent Evaluation CTA Button -->
                        <div class="mt-5 flex flex-col gap-2.5">
                            <button
                                type="button"
                                @click="runEvaluation"
                                :disabled="isEvaluating || !selectedProductId"
                                class="relative flex w-full items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-emerald-600 via-emerald-500 to-teal-600 py-3.5 px-6 font-semibold text-white shadow-lg shadow-emerald-500/25 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-emerald-500/30 active:translate-y-0 disabled:pointer-events-none disabled:opacity-60"
                            >
                                <RefreshCw v-if="isEvaluating" class="h-5 w-5 animate-spin" />
                                <Sparkles v-else class="h-5 w-5 text-emerald-100" />
                                <span class="text-base tracking-wide">
                                    {{ isEvaluating ? 'Evaluating AI Decision...' : 'Run Agent Evaluation Now' }}
                                </span>
                            </button>

                            <div class="flex items-center justify-between text-xs">
                                <button
                                    type="button"
                                    @click="resetProductState"
                                    :disabled="isResetting || !selectedProductId"
                                    class="inline-flex items-center gap-1.5 text-neutral-500 hover:text-neutral-900 dark:hover:text-white transition"
                                >
                                    <RotateCcw class="h-3.5 w-3.5" :class="{ 'animate-spin': isResetting }" />
                                    <span>Reset product to 14d Active State</span>
                                </button>
                                <Link
                                    href="/agent-logs"
                                    class="inline-flex items-center gap-1 text-emerald-600 hover:underline dark:text-emerald-400 font-medium"
                                >
                                    <span>View Audit Trail</span>
                                    <ArrowRight class="h-3.5 w-3.5" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: INTERACTIVE LIVE RESULT BOX -->
                <div class="space-y-6 lg:col-span-7">
                    <div
                        class="relative min-h-[580px] rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 flex flex-col justify-between"
                    >
                        <!-- Top Bar with Status and Tabs -->
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 pb-4 dark:border-neutral-800">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-3 w-3 relative">
                                        <span
                                            v-if="isEvaluating"
                                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
                                        ></span>
                                        <span
                                            class="relative inline-flex rounded-full h-3 w-3"
                                            :class="liveResult ? 'bg-emerald-500' : 'bg-neutral-300 dark:bg-neutral-700'"
                                        ></span>
                                    </span>
                                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">
                                        Live AI Decision & Execution Result
                                    </h3>
                                </div>

                                <div class="flex items-center gap-1 rounded-lg border border-neutral-200 bg-neutral-100 p-1 dark:border-neutral-800 dark:bg-neutral-800">
                                    <button
                                        type="button"
                                        @click="activeTab = 'decision'"
                                        class="rounded-md px-3 py-1 text-xs font-semibold transition"
                                        :class="activeTab === 'decision'
                                            ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                                            : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white'"
                                    >
                                        Decision & Actions
                                    </button>
                                    <button
                                        type="button"
                                        @click="activeTab = 'json'"
                                        class="rounded-md px-3 py-1 text-xs font-semibold transition"
                                        :class="activeTab === 'json'
                                            ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                                            : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white'"
                                    >
                                        Raw JSON Payload
                                    </button>
                                    <button
                                        type="button"
                                        @click="activeTab = 'webhook'"
                                        class="rounded-md px-3 py-1 text-xs font-semibold transition"
                                        :class="activeTab === 'webhook'
                                            ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                                            : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white'"
                                    >
                                        n8n Dispatch
                                    </button>
                                </div>
                            </div>

                            <!-- EVALUATING SHIMMER SKELETON -->
                            <div v-if="isEvaluating" class="mt-8 space-y-4 animate-pulse">
                                <div class="h-8 w-48 rounded-lg bg-neutral-200 dark:bg-neutral-800"></div>
                                <div class="h-28 rounded-xl bg-neutral-100 dark:bg-neutral-800/60"></div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="h-20 rounded-xl bg-neutral-100 dark:bg-neutral-800/60"></div>
                                    <div class="h-20 rounded-xl bg-neutral-100 dark:bg-neutral-800/60"></div>
                                </div>
                                <div class="h-32 rounded-xl bg-neutral-100 dark:bg-neutral-800/60"></div>
                            </div>

                            <!-- EMPTY STATE: WAITING FOR USER TO CLICK EVALUATE -->
                            <div
                                v-else-if="!liveResult"
                                class="my-auto flex flex-col items-center justify-center py-16 text-center"
                            >
                                <div
                                    class="relative flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-tr from-emerald-500/10 via-teal-500/20 to-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                                >
                                    <Bot class="h-10 w-10 animate-pulse" />
                                    <span
                                        class="absolute -top-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500 text-white shadow-md text-xs font-bold"
                                    >
                                        AI
                                    </span>
                                </div>
                                <h4 class="mt-5 text-lg font-bold text-neutral-900 dark:text-white">
                                    Autonomous Sandbox Ready
                                </h4>
                                <p class="mt-1.5 max-w-md text-sm text-neutral-500">
                                    Pick a product on the left deck, choose a simulated expiration horizon (e.g., 1 day for donation or 4 days for markdown), then click
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">"Run Agent Evaluation Now"</span>
                                    to see AI reasoning and live database synchronization.
                                </p>

                                <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1 text-xs text-neutral-600 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-400">
                                        <CheckCircle2 class="h-3.5 w-3.5 text-emerald-500" /> Real-time Execution
                                    </span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1 text-xs text-neutral-600 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-400">
                                        <CheckCircle2 class="h-3.5 w-3.5 text-emerald-500" /> Database State Mutated
                                    </span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1 text-xs text-neutral-600 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-400">
                                        <CheckCircle2 class="h-3.5 w-3.5 text-emerald-500" /> Audit Log Written
                                    </span>
                                </div>
                            </div>

                            <!-- RESULTS VIEW -->
                            <div v-else class="mt-5 space-y-5">
                                <!-- TAB 1: DECISION & EXECUTED ACTIONS -->
                                <div v-if="activeTab === 'decision'" class="space-y-4">
                                    <!-- Decision Banner Card -->
                                    <div
                                        class="rounded-xl border p-4 shadow-sm"
                                        :class="{
                                            'border-rose-300 bg-rose-50/60 dark:border-rose-900 dark:bg-rose-950/30': liveResult.decision.action === 'donate',
                                            'border-amber-300 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30': liveResult.decision.action === 'discount',
                                            'border-emerald-300 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/30': liveResult.decision.action === 'none',
                                        }"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg"
                                                    :class="{
                                                        'bg-rose-500 text-white': liveResult.decision.action === 'donate',
                                                        'bg-amber-500 text-white': liveResult.decision.action === 'discount',
                                                        'bg-emerald-500 text-white': liveResult.decision.action === 'none',
                                                    }"
                                                >
                                                    <HeartHandshake v-if="liveResult.decision.action === 'donate'" class="h-4 w-4" />
                                                    <Percent v-else-if="liveResult.decision.action === 'discount'" class="h-4 w-4" />
                                                    <ShieldCheck v-else class="h-4 w-4" />
                                                </span>
                                                <div>
                                                    <span class="text-xs font-semibold uppercase tracking-wider text-neutral-500">
                                                        AI Autonomous Decision
                                                    </span>
                                                    <h4 class="text-lg font-bold capitalize text-neutral-900 dark:text-white">
                                                        {{ liveResult.executed_action.label }}
                                                    </h4>
                                                </div>
                                            </div>

                                            <div class="flex items-center gap-2">
                                                <span class="rounded-md border border-neutral-300/80 bg-white/80 px-2.5 py-1 text-xs font-mono font-medium text-neutral-700 backdrop-blur dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                    Provider: {{ liveResult.decision.provider }}
                                                </span>
                                                <span class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">
                                                    Executed
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Summary Line -->
                                        <p class="mt-3 text-sm font-medium text-neutral-800 dark:text-neutral-200">
                                            {{ liveResult.executed_action.summary }}
                                        </p>
                                    </div>

                                    <!-- AI Reasoning Card -->
                                    <div
                                        class="rounded-xl border border-neutral-200/90 bg-neutral-50/70 p-4 dark:border-neutral-800/90 dark:bg-neutral-800/40"
                                    >
                                        <div class="mb-2 flex items-center justify-between">
                                            <span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-neutral-500">
                                                <Bot class="h-4 w-4 text-emerald-500" />
                                                AI Rationale & Environmental Logic
                                            </span>
                                            <span class="text-[11px] text-neutral-500 font-mono">
                                                Decision Engine Rule Verified
                                            </span>
                                        </div>
                                        <blockquote class="text-sm italic leading-relaxed text-neutral-800 dark:text-neutral-200 border-l-2 border-emerald-500 pl-3">
                                            "{{ liveResult.decision.reasoning }}"
                                        </blockquote>
                                    </div>

                                    <!-- Execution Metrics Grid -->
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <!-- Stock & Expiry -->
                                        <div class="rounded-xl border border-neutral-200/80 bg-white p-3 dark:border-neutral-800/80 dark:bg-neutral-800/60">
                                            <span class="text-xs text-neutral-500">Product & SKU</span>
                                            <div class="mt-0.5 font-semibold text-neutral-900 dark:text-white truncate">
                                                {{ liveResult.product.name }}
                                            </div>
                                            <div class="font-mono text-xs text-neutral-500">{{ liveResult.product.sku }}</div>
                                        </div>

                                        <!-- Price Delta or Charity -->
                                        <div class="rounded-xl border border-neutral-200/80 bg-white p-3 dark:border-neutral-800/80 dark:bg-neutral-800/60">
                                            <span class="text-xs text-neutral-500">
                                                {{ liveResult.decision.action === 'donate' ? 'Donation Partner' : 'Effective Price' }}
                                            </span>
                                            <div v-if="liveResult.decision.action === 'donate'" class="mt-0.5 font-semibold text-neutral-900 dark:text-white truncate">
                                                {{ liveResult.executed_action.partner_name || 'Primary Active Partner' }}
                                            </div>
                                            <div v-else-if="liveResult.product.discount_price" class="mt-0.5 font-semibold text-amber-600 dark:text-amber-400">
                                                {{ formatCurrency(liveResult.product.discount_price) }}
                                                <span class="text-xs text-neutral-400 line-through font-normal">
                                                    {{ formatCurrency(liveResult.product.original_price) }}
                                                </span>
                                            </div>
                                            <div v-else class="mt-0.5 font-semibold text-neutral-900 dark:text-white">
                                                {{ formatCurrency(liveResult.product.original_price) }} (Full Retail)
                                            </div>
                                            <div class="text-[11px] text-neutral-500">
                                                {{ liveResult.decision.action === 'discount' ? `-${liveResult.decision.discount_percentage}% Markdown Applied` : 'Updated in DB' }}
                                            </div>
                                        </div>

                                        <!-- Expiry Horizon in Database -->
                                        <div class="rounded-xl border border-neutral-200/80 bg-white p-3 dark:border-neutral-800/80 dark:bg-neutral-800/60">
                                            <span class="text-xs text-neutral-500">Updated DB Expiry</span>
                                            <div class="mt-0.5 font-semibold text-neutral-900 dark:text-white">
                                                {{ liveResult.product.expiration_date }}
                                            </div>
                                            <div class="font-mono text-xs text-neutral-500">
                                                {{ liveResult.product.days_remaining }} day{{ liveResult.product.days_remaining !== 1 ? 's' : '' }} remaining
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Audit Record Reference Callout -->
                                    <div
                                        v-if="liveResult.agent_log"
                                        class="flex items-center justify-between rounded-xl border border-emerald-500/20 bg-emerald-50/40 p-3 text-xs dark:bg-emerald-950/20"
                                    >
                                        <div class="flex items-center gap-2">
                                            <ShieldCheck class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                            <span>
                                                Recorded to permanent audit trail as
                                                <strong class="font-semibold text-neutral-900 dark:text-white">Log #{{ liveResult.agent_log.id }}</strong>
                                            </span>
                                        </div>
                                        <Link
                                            href="/agent-logs"
                                            class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
                                        >
                                            View in Audit Trail <ExternalLink class="h-3 w-3" />
                                        </Link>
                                    </div>
                                </div>

                                <!-- TAB 2: RAW JSON PAYLOAD VIEWER -->
                                <div v-else-if="activeTab === 'json'" class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs text-neutral-500">
                                            Structured output payload generated during this execution:
                                        </span>
                                        <button
                                            type="button"
                                            @click="copyResultJson"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                        >
                                            <Check v-if="hasCopiedJson" class="h-3.5 w-3.5 text-emerald-500" />
                                            <Copy v-else class="h-3.5 w-3.5" />
                                            <span>{{ hasCopiedJson ? 'Copied!' : 'Copy JSON' }}</span>
                                        </button>
                                    </div>

                                    <div
                                        class="max-h-[380px] overflow-auto rounded-xl border border-neutral-800 bg-neutral-950 p-4 font-mono text-xs leading-relaxed text-neutral-200 shadow-inner"
                                    >
                                        <pre class="whitespace-pre-wrap">{{ JSON.stringify(liveResult, null, 2) }}</pre>
                                    </div>
                                </div>

                                <!-- TAB 3: N8N WEBHOOK DISPATCH -->
                                <div v-else-if="activeTab === 'webhook'" class="space-y-4">
                                    <div
                                        class="rounded-xl border p-4"
                                        :class="liveResult.n8n?.status === 'dispatched_success'
                                            ? 'border-emerald-300 bg-emerald-50/50 dark:border-emerald-800 dark:bg-emerald-950/20'
                                            : 'border-neutral-200 bg-neutral-50/70 dark:border-neutral-800 dark:bg-neutral-800/40'"
                                    >
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <Send class="h-4 w-4 text-indigo-500" />
                                                <span class="font-semibold text-neutral-900 dark:text-white text-sm">
                                                    n8n External Automation Pipeline
                                                </span>
                                            </div>
                                            <span
                                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize"
                                                :class="liveResult.n8n?.status === 'dispatched_success'
                                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'"
                                            >
                                                {{ liveResult.n8n?.status || 'simulated' }}
                                            </span>
                                        </div>

                                        <p class="mt-2 text-xs text-neutral-600 dark:text-neutral-400">
                                            {{ liveResult.n8n?.configured
                                                ? `Payload successfully dispatched to configured webhook: ${liveResult.n8n.url}`
                                                : 'N8N_WEBHOOK_URL is not set in environment. Webhook payload is generated and verified in simulation mode.' }}
                                        </p>
                                    </div>

                                    <div class="space-y-1.5">
                                        <div class="flex items-center justify-between text-xs text-neutral-500">
                                            <span>Dispatched Webhook Payload:</span>
                                            <button
                                                type="button"
                                                @click="copyResultJson"
                                                class="inline-flex items-center gap-1 hover:text-neutral-900 dark:hover:text-white"
                                            >
                                                <Copy class="h-3 w-3" /> Copy
                                            </button>
                                        </div>
                                        <div
                                            class="max-h-[320px] overflow-auto rounded-xl border border-neutral-800 bg-neutral-950 p-4 font-mono text-xs text-neutral-200"
                                        >
                                            <pre class="whitespace-pre-wrap">{{ JSON.stringify(liveResult.n8n?.payload || {}, null, 2) }}</pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Live Quick Stats -->
                        <div class="mt-6 border-t border-neutral-200 pt-4 text-xs text-neutral-500 dark:border-neutral-800 flex flex-wrap items-center justify-between gap-2">
                            <span class="flex items-center gap-1.5">
                                <Activity class="h-3.5 w-3.5 text-emerald-500" />
                                Database mutations are instantaneous and transaction-locked.
                            </span>
                            <span class="font-mono">
                                EcoLoop Autonomous Engine v2.0
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent AI Evaluations Activity Feed -->
            <div
                v-if="recentLogs && recentLogs.length > 0"
                class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
            >
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Clock class="h-4 w-4 text-neutral-500" />
                        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">
                            Recent Autonomous Decisions Stream
                        </h3>
                    </div>
                    <Link
                        href="/agent-logs"
                        class="text-xs font-medium text-emerald-600 hover:underline dark:text-emerald-400"
                    >
                        View all logs →
                    </Link>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div
                        v-for="log in recentLogs.slice(0, 3)"
                        :key="log.id"
                        class="rounded-xl border border-neutral-200/80 bg-neutral-50/60 p-3.5 text-xs transition hover:border-neutral-300 dark:border-neutral-800/80 dark:bg-neutral-800/40"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                :class="{
                                    'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300': log.action_type === 'donation_requested',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300': log.action_type === 'discount_applied',
                                    'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300': log.action_type === 'alert_sent',
                                    'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300': log.action_type === 'manual_override',
                                }"
                            >
                                {{ log.action_type.replace('_', ' ') }}
                            </span>
                            <span class="text-[11px] text-neutral-400 font-mono">
                                Log #{{ log.id }}
                            </span>
                        </div>

                        <p class="mt-2 font-medium text-neutral-900 dark:text-white truncate">
                            {{ log.product?.name || 'General Inventory' }}
                        </p>
                        <p class="mt-1 line-clamp-2 text-neutral-500">
                            {{ log.reasoning }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
