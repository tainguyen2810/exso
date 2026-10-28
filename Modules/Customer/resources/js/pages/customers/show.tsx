import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { RecordDialog } from '@/components/app/settings-page';
import { Pill } from '@/components/app/status-badge';
import type { Tone } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { bpToPercent } from '@/lib/percent';
import { cn } from '@/lib/utils';

type Address = {
    id: number;
    name: string | null;
    phone: string | null;
    address: string;
    province: string | null;
    district: string | null;
    ward: string | null;
    full: string;
    is_default: boolean;
};

type Contact = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    position: string | null;
    note: string | null;
};

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
    marketplace_id: string | null;
    source: string | null;
    status: string;
    group: { id: number; name: string } | null;
    assignee: string | null;
    price_label: string;
    customer_price: string | null;
    discount_bp: number | null;
    payment_method: string | null;
    debt: number;
    total_spent: number;
    orders_count: number;
    returned_quantity: number;
    last_order_at: string | null;
    creator: string | null;
    created_at: string | null;
    addresses: Address[];
    contacts: Contact[];
    notes: {
        id: number;
        content: string;
        creator: string | null;
        created_at: string | null;
    }[];
};

type OrderRow = {
    id: number;
    code: string;
    status: string;
    status_label: string;
    payment_status: string | null;
    exported: boolean;
    total: number;
    branch: string;
    source: string | null;
    assignee: string | null;
    sale_at: string | null;
};

type DebtLine = {
    id: number;
    type: string;
    amount: number;
    debt_after: number;
    reference_code: string | null;
    reference_url: string | null;
    note: string | null;
    creator: string | null;
    created_at: string | null;
};

const orderTone: Record<string, Tone> = {
    pending: 'warning',
    approved: 'info',
    packed: 'info',
    shipped: 'info',
    completed: 'success',
    cancelled: 'neutral',
};

const paymentLabels: Record<string, [string, Tone]> = {
    unpaid: ['Chưa thanh toán', 'danger'],
    partial: ['Thanh toán một phần', 'warning'],
    paid: ['Đã thanh toán', 'success'],
};

const genders: Record<string, string> = {
    male: 'Nam',
    female: 'Nữ',
    other: 'Khác',
};

const tabs = [
    ['orders', 'Lịch sử mua hàng'],
    ['debts', 'Công nợ'],
    ['contacts', 'Liên hệ'],
    ['addresses', 'Địa chỉ'],
    ['notes', 'Ghi chú'],
] as const;

type Tab = (typeof tabs)[number][0];

/** Customer detail like Sapo: profile, purchase summary, then tabs. */
export default function CustomerShow({
    customer,
    orders,
    debts,
}: {
    customer: Customer;
    orders: OrderRow[];
    debts: DebtLine[];
}) {
    const can = useCan();
    const [tab, setTab] = useState<Tab>('orders');
    const editable = can('customers.update');

    return (
        <>
            <Head title={customer.name} />
            <DocumentView
                title={customer.name}
                badges={
                    customer.status === 'active' ? (
                        <Pill tone="success">Đang giao dịch</Pill>
                    ) : (
                        <Pill>Ngừng giao dịch</Pill>
                    )
                }
                meta={`${customer.code}${customer.created_at ? ` · Ngày tạo ${formatDateTime(customer.created_at)}` : ''}${customer.creator ? ` · ${customer.creator}` : ''}`}
                backHref="/customers"
                backLabel="Quay lại danh sách khách hàng"
                actions={
                    <>
                        {editable && (
                            <Button variant="outline" asChild>
                                <Link href={`/customers/${customer.id}/edit`}>
                                    Sửa khách hàng
                                </Link>
                            </Button>
                        )}
                        {can('orders.create') && (
                            <Button asChild>
                                <Link
                                    href={`/orders/create?customer=${customer.id}`}
                                >
                                    Tạo đơn hàng
                                </Link>
                            </Button>
                        )}
                    </>
                }
                aside={
                    <>
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b px-5 py-4">
                                <CardTitle>Thông tin mua hàng</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 px-5 py-4 text-sm">
                                <Line label="Nợ hiện tại">
                                    <span
                                        className={cn(
                                            'font-medium',
                                            customer.debt > 0 &&
                                                'text-destructive',
                                        )}
                                    >
                                        {formatNumber(customer.debt)}
                                    </span>
                                </Line>
                                <Line label="Tổng chi tiêu">
                                    {formatNumber(customer.total_spent)}
                                </Line>
                                <Line label="Tổng SL đơn hàng">
                                    {formatNumber(customer.orders_count)}
                                </Line>
                                <Line label="Tổng SL sản phẩm hoàn trả">
                                    {formatNumber(customer.returned_quantity)}
                                </Line>
                                <Line label="Ngày cuối cùng mua hàng">
                                    {formatDate(customer.last_order_at) || '—'}
                                </Line>
                            </CardContent>
                        </Card>
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b px-5 py-4">
                                <CardTitle>
                                    Thông tin gợi ý khi bán hàng
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 px-5 py-4 text-sm">
                                <Line label="Nhóm khách hàng">
                                    {customer.group?.name ?? '—'}
                                </Line>
                                <Line label="Chính sách giá">
                                    {customer.price_label}
                                </Line>
                                <Line label="Chiết khấu">
                                    {customer.discount_bp != null
                                        ? `${bpToPercent(customer.discount_bp)}%`
                                        : 'Theo nhóm'}
                                </Line>
                                <Line label="Thanh toán">
                                    {customer.payment_method ?? 'Theo nhóm'}
                                </Line>
                                <Line label="Nhân viên phụ trách">
                                    {customer.assignee ?? '—'}
                                </Line>
                            </CardContent>
                        </Card>
                        {customer.tags && (
                            <Card className="gap-2 px-5 py-4">
                                <CardTitle>Tag</CardTitle>
                                <div className="flex flex-wrap gap-1.5">
                                    {customer.tags.split(',').map((tag) => (
                                        <Pill key={tag}>{tag.trim()}</Pill>
                                    ))}
                                </div>
                            </Card>
                        )}
                    </>
                }
            >
                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Thông tin cá nhân</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-x-8 gap-y-2 px-5 py-4 text-sm sm:grid-cols-2">
                        <Line label="Số điện thoại">
                            {customer.phone ?? '—'}
                        </Line>
                        <Line label="Email">{customer.email ?? '—'}</Line>
                        <Line label="Ngày sinh">
                            {customer.birthday ?? '—'}
                        </Line>
                        <Line label="Giới tính">
                            {customer.gender ? genders[customer.gender] : '—'}
                        </Line>
                        <Line label="Mã số thuế">
                            {customer.tax_code ?? '—'}
                        </Line>
                        <Line label="Website">{customer.website ?? '—'}</Line>
                        <div className="sm:col-span-2">
                            <Line label="Địa chỉ">
                                {[
                                    customer.address,
                                    customer.ward,
                                    customer.district,
                                    customer.province,
                                ]
                                    .filter(Boolean)
                                    .join(', ') || '—'}
                            </Line>
                        </div>
                        {customer.marketplace_id && (
                            <div className="sm:col-span-2">
                                <Line label="Mã khách trên sàn">
                                    {customer.marketplace_id}
                                    {customer.source
                                        ? ` (${customer.source})`
                                        : ''}
                                </Line>
                            </div>
                        )}
                        {customer.description && (
                            <p className="whitespace-pre-line text-muted-foreground sm:col-span-2">
                                {customer.description}
                            </p>
                        )}
                    </CardContent>
                </Card>
                <Card className="gap-0 overflow-hidden py-0">
                    <div className="flex gap-6 overflow-x-auto border-b px-5">
                        {tabs.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setTab(key)}
                                className={cn(
                                    '-mb-px border-b-2 py-3 text-sm whitespace-nowrap',
                                    tab === key
                                        ? 'border-primary font-medium text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {tab === 'orders' && <OrdersTab orders={orders} />}
                    {tab === 'debts' && <DebtsTab debts={debts} />}
                    {tab === 'contacts' && (
                        <ContactsTab customer={customer} editable={editable} />
                    )}
                    {tab === 'addresses' && (
                        <AddressesTab customer={customer} editable={editable} />
                    )}
                    {tab === 'notes' && (
                        <NotesTab customer={customer} editable={editable} />
                    )}
                </Card>
            </DocumentView>
        </>
    );
}

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right tabular-nums">{children}</span>
        </div>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

function OrdersTab({ orders }: { orders: OrderRow[] }) {
    if (orders.length === 0) {
        return <Empty>Khách hàng chưa có đơn hàng.</Empty>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead className="pl-5">Mã đơn hàng</TableHead>
                    <TableHead>Trạng thái</TableHead>
                    <TableHead>Thanh toán</TableHead>
                    <TableHead>Xuất kho</TableHead>
                    <TableHead className="text-right">Giá trị</TableHead>
                    <TableHead>Chi nhánh</TableHead>
                    <TableHead>Nguồn</TableHead>
                    <TableHead>NV xử lý</TableHead>
                    <TableHead className="pr-5">Ngày ghi nhận</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {orders.map((order) => (
                    <TableRow key={order.id}>
                        <TableCell className="pl-5">
                            <Link
                                href={`/orders/${order.id}`}
                                className="text-primary hover:underline"
                            >
                                {order.code}
                            </Link>
                        </TableCell>
                        <TableCell>
                            <Pill tone={orderTone[order.status]}>
                                {order.status_label}
                            </Pill>
                        </TableCell>
                        <TableCell>
                            {order.payment_status && (
                                <Pill
                                    tone={
                                        paymentLabels[order.payment_status][1]
                                    }
                                >
                                    {paymentLabels[order.payment_status][0]}
                                </Pill>
                            )}
                        </TableCell>
                        <TableCell>
                            {order.status !== 'cancelled' &&
                                (order.exported ? (
                                    <Pill tone="success">Đã xuất kho</Pill>
                                ) : (
                                    <Pill tone="warning">Chưa xuất kho</Pill>
                                ))}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {formatNumber(order.total)}
                        </TableCell>
                        <TableCell>{order.branch}</TableCell>
                        <TableCell>{order.source}</TableCell>
                        <TableCell>{order.assignee}</TableCell>
                        <TableCell className="pr-5">
                            {formatDateTime(order.sale_at)}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

function DebtsTab({ debts }: { debts: DebtLine[] }) {
    if (debts.length === 0) {
        return <Empty>Chưa phát sinh công nợ.</Empty>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead className="pl-5">Thời gian</TableHead>
                    <TableHead>Nghiệp vụ</TableHead>
                    <TableHead>Chứng từ</TableHead>
                    <TableHead>Người tạo</TableHead>
                    <TableHead className="text-right">
                        Giá trị thay đổi
                    </TableHead>
                    <TableHead className="pr-5 text-right">Công nợ</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {debts.map((line) => (
                    <TableRow key={line.id}>
                        <TableCell className="pl-5">
                            {formatDateTime(line.created_at)}
                        </TableCell>
                        <TableCell>
                            {line.type}
                            {line.note && (
                                <span className="block text-xs text-muted-foreground">
                                    {line.note}
                                </span>
                            )}
                        </TableCell>
                        <TableCell>
                            {line.reference_url ? (
                                <Link
                                    href={line.reference_url}
                                    className="text-primary hover:underline"
                                >
                                    {line.reference_code}
                                </Link>
                            ) : (
                                line.reference_code
                            )}
                        </TableCell>
                        <TableCell>{line.creator}</TableCell>
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
    );
}

function remove(url: string) {
    router.delete(url, { preserveScroll: true });
}

function ContactsTab({
    customer,
    editable,
}: {
    customer: Customer;
    editable: boolean;
}) {
    const [editing, setEditing] = useState<Contact | 'new' | null>(null);
    const form = useForm({
        name: '',
        phone: '',
        email: '',
        position: '',
        note: '',
    });

    const open = (contact: Contact | 'new') => {
        form.clearErrors();
        form.setData(
            contact === 'new'
                ? { name: '', phone: '', email: '', position: '', note: '' }
                : {
                      name: contact.name,
                      phone: contact.phone ?? '',
                      email: contact.email ?? '',
                      position: contact.position ?? '',
                      note: contact.note ?? '',
                  },
        );
        setEditing(contact);
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        };

        if (editing === 'new') {
            form.post(`/customers/${customer.id}/contacts`, options);
        } else if (editing) {
            form.put(
                `/customers/${customer.id}/contacts/${editing.id}`,
                options,
            );
        }
    };

    return (
        <div>
            {customer.contacts.length === 0 ? (
                <Empty>Chưa có người liên hệ.</Empty>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-5">
                                Tên người liên hệ
                            </TableHead>
                            <TableHead>Chức vụ</TableHead>
                            <TableHead>Số điện thoại</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead className="pr-5">Ghi chú</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {customer.contacts.map((contact) => (
                            <TableRow key={contact.id}>
                                <TableCell className="pl-5">
                                    {editable ? (
                                        <button
                                            type="button"
                                            className="text-primary hover:underline"
                                            onClick={() => open(contact)}
                                        >
                                            {contact.name}
                                        </button>
                                    ) : (
                                        contact.name
                                    )}
                                </TableCell>
                                <TableCell>{contact.position}</TableCell>
                                <TableCell>{contact.phone}</TableCell>
                                <TableCell>{contact.email}</TableCell>
                                <TableCell className="pr-5">
                                    {contact.note}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {editable && (
                <div className="border-t px-5 py-3">
                    <Button
                        variant="link"
                        className="px-0"
                        onClick={() => open('new')}
                    >
                        <Plus /> Thêm người liên hệ
                    </Button>
                </div>
            )}
            <RecordDialog
                open={editing !== null}
                onOpenChange={(next) => !next && setEditing(null)}
                title={
                    editing === 'new'
                        ? 'Thêm người liên hệ'
                        : 'Sửa người liên hệ'
                }
                onSubmit={submit}
                processing={form.processing}
                extra={
                    editing &&
                    editing !== 'new' && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => {
                                remove(
                                    `/customers/${customer.id}/contacts/${editing.id}`,
                                );
                                setEditing(null);
                            }}
                        >
                            Xóa
                        </Button>
                    )
                }
            >
                <Field label="Tên người liên hệ *" error={form.errors.name}>
                    <Input
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
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
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                        />
                    </Field>
                </div>
                <Field label="Chức vụ" error={form.errors.position}>
                    <Input
                        value={form.data.position}
                        onChange={(event) =>
                            form.setData('position', event.target.value)
                        }
                    />
                </Field>
                <Field label="Ghi chú" error={form.errors.note}>
                    <Input
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                    />
                </Field>
            </RecordDialog>
        </div>
    );
}

function AddressesTab({
    customer,
    editable,
}: {
    customer: Customer;
    editable: boolean;
}) {
    const [editing, setEditing] = useState<Address | 'new' | null>(null);
    const blank = {
        name: customer.name,
        phone: customer.phone ?? '',
        address: '',
        province: '',
        district: '',
        ward: '',
        is_default: false,
    };
    const form = useForm(blank);

    const open = (address: Address | 'new') => {
        form.clearErrors();
        form.setData(
            address === 'new'
                ? blank
                : {
                      name: address.name ?? '',
                      phone: address.phone ?? '',
                      address: address.address,
                      province: address.province ?? '',
                      district: address.district ?? '',
                      ward: address.ward ?? '',
                      is_default: address.is_default,
                  },
        );
        setEditing(address);
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        };

        if (editing === 'new') {
            form.post(`/customers/${customer.id}/addresses`, options);
        } else if (editing) {
            form.put(
                `/customers/${customer.id}/addresses/${editing.id}`,
                options,
            );
        }
    };

    return (
        <div>
            {customer.addresses.length === 0 ? (
                <Empty>Chưa có địa chỉ giao hàng.</Empty>
            ) : (
                <div className="divide-y">
                    {customer.addresses.map((address) => (
                        <div
                            key={address.id}
                            className="flex items-start gap-3 px-5 py-3 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium">
                                    {address.name}
                                    {address.phone ? ` · ${address.phone}` : ''}
                                </span>
                                {address.is_default && (
                                    <Pill tone="info" className="ml-2">
                                        <Star className="mr-1 size-3" /> Mặc
                                        định
                                    </Pill>
                                )}
                                <p className="text-muted-foreground">
                                    {address.full}
                                </p>
                            </div>
                            {editable && (
                                <Button
                                    variant="link"
                                    className="h-auto px-0"
                                    onClick={() => open(address)}
                                >
                                    Sửa
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            )}
            {editable && (
                <div className="border-t px-5 py-3">
                    <Button
                        variant="link"
                        className="px-0"
                        onClick={() => open('new')}
                    >
                        <Plus /> Thêm địa chỉ
                    </Button>
                </div>
            )}
            <RecordDialog
                open={editing !== null}
                onOpenChange={(next) => !next && setEditing(null)}
                title={editing === 'new' ? 'Thêm địa chỉ' : 'Sửa địa chỉ'}
                onSubmit={submit}
                processing={form.processing}
                extra={
                    editing &&
                    editing !== 'new' && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => {
                                remove(
                                    `/customers/${customer.id}/addresses/${editing.id}`,
                                );
                                setEditing(null);
                            }}
                        >
                            Xóa
                        </Button>
                    )
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Người nhận" error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Số điện thoại" error={form.errors.phone}>
                        <Input
                            value={form.data.phone}
                            onChange={(event) =>
                                form.setData('phone', event.target.value)
                            }
                        />
                    </Field>
                </div>
                <Field label="Địa chỉ *" error={form.errors.address}>
                    <Input
                        value={form.data.address}
                        onChange={(event) =>
                            form.setData('address', event.target.value)
                        }
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Tỉnh/Thành phố">
                        <Input
                            value={form.data.province}
                            onChange={(event) =>
                                form.setData('province', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Quận/Huyện">
                        <Input
                            value={form.data.district}
                            onChange={(event) =>
                                form.setData('district', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Phường/Xã">
                        <Input
                            value={form.data.ward}
                            onChange={(event) =>
                                form.setData('ward', event.target.value)
                            }
                        />
                    </Field>
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.is_default}
                        onChange={(event) =>
                            form.setData('is_default', event.target.checked)
                        }
                    />
                    Đặt làm địa chỉ mặc định
                </label>
            </RecordDialog>
        </div>
    );
}

function NotesTab({
    customer,
    editable,
}: {
    customer: Customer;
    editable: boolean;
}) {
    const form = useForm({ content: '' });

    return (
        <div>
            {editable && (
                <form
                    className="grid gap-2 border-b px-5 py-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/customers/${customer.id}/notes`, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Textarea
                        value={form.data.content}
                        placeholder="Viết ghi chú về khách hàng"
                        onChange={(event) =>
                            form.setData('content', event.target.value)
                        }
                    />
                    {form.errors.content && (
                        <p className="text-sm text-destructive">
                            {form.errors.content}
                        </p>
                    )}
                    <div>
                        <Button
                            size="sm"
                            disabled={
                                form.processing || !form.data.content.trim()
                            }
                        >
                            Thêm ghi chú
                        </Button>
                    </div>
                </form>
            )}
            {customer.notes.length === 0 ? (
                <Empty>Chưa có ghi chú.</Empty>
            ) : (
                <div className="divide-y">
                    {customer.notes.map((note) => (
                        <div
                            key={note.id}
                            className="flex gap-3 px-5 py-3 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="whitespace-pre-line">
                                    {note.content}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {note.creator} ·{' '}
                                    {formatDateTime(note.created_at)}
                                </p>
                            </div>
                            {editable && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 text-muted-foreground"
                                    aria-label="Xóa ghi chú"
                                    onClick={() =>
                                        remove(
                                            `/customers/${customer.id}/notes/${note.id}`,
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

CustomerShow.layout = {
    breadcrumbs: [
        { title: 'Khách hàng', href: '/customers' },
        { title: 'Chi tiết khách hàng', href: '#' },
    ],
};
