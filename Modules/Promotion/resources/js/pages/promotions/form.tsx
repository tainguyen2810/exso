import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect } from '@/components/app/option-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    DiscountInput,
    PeriodFields,
    PromotionState,
    ScopeFields,
    TargetPicker,
    VariantList,
    nowLocal,
} from '../../components/promotion-fields';
import type {
    DiscountValue,
    ScopeData,
    ScopeOptions,
    VariantLabel,
} from '../../components/promotion-fields';

type DiscountType = DiscountValue['type'];

type Tier = {
    from: number;
    to: number | null;
    type: DiscountType;
    value: number;
};

/** One condition row; which keys are used depends on the method. */
type Row = {
    min?: number;
    max?: number | null;
    ids?: number[];
    min_qty?: number;
    limit_qty?: number | null;
    min_order?: number;
    max_qty?: number;
    tiers?: Tier[];
    buy_ids?: number[];
    buy_qty?: number;
    get_ids?: number[];
    get_qty?: number;
    gift_ids?: number[];
    gift_qty?: number;
    type?: DiscountType;
    value?: number;
};

type Schedule = {
    time_from: string | null;
    time_to: string | null;
    weekdays: number[];
    months: number[];
    days: number[];
};

type Promotion = ScopeData & {
    id: number;
    code: string;
    name: string;
    description: string | null;
    kind: string;
    method: string;
    status: string;
    state: string;
    schedule: Schedule | null;
    usage_limit: number | null;
    used_count: number;
    target_type: string;
    get_target_type: string;
    repeatable: boolean;
    conditions: Row[];
    creator: string | null;
    created_at: string | null;
    orders: {
        order_id: number;
        code: string;
        amount: number;
        created_at: string | null;
    }[];
};

type Method = { value: string; kind: string; label: string };

const discount = { type: 'percent' as DiscountType, value: 0 };

const blankRow = (method: string): Row =>
    ({
        order_total: { min: 0, max: null, ...discount },
        product: { ids: [], min_qty: 1, limit_qty: null, ...discount },
        buy_more: { min_order: 0, ids: [], max_qty: 1, ...discount },
        quantity: { ids: [], tiers: [{ from: 1, to: null, ...discount }] },
        buy_get: {
            buy_ids: [],
            buy_qty: 1,
            get_ids: [],
            get_qty: 1,
            ...discount,
        },
        gift_order: { min_order: 0, gift_ids: [], gift_qty: 1 },
        gift_product: { buy_ids: [], buy_qty: 1, gift_ids: [], gift_qty: 1 },
    })[method] ?? {};

/** Methods whose products are picked by `target_type`. */
const usesTarget = [
    'product',
    'buy_more',
    'quantity',
    'buy_get',
    'gift_product',
];

const WEEKDAYS = [
    'Thứ 2',
    'Thứ 3',
    'Thứ 4',
    'Thứ 5',
    'Thứ 6',
    'Thứ 7',
    'Chủ nhật',
];

/** Tạo / sửa chương trình khuyến mại (chiết khấu hoặc tặng quà), như Sapo. */
export default function PromotionForm({
    promotion,
    kind,
    methods,
    targets,
    variants,
    ...options
}: ScopeOptions & {
    promotion: Promotion | null;
    kind: string;
    methods: Method[];
    targets: { value: string; label: string }[];
    variants?: Record<number, VariantLabel> | VariantLabel[];
}) {
    const can = useCan();
    const kindMethods = methods.filter((method) => method.kind === kind);
    const firstMethod =
        promotion?.method ?? kindMethods[0]?.value ?? 'order_total';
    const [labels, setLabels] = useState<Record<number, VariantLabel>>(() =>
        Array.isArray(variants) ? {} : (variants ?? {}),
    );
    const [advanced, setAdvanced] = useState(Boolean(promotion?.schedule));

    const form = useForm({
        name: promotion?.name ?? '',
        code: promotion?.code ?? '',
        description: promotion?.description ?? '',
        method: firstMethod,
        usage_limit: promotion?.usage_limit ?? (null as number | null),
        target_type: promotion?.target_type ?? 'variant',
        get_target_type: promotion?.get_target_type ?? 'variant',
        repeatable: promotion?.repeatable ?? false,
        conditions: promotion?.conditions ?? [blankRow(firstMethod)],
        schedule: promotion?.schedule ?? {
            time_from: null,
            time_to: null,
            weekdays: [],
            months: [],
            days: [],
        },
        starts_at: promotion?.starts_at ?? nowLocal(),
        ends_at: promotion?.ends_at ?? '',
        all_branches: promotion?.all_branches ?? true,
        branch_ids: promotion?.branch_ids ?? [],
        all_sources: promotion?.all_sources ?? true,
        source_ids: promotion?.source_ids ?? [],
        all_customers: promotion?.all_customers ?? true,
        customer_group_ids: promotion?.customer_group_ids ?? [],
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const editable = promotion
        ? can('promotions.update') && promotion.status !== 'stopped'
        : can('promotions.create');

    const submit = () => {
        if (promotion) {
            form.put(`/promotions/${promotion.id}`, { preserveScroll: true });
        } else {
            form.post('/promotions');
        }
    };

    const setRow = (index: number, values: Partial<Row>) =>
        form.setData(
            'conditions',
            data.conditions.map((row, i) =>
                i === index ? { ...row, ...values } : row,
            ),
        );
    const addLabel = (label: VariantLabel) =>
        setLabels((current) => ({ ...current, [label.id]: label }));

    const target = (
        index: number,
        key: 'ids' | 'buy_ids' | 'get_ids',
        type: string,
    ) => (
        <TargetPicker
            target={type}
            value={data.conditions[index][key] ?? []}
            onChange={(ids) => setRow(index, { [key]: ids })}
            options={options}
            labels={labels}
            onLabel={addLabel}
        />
    );
    const gifts = (index: number) => (
        <VariantList
            value={data.conditions[index].gift_ids ?? []}
            onChange={(ids) => setRow(index, { gift_ids: ids })}
            labels={labels}
            onLabel={addLabel}
            placeholder="Tìm sản phẩm quà tặng"
        />
    );
    const number = (
        index: number,
        key: keyof Row,
        nullable = false,
        placeholder?: string,
    ) => (
        <MoneyInput
            value={(data.conditions[index][key] as number | null) ?? null}
            placeholder={placeholder}
            onValueChange={(value) =>
                setRow(index, { [key]: nullable ? value : (value ?? 0) })
            }
        />
    );
    const discountOf = (index: number) => (
        <DiscountInput
            value={{
                type: data.conditions[index].type ?? 'percent',
                value: data.conditions[index].value ?? 0,
            }}
            onChange={(value) => setRow(index, value)}
        />
    );

    const rowFields = (index: number): ReactNode => {
        const row = data.conditions[index];

        switch (data.method) {
            case 'order_total':
                return (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Tổng tiền đơn hàng từ">
                            {number(index, 'min')}
                        </Field>
                        <Field label="Đến">
                            {number(index, 'max', true, 'Không giới hạn')}
                        </Field>
                        <Field label="Chiết khấu">{discountOf(index)}</Field>
                    </div>
                );
            case 'product':
                return (
                    <div className="grid gap-3">
                        <Field label="Sản phẩm áp dụng">
                            {target(index, 'ids', data.target_type)}
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Số lượng tối thiểu">
                                {number(index, 'min_qty')}
                            </Field>
                            <Field label="Giới hạn số lượng giảm">
                                {number(
                                    index,
                                    'limit_qty',
                                    true,
                                    'Không giới hạn',
                                )}
                            </Field>
                            <Field label="Chiết khấu">
                                {discountOf(index)}
                            </Field>
                        </div>
                    </div>
                );
            case 'buy_more':
                return (
                    <div className="grid gap-3">
                        <Field label="Tổng tiền đơn hàng từ">
                            {number(index, 'min_order')}
                        </Field>
                        <Field label="Sản phẩm mua thêm">
                            {target(index, 'ids', data.target_type)}
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Số lượng tối đa được giảm">
                                {number(index, 'max_qty')}
                            </Field>
                            <Field label="Chiết khấu">
                                {discountOf(index)}
                            </Field>
                        </div>
                    </div>
                );
            case 'quantity':
                return (
                    <div className="grid gap-3">
                        <Field label="Sản phẩm áp dụng">
                            {target(index, 'ids', data.target_type)}
                        </Field>
                        <div className="grid gap-2">
                            <p className="text-sm font-medium">Mức số lượng</p>
                            {(row.tiers ?? []).map((tier, tierIndex) => {
                                const setTier = (values: Partial<Tier>) =>
                                    setRow(index, {
                                        tiers: (row.tiers ?? []).map(
                                            (item, i) =>
                                                i === tierIndex
                                                    ? { ...item, ...values }
                                                    : item,
                                        ),
                                    });

                                return (
                                    <div
                                        key={tierIndex}
                                        className="grid grid-cols-[1fr_1fr_1.4fr_auto] items-center gap-2"
                                    >
                                        <MoneyInput
                                            value={tier.from}
                                            placeholder="Từ"
                                            onValueChange={(value) =>
                                                setTier({ from: value ?? 1 })
                                            }
                                        />
                                        <MoneyInput
                                            value={tier.to}
                                            placeholder="Đến (không giới hạn)"
                                            onValueChange={(value) =>
                                                setTier({ to: value })
                                            }
                                        />
                                        <DiscountInput
                                            value={{
                                                type: tier.type,
                                                value: tier.value,
                                            }}
                                            onChange={setTier}
                                        />
                                        <RemoveButton
                                            disabled={
                                                (row.tiers ?? []).length <= 1
                                            }
                                            onClick={() =>
                                                setRow(index, {
                                                    tiers: (
                                                        row.tiers ?? []
                                                    ).filter(
                                                        (_, i) =>
                                                            i !== tierIndex,
                                                    ),
                                                })
                                            }
                                        />
                                    </div>
                                );
                            })}
                            <button
                                type="button"
                                className="w-fit text-sm text-primary hover:underline"
                                onClick={() => {
                                    const last = row.tiers?.at(-1);
                                    setRow(index, {
                                        tiers: [
                                            ...(row.tiers ?? []),
                                            {
                                                from:
                                                    (last?.to ??
                                                        last?.from ??
                                                        0) + 1,
                                                to: null,
                                                ...discount,
                                            },
                                        ],
                                    });
                                }}
                            >
                                + Thêm mức số lượng
                            </button>
                        </div>
                    </div>
                );
            case 'buy_get':
                return (
                    <div className="grid gap-3">
                        <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
                            <Field label="Sản phẩm mua">
                                {target(index, 'buy_ids', data.target_type)}
                            </Field>
                            <Field label="Số lượng mua">
                                {number(index, 'buy_qty')}
                            </Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
                            <Field label="Sản phẩm được giảm giá">
                                {target(index, 'get_ids', data.get_target_type)}
                            </Field>
                            <Field label="Số lượng giảm">
                                {number(index, 'get_qty')}
                            </Field>
                        </div>
                        <Field label="Chiết khấu" className="sm:max-w-xs">
                            {discountOf(index)}
                        </Field>
                    </div>
                );
            case 'gift_order':
                return (
                    <div className="grid gap-3">
                        <Field label="Tổng tiền đơn hàng từ">
                            {number(index, 'min_order')}
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
                            <Field label="Quà tặng (khách chọn 1)">
                                {gifts(index)}
                            </Field>
                            <Field label="Số lượng tặng">
                                {number(index, 'gift_qty')}
                            </Field>
                        </div>
                    </div>
                );
            case 'gift_product':
                return (
                    <div className="grid gap-3">
                        <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
                            <Field label="Sản phẩm mua">
                                {target(index, 'buy_ids', data.target_type)}
                            </Field>
                            <Field label="Số lượng mua">
                                {number(index, 'buy_qty')}
                            </Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-[1fr_140px]">
                            <Field label="Quà tặng (khách chọn 1)">
                                {gifts(index)}
                            </Field>
                            <Field label="Số lượng tặng">
                                {number(index, 'gift_qty')}
                            </Field>
                        </div>
                    </div>
                );
            default:
                return null;
        }
    };

    const schedule = data.schedule;
    const setSchedule = (values: Partial<Schedule>) =>
        form.setData('schedule', { ...schedule, ...values });
    const toggle = (list: number[], value: number) =>
        list.includes(value)
            ? list.filter((item) => item !== value)
            : [...list, value].sort((a, b) => a - b);

    const title = promotion
        ? promotion.name
        : kind === 'gift'
          ? 'Tạo chương trình tặng quà'
          : 'Tạo chương trình chiết khấu';

    const status = (value: string) =>
        router.post(
            `/promotions/${promotion?.id}/status`,
            { status: value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/promotions"
                backLabel="Quay lại danh sách khuyến mại"
                onSubmit={submit}
                processing={form.processing}
                readOnly={
                    editable
                        ? null
                        : promotion?.status === 'stopped'
                          ? 'Chương trình đã kết thúc, chỉ xem được.'
                          : 'Bạn chỉ có quyền xem chương trình này.'
                }
                actions={
                    promotion &&
                    can('promotions.delete') && (
                        <ConfirmDelete
                            url={`/promotions/${promotion.id}`}
                            title={`Xóa chương trình ${promotion.code}?`}
                            description="Đơn hàng đã áp dụng chương trình vẫn giữ nguyên chiết khấu."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa chương trình
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        {promotion && (
                            <FormSection title="Trạng thái">
                                <div className="flex items-center justify-between gap-2">
                                    <PromotionState value={promotion.state} />
                                    <span className="text-sm text-muted-foreground">
                                        Đã áp dụng{' '}
                                        {formatNumber(promotion.used_count)}
                                        {promotion.usage_limit
                                            ? `/${formatNumber(promotion.usage_limit)}`
                                            : ''}{' '}
                                        lần
                                    </span>
                                </div>
                                {can('promotions.update') &&
                                    promotion.status !== 'stopped' && (
                                        <div className="flex flex-wrap gap-2">
                                            {promotion.status === 'active' ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        status('paused')
                                                    }
                                                >
                                                    Tạm ngừng
                                                </Button>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        status('active')
                                                    }
                                                >
                                                    Kích hoạt
                                                </Button>
                                            )}
                                            <ConfirmAction
                                                onConfirm={() =>
                                                    status('stopped')
                                                }
                                                title="Kết thúc chương trình?"
                                                description="Chương trình kết thúc sẽ không áp dụng cho đơn hàng mới và không kích hoạt lại được."
                                                confirmLabel="Kết thúc"
                                                destructive
                                                trigger={
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        className="text-destructive"
                                                    >
                                                        Kết thúc
                                                    </Button>
                                                }
                                            />
                                        </div>
                                    )}
                                {errors.status && (
                                    <p className="text-sm text-destructive">
                                        {errors.status}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Tạo bởi {promotion.creator ?? '—'} lúc{' '}
                                    {formatDateTime(promotion.created_at)}
                                </p>
                            </FormSection>
                        )}
                        <FormSection title="Thời gian áp dụng">
                            <PeriodFields
                                startsAt={data.starts_at}
                                endsAt={data.ends_at}
                                onChange={(values) =>
                                    form.setData({ ...data, ...values })
                                }
                                errors={errors}
                            />
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={advanced}
                                    onCheckedChange={(checked) => {
                                        setAdvanced(checked === true);

                                        if (!checked) {
                                            setSchedule({
                                                time_from: null,
                                                time_to: null,
                                                weekdays: [],
                                                months: [],
                                                days: [],
                                            });
                                        }
                                    }}
                                />
                                Hiển thị thiết lập nâng cao
                            </label>
                            {advanced && (
                                <div className="grid gap-4 rounded-md bg-muted/50 p-3">
                                    <div className="grid gap-2">
                                        <p className="text-sm font-medium">
                                            Khung giờ trong ngày
                                        </p>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Input
                                                type="time"
                                                value={schedule.time_from ?? ''}
                                                onChange={(event) =>
                                                    setSchedule({
                                                        time_from:
                                                            event.target
                                                                .value || null,
                                                    })
                                                }
                                            />
                                            <Input
                                                type="time"
                                                value={schedule.time_to ?? ''}
                                                onChange={(event) =>
                                                    setSchedule({
                                                        time_to:
                                                            event.target
                                                                .value || null,
                                                    })
                                                }
                                            />
                                        </div>
                                        {errors['schedule.time_to'] && (
                                            <p className="text-sm text-destructive">
                                                {errors['schedule.time_to']}
                                            </p>
                                        )}
                                    </div>
                                    <DayPicker
                                        title="Ngày trong tuần"
                                        items={WEEKDAYS.map((label, i) => ({
                                            value: i + 1,
                                            label,
                                        }))}
                                        value={schedule.weekdays}
                                        onToggle={(value) =>
                                            setSchedule({
                                                weekdays: toggle(
                                                    schedule.weekdays,
                                                    value,
                                                ),
                                            })
                                        }
                                    />
                                    <DayPicker
                                        title="Tháng trong năm"
                                        items={Array.from(
                                            { length: 12 },
                                            (_, i) => ({
                                                value: i + 1,
                                                label: `T${i + 1}`,
                                            }),
                                        )}
                                        value={schedule.months}
                                        onToggle={(value) =>
                                            setSchedule({
                                                months: toggle(
                                                    schedule.months,
                                                    value,
                                                ),
                                            })
                                        }
                                    />
                                    <DayPicker
                                        title="Ngày trong tháng"
                                        items={Array.from(
                                            { length: 31 },
                                            (_, i) => ({
                                                value: i + 1,
                                                label: String(i + 1),
                                            }),
                                        )}
                                        value={schedule.days}
                                        onToggle={(value) =>
                                            setSchedule({
                                                days: toggle(
                                                    schedule.days,
                                                    value,
                                                ),
                                            })
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Để trống mục nào thì áp dụng mọi
                                        giờ/ngày của mục đó.
                                    </p>
                                </div>
                            )}
                        </FormSection>
                        <FormSection title="Phạm vi áp dụng">
                            <ScopeFields
                                data={data}
                                setData={(values) =>
                                    form.setData({ ...data, ...values })
                                }
                                options={options}
                                errors={errors}
                            />
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Thông tin chung">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Tên chương trình khuyến mại *"
                            error={errors.name}
                        >
                            <Input
                                autoFocus={!promotion}
                                value={data.name}
                                placeholder="Ví dụ: Khuyến mại tháng 11"
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Mã khuyến mại" error={errors.code}>
                            <Input
                                value={data.code}
                                placeholder="Để trống để tự sinh (KM...)"
                                onChange={(event) =>
                                    form.setData('code', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Số lượng áp dụng" error={errors.usage_limit}>
                        <div className="flex flex-wrap items-center gap-4">
                            <MoneyInput
                                className="max-w-48"
                                value={data.usage_limit}
                                disabled={data.usage_limit === null}
                                placeholder="Không giới hạn"
                                onValueChange={(value) =>
                                    form.setData('usage_limit', value)
                                }
                            />
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.usage_limit === null}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'usage_limit',
                                            checked ? null : 100,
                                        )
                                    }
                                />
                                Không giới hạn số lượng
                            </label>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Số đơn hàng tối đa được áp dụng chương trình.
                        </p>
                    </Field>
                    <Field label="Mô tả" error={errors.description}>
                        <Textarea
                            value={data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                </FormSection>

                <FormSection title="Thông tin khuyến mại">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Hình thức khuyến mại *"
                            error={errors.method}
                        >
                            <OptionSelect
                                value={data.method}
                                disabled={(promotion?.used_count ?? 0) > 0}
                                options={kindMethods}
                                onChange={(value) => {
                                    const method = value ?? data.method;
                                    form.setData({
                                        ...data,
                                        method,
                                        conditions: [blankRow(method)],
                                    });
                                }}
                            />
                        </Field>
                        {usesTarget.includes(data.method) && (
                            <Field
                                label={
                                    data.method === 'buy_get' ||
                                    data.method === 'gift_product'
                                        ? 'Chọn sản phẩm mua theo'
                                        : 'Áp dụng cho'
                                }
                                error={errors.target_type}
                            >
                                <OptionSelect
                                    value={data.target_type}
                                    options={targets}
                                    onChange={(value) =>
                                        form.setData({
                                            ...data,
                                            target_type: value ?? 'variant',
                                            conditions: data.conditions.map(
                                                (row) => ({
                                                    ...row,
                                                    ids: row.ids && [],
                                                    buy_ids: row.buy_ids && [],
                                                }),
                                            ),
                                        })
                                    }
                                />
                            </Field>
                        )}
                        {data.method === 'buy_get' && (
                            <Field
                                label="Chọn sản phẩm được giảm theo"
                                error={errors.get_target_type}
                            >
                                <OptionSelect
                                    value={data.get_target_type}
                                    options={targets}
                                    onChange={(value) =>
                                        form.setData({
                                            ...data,
                                            get_target_type: value ?? 'variant',
                                            conditions: data.conditions.map(
                                                (row) => ({
                                                    ...row,
                                                    get_ids: [],
                                                }),
                                            ),
                                        })
                                    }
                                />
                            </Field>
                        )}
                    </div>
                    {(data.method === 'buy_get' ||
                        data.method === 'gift_product') && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.repeatable}
                                onCheckedChange={(checked) =>
                                    form.setData('repeatable', checked === true)
                                }
                            />
                            Nhân theo số lượng mua (mua 2 lần số lượng thì được
                            2 lần ưu đãi)
                        </label>
                    )}
                    {errors.conditions && (
                        <p className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                            {errors.conditions}
                        </p>
                    )}
                    <div className="grid gap-3">
                        {data.conditions.map((_, index) => (
                            <div
                                key={index}
                                className="relative rounded-md border p-4 pr-12"
                            >
                                <p className="mb-3 text-sm font-medium text-muted-foreground">
                                    Điều kiện {index + 1}
                                </p>
                                {rowFields(index)}
                                <div className="absolute top-3 right-3">
                                    <RemoveButton
                                        disabled={data.conditions.length <= 1}
                                        onClick={() =>
                                            form.setData(
                                                'conditions',
                                                data.conditions.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            className="w-fit"
                            onClick={() =>
                                form.setData('conditions', [
                                    ...data.conditions,
                                    blankRow(data.method),
                                ])
                            }
                        >
                            <Plus /> Thêm điều kiện
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            Đơn hàng thỏa nhiều điều kiện thì áp dụng điều kiện
                            có lợi nhất cho khách.
                        </p>
                    </div>
                </FormSection>

                {promotion && promotion.orders.length > 0 && (
                    <FormSection title="Đơn hàng đã áp dụng">
                        <div className="divide-y text-sm">
                            {promotion.orders.map((order) => (
                                <div
                                    key={`${order.order_id}-${order.created_at}`}
                                    className="flex items-center justify-between py-2"
                                >
                                    <Link
                                        href={`/orders/${order.order_id}`}
                                        className="text-primary hover:underline"
                                    >
                                        {order.code}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {formatDateTime(order.created_at)}
                                    </span>
                                    <span className="tabular-nums">
                                        {order.amount > 0
                                            ? `-${formatNumber(order.amount)}`
                                            : 'Quà tặng'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </FormSection>
                )}
            </DocumentForm>
        </>
    );
}

function RemoveButton({
    onClick,
    disabled,
}: {
    onClick: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            className="rounded p-1 text-muted-foreground hover:text-destructive disabled:opacity-30"
            aria-label="Xóa"
            disabled={disabled}
            onClick={onClick}
        >
            <X className="size-4" />
        </button>
    );
}

function DayPicker({
    title,
    items,
    value,
    onToggle,
}: {
    title: string;
    items: { value: number; label: string }[];
    value: number[];
    onToggle: (value: number) => void;
}) {
    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">{title}</p>
            <div className="flex flex-wrap gap-1.5">
                {items.map((item) => (
                    <button
                        key={item.value}
                        type="button"
                        className={
                            value.includes(item.value)
                                ? 'min-w-9 rounded border border-primary bg-primary px-2 py-1 text-xs text-primary-foreground'
                                : 'min-w-9 rounded border bg-background px-2 py-1 text-xs hover:border-primary'
                        }
                        onClick={() => onToggle(item.value)}
                    >
                        {item.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

PromotionForm.layout = {
    breadcrumbs: [
        { title: 'Khuyến mại', href: '/promotions' },
        { title: 'Quản lý khuyến mại', href: '/promotions' },
        { title: 'Chi tiết', href: '#' },
    ],
};
