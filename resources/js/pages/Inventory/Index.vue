<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Clock, Edit3, Handshake, Package, Plus, RefreshCw, Search, ShieldAlert, Tag, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface CategoryItem {
    id: number;
    name: string;
    slug?: string;
}

interface DonationPartnerItem {
    id: number;
    name: string;
    contact_person: string;
}

interface ProductItem {
    id: number;
    external_id: string | null;
    source_system: string | null;
    sku: string;
    name: string;
    description: string | null;
    category_id: number;
    category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    quantity: number;
    unit: string;
    original_price: number;
    discount_price: number | null;
    original_price_formatted: string;
    discount_price_formatted: string | null;
    expiration_date: string;
    expiration_date_formatted: string;
    days_remaining: number;
    urgency: 'critical' | 'warning' | 'safe' | 'expired';
    status: 'active' | 'discounted' | 'reserved_donation' | 'donated' | 'disposed' | 'sold_out';
    created_at?: string;
    received_quantity: number;
    donated_quantity: number;
    sold_quantity: number;
    batches: ProductBatchItem[];
}

interface ProductBatchItem {
    id: number;
    batch_code: string;
    source: string;
    received_at: string | null;
    quantity_received: number;
    quantity_available: number;
    quantity_donated: number;
    quantity_sold: number;
    expiration_date: string;
    days_remaining: number;
    status: string;
    discount_price: number | null;
    discount_approval: {
        status: string;
        recommended_percentage: number;
        approved_percentage: number | null;
    } | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedProducts {
    data: ProductItem[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

interface InventoryStats {
    total_items: number;
    critical_count: number;
    warning_count: number;
    discounted_count: number;
    reserved_donation_count: number;
}

interface AutoSyncSummary {
    new_products: number;
    new_batches: number;
    received_units: number;
    sales_transactions: number;
    sold_units: number;
    changed_products: number;
    demo_new_products?: number;
    demo_donated_products?: number;
    demo_donated_units?: number;
    demo_partner_count?: number;
}

interface AutoSyncState {
    auto_sync_enabled: boolean;
    last_synced_at: string | null;
    last_sync_summary: AutoSyncSummary | null;
}

interface Filters {
    search: string;
    category: string | number;
    status: string;
    urgency: string;
    sort_by: string;
    sort_order: string;
}

const props = defineProps<{
    products: PaginatedProducts;
    filters: Filters;
    categories: CategoryItem[];
    donation_partners: DonationPartnerItem[];
    stats: InventoryStats;
    auto_sync: AutoSyncState;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Inventory', href: '/inventory' },
];

const page = usePage();
const flashSuccess = computed(() => (page.props as any).flash?.success);
const flashError = computed(() => (page.props as any).flash?.error);
const isSyncing = ref(false);
const isTogglingAutoSync = ref(false);
const expandedProductIds = ref<number[]>([]);

const runAutoSyncNow = () => {
    isSyncing.value = true;
    router.post('/inventory/auto-sync/run', {}, {
        preserveScroll: true,
        onFinish: () => {
            isSyncing.value = false;
        },
    });
};

const toggleAutoSync = () => {
    isTogglingAutoSync.value = true;
    router.post('/inventory/auto-sync/toggle', {
        enabled: !props.auto_sync.auto_sync_enabled,
    }, {
        preserveScroll: true,
        onFinish: () => {
            isTogglingAutoSync.value = false;
        },
    });
};

// Filter state
const search = ref(props.filters.search || '');
const selectedCategory = ref(props.filters.category || '');
const selectedStatus = ref(props.filters.status || '');
const selectedUrgency = ref(props.filters.urgency || '');

// Debounce timer for search
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

const applyFilters = () => {
    router.get(
        '/inventory',
        {
            search: search.value || undefined,
            category: selectedCategory.value || undefined,
            status: selectedStatus.value || undefined,
            urgency: selectedUrgency.value || undefined,
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

const clearFilters = () => {
    search.value = '';
    selectedCategory.value = '';
    selectedStatus.value = '';
    selectedUrgency.value = '';
    router.get('/inventory', {}, { preserveState: true, replace: true });
};

const hasActiveFilters = computed(() => {
    return Boolean(search.value || selectedCategory.value || selectedStatus.value || selectedUrgency.value);
});

// Modal State: Apply Discount
const isDiscountModalOpen = ref(false);
const discountTargetProduct = ref<ProductItem | null>(null);
const discountPercentage = ref(30);
const discountReason = ref('');
const isSubmittingDiscount = ref(false);

const openDiscountModal = (product: ProductItem) => {
    discountTargetProduct.value = product;
    discountPercentage.value = product.days_remaining <= 2 ? 50 : 30;
    discountReason.value = `Manual clearance markdown due to ${product.days_remaining} day(s) shelf life remaining.`;
    isDiscountModalOpen.value = true;
};

const closeDiscountModal = () => {
    isDiscountModalOpen.value = false;
    discountTargetProduct.value = null;
    isSubmittingDiscount.value = false;
};

const calculatedDiscountPrice = computed(() => {
    if (!discountTargetProduct.value) return 0;
    const orig = discountTargetProduct.value.original_price;
    const discount = orig * (discountPercentage.value / 100);
    return Math.max(0, Math.round(orig - discount));
});

const submitDiscount = () => {
    if (!discountTargetProduct.value) return;
    isSubmittingDiscount.value = true;

    router.post(
        `/inventory/${discountTargetProduct.value.id}/discount`,
        {
            discount_percentage: discountPercentage.value,
            reason: discountReason.value,
        },
        {
            onSuccess: () => {
                closeDiscountModal();
            },
            onFinish: () => {
                isSubmittingDiscount.value = false;
            },
        },
    );
};

// Modal State: Reserve Donation
const isDonationModalOpen = ref(false);
const donationTargetProduct = ref<ProductItem | null>(null);
const selectedPartnerId = ref<number | ''>(props.donation_partners[0]?.id || '');
const donationReason = ref('');
const isSubmittingDonation = ref(false);

const openDonationModal = (product: ProductItem) => {
    donationTargetProduct.value = product;
    selectedPartnerId.value = props.donation_partners[0]?.id || '';
    donationReason.value = `Surplus food allocation for immediate community pantry pickup (${product.quantity} ${product.unit}).`;
    isDonationModalOpen.value = true;
};

const closeDonationModal = () => {
    isDonationModalOpen.value = false;
    donationTargetProduct.value = null;
    isSubmittingDonation.value = false;
};

const submitDonation = () => {
    if (!donationTargetProduct.value || !selectedPartnerId.value) return;
    isSubmittingDonation.value = true;

    router.post(
        `/inventory/${donationTargetProduct.value.id}/donate`,
        {
            partner_id: selectedPartnerId.value,
            reason: donationReason.value,
        },
        {
            onSuccess: () => {
                closeDonationModal();
            },
            onFinish: () => {
                isSubmittingDonation.value = false;
            },
        },
    );
};

// Modal State: Delete Confirmation
const isDeleteModalOpen = ref(false);
const deleteTargetProduct = ref<ProductItem | null>(null);
const isDeleting = ref(false);

const openDeleteModal = (product: ProductItem) => {
    deleteTargetProduct.value = product;
    isDeleteModalOpen.value = true;
};

const closeDeleteModal = () => {
    isDeleteModalOpen.value = false;
    deleteTargetProduct.value = null;
    isDeleting.value = false;
};

const confirmDelete = () => {
    if (!deleteTargetProduct.value) return;
    isDeleting.value = true;

    router.delete(`/inventory/${deleteTargetProduct.value.id}`, {
        onSuccess: () => {
            closeDeleteModal();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
};

// Helpers for Status & Urgency Badges
const getUrgencyBadgeClasses = (urgency: ProductItem['urgency'], days: number) => {
    if (urgency === 'expired' || days < 0) {
        return 'bg-red-500/10 text-red-500 border border-red-500/20';
    }
    if (urgency === 'critical' || days <= 2) {
        return 'bg-rose-500/15 text-rose-500 border border-rose-500/30 font-semibold';
    }
    if (urgency === 'warning' || (days >= 3 && days <= 5)) {
        return 'bg-amber-500/15 text-amber-500 border border-amber-500/30';
    }
    return 'bg-emerald-500/15 text-emerald-500 border border-emerald-500/30';
};

const getUrgencyLabel = (urgency: ProductItem['urgency'], days: number) => {
    if (urgency === 'expired' || days < 0) {
        return `Expired (${Math.abs(days)}d ago)`;
    }
    if (urgency === 'critical' || days <= 2) {
        return days === 0 ? 'Expires Today' : `${days}d left (Critical)`;
    }
    if (urgency === 'warning' || (days >= 3 && days <= 5)) {
        return `${days}d left (Warning)`;
    }
    return `${days}d left (Safe)`;
};

const getStatusBadgeClasses = (status: ProductItem['status']) => {
    switch (status) {
        case 'active':
            return 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
        case 'discounted':
            return 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
        case 'reserved_donation':
            return 'bg-purple-500/10 text-purple-400 border border-purple-500/20';
        case 'donated':
            return 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
        case 'disposed':
            return 'bg-neutral-500/10 text-neutral-400 border border-neutral-500/20 line-through';
        case 'sold_out':
            return 'bg-slate-500/10 text-slate-500 border border-slate-500/20';
        default:
            return 'bg-neutral-500/10 text-neutral-400';
    }
};

const formatStatusLabel = (status: ProductItem['status']) => {
    switch (status) {
        case 'active':
            return 'Active';
        case 'discounted':
            return 'Discounted';
        case 'reserved_donation':
            return 'Reserved Donation';
        case 'donated':
            return 'Donated';
        case 'disposed':
            return 'Disposed';
        case 'sold_out':
            return 'Sold Out';
        default:
            return status;
    }
};

const toggleProductBatches = (productId: number) => {
    expandedProductIds.value = expandedProductIds.value.includes(productId)
        ? expandedProductIds.value.filter((id) => id !== productId)
        : [...expandedProductIds.value, productId];
};

const isProductExpanded = (productId: number) => expandedProductIds.value.includes(productId);

const getBatchStatusLabel = (batch: ProductBatchItem) => {
    if (batch.status === 'donated') return 'Donated';
    if (batch.discount_approval?.status === 'pending') {
        return `Awaiting discount approval · ${batch.discount_approval.recommended_percentage}%`;
    }
    if (batch.discount_approval?.status === 'rejected') return 'Discount rejected · regular price';
    if (batch.status === 'discounted') {
        return `Discounted${batch.discount_approval?.approved_percentage ? ` · ${batch.discount_approval.approved_percentage}%` : ''}`;
    }
    if (batch.status === 'sold_out') return 'Sold out';
    if (batch.status === 'disposed') return 'Disposed';

    return 'Active stock';
};

const getBatchStatusClasses = (batch: ProductBatchItem) => {
    if (batch.status === 'donated') return 'border-violet-500/20 bg-violet-500/10 text-violet-600 dark:text-violet-300';
    if (batch.discount_approval?.status === 'pending') return 'border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-300';
    if (batch.status === 'discounted') return 'border-orange-500/20 bg-orange-500/10 text-orange-600 dark:text-orange-300';
    if (batch.status === 'sold_out' || batch.status === 'disposed') return 'border-neutral-500/20 bg-neutral-500/10 text-neutral-500';

    return 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300';
};

const formatReceivedAt = (date: string | null) => (date ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(date)) : 'Unknown date');
const formatCurrency = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
</script>

<template>
    <Head title="Food Inventory - EcoLoop Agent" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Toast Feedback Notification -->
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
                    class="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-sm text-emerald-400 shadow-sm"
                >
                    <CheckCircle2 class="size-5 shrink-0 text-emerald-400" />
                    <span class="flex-1 font-medium">{{ flashSuccess }}</span>
                </div>
                <div
                    v-else-if="flashError"
                    class="flex items-center gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 p-3.5 text-sm text-rose-400 shadow-sm"
                >
                    <AlertTriangle class="size-5 shrink-0 text-rose-400" />
                    <span class="flex-1 font-medium">{{ flashError }}</span>
                </div>
            </transition>

            <!-- Header Section -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">Food Inventory Management</h1>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Track stock levels, real-time expiration urgency, and execute manual markdown or donation overrides.
                    </p>
                </div>
                <div class="flex items-center gap-2.5">
                    <Link
                        href="/inventory/create"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 active:bg-emerald-700"
                    >
                        <Plus class="size-4" />
                        <span>Add Product</span>
                    </Link>
                </div>
            </div>

            <section class="flex flex-col gap-4 rounded-xl border border-sky-500/20 bg-sky-500/5 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Supermarket Central Sync</h2>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="auto_sync.auto_sync_enabled ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-neutral-500/10 text-neutral-500'"
                        >
                            {{ auto_sync.auto_sync_enabled ? 'Auto Sync On' : 'Paused' }}
                        </span>
                        <span class="rounded-full bg-sky-500/10 px-2.5 py-1 text-xs font-medium text-sky-700 dark:text-sky-300">DEMO DATA</span>
                    </div>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        Auto Sync simulates central product receipts and POS sales every 5 minutes. Sync Now updates demo data immediately and donates 3 products expiring within 2 days. Manual product entry remains available.
                    </p>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        Last sync: {{ auto_sync.last_synced_at ? new Date(auto_sync.last_synced_at).toLocaleString() : 'Not synced yet' }}
                        <template v-if="auto_sync.last_sync_summary">
                            · {{ auto_sync.last_sync_summary.new_products }} new products
                            · {{ auto_sync.last_sync_summary.received_units }} units received
                            · {{ auto_sync.last_sync_summary.sold_units }} units sold
                            <template v-if="auto_sync.last_sync_summary.demo_donated_products !== undefined">
                                · {{ auto_sync.last_sync_summary.demo_donated_products }} urgent products donated
                                ({{ auto_sync.last_sync_summary.demo_donated_units }} units) across
                                {{ auto_sync.last_sync_summary.demo_partner_count ?? 0 }} partners
                            </template>
                        </template>
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3.5 py-2 text-sm font-semibold text-neutral-700 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        :disabled="isTogglingAutoSync"
                        @click="toggleAutoSync"
                    >
                        {{ auto_sync.auto_sync_enabled ? 'Pause Auto Sync' : 'Enable Auto Sync' }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="isSyncing"
                        @click="runAutoSyncNow"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': isSyncing }" />
                        {{ isSyncing ? 'Syncing…' : 'Sync Now' }}
                    </button>
                </div>
            </section>

            <!-- Metric Summary Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                <div class="rounded-xl border border-neutral-200/80 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900/60">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Total Catalog</span>
                        <Package class="size-4 text-neutral-400" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">
                        {{ stats.total_items }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-400">Total tracked SKUs</p>
                </div>

                <div class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-rose-500">Critical (≤ 2 Days)</span>
                        <ShieldAlert class="size-4 text-rose-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-rose-500">
                        {{ stats.critical_count }}
                    </p>
                    <p class="mt-0.5 text-xs text-rose-400/80">Requires immediate action</p>
                </div>

                <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-amber-500">Warning (3-5 Days)</span>
                        <Clock class="size-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-500">
                        {{ stats.warning_count }}
                    </p>
                    <p class="mt-0.5 text-xs text-amber-400/80">Discount clearance range</p>
                </div>

                <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">Discounted</span>
                        <Tag class="size-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.discounted_count }}
                    </p>
                    <p class="mt-0.5 text-xs text-amber-400/80">Promotions running</p>
                </div>

                <div class="col-span-2 rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 shadow-sm sm:col-span-1">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">Reserved Donation</span>
                        <Handshake class="size-4 text-purple-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-purple-600 dark:text-purple-400">
                        {{ stats.reserved_donation_count }}
                    </p>
                    <p class="mt-0.5 text-xs text-purple-400/80">Awaiting pickup</p>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <div class="rounded-xl border border-neutral-200/80 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900/60">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <!-- Search Input -->
                    <div class="relative lg:col-span-2">
                        <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by SKU, product name, or description..."
                            class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 py-2 pl-9 pr-8 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            @input="handleSearchInput"
                        />
                        <button
                            v-if="search"
                            type="button"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                            @click="
                                search = '';
                                applyFilters();
                            "
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <!-- Category Filter -->
                    <div>
                        <select
                            v-model="selectedCategory"
                            class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3 py-2 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            @change="applyFilters"
                        >
                            <option value="">All Categories</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                {{ cat.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Urgency Filter -->
                    <div>
                        <select
                            v-model="selectedUrgency"
                            class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3 py-2 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            @change="applyFilters"
                        >
                            <option value="">All Urgency Levels</option>
                            <option value="critical">🔴 Critical (≤ 2 Days)</option>
                            <option value="warning">🟡 Warning (3 - 5 Days)</option>
                            <option value="safe">🟢 Safe (&gt; 5 Days)</option>
                            <option value="expired">⚫ Expired</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="flex items-center gap-2">
                        <select
                            v-model="selectedStatus"
                            class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3 py-2 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            @change="applyFilters"
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="discounted">Discounted</option>
                            <option value="reserved_donation">Reserved Donation</option>
                            <option value="donated">Donated</option>
                            <option value="sold_out">Sold Out</option>
                            <option value="disposed">Disposed</option>
                        </select>

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            title="Reset all filters"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="clearFilters"
                        >
                            <RefreshCw class="size-3.5" />
                            <span>Reset</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Products Data Table -->
            <div class="overflow-hidden rounded-xl border border-neutral-200/80 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900/60">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b border-neutral-200 bg-neutral-50/75 text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/50 dark:text-neutral-400"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3.5 sm:px-6">SKU & Item</th>
                                <th scope="col" class="px-4 py-3.5">Category</th>
                                <th scope="col" class="px-4 py-3.5">Stock</th>
                                <th scope="col" class="px-4 py-3.5">Price</th>
                                <th scope="col" class="px-4 py-3.5">Shelf Life & Urgency</th>
                                <th scope="col" class="px-4 py-3.5">Status</th>
                                <th scope="col" class="px-4 py-3.5 text-right sm:pr-6">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200/80 dark:divide-neutral-800/80">
                            <tr
                                v-for="product in products.data"
                                :key="product.id"
                                class="transition-colors hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40"
                            >
                                <!-- SKU & Item Name -->
                                <td class="px-4 py-4 sm:px-6">
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                            >
                                                {{ product.sku }}
                                            </span>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 text-left font-semibold text-neutral-900 hover:text-emerald-600 dark:text-neutral-100 dark:hover:text-emerald-400"
                                                :aria-expanded="isProductExpanded(product.id)"
                                                :aria-label="`${isProductExpanded(product.id) ? 'Hide' : 'Show'} stock batches for ${product.name}`"
                                                @click="toggleProductBatches(product.id)"
                                            >
                                                <ChevronDown v-if="isProductExpanded(product.id)" class="size-4 shrink-0" />
                                                <ChevronRight v-else class="size-4 shrink-0" />
                                                {{ product.name }}
                                            </button>
                                        </div>
                                        <p
                                            v-if="product.description"
                                            class="mt-1 line-clamp-1 max-w-xs text-xs text-neutral-500 dark:text-neutral-400"
                                        >
                                            {{ product.description }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-neutral-400">
                                            {{ product.source_system === 'supermarket_central' ? 'Central DB · ' + product.external_id : 'Manual entry' }}
                                        </p>
                                    </div>
                                </td>

                                <!-- Category -->
                                <td class="whitespace-nowrap px-4 py-4 text-xs font-medium text-neutral-600 dark:text-neutral-300">
                                    <span class="inline-flex items-center rounded-md bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">
                                        {{ product.category?.name ?? 'General' }}
                                    </span>
                                </td>

                                <!-- Stock / Quantity -->
                                <td class="whitespace-nowrap px-4 py-4">
                                    <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ product.quantity }}</span>
                                    <span class="ml-1 text-xs text-neutral-500 dark:text-neutral-400">{{ product.unit }}</span>
                                    <p class="mt-1 text-[11px] text-neutral-500 dark:text-neutral-400">
                                        {{ product.received_quantity }} {{ product.unit }} received · {{ product.donated_quantity }} {{ product.unit }} donated
                                    </p>
                                </td>

                                <!-- Price (with discount tag if active) -->
                                <td class="whitespace-nowrap px-4 py-4">
                                    <div
                                        v-if="product.discount_price !== null && product.discount_price < product.original_price"
                                        class="flex flex-col"
                                    >
                                        <span class="font-bold text-amber-600 dark:text-amber-400">
                                            {{ product.discount_price_formatted }}
                                        </span>
                                        <span class="text-xs text-neutral-400 line-through">
                                            {{ product.original_price_formatted }}
                                        </span>
                                    </div>
                                    <div v-else>
                                        <span class="font-medium text-neutral-900 dark:text-neutral-100">
                                            {{ product.original_price_formatted }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Shelf Life & Urgency Badges -->
                                <td class="whitespace-nowrap px-4 py-4">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-xs text-neutral-500 dark:text-neutral-400">
                                            Exp: {{ product.expiration_date_formatted }}
                                        </span>
                                        <div class="inline-flex">
                                            <span
                                                :class="[
                                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs',
                                                    getUrgencyBadgeClasses(product.urgency, product.days_remaining),
                                                ]"
                                            >
                                                <span v-if="product.urgency === 'critical'" class="relative flex size-2">
                                                    <span
                                                        class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75"
                                                    ></span>
                                                    <span class="relative inline-flex size-2 rounded-full bg-rose-500"></span>
                                                </span>
                                                <span v-else-if="product.urgency === 'warning'" class="size-2 rounded-full bg-amber-500"></span>
                                                <span v-else-if="product.urgency === 'safe'" class="size-2 rounded-full bg-emerald-500"></span>
                                                <span>{{ getUrgencyLabel(product.urgency, product.days_remaining) }}</span>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td class="whitespace-nowrap px-4 py-4">
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                            getStatusBadgeClasses(product.status),
                                        ]"
                                    >
                                        {{ formatStatusLabel(product.status) }}
                                    </span>
                                </td>

                                <!-- Row Action Buttons -->
                                <td class="whitespace-nowrap px-4 py-4 text-right sm:pr-6">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Apply Discount Modal Trigger -->
                                        <button
                                            type="button"
                                            title="Apply Markdown Discount"
                                            :disabled="product.status === 'donated' || product.status === 'disposed' || product.status === 'sold_out'"
                                            class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-xs font-semibold text-amber-500 transition hover:bg-amber-500/20 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="openDiscountModal(product)"
                                        >
                                            <Tag class="size-3.5" />
                                            <span class="hidden xl:inline">Discount</span>
                                        </button>

                                        <!-- Reserve Donation Modal Trigger -->
                                        <button
                                            type="button"
                                            title="Reserve for Donation"
                                            :disabled="
                                                product.status === 'donated' ||
                                                product.status === 'disposed' ||
                                                product.status === 'sold_out' ||
                                                product.status === 'reserved_donation'
                                            "
                                            class="inline-flex items-center gap-1 rounded-md border border-purple-500/30 bg-purple-500/10 px-2 py-1 text-xs font-semibold text-purple-400 transition hover:bg-purple-500/20 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="openDonationModal(product)"
                                        >
                                            <Handshake class="size-3.5" />
                                            <span class="hidden xl:inline">Donate</span>
                                        </button>

                                        <!-- Edit Item -->
                                        <Link
                                            :href="`/inventory/${product.id}/edit`"
                                            title="Edit Item"
                                            class="inline-flex items-center rounded-md border border-neutral-200 bg-white p-1 text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:hover:text-white"
                                        >
                                            <Edit3 class="size-3.5" />
                                        </Link>

                                        <!-- Delete Item -->
                                        <button
                                            type="button"
                                            title="Delete Item"
                                            class="inline-flex items-center rounded-md border border-neutral-200 bg-white p-1 text-neutral-400 transition hover:border-red-500/30 hover:bg-red-500/10 hover:text-red-500 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:bg-red-500/20"
                                            @click="openDeleteModal(product)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="isProductExpanded(product.id)" :key="`batches-${product.id}`" class="bg-neutral-50/70 dark:bg-neutral-800/30">
                                <td colspan="7" class="px-4 py-4 sm:px-6">
                                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Stock batches · {{ product.name }}</h3>
                                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                {{ product.batches.length }} receipts · {{ product.received_quantity }} {{ product.unit }} received ·
                                                {{ product.quantity }} {{ product.unit }} currently available ·
                                                {{ product.donated_quantity }} {{ product.unit }} donated · {{ product.sold_quantity }} {{ product.unit }} sold
                                            </p>
                                        </div>
                                        <Link
                                            :href="`/inventory/${product.id}/edit`"
                                            class="text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                                        >
                                            Edit product
                                        </Link>
                                    </div>
                                    <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                                        <table class="w-full min-w-[760px] text-left text-xs">
                                            <thead class="bg-white text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                                                <tr>
                                                    <th class="px-3 py-2.5">Batch / received</th>
                                                    <th class="px-3 py-2.5">Received</th>
                                                    <th class="px-3 py-2.5">Available</th>
                                                    <th class="px-3 py-2.5">Donated / sold</th>
                                                    <th class="px-3 py-2.5">Expires</th>
                                                    <th class="px-3 py-2.5">Batch status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                                <tr v-for="batch in product.batches" :key="batch.id" class="bg-white dark:bg-neutral-900">
                                                    <td class="px-3 py-3">
                                                        <p class="font-mono font-medium text-neutral-800 dark:text-neutral-200">{{ batch.batch_code }}</p>
                                                        <p class="mt-1 text-neutral-500 dark:text-neutral-400">
                                                            Received {{ formatReceivedAt(batch.received_at) }} · {{ batch.source.replaceAll('_', ' ') }}
                                                        </p>
                                                    </td>
                                                    <td class="px-3 py-3 font-medium text-neutral-800 dark:text-neutral-200">
                                                        {{ batch.quantity_received }} {{ product.unit }}
                                                    </td>
                                                    <td class="px-3 py-3 font-medium text-neutral-800 dark:text-neutral-200">
                                                        {{ batch.quantity_available }} {{ product.unit }}
                                                    </td>
                                                    <td class="px-3 py-3 text-neutral-600 dark:text-neutral-300">
                                                        {{ batch.quantity_donated }} / {{ batch.quantity_sold }} {{ product.unit }}
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <p class="font-medium text-neutral-800 dark:text-neutral-200">{{ batch.expiration_date }}</p>
                                                        <p :class="batch.days_remaining <= 2 ? 'mt-1 font-semibold text-rose-600' : 'mt-1 text-neutral-500 dark:text-neutral-400'">
                                                            {{ batch.days_remaining < 0 ? `Expired ${Math.abs(batch.days_remaining)}d ago` : `${batch.days_remaining}d remaining` }}
                                                        </p>
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <span class="inline-flex rounded-full border px-2.5 py-1 font-medium" :class="getBatchStatusClasses(batch)">
                                                            {{ getBatchStatusLabel(batch) }}
                                                        </span>
                                                        <p v-if="batch.discount_price !== null" class="mt-1 text-neutral-500 dark:text-neutral-400">
                                                            {{ formatCurrency(batch.discount_price) }} per unit
                                                        </p>
                                                    </td>
                                                </tr>
                                                <tr v-if="product.batches.length === 0">
                                                    <td colspan="6" class="px-3 py-6 text-center text-neutral-500">No receipt batches recorded for this product.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="mt-2 text-[11px] text-neutral-500 dark:text-neutral-400">
                                        Stock with 0–2 days remaining is donated automatically; batches with 3–7 days remaining may appear here as awaiting discount approval.
                                    </p>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="products.data.length === 0">
                                <td colspan="7" class="py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="rounded-full bg-neutral-100 p-3 text-neutral-400 dark:bg-neutral-800">
                                            <Package class="size-8" />
                                        </div>
                                        <h3 class="mt-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">No food products found</h3>
                                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                            {{
                                                hasActiveFilters
                                                    ? 'Try adjusting your search query or urgency filters.'
                                                    : 'Get started by adding your first inventory item.'
                                            }}
                                        </p>
                                        <div class="mt-4 flex gap-2">
                                            <button
                                                v-if="hasActiveFilters"
                                                type="button"
                                                class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                @click="clearFilters"
                                            >
                                                Clear filters
                                            </button>
                                            <Link
                                                href="/inventory/create"
                                                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500"
                                            >
                                                Add Product
                                            </Link>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div
                    v-if="products.total > 0"
                    class="flex flex-col items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800 sm:flex-row sm:px-6"
                >
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                        Showing
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ products.from ?? 0 }}</span>
                        to
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ products.to ?? 0 }}</span>
                        of
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ products.total }}</span>
                        items
                    </div>

                    <div class="flex items-center gap-1">
                        <template v-for="(link, idx) in products.links" :key="idx">
                            <span v-if="!link.url" class="rounded-md px-2.5 py-1 text-xs text-neutral-400" v-html="link.label" />
                            <Link
                                v-else
                                :href="link.url"
                                preserve-scroll
                                preserve-state
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition',
                                    link.active
                                        ? 'bg-emerald-600 text-white shadow-sm'
                                        : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                ]"
                            >
                                <span v-html="link.label" />
                            </Link>
                        </template>
                    </div>
                </div>
            </div>

            <!-- MODAL: Apply Discount Override -->
            <div
                v-if="isDiscountModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/60 p-4 backdrop-blur-sm"
                @click.self="closeDiscountModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div class="flex items-center justify-between border-b border-neutral-200 pb-4 dark:border-neutral-800">
                        <div class="flex items-center gap-2 text-amber-500">
                            <Tag class="size-5" />
                            <h3 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Apply Clearance Markdown</h3>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="closeDiscountModal"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <div v-if="discountTargetProduct" class="mt-4 space-y-4">
                        <!-- Product Preview Card -->
                        <div class="rounded-lg border border-neutral-200/80 bg-neutral-50 p-3 dark:border-neutral-800 dark:bg-neutral-800/50">
                            <p class="font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ discountTargetProduct.name }}
                            </p>
                            <div class="mt-1 flex items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
                                <span>SKU: {{ discountTargetProduct.sku }}</span>
                                <span>•</span>
                                <span>Current: {{ discountTargetProduct.original_price_formatted }}</span>
                            </div>
                        </div>

                        <!-- Discount Percentage Picker -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold uppercase text-neutral-600 dark:text-neutral-300"> Discount Percentage </label>
                                <span class="font-mono text-base font-bold text-amber-500"> {{ discountPercentage }}% </span>
                            </div>
                            <input
                                v-model.number="discountPercentage"
                                type="range"
                                min="5"
                                max="95"
                                step="5"
                                class="mt-2 h-2 w-full cursor-pointer appearance-none rounded-lg bg-neutral-200 accent-amber-500 dark:bg-neutral-700"
                            />

                            <!-- Quick Preset Chips -->
                            <div class="mt-2.5 flex items-center gap-2">
                                <button
                                    v-for="preset in [20, 30, 40, 50, 75]"
                                    :key="preset"
                                    type="button"
                                    :class="[
                                        'rounded-md px-2.5 py-1 text-xs font-medium transition',
                                        discountPercentage === preset
                                            ? 'bg-amber-500 text-white shadow-sm'
                                            : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700',
                                    ]"
                                    @click="discountPercentage = preset"
                                >
                                    {{ preset }}%
                                </button>
                            </div>
                        </div>

                        <!-- Live Price Preview -->
                        <div class="flex items-center justify-between rounded-lg border border-amber-500/30 bg-amber-500/10 p-3.5">
                            <div>
                                <p class="text-xs font-medium text-amber-500">New Clearance Price</p>
                                <p class="mt-0.5 text-lg font-bold text-amber-500">IDR {{ calculatedDiscountPrice.toLocaleString('id-ID') }}</p>
                            </div>
                            <span class="rounded-full bg-amber-500/20 px-2.5 py-1 text-xs font-semibold text-amber-500">
                                Save {{ discountPercentage }}%
                            </span>
                        </div>

                        <!-- Rationale Field -->
                        <div>
                            <label class="block text-xs font-semibold uppercase text-neutral-600 dark:text-neutral-300">
                                Markdown Justification
                            </label>
                            <textarea
                                v-model="discountReason"
                                rows="2"
                                placeholder="Why is this markdown applied..."
                                class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 p-2.5 text-xs text-neutral-900 focus:border-amber-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100"
                            ></textarea>
                        </div>

                        <!-- Modal Actions -->
                        <div class="flex items-center justify-end gap-2.5 pt-2">
                            <button
                                type="button"
                                class="rounded-lg px-4 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="closeDiscountModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                :disabled="isSubmittingDiscount"
                                class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-amber-600 disabled:opacity-50"
                                @click="submitDiscount"
                            >
                                <span v-if="isSubmittingDiscount">Applying...</span>
                                <span v-else>Confirm Markdown</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL: Reserve Donation Override -->
            <div
                v-if="isDonationModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/60 p-4 backdrop-blur-sm"
                @click.self="closeDonationModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div class="flex items-center justify-between border-b border-neutral-200 pb-4 dark:border-neutral-800">
                        <div class="flex items-center gap-2 text-purple-500">
                            <Handshake class="size-5" />
                            <h3 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Reserve for Charity Donation</h3>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="closeDonationModal"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <div v-if="donationTargetProduct" class="mt-4 space-y-4">
                        <!-- Product Preview Card -->
                        <div class="rounded-lg border border-neutral-200/80 bg-neutral-50 p-3 dark:border-neutral-800 dark:bg-neutral-800/50">
                            <p class="font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ donationTargetProduct.name }}
                            </p>
                            <div class="mt-1 flex items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
                                <span>SKU: {{ donationTargetProduct.sku }}</span>
                                <span>•</span>
                                <span>Stock: {{ donationTargetProduct.quantity }} {{ donationTargetProduct.unit }}</span>
                            </div>
                        </div>

                        <!-- Select Donation Partner -->
                        <div>
                            <label class="block text-xs font-semibold uppercase text-neutral-600 dark:text-neutral-300">
                                Select Registered Partner
                            </label>
                            <select
                                v-model="selectedPartnerId"
                                class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3 py-2 text-xs text-neutral-900 focus:border-purple-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100"
                            >
                                <option v-for="partner in donation_partners" :key="partner.id" :value="partner.id">
                                    {{ partner.name }} (Contact: {{ partner.contact_person }})
                                </option>
                            </select>
                        </div>

                        <!-- Rationale Field -->
                        <div>
                            <label class="block text-xs font-semibold uppercase text-neutral-600 dark:text-neutral-300">
                                Donation Purpose & Instructions
                            </label>
                            <textarea
                                v-model="donationReason"
                                rows="2"
                                placeholder="E.g., Allocated for emergency community meals..."
                                class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 p-2.5 text-xs text-neutral-900 focus:border-purple-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-purple-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100"
                            ></textarea>
                        </div>

                        <!-- Modal Actions -->
                        <div class="flex items-center justify-end gap-2.5 pt-2">
                            <button
                                type="button"
                                class="rounded-lg px-4 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="closeDonationModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                :disabled="isSubmittingDonation || !selectedPartnerId"
                                class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-purple-700 disabled:opacity-50"
                                @click="submitDonation"
                            >
                                <span v-if="isSubmittingDonation">Reserving...</span>
                                <span v-else>Confirm Allocation</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL: Delete Confirmation -->
            <div
                v-if="isDeleteModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/60 p-4 backdrop-blur-sm"
                @click.self="closeDeleteModal"
            >
                <div
                    class="w-full max-w-sm rounded-2xl border border-neutral-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div class="flex items-center gap-3 text-red-500">
                        <AlertTriangle class="size-6 shrink-0" />
                        <h3 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Delete Product?</h3>
                    </div>

                    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                        Are you sure you want to permanently delete
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100"> {{ deleteTargetProduct?.name }} </span>? This will remove
                        its stock tracking and related logs.
                    </p>

                    <div class="mt-6 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-3.5 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="closeDeleteModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="isDeleting"
                            class="rounded-lg bg-red-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                            @click="confirmDelete"
                        >
                            <span v-if="isDeleting">Deleting...</span>
                            <span v-else>Delete Permanently</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
