<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Partner {
    id: number;
    name: string;
    partner_type: 'foundation' | 'umkm' | 'business_owner';
    contact_person: string;
    email: string;
    phone: string;
    telegram_chat_id: string | null;
    is_active: boolean;
    created_at: string | null;
    user: { id: number; name: string; email: string; role: string } | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    partners: { data: Partner[]; links: PageLink[]; current_page: number; last_page: number; total: number };
    filters: { search: string; status: string; type: string };
    stats: { total: number; pending: number; active: number };
}>();

const breadcrumbs: BreadcrumbItemType[] = [{ title: 'Kelola Mitra', href: '/admin/partners' }];
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const type = ref(props.filters.type);
const page = usePage();
const successMessage = computed(() => (page.props.flash as { success?: string } | undefined)?.success);

const applyFilters = () => router.get('/admin/partners', {
    search: search.value || undefined,
    status: status.value || undefined,
    type: type.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

const approve = (partner: Partner) => router.post(`/admin/partners/${partner.id}/approve`, {}, { preserveScroll: true });
const toggleStatus = (partner: Partner) => {
    const action = partner.is_active ? 'menonaktifkan' : 'mengaktifkan kembali';
    if (window.confirm(`Yakin ${action} akun ${partner.name}?`)) {
        router.post(`/admin/partners/${partner.id}/toggle-status`, {}, { preserveScroll: true });
    }
};
const remove = (partner: Partner) => {
    if (window.confirm(`Hapus akun mitra ${partner.name} beserta profilnya? Tindakan ini tidak dapat dibatalkan.`)) {
        router.delete(`/admin/partners/${partner.id}`, { preserveScroll: true });
    }
};

const partnerTypeLabel = (partnerType: Partner['partner_type']) => ({
    foundation: 'Yayasan / Badan Amal',
    umkm: 'Mitra UMKM',
    business_owner: 'Pemilik Bisnis / Pemberi Donasi',
})[partnerType];
</script>

<template>
    <Head title="Kelola Mitra EcoLoop" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="mx-auto w-full max-w-7xl space-y-6 p-5 md:p-8">
            <header>
                <p class="text-sm font-semibold uppercase tracking-wider text-emerald-700">Admin EcoLoop</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-foreground">Kelola akun mitra</h1>
                <p class="mt-2 max-w-3xl text-sm text-muted-foreground">Tinjau pendaftaran Yayasan, UMKM, dan Pemilik Bisnis/Pemberi Donasi. Persetujuan di sini mengaktifkan akun mitra; dashboard supermarket tetap dikelola oleh akun manager/kasir.</p>
            </header>

            <div v-if="successMessage" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300" role="status">
                {{ successMessage }}
            </div>

            <section class="grid gap-3 sm:grid-cols-3" aria-label="Ringkasan akun mitra">
                <article class="rounded-xl border border-sidebar-border bg-card p-4"><p class="text-xs text-muted-foreground">Total mitra</p><p class="mt-1 text-2xl font-bold">{{ stats.total }}</p></article>
                <article class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4"><p class="text-xs text-muted-foreground">Menunggu persetujuan</p><p class="mt-1 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ stats.pending }}</p></article>
                <article class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4"><p class="text-xs text-muted-foreground">Aktif</p><p class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ stats.active }}</p></article>
            </section>

            <section class="space-y-4 rounded-xl border border-sidebar-border bg-card p-4 shadow-sm md:p-5">
                <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
                    <input v-model="search" type="search" placeholder="Cari nama, kontak, email, telepon" class="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <select v-model="status" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua status</option><option value="pending">Menunggu</option><option value="active">Aktif</option>
                    </select>
                    <select v-model="type" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua jenis mitra</option><option value="foundation">Yayasan / Badan Amal</option><option value="umkm">UMKM</option><option value="business_owner">Pemilik Bisnis / Pemberi Donasi</option>
                    </select>
                    <button type="submit" class="h-10 rounded-md bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800">Terapkan filter</button>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left text-sm">
                        <thead class="border-b border-sidebar-border text-xs uppercase text-muted-foreground">
                            <tr><th class="px-3 py-3">Organisasi / bisnis</th><th class="px-3 py-3">Jenis</th><th class="px-3 py-3">Kontak</th><th class="px-3 py-3">Chat ID</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Tindakan</th></tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr v-for="partner in partners.data" :key="partner.id">
                                <td class="px-3 py-4"><p class="font-semibold">{{ partner.name }}</p><p class="text-xs text-muted-foreground">{{ partner.user?.name || partner.contact_person }} · {{ partner.user?.email || partner.email }}</p></td>
                                <td class="px-3 py-4 text-xs">{{ partnerTypeLabel(partner.partner_type) }}</td>
                                <td class="px-3 py-4 text-xs"><p>{{ partner.email }}</p><p class="text-muted-foreground">{{ partner.phone }}</p></td>
                                <td class="px-3 py-4 font-mono text-xs">{{ partner.telegram_chat_id || '—' }}</td>
                                <td class="px-3 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="partner.is_active ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'">{{ partner.is_active ? 'Aktif' : 'Menunggu' }}</span></td>
                                <td class="px-3 py-4"><div class="flex justify-end gap-2">
                                    <button v-if="!partner.is_active" type="button" class="rounded-md bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800" @click="approve(partner)">Setujui</button>
                                    <button v-else type="button" class="rounded-md border border-sidebar-border px-3 py-2 text-xs font-medium hover:bg-muted" @click="toggleStatus(partner)">Nonaktifkan</button>
                                    <button type="button" class="rounded-md border border-red-500/30 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-500/10 dark:text-red-300" @click="remove(partner)">Hapus</button>
                                </div></td>
                            </tr>
                            <tr v-if="partners.data.length === 0"><td colspan="6" class="px-3 py-12 text-center text-sm text-muted-foreground">Belum ada mitra yang sesuai filter.</td></tr>
                        </tbody>
                    </table>
                </div>

                <nav v-if="partners.last_page > 1" class="flex flex-wrap justify-end gap-2" aria-label="Paginasi mitra">
                    <button v-for="(link, index) in partners.links" :key="index" type="button" :disabled="!link.url" class="rounded-md border border-sidebar-border px-3 py-2 text-xs disabled:opacity-40" :class="link.active ? 'bg-muted font-semibold' : ''" @click="link.url && router.visit(link.url, { preserveScroll: true })">{{ link.label.replace('&laquo; ', '').replace(' &raquo;', '') }}</button>
                </nav>
            </section>
        </main>
    </AppLayout>
</template>
