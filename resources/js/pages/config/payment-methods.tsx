import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import {
    RecordDialog,
    SettingsPage,
    SettingsSection,
} from '@/components/app/settings-page';
import { Pill } from '@/components/app/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';

type Method = {
    id: number;
    name: string;
    type: string;
    bank_bin: string | null;
    account_number: string | null;
    account_name: string | null;
    is_system: boolean;
    is_active: boolean;
};

type Props = {
    methods: Method[];
    types: Record<string, string>;
    banks: Record<string, string>;
    defaults: { pos: number | null; online: number | null };
};

/**
 * Thanh toán, like Sapo: default method at the counter and online, bank
 * accounts (used for VietQR when selling), e-wallets and other methods.
 */
export default function PaymentMethods({
    methods,
    types,
    banks,
    defaults,
}: Props) {
    const can = useCan();
    const form = useForm({
        pos: defaults.pos ? String(defaults.pos) : '',
        online: defaults.online ? String(defaults.online) : '',
    });
    const [editing, setEditing] = useState<
        Method | { new: 'bank' | 'ewallet' } | null
    >(null);
    const isDefault = (method: Method) =>
        [defaults.pos, defaults.online].includes(method.id);

    const defaultSelect = (key: 'pos' | 'online', label: string) => (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <Select
                value={form.data[key]}
                onValueChange={(value) => form.setData(key, value)}
            >
                <SelectTrigger className="w-full">
                    <SelectValue placeholder="Chọn hình thức" />
                </SelectTrigger>
                <SelectContent>
                    {methods
                        .filter((method) => method.is_active)
                        .map((method) => (
                            <SelectItem
                                key={method.id}
                                value={String(method.id)}
                            >
                                {method.name}
                            </SelectItem>
                        ))}
                </SelectContent>
            </Select>
            <InputError message={form.errors[key]} />
        </div>
    );

    const list = (items: Method[], empty: string) =>
        items.length === 0 ? (
            <p className="px-5 py-4 text-sm text-muted-foreground">{empty}</p>
        ) : (
            <ul className="divide-y">
                {items.map((method) => (
                    <li
                        key={method.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                    >
                        <div className="min-w-0 flex-1">
                            {can('settings.update') ? (
                                <button
                                    type="button"
                                    className="font-medium text-primary hover:underline"
                                    onClick={() => setEditing(method)}
                                >
                                    {method.name}
                                </button>
                            ) : (
                                <span className="font-medium">
                                    {method.name}
                                </span>
                            )}
                            {method.type === 'bank' && (
                                <div className="text-sm text-muted-foreground">
                                    {method.account_number
                                        ? `${banks[method.bank_bin ?? ''] ?? ''} · ${method.account_number} · ${method.account_name ?? ''}`
                                        : 'Chưa có tài khoản nhận tiền'}
                                </div>
                            )}
                        </div>
                        {defaults.pos === method.id && (
                            <Pill tone="info">Mặc định tại quầy</Pill>
                        )}
                        {defaults.online === method.id && (
                            <Pill tone="info">Mặc định online</Pill>
                        )}
                        {method.is_active ? (
                            <Pill tone="success">Đang áp dụng</Pill>
                        ) : (
                            <Pill>Ngừng áp dụng</Pill>
                        )}
                    </li>
                ))}
            </ul>
        );

    const addButton = (type: 'bank' | 'ewallet', label: string) =>
        can('settings.update') && (
            <div className="border-t px-5 py-3">
                <Button
                    type="button"
                    variant="link"
                    className="h-auto p-0"
                    onClick={() => setEditing({ new: type })}
                >
                    <Plus className="size-4" />
                    {label}
                </Button>
            </div>
        );

    return (
        <>
            <Head title="Thanh toán" />
            <SettingsPage
                title="Thanh toán"
                onSave={() =>
                    form.put('/config/payment-methods/defaults', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="Thanh toán mặc định"
                    description="Hình thức được chọn sẵn khi tạo đơn. Nhân viên vẫn đổi được trên từng đơn."
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {defaultSelect('pos', 'Bán tại quầy')}
                    {defaultSelect('online', 'Bán online')}
                </SettingsSection>

                <SettingsSection
                    title="Chuyển khoản"
                    description="Tài khoản nhận tiền. Khi bán hàng, hệ thống tạo mã VietQR theo số tiền của đơn để khách quét."
                    className="px-0 py-0"
                >
                    {list(
                        methods.filter((m) => m.type === 'bank'),
                        'Chưa có tài khoản ngân hàng.',
                    )}
                    {addButton('bank', 'Thêm tài khoản ngân hàng')}
                </SettingsSection>

                <SettingsSection
                    title="Ví điện tử"
                    description="Ví của sàn TMĐT hoặc ví thanh toán khách dùng để trả tiền."
                    className="px-0 py-0"
                >
                    {list(
                        methods.filter((m) => m.type === 'ewallet'),
                        'Chưa có ví điện tử.',
                    )}
                    {addButton('ewallet', 'Thêm ví điện tử')}
                </SettingsSection>

                <SettingsSection
                    title="Hình thức khác"
                    description="Hình thức có sẵn, không xóa được nhưng có thể ngừng áp dụng."
                    className="px-0 py-0"
                >
                    {list(
                        methods.filter((m) =>
                            ['cash', 'cod', 'points'].includes(m.type),
                        ),
                        'Không có hình thức nào.',
                    )}
                </SettingsSection>
            </SettingsPage>
            {editing && (
                <MethodDialog
                    method={'new' in editing ? null : editing}
                    type={'new' in editing ? editing.new : editing.type}
                    types={types}
                    banks={banks}
                    isDefault={!('new' in editing) && isDefault(editing)}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function MethodDialog({
    method,
    type,
    types,
    banks,
    isDefault,
    onClose,
}: {
    method: Method | null;
    type: string;
    types: Record<string, string>;
    banks: Record<string, string>;
    isDefault: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        name: method?.name ?? '',
        type,
        bank_bin: method?.bank_bin ?? '',
        account_number: method?.account_number ?? '',
        account_name: method?.account_name ?? '',
        is_active: method?.is_active ?? true,
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };

        if (method) {
            form.put(`/config/payment-methods/${method.id}`, options);
        } else {
            form.post('/config/payment-methods', options);
        }
    };

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={
                method
                    ? `Sửa ${method.name}`
                    : type === 'bank'
                      ? 'Thêm tài khoản ngân hàng'
                      : 'Thêm ví điện tử'
            }
            description={types[type]}
            onSubmit={submit}
            processing={form.processing}
            extra={
                method &&
                !method.is_system &&
                !isDefault && (
                    <ConfirmDelete
                        url={`/config/payment-methods/${method.id}`}
                        title={`Xóa ${method.name}?`}
                        description="Đơn hàng cũ vẫn giữ hình thức này, chỉ không chọn được cho đơn mới."
                        trigger={
                            <Button
                                type="button"
                                variant="outline"
                                className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                            >
                                Xóa
                            </Button>
                        }
                    />
                )
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="method-name">Tên hình thức *</Label>
                <Input
                    id="method-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={100}
                    placeholder={
                        type === 'bank' ? 'VD: Chuyển khoản MB' : 'VD: Ví MoMo'
                    }
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            {type === 'bank' && (
                <>
                    <div className="grid gap-2">
                        <Label>Ngân hàng</Label>
                        <Select
                            value={form.data.bank_bin || undefined}
                            onValueChange={(value) =>
                                form.setData('bank_bin', value)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Chọn ngân hàng" />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(banks).map(([bin, name]) => (
                                    <SelectItem key={bin} value={bin}>
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.bank_bin} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="account-number">Số tài khoản</Label>
                            <Input
                                id="account-number"
                                value={form.data.account_number}
                                onChange={(e) =>
                                    form.setData(
                                        'account_number',
                                        e.target.value.replace(/\s/g, ''),
                                    )
                                }
                                maxLength={30}
                            />
                            <InputError message={form.errors.account_number} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="account-name">Chủ tài khoản</Label>
                            <Input
                                id="account-name"
                                value={form.data.account_name}
                                onChange={(e) =>
                                    form.setData(
                                        'account_name',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                maxLength={100}
                                placeholder="NGUYEN VAN A"
                            />
                            <InputError message={form.errors.account_name} />
                        </div>
                    </div>
                </>
            )}
            <label className="flex items-center gap-3 text-sm">
                <Switch
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData('is_active', checked)
                    }
                    disabled={isDefault}
                />
                Đang áp dụng
                {isDefault && (
                    <span className="text-muted-foreground">
                        (đang là mặc định)
                    </span>
                )}
            </label>
            <InputError message={form.errors.is_active} />
        </RecordDialog>
    );
}

PaymentMethods.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Thanh toán', href: '/config/payment-methods' },
    ],
};
