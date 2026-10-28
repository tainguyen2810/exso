import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain, navItemClass } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { configItem, filterMenu, menu } from '@/config/menu';
import { useCan } from '@/hooks/use-can';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';

/** Dark navy sidebar like Sapo; Cấu hình is pinned at the bottom. */
export function AppSidebar() {
    const can = useCan();
    const { currentUrl } = useCurrentUrl();
    const groups = filterMenu(menu, can);
    const showConfig = can(configItem.permission);
    // Settings pages live under Cấu hình, like in Sapo.
    const configActive = [
        '/config',
        '/staff',
        '/roles',
        '/branches',
        '/activity-log',
    ].some((href) => currentUrl === href || currentUrl.startsWith(`${href}/`));

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="h-[52px] justify-center border-b border-sidebar-border p-0 px-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="h-10 hover:bg-transparent"
                        >
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0">
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarFooter className="gap-0 border-t border-sidebar-border p-0">
                <SidebarMenu className="gap-0">
                    {showConfig && (
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                isActive={configActive}
                                tooltip={{ children: configItem.title }}
                                className={navItemClass}
                            >
                                <Link href={configItem.href} prefetch>
                                    {configItem.icon && <configItem.icon />}
                                    <span>{configItem.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    )}
                </SidebarMenu>
                <div className="hidden justify-end px-2 pb-2 group-data-[collapsible=icon]:justify-center md:flex">
                    <SidebarTrigger className="text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-white" />
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
