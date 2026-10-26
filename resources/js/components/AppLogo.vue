<script setup lang="ts">
interface Props { class?: string }
defineProps<Props>();
</script>

<template>
    <span class="flex min-w-0 flex-1 items-center gap-2.5">
        <img src="/images/ecoloop-mark.png" alt="EcoLoop" class="hidden size-8 shrink-0 object-contain group-data-[collapsible=icon]:block" />
        <img src="/images/ecoloop-logo-light.png" alt="EcoLoop" class="h-8 w-auto max-w-[145px] object-contain dark:hidden group-data-[collapsible=icon]:hidden" />
        <img src="/images/ecoloop-logo-dark.png" alt="EcoLoop" class="hidden h-8 w-auto max-w-[145px] object-contain dark:block group-data-[collapsible=icon]:hidden" />
    </span>
</template>
