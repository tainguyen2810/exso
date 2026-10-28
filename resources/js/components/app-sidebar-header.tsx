import { usePage } from '@inertiajs/react';
import { ChevronDown, CircleHelp } from 'lucide-react';
import { useTopbarSlots } from '@/components/app/topbar';
import { BranchSwitcher } from '@/components/branch-switcher';
import { CommandPalette } from '@/components/command-palette';
import { NotificationBell } from '@/components/notification-bell';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * White topbar like Sapo: page title (or a back link on form pages) on the
 * left, page buttons and the user's tools on the right.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const slots = useTopbarSlots();
    const { auth } = usePage().props;
    const title = breadcrumbs.at(-1)?.title;

    return (
        <header className="sticky top-0 z-20 flex h-[52px] shrink-0 items-center gap-3 bg-card px-4 shadow-[0_2px_4px_rgba(168,168,168,0.25)] md:px-8">
            <SidebarTrigger className="-ml-2 md:hidden" />
            <div className="flex min-w-0 flex-1 items-center">
                <div ref={slots?.setTitle} className="peer min-w-0" />
                <h1 className="hidden truncate text-xl font-medium text-foreground peer-empty:block">
                    {title}
                </h1>
            </div>
            <div className="flex shrink-0 items-center gap-1.5">
                <div
                    ref={slots?.setActions}
                    className="mr-2 flex items-center gap-2 empty:hidden"
                />
                <CommandPalette />
                <BranchSwitcher />
                <NotificationBell />
                <Button
                    variant="ghost"
                    size="icon"
                    asChild
                    className="hidden sm:inline-flex"
                >
                    <a
                        href="https://github.com/tainguyen2810/exso#readme"
                        target="_blank"
                        rel="noreferrer"
                        aria-label="Trợ giúp"
                        title="Trợ giúp"
                    >
                        <CircleHelp className="size-5" />
                    </a>
                </Button>
                {auth.user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-md px-1.5 py-1 hover:bg-muted"
                                data-test="sidebar-menu-button"
                            >
                                <UserInfo user={auth.user} />
                                <ChevronDown className="hidden size-4 text-muted-foreground sm:block" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="min-w-56" align="end">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}
