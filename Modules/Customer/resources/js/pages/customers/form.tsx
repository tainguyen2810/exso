import { Head, useForm } from '@inertiajs/react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { PercentInput } from '@/components/app/percent-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';

type Customer = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    gender: string | null;
    birthday: string | null;
    tax_code: string | null;
    website: string | null;
    address: string | null;
    province: string | null;
    district: string | null;
    ward: string | null;
    description: string | null;
    tags: string | null;
    group_id: number | null;
    assigned_to: number | null;
    price_policy: string | null;
    discount_bp: number | null;
    payment_method_id: number | null;
    marketplace_id: string | null;
    source: string | null;
    status: string;
};

type Option = { id: number; name: string };

/** Thêm / sửa khách hàng, laid out like Sapo's customer form. */
export default function CustomerForm({
    customer,
    groups,
    staff,
    pricePolicies,
    paymentMethods,
    genders,
}: {
    customer: Customer | null;
    groups: Option[];
    staff: Option[];
    pricePolicies: SelectOption[];
    paymentMethods: Option[];
    genders: SelectOption[];
}) {
    const can = useCan();
    const form = useForm({
        code: customer?.code ?? '',
        name: customer?.name ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        gender: customer?.gender ?? null,
        birthday: customer?.birthday ?? '',
        tax_code: customer?.tax_code ?? '',
        website: customer?.website ?? '',
        address: customer?.address ?? '',
        province: customer?.province ?? '',
        district: customer?.district ?? '',
        ward: customer?.ward ?? '',
        description: customer?.description ?? '',
        tags: customer?.tags ?? '',
        group_id: customer?.group_id ?? null,
        assigned_to: customer?.assigned_to ?? null,
        price_policy: customer?.price_policy ?? null,
        discount_bp: customer?.discount_bp ?? null,
        payment_method_id: customer?.payment_method_id ?? null,
        marketplace_id: customer?.marketplace_id ?? '',
        status: customer?.status ?? 'active',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = () => {
        if (customer) {
            form.put(`/customers/${customer.id}`, { preserveScroll: true });
        } else {
            form.post('/customers');
        }
    };

    const text = (key: keyof typeof form.data, label: string, props = {}) => (
        <Field label={label} error={errors[key]}>
            <Input
                value={(form.data[key] as string | null) ?? ''}
                onChange={(event) => form.setData(key, event.target.value)}
                {...props}
            />
        </Field>
    );

    const title = customer
        ? `Sửa khách hàng ${customer.name}`
        : 'Thêm mới khách hàng';
    const back = customer ? `/customers/${customer.id}` : '/customers';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref={back}
                backLabel={
                    customer
                        ? 'Quay lại chi tiết khách hàng'
                        : 'Quay lại danh sách khách hàng'
                }
                onSubmit={submit}
                processing={form.processing}
                actions={
                    customer &&
                    can('customers.delete') && (
                        <ConfirmDelete
                            url={`/customers/${customer.id}`}
                            title={`Xóa khách hàng ${customer.name}?`}
                            description="Chỉ xóa được khách hàng không còn công nợ. Lịch sử đơn hàng vẫn giữ tên khách."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa khách hàng
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        <FormSection title="Thông tin bổ sung">
                            {text('code', 'Mã khách hàng', {
                                placeholder: 'Để trống để tự sinh (CUZN00001)',
                            })}
                            <Field
                                label="Nhóm khách hàng"
                                error={errors.group_id}
                            >
                                <OptionSelect
                                    value={
                                        form.data.group_id
                                            ? String(form.data.group_id)
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'group_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(groups)}
                                    emptyLabel="Nhóm mặc định"
                                />
                            </Field>
                            <Field
                                label="Nhân viên phụ trách"
                                error={errors.assigned_to}
                            >
                                <OptionSelect
                                    value={
                                        form.data.assigned_to
                                            ? String(form.data.assigned_to)
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'assigned_to',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(staff)}
                                    emptyLabel="Chưa có"
                                />
                            </Field>
                            {text('tags', 'Tag', {
                                placeholder: 'Cách nhau bởi dấu phẩy',
                            })}
                            <Field label="Ghi chú" error={errors.description}>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <label className="flex items-center justify-between gap-3 text-sm">
                                Đang giao dịch
                                <Switch
                                    checked={form.data.status === 'active'}
                                    onCheckedChange={(on) =>
                                        form.setData(
                                            'status',
                                            on ? 'active' : 'inactive',
                                        )
                                    }
                                />
                            </label>
                        </FormSection>
                        <FormSection
                            title="Thông tin gợi ý khi bán hàng"
                            description="Để trống thì dùng thiết lập của nhóm khách hàng."
                        >
                            <Field
                                label="Chính sách giá mặc định"
                                error={errors.price_policy}
                            >
                                <OptionSelect
                                    value={form.data.price_policy}
                                    onChange={(value) =>
                                        form.setData('price_policy', value)
                                    }
                                    options={pricePolicies}
                                    emptyLabel="Theo nhóm khách hàng"
                                />
                            </Field>
                            <Field
                                label="Chiết khấu mặc định (%)"
                                error={errors.discount_bp}
                            >
                                <PercentInput
                                    value={form.data.discount_bp}
                                    placeholder="Theo nhóm khách hàng"
                                    onValueChange={(value) =>
                                        form.setData('discount_bp', value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Hình thức thanh toán mặc định"
                                error={errors.payment_method_id}
                            >
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
                                    emptyLabel="Theo nhóm khách hàng"
                                />
                            </Field>
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Thông tin chung">
                    {text('name', 'Tên khách hàng *', { autoFocus: !customer })}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('phone', 'Số điện thoại', { inputMode: 'tel' })}
                        {text('email', 'Email', { type: 'email' })}
                        <Field label="Giới tính" error={errors.gender}>
                            <OptionSelect
                                value={form.data.gender}
                                onChange={(value) =>
                                    form.setData('gender', value)
                                }
                                options={genders}
                                emptyLabel="Chưa chọn"
                            />
                        </Field>
                        {text('birthday', 'Ngày sinh', {
                            placeholder: 'dd/mm/yyyy',
                        })}
                        {text('tax_code', 'Mã số thuế')}
                        {text('website', 'Website')}
                    </div>
                    {customer?.marketplace_id && (
                        <p className="text-sm text-muted-foreground">
                            Khách từ sàn {customer.source ?? ''}, mã trên sàn:{' '}
                            {customer.marketplace_id}
                        </p>
                    )}
                </FormSection>
                <FormSection title="Địa chỉ">
                    {text('address', 'Địa chỉ', {
                        placeholder: 'Số nhà, tên đường',
                    })}
                    <div className="grid gap-4 sm:grid-cols-3">
                        {text('province', 'Tỉnh/Thành phố')}
                        {text('district', 'Quận/Huyện')}
                        {text('ward', 'Phường/Xã')}
                    </div>
                </FormSection>
            </DocumentForm>
        </>
    );
}

CustomerForm.layout = {
    breadcrumbs: [
        { title: 'Khách hàng', href: '/customers' },
        { title: 'Thông tin khách hàng', href: '#' },
    ],
};
