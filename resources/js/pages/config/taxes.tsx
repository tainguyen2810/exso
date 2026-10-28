import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import {
    RecordDialog,
    SettingsPage,
    SettingsSection,
} from '@/components/app/settings-page';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';

type Rate = {
    id: number;
    name: string;
    code: string;
    rate_bp: number;
    type: string;
};

type Props = {
    rates: Rate[];
    types: Record<string, string>;
    defaults: { sale: string; purchase: string; price_includes_tax: boolean };
};

const percent = (bp: number) =>
    `${(bp / 100).toLocaleString('vi-VN', { maximumFractionDigits: 2 })}%`;

/** Thuế, like Sapo: default taxes, then the list of tax rates. */
export default function Taxes({ rates, types, defaults }: Props) {
    const can = useCan();
    const form = useForm(defaults);
    const [editing, setEditing] = useState<Rate | 'new' | null>(null);

    const rateSelect = (key: 'sale' | 'purchase', label: string) => (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <Select
                value={form.data[key]}
                onValueChange={(value) => form.setData(key, value)}
            >
                <SelectTrigger className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {rates.map((rate) => (
                        <SelectItem key={rate.code} value={rate.code}>
                            {rate.name} ({percent(rate.rate_bp)})
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={form.errors[key]} />
        </div>
    );

    return (
        <>
            <Head title="Thuế" />
            <SettingsPage
                title="Thuế"
                onSave={() =>
                    form.put('/config/taxes/defaults', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="Thiết lập thuế"
                    description="Thuế mặc định áp dụng khi thêm sản phẩm mới. Bạn vẫn đổi được thuế của từng sản phẩm."
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {rateSelect('sale', 'Thuế bán hàng mặc định')}
                    {rateSelect('purchase', 'Thuế nhập hàng mặc định')}
                    <label className="flex items-start gap-3 sm:col-span-2">
                        <Switch
                            checked={form.data.price_includes_tax}
                            onCheckedChange={(checked) =>
                                form.setData('price_includes_tax', checked)
                            }
                        />
                        <span className="text-sm">
                            Giá sản phẩm đã bao gồm thuế
                            <span className="block text-muted-foreground">
                                Tắt nếu giá bán, giá nhập nhập vào là giá chưa
                                có thuế.
                            </span>
                        </span>
                    </label>
                </SettingsSection>

                <SettingsSection
                    title="Danh sách thuế"
                    description="Các mức thuế dùng cho sản phẩm và đơn hàng. Mức đang là mặc định không xóa được."
                    className="px-0 py-0"
                >
                    <div className="flex items-center justify-between border-b px-5 py-3">
                        <span className="text-sm text-muted-foreground">
                            {rates.length} mức thuế
                        </span>
                        {can('settings.update') && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing('new')}
                            >
                                <Plus className="size-4" />
                                Thêm thuế
                            </Button>
                        )}
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Tên thuế</TableHead>
                                <TableHead>Mã thuế</TableHead>
                                <TableHead className="text-right">
                                    Thuế suất
                                </TableHead>
                                <TableHead className="pr-5">
                                    Loại thuế
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rates.map((rate) => (
                                <TableRow key={rate.id}>
                                    <TableCell className="pl-5">
                                        {can('settings.update') ? (
                                            <button
                                                type="button"
                                                className="text-primary hover:underline"
                                                onClick={() => setEditing(rate)}
                                            >
                                                {rate.name}
                                            </button>
                                        ) : (
                                            rate.name
                                        )}
                                    </TableCell>
                                    <TableCell>{rate.code}</TableCell>
                                    <TableCell className="text-right">
                                        {percent(rate.rate_bp)}
                                    </TableCell>
                                    <TableCell className="pr-5">
                                        {types[rate.type] ?? rate.type}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SettingsSection>
            </SettingsPage>
            {editing && (
                <RateDialog
                    rate={editing === 'new' ? null : editing}
                    types={types}
                    inUse={
                        editing !== 'new' &&
                        [defaults.sale, defaults.purchase].includes(
                            editing.code,
                        )
                    }
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function RateDialog({
    rate,
    types,
    inUse,
    onClose,
}: {
    rate: Rate | null;
    types: Record<string, string>;
    inUse: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        name: rate?.name ?? '',
        code: rate?.code ?? '',
        rate: rate ? String(rate.rate_bp / 100) : '',
        type: rate?.type ?? 'sale',
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };

        if (rate) {
            form.put(`/config/taxes/${rate.id}`, options);
        } else {
            form.post('/config/taxes', options);
        }
    };

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={rate ? `Sửa ${rate.name}` : 'Thêm thuế'}
            onSubmit={submit}
            processing={form.processing}
            extra={
                rate &&
                !inUse && (
                    <ConfirmDelete
                        url={`/config/taxes/${rate.id}`}
                        title={`Xóa ${rate.name}?`}
                        description="Mức thuế sẽ không còn chọn được cho sản phẩm mới."
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
                <Label htmlFor="tax-name">Tên thuế *</Label>
                <Input
                    id="tax-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={100}
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="tax-code">Mã thuế *</Label>
                    <Input
                        id="tax-code"
                        value={form.data.code}
                        onChange={(e) =>
                            form.setData('code', e.target.value.toUpperCase())
                        }
                        disabled={inUse}
                        maxLength={30}
                        placeholder="VD: VAT8"
                    />
                    <InputError message={form.errors.code} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="tax-rate">Thuế suất (%) *</Label>
                    <Input
                        id="tax-rate"
                        inputMode="decimal"
                        value={form.data.rate}
                        onChange={(e) =>
                            form.setData(
                                'rate',
                                e.target.value.replace(',', '.'),
                            )
                        }
                        placeholder="10"
                    />
                    <InputError message={form.errors.rate} />
                </div>
            </div>
            <div className="grid gap-2">
                <Label>Loại thuế</Label>
                <Select
                    value={form.data.type}
                    onValueChange={(value) => form.setData('type', value)}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(types).map(([value, label]) => (
                            <SelectItem key={value} value={value}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={form.errors.type} />
            </div>
        </RecordDialog>
    );
}

Taxes.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Thuế', href: '/config/taxes' },
    ],
};
