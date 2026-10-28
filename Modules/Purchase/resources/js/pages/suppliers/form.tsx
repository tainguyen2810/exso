import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { Pill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Supplier = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    tax_code: string | null;
    contact_name: string | null;
    note: string | null;
    status: string;
    debt: number;
    receipts_count: number;
    receipts_total: number;
    created_at: string | null;
};

type Receipt = {
    id: number;
    code: string;
    branch: string;
    status: string;
    payment_status: string;
    total: number;
    created_at: string | null;
};

type DebtLine = {
    id: number;
    type: string;
    amount: number;
    debt_after: number;
    reference_code: string | null;
    payment_method: string | null;
    note: string | null;
    creator: string | null;
    created_at: string | null;
};

const receiptStatus: Record<
    string,
    [string, 'success' | 'warning' | 'neutral']
> = {
    draft: ['Chưa nhập kho', 'warning'],
    received: ['Đã nhập kho', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

/** Supplier detail like Sapo: info on the left, debt and history below. */
export default function SupplierForm({
    supplier,
    receipts = [],
    debts = [],
}: {
    supplier: Supplier | null;
    receipts?: Receipt[];
    debts?: DebtLine[];
}) {
    const can = useCan();
    const [tab, setTab] = useState<'receipts' | 'debts'>('receipts');
    const form = useForm({
        code: supplier?.code ?? '',
        name: supplier?.name ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        address: supplier?.address ?? '',
        tax_code: supplier?.tax_code ?? '',
        contact_name: supplier?.contact_name ?? '',
        note: supplier?.note ?? '',
        status: supplier?.status ?? 'active',
    });
    const editable = supplier
        ? can('suppliers.update')
        : can('suppliers.create');

    const submit = () => {
        if (supplier) {
            form.put(`/suppliers/${supplier.id}`, { preserveScroll: true });
        } else {
            form.post('/suppliers');
        }
    };

    const title = supplier ? supplier.name : 'Thêm nhà cung cấp';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/suppliers"
                backLabel="Quay lại danh sách nhà cung cấp"
                onSubmit={submit}
                processing={form.processing}
                readOnly={
                    editable ? null : 'Bạn chỉ có quyền xem nhà cung cấp.'
                }
                actions={
                    supplier &&
                    can('suppliers.delete') && (
                        <ConfirmDelete
                            url={`/suppliers/${supplier.id}`}
                            title={`Xóa nhà cung cấp ${supplier.name}?`}
                            description="Chỉ xóa được nhà cung cấp chưa có phiếu nhập và không còn công nợ. Nếu không giao dịch nữa, hãy chuyển sang Ngừng giao dịch."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa nhà cung cấp
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        {supplier && (
                            <FormSection title="Công nợ">
                                <div className="grid gap-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Nợ hiện tại
                                        </span>
                                        <span
                                            className={cn(
                                                'font-medium tabular-nums',
                                                supplier.debt > 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {formatNumber(supplier.debt)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Tổng nhập hàng
                                        </span>
                                        <span className="tabular-nums">
                                            {formatNumber(
                                                supplier.receipts_total,
                                            )}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Số phiếu nhập
                                        </span>
                                        <span className="tabular-nums">
                                            {formatNumber(
                                                supplier.receipts_count,
                                            )}
                                        </span>
                                    </div>
                                </div>
                            </FormSection>
                        )}
                        <FormSection title="Thông tin bổ sung">
                            <Field
                                label="Mã nhà cung cấp"
                                error={form.errors.code}
                            >
                                <Input
                                    value={form.data.code}
                                    onChange={(event) =>
                                        form.setData('code', event.target.value)
                                    }
                                    placeholder="Để trống để tự sinh (NCC00001)"
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
                            {supplier && (
                                <p className="text-xs text-muted-foreground">
                                    Ngày tạo{' '}
                                    {formatDateTime(supplier.created_at)}
                                </p>
                            )}
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Thông tin chung">
                    <Field label="Tên nhà cung cấp *" error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            autoFocus={!supplier}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Số điện thoại" error={form.errors.phone}>
                            <Input
                                value={form.data.phone}
                                onChange={(event) =>
                                    form.setData('phone', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Email" error={form.errors.email}>
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Mã số thuế" error={form.errors.tax_code}>
                            <Input
                                value={form.data.tax_code}
                                onChange={(event) =>
                                    form.setData('tax_code', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Người liên hệ"
                            error={form.errors.contact_name}
                        >
                            <Input
                                value={form.data.contact_name}
                                onChange={(event) =>
                                    form.setData(
                                        'contact_name',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Địa chỉ" error={form.errors.address}>
                        <Input
                            value={form.data.address}
                            onChange={(event) =>
                                form.setData('address', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Ghi chú" error={form.errors.note}>
                        <Textarea
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                </FormSection>
                {supplier && (
                    <Card className="gap-0 overflow-hidden py-0">
                        <div className="flex gap-6 border-b px-5">
                            {(
                                [
                                    ['receipts', 'Lịch sử nhập hàng'],
                                    ['debts', 'Công nợ'],
                                ] as const
                            ).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    className={cn(
                                        '-mb-px border-b-2 py-3 text-sm',
                                        tab === key
                                            ? 'border-primary font-medium text-primary'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {tab === 'receipts' ? (
                            receipts.length === 0 ? (
                                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                    Chưa có phiếu nhập hàng.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="pl-5">
                                                Mã phiếu
                                            </TableHead>
                                            <TableHead>Ngày tạo</TableHead>
                                            <TableHead>Chi nhánh</TableHead>
                                            <TableHead>Trạng thái</TableHead>
                                            <TableHead className="pr-5 text-right">
                                                Giá trị
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {receipts.map((receipt) => (
                                            <TableRow key={receipt.id}>
                                                <TableCell className="pl-5">
                                                    <Link
                                                        href={`/purchase-receipts/${receipt.id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {receipt.code}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {formatDateTime(
                                                        receipt.created_at,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {receipt.branch}
                                                </TableCell>
                                                <TableCell>
                                                    <Pill
                                                        tone={
                                                            receiptStatus[
                                                                receipt.status
                                                            ]?.[1]
                                                        }
                                                    >
                                                        {
                                                            receiptStatus[
                                                                receipt.status
                                                            ]?.[0]
                                                        }
                                                    </Pill>
                                                </TableCell>
                                                <TableCell className="pr-5 text-right tabular-nums">
                                                    {formatNumber(
                                                        receipt.total,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )
                        ) : debts.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                Chưa phát sinh công nợ.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-5">
                                            Thời gian
                                        </TableHead>
                                        <TableHead>Nghiệp vụ</TableHead>
                                        <TableHead>Chứng từ</TableHead>
                                        <TableHead className="text-right">
                                            Giá trị
                                        </TableHead>
                                        <TableHead className="pr-5 text-right">
                                            Nợ sau
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {debts.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="pl-5">
                                                {formatDateTime(
                                                    line.created_at,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.type}
                                                {line.payment_method && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {line.payment_method}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.reference_code}
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'text-right tabular-nums',
                                                    line.amount > 0
                                                        ? 'text-destructive'
                                                        : 'text-success',
                                                )}
                                            >
                                                {line.amount > 0 ? '+' : ''}
                                                {formatNumber(line.amount)}
                                            </TableCell>
                                            <TableCell className="pr-5 text-right tabular-nums">
                                                {formatNumber(line.debt_after)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Card>
                )}
            </DocumentForm>
        </>
    );
}

SupplierForm.layout = {
    breadcrumbs: [
        { title: 'Nhà cung cấp', href: '/suppliers' },
        { title: 'Chi tiết', href: '#' },
    ],
};
