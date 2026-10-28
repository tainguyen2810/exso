import { Plus, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Field } from '@/components/app/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';
import { HttpError, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';

export type CustomerOption = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    group: string | null;
    address: string | null;
    addresses: {
        id: number;
        name: string | null;
        phone: string | null;
        address: string;
    }[];
    debt: number;
    total_spent: number;
    orders_count: number;
    returned_quantity: number;
    /** "retail_price" | "wholesale_price" | "list:<id>", from the group. */
    price_policy: string;
    price_label: string;
    discount_bp: number;
    payment_method_id: number | null;
};

/**
 * Search and pick a customer (F4) like Sapo's order form: recent customers
 * when the box is empty, "+ Thêm mới khách hàng" opens a quick add, and the
 * picked customer shows with debt, total spent and orders.
 */
export function CustomerPicker({
    value,
    onChange,
    disabled = false,
    error,
    placeholder = 'Tìm theo tên, SĐT, mã khách hàng... (F4)',
    compact = false,
}: {
    value: CustomerOption | null;
    onChange: (customer: CustomerOption | null) => void;
    disabled?: boolean;
    error?: string;
    placeholder?: string;
    /** One line summary (POS). */
    compact?: boolean;
}) {
    const can = useCan();
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<CustomerOption[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [adding, setAdding] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F4') {
                event.preventDefault();

                if (value && !disabled) {
                    onChange(null);
                }

                setTimeout(() => input.current?.focus(), 0);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [value, disabled, onChange]);

    useEffect(() => {
        if (value || !open) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/customers/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((data: CustomerOption[]) => {
                    setResults(data);
                    setActive(0);
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, value, open]);

    const pick = (customer: CustomerOption | undefined) => {
        if (customer) {
            onChange(customer);
            setTerm('');
            setOpen(false);
        }
    };

    const dialog = (
        <QuickAddCustomer
            open={adding}
            onOpenChange={setAdding}
            initial={term}
            onCreated={pick}
        />
    );

    if (value) {
        return (
            <div className="grid gap-1 text-sm">
                <div className="flex items-start justify-between gap-2">
                    <span className="min-w-0">
                        <a
                            href={`/customers/${value.id}`}
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium text-primary hover:underline"
                        >
                            {value.name}
                        </a>
                        {value.phone && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {value.phone}
                            </span>
                        )}
                    </span>
                    {!disabled && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="-mt-1 size-7 text-muted-foreground"
                            aria-label="Bỏ chọn khách hàng"
                            onClick={() => onChange(null)}
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
                {!compact && value.address && (
                    <span className="text-muted-foreground">
                        {value.address}
                    </span>
                )}
                <div
                    className={cn(
                        'grid gap-x-3 gap-y-1 text-xs',
                        compact ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-4',
                    )}
                >
                    <span>
                        <span className="text-muted-foreground">
                            Nợ phải thu
                        </span>
                        <span
                            className={cn(
                                'block font-medium tabular-nums',
                                value.debt > 0 && 'text-destructive',
                            )}
                        >
                            {formatNumber(value.debt)}
                        </span>
                    </span>
                    <span>
                        <span className="text-muted-foreground">
                            Tổng chi tiêu
                        </span>
                        <span className="block font-medium tabular-nums">
                            {formatNumber(value.total_spent)}
                        </span>
                    </span>
                    {!compact && (
                        <>
                            <span>
                                <span className="text-muted-foreground">
                                    Số đơn hàng
                                </span>
                                <span className="block font-medium tabular-nums">
                                    {formatNumber(value.orders_count)}
                                </span>
                            </span>
                            <span>
                                <span className="text-muted-foreground">
                                    Nhóm / giá
                                </span>
                                <span className="block font-medium">
                                    {value.group ?? '—'} · {value.price_label}
                                </span>
                            </span>
                        </>
                    )}
                </div>
                {dialog}
            </div>
        );
    }

    return (
        <div className="relative">
            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
            <Input
                ref={input}
                value={term}
                disabled={disabled}
                placeholder={placeholder}
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
                    } else if (event.key === 'Escape') {
                        setOpen(false);
                    }
                }}
            />
            {open && (
                <div className="absolute z-30 mt-1 max-h-80 w-full overflow-y-auto rounded-md border bg-popover p-1 shadow-md">
                    {can('customers.create') && (
                        <button
                            type="button"
                            className="flex w-full items-center gap-2 rounded-sm px-2 py-2 text-left text-sm text-primary hover:bg-accent"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => {
                                setOpen(false);
                                setAdding(true);
                            }}
                        >
                            <Plus className="size-4" /> Thêm mới khách hàng
                        </button>
                    )}
                    {results.length === 0 ? (
                        <div className="px-2 py-3 text-sm text-muted-foreground">
                            Không tìm thấy khách hàng.
                        </div>
                    ) : (
                        results.map((customer, index) => (
                            <button
                                type="button"
                                key={customer.id}
                                className={cn(
                                    'flex w-full items-center justify-between gap-3 rounded-sm px-2 py-1.5 text-left text-sm',
                                    index === active && 'bg-accent',
                                )}
                                onMouseEnter={() => setActive(index)}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => pick(customer)}
                            >
                                <span className="min-w-0">
                                    <span className="block truncate">
                                        {customer.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {customer.phone ?? customer.code}
                                    </span>
                                </span>
                                {customer.group && (
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {customer.group}
                                    </span>
                                )}
                            </button>
                        ))
                    )}
                </div>
            )}
            {error && <p className="mt-1 text-sm text-destructive">{error}</p>}
            {dialog}
        </div>
    );
}

/** "Thêm mới khách hàng" popup; the new customer is picked right away. */
function QuickAddCustomer({
    open,
    onOpenChange,
    initial,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initial: string;
    onCreated: (customer: CustomerOption) => void;
}) {
    const [data, setData] = useState({ name: '', phone: '', address: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            const digits = /^[\d\s+.-]{3,}$/.test(initial.trim());
            setData({
                name: digits ? '' : initial,
                phone: digits ? initial.trim() : '',
                address: '',
            });
            setErrors({});
        }
    }, [open, initial]);

    const submit = () => {
        setProcessing(true);
        postJson<CustomerOption>('/customers', data)
            .then((customer) => {
                onOpenChange(false);
                onCreated(customer);
            })
            .catch((error: unknown) =>
                setErrors(
                    error instanceof HttpError
                        ? { ...error.errors, form: error.message }
                        : { form: 'Có lỗi xảy ra, hãy thử lại.' },
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        submit();
                    }}
                >
                    <div>
                        <DialogTitle>Thêm mới khách hàng</DialogTitle>
                        <DialogDescription className="mt-1">
                            Khách mới vào nhóm mặc định; sửa thêm thông tin ở
                            trang khách hàng.
                        </DialogDescription>
                    </div>
                    <Field label="Tên khách hàng *" error={errors.name}>
                        <Input
                            autoFocus
                            value={data.name}
                            onChange={(event) =>
                                setData({ ...data, name: event.target.value })
                            }
                        />
                    </Field>
                    <Field label="Số điện thoại" error={errors.phone}>
                        <Input
                            value={data.phone}
                            inputMode="tel"
                            onChange={(event) =>
                                setData({ ...data, phone: event.target.value })
                            }
                        />
                    </Field>
                    <Field label="Địa chỉ" error={errors.address}>
                        <Input
                            value={data.address}
                            onChange={(event) =>
                                setData({
                                    ...data,
                                    address: event.target.value,
                                })
                            }
                        />
                    </Field>
                    {errors.form && !errors.name && !errors.phone && (
                        <p className="text-sm text-destructive">
                            {errors.form}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Hủy
                        </Button>
                        <Button disabled={processing}>Thêm</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
