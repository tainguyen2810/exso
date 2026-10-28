import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
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

export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    const activeParent = groups
        .flatMap((group) => group.items)
        .find((item) =>
            item.children?.some((child) => isCurrentOrParentUrl(child.href)),
        )?.title;

    // Only the most specific matching child is active ("/products/barcodes"
    // must not also light up "/products").
    const activeChild = groups
        .flatMap((group) => group.items)
        .flatMap((item) => item.children ?? [])
        .filter((child) => isCurrentOrParentUrl(child.href))
        .sort((a, b) => toUrl(b.href).length - toUrl(a.href).length)[0];

    // Accordion: opening one menu group closes the others.
    const [open, setOpen] = useState<string | undefined>(activeParent);

    const renderItem = (item: NavItem) => {
        if (!item.children?.length) {
            return (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton
                        asChild
                        isActive={isCurrentOrParentUrl(item.href)}
                        tooltip={{ children: item.title }}
                    >
                        <Link href={item.href} prefetch>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            );
        }

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
                        <SidebarMenuButton tooltip={{ children: item.title }}>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                            <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                        </SidebarMenuButton>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <SidebarMenuSub>
                            {item.children.map((child) => (
                                <SidebarMenuSubItem key={child.title}>
                                    <SidebarMenuSubButton
                                        asChild
                                        isActive={child === activeChild}
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
                <SidebarGroup key={group.title ?? index} className="px-2 py-1">
                    {group.title && (
                        <SidebarGroupLabel className="text-[11px] tracking-wider text-sidebar-foreground/60 uppercase">
                            {group.title}
                        </SidebarGroupLabel>
                    )}
                    <SidebarMenu>{group.items.map(renderItem)}</SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
