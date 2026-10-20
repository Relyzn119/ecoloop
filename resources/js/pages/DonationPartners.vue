<script setup lang="ts">
import PartnersIndex from './Partners/Index.vue';

defineProps<{
    partners?: any;
    filters?: any;
    stats?: any;
}>();
</script>

<template>
    <PartnersIndex v-bind="$props" />
</template>
