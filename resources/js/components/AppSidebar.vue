<script setup lang="ts">

import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type User } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { BedDouble, BriefcaseBusiness, Building2, ChartNoAxesColumn, Inbox, LayoutGrid, ListChecks, Mail, Package } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    { title: 'Моя работа', href: '/work', icon: BriefcaseBusiness },
    { title: 'Заявки на размещение', href: '/group-requests', icon: Inbox },
    { title: 'Размещение', href: '/hospitality', icon: BedDouble },
    { title: 'Входящие', href: '/inbox', icon: Mail },
    { title: 'Клиенты', href: '/clients', icon: Building2 },
    { title: 'Каталог услуг', href: '/catalog', icon: Package },
    {
        title: 'Воронка продаж',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    { title: 'Задачи', href: '/tasks', icon: ListChecks },
    { title: 'Отчёты по продажам', href: '/reports', icon: ChartNoAxesColumn },
];
const page = usePage<{auth:{user:User}}>();
const visibleNavItems = computed(() => page.props.auth.user?.sales_role === 'lodging' ? mainNavItems.filter((item) => item.href === '/hospitality') : mainNavItems);


</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="visibleNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
