<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Package, Plus } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Inventory', href: '/inventory' },
];
</script>

<template>
    <Head title="Food Inventory - EcoLoop Agent" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">Food Inventory</h1>
                    <p class="text-sm text-muted-foreground">Monitor surplus items, expiration predictions, and salvage opportunities.</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700"
                >
                    <Plus class="size-4" />
                    <span>Add Item</span>
                </button>
            </div>

            <div
                class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/80 bg-card/50 p-12 text-center"
            >
                <div class="rounded-full bg-emerald-500/10 p-4 text-emerald-600 dark:text-emerald-400">
                    <Package class="size-8" />
                </div>
                <h3 class="mt-4 text-base font-semibold">Inventory Manager Ready</h3>
                <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                    No surplus inventory logged yet. Connect your ERP or add stock items manually to trigger automated shelf-life analysis.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
