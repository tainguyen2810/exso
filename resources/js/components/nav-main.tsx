import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import type { NavGroup, NavItem } from '@/types';

const itemClass =
    'h-12 gap-3 rounded-[3px] px-4 text-sm text-sidebar-foreground hover:bg-sidebar-accent hover:text-white data-[active=true]:bg-sidebar-primary data-[active=true]:font-normal data-[active=true]:text-white [&>svg]:size-5 [&>svg]:opacity-80';

/**
 * The link the current page belongs to: the longest menu href that equals
 * the path or is a parent of it, so "/orders/create" does not also light up
 * "/orders".
 */
export function activeHref(items: NavItem[], path: string): string | undefined {
    return items
        .flatMap((item) => [item, ...(item.children ?? [])])
        .map((item) => toUrl(item.href))
        .filter((href) => path === href || path.startsWith(`${href}/`))
        .sort((a, b) => b.length - a.length)[0];
}

export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { currentUrl } = useCurrentUrl();
    const items = groups.flatMap((group) => group.items);
    const active = activeHref(items, currentUrl);

    const activeParent = items.find((item) =>
        item.children?.some((child) => toUrl(child.href) === active),
    )?.title;

    // Accordion: opening one menu group closes the others.
    const [open, setOpen] = useState<string | undefined>(activeParent);

    const renderItem = (item: NavItem) => {
        if (!item.children?.length) {
            return (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton
                        asChild
                        isActive={toUrl(item.href) === active}
                        tooltip={{ children: item.title }}
                        className={itemClass}
                    >
                        <Link href={item.href} prefetch>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            );
        }

        const isParent = item.title === activeParent;

        return (
            <Collapsible
                key={item.title}
                asChild
                open={open === item.title}
                onOpenChange={(value) =>
                    setOpen(value ? item.title : undefined)
                }
                className="group/collapsible"
            >
                <SidebarMenuItem>
                    <CollapsibleTrigger asChild>
                        <SidebarMenuButton
                            tooltip={{ children: item.title }}
                            className={`${itemClass} ${isParent ? 'text-white [&>svg]:opacity-100' : ''}`}
                        >
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                            <ChevronDown className="ml-auto size-4! transition-transform duration-200 group-data-[state=open]/collapsible:rotate-180" />
                        </SidebarMenuButton>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <SidebarMenuSub className="mx-0 gap-0 border-0 px-0 py-0">
                            {item.children.map((child) => (
                                <SidebarMenuSubItem key={child.title}>
                                    <SidebarMenuSubButton
                                        asChild
                                        isActive={toUrl(child.href) === active}
                                        className="h-10 rounded-[3px] pl-12 text-sidebar-foreground/85 hover:bg-sidebar-accent hover:text-white data-[active=true]:bg-sidebar-primary data-[active=true]:text-white"
                                    >
                                        <Link href={child.href} prefetch>
                                            <span>{child.title}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            ))}
                        </SidebarMenuSub>
                    </CollapsibleContent>
                </SidebarMenuItem>
            </Collapsible>
        );
    };

    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup key={group.title ?? index} className="px-0 py-1">
                    {group.title && (
                        <SidebarGroupLabel className="h-10 px-4 text-xs font-medium tracking-wide text-sidebar-foreground/60 uppercase">
                            {group.title}
                        </SidebarGroupLabel>
                    )}
                    <SidebarMenu className="gap-0">
                        {group.items.map(renderItem)}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

export { itemClass as navItemClass };
