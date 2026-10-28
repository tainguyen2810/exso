import { ChevronDown, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { MoneyInput } from '@/components/app/money-input';
import { PercentInput } from '@/components/app/percent-input';
import { StatusPill } from '@/components/app/status-badge';
import type { StatusMap } from '@/components/app/status-badge';
import { VariantPicker } from '@/components/app/variant-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type Named = { id: number; name: string };

export type VariantLabel = { id: number; name: string; sku: string };

export type ScopeOptions = {
    branches: Named[];
    sources: Named[];
    customerGroups: Named[];
    categories: Named[];
    brands: Named[];
};

export type ScopeData = {
    starts_at: string;
    ends_at: string;
    all_branches: boolean;
    branch_ids: number[];
    all_sources: boolean;
    source_ids: number[];
    all_customers: boolean;
    customer_group_ids: number[];
};

/** Trạng thái chương trình / đợt phát hành, như Sapo. */
export const promotionStates: StatusMap = {
    running: ['Đang áp dụng', 'success'],
    scheduled: ['Chưa áp dụng', 'info'],
    paused: ['Tạm ngừng', 'warning'],
    expired: ['Hết hạn', 'neutral'],
    stopped: ['Đã kết thúc', 'neutral'],
};

export function PromotionState({ value }: { value: string }) {
    return <StatusPill statuses={promotionStates} value={value} />;
}

/** Radio with its label, Sapo style. */
export function Radio({
    checked,
    onChange,
    children,
}: {
    checked: boolean;
    onChange: () => void;
    children: ReactNode;
}) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input
                type="radio"
                className="accent-primary"
                checked={checked}
                onChange={onChange}
            />
            {children}
        </label>
    );
}

/** Popover of checkboxes; the picked names show as chips. */
export function MultiPick({
    options,
    value,
    onChange,
    placeholder = 'Chọn',
}: {
    options: Named[];
    value: number[];
    onChange: (value: number[]) => void;
    placeholder?: string;
}) {
    const [term, setTerm] = useState('');
    const picked = options.filter((option) => value.includes(option.id));
    const shown = options.filter((option) =>
        option.name.toLowerCase().includes(term.trim().toLowerCase()),
    );

    return (
        <div className="grid gap-2">
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full justify-between font-normal"
                    >
                        <span className="truncate text-muted-foreground">
                            {picked.length > 0
                                ? `Đã chọn ${picked.length}`
                                : placeholder}
                        </span>
                        <ChevronDown className="size-4 opacity-60" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="w-(--radix-popover-trigger-width) p-2"
                >
                    {options.length > 8 && (
                        <Input
                            value={term}
                            placeholder="Tìm kiếm"
                            className="mb-2"
                            onChange={(event) => setTerm(event.target.value)}
                        />
                    )}
                    <div className="max-h-64 overflow-y-auto">
                        {shown.length === 0 && (
                            <p className="px-2 py-1.5 text-sm text-muted-foreground">
                                Không có lựa chọn.
                            </p>
                        )}
                        {shown.map((option) => (
                            <label
                                key={option.id}
                                className="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent"
                            >
                                <Checkbox
                                    checked={value.includes(option.id)}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            checked
                                                ? [...value, option.id]
                                                : value.filter(
                                                      (id) => id !== option.id,
                                                  ),
                                        )
                                    }
                                />
                                {option.name}
                            </label>
                        ))}
                    </div>
                </PopoverContent>
            </Popover>
            {picked.length > 0 && (
                <Chips
                    items={picked.map((option) => ({
                        id: option.id,
                        label: option.name,
                    }))}
                    onRemove={(id) =>
                        onChange(value.filter((item) => item !== id))
                    }
                />
            )}
        </div>
    );
}

function Chips({
    items,
    onRemove,
}: {
    items: { id: number; label: ReactNode }[];
    onRemove: (id: number) => void;
}) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item) => (
                <span
                    key={item.id}
                    className="inline-flex items-center gap-1 rounded-full border bg-muted px-2.5 py-0.5 text-xs"
                >
                    {item.label}
                    <button
                        type="button"
                        className="text-muted-foreground hover:text-destructive"
                        aria-label="Bỏ chọn"
                        onClick={() => onRemove(item.id)}
                    >
                        <X className="size-3" />
                    </button>
                </span>
            ))}
        </div>
    );
}

/** Search box for variants plus the picked ones as chips. */
export function VariantList({
    value,
    onChange,
    labels,
    onLabel,
    placeholder,
}: {
    value: number[];
    onChange: (value: number[]) => void;
    labels: Record<number, VariantLabel>;
    onLabel: (label: VariantLabel) => void;
    placeholder?: string;
}) {
    return (
        <div className="grid gap-2">
            <VariantPicker
                placeholder={
                    placeholder ?? 'Tìm sản phẩm theo tên, SKU, mã vạch'
                }
                onPick={(variant) => {
                    onLabel({
                        id: variant.id,
                        name: variant.name,
                        sku: variant.sku,
                    });

                    if (!value.includes(variant.id)) {
                        onChange([...value, variant.id]);
                    }
                }}
            />
            {value.length > 0 && (
                <Chips
                    items={value.map((id) => ({
                        id,
                        label: labels[id]
                            ? `${labels[id].name} (${labels[id].sku})`
                            : `#${id}`,
                    }))}
                    onRemove={(id) =>
                        onChange(value.filter((item) => item !== id))
                    }
                />
            )}
        </div>
    );
}

/** Products a rule applies to: variants, categories or brands. */
export function TargetPicker({
    target,
    value,
    onChange,
    options,
    labels,
    onLabel,
}: {
    target: string;
    value: number[];
    onChange: (value: number[]) => void;
    options: ScopeOptions;
    labels: Record<number, VariantLabel>;
    onLabel: (label: VariantLabel) => void;
}) {
    if (target === 'category') {
        return (
            <MultiPick
                options={options.categories}
                value={value}
                onChange={onChange}
                placeholder="Chọn loại sản phẩm"
            />
        );
    }

    if (target === 'brand') {
        return (
            <MultiPick
                options={options.brands}
                value={value}
                onChange={onChange}
                placeholder="Chọn nhãn hiệu"
            />
        );
    }

    return (
        <VariantList
            value={value}
            onChange={onChange}
            labels={labels}
            onLabel={onLabel}
        />
    );
}

export type DiscountValue = { type: 'percent' | 'amount'; value: number };

/** "Chiết khấu" box: percent (basis points) or amount in đồng. */
export function DiscountInput({
    value,
    onChange,
    className,
}: {
    value: DiscountValue;
    onChange: (value: DiscountValue) => void;
    className?: string;
}) {
    return (
        <div className={cn('flex', className)}>
            {value.type === 'percent' ? (
                <PercentInput
                    className="rounded-r-none text-right"
                    value={value.value || null}
                    placeholder="0"
                    onValueChange={(next) =>
                        onChange({ ...value, value: next ?? 0 })
                    }
                />
            ) : (
                <MoneyInput
                    className="rounded-r-none"
                    value={value.value || null}
                    placeholder="0"
                    onValueChange={(next) =>
                        onChange({ ...value, value: next ?? 0 })
                    }
                />
            )}
            <div className="flex shrink-0 rounded-r-md border border-l-0">
                {(['percent', 'amount'] as const).map((type) => (
                    <button
                        key={type}
                        type="button"
                        className={cn(
                            'px-2.5 text-sm',
                            value.type === type
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted',
                            type === 'amount' && 'rounded-r-md',
                        )}
                        onClick={() => onChange({ type, value: 0 })}
                    >
                        {type === 'percent' ? '%' : 'đ'}
                    </button>
                ))}
            </div>
        </div>
    );
}

/** One "Áp dụng cho" block: everyone, or some picked items. */
function ScopeBlock({
    title,
    allLabel,
    someLabel,
    all,
    ids,
    options,
    onChange,
}: {
    title: string;
    allLabel: string;
    someLabel: string;
    all: boolean;
    ids: number[];
    options: Named[];
    onChange: (all: boolean, ids: number[]) => void;
}) {
    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">{title}</p>
            <Radio checked={all} onChange={() => onChange(true, ids)}>
                {allLabel}
            </Radio>
            <Radio checked={!all} onChange={() => onChange(false, ids)}>
                {someLabel}
            </Radio>
            {!all && (
                <MultiPick
                    options={options}
                    value={ids}
                    onChange={(next) => onChange(false, next)}
                />
            )}
        </div>
    );
}

/** Chi nhánh / Nguồn đơn / Đối tượng khách hàng of a program or batch. */
export function ScopeFields<T extends ScopeData>({
    data,
    setData,
    options,
    errors,
}: {
    data: T;
    setData: (values: Partial<ScopeData>) => void;
    options: ScopeOptions;
    errors: Partial<Record<string, string>>;
}) {
    return (
        <div className="grid gap-5">
            <ScopeBlock
                title="Chi nhánh áp dụng"
                allLabel="Tất cả chi nhánh"
                someLabel="Chi nhánh được chọn"
                all={data.all_branches}
                ids={data.branch_ids}
                options={options.branches}
                onChange={(all, ids) =>
                    setData({ all_branches: all, branch_ids: ids })
                }
            />
            <ScopeBlock
                title="Nguồn đơn áp dụng"
                allLabel="Tất cả nguồn đơn"
                someLabel="Nguồn đơn được chọn"
                all={data.all_sources}
                ids={data.source_ids}
                options={options.sources}
                onChange={(all, ids) =>
                    setData({ all_sources: all, source_ids: ids })
                }
            />
            <ScopeBlock
                title="Đối tượng khách hàng"
                allLabel="Tất cả khách hàng"
                someLabel="Nhóm khách hàng được chọn"
                all={data.all_customers}
                ids={data.customer_group_ids}
                options={options.customerGroups}
                onChange={(all, ids) =>
                    setData({ all_customers: all, customer_group_ids: ids })
                }
            />
            {(errors.branch_ids ||
                errors.source_ids ||
                errors.customer_group_ids) && (
                <p className="text-sm text-destructive">
                    {errors.branch_ids ??
                        errors.source_ids ??
                        errors.customer_group_ids}
                </p>
            )}
        </div>
    );
}

/** Start / end date-time; "Không có ngày kết thúc" clears the end. */
export function PeriodFields({
    startsAt,
    endsAt,
    onChange,
    errors,
}: {
    startsAt: string;
    endsAt: string;
    onChange: (values: { starts_at?: string; ends_at?: string }) => void;
    errors: Partial<Record<string, string>>;
}) {
    const [noEnd, setNoEnd] = useState(endsAt === '');

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <p className="text-sm font-medium">Ngày bắt đầu *</p>
                <Input
                    type="datetime-local"
                    value={startsAt}
                    onChange={(event) =>
                        onChange({ starts_at: event.target.value })
                    }
                />
                {errors.starts_at && (
                    <p className="text-sm text-destructive">
                        {errors.starts_at}
                    </p>
                )}
            </div>
            <div className="grid gap-2">
                <p className="text-sm font-medium">Ngày kết thúc</p>
                {!noEnd && (
                    <Input
                        type="datetime-local"
                        value={endsAt}
                        onChange={(event) =>
                            onChange({ ends_at: event.target.value })
                        }
                    />
                )}
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={noEnd}
                        onCheckedChange={(checked) => {
                            setNoEnd(checked === true);

                            if (checked) {
                                onChange({ ends_at: '' });
                            }
                        }}
                    />
                    Không có ngày kết thúc
                </label>
                {errors.ends_at && (
                    <p className="text-sm text-destructive">{errors.ends_at}</p>
                )}
            </div>
        </div>
    );
}

/** Now in the datetime-local format, for a new program's start. */
export function nowLocal(): string {
    const now = new Date();
    now.setSeconds(0, 0);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
}
