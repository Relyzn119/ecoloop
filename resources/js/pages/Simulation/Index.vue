<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Bot,
    CalendarClock,
    Camera,
    CheckCircle2,
    HeartHandshake,
    ImagePlus,
    Package,
    Percent,
    RefreshCw,
    Tag,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

interface InventoryBatch {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    category: string;
    batch_code: string | null;
    quantity_received: number;
    quantity_available: number;
    quantity_donated: number;
    quantity_sold: number;
    unit: string;
    original_price: number;
    discount_price: number | null;
    status: string;
    source: string;
    store_code: string | null;
    source_synced_at: string | null;
    expiration_date: string;
    days_remaining: number;
}

interface DiscountApprovalItem {
    id: number;
    product_name: string;
    sku: string;
    batch_code: string | null;
    quantity: number;
    expiration_date: string;
    recommended_percentage: number;
    recommended_price: number;
    reasoning: string;
    created_at: string | null;
}

interface AgentLogItem {
    id: number;
    action_type: string;
    reasoning: string;
    status: string;
    created_at_human: string;
    product?: { id: number; name: string; sku: string } | null;
    partner?: { id: number | null; name: string } | null;
}

interface DonationPartnerOption {
    id: number;
    name: string;
    telegram_ready: boolean;
}

interface VisionResult {
    donation_eligible: boolean;
    confidence: number;
    condition: 'good' | 'acceptable' | 'poor' | 'unclear';
    reasoning: string;
    safety_note: string;
    provider: string;
    batch_id: number | null;
    product_name: string | null;
    batch_code: string | null;
    quantity_available: number | null;
    unit: string | null;
    review_token: string | null;
    donation_completed?: boolean;
    donation_message?: string | null;
}

const props = defineProps<{
    batches: InventoryBatch[];
    pendingApprovals: DiscountApprovalItem[];
    recentLogs: AgentLogItem[];
    lastUpdatedAt: string;
    donationPartners: DonationPartnerOption[];
    visionResult: VisionResult | null;
    portalMode?: 'staff' | 'partner';
}>();
const isPartnerPortal = computed(() => props.portalMode === 'partner');

const breadcrumbs = computed<BreadcrumbItem[]>(() =>
    isPartnerPortal.value
        ? [{ title: 'Portal Mitra', href: '/partner/scan' }]
        : [
              { title: 'Dashboard', href: '/dashboard' },
              { title: 'AI Simulation', href: '/simulation' },
          ],
);

const isRefreshing = ref(false);
const selectedPartnerId = ref<number | null>(props.donationPartners[0]?.id ?? null);
const selectedPhoto = ref<File | null>(null);
const photoPreviewUrl = ref<string | null>(null);
const visionResult = ref<VisionResult | null>(props.visionResult);
const donationNotice = ref('');
const photoInput = ref<HTMLInputElement | null>(null);
const cameraVideo = ref<HTMLVideoElement | null>(null);
const cameraActive = ref(false);
const cameraError = ref('');
let cameraStream: MediaStream | null = null;
let currentPreviewUrl: string | null = null;
const analysisForm = useForm<{ photo: File | null }>({ photo: null });
const donationForm = useForm<{ review_token: string; partner_id: number | null }>({ review_token: '', partner_id: selectedPartnerId.value });
let approvalRefreshTimer: ReturnType<typeof setInterval> | null = null;
const selectedDonationPartner = computed(() => props.donationPartners.find((partner) => partner.id === selectedPartnerId.value) ?? null);
const availableUnits = computed(() => props.batches.reduce((total, batch) => total + batch.quantity_available, 0));
const donatedUnits = computed(() => props.batches.reduce((total, batch) => total + batch.quantity_donated, 0));
const lastUpdatedLabel = computed(() =>
    new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(props.lastUpdatedAt)),
);

onBeforeUnmount(() => {
    if (currentPreviewUrl) URL.revokeObjectURL(currentPreviewUrl);
    if (approvalRefreshTimer) clearInterval(approvalRefreshTimer);
    stopCamera();
});

onMounted(() => {
    if (!isPartnerPortal.value) {
        approvalRefreshTimer = setInterval(() => {
            router.reload({ only: ['pendingApprovals'], preserveScroll: true });
        }, 5000);
    }
});

function refreshData(): void {
    router.reload({
        only: ['batches', 'donationPartners', 'pendingApprovals', 'recentLogs', 'lastUpdatedAt'],
        onStart: () => {
            isRefreshing.value = true;
        },
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
}

function expiryLabel(days: number): string {
    if (days < 0) return `Expired ${Math.abs(days)}d ago`;
    if (days === 0) return 'Expires today';
    return `${days}d remaining`;
}

function statusClass(status: string): string {
    if (status === 'donated') return 'bg-violet-500/10 text-violet-700 dark:text-violet-300';
    if (status === 'discounted') return 'bg-amber-500/10 text-amber-700 dark:text-amber-300';
    if (status === 'disposed') return 'bg-neutral-500/10 text-neutral-700 dark:text-neutral-300';

    return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
}

function selectPhoto(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    setSelectedPhoto(file);
}

function setSelectedPhoto(file: File | null): void {
    selectedPhoto.value = file;
    analysisForm.photo = file;
    visionResult.value = null;
    donationNotice.value = '';
    if (currentPreviewUrl) URL.revokeObjectURL(currentPreviewUrl);
    currentPreviewUrl = file ? URL.createObjectURL(file) : null;
    photoPreviewUrl.value = currentPreviewUrl;
}

async function startCamera(): Promise<void> {
    cameraError.value = '';
    if (!navigator.mediaDevices?.getUserMedia) {
        cameraError.value = 'Akses kamera langsung tidak tersedia di browser ini. Gunakan tombol kamera HP / pilih foto.';
        return;
    }

    stopCamera();
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) ? 'environment' : 'user' } },
            audio: false,
        });
        cameraActive.value = true;
        await nextTick();
        if (cameraVideo.value) cameraVideo.value.srcObject = cameraStream;
    } catch (error) {
        cameraError.value =
            error instanceof DOMException && error.name === 'NotAllowedError'
                ? 'Izin kamera ditolak. Izinkan akses kamera untuk situs ini di pengaturan browser.'
                : 'Kamera tidak dapat dibuka. Pastikan kamera tidak sedang dipakai aplikasi lain dan situs dibuka melalui HTTPS atau localhost.';
    }
}

function stopCamera(): void {
    cameraStream?.getTracks().forEach((track) => track.stop());
    cameraStream = null;
    cameraActive.value = false;
}

function captureCameraPhoto(): void {
    const video = cameraVideo.value;
    if (!video || video.videoWidth === 0 || video.videoHeight === 0) return;
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);
    canvas.toBlob(
        (blob) => {
            if (blob) setSelectedPhoto(new File([blob], `ecoloop-camera-${Date.now()}.jpg`, { type: 'image/jpeg' }));
            stopCamera();
        },
        'image/jpeg',
        0.9,
    );
}

function analyzePhoto(): void {
    if (!selectedPhoto.value) return;
    visionResult.value = null;
    donationNotice.value = '';
    analysisForm.photo = selectedPhoto.value;
    analysisForm.post(route(isPartnerPortal.value ? 'partner.scan.analyze' : 'simulation.vision.analyze'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            const pageProps = page.props as unknown as { visionResult?: VisionResult | null };
            visionResult.value = pageProps.visionResult ?? null;
        },
    });
}

function donateReviewedPhoto(): void {
    if (!visionResult.value?.donation_eligible || !visionResult.value.review_token || (!isPartnerPortal.value && !selectedPartnerId.value)) return;
    donationNotice.value = '';
    donationForm.review_token = visionResult.value.review_token;
    donationForm.partner_id = isPartnerPortal.value ? null : selectedPartnerId.value;
    donationForm.post(route('simulation.vision.donate'), {
        preserveScroll: true,
        onSuccess: (page) => {
            const pageProps = page.props as unknown as { flash?: { success?: string; error?: string } };
            donationNotice.value = pageProps.flash?.success ?? pageProps.flash?.error ?? 'Permintaan donasi telah diproses.';
            if (pageProps.flash?.success) {
                visionResult.value = null;
                selectedPhoto.value = null;
                photoPreviewUrl.value = null;
                if (currentPreviewUrl) URL.revokeObjectURL(currentPreviewUrl);
                currentPreviewUrl = null;
                if (photoInput.value) photoInput.value.value = '';
                refreshData();
            }
        },
    });
}
</script>

<template>
    <Head :title="isPartnerPortal ? 'Scan Produk Donasi' : 'AI Simulation - Latest Inventory'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="space-y-6 p-4 sm:p-6 lg:p-8">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">
                        {{ isPartnerPortal ? 'Scan Produk Donasi' : 'AI Simulation' }}
                    </h1>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {{
                            isPartnerPortal
                                ? 'Pindai batch yang disiapkan supermarket. Donasi hanya dapat dilakukan jika AI menilai produk masih layak dikonsumsi.'
                                : 'Latest stock lots, autonomous agent activity, and discount recommendations awaiting approval.'
                        }}
                    </p>
                    <p class="mt-1 text-xs text-neutral-400">Updated {{ lastUpdatedLabel }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :disabled="isRefreshing"
                        aria-label="Refresh latest inventory data"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-60"
                        @click="refreshData"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': isRefreshing }" />
                        <span>{{ isRefreshing ? 'Refreshing…' : 'Refresh data' }}</span>
                    </button>
                    <Link
                        v-if="!isPartnerPortal"
                        href="/inventory"
                        class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border bg-card px-4 py-2.5 text-sm font-medium text-foreground shadow-sm transition hover:bg-accent"
                    >
                        <Package class="size-4" />
                        <span>Open inventory</span>
                    </Link>
                </div>
            </header>

            <section v-if="!isPartnerPortal" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Inventory summary">
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground">
                        <span>Tracked lots</span><Package class="size-4" />
                    </div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ batches.length }}</p>
                </article>
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground">
                        <span>Available stock</span><CalendarClock class="size-4" />
                    </div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ availableUnits }}</p>
                    <p class="text-xs text-muted-foreground">units across displayed lots</p>
                </article>
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground">
                        <span>Donated stock</span><HeartHandshake class="size-4" />
                    </div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ donatedUnits }}</p>
                    <p class="text-xs text-muted-foreground">units across displayed lots</p>
                </article>
                <article class="rounded-xl border border-sidebar-border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between text-sm text-muted-foreground">
                        <span>Discount approvals</span><Percent class="size-4" />
                    </div>
                    <p class="mt-2 text-3xl font-bold text-foreground">{{ pendingApprovals.length }}</p>
                    <p class="text-xs text-muted-foreground">waiting for a manager or cashier</p>
                </article>
            </section>

            <section class="rounded-xl border border-emerald-200 bg-card shadow-sm dark:border-emerald-900">
                <div class="flex items-center gap-3 border-b border-sidebar-border px-5 py-4">
                    <div class="rounded-lg bg-emerald-500/10 p-2 text-emerald-700 dark:text-emerald-300"><Camera class="size-5" /></div>
                    <div>
                        <h2 class="font-semibold text-foreground">
                            {{ isPartnerPortal ? 'Pemeriksaan kelayakan konsumsi' : 'AI food condition check' }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Use a phone camera, webcam, or uploaded image to check sale and donation suitability.
                        </p>
                    </div>
                </div>
                <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.8fr)]">
                    <div class="space-y-4">
                        <p class="text-sm text-muted-foreground">
                            Ambil atau unggah foto produk. AI akan mencocokkannya dengan stok yang tersedia dan memilih batch yang paling dekat tanggal kedaluwarsanya.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <input
                                ref="photoInput"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                class="sr-only"
                                @change="selectPhoto"
                            />
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border px-4 py-2.5 text-sm font-medium text-foreground transition hover:bg-accent"
                                :disabled="analysisForm.processing || cameraActive"
                                @click="startCamera"
                            >
                                <Camera class="size-4" />Open camera
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border px-4 py-2.5 text-sm font-medium text-foreground transition hover:bg-accent"
                                :disabled="analysisForm.processing"
                                @click="photoInput?.click()"
                            >
                                <ImagePlus class="size-4" />{{ selectedPhoto ? 'Choose another photo' : 'Use phone camera / upload' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!selectedPhoto || analysisForm.processing"
                                @click="analyzePhoto"
                            >
                                <RefreshCw class="size-4" :class="{ 'animate-spin': analysisForm.processing }" />{{
                                    analysisForm.processing ? 'Checking photo…' : 'Analyze photo'
                                }}
                            </button>
                        </div>
                        <div v-if="cameraActive" class="space-y-2 rounded-lg border border-sidebar-border bg-black p-2">
                            <video ref="cameraVideo" autoplay playsinline muted class="max-h-80 w-full rounded object-contain"></video>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white"
                                    @click="captureCameraPhoto"
                                >
                                    Take photo
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-white/30 px-4 py-2 text-sm font-medium text-white"
                                    @click="stopCamera"
                                >
                                    Close camera
                                </button>
                            </div>
                        </div>
                        <p v-if="cameraError" role="status" class="text-xs text-amber-700">{{ cameraError }}</p>
                        <p v-if="analysisForm.errors.photo" class="text-xs text-red-600">{{ analysisForm.errors.photo }}</p>
                        <p class="text-xs leading-relaxed text-muted-foreground">
                            This is a visual screening aid only. It cannot verify smell, storage temperature, contamination, or microbial safety.
                            Stock stays unchanged during analysis; unclear results require a manual check or a retake.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <div v-if="photoPreviewUrl" class="overflow-hidden rounded-lg border border-sidebar-border bg-muted/30">
                            <img :src="photoPreviewUrl" alt="Selected food photo preview" class="max-h-64 w-full object-contain" />
                        </div>
                        <div
                            v-else
                            class="flex min-h-32 items-center justify-center rounded-lg border border-dashed border-sidebar-border bg-muted/20 px-4 text-center text-sm text-muted-foreground"
                        >
                            A selected photo preview will appear here.
                        </div>

                        <article
                            v-if="visionResult"
                            class="space-y-3 rounded-lg border p-4"
                            :class="
                                visionResult.donation_eligible
                                    ? 'border-emerald-300 bg-emerald-500/5 dark:border-emerald-900'
                                    : visionResult.condition === 'good'
                                      ? 'border-blue-300 bg-blue-500/5 dark:border-blue-900'
                                      : visionResult.condition === 'poor'
                                        ? 'border-red-300 bg-red-500/5 dark:border-red-900'
                                        : 'border-amber-300 bg-amber-500/5 dark:border-amber-900'
                            "
                        >
                            <div class="flex items-start gap-2">
                                <CheckCircle2
                                    v-if="visionResult.donation_eligible || visionResult.condition === 'good'"
                                    class="mt-0.5 size-5 shrink-0 text-emerald-600"
                                />
                                <AlertTriangle v-else class="mt-0.5 size-5 shrink-0 text-amber-600" />
                                <div>
                                    <p class="font-semibold text-foreground">
                                        {{
                                            !visionResult.product_name
                                                ? 'Produk tidak cocok dengan stok yang tersedia — tidak ada stok berubah'
                                                : visionResult.donation_eligible && isPartnerPortal
                                                  ? visionResult.donation_completed
                                                      ? 'Layak konsumsi — donasi langsung berhasil'
                                                      : 'Layak konsumsi — donasi belum berhasil diproses'
                                                  : visionResult.donation_eligible
                                                ? 'Not suitable for sale; potentially suitable for donation'
                                                : visionResult.condition === 'good'
                                                  ? isPartnerPortal
                                                      ? 'Masih layak konsumsi — donasi dapat dilanjutkan'
                                                      : 'Still suitable for sale — donation is disabled'
                                                  : visionResult.condition === 'poor'
                                                    ? 'Possible spoilage or unsafe condition — do not donate'
                                                    : 'Unclear — inspect manually or take another photo'
                                        }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        <template v-if="visionResult.product_name">
                                            {{ visionResult.product_name }} · {{ visionResult.batch_code || 'Batch' }} ·
                                        </template>
                                        {{ Math.round(visionResult.confidence * 100) }}% visual confidence
                                    </p>
                                </div>
                            </div>
                            <p class="text-sm text-foreground">{{ visionResult.reasoning }}</p>
                            <p class="text-xs leading-relaxed text-muted-foreground">{{ visionResult.safety_note }}</p>
                            <div
                                v-if="visionResult.donation_eligible && visionResult.review_token && !isPartnerPortal"
                                class="space-y-3 border-t border-sidebar-border/70 pt-3"
                            >
                                <label v-if="!isPartnerPortal" class="block space-y-1.5 text-sm font-medium text-foreground"
                                    ><span>Donation partner</span>
                                    <select
                                        v-model.number="selectedPartnerId"
                                        class="w-full rounded-lg border-input bg-background text-sm"
                                        :disabled="donationForm.processing || donationPartners.length === 0"
                                    >
                                        <option :value="null" disabled>Select active partner</option>
                                        <option v-for="partner in donationPartners" :key="partner.id" :value="partner.id">
                                            {{ partner.name }}{{ partner.telegram_ready ? '' : ' · Telegram not set up' }}
                                        </option>
                                    </select>
                                </label>
                                <p
                                    v-if="!isPartnerPortal && selectedDonationPartner && !selectedDonationPartner.telegram_ready"
                                    class="text-xs text-amber-700"
                                >
                                    Set the partner Telegram chat ID in Donation Partners to enable donation and ensure both email and Telegram alerts
                                    can be queued.
                                </p>
                                <button
                                    type="button"
                                    class="w-full rounded-lg bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!selectedDonationPartner?.telegram_ready || donationForm.processing"
                                    @click="donateReviewedPhoto"
                                >
                                    {{
                                        donationForm.processing
                                            ? 'Processing donation…'
                                            : `Donate ${visionResult.quantity_available ?? 0} ${visionResult.unit ?? ''}`
                                    }}
                                </button>
                                <p v-if="!isPartnerPortal && donationPartners.length === 0" class="text-xs text-amber-700">
                                    Add an active donation partner before donating this batch.
                                </p>
                            </div>
                        </article>
                        <p v-if="isPartnerPortal && visionResult?.donation_message" role="status" class="rounded-lg bg-muted/50 p-3 text-sm text-foreground">
                            {{ visionResult.donation_message }}
                        </p>
                        <p v-if="donationNotice" role="status" class="rounded-lg bg-muted/50 p-3 text-sm text-foreground">{{ donationNotice }}</p>
                    </div>
                </div>
            </section>

            <section v-if="!isPartnerPortal" class="overflow-hidden rounded-xl border border-sidebar-border bg-card shadow-sm">
                <div class="flex items-center justify-between border-b border-sidebar-border px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-foreground">Inventory lots</h2>
                        <p class="text-xs text-muted-foreground">One row per receiving batch, including remaining and diverted quantities.</p>
                    </div>
                    <Link
                        href="/inventory"
                        class="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                    >
                        Manage inventory <ArrowRight class="size-4" />
                    </Link>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-left text-sm">
                        <thead class="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3">Product</th>
                                <th class="px-4 py-3">Batch</th>
                                <th class="px-4 py-3">Received</th>
                                <th class="px-4 py-3">Available</th>
                                <th class="px-4 py-3">Donated / sold</th>
                                <th class="px-4 py-3">Expiration</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/60">
                            <tr v-for="batch in batches" :key="batch.id" class="hover:bg-muted/20">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-foreground">{{ batch.name }}</p>
                                    <p class="font-mono text-xs text-muted-foreground">{{ batch.sku }} · {{ batch.category }}</p>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-foreground">
                                    {{ batch.batch_code || '—' }}
                                    <p class="font-sans text-[11px] text-muted-foreground">
                                        {{ batch.source === 'central_sync' ? 'Supermarket POS · ' + batch.store_code : batch.source }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-foreground">{{ batch.quantity_received }} {{ batch.unit }}</td>
                                <td class="px-4 py-3 font-semibold text-foreground">{{ batch.quantity_available }} {{ batch.unit }}</td>
                                <td class="px-4 py-3 text-foreground">{{ batch.quantity_donated }} / {{ batch.quantity_sold }}</td>
                                <td class="px-4 py-3">
                                    <p class="text-foreground">{{ batch.expiration_date }}</p>
                                    <p class="text-xs text-muted-foreground">{{ expiryLabel(batch.days_remaining) }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold capitalize" :class="statusClass(batch.status)">{{
                                        batch.status.replace('_', ' ')
                                    }}</span>
                                </td>
                            </tr>
                            <tr v-if="batches.length === 0">
                                <td colspan="7" class="px-5 py-12 text-center text-sm text-muted-foreground">
                                    No inventory batches yet. Add a product in Inventory to begin tracking.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div v-if="!isPartnerPortal" class="grid gap-6 xl:grid-cols-2">
                <section class="rounded-xl border border-sidebar-border bg-card shadow-sm">
                    <div class="flex items-center gap-2 border-b border-sidebar-border px-5 py-4">
                        <Percent class="size-4 text-amber-600 dark:text-amber-400" />
                        <div>
                            <h2 class="font-semibold text-foreground">Discount recommendations</h2>
                            <p class="text-xs text-muted-foreground">Products expiring in 4–6 days are added automatically; prices stay unchanged until approval.</p>
                        </div>
                    </div>
                    <div v-if="pendingApprovals.length" class="divide-y divide-sidebar-border/60">
                        <article v-for="approval in pendingApprovals" :key="approval.id" class="space-y-2 px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium text-foreground">{{ approval.product_name }}</p>
                                    <p class="font-mono text-xs text-muted-foreground">{{ approval.sku }} · {{ approval.batch_code || 'Batch' }}</p>
                                </div>
                                <span class="rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                                    >Pending approval</span
                                >
                            </div>
                            <p class="text-sm text-foreground">{{ approval.quantity }} units · expires {{ approval.expiration_date }}</p>
                            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                Recommended discount: {{ approval.recommended_percentage }}% · {{ formatCurrency(approval.recommended_price) }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ approval.reasoning }}</p>
                        </article>
                    </div>
                    <p v-else class="px-5 py-10 text-center text-sm text-muted-foreground">No discount recommendations are waiting for approval.</p>
                </section>

                <section class="rounded-xl border border-sidebar-border bg-card shadow-sm">
                    <div class="flex items-center gap-2 border-b border-sidebar-border px-5 py-4">
                        <Bot class="size-4 text-emerald-700 dark:text-emerald-400" />
                        <div>
                            <h2 class="font-semibold text-foreground">Recent agent activity</h2>
                            <p class="text-xs text-muted-foreground">Latest recorded decisions and inventory actions.</p>
                        </div>
                    </div>
                    <div v-if="recentLogs.length" class="divide-y divide-sidebar-border/60">
                        <article v-for="log in recentLogs" :key="log.id" class="px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <Tag class="size-3.5 text-muted-foreground" /><span class="text-sm font-semibold capitalize text-foreground">{{
                                        log.action_type.replace('_', ' ')
                                    }}</span>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ log.created_at_human }}</span>
                            </div>
                            <p class="mt-1 text-sm text-foreground">
                                {{ log.product?.name || 'Inventory event' }}<span v-if="log.partner?.name"> · {{ log.partner.name }}</span>
                            </p>
                            <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ log.reasoning }}</p>
                        </article>
                    </div>
                    <div v-else class="flex flex-col items-center gap-2 px-5 py-10 text-center text-sm text-muted-foreground">
                        <Bot class="size-6" />
                        <p>No agent activity has been recorded.</p>
                    </div>
                    <div class="border-t border-sidebar-border px-5 py-3 text-right">
                        <Link href="/agent-logs" class="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                            >Open audit trail <ArrowRight class="inline size-4"
                        /></Link>
                    </div>
                </section>
            </div>
        </main>
    </AppLayout>
</template>
