import { Head, Link, useForm } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { CustomerPicker } from '@/components/app/customer-picker';
import type { CustomerOption } from '@/components/app/customer-picker';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { StatusPill } from '@/components/app/status-badge';
import { SupplierPicker } from '@/components/app/supplier-picker';
import type { SupplierOption } from '@/components/app/supplier-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { kindText, voucherStatuses } from '../../components/statuses';
import type { VoucherKind } from '../../components/statuses';

type Voucher = {
    id: number;
    kind: VoucherKind;
    code: string;
    type_id: number;
    type: string;
    status: string;
    is_auto: boolean;
    party_type: string;
    party_id: number | null;
    party_name: string;
    party_url: string | null;
    amount: number;
    method_name: string;
    reference: string | null;
    document_code: string | null;
    document_url: string | null;
    changes_debt: boolean;
    affects_profit: boolean;
    description: string | null;
    tags: string | null;
    branch: string;
    creator: string | null;
    canceller: string | null;
    recorded_at: string;
    created_at: string | null;
    updated_at: string | null;
    cancelled_at: string | null;
};

type VoucherType = {
    id: number;
    name: string;
    code: string;
    affects_profit: boolean;
};

type Option = { id: number; name: string };

/**
 * Tạo phiếu thu / phiếu chi, or view and edit one, laid out like Sapo:
 * "Thông tin chung" (who, type) and "Giá trị ghi nhận" (amount, method),
 * branch / date / note on the right.
 */
export default function CashVoucherForm({
    kind,
    voucher,
    types,
    branches,
    defaultBranch,
    paymentMethods,
    staff,
    canChangeDate,
    parties,
}: {
    kind: VoucherKind;
    voucher: Voucher | null;
    types: VoucherType[];
    branches: Option[];
    defaultBranch: number | null;
    paymentMethods: (Option & { type: string })[];
    staff: Option[];
    canChangeDate: boolean;
    parties: Record<string, string>;
}) {
    const can = useCan();
    const text = kindText(kind);
    const [customer, setCustomer] = useState<CustomerOption | null>(null);
    const [supplier, setSupplier] = useState<SupplierOption | null>(null);
    const cashMethod = paymentMethods.find((method) => method.type === 'cash');

    const form = useForm({
        kind,
        branch_id: (defaultBranch ?? branches[0]?.id ?? null) as number | null,
        type_id: voucher?.type_id ?? null,
        party_type: voucher?.party_type ?? 'customer',
        party_id: voucher?.party_id ?? null,
        party_name: voucher?.party_name ?? '',
        amount: voucher?.amount ?? null,
        payment_method_id: (cashMethod?.id ?? paymentMethods[0]?.id ?? null) as
            | number
            | null,
        reference: voucher?.reference ?? '',
        changes_debt: voucher?.changes_debt ?? true,
        affects_profit: voucher?.affects_profit ?? false,
        description: voucher?.description ?? '',
        tags: voucher?.tags ?? '',
        recorded_at: voucher ? formatDate(voucher.recorded_at) : '',
    });

    const editing = voucher !== null;
    const readOnly = voucher
        ? voucher.is_auto
            ? `Phiếu được tạo tự động từ ${voucher.document_code ?? 'chứng từ khác'}, chỉ thay đổi theo chứng từ gốc.`
            : voucher.status === 'cancelled'
              ? `Phiếu đã hủy${voucher.cancelled_at ? ` lúc ${formatDateTime(voucher.cancelled_at)}` : ''}${voucher.canceller ? ` bởi ${voucher.canceller}` : ''}.`
              : !can('cashbook.update')
                ? 'Bạn không có quyền sửa phiếu.'
                : null
        : null;
    const debtParty =
        form.data.party_type === 'customer' ||
        form.data.party_type === 'supplier';

    const submit = () => {
        if (voucher) {
            form.put(`/cashbook/vouchers/${voucher.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/cashbook/vouchers');
        }
    };

    const setParty = (type: string) => {
        form.setData((data) => ({
            ...data,
            party_type: type,
            party_id: null,
            party_name: '',
        }));
        setCustomer(null);
        setSupplier(null);
    };

    const setType = (value: string | null) => {
        const type = types.find((item) => String(item.id) === value);
        form.setData((data) => ({
            ...data,
            type_id: type?.id ?? null,
            affects_profit: type?.affects_profit ?? false,
        }));
    };

    const title = voucher ? voucher.code : `Tạo ${text.name}`;

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref={text.list}
                backLabel={`Quay lại danh sách ${text.name}`}
                onSubmit={submit}
                processing={form.processing}
                isDirty={!editing || form.isDirty}
                submitLabel={editing ? 'Lưu thay đổi' : 'Lưu'}
                readOnly={readOnly}
                actions={
                    voucher &&
                    can('cashbook.cancel') && (
                        <ConfirmAction
                            url={`/cashbook/vouchers/${voucher.id}/cancel`}
                            title={`Hủy ${text.name} ${voucher.code}?`}
                            description={
                                voucher.changes_debt
                                    ? `Phiếu sẽ không còn tính vào sổ quỹ và công nợ của ${voucher.party_name} được hoàn lại như trước.`
                                    : 'Phiếu sẽ không còn tính vào sổ quỹ. Thao tác không hoàn tác được.'
                            }
                            confirmLabel="Hủy phiếu"
                            destructive
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Hủy phiếu
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        <FormSection title="Thông tin bổ sung">
                            {voucher ? (
                                <dl className="grid grid-cols-[110px_1fr] gap-y-2 text-sm">
                                    <dt className="text-muted-foreground">
                                        Chi nhánh
                                    </dt>
                                    <dd>{voucher.branch}</dd>
                                    <dt className="text-muted-foreground">
                                        Nhân viên tạo
                                    </dt>
                                    <dd>{voucher.creator ?? '—'}</dd>
                                    <dt className="text-muted-foreground">
                                        Ngày tạo
                                    </dt>
                                    <dd>
                                        {formatDateTime(voucher.created_at)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Ngày ghi nhận
                                    </dt>
                                    <dd>
                                        {formatDateTime(voucher.recorded_at)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Ngày cập nhật
                                    </dt>
                                    <dd>
                                        {formatDateTime(voucher.updated_at)}
                                    </dd>
                                </dl>
                            ) : (
                                <Field
                                    label="Chi nhánh *"
                                    error={form.errors.branch_id}
                                >
                                    <OptionSelect
                                        value={
                                            form.data.branch_id
                                                ? String(form.data.branch_id)
                                                : null
                                        }
                                        onChange={(value) =>
                                            form.setData(
                                                'branch_id',
                                                value ? Number(value) : null,
                                            )
                                        }
                                        options={toSelectOptions(branches)}
                                    />
                                </Field>
                            )}
                            {canChangeDate && !voucher?.is_auto && (
                                <Field
                                    label="Ngày ghi nhận"
                                    error={form.errors.recorded_at}
                                >
                                    <Input
                                        value={form.data.recorded_at}
                                        placeholder="dd/mm/yyyy (để trống = hôm nay)"
                                        onChange={(event) =>
                                            form.setData(
                                                'recorded_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                        </FormSection>
                        <FormSection title="Mô tả">
                            <Textarea
                                value={form.data.description}
                                rows={3}
                                placeholder="Nội dung thu chi"
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                            <Field label="Tags" error={form.errors.tags}>
                                <Input
                                    value={form.data.tags}
                                    placeholder="Cách nhau bởi dấu phẩy"
                                    onChange={(event) =>
                                        form.setData('tags', event.target.value)
                                    }
                                />
                            </Field>
                        </FormSection>
                    </>
                }
            >
                {voucher && (
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusPill
                            statuses={voucherStatuses}
                            value={voucher.status}
                        />
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={`/cashbook/vouchers/${voucher.id}/print`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer /> In {text.name}
                            </a>
                        </Button>
                    </div>
                )}
                <FormSection title="Thông tin chung">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={`Nhóm ${text.party} *`}
                            error={form.errors.party_type}
                        >
                            <OptionSelect
                                value={form.data.party_type}
                                onChange={(value) => setParty(value ?? 'other')}
                                options={Object.entries(parties).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                                disabled={editing}
                            />
                        </Field>
                        <Field
                            label={`Tên ${text.party} *`}
                            error={
                                form.errors.party_id ?? form.errors.party_name
                            }
                        >
                            {editing ? (
                                voucher?.party_url ? (
                                    <Link
                                        href={voucher.party_url}
                                        className="flex h-9 items-center text-primary hover:underline"
                                    >
                                        {voucher.party_name}
                                    </Link>
                                ) : (
                                    <Input
                                        value={voucher?.party_name}
                                        disabled
                                    />
                                )
                            ) : form.data.party_type === 'customer' ? (
                                <CustomerPicker
                                    value={customer}
                                    compact
                                    placeholder="Chọn khách hàng (F4)"
                                    onChange={(value) => {
                                        setCustomer(value);
                                        form.setData(
                                            'party_id',
                                            value?.id ?? null,
                                        );
                                    }}
                                />
                            ) : form.data.party_type === 'supplier' ? (
                                <SupplierPicker
                                    value={supplier}
                                    onChange={(value) => {
                                        setSupplier(value);
                                        form.setData(
                                            'party_id',
                                            value?.id ?? null,
                                        );
                                    }}
                                />
                            ) : form.data.party_type === 'staff' ? (
                                <OptionSelect
                                    value={
                                        form.data.party_id
                                            ? String(form.data.party_id)
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'party_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(staff)}
                                    placeholder="Chọn nhân viên"
                                />
                            ) : (
                                <Input
                                    value={form.data.party_name}
                                    placeholder={`Nhập tên ${text.party}`}
                                    onChange={(event) =>
                                        form.setData(
                                            'party_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </Field>
                        <Field
                            label={`${text.typeName} *`}
                            error={form.errors.type_id}
                        >
                            <OptionSelect
                                value={
                                    form.data.type_id
                                        ? String(form.data.type_id)
                                        : null
                                }
                                onChange={setType}
                                options={toSelectOptions(types)}
                                placeholder={`Chọn ${text.typeName.toLowerCase()}`}
                            />
                        </Field>
                        <Field label="Mã phiếu">
                            <Input
                                value={voucher?.code ?? ''}
                                placeholder="Tự sinh khi lưu"
                                disabled
                            />
                        </Field>
                    </div>
                </FormSection>
                <FormSection title="Giá trị ghi nhận">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Giá trị *" error={form.errors.amount}>
                            <MoneyInput
                                value={form.data.amount}
                                onValueChange={(value) =>
                                    form.setData('amount', value)
                                }
                                disabled={editing}
                            />
                        </Field>
                        <Field
                            label="Hình thức thanh toán"
                            error={form.errors.payment_method_id}
                        >
                            {editing ? (
                                <Input value={voucher?.method_name} disabled />
                            ) : (
                                <OptionSelect
                                    value={
                                        form.data.payment_method_id
                                            ? String(
                                                  form.data.payment_method_id,
                                              )
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'payment_method_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(paymentMethods)}
                                />
                            )}
                        </Field>
                        <Field label="Tham chiếu" error={form.errors.reference}>
                            <Input
                                value={form.data.reference}
                                maxLength={100}
                                onChange={(event) =>
                                    form.setData(
                                        'reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    {debtParty && (
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={form.data.changes_debt}
                                disabled={editing}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'changes_debt',
                                        checked === true,
                                    )
                                }
                            />
                            Thay đổi công nợ đối tượng{' '}
                            {kind === 'receipt' ? 'nộp' : 'nhận'}
                            {customer && !editing && (
                                <span className="text-muted-foreground">
                                    (nợ hiện tại {formatNumber(customer.debt)})
                                </span>
                            )}
                            {supplier && !editing && (
                                <span className="text-muted-foreground">
                                    (nợ hiện tại {formatNumber(supplier.debt)})
                                </span>
                            )}
                        </Label>
                    )}
                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox
                            checked={form.data.affects_profit}
                            onCheckedChange={(checked) =>
                                form.setData('affects_profit', checked === true)
                            }
                        />
                        Hạch toán kết quả kinh doanh
                    </Label>
                    {voucher?.is_auto ? (
                        <p className="text-sm">
                            Phiếu được tạo tự động.{' '}
                            {voucher.document_url && (
                                <Link
                                    href={voucher.document_url}
                                    className="text-primary hover:underline"
                                >
                                    Xem chứng từ gốc ({voucher.document_code})
                                </Link>
                            )}
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Phiếu có hạch toán kết quả kinh doanh được tính vào
                            báo cáo lãi lỗ (thu nhập khác / chi phí khác). Mặc
                            định theo loại phiếu.
                        </p>
                    )}
                </FormSection>
            </DocumentForm>
        </>
    );
}

CashVoucherForm.layout = (props: {
    kind: VoucherKind;
    voucher: Voucher | null;
}) => ({
    breadcrumbs: [
        { title: 'Sổ quỹ', href: '/cashbook' },
        { title: kindText(props.kind).title, href: kindText(props.kind).list },
        {
            title: props.voucher?.code ?? `Tạo ${kindText(props.kind).name}`,
            href: '#',
        },
    ],
});
