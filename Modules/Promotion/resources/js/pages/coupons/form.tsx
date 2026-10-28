import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect } from '@/components/app/option-select';
import { PercentInput } from '@/components/app/percent-input';
import { RecordDialog } from '@/components/app/settings-page';
import { Pill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    PeriodFields,
    PromotionState,
    Radio,
    ScopeFields,
    TargetPicker,
    nowLocal,
} from '../../components/promotion-fields';
import type {
    ScopeData,
    ScopeOptions,
    VariantLabel,
} from '../../components/promotion-fields';

type Code = {
    id: number;
    code: string;
    is_active: boolean;
    used_count: number;
    created_at: string | null;
};

type Batch = ScopeData & {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    state: string;
    value_type: 'percent' | 'amount';
    value: number;
    max_discount: number | null;
    uses_per_code: number | null;
    uses_per_customer: number | null;
    combinable: boolean;
    min_order: number;
    product_target_type: string | null;
    product_ids: number[];
    used_count: number;
    creator: string | null;
    created_at: string | null;
    coupons: Code[];
    coupons_count: number;
};

/** Đợt phát hành mã giảm giá: giá trị, điều kiện, giới hạn và danh sách mã. */
export default function CouponForm({
    batch,
    targets,
    variants,
    ...options
}: ScopeOptions & {
    batch: Batch | null;
    targets: { value: string; label: string }[];
    variants?: Record<number, VariantLabel> | VariantLabel[];
}) {
    const can = useCan();
    const [labels, setLabels] = useState<Record<number, VariantLabel>>(() =>
        Array.isArray(variants) ? {} : (variants ?? {}),
    );
    const form = useForm({
        name: batch?.name ?? '',
        code: batch?.code ?? '',
        description: batch?.description ?? '',
        is_active: batch?.is_active ?? true,
        value_type: batch?.value_type ?? ('percent' as 'percent' | 'amount'),
        value: batch?.value ?? (0 as number),
        max_discount: batch?.max_discount ?? (null as number | null),
        uses_per_code: batch?.uses_per_code ?? (1 as number | null),
        uses_per_customer: batch?.uses_per_customer ?? (null as number | null),
        combinable: batch?.combinable ?? false,
        min_order: batch?.min_order ?? 0,
        product_target_type: batch?.product_target_type ?? 'variant',
        product_ids: batch?.product_ids ?? [],
        starts_at: batch?.starts_at ?? nowLocal(),
        ends_at: batch?.ends_at ?? '',
        all_branches: batch?.all_branches ?? true,
        branch_ids: batch?.branch_ids ?? [],
        all_sources: batch?.all_sources ?? true,
        source_ids: batch?.source_ids ?? [],
        all_customers: batch?.all_customers ?? true,
        customer_group_ids: batch?.customer_group_ids ?? [],
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const [someProducts, setSomeProducts] = useState(
        (batch?.product_ids.length ?? 0) > 0,
    );
    const editable = batch ? can('coupons.update') : can('coupons.create');

    const submit = () => {
        form.transform((values) => ({
            ...values,
            product_ids: someProducts ? values.product_ids : [],
        }));

        if (batch) {
            form.put(`/coupons/${batch.id}`, { preserveScroll: true });
        } else {
            form.post('/coupons');
        }
    };

    const title = batch ? batch.name : 'Tạo đợt phát hành mã giảm giá';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/coupons"
                backLabel="Quay lại danh sách mã giảm giá"
                onSubmit={submit}
                processing={form.processing}
                readOnly={
                    editable ? null : 'Bạn chỉ có quyền xem đợt phát hành này.'
                }
                actions={
                    batch &&
                    can('coupons.delete') && (
                        <ConfirmDelete
                            url={`/coupons/${batch.id}`}
                            title={`Xóa đợt phát hành ${batch.code}?`}
                            description="Các mã của đợt sẽ không dùng được nữa. Đơn hàng đã dùng mã vẫn giữ nguyên."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa đợt phát hành
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        <FormSection title="Trạng thái">
                            <label className="flex items-center justify-between gap-2 text-sm">
                                <span>Kích hoạt đợt phát hành</span>
                                <Switch
                                    checked={data.is_active}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_active', checked)
                                    }
                                />
                            </label>
                            {batch && (
                                <>
                                    <div className="flex items-center justify-between text-sm">
                                        <PromotionState value={batch.state} />
                                        <span className="text-muted-foreground">
                                            Đã dùng{' '}
                                            {formatNumber(batch.used_count)} lần
                                        </span>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Tạo bởi {batch.creator ?? '—'} lúc{' '}
                                        {formatDateTime(batch.created_at)}
                                    </p>
                                </>
                            )}
                        </FormSection>
                        <FormSection title="Thời gian áp dụng">
                            <PeriodFields
                                startsAt={data.starts_at}
                                endsAt={data.ends_at}
                                onChange={(values) =>
                                    form.setData({ ...data, ...values })
                                }
                                errors={errors}
                            />
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
                        <Field label="Tên đợt phát hành *" error={errors.name}>
                            <Input
                                autoFocus={!batch}
                                value={data.name}
                                placeholder="Ví dụ: Mã giảm giá khách mới"
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Mã đợt phát hành" error={errors.code}>
                            <Input
                                value={data.code}
                                placeholder="Để trống để tự sinh (DPH...)"
                                onChange={(event) =>
                                    form.setData('code', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Mô tả" error={errors.description}>
                        <Textarea
                            value={data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                </FormSection>

                <FormSection title="Giá trị khuyến mại">
                    <div className="flex gap-6">
                        <Radio
                            checked={data.value_type === 'percent'}
                            onChange={() =>
                                form.setData({
                                    ...data,
                                    value_type: 'percent',
                                    value: 0,
                                })
                            }
                        >
                            Giảm theo phần trăm
                        </Radio>
                        <Radio
                            checked={data.value_type === 'amount'}
                            onChange={() =>
                                form.setData({
                                    ...data,
                                    value_type: 'amount',
                                    value: 0,
                                    max_discount: null,
                                })
                            }
                        >
                            Giảm theo số tiền
                        </Radio>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={
                                data.value_type === 'percent'
                                    ? 'Giá trị giảm (%) *'
                                    : 'Số tiền giảm *'
                            }
                            error={errors.value}
                        >
                            {data.value_type === 'percent' ? (
                                <PercentInput
                                    value={data.value || null}
                                    placeholder="0"
                                    onValueChange={(value) =>
                                        form.setData('value', value ?? 0)
                                    }
                                />
                            ) : (
                                <MoneyInput
                                    value={data.value || null}
                                    placeholder="0"
                                    onValueChange={(value) =>
                                        form.setData('value', value ?? 0)
                                    }
                                />
                            )}
                        </Field>
                        {data.value_type === 'percent' && (
                            <Field
                                label="Giảm tối đa"
                                error={errors.max_discount}
                            >
                                <MoneyInput
                                    value={data.max_discount}
                                    placeholder="Không giới hạn"
                                    onValueChange={(value) =>
                                        form.setData('max_discount', value)
                                    }
                                />
                            </Field>
                        )}
                    </div>
                </FormSection>

                <FormSection title="Điều kiện áp dụng">
                    <Field
                        label="Giá trị đơn hàng tối thiểu"
                        error={errors.min_order}
                        className="sm:max-w-xs"
                    >
                        <MoneyInput
                            value={data.min_order || null}
                            placeholder="0"
                            onValueChange={(value) =>
                                form.setData('min_order', value ?? 0)
                            }
                        />
                    </Field>
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">Sản phẩm áp dụng</p>
                        <Radio
                            checked={!someProducts}
                            onChange={() => setSomeProducts(false)}
                        >
                            Tất cả sản phẩm
                        </Radio>
                        <Radio
                            checked={someProducts}
                            onChange={() => setSomeProducts(true)}
                        >
                            Sản phẩm được chọn
                        </Radio>
                        {someProducts && (
                            <div className="grid gap-2 sm:grid-cols-[200px_1fr]">
                                <OptionSelect
                                    value={data.product_target_type}
                                    options={targets}
                                    onChange={(value) =>
                                        form.setData({
                                            ...data,
                                            product_target_type:
                                                value ?? 'variant',
                                            product_ids: [],
                                        })
                                    }
                                />
                                <TargetPicker
                                    target={data.product_target_type}
                                    value={data.product_ids}
                                    onChange={(ids) =>
                                        form.setData('product_ids', ids)
                                    }
                                    options={options}
                                    labels={labels}
                                    onLabel={(label) =>
                                        setLabels((current) => ({
                                            ...current,
                                            [label.id]: label,
                                        }))
                                    }
                                />
                            </div>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Mã giảm trên tiền hàng của các sản phẩm áp dụng, sau
                            khi trừ chiết khấu khuyến mại.
                        </p>
                    </div>
                </FormSection>

                <FormSection title="Giới hạn sử dụng">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <LimitField
                            label="Số lần mỗi mã được sử dụng"
                            value={data.uses_per_code}
                            error={errors.uses_per_code}
                            onChange={(value) =>
                                form.setData('uses_per_code', value)
                            }
                        />
                        <LimitField
                            label="Số lần mỗi khách hàng được sử dụng"
                            value={data.uses_per_customer}
                            error={errors.uses_per_customer}
                            onChange={(value) =>
                                form.setData('uses_per_customer', value)
                            }
                        />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={data.combinable}
                            onCheckedChange={(checked) =>
                                form.setData('combinable', checked === true)
                            }
                        />
                        Dùng chung với chương trình khuyến mại khác
                    </label>
                </FormSection>

                {batch && <CouponCodes batch={batch} editable={editable} />}
            </DocumentForm>
        </>
    );
}

function LimitField({
    label,
    value,
    error,
    onChange,
}: {
    label: string;
    value: number | null;
    error?: string;
    onChange: (value: number | null) => void;
}) {
    return (
        <Field label={label} error={error}>
            <MoneyInput
                value={value}
                disabled={value === null}
                placeholder="Không giới hạn"
                onValueChange={onChange}
            />
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={value === null}
                    onCheckedChange={(checked) => onChange(checked ? null : 1)}
                />
                Không giới hạn
            </label>
        </Field>
    );
}

/** "Danh sách mã giảm giá" of a batch: add one, generate many, stop, delete. */
function CouponCodes({ batch, editable }: { batch: Batch; editable: boolean }) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const form = useForm({
        mode: 'manual' as 'manual' | 'generate',
        code: '',
        count: 10 as number | null,
        prefix: '',
        length: 8 as number | null,
    });
    const shown = batch.coupons.filter((coupon) =>
        coupon.code.includes(term.trim().toUpperCase()),
    );

    return (
        <FormSection
            title={`Danh sách mã giảm giá (${formatNumber(batch.coupons_count)})`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    value={term}
                    placeholder="Tìm mã giảm giá"
                    className="max-w-xs"
                    onChange={(event) => setTerm(event.target.value)}
                />
                {editable && (
                    <Button
                        type="button"
                        variant="outline"
                        className="ml-auto"
                        onClick={() => setOpen(true)}
                    >
                        <Plus /> Thêm mã giảm giá
                    </Button>
                )}
            </div>
            {batch.coupons.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Đợt phát hành chưa có mã. Thêm mã để khách hàng sử dụng.
                </p>
            ) : (
                <div className="max-h-96 overflow-y-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Mã</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Đã dùng
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Trạng thái
                                </th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {shown.map((coupon) => (
                                <tr key={coupon.id}>
                                    <td className="px-3 py-2 font-mono">
                                        {coupon.code}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {formatNumber(coupon.used_count)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {coupon.is_active ? (
                                            <Pill tone="success">
                                                Đang hoạt động
                                            </Pill>
                                        ) : (
                                            <Pill>Ngừng</Pill>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">
                                        {editable && (
                                            <span className="inline-flex gap-3">
                                                <button
                                                    type="button"
                                                    className="text-primary hover:underline"
                                                    onClick={() =>
                                                        router.put(
                                                            `/coupons/${batch.id}/codes/${coupon.id}`,
                                                            {
                                                                is_active:
                                                                    !coupon.is_active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {coupon.is_active
                                                        ? 'Ngừng'
                                                        : 'Kích hoạt'}
                                                </button>
                                                {coupon.used_count === 0 && (
                                                    <button
                                                        type="button"
                                                        className="text-destructive hover:underline"
                                                        onClick={() =>
                                                            router.delete(
                                                                `/coupons/${batch.id}/codes/${coupon.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Xóa
                                                    </button>
                                                )}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <RecordDialog
                open={open}
                onOpenChange={setOpen}
                title="Thêm mã giảm giá"
                processing={form.processing}
                onSubmit={() =>
                    form.post(`/coupons/${batch.id}/codes`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset('code');
                            setOpen(false);
                        },
                    })
                }
            >
                <div className="flex gap-6">
                    <Radio
                        checked={form.data.mode === 'manual'}
                        onChange={() => form.setData('mode', 'manual')}
                    >
                        Nhập mã
                    </Radio>
                    <Radio
                        checked={form.data.mode === 'generate'}
                        onChange={() => form.setData('mode', 'generate')}
                    >
                        Tạo mã ngẫu nhiên
                    </Radio>
                </div>
                {form.data.mode === 'manual' ? (
                    <Field label="Mã giảm giá *" error={form.errors.code}>
                        <Input
                            autoFocus
                            value={form.data.code}
                            placeholder="Ví dụ: KHACHMOI"
                            className="uppercase"
                            onChange={(event) =>
                                form.setData('code', event.target.value)
                            }
                        />
                    </Field>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Số lượng mã *" error={form.errors.count}>
                            <MoneyInput
                                value={form.data.count}
                                onValueChange={(value) =>
                                    form.setData('count', value)
                                }
                            />
                        </Field>
                        <Field label="Tiền tố" error={form.errors.prefix}>
                            <Input
                                value={form.data.prefix}
                                placeholder="VD: SALE"
                                className="uppercase"
                                onChange={(event) =>
                                    form.setData('prefix', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Độ dài phần ngẫu nhiên"
                            error={form.errors.length}
                        >
                            <MoneyInput
                                value={form.data.length}
                                onValueChange={(value) =>
                                    form.setData('length', value)
                                }
                            />
                        </Field>
                    </div>
                )}
            </RecordDialog>
        </FormSection>
    );
}

CouponForm.layout = {
    breadcrumbs: [
        { title: 'Khuyến mại', href: '/promotions' },
        { title: 'Quản lý mã giảm giá', href: '/coupons' },
        { title: 'Chi tiết', href: '#' },
    ],
};
