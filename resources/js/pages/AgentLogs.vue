<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Bot, RefreshCw } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Agent Logs', href: '/agent-logs' },
];
</script>

<template>
    <Head title="AI Agent Logs - EcoLoop Agent" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">AI Agent Logs</h1>
                    <p class="text-sm text-muted-foreground">Autonomous decisions, discount triggers, and redistribution recommendations.</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border/80 bg-card px-3.5 py-2 text-sm font-medium hover:bg-muted transition"
                >
                    <RefreshCw class="size-4" />
                    <span>Refresh Stream</span>
                </button>
            </div>

            <div class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/80 bg-card/50 p-12 text-center">
                <div class="rounded-full bg-amber-500/10 p-4 text-amber-600 dark:text-amber-400">
                    <Bot class="size-8" />
                </div>
                <h3 class="mt-4 text-base font-semibold">Autonomous Agent Idle</h3>
                <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
                    The Gemini / Groq reasoning agent evaluates inventory cycles periodically. Execution trails and tool-calls will stream here.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
