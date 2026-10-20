<script setup lang="ts">
import InventoryIndex from './Inventory/Index.vue';

defineProps<{
    products?: any;
    filters?: any;
    categories?: any;
    donationPartners?: any;
    stats?: any;
}>();
</script>

<template>
    <InventoryIndex v-bind="$props" />
</template>
