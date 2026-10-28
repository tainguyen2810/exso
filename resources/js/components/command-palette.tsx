import { router } from '@inertiajs/react';
import { Package, Search } from 'lucide-react';
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
import { configEntries, filterMenu, menu } from '@/config/menu';
import { useCan } from '@/hooks/use-can';
import { toUrl } from '@/lib/utils';

type VariantHit = {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    barcode: string | null;
};

/**
 * Ctrl+K (⌘K on Mac): jump to any page the user can open.
 * Also finds products by name, SKU or barcode; orders and customers come
 * with their modules.
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const can = useCan();
    const groups = filterMenu(menu, can);
    const settings = configEntries.filter(
        (entry) => !entry.phase && can(entry.permission),
    );

    // Products by name / SKU / barcode, for users who can see products.
    const [term, setTerm] = useState('');
    const [variants, setVariants] = useState<VariantHit[]>([]);
    const canSearchProducts = can('products.view');

    useEffect(() => {
        if (!canSearchProducts || term.trim().length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(
                `/products/variants/search?q=${encodeURIComponent(term.trim())}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => response.json())
                .then((data: VariantHit[]) => setVariants(data.slice(0, 8)))
                .catch(() => undefined);
        }, 150);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, canSearchProducts]);

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
                className="hidden w-64 justify-start gap-2 bg-muted font-normal text-muted-foreground shadow-none lg:flex"
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
                className="lg:hidden"
                onClick={() => setOpen(true)}
                aria-label="Tìm nhanh"
            >
                <Search className="size-5" />
            </Button>

            <CommandDialog open={open} onOpenChange={setOpen}>
                <CommandInput
                    placeholder={
                        canSearchProducts
                            ? 'Gõ tên chức năng, sản phẩm, mã SKU...'
                            : 'Gõ tên chức năng...'
                    }
                    value={term}
                    onValueChange={setTerm}
                />
                <CommandList>
                    <CommandEmpty>Không tìm thấy.</CommandEmpty>
                    {groups.map((group, index) => (
                        <CommandGroup
                            key={group.title ?? index}
                            heading={group.title ?? 'Chức năng'}
                        >
                            {group.items.flatMap((item) =>
                                (item.children ?? [item]).map((entry) => (
                                    <CommandItem
                                        key={toUrl(entry.href)}
                                        value={`${item.title} ${entry.title}`}
                                        onSelect={() => go(toUrl(entry.href))}
                                    >
                                        {item.icon && <item.icon />}
                                        {entry.title}
                                    </CommandItem>
                                )),
                            )}
                        </CommandGroup>
                    ))}
                    {term.trim().length >= 2 && variants.length > 0 && (
                        <CommandGroup heading="Sản phẩm">
                            {variants.map((variant) => (
                                <CommandItem
                                    key={variant.id}
                                    value={`${variant.name} ${variant.sku} ${variant.barcode ?? ''} ${term}`}
                                    onSelect={() =>
                                        go(
                                            `/products/${variant.product_id}/edit`,
                                        )
                                    }
                                >
                                    <Package />
                                    <span className="flex-1 truncate">
                                        {variant.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {variant.sku}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}
                    {settings.length > 0 && (
                        <CommandGroup heading="Cấu hình">
                            {settings.map((entry) => (
                                <CommandItem
                                    key={entry.href}
                                    value={`Cấu hình ${entry.title}`}
                                    onSelect={() => go(entry.href)}
                                >
                                    <entry.icon />
                                    {entry.title}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}
                </CommandList>
            </CommandDialog>
        </>
    );
}
