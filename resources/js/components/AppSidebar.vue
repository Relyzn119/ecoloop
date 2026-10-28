<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BadgePercent, CalendarDays, FlaskConical, Handshake, HeartHandshake, History, LayoutGrid, Package, Percent, ScrollText, Settings, Store } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<{ auth: { user: { role: string } | null } }>();
const partnerRoles = ['donation_partner', 'umkm_partner'];
const userRole = page.props.auth.user?.role;
const supermarketNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Inventory',
        href: '/inventory',
        icon: Package,
    },
    {
        title: 'Riwayat Sinkronisasi',
        href: '/inventory/sync-history',
        icon: History,
    },
    {
        title: 'AI Simulation',
        href: '/simulation',
        icon: FlaskConical,
    },
    {
        title: 'Agent Logs',
        href: '/agent-logs',
        icon: ScrollText,
    },
    {
        title: 'Settings',
        href: '/settings',
        icon: Settings,
    },
];
const mainNavItems: NavItem[] = userRole === 'ecoloop_admin'
    ? [{ title: 'Kelola Mitra', href: '/admin/partners', icon: Handshake }]
    : userRole === 'donation_partner'
        ? [
            { title: 'Scan Produk', href: '/partner/scan', icon: FlaskConical },
            { title: 'Donasi Hari Ini', href: '/partner/donations/today', icon: HeartHandshake },
            { title: 'Daftar Supermarket/Toko', href: '/partner/stores', icon: Store },
            { title: 'Riwayat Donasi', href: '/partner/donations/history', icon: History },
        ]
        : userRole === 'umkm_partner'
          ? [
                { title: 'Diskon Tersedia', href: '/umkm/offers', icon: BadgePercent },
                { title: 'Daftar Supermarket/Toko', href: '/umkm/stores', icon: Store },
                { title: 'Riwayat Diskon', href: '/umkm/discount-history', icon: History },
            ]
        : supermarketNavItems;

const footerNavItems: NavItem[] = [
    {
        title: 'Rekap Donasi (PDF)',
        href: '/reports?type=donation',
        icon: HeartHandshake,
    },
    {
        title: 'Rekap Diskon (PDF)',
        href: '/reports?type=discount',
        icon: Percent,
    },
].filter(() => !partnerRoles.includes(userRole ?? '') && userRole !== 'ecoloop_admin');
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('home')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
