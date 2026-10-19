<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" :class="className" v-bind="$attrs">
        <!-- Circular arrows (recycling loop) -->
        <path
            fill="currentColor"
            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8Z"
            opacity="0.3"
        />
        <!-- Leaf shape in center -->
        <path
            fill="currentColor"
            d="M17 8c-2.21 0-4.21.9-5.66 2.34L10 9h-.01C9.02 6.19 6.77 4 4 4v2c2.1 0 3.9 1.34 4.57 3.21l.71 2.07L7.2 13.36C8.28 14.35 9 15.77 9 17.36V20h2v-2.64c0-1.33-.45-2.56-1.21-3.54l1.82-1.82A5.983 5.983 0 0 1 17 10v-2Z"
        />
        <!-- Small sprout accent -->
        <path fill="currentColor" d="M15.5 7.5c0 1.38-1.12 2.5-2.5 2.5V8.5c.55 0 1-.45 1-1s-.45-1-1-1V5c1.38 0 2.5 1.12 2.5 2.5Z" />
    </svg>
</template>
