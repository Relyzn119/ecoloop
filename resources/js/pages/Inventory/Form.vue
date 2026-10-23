<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertCircle, ArrowLeft, Calendar, Save, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface CategoryItem {
    id: number;
    name: string;
    slug?: string;
}

interface ProductFormData {
    id?: number;
    category_id: number | '';
    sku: string;
    name: string;
    description: string | null;
    quantity: number;
    unit: string;
    original_price: number | '';
    discount_price: number | '' | null;
    expiration_date: string;
    batch_count?: number;
    stock_locked?: boolean;
    status: 'active' | 'discounted' | 'reserved_donation' | 'donated' | 'disposed';
}

const props = defineProps<{
    product?: ProductFormData | null;
    categories: CategoryItem[];
}>();

const isEditing = computed(() => Boolean(props.product?.id));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Inventory', href: '/inventory' },
    { title: isEditing.value ? 'Edit Item' : 'New Product', href: isEditing.value ? `/inventory/${props.product?.id}/edit` : '/inventory/create' },
];

const generateRandomSku = () => {
    const prefix = 'ECO';
    const rand = Math.floor(1000 + Math.random() * 9000);
    return `${prefix}-${rand}`;
};

const getFutureDate = (days: number): string => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
};

const form = useForm({
    category_id: props.product?.category_id ?? (props.categories[0]?.id || ''),
    sku: props.product?.sku ?? generateRandomSku(),
    name: props.product?.name ?? '',
    description: props.product?.description ?? '',
    quantity: props.product?.quantity ?? 10,
    unit: props.product?.unit ?? 'kg',
    original_price: props.product?.original_price ?? '',
    discount_price: props.product?.discount_price ?? null,
    expiration_date: props.product?.expiration_date ?? getFutureDate(3),
    status: props.product?.status ?? 'active',
    additionalBatches: [] as Array<{ quantity: number; expiration_date: string }>,
});

// Quick Expiration Date Presets
const setExpirationPreset = (days: number) => {
    form.expiration_date = getFutureDate(days);
};

// Client-side validation errors
const clientErrors = ref<Record<string, string>>({});

const validateClientSide = (): boolean => {
    clientErrors.value = {};

    if (!form.sku || !form.sku.trim()) {
        clientErrors.value.sku = 'SKU is required.';
    }
    if (!form.name || !form.name.trim()) {
        clientErrors.value.name = 'Product name is required.';
    }
    if (!form.category_id) {
        clientErrors.value.category_id = 'Please select a category.';
    }
    if (form.quantity === undefined || form.quantity === null || form.quantity < 0) {
        clientErrors.value.quantity = 'Quantity must be 0 or greater.';
    }
    if (!form.unit || !form.unit.trim()) {
        clientErrors.value.unit = 'Unit is required.';
    }
    if (form.original_price === '' || Number(form.original_price) < 0) {
        clientErrors.value.original_price = 'Original price must be 0 or greater.';
    }
    if (form.discount_price !== null && form.discount_price !== '' && Number(form.discount_price) > Number(form.original_price)) {
        clientErrors.value.discount_price = 'Discount price cannot exceed original price.';
    }
    if (!form.expiration_date) {
        clientErrors.value.expiration_date = 'Expiration date is required.';
    }
    form.additionalBatches.forEach((batch, index) => {
        if (batch.quantity < 0) {
            clientErrors.value[`batch_${index}_quantity`] = 'Quantity must be 0 or greater.';
        }
        if (!batch.expiration_date) {
            clientErrors.value[`batch_${index}_expiration_date`] = 'Expiration date is required.';
        }
    });

    return Object.keys(clientErrors.value).length === 0;
};

const submitForm = () => {
    if (!validateClientSide()) {
        return;
    }

    if (isEditing.value && props.product?.id) {
        form.put(`/inventory/${props.product.id}`, {
            preserveScroll: true,
        });
    } else {
        form.transform((data) => ({
            ...data,
            batches: [
                { quantity: data.quantity, expiration_date: data.expiration_date },
                ...data.additionalBatches,
            ],
        })).post('/inventory', {
            preserveScroll: true,
        });
    }
};

const commonUnits = ['kg', 'pcs', 'liters', 'packs', 'boxes', 'bunches'];
</script>

<template>
    <Head :title="(isEditing ? 'Edit Product' : 'Add Product') + ' - EcoLoop Agent'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header Section -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <Link
                            href="/inventory"
                            class="inline-flex size-8 items-center justify-center rounded-lg border border-neutral-200 bg-white text-neutral-600 shadow-sm transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        >
                            <ArrowLeft class="size-4" />
                        </Link>
                        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">
                            {{ isEditing ? 'Edit Inventory Product' : 'Add New Inventory Product' }}
                        </h1>
                    </div>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {{
                            isEditing
                                ? 'Update stock levels, pricing, or expiration dates for this product.'
                                : 'Register a new food surplus item to enable shelf-life urgency tracking.'
                        }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        href="/inventory"
                        class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 shadow-sm transition hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        Cancel
                    </Link>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 disabled:opacity-50"
                        @click="submitForm"
                    >
                        <Save class="size-4" />
                        <span>{{ form.processing ? 'Saving...' : isEditing ? 'Update Product' : 'Save Product' }}</span>
                    </button>
                </div>
            </div>

            <!-- Form Card -->
            <form
                class="rounded-xl border border-neutral-200/80 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900/60"
                @submit.prevent="submitForm"
            >
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Basic Information Section -->
                    <div class="col-span-1 md:col-span-2">
                        <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">Product Details</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">General classification and stock identity information.</p>
                    </div>

                    <!-- SKU -->
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                                SKU / Item Code <span class="text-red-500">*</span>
                            </label>
                            <button
                                v-if="!isEditing"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:text-emerald-500 dark:text-emerald-400"
                                @click="form.sku = generateRandomSku()"
                            >
                                <Sparkles class="size-3" />
                                <span>Generate SKU</span>
                            </button>
                        </div>
                        <input
                            v-model="form.sku"
                            type="text"
                            placeholder="e.g. ECO-5421"
                            class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2.5 font-mono text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            :class="{ 'border-red-500': form.errors.sku || clientErrors.sku }"
                        />
                        <p v-if="form.errors.sku || clientErrors.sku" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.sku || clientErrors.sku }}</span>
                        </p>
                    </div>

                    <!-- Product Name -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Product Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Organic Whole Wheat Bread"
                            class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            :class="{ 'border-red-500': form.errors.name || clientErrors.name }"
                        />
                        <p v-if="form.errors.name || clientErrors.name" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.name || clientErrors.name }}</span>
                        </p>
                    </div>

                    <!-- Category -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Category <span class="text-red-500">*</span>
                        </label>
                        <select
                            v-model="form.category_id"
                            class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            :class="{ 'border-red-500': form.errors.category_id || clientErrors.category_id }"
                        >
                            <option value="" disabled>Select Category</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                {{ cat.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.category_id || clientErrors.category_id" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.category_id || clientErrors.category_id }}</span>
                        </p>
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Operational Status <span class="text-red-500">*</span>
                        </label>
                        <select
                            v-model="form.status"
                            :disabled="isEditing && props.product?.stock_locked"
                            class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                        >
                            <option value="active">Active (Full Price)</option>
                            <option value="discounted">Discounted (Promotional Clearance)</option>
                            <option value="reserved_donation">Reserved for Donation</option>
                            <option value="donated">Donated</option>
                            <option value="disposed">Disposed / Composted</option>
                        </select>
                        <p v-if="form.errors.status" class="mt-1 text-xs text-red-500">
                            {{ form.errors.status }}
                        </p>
                        <p v-if="isEditing && props.product?.stock_locked" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            Status and remaining stock are managed by the product batches.
                        </p>
                    </div>

                    <!-- Description -->
                    <div class="col-span-1 md:col-span-2">
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Description / Batch Notes (Optional)
                        </label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            placeholder="Add details about packaging condition, storage temperatures, or batch supplier..."
                            class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                        ></textarea>
                    </div>

                    <!-- Inventory & Pricing Section -->
                    <div class="col-span-1 border-t border-neutral-200 pt-4 dark:border-neutral-800 md:col-span-2">
                        <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">Stock, Expiration & Pricing</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                            Specify stock units and shelf-life timeline to compute AI markdown eligibility.
                        </p>
                    </div>

                    <!-- Quantity -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Quantity In Stock <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model.number="form.quantity"
                            type="number"
                            min="0"
                            :disabled="isEditing && props.product?.stock_locked"
                            placeholder="e.g. 20"
                            class="mt-1.5 w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                            :class="{ 'border-red-500': form.errors.quantity || clientErrors.quantity }"
                        />
                        <p v-if="form.errors.quantity || clientErrors.quantity" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.quantity || clientErrors.quantity }}</span>
                        </p>
                    </div>

                    <!-- Unit -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Unit of Measure <span class="text-red-500">*</span>
                        </label>
                        <div class="mt-1.5 flex gap-2">
                            <input
                                v-model="form.unit"
                                type="text"
                                placeholder="kg, pcs, liters..."
                                class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 px-3.5 py-2.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                                :class="{ 'border-red-500': form.errors.unit || clientErrors.unit }"
                            />
                        </div>
                        <!-- Common Units Chips -->
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <button
                                v-for="u in commonUnits"
                                :key="u"
                                type="button"
                                class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                @click="form.unit = u"
                            >
                                {{ u }}
                            </button>
                        </div>
                        <p v-if="form.errors.unit || clientErrors.unit" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.unit || clientErrors.unit }}</span>
                        </p>
                    </div>

                    <!-- Expiration Date -->
                    <div class="col-span-1 md:col-span-2">
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Expiration Date <span class="text-red-500">*</span>
                        </label>
                        <div class="mt-1.5 flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div class="relative flex-1">
                                <Calendar class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400" />
                                <input
                                    v-model="form.expiration_date"
                                    type="date"
                                    :disabled="isEditing && props.product?.stock_locked"
                                    class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 py-2.5 pl-10 pr-3.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                                    :class="{ 'border-red-500': form.errors.expiration_date || clientErrors.expiration_date }"
                                />
                            </div>

                            <!-- Quick Preset Date Buttons -->
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-neutral-400">Presets:</span>
                                <button
                                    type="button"
                                    class="rounded-md border border-neutral-200 bg-neutral-50 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                    @click="setExpirationPreset(2)"
                                >
                                    +2 Days (Critical)
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-neutral-200 bg-neutral-50 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                    @click="setExpirationPreset(4)"
                                >
                                    +4 Days (Warning)
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-neutral-200 bg-neutral-50 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                    @click="setExpirationPreset(10)"
                                >
                                    +10 Days (Safe)
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="form.errors.expiration_date || clientErrors.expiration_date"
                            class="mt-1 flex items-center gap-1 text-xs text-red-500"
                        >
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.expiration_date || clientErrors.expiration_date }}</span>
                        </p>
                    </div>

                    <div v-if="!isEditing" class="col-span-1 rounded-lg border border-dashed border-neutral-300 p-4 dark:border-neutral-700 md:col-span-2">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Additional supermarket batches</h3>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Use one row per receiving lot when the same SKU has different expiration dates.</p>
                            </div>
                            <button
                                type="button"
                                class="rounded-md border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950"
                                @click="form.additionalBatches.push({ quantity: 1, expiration_date: getFutureDate(5) })"
                            >
                                Add batch
                            </button>
                        </div>
                        <div v-for="(batch, index) in form.additionalBatches" :key="index" class="mt-3 grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
                            <label class="text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                Batch quantity
                                <input v-model.number="batch.quantity" type="number" min="0" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100" />
                                <span v-if="clientErrors[`batch_${index}_quantity`]" class="mt-1 block text-red-500">{{ clientErrors[`batch_${index}_quantity`] }}</span>
                            </label>
                            <label class="text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                Expiration date
                                <input v-model="batch.expiration_date" type="date" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100" />
                                <span v-if="clientErrors[`batch_${index}_expiration_date`]" class="mt-1 block text-red-500">{{ clientErrors[`batch_${index}_expiration_date`] }}</span>
                            </label>
                            <button type="button" class="rounded-md px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 dark:hover:bg-red-950" @click="form.additionalBatches.splice(index, 1)">Remove</button>
                        </div>
                    </div>

                    <!-- Original Price -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Original Retail Price (IDR) <span class="text-red-500">*</span>
                        </label>
                        <div class="relative mt-1.5">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-neutral-400"> IDR </span>
                            <input
                                v-model.number="form.original_price"
                                type="number"
                                min="0"
                                step="100"
                                placeholder="e.g. 25000"
                                class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 py-2.5 pl-12 pr-3.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                                :class="{ 'border-red-500': form.errors.original_price || clientErrors.original_price }"
                            />
                        </div>
                        <p v-if="form.errors.original_price || clientErrors.original_price" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.original_price || clientErrors.original_price }}</span>
                        </p>
                    </div>

                    <!-- Discount Price (Optional) -->
                    <div>
                        <label class="block text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-300">
                            Discount / Clearance Price (Optional)
                        </label>
                        <div class="relative mt-1.5">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-neutral-400"> IDR </span>
                            <input
                                v-model.number="form.discount_price"
                                type="number"
                                min="0"
                                step="100"
                                placeholder="Leave blank if full price"
                                class="w-full rounded-lg border border-neutral-200 bg-neutral-50/50 py-2.5 pl-12 pr-3.5 text-sm text-neutral-900 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-100 dark:focus:border-emerald-500 dark:focus:bg-neutral-800"
                                :class="{ 'border-red-500': form.errors.discount_price || clientErrors.discount_price }"
                            />
                        </div>
                        <p v-if="form.errors.discount_price || clientErrors.discount_price" class="mt-1 flex items-center gap-1 text-xs text-red-500">
                            <AlertCircle class="size-3.5" />
                            <span>{{ form.errors.discount_price || clientErrors.discount_price }}</span>
                        </p>
                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-neutral-200 pt-5 dark:border-neutral-800">
                    <Link
                        href="/inventory"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-600 transition hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 disabled:opacity-50"
                    >
                        <Save class="size-4" />
                        <span>{{ form.processing ? 'Saving...' : isEditing ? 'Update Product' : 'Save Product' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
