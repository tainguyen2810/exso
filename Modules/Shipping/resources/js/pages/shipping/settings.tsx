import { Head, useForm } from '@inertiajs/react';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import { SettingsPage, SettingsSection } from '@/components/app/settings-page';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

type Values = {
    weight_mode: 'product' | 'custom';
    default_weight: number;
    default_length: number;
    default_width: number;
    default_height: number;
    required_note: string;
    note: string;
    auto_export: boolean;
    auto_receive_return: boolean;
    late_pick_days: number;
    late_delivery_days: number;
};

/** "Cấu hình giao hàng" like Sapo: package defaults, automation, warnings. */
export default function ShippingSettings({
    settings,
    requiredNotes,
}: {
    settings: Values;
    requiredNotes: SelectOption[];
}) {
    const form = useForm<Values>(settings);
    const errors = form.errors as Record<string, string | undefined>;
    const number = (key: keyof Values) => (
        <MoneyInput
            value={form.data[key] as number}
            onValueChange={(value) => form.setData(key, (value ?? 0) as never)}
        />
    );

    return (
        <>
            <Head title="Cấu hình giao hàng" />
            <SettingsPage
                title="Cấu hình giao hàng"
                onSave={() =>
                    form.put('/shipping/settings', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="Khối lượng"
                    description="Khối lượng gói hàng điền sẵn khi tạo vận đơn."
                >
                    <div className="grid gap-3 text-sm">
                        {(
                            [
                                [
                                    'product',
                                    'Theo khối lượng sản phẩm (dùng mặc định khi sản phẩm chưa có khối lượng)',
                                ],
                                ['custom', 'Luôn dùng khối lượng mặc định'],
                            ] as const
                        ).map(([value, label]) => (
                            <label
                                key={value}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="radio"
                                    className="accent-primary"
                                    checked={form.data.weight_mode === value}
                                    onChange={() =>
                                        form.setData('weight_mode', value)
                                    }
                                />
                                {label}
                            </label>
                        ))}
                        <Field
                            label="Khối lượng mặc định (g)"
                            error={errors.default_weight}
                            className="max-w-xs"
                        >
                            {number('default_weight')}
                        </Field>
                    </div>
                </SettingsSection>
                <SettingsSection
                    title="Kích thước"
                    description="Kích thước gói hàng mặc định (cm)."
                >
                    <div className="grid grid-cols-3 gap-3">
                        <Field label="Dài" error={errors.default_length}>
                            {number('default_length')}
                        </Field>
                        <Field label="Rộng" error={errors.default_width}>
                            {number('default_width')}
                        </Field>
                        <Field label="Cao" error={errors.default_height}>
                            {number('default_height')}
                        </Field>
                    </div>
                </SettingsSection>
                <SettingsSection
                    title="Yêu cầu và ghi chú"
                    description="Lưu ý cho đối tác vận chuyển khi giao hàng."
                >
                    <div className="grid gap-4">
                        <Field
                            label="Yêu cầu khi giao"
                            error={errors.required_note}
                        >
                            <OptionSelect
                                value={form.data.required_note}
                                onChange={(value) =>
                                    form.setData(
                                        'required_note',
                                        value ?? 'CHOXEMHANGKHONGTHU',
                                    )
                                }
                                options={requiredNotes}
                            />
                        </Field>
                        <Field label="Ghi chú mặc định" error={errors.note}>
                            <Input
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                                placeholder="VD: Gọi trước khi giao"
                            />
                        </Field>
                    </div>
                </SettingsSection>
                <SettingsSection
                    title="Tự động"
                    description="Đơn hàng đi theo trạng thái mà hãng vận chuyển báo về."
                >
                    <div className="grid gap-4 text-sm">
                        <Toggle
                            checked={form.data.auto_export}
                            onChange={(checked) =>
                                form.setData('auto_export', checked)
                            }
                            title="Tự xuất kho khi đối tác lấy hàng"
                            description="Đơn chuyển sang Đã xuất kho khi vận đơn chuyển sang Đang giao hàng."
                        />
                        <Toggle
                            checked={form.data.auto_receive_return}
                            onChange={(checked) =>
                                form.setData('auto_receive_return', checked)
                            }
                            title="Tự chuyển trạng thái Hủy giao - đã nhận"
                            description="Khi hãng báo đã hoàn hàng về cửa hàng, hàng được nhập lại kho cho đơn."
                        />
                    </div>
                </SettingsSection>
                <SettingsSection
                    title="Cảnh báo"
                    description="Số ngày để cảnh báo vận đơn lấy trễ, giao trễ ở Tổng quan vận chuyển (0 = không cảnh báo)."
                >
                    <div className="grid grid-cols-2 gap-3">
                        <Field
                            label="Lấy hàng trễ sau (ngày)"
                            error={errors.late_pick_days}
                        >
                            {number('late_pick_days')}
                        </Field>
                        <Field
                            label="Giao hàng trễ sau (ngày)"
                            error={errors.late_delivery_days}
                        >
                            {number('late_delivery_days')}
                        </Field>
                    </div>
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

function Toggle({
    checked,
    onChange,
    title,
    description,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    title: string;
    description: string;
}) {
    return (
        <label className="flex items-center justify-between gap-4">
            <span>
                <span className="block font-medium">{title}</span>
                <span className="text-muted-foreground">{description}</span>
            </span>
            <Switch checked={checked} onCheckedChange={onChange} />
        </label>
    );
}

ShippingSettings.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Cấu hình giao hàng', href: '/shipping/settings' },
    ],
};
