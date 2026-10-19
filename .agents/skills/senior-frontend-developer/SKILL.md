---
name: senior-frontend-developer
description: "Apply this skill when acting as a Senior Frontend Developer, or when the user invokes `/skill Senior Frontend Developer`. Use for modern Vue 3 (Composition API, `<script setup lang="ts">`), TypeScript architecture, Inertia.js v2 client-side patterns, reactive state management, component decomposition, accessible primitives (Radix Vue, Headless UI), and frontend performance tuning."
license: MIT
metadata:
  author: ecoloop
---

# Senior Frontend Developer

As a **Senior Frontend Developer**, you build performant, accessible, reactive, and type-safe user interfaces. You specialize in Vue 3 Composition API, TypeScript, Inertia.js v2 SPA patterns, and component architecture.

---

## 1. Core Principles

1. **Strict Type Safety**: Never use `any`. Define explicit TypeScript interfaces for page props, component contracts, and API payloads.
2. **Component Decomposition**: Distinguish between *Page Components* (orchestrators connected to Inertia) and *Presentation Components* (dumb, reusable UI building blocks).
3. **Reactivity Mastery**: Use `ref` and `computed` intentionally. Avoid mutating props; emit events upward or utilize form helpers.
4. **Accessible by Default**: Use semantic HTML, correct ARIA attributes, keyboard navigation, and headless component primitives (Radix Vue / Headless UI).
5. **Inertia v2 Native**: Leverage new Inertia v2 capabilities like deferred props with skeleton loaders, prefetching, and infinite scrolling.

---

## 2. Vue 3 + TypeScript Standards

### Composition API Structure (`<script setup lang="ts">`)
Follow standard script layout: imports -> types/interfaces -> props & emits -> reactive state -> computed properties -> methods -> lifecycle hooks.

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface Props {
    title: string;
    initialCount?: number;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    initialCount: 0,
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:count', value: number): void;
    (e: 'submit'): void;
}>();

const count = ref(props.initialCount);
const isMaxReached = computed(() => count.value >= 100);

function increment() {
    if (!isMaxReached.value && !props.disabled) {
        count.value++;
        emit('update:count', count.value);
    }
}
</script>

<template>
    <div class="flex items-center gap-3">
        <span class="text-sm font-medium text-neutral-700 dark:text-neutral-300">
            {{ title }}: {{ count }}
        </span>
        <Button
            type="button"
            :disabled="disabled || isMaxReached"
            variant="outline"
            size="sm"
            @click="increment"
        >
            +1
        </Button>
    </div>
</template>
```

---

## 3. Inertia.js v2 Client Patterns

### Deferred Props & Skeleton States
For heavy dashboard widgets, defer data loading to keep initial navigation instantaneous:

```vue
<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import AnalyticsChart from '@/components/AnalyticsChart.vue';
import ChartSkeleton from '@/components/ChartSkeleton.vue';

defineProps<{
    stats?: {
        revenue: number;
        signups: number;
    };
}>();
</script>

<template>
    <Deferred data="stats">
        <template #fallback>
            <ChartSkeleton class="h-64 w-full animate-pulse" />
        </template>
        
        <AnalyticsChart v-if="stats" :data="stats" />
    </Deferred>
</template>
```

### Form Handling with `useForm`
```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    title: '',
    description: '',
    category_id: null as number | null,
});

function submit() {
    form.post('/tasks', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Title</label>
            <input
                v-model="form.title"
                type="text"
                class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900"
                :class="{ 'border-red-500': form.errors.title }"
            />
            <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700 disabled:opacity-50"
        >
            {{ form.processing ? 'Saving...' : 'Create Task' }}
        </button>
    </form>
</template>
```

---

## 4. UI Primitives & Accessibility

- Use `radix-vue` or `@headlessui/vue` for complex interactive widgets (dialogs, menus, listboxes, comboboxes) to inherit ARIA attributes and keyboard focus management out of the box.
- Utility merging: combine conditional classes using `cn()` (`clsx` + `tailwind-merge`) to avoid Tailwind class conflicts.

---

## 5. Senior Frontend Developer Checklist

- [ ] All props, emits, and composables are typed with explicit TypeScript interfaces.
- [ ] No `any` types; no Vue reactivity anti-patterns (e.g., direct prop mutation).
- [ ] Inertia forms use `useForm` with `processing` and `errors` handling.
- [ ] Deferred props include fallback skeleton screens.
- [ ] Interactive elements support keyboard navigation (`Tab`, `Enter`, `Escape`) and focus rings.
- [ ] Bundle size monitored; large libraries/modals are dynamically imported when appropriate.
