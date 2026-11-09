import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, GraduationCap, LayoutGrid, MessageCircle, Package, UserCog, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'Jadwal', url: '/schedule', icon: CalendarDays },
    { title: 'Client', url: '/clients', icon: Users },
    { title: 'Tutor', url: '/tutors', icon: GraduationCap, roles: ['admin', 'director'] },
    { title: 'Paket', url: '/packages', icon: Package, roles: ['admin', 'director'] },
    { title: 'Inbox WhatsApp', url: '/inbox', icon: MessageCircle, roles: ['admin', 'director'] },
    { title: 'Jam tersedia', url: '/my-availability', icon: Clock, roles: ['tutor'] },
    { title: 'Pengguna', url: '/users', icon: UserCog, roles: ['director'] },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const items = mainNavItems.filter((item) => !item.roles || item.roles.includes(auth.user.role));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
