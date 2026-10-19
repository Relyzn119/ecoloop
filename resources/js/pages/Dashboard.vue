<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { AlertCircle, ArrowDownRight, ArrowUpRight, CheckCircle2, Handshake, Info, Leaf, Package, ScrollText, X, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Flash notifications
const page = usePage();
const flash = computed(() => page.props.flash as Record<string, string> | undefined);
const dismissedFlashes = ref<Set<string>>(new Set());

function dismissFlash(type: string) {
    dismissedFlashes.value.add(type);
}

function isFlashVisible(type: string): boolean {
    return !dismissedFlashes.value.has(type) && !!flash.value?.[type];
}

// Stats cards data
const stats = [
    {
        title: 'Food Waste Prevented',
        value: '0 kg',
        change: '+0%',
        changeType: 'positive' as const,
        icon: Leaf,
        color: 'text-emerald-600 dark:text-emerald-400',
        bgColor: 'bg-emerald-500/10',
    },
    {
        title: 'Active Inventory',
        value: '0',
        change: '0 items',
        changeType: 'neutral' as const,
        icon: Package,
        color: 'text-blue-600 dark:text-blue-400',
        bgColor: 'bg-blue-500/10',
    },
    {
        title: 'AI Agent Actions',
        value: '0',
        change: 'No activity yet',
        changeType: 'neutral' as const,
        icon: Zap,
        color: 'text-amber-600 dark:text-amber-400',
        bgColor: 'bg-amber-500/10',
    },
    {
        title: 'Donation Partners',
        value: '0',
        change: '0 connected',
        changeType: 'neutral' as const,
        icon: Handshake,
        color: 'text-violet-600 dark:text-violet-400',
        bgColor: 'bg-violet-500/10',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Flash Notification Container -->
            <Transition name="flash">
                <div
                    v-if="isFlashVisible('success')"
                    class="flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                >
                    <CheckCircle2 class="size-5 shrink-0" />
                    <span class="flex-1">{{ flash?.success }}</span>
                    <button @click="dismissFlash('success')" class="shrink-0 rounded-md p-0.5 hover:bg-emerald-200 dark:hover:bg-emerald-800">
                        <X class="size-4" />
                    </button>
                </div>
            </Transition>

            <Transition name="flash">
                <div
                    v-if="isFlashVisible('error')"
                    class="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/50 dark:text-red-300"
                >
                    <AlertCircle class="size-5 shrink-0" />
                    <span class="flex-1">{{ flash?.error }}</span>
                    <button @click="dismissFlash('error')" class="shrink-0 rounded-md p-0.5 hover:bg-red-200 dark:hover:bg-red-800">
                        <X class="size-4" />
                    </button>
                </div>
            </Transition>

            <Transition name="flash">
                <div
                    v-if="isFlashVisible('info')"
                    class="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-300"
                >
                    <Info class="size-5 shrink-0" />
                    <span class="flex-1">{{ flash?.info }}</span>
                    <button @click="dismissFlash('info')" class="shrink-0 rounded-md p-0.5 hover:bg-blue-200 dark:hover:bg-blue-800">
                        <X class="size-4" />
                    </button>
                </div>
            </Transition>

            <!-- Stats Cards Grid -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="stat in stats"
                    :key="stat.title"
                    class="group relative overflow-hidden rounded-xl border border-sidebar-border/70 bg-card p-5 transition-all duration-200 hover:shadow-md dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between">
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-muted-foreground">{{ stat.title }}</p>
                            <p class="text-2xl font-bold tracking-tight">{{ stat.value }}</p>
                        </div>
                        <div class="rounded-lg p-2.5" :class="stat.bgColor">
                            <component :is="stat.icon" class="size-5" :class="stat.color" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-center gap-1 text-xs text-muted-foreground">
                        <ArrowUpRight v-if="stat.changeType === 'positive'" class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                        <ArrowDownRight v-else-if="stat.changeType === 'negative'" class="size-3.5 text-red-600 dark:text-red-400" />
                        <span :class="{ 'text-emerald-600 dark:text-emerald-400': stat.changeType === 'positive', 'text-red-600 dark:text-red-400': stat.changeType === 'negative' }">
                            {{ stat.change }}
                        </span>
                    </div>
                    <!-- Subtle gradient accent -->
                    <div class="pointer-events-none absolute -right-6 -top-6 size-24 rounded-full opacity-0 blur-2xl transition-opacity duration-300 group-hover:opacity-100" :class="stat.bgColor" />
                </div>
            </div>

            <!-- Content Panels -->
            <div class="grid flex-1 gap-4 lg:grid-cols-3">
                <!-- Recent Agent Activity -->
                <div class="lg:col-span-2 rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    <div class="flex items-center gap-2 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border">
                        <ScrollText class="size-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold">Recent Agent Activity</h3>
                    </div>
                    <div class="flex flex-col items-center justify-center gap-3 px-5 py-16 text-center">
                        <div class="rounded-full bg-muted p-3">
                            <Zap class="size-6 text-muted-foreground" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">No agent activity yet</p>
                            <p class="mt-1 text-xs text-muted-foreground/70">AI agents will start logging actions once inventory is configured.</p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions / Waste Prevention Summary -->
                <div class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    <div class="flex items-center gap-2 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border">
                        <Leaf class="size-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold">Waste Prevention</h3>
                    </div>
                    <div class="flex flex-col items-center justify-center gap-3 px-5 py-16 text-center">
                        <div class="rounded-full bg-emerald-500/10 p-3">
                            <Leaf class="size-6 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Getting started</p>
                            <p class="mt-1 text-xs text-muted-foreground/70">Add inventory items to begin tracking waste prevention metrics.</p>
                        </div>
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
