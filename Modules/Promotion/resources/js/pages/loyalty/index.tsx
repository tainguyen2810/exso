import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { PercentInput } from '@/components/app/percent-input';
import {
    RecordDialog,
    SettingsPage,
    SettingsSection,
} from '@/components/app/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';
import { bpToPercent } from '@/lib/percent';

type Values = { enabled: boolean; earn_amount: number; redeem_value: number };

type Tier = {
    id: number;
    name: string;
    min_spent: number;
    earn_multiplier_bp: number;
    description: string | null;
    customers: number;
};

type Stats = { members: number; points: number; earned: number; used: number };

/** Loyalty: tích điểm khi đơn hoàn thành, đổi điểm khi thanh toán, hạng thành viên. */
export default function LoyaltyIndex({
    values,
    tiers,
    stats,
}: {
    values: Values;
    tiers: Tier[];
    stats: Stats;
}) {
    const can = useCan();
    const form = useForm<Values>(values);
    const [editing, setEditing] = useState<Tier | 'new' | null>(null);

    return (
        <>
            <Head title="Tích điểm khách hàng" />
            <SettingsPage
                title="Tích điểm khách hàng"
                permission="loyalty.update"
                onSave={() =>
                    form.put('/loyalty', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <div className="grid gap-3 sm:grid-cols-4">
                    {[
                        ['Khách hàng có điểm', stats.members],
                        ['Điểm hiện có', stats.points],
                        ['Điểm đã tích', stats.earned],
                        ['Điểm đã dùng', stats.used],
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-lg border bg-card px-4 py-3"
                        >
                            <p className="text-sm text-muted-foreground">
                                {label}
                            </p>
                            <p className="text-xl font-medium tabular-nums">
                                {formatNumber(Number(value))}
                            </p>
                        </div>
                    ))}
                </div>
                <div className="-mt-4 text-right text-sm">
                    <Link
                        href="/loyalty/points"
                        className="text-primary hover:underline"
                    >
                        Xem lịch sử điểm
                    </Link>
                </div>

                <SettingsSection
                    title="Tích điểm"
                    description="Điểm được cộng khi đơn hàng hoàn thành và trừ lại khi khách trả hàng. Tiền khách trả bằng điểm và phí giao hàng không được tích điểm."
                >
                    <div className="grid gap-5">
                        <label className="flex items-center justify-between gap-4 text-sm">
                            <span className="font-medium">
                                Bật tích điểm cho khách hàng
                            </span>
                            <Switch
                                checked={form.data.enabled}
                                onCheckedChange={(checked) =>
                                    form.setData('enabled', checked)
                                }
                            />
                        </label>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Số tiền mua hàng để tích 1 điểm"
                                error={form.errors.earn_amount}
                            >
                                <MoneyInput
                                    value={form.data.earn_amount}
                                    onValueChange={(value) =>
                                        form.setData('earn_amount', value ?? 0)
                                    }
                                />
                            </Field>
                            <Field
                                label="Giá trị 1 điểm khi thanh toán"
                                error={form.errors.redeem_value}
                            >
                                <MoneyInput
                                    value={form.data.redeem_value}
                                    onValueChange={(value) =>
                                        form.setData('redeem_value', value ?? 0)
                                    }
                                />
                            </Field>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Ví dụ: đơn {formatNumber(form.data.earn_amount * 5)}
                            đ được 5 điểm; 5 điểm thanh toán được{' '}
                            {formatNumber(form.data.redeem_value * 5)}đ. Khách
                            thanh toán bằng điểm qua hình thức “Thanh toán bằng
                            điểm”.
                        </p>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Hạng thành viên"
                    description="Khách hàng lên hạng theo tổng chi tiêu. Hệ số tích điểm nhân với số điểm được cộng mỗi đơn."
                    className="px-0 py-0"
                >
                    <table className="w-full text-sm">
                        <thead className="border-b text-left text-muted-foreground">
                            <tr>
                                <th className="px-5 py-3 font-medium">
                                    Tên hạng
                                </th>
                                <th className="px-5 py-3 text-right font-medium">
                                    Tổng chi tiêu từ
                                </th>
                                <th className="px-5 py-3 text-right font-medium">
                                    Hệ số tích điểm
                                </th>
                                <th className="px-5 py-3 text-right font-medium">
                                    Khách hàng
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {tiers.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-5 py-4 text-muted-foreground"
                                    >
                                        Chưa có hạng thành viên.
                                    </td>
                                </tr>
                            )}
                            {tiers.map((tier) => (
                                <tr key={tier.id}>
                                    <td className="px-5 py-3">
                                        {can('loyalty.update') ? (
                                            <button
                                                type="button"
                                                className="text-primary hover:underline"
                                                onClick={() => setEditing(tier)}
                                            >
                                                {tier.name}
                                            </button>
                                        ) : (
                                            tier.name
                                        )}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums">
                                        {formatNumber(tier.min_spent)}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums">
                                        x
                                        {bpToPercent(
                                            tier.earn_multiplier_bp / 100,
                                        )}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums">
                                        {formatNumber(tier.customers)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {can('loyalty.update') && (
                        <div className="border-t px-5 py-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing('new')}
                            >
                                <Plus /> Thêm hạng thành viên
                            </Button>
                        </div>
                    )}
                </SettingsSection>
            </SettingsPage>
            {editing && (
                <TierDialog
                    tier={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function TierDialog({
    tier,
    onClose,
}: {
    tier: Tier | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: tier?.name ?? '',
        min_spent: tier?.min_spent ?? 0,
        earn_multiplier_bp: tier?.earn_multiplier_bp ?? 10000,
        description: tier?.description ?? '',
    });
    const options = { preserveScroll: true, onSuccess: onClose };

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={tier ? `Sửa hạng ${tier.name}` : 'Thêm hạng thành viên'}
            processing={form.processing}
            onSubmit={() =>
                tier
                    ? form.put(`/loyalty/tiers/${tier.id}`, options)
                    : form.post('/loyalty/tiers', options)
            }
            extra={
                tier && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-destructive"
                        onClick={() =>
                            router.delete(`/loyalty/tiers/${tier.id}`, options)
                        }
                    >
                        Xóa hạng
                    </Button>
                )
            }
        >
            <Field label="Tên hạng *" error={form.errors.name}>
                <Input
                    autoFocus
                    value={form.data.name}
                    placeholder="Ví dụ: Vàng"
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Tổng chi tiêu từ" error={form.errors.min_spent}>
                    <MoneyInput
                        value={form.data.min_spent}
                        onValueChange={(value) =>
                            form.setData('min_spent', value ?? 0)
                        }
                    />
                </Field>
                <Field
                    label="Hệ số tích điểm (%)"
                    error={form.errors.earn_multiplier_bp}
                >
                    <PercentInput
                        value={form.data.earn_multiplier_bp}
                        onValueChange={(value) =>
                            form.setData('earn_multiplier_bp', value ?? 0)
                        }
                    />
                </Field>
            </div>
            <p className="-mt-2 text-xs text-muted-foreground">
                100% = điểm như bình thường, 150% = được thêm một nửa số điểm.
            </p>
            <Field label="Mô tả" error={form.errors.description}>
                <Textarea
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </Field>
        </RecordDialog>
    );
}

LoyaltyIndex.layout = {
    breadcrumbs: [
        { title: 'Khách hàng', href: '/customers' },
        { title: 'Tích điểm khách hàng', href: '/loyalty' },
    ],
};
