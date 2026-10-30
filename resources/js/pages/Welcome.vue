<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Bot,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Handshake,
    Leaf,
    Menu,
    Package,
    ScanEye,
    Sparkles,
    Store,
    Tag,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();
const isSignedIn = computed(() => Boolean((page.props as unknown as { auth?: { user?: unknown } }).auth?.user));
const primaryHref = computed(() => (isSignedIn.value ? route('dashboard') : route('login')));
const primaryLabel = computed(() => (isSignedIn.value ? 'Open dashboard' : 'Sign in to EcoLoop'));
const carousel = ref<HTMLElement | null>(null);
const proofSection = ref<HTMLElement | null>(null);
const counts = ref([0, 0, 0]);
const openFaq = ref(0);
const mobileMenuOpen = ref(false);
let revealObserver: IntersectionObserver | undefined;
let counterObserver: IntersectionObserver | undefined;
let parallaxFrame = 0;
let counterFrame = 0;
let counted = false;

const features = [
    {
        eyebrow: '01 · VISIBILITY',
        title: 'Know your stock before it is too late.',
        description: 'Track products and batches by shelf life, quantity, and source in one actionable view.',
        image: 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1200&q=85',
        alt: 'Fresh produce at a market',
        icon: Package,
    },
    {
        eyebrow: '02 · VALUE RECOVERY',
        title: 'Discount at the right moment.',
        description: 'Markdown recommendations help products retain value for customers and reduce the chance of inventory becoming waste.',
        image: 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=1200&q=85',
        alt: 'Freshly baked bread',
        icon: Tag,
    },
    {
        eyebrow: '03 · TARGETED DONATIONS',
        title: 'Connect surplus food with partners.',
        description: 'Allocate safe-to-eat products, coordinate reviews, and keep donation handoffs transparent.',
        image: 'https://images.unsplash.com/photo-1464226184884-fa280b87c399?auto=format&fit=crop&w=1200&q=85',
        alt: 'Fresh vegetable harvest',
        icon: Handshake,
    },
];
const faqs = [
    {
        question: 'What is EcoLoop?',
        answer: 'EcoLoop helps retailers manage food inventory nearing its expiration date. It tracks shelf life, recommends discounts, and connects safe-to-eat products with donation partners.',
    },
    {
        question: 'Does EcoLoop change prices automatically?',
        answer: 'EcoLoop can recommend markdowns based on stock conditions and remaining shelf life. Your operations team can review and approve actions before any changes are applied.',
    },
    {
        question: 'How do partners receive donations?',
        answer: 'Products allocated for donation are routed through partner workflows. EcoLoop supports notifications and reviews so every handoff is clear and recorded.',
    },
    {
        question: 'Can EcoLoop connect to store inventory?',
        answer: 'EcoLoop syncs product, quantity, price, and expiration data with your store operations.',
    },
];
const nodes = [
    { label: 'POS', icon: Store, pos: 'node-top' },
    { label: 'AI', icon: Bot, pos: 'node-right-top' },
    { label: 'Vision', icon: ScanEye, pos: 'node-right-bottom' },
    { label: 'Partners', icon: Handshake, pos: 'node-bottom' },
    { label: 'Inventory', icon: Package, pos: 'node-left-bottom' },
    { label: 'Discount', icon: Tag, pos: 'node-left-top' },
];
function scrollFeatures(direction: -1 | 1): void {
    carousel.value?.scrollBy({ left: direction * 440, behavior: 'smooth' });
}
function animateCounters(): void {
    if (counted) return;
    counted = true;
    const targets = [3, 2, 1];
    const start = performance.now();
    const tick = (now: number) => {
        const progress = Math.min((now - start) / 1200, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        counts.value = targets.map((target) => Math.round(target * eased));
        if (progress < 1) counterFrame = requestAnimationFrame(tick);
        else counts.value = targets;
    };
    counterFrame = requestAnimationFrame(tick);
}
function splitHeadingWords(): void {
    document.querySelectorAll<HTMLElement>('.w-anim').forEach((heading) => {
        const walker = document.createTreeWalker(heading, NodeFilter.SHOW_TEXT);
        const nodes: Text[] = [];
        while (walker.nextNode()) nodes.push(walker.currentNode as Text);
        let wordIndex = 0;
        nodes.forEach((node) => {
            const fragment = document.createDocumentFragment();
            (node.textContent ?? '').split(/(\s+)/).forEach((part) => {
                if (!part) return;
                if (/^\s+$/.test(part)) {
                    fragment.appendChild(document.createTextNode(part));
                    return;
                }
                const word = document.createElement('span');
                word.className = 'word-reveal';
                word.textContent = part;
                word.style.animationDelay = wordIndex * 55 + 'ms';
                wordIndex += 1;
                fragment.appendChild(word);
            });
            node.parentNode?.replaceChild(fragment, node);
        });
    });
}
function updateParallax(): void {
    if (parallaxFrame) cancelAnimationFrame(parallaxFrame);
    parallaxFrame = requestAnimationFrame(() =>
        document.querySelectorAll<HTMLElement>('.parallax').forEach((element) => {
            const rect = element.getBoundingClientRect();
            element.style.transform = 'translate3d(0, ' + (rect.top - window.innerHeight / 2) * 0.06 + 'px, 0)';
        }),
    );
}
onMounted(() => {
    splitHeadingWords();
    revealObserver = new IntersectionObserver(
        (entries, observer) =>
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer.unobserve(entry.target);
                }
            }),
        { threshold: 0.12 },
    );
    document.querySelectorAll('.reveal').forEach((element) => revealObserver?.observe(element));
    if (proofSection.value) {
        counterObserver = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    animateCounters();
                    counterObserver?.disconnect();
                }
            },
            { threshold: 0.35 },
        );
        counterObserver.observe(proofSection.value);
    }
    window.addEventListener('scroll', updateParallax, { passive: true });
    updateParallax();
});
onBeforeUnmount(() => {
    revealObserver?.disconnect();
    counterObserver?.disconnect();
    window.removeEventListener('scroll', updateParallax);
    cancelAnimationFrame(parallaxFrame);
    cancelAnimationFrame(counterFrame);
});
</script>
<template>
    <Head title="EcoLoop — Save food, keep its value">
        <meta
            name="description"
            content="Track shelf life, preserve product value with markdowns, and route safe-to-eat food through donation partners."
        />
        <link rel="preconnect" href="https://fonts.googleapis.com" /><link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin="anonymous"
        />
        <link
            href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,500&family=Inter:wght@400;500;600;700&display=swap"
            rel="stylesheet"
        />
    </Head>
    <div class="ecoloop min-h-screen overflow-hidden bg-[#F2EEE5] text-[#171512] antialiased">
        <header class="fixed inset-x-0 top-0 z-50 px-4 pt-4 sm:px-8 lg:px-12">
            <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-2 rounded-full border border-white/65 bg-[#F2EEE5]/85 px-3 py-2.5 shadow-lg backdrop-blur-2xl sm:px-7 sm:py-3">
                <a href="#home" class="inline-flex items-center" aria-label="EcoLoop — back to home"><img src="/images/ecoloop-logo-light.png" alt="EcoLoop" class="h-7 w-auto max-w-[92px] sm:h-9 sm:max-w-[150px]" /></a>
                <nav class="hidden items-center gap-8 text-[12px] font-medium text-[#6d6a5f] lg:flex" aria-label="Main navigation">
                    <a href="#solusi">Solutions</a><a href="#alur">How it works</a><a href="#dampak">Impact</a><a href="#integrasi">Integrations</a><a href="#faq">FAQ</a>
                </nav>
                <div class="flex items-center gap-2"><Link :href="primaryHref" class="btn-lift inline-flex items-center gap-2 rounded-full bg-[#171512] px-3 py-2.5 text-[10px] font-semibold text-[#F2EEE5] sm:px-5 sm:text-[12px]"><span class="hidden sm:inline">{{ primaryLabel }}</span><span class="sm:hidden">{{ isSignedIn ? 'Dashboard' : 'Sign in' }}</span> <ArrowUpRight class="size-3.5" /></Link><button type="button" class="grid size-10 place-items-center rounded-full border border-[#d8d2c5] text-[#3B4B34] transition active:scale-95 lg:hidden" :aria-expanded="mobileMenuOpen" aria-controls="mobile-site-menu" aria-label="Open navigation" @click="mobileMenuOpen = !mobileMenuOpen"><X v-if="mobileMenuOpen" class="size-4" /><Menu v-else class="size-4" /></button></div>
            </div>
            <nav v-if="mobileMenuOpen" id="mobile-site-menu" class="mx-auto mt-2 grid max-w-[1440px] gap-1 rounded-3xl border border-white/70 bg-[#F2EEE5]/95 p-3 text-sm text-[#3b4b34] shadow-xl backdrop-blur-2xl lg:hidden">
                <a v-for="item in [{ label: 'Solutions', href: '#solusi' }, { label: 'How it works', href: '#alur' }, { label: 'Impact', href: '#dampak' }, { label: 'Integrations', href: '#integrasi' }, { label: 'FAQ', href: '#faq' }]" :key="item.href" :href="item.href" class="rounded-xl px-4 py-3 transition hover:bg-[#e7ebde] active:scale-[.99]" @click="mobileMenuOpen = false">{{ item.label }}</a>
            </nav>        </header>
        <main id="home">
            <section class="hero relative isolate flex min-h-[760px] items-center px-5 pb-20 pt-36 sm:px-8 lg:min-h-[900px] lg:px-12 lg:pt-40">
                <div class="hero-photo parallax absolute inset-0 -z-20" role="img" aria-label="Fresh vegetable harvest" />
                <div class="hero-shade absolute inset-0 -z-10" />
                <div class="hero-glow absolute -left-36 top-32 -z-10 size-[460px] rounded-full" />
                <div class="mx-auto grid w-full max-w-[1320px] items-center gap-14 lg:grid-cols-[.88fr_1.12fr] lg:gap-8">
                    <div class="relative z-10 max-w-[610px] text-white">
                        <div
                            class="reveal mb-7 inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-3.5 py-2 text-[10px] font-semibold uppercase tracking-[.16em] backdrop-blur-xl"
                        >
                            <span class="size-2 rounded-full bg-[#b4c29a]"></span>A circular system for retail food
                        </div>
                        <h1 class="w-anim reveal font-serif text-[clamp(3.6rem,8vw,7.3rem)] leading-[.9] tracking-[-.055em] text-[#F7F4ED]">
                            <span class="block">Save</span><span class="block">food<span class="text-[#bdcaa6]">.</span></span
                            ><span class="block italic text-[#d8dfc8]">Keep its value.</span>
                        </h1>
                        <p class="reveal mt-7 max-w-[450px] text-[14px] leading-7 text-white/75 sm:text-[15px]">
                            EcoLoop helps retailers spot risk early, make informed decisions, and redirect food that still has value
                            to the people who need it.
                        </p>
                        <div class="reveal mt-8 flex flex-wrap gap-3">
                            <Link
                                :href="primaryHref"
                                class="btn-lift inline-flex items-center gap-3 rounded-full bg-[#F2EEE5] px-6 py-3.5 text-[12px] font-semibold text-[#171512]"
                                >{{ primaryLabel }} <ArrowRight class="size-4" /></Link
                            ><a
                                href="#solusi"
                                class="inline-flex items-center gap-2 rounded-full border border-white/30 bg-white/5 px-5 py-3.5 text-[12px] backdrop-blur-lg"
                                >Explore how it works <ArrowDownRight class="size-4"
                            /></a>
                        </div>
                        <div class="reveal mt-12 flex flex-wrap gap-5 text-[10px] text-white/65">
                            <span class="inline-flex items-center gap-2"><Check class="size-3.5 text-[#bdcaa6]" /> Recommendations stay reviewable</span
                            ><span class="inline-flex items-center gap-2"><Check class="size-3.5 text-[#bdcaa6]" /> Every step is recorded</span>
                        </div>
                    </div>
                    <div class="reveal relative mx-auto w-full max-w-[710px] lg:ml-auto">
                        <div class="absolute -right-5 -top-8 size-32 rounded-full bg-[#c68c62]/35 blur-3xl"></div>
                        <div class="absolute -bottom-8 -left-8 size-40 rounded-full bg-[#8e9c75]/40 blur-3xl"></div>
                        <div
                            class="grad-border relative overflow-hidden rounded-[30px] bg-[#f8f5ed]/90 p-2.5 shadow-[0_35px_100px_rgba(20,30,20,.34)] backdrop-blur-[24px] sm:p-3.5"
                        >
                            <div class="overflow-hidden rounded-[24px] bg-[#fbfaf6] text-[#171512]">
                                <div class="flex items-center justify-between border-b border-[#e9e5dc] px-4 py-3 sm:px-6 sm:py-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="grid size-8 place-items-center rounded-xl bg-[#3B4B34] text-white"><Leaf class="size-4" /></div>
                                        <div>
                                            <p class="text-[11px] font-semibold">EcoLoop Control</p>
                                            <p class="text-[9px] text-[#8a877d]">Concept preview</p>
                                        </div>
                                    </div>
                                    <span class="rounded-full bg-[#edf1e7] px-2.5 py-1 text-[9px] text-[#3B4B34]">● System connected</span>
                                </div>
                                <div class="grid min-h-[370px] sm:grid-cols-[140px_1fr]">
                                    <aside class="hidden border-r border-[#e9e5dc] bg-[#f6f3eb] p-4 sm:flex sm:flex-col">
                                        <p class="mb-3 px-2 text-[8px] font-semibold uppercase tracking-[.15em] text-[#a29e93]">Workspace</p>
                                        <div class="flex flex-col gap-1 text-[10px]">
                                            <div class="flex items-center gap-2 rounded-xl bg-[#e7ebde] px-2.5 py-2.5 text-[#3B4B34]">
                                                <Package class="size-3.5" /> Dashboard
                                            </div>
                                            <div class="flex items-center gap-2 px-2.5 py-2.5 text-[#817e74]">
                                                <Bot class="size-3.5" /> Inventory
                                            </div>
                                            <div class="flex items-center gap-2 px-2.5 py-2.5 text-[#817e74]">
                                                <Handshake class="size-3.5" /> AI Simulation
                                            </div>
                                        </div>
                                    </aside>
                                    <div class="min-w-0 p-4 sm:p-5">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-[8px] font-semibold uppercase tracking-[.15em] text-[#929084]">
                                                    Operational overview
                                                </p>
                                                <h2 class="mt-1 font-serif text-[25px] sm:text-[30px]">
                                                    Food under control<span class="text-[#8E9C75]">.</span>
                                                </h2>
                                            </div>
                                        </div>
                                        <div class="mt-4 grid grid-cols-2 gap-2 xl:grid-cols-4">
                                            <div class="rounded-2xl border border-[#ebe7de] bg-white/75 p-2.5"><p class="text-[8px] text-[#8d8a7e]">Expiring soon</p><p class="mt-1 font-serif text-[20px]">7</p><p class="text-[8px] text-[#bb7a54]">Critical window</p></div>
                                            <div class="rounded-2xl border border-[#ebe7de] bg-white/75 p-2.5"><p class="text-[8px] text-[#8d8a7e]">Food waste diverted</p><p class="mt-1 font-serif text-[20px]">88 <span class="text-[10px]">kg</span></p><p class="text-[8px] text-[#71845f]">Rescued food</p></div>
                                            <div class="rounded-2xl border border-[#ebe7de] bg-white/75 p-2.5"><p class="text-[8px] text-[#8d8a7e]">Loss prevented</p><p class="mt-1 font-serif text-[18px]">Rp 5.7jt</p><p class="text-[8px] text-[#71845f]">Markdown & rescue</p></div>
                                            <div class="rounded-2xl border border-[#ebe7de] bg-white/75 p-2.5"><p class="text-[8px] text-[#8d8a7e]">CO₂e reduced</p><p class="mt-1 font-serif text-[20px]">220 <span class="text-[10px]">kg</span></p><p class="text-[8px] text-[#71845f]">Impact tracked</p></div>
                                        </div>
                                        <div class="mt-3 grid gap-3 lg:grid-cols-[1.2fr_.8fr]">
                                            <div class="rounded-2xl border border-[#ebe7de] bg-white/75 p-3.5">
                                                <div class="flex justify-between">
                                                    <div>
                                                        <p class="text-[9px] font-semibold">Urgent Clearance Items</p>
                                                        <p class="text-[8px] text-[#949084]">Products approaching expiration</p>
                                                    </div>
                                                </div>
                                                <div class="mt-3 flex flex-col gap-2.5">
                                                    <div
                                                        v-for="item in [
                                                            {
                                                                emoji: '🥑',
                                                                name: 'Local avocados',
                                                                status: 'Review',
                                                                color: 'bg-[#bb7a54]',
                                                                width: '72%',
                                                            },
                                                            {
                                                                emoji: '🥬',
                                                                name: 'Leafy greens',
                                                                status: 'Monitor',
                                                                color: 'bg-[#8e9c75]',
                                                                width: '40%',
                                                            },
                                                            {
                                                                emoji: '🥖',
                                                                name: 'Whole-grain bread',
                                                                status: 'Discount',
                                                                color: 'bg-[#d2a16e]',
                                                                width: '55%',
                                                            },
                                                        ]"
                                                        :key="item.name"
                                                        class="flex items-center gap-2.5"
                                                    >
                                                        <div class="grid size-8 place-items-center rounded-xl bg-[#f4ead8] text-[15px]">
                                                            {{ item.emoji }}
                                                        </div>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="flex justify-between text-[9px]">
                                                                <span class="font-semibold">{{ item.name }}</span
                                                                ><span class="text-[#a25e42]">{{ item.status }}</span>
                                                            </div>
                                                            <div class="mt-1.5 h-1 rounded-full bg-[#eeeae0]">
                                                                <div
                                                                    class="h-full rounded-full"
                                                                    :class="item.color"
                                                                    :style="{ width: item.width }"
                                                                ></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="rounded-2xl border border-[#ebe7de] bg-white/75 p-3.5">
                                                <div class="mb-3 flex items-center justify-between"><span class="text-[9px] font-semibold">Agent Activity Feed</span><span class="size-2 rounded-full bg-[#7a9c6b]"></span></div>
                                                <div class="space-y-2">
                                                    <div class="rounded-xl border border-[#ebe7de] bg-[#faf8f2] p-2"><p class="text-[8px] font-semibold text-[#8b6949]">Alert sent · Organic Whole Milk</p><p class="mt-1 text-[7px] text-[#858277]">19 items monitored · markdown active</p></div>
                                                    <div class="rounded-xl border border-[#ebe7de] bg-[#faf8f2] p-2"><p class="text-[8px] font-semibold text-[#596d48]">Donation requested · Sourdough</p><p class="mt-1 text-[7px] text-[#858277]">Dispatched to community partner</p></div>
                                                    <div class="rounded-xl border border-[#ebe7de] bg-[#faf8f2] p-2"><p class="text-[8px] font-semibold text-[#8b6949]">Review needed · Baby Spinach</p><p class="mt-1 text-[7px] text-[#858277]">Shelf life remaining: 48 hours</p></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-3 flex items-center gap-2 rounded-xl border border-[#eae6dd] bg-white/65 px-3 py-2.5">
                                            <Check class="size-3 text-[#3B4B34]" />
                                            <p class="text-[8px] text-[#77746b]">Every action is reviewable and recorded.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="dampak"
                ref="proofSection"
                class="proof relative overflow-hidden bg-[#171512] px-5 py-14 text-[#F2EEE5] sm:px-8 lg:px-12 lg:py-[72px]"
            >
                <div class="proof-glow proof-left absolute -left-20 top-[-120px] size-[350px] rounded-full"></div>
                <div class="proof-glow proof-right absolute -right-10 bottom-[-160px] size-[420px] rounded-full"></div>
                <div class="relative mx-auto grid max-w-[1160px] gap-8 sm:grid-cols-3 sm:gap-6">
                    <div
                        v-for="(item, index) in [
                            { suffix: ' steps', label: 'from monitoring to action', icon: ScanEye },
                            { suffix: ' paths', label: 'markdowns or targeted donations', icon: ArrowRight },
                            { suffix: ' workflows', label: 'connected for operations teams', icon: Sparkles },
                        ]"
                        :key="item.label"
                        class="reveal flex items-center gap-4 border-b border-white/10 pb-7 last:border-0 sm:border-0"
                    >
                        <div class="grid size-11 place-items-center rounded-full border border-white/15 bg-white/[.06] text-[#bdcaa6]">
                            <component :is="item.icon" class="size-[18px]" />
                        </div>
                        <div>
                            <p class="font-serif text-[38px] leading-none">
                                {{ counts[index] }}<span class="ml-1 text-[16px] italic text-[#9eab88]">{{ item.suffix }}</span>
                            </p>
                            <p class="mt-2 text-[10px] text-white/50">{{ item.label }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="solusi" class="px-5 py-24 sm:px-8 lg:px-12 lg:py-32">
                <div class="mx-auto max-w-[1320px]">
                    <div class="reveal mb-10 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p class="mb-4 text-[10px] font-semibold uppercase tracking-[.18em] text-[#71805f]">One ecosystem, three decisions</p>
                            <h2 class="w-anim font-serif text-[clamp(2.8rem,6vw,5.3rem)] leading-[.92]">
                                Food moves<br /><span class="italic text-[#3B4B34]">further.</span>
                            </h2>
                        </div>
                        <p class="max-w-[360px] text-[12px] leading-6 text-[#77746a]">
                            Turn unpredictable inventory into clearer, measurable decisions with a path forward.
                        </p>
                    </div>
                    <div class="reveal mb-5 flex justify-between">
                        <p class="text-[10px] text-[#79766d]">Tools for every stage of food recovery</p>
                        <div class="flex gap-2">
                            <button
                                aria-label="Scroll features left"
                                @click="scrollFeatures(-1)"
                                class="grid size-10 place-items-center rounded-full border border-[#dcd7cb] bg-white/40"
                            >
                                <ChevronLeft class="size-4" /></button
                            ><button
                                aria-label="Scroll features right"
                                @click="scrollFeatures(1)"
                                class="grid size-10 place-items-center rounded-full border border-[#dcd7cb] bg-white/40"
                            >
                                <ChevronRight class="size-4" />
                            </button>
                        </div>
                    </div>
                    <div
                        ref="carousel"
                        class="feature-carousel flex snap-x snap-mandatory gap-5 overflow-x-auto px-[max(2rem,calc((100vw-1200px)/2+2rem))] pb-5 [scrollbar-width:none]"
                    >
                        <article
                            v-for="feature in features"
                            :key="feature.eyebrow"
                            class="grad-border group min-w-[82vw] snap-start overflow-hidden rounded-[28px] bg-[#faf8f2] sm:min-w-[440px] lg:min-w-[calc((100vw-120px)/3)] lg:max-w-[420px]"
                        >
                            <div class="relative h-[240px] overflow-hidden sm:h-[280px]">
                                <img
                                    :src="feature.image"
                                    :alt="feature.alt"
                                    loading="lazy"
                                    class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                                />
                                <div class="absolute inset-0 bg-gradient-to-t from-[#171512]/45 via-transparent"></div>
                                <span
                                    class="absolute left-5 top-5 inline-flex items-center gap-2 rounded-full border border-white/30 bg-white/15 px-3 py-1.5 text-[9px] text-white backdrop-blur-xl"
                                    ><component :is="feature.icon" class="size-3" />{{ feature.eyebrow }}</span
                                ><ArrowUpRight class="absolute bottom-5 right-5 size-4 text-white" />
                            </div>
                            <div class="p-5 sm:p-6">
                                <h3 class="font-serif text-[29px] leading-[1.02]">{{ feature.title }}</h3>
                                <p class="mt-3 text-[11px] leading-5 text-[#7b786e]">{{ feature.description }}</p>
                                <div class="mt-5 h-px bg-[#e7e2d8]"></div>
                                <div class="mt-4 flex justify-between text-[9px] text-[#77746a]">
                                    <span>EcoLoop · {{ feature.eyebrow.split(' · ')[1] }}</span
                                    ><ArrowRight class="size-3.5 text-[#3B4B34]" />
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section id="alur" class="bg-[#e9e5da] px-5 py-24 sm:px-8 lg:px-12 lg:py-32">
                <div class="mx-auto grid max-w-[1180px] items-center gap-12 lg:grid-cols-[.9fr_1.1fr]">
                    <div class="reveal">
                        <p class="mb-4 text-[10px] font-semibold uppercase tracking-[.18em] text-[#71805f]">A workflow that makes sense</p>
                        <h2 class="w-anim font-serif text-[clamp(2.8rem,6vw,5.1rem)] leading-[.94]">
                            From shelf,<br />toward <span class="italic text-[#3B4B34]">impact.</span>
                        </h2>
                        <p class="mt-6 max-w-[410px] text-[12px] leading-6 text-[#77746a]">
                            Bring inventory visibility, reviewable recommendations, and partner coordination together in one workflow.
                        </p>
                        <Link
                            :href="primaryHref"
                            class="btn-lift mt-8 inline-flex items-center gap-3 rounded-full bg-[#3B4B34] px-6 py-3.5 text-[11px] font-semibold text-white"
                            >{{ primaryLabel }} <ArrowRight class="size-4"
                        /></Link>
                    </div>
                    <div class="reveal relative mx-auto w-full max-w-[560px]">
                        <div class="absolute inset-[15%] rounded-full bg-[#b5bf9f]/30 blur-3xl"></div>
                        <svg class="absolute inset-0 size-full" viewBox="0 0 760 380" fill="none" aria-hidden="true">
                            <path
                                d="M380 190 L110 80 M380 190 L640 90 M380 190 L90 260 M380 190 L660 270 M380 190 L250 330 M380 190 L510 40"
                                stroke="#8E9C75"
                                stroke-opacity=".35"
                                stroke-dasharray="3 6"
                            />
                            <circle cx="380" cy="190" r="124" stroke="#8E9C75" stroke-opacity=".25" />
                            <circle cx="380" cy="190" r="180" stroke="#8E9C75" stroke-opacity=".18" />
                        </svg>
                        <div class="relative flex aspect-[1.35] items-center justify-center">
                            <div class="relative z-10 grid size-[110px] place-items-center rounded-full bg-[#3B4B34] text-white shadow-xl">
                                <div class="text-center"><Leaf class="mx-auto size-6" /><span class="text-[9px] font-semibold">ECOLOOP</span></div>
                            </div>
                            <div
                                v-for="(node, index) in nodes"
                                :key="node.label"
                                class="orbit-node absolute z-10 flex flex-col items-center gap-1.5"
                                :class="node.pos"
                                :style="{ animationDelay: index * -0.65 + 's' }"
                            >
                                <div
                                    class="grid size-11 place-items-center rounded-2xl border border-white/70 bg-[#F8F6F0]/90 text-[#3B4B34] shadow-lg"
                                >
                                    <component :is="node.icon" class="size-[17px]" />
                                </div>
                                <span class="rounded-full bg-[#F2EEE5]/80 px-2 py-1 text-[8px]">{{ node.label }}</span>
                            </div>
                        </div>
                        <div class="relative mx-auto grid max-w-[450px] gap-2 sm:grid-cols-3">
                            <div class="rounded-2xl border border-white/65 bg-white/35 p-3">
                                <p class="text-[9px] font-semibold">01 · Monitor</p>
                                <p class="text-[8px] text-[#817e73]">Inventory and shelf life</p>
                            </div>
                            <div class="rounded-2xl border border-white/65 bg-white/35 p-3">
                                <p class="text-[9px] font-semibold">02 · Decide</p>
                                <p class="text-[8px] text-[#817e73]">Discount or donation</p>
                            </div>
                            <div class="rounded-2xl border border-white/65 bg-white/35 p-3">
                                <p class="text-[9px] font-semibold">03 · Route</p>
                                <p class="text-[8px] text-[#817e73]">Partner follow-up</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="integrasi" class="relative px-5 py-24 sm:px-8 lg:px-12 lg:py-32">
                <div class="mx-auto grid max-w-[1180px] items-center gap-12 lg:grid-cols-2">
                    <div class="reveal">
                        <p class="mb-4 text-[10px] font-semibold uppercase tracking-[.18em] text-[#71805f]">Works with your ecosystem</p>
                        <h2 class="w-anim font-serif text-[clamp(2.8rem,6vw,5rem)] leading-[.94]">
                            One cycle.<br /><span class="italic text-[#3B4B34]">Many connections.</span>
                        </h2>
                        <p class="mt-6 max-w-[420px] text-[12px] leading-6 text-[#77746a]">
                            Connect inventory sources, smart monitoring, and donation partners so each decision starts with the operational context
                            it needs.
                        </p>
                        <div class="mt-7 flex flex-wrap gap-2">
                            <span class="rounded-full border border-[#d9d6ca] bg-white/35 px-3 py-1.5 text-[9px]">POS sync</span
                            ><span class="rounded-full border border-[#d9d6ca] bg-white/35 px-3 py-1.5 text-[9px]">Partner notifications</span
                            ><span class="rounded-full border border-[#d9d6ca] bg-white/35 px-3 py-1.5 text-[9px]">AI & computer vision</span>
                        </div>
                    </div>
                    <div class="reveal rounded-[30px] border border-white/75 bg-white/30 p-5 shadow-xl backdrop-blur-2xl sm:p-8">
                        <p class="text-[9px] font-semibold uppercase tracking-[.16em] text-[#8b887d]">EcoLoop network</p>
                        <p class="mt-1 font-serif text-[28px]">Connected workflows</p>
                        <div class="mt-6 flex flex-col gap-2.5">
                            <div
                                v-for="(item, index) in [
                                    { title: 'Store dashboard', desc: 'Products · batches · shelf life', icon: Store },
                                    { title: 'EcoLoop intelligence', desc: 'Forecasts · recommendations · audit', icon: Bot },
                                    { title: 'Donation partners', desc: 'Discounts · donations · notifications', icon: Handshake },
                                ]"
                                :key="item.title"
                                class="flex items-center gap-3 rounded-2xl border border-[#e4e0d6] bg-white/70 p-3.5"
                            >
                                <div class="grid size-10 place-items-center rounded-xl bg-[#e8ecdf] text-[#3B4B34]">
                                    <component :is="item.icon" class="size-[17px]" />
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold">{{ item.title }}</p>
                                    <p class="mt-1 text-[8px] text-[#908c81]">{{ item.desc }}</p>
                                </div>
                                <Check v-if="index === 2" class="ml-auto size-4 text-[#71805f]" />
                            </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between rounded-2xl bg-[#3B4B34] px-4 py-3.5 text-white">
                            <span class="text-[9px]">Data flows in one cycle</span><ArrowRight class="size-4" />
                        </div>
                    </div>
                </div>
            </section>

            <section id="pricing" class="px-5 py-12 sm:px-8 lg:px-12 lg:py-20">
                <div
                    class="pilot grad-border relative mx-auto max-w-[1320px] overflow-hidden rounded-[32px] bg-[#3B4B34] px-6 py-12 text-[#F2EEE5] sm:px-12 lg:px-16 lg:py-16"
                >
                    <div class="pilot-grain absolute inset-0 opacity-25"></div>
                    <div class="absolute -right-20 -top-36 size-[450px] rounded-full border border-white/10"></div>
                    <div class="relative grid items-end gap-10 lg:grid-cols-[1fr_auto]">
                        <div class="reveal max-w-[650px]">
                            <p
                                class="mb-4 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/[.07] px-3 py-1.5 text-[9px] uppercase tracking-[.14em] text-white/75"
                            >
                                <Sparkles class="size-3" /> EcoLoop pilot program
                            </p>
                            <h2 class="font-serif text-[clamp(2.8rem,6vw,5.5rem)] leading-[.92]">
                                Start with one<br /><span class="italic text-[#c9d2b6]">meaningful step.</span>
                            </h2>
                            <p class="mt-5 max-w-[470px] text-[11px] leading-6 text-white/65">
                                Shape the EcoLoop workflow around how your store operates. We tailor the implementation plan to your
                                operational needs.
                            </p>
                        </div>
                        <div class="reveal w-full max-w-[290px] rounded-[22px] border border-white/15 bg-white/[.08] p-5 backdrop-blur-xl">
                            <div class="flex justify-between text-[9px] uppercase tracking-[.14em] text-white/60">
                                <span>A phased rollout</span><Leaf class="size-4" />
                            </div>
                            <p class="mt-3 font-serif text-[28px]">Designed together.</p>
                            <p class="mt-2 text-[9px] leading-4 text-white/55">Scope, integrations, and success metrics are agreed together.</p>
                            <Link
                                :href="primaryHref"
                                class="mt-5 flex justify-between rounded-full bg-[#F2EEE5] px-4 py-3 text-[10px] font-semibold text-[#171512]"
                                >{{ primaryLabel }} <ArrowUpRight class="size-4"
                            /></Link>
                            <p class="mt-3 text-[8px] text-white/40">Pilot pricing is discussed together and tailored to your needs</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="faq" class="px-5 py-20 sm:px-8 lg:px-12 lg:py-28">
                <div class="mx-auto grid max-w-[1120px] gap-12 lg:grid-cols-[.72fr_1.28fr]">
                    <div class="reveal">
                        <p class="mb-4 text-[10px] uppercase tracking-[.18em] text-[#71805f]">Frequently asked questions</p>
                        <h2 class="w-anim font-serif text-[clamp(2.8rem,5vw,4.6rem)] leading-[.95]">
                            Here to<br /><span class="italic text-[#3B4B34]">help.</span>
                        </h2>
                        <p class="mt-5 max-w-[310px] text-[11px] leading-6 text-[#77746a]">Our team can help map a workflow that fits your needs.</p>
                        <a href="mailto:hello@ecoloop.id" class="mt-5 inline-flex items-center gap-2 text-[10px] font-semibold text-[#3B4B34]"
                            >Contact the EcoLoop team <ArrowUpRight class="size-3.5"
                        /></a>
                    </div>
                    <div class="reveal divide-y divide-[#dcd7cb] border-y border-[#dcd7cb]">
                        <article v-for="(faq, index) in faqs" :key="faq.question" class="py-5">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-5 text-left"
                                :aria-expanded="openFaq === index"
                                :aria-controls="'faq-panel-' + index"
                                @click="openFaq = openFaq === index ? -1 : index"
                            >
                                <span class="font-serif text-[20px] sm:text-[23px]">{{ faq.question }}</span
                                ><span
                                    class="grid size-8 place-items-center rounded-full border border-[#d9d5c9] text-[#3B4B34] transition-transform"
                                    :class="{ 'rotate-180 bg-[#3B4B34] text-white': openFaq === index }"
                                    ><ChevronDown class="size-4"
                                /></span>
                            </button>
                            <div :id="'faq-panel-' + index" class="faq-body" :style="{ maxHeight: openFaq === index ? '220px' : '0px' }">
                                <p class="max-w-[590px] pr-10 pt-3 text-[11px] leading-6 text-[#77746a]">{{ faq.answer }}</p>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </main>
        <footer class="footer-gradient px-5 pb-8 pt-12 text-[#F2EEE5] sm:px-8 lg:px-12 lg:pt-16">
            <div class="mx-auto max-w-[1320px]">
                <div class="grid gap-10 border-b border-white/15 pb-10 md:grid-cols-[1fr_auto] md:items-end">
                    <div>
                        <a href="#home" class="inline-flex items-center rounded-xl bg-[#F2EEE5] px-3 py-2" aria-label="EcoLoop — back to top"><img src="/images/ecoloop-logo-light.png" alt="EcoLoop" class="h-8 w-auto max-w-[140px]" /></a>
                        <p class="mt-4 max-w-sm text-[10px] leading-5 text-white/55">A circular system that keeps food valuable.</p>
                    </div>
                </div>
                <div class="flex justify-between pt-5 text-[8px] text-white/35">
                    <span>© {{ new Date().getFullYear() }} EcoLoop</span><span>Designed to preserve value at every step.</span>
                </div>
            </div>
        </footer>
    </div>
</template>
<style scoped>
.ecoloop {
    --serif: 'Cormorant Garamond', Georgia, serif;
    font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
    scroll-behavior: smooth;
}
.font-serif {
    font-family: var(--serif);
}
.hero-photo {
    background-image: url('https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=2400&q=90');
    background-position: center 48%;
    background-size: cover;
    will-change: transform;
}
.hero-shade {
    background:
        linear-gradient(90deg, rgba(16, 20, 15, 0.82), rgba(20, 24, 18, 0.67) 42%, rgba(20, 24, 18, 0.35)),
        linear-gradient(0deg, rgba(19, 22, 17, 0.52), transparent 48%, rgba(20, 23, 18, 0.18));
}
.hero-glow {
    background: radial-gradient(circle, rgba(142, 156, 117, 0.42), transparent 70%);
    filter: blur(32px);
}
.proof-glow {
    filter: blur(50px);
}
.proof-left {
    background: radial-gradient(circle, rgba(142, 156, 117, 0.5), transparent 70%);
}
.proof-right {
    background: radial-gradient(circle, rgba(185, 106, 74, 0.32), transparent 70%);
}
.grad-border {
    position: relative;
    isolation: isolate;
}
.grad-border::before {
    position: absolute;
    z-index: -1;
    padding: 1px;
    border-radius: inherit;
    background: linear-gradient(135deg, rgba(142, 156, 117, 0.45), rgba(255, 255, 255, 0.76) 42%, rgba(185, 106, 74, 0.24));
    content: '';
    inset: 0;
    -webkit-mask:
        linear-gradient(#fff 0 0) content-box,
        linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}
.btn-lift {
    transition:
        transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1),
        box-shadow 220ms ease;
}
.btn-lift:hover {
    transform: scale(1.03) translateY(-2px);
    box-shadow: 0 12px 28px rgba(23, 21, 18, 0.16);
}
.reveal {
    opacity: 0;
    transform: translateY(22px);
    transition:
        opacity 700ms cubic-bezier(0.2, 0.7, 0.2, 1),
        transform 800ms cubic-bezier(0.16, 1, 0.3, 1);
}
.reveal.is-revealed {
    opacity: 1;
    transform: translateY(0);
}
.word-reveal {
    display: inline-block;
    animation: word-rise 850ms cubic-bezier(0.16, 1, 0.3, 1) both;
}
.feature-carousel {
    padding-left: max(2rem, calc((100vw - 1200px) / 2 + 2rem));
    padding-right: max(2rem, calc((100vw - 1200px) / 2 + 2rem));
    margin-left: calc(-1 * max(1.25rem, (100vw - 1320px)/2));
    margin-right: calc(-1 * max(1.25rem, (100vw - 1320px)/2));
    scroll-padding-left: max(2rem, calc((100vw - 1200px) / 2 + 2rem));
    -ms-overflow-style: none;
}
.feature-carousel::-webkit-scrollbar {
    display: none;
}
.orbit-node {
    animation: float-y 4.8s ease-in-out infinite;
}
.node-top {
    left: 50%;
    top: 1%;
    transform: translateX(-50%);
}
.node-right-top {
    right: 7%;
    top: 21%;
}
.node-right-bottom {
    right: 8%;
    bottom: 13%;
}
.node-bottom {
    left: 50%;
    bottom: 0;
    transform: translateX(-50%);
}
.node-left-bottom {
    left: 7%;
    bottom: 13%;
}
.node-left-top {
    left: 7%;
    top: 21%;
}
.faq-body {
    overflow: hidden;
    transition: max-height 420ms cubic-bezier(0.2, 0.8, 0.2, 1);
}
.footer-gradient {
    background: linear-gradient(180deg, #f2eee5 0%, #3b4b34 31%, #273225 100%);
}
.pilot-grain {
    background-image: radial-gradient(rgba(242, 238, 229, 0.17) 0.6px, transparent 0.6px);
    background-size: 7px 7px;
}
@keyframes word-rise {
    from {
        opacity: 0;
        transform: translateY(0.38em) rotate(1deg);
    }
    to {
        opacity: 1;
        transform: translateY(0) rotate(0);
    }
}
@keyframes float-y {
    0%,
    100% {
        translate: 0 0;
    }
    50% {
        translate: 0 -8px;
    }
}
@media (max-width: 640px) {
    .hero-section {
        min-height: auto;
    }
    .hero-shade {
        background:
            linear-gradient(90deg, rgba(16, 20, 15, 0.8), rgba(20, 24, 18, 0.45)), linear-gradient(0deg, rgba(19, 22, 17, 0.48), transparent 52%);
    }
    .feature-carousel {
        padding-left: 1.25rem;
        padding-right: 1.25rem;
        margin-left: -1.25rem;
        margin-right: -1.25rem;
    }
    .node-right-top,
    .node-right-bottom {
        right: 0;
    }
    .node-left-top,
    .node-left-bottom {
        left: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .ecoloop *,
    .ecoloop *::before,
    .ecoloop *::after {
        scroll-behavior: auto !important;
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
    .reveal {
        opacity: 1;
        transform: none;
    }
}
</style>
