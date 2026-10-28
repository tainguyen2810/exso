import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type SupplierOption = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    address: string | null;
    debt: number;
};

/**
 * Search and pick a supplier (F4), then show who was picked with their
 * debt, like Sapo's purchase form.
 */
export function SupplierPicker({
    value,
    onChange,
    disabled = false,
    error,
}: {
    value: SupplierOption | null;
    onChange: (supplier: SupplierOption | null) => void;
    disabled?: boolean;
    error?: string;
}) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<SupplierOption[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F4') {
                event.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (value) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/suppliers/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((data: SupplierOption[]) => {
                    setResults(data);
                    setActive(0);
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, value]);

    if (value) {
        return (
            <div className="grid gap-1 text-sm">
                <div className="flex items-start justify-between gap-2">
                    <a
                        href={`/suppliers/${value.id}`}
                        target="_blank"
                        rel="noreferrer"
                        className="font-medium text-primary hover:underline"
                    >
                        {value.name}
                    </a>
                    {!disabled && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="-mt-1 size-7 text-muted-foreground"
                            aria-label="Đổi nhà cung cấp"
                            onClick={() => onChange(null)}
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
                <span className="text-muted-foreground">
                    {value.code}
                    {value.phone ? ` · ${value.phone}` : ''}
                </span>
                {value.address && (
                    <span className="text-muted-foreground">
                        {value.address}
                    </span>
                )}
                <span>
                    Công nợ:{' '}
                    <span
                        className={cn(
                            'font-medium tabular-nums',
                            value.debt > 0 && 'text-destructive',
                        )}
                    >
                        {formatNumber(value.debt)}
                    </span>
                </span>
            </div>
        );
    }

    const pick = (supplier: SupplierOption | undefined) => {
        if (supplier) {
            onChange(supplier);
            setTerm('');
            setOpen(false);
        }
    };

    return (
        <div className="relative">
            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
            <Input
                ref={input}
                value={term}
                disabled={disabled}
                placeholder="Tìm theo tên, mã, số điện thoại (F4)"
                className="pl-8"
                aria-invalid={!!error}
                onChange={(event) => {
                    setTerm(event.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setActive((index) =>
                            Math.min(index + 1, results.length - 1),
                        );
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setActive((index) => Math.max(index - 1, 0));
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        pick(results[active]);
                    }
                }}
            />
            {open && (
                <div className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-popover p-1 shadow-md">
                    {results.length === 0 ? (
                        <div className="px-2 py-3 text-sm text-muted-foreground">
                            Không tìm thấy nhà cung cấp.{' '}
                            <a
                                href="/suppliers/create"
                                target="_blank"
                                rel="noreferrer"
                                className="text-primary hover:underline"
                            >
                                Thêm mới
                            </a>
                        </div>
                    ) : (
                        results.map((supplier, index) => (
                            <button
                                type="button"
                                key={supplier.id}
                                className={cn(
                                    'flex w-full flex-col rounded-sm px-2 py-1.5 text-left text-sm',
                                    index === active && 'bg-accent',
                                )}
                                onMouseEnter={() => setActive(index)}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => pick(supplier)}
                            >
                                <span>{supplier.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {supplier.code}
                                    {supplier.phone
                                        ? ` · ${supplier.phone}`
                                        : ''}
                                </span>
                            </button>
                        ))
                    )}
                </div>
            )}
            {error && <p className="mt-1 text-sm text-destructive">{error}</p>}
        </div>
    );
}
