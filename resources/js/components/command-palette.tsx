import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { filterMenu, menu } from '@/config/menu';
import { useCan } from '@/hooks/use-can';
import { toUrl } from '@/lib/utils';

/**
 * Ctrl+K (⌘K on Mac): jump to any page the user can open.
 * Searching orders, customers and products is added with those modules.
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const can = useCan();
    const groups = filterMenu(menu, can);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const go = (href: string) => {
        setOpen(false);
        router.visit(href);
    };

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                className="hidden w-56 justify-start gap-2 text-muted-foreground sm:flex"
                onClick={() => setOpen(true)}
            >
                <Search className="size-4" />
                <span className="flex-1 text-left">Tìm nhanh...</span>
                <kbd className="rounded border bg-muted px-1.5 text-[10px] font-medium">
                    Ctrl K
                </kbd>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="sm:hidden"
                onClick={() => setOpen(true)}
                aria-label="Tìm nhanh"
            >
                <Search className="size-5" />
            </Button>

            <CommandDialog open={open} onOpenChange={setOpen}>
                <CommandInput placeholder="Gõ tên chức năng..." />
                <CommandList>
                    <CommandEmpty>Không tìm thấy.</CommandEmpty>
                    {groups.map((group, index) => (
                        <CommandGroup
                            key={group.title ?? index}
                            heading={group.title ?? 'Chức năng'}
                        >
                            {group.items.flatMap((item) =>
                                [item, ...(item.children ?? [])].map(
                                    (entry) => (
                                        <CommandItem
                                            key={entry.title}
                                            value={entry.title}
                                            onSelect={() =>
                                                go(toUrl(entry.href))
                                            }
                                        >
                                            {entry.icon && <entry.icon />}
                                            {entry.title}
                                        </CommandItem>
                                    ),
                                ),
                            )}
                        </CommandGroup>
                    ))}
                </CommandList>
            </CommandDialog>
        </>
    );
}
