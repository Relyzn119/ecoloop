<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { FlaskConical, Handshake, HeartHandshake, LayoutGrid, Package, Percent, ScrollText, Settings } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<{ auth: { user: { role: string } | null } }>();
const partnerRoles = ['donation_partner', 'umkm_partner'];
const userRole = page.props.auth.user?.role;
const mainNavItems: NavItem[] = [
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
        title: 'Donation Partners',
        href: '/donation-partners',
        icon: Handshake,
    },
    {
        title: 'Settings',
        href: '/settings',
        icon: Settings,
    },
]
    .filter((item) => !partnerRoles.includes(userRole ?? '') || item.title === 'Settings')
    .map((item) =>
        partnerRoles.includes(userRole ?? '') && item.title === 'Settings'
            ? { ...item, title: userRole === 'umkm_partner' ? 'Penawaran Diskon' : 'Portal Mitra', href: userRole === 'umkm_partner' ? '/umkm/offers' : '/partner/scan', icon: userRole === 'umkm_partner' ? Percent : FlaskConical }
            : item,
    );

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
].filter(() => !partnerRoles.includes(userRole ?? ''));
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
