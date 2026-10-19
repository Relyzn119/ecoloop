<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Handshake, UserPlus } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Donation Partners', href: '/donation-partners' },
];
</script>

<template>
    <Head title="Donation Partners - EcoLoop Agent" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">Donation & NGO Partners</h1>
                    <p class="text-sm text-muted-foreground">Charity food banks, logistics dispatch networks, and shelter dispatch contacts.</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 transition"
                >
                    <UserPlus class="size-4" />
                    <span>Register Partner</span>
                </button>
            </div>

            <div class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/80 bg-card/50 p-12 text-center">
                <div class="rounded-full bg-violet-500/10 p-4 text-violet-600 dark:text-violet-400">
                    <Handshake class="size-8" />
                </div>
                <h3 class="mt-4 text-base font-semibold">No Redistribution Partners Configured</h3>
                <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                    Connect local food rescues and shelters to enable one-click smart batch donation dispatch when shelf-life thresholds trigger.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
