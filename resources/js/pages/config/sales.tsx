import { Head, useForm } from '@inertiajs/react';
import { SettingsPage, SettingsSection } from '@/components/app/settings-page';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

type Item = { key: string; label: string; description: string | null };

type Props = {
    groups: { label: string; items: Item[] }[];
    values: Record<string, boolean | number>;
};

/** Settings keys contain dots; the form sends them with "_" instead. */
const field = (key: string) => key.replaceAll('.', '_');

/** Cấu hình bán hàng, like Sapo: grouped on/off rules plus invoice copies. */
export default function SalesSettings({ groups, values }: Props) {
    const form = useForm<Record<string, boolean | number>>(
        Object.fromEntries(
            Object.entries(values).map(([key, value]) => [field(key), value]),
        ),
    );
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title="Cấu hình bán hàng" />
            <SettingsPage
                title="Cấu hình bán hàng"
                onSave={() =>
                    form.put('/config/sales', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="In hóa đơn"
                    description="Số liên in ra mỗi lần in hóa đơn bán hàng."
                >
                    <div className="grid max-w-xs gap-2">
                        <Label>Số liên hóa đơn</Label>
                        <Select
                            value={String(form.data.sales_print_copies)}
                            onValueChange={(value) =>
                                form.setData(
                                    'sales_print_copies',
                                    Number(value),
                                )
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[1, 2, 3].map((copies) => (
                                    <SelectItem
                                        key={copies}
                                        value={String(copies)}
                                    >
                                        In {copies} liên
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.sales_print_copies} />
                    </div>
                </SettingsSection>

                {groups.map((group) => (
                    <SettingsSection
                        key={group.label}
                        title={group.label}
                        className="gap-5"
                    >
                        {group.items.map((item) => (
                            <label
                                key={item.key}
                                className="flex items-start gap-3"
                            >
                                <Switch
                                    className="mt-0.5"
                                    checked={!!form.data[field(item.key)]}
                                    onCheckedChange={(checked) =>
                                        form.setData(field(item.key), checked)
                                    }
                                />
                                <span className="text-sm">
                                    {item.label}
                                    {item.description && (
                                        <span className="block text-muted-foreground">
                                            {item.description}
                                        </span>
                                    )}
                                    <InputError
                                        message={errors[field(item.key)]}
                                    />
                                </span>
                            </label>
                        ))}
                    </SettingsSection>
                ))}
            </SettingsPage>
        </>
    );
}

SalesSettings.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Cấu hình bán hàng', href: '/config/sales' },
    ],
};
