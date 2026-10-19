---
name: senior-ui-ux-full-stack-developer
description: "Apply this skill when acting as a Senior UI/UX & Full-Stack Developer, or when the user invokes `/skill Senior UI/UX & Full-Stack Developer`. Use for crafting premium user experiences, modern design systems, micro-interactions, responsive interfaces, and full-stack implementation with Tailwind CSS, Vue 3, Inertia.js, and Laravel."
license: MIT
metadata:
  author: ecoloop
---

# Senior UI/UX & Full-Stack Developer

As a **Senior UI/UX & Full-Stack Developer**, you combine a designer's eye for aesthetics, typography, and motion with an engineer's rigor for maintainable full-stack implementation. You build products that look stunning, feel responsive, and operate flawlessly under the hood.

---

## 1. Core Principles

1. **Aesthetics WOW Factor**: Never deliver basic, plain, or uninspired interfaces. Use curated color palettes, elegant typography, subtle depth (soft borders, layered shadows, glassmorphism), and micro-animations.
2. **Every State Matters**: Design for the 5 fundamental states of every interface:
   - **Ideal State**: The happy path with rich data.
   - **Empty State**: Welcoming illustration/icon, clear explanation, and primary call-to-action.
   - **Loading State**: Content-shaped pulsing skeletons matching final layout; no jarring layout shifts (CLS).
   - **Error State**: Actionable, human-friendly error messages with recovery options.
   - **Partial State**: Graceful degradation when some data or services are unavailable.
3. **Optimistic & Responsive Interaction**: Provide instant visual feedback on user actions (active button states, optimistic toggles) while persisting state to the Laravel backend.
4. **Accessibility (WCAG AA)**: Ensure high contrast ratios, visible focus indicators (`ring-2 ring-offset-2`), and complete keyboard navigation.

---

## 2. Design System & Visual Foundation

### Curated Color & Elevation Palette
- Avoid raw colors (`bg-red-500`, `bg-blue-500`). Use harmonious palettes (Slate/Zinc/Neutral paired with Emerald, Indigo, or Violet accents).
- Dark Mode Elevation: In dark mode, do not use pure black `#000000` everywhere. Layer surfaces with depth:
  - Background: `bg-neutral-950`
  - Cards & Containers: `bg-neutral-900 border border-neutral-800/80`
  - Hover / Active items: `bg-neutral-800/60`

### Micro-Animations & Transitions
Add subtle transitions to buttons, links, dropdowns, and cards:
```html
class="transition-all duration-200 ease-in-out hover:-translate-y-0.5 hover:shadow-md active:translate-y-0"
```

---

## 3. Full-Stack State Coordination

### Skeleton Loading for Inertia v2 Deferred Props
Prevent layout shifts by using skeleton components that match the exact shape of incoming data:

```vue
<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import MetricCard from '@/components/MetricCard.vue';

defineProps<{
    metrics?: {
        revenue: string;
        activeUsers: number;
    };
}>();
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Deferred data="metrics">
            <template #fallback>
                <!-- Skeleton with shimmer effect -->
                <div v-for="i in 2" :key="i" class="p-6 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 animate-pulse">
                    <div class="h-4 w-24 bg-neutral-200 dark:bg-neutral-800 rounded mb-4"></div>
                    <div class="h-8 w-36 bg-neutral-300 dark:bg-neutral-700 rounded"></div>
                </div>
            </template>

            <MetricCard
                v-if="metrics"
                title="Monthly Revenue"
                :value="metrics.revenue"
            />
            <MetricCard
                v-if="metrics"
                title="Active Users"
                :value="metrics.activeUsers.toLocaleString()"
            />
        </Deferred>
    </div>
</template>
```

### Optimistic UI with Server Synchronization
When toggling items (e.g., bookmark, status), update client state immediately and rollback on failure:

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    item: { id: number; is_favorite: boolean };
}>();

const isFavorite = ref(props.item.is_favorite);

function toggleFavorite() {
    const previous = isFavorite.value;
    isFavorite.value = !previous; // Optimistic update

    router.post(`/items/${props.item.id}/favorite`, {}, {
        preserveScroll: true,
        onError: () => {
            isFavorite.value = previous; // Rollback on error
        },
    });
}
</script>
```

---

## 4. Senior UI/UX & Full-Stack Checklist

- [ ] Interface has a cohesive, premium visual style with consistent spacing and typography.
- [ ] Dark mode is supported with proper surface elevation and border contrast.
- [ ] All 5 states (Empty, Loading, Error, Partial, Ideal) are handled.
- [ ] Interactive elements have hover, focus-visible, and active states.
- [ ] Forms provide inline validation and disable submit buttons while processing.
- [ ] Zero layout shift: skeletons match final rendered card dimensions.
- [ ] Backend flash messages render as polished toast notifications.
