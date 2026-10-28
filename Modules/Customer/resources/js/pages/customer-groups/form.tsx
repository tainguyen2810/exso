import { Head, useForm } from '@inertiajs/react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import { PercentInput } from '@/components/app/percent-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

type Group = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_policy: string;
    discount_bp: number;
    payment_method_id: number | null;
    is_default: boolean;
    customers_count: number;
};

/** Thêm / sửa nhóm khách hàng: giá, chiết khấu, thanh toán mặc định. */
export default function CustomerGroupForm({
    group,
    pricePolicies,
    paymentMethods,
}: {
    group: Group | null;
    pricePolicies: SelectOption[];
    paymentMethods: { id: number; name: string }[];
}) {
    const form = useForm({
        name: group?.name ?? '',
        code: group?.code ?? '',
        description: group?.description ?? '',
        price_policy: group?.price_policy ?? 'retail_price',
        discount_bp: group?.discount_bp ?? 0,
        payment_method_id: group?.payment_method_id ?? null,
    });

    const submit = () => {
        if (group) {
            form.put(`/customer-groups/${group.id}`);
        } else {
            form.post('/customer-groups');
        }
    };

    const title = group ? group.name : 'Thêm nhóm khách hàng';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/customer-groups"
                backLabel="Quay lại danh sách nhóm khách hàng"
                onSubmit={submit}
                processing={form.processing}
                actions={
                    group &&
                    !group.is_default && (
                        <ConfirmDelete
                            url={`/customer-groups/${group.id}`}
                            title={`Xóa nhóm ${group.name}?`}
                            description="Chỉ xóa được nhóm không còn khách hàng."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa nhóm
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <FormSection
                        title="Cài đặt nâng cao"
                        description="Đơn hàng mới của khách trong nhóm sẽ tự áp dụng các giá trị này."
                    >
                        <Field
                            label="Giá mặc định *"
                            error={form.errors.price_policy}
                        >
                            <OptionSelect
                                value={form.data.price_policy}
                                onChange={(value) =>
                                    form.setData(
                                        'price_policy',
                                        value ?? 'retail_price',
                                    )
                                }
                                options={pricePolicies}
                            />
                        </Field>
                        <Field
                            label="Chiết khấu (%)"
                            error={form.errors.discount_bp}
                        >
                            <PercentInput
                                value={form.data.discount_bp}
                                placeholder="0"
                                onValueChange={(value) =>
                                    form.setData('discount_bp', value ?? 0)
                                }
                            />
                        </Field>
                        <Field
                            label="Hình thức thanh toán"
                            error={form.errors.payment_method_id}
                        >
                            <OptionSelect
                                value={
                                    form.data.payment_method_id
                                        ? String(form.data.payment_method_id)
                                        : null
                                }
                                onChange={(value) =>
                                    form.setData(
                                        'payment_method_id',
                                        value ? Number(value) : null,
                                    )
                                }
                                options={toSelectOptions(paymentMethods)}
                                emptyLabel="Không chọn"
                            />
                        </Field>
                    </FormSection>
                }
            >
                <FormSection title="Thông tin chung">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tên nhóm *" error={form.errors.name}>
                            <Input
                                autoFocus={!group}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Mã nhóm" error={form.errors.code}>
                            <Input
                                value={form.data.code}
                                placeholder="Để trống để tự sinh"
                                onChange={(event) =>
                                    form.setData('code', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Mô tả" error={form.errors.description}>
                        <Textarea
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                    {group && (
                        <p className="text-sm text-muted-foreground">
                            Nhóm đang có {group.customers_count} khách hàng.
                        </p>
                    )}
                </FormSection>
            </DocumentForm>
        </>
    );
}

CustomerGroupForm.layout = {
    breadcrumbs: [
        { title: 'Khách hàng', href: '/customers' },
        { title: 'Nhóm khách hàng', href: '/customer-groups' },
        { title: 'Chi tiết', href: '#' },
    ],
};
