<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Activity, Leaf, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
}>();

const page = usePage();

const systemStatus = computed(() => {
    const flash = page.props.flash as Record<string, string> | undefined;
    if (flash?.error) {
        return { label: 'Issue Detected', color: 'bg-red-500', pulse: true };
    }
    return { label: 'All Systems Operational', color: 'bg-emerald-500', pulse: false };
});
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs.length > 0">
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem>
                                <template v-if="index === breadcrumbs.length - 1">
                                    <BreadcrumbPage>{{ item.title }}</BreadcrumbPage>
                                </template>
                                <template v-else>
                                    <BreadcrumbLink :href="item.href">
                                        {{ item.title }}
                                    </BreadcrumbLink>
                                </template>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </template>
        </div>

        <!-- Right side: Quick Stats & System Status -->
        <div class="ml-auto hidden items-center gap-4 sm:flex">
            <!-- Quick Stats Indicators -->
            <div class="flex items-center gap-3 text-xs text-muted-foreground">
                <div class="flex items-center gap-1.5 rounded-md bg-emerald-500/10 px-2.5 py-1.5 text-emerald-700 dark:text-emerald-400">
                    <Leaf class="size-3.5" />
                    <span class="font-medium">0 kg saved</span>
                </div>
                <div class="flex items-center gap-1.5 rounded-md bg-amber-500/10 px-2.5 py-1.5 text-amber-700 dark:text-amber-400">
                    <Zap class="size-3.5" />
                    <span class="font-medium">0 actions</span>
                </div>
            </div>

            <!-- System Status -->
            <div class="flex items-center gap-2 rounded-md border border-sidebar-border/70 px-2.5 py-1.5 text-xs">
                <Activity class="size-3.5 text-muted-foreground" />
                <span class="text-muted-foreground">{{ systemStatus.label }}</span>
                <span class="relative flex size-2">
                    <span
                        v-if="systemStatus.pulse"
                        class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
                        :class="systemStatus.color"
                    />
                    <span class="relative inline-flex size-2 rounded-full" :class="systemStatus.color" />
                </span>
            </div>
        </div>
    </header>
</template>
