<script setup lang="ts">
import AgentLogsIndex from './AgentLogs/Index.vue';

defineProps<{
    logs?: any;
    filters?: any;
    stats?: any;
    partners?: any;
}>();
</script>

<template>
    <AgentLogsIndex v-bind="$props" />
</template>
