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
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';

type Reason = { id: number; type: string; name: string; is_active: boolean };

type Props = {
    reasons: Reason[];
    types: Record<string, string>;
    required: { cancel: boolean; return: boolean };
};

const help: Record<string, { title: string; toggle: string }> = {
    cancel: {
        title: 'Lý do hủy đơn',
        toggle: 'Bắt buộc chọn lý do khi hủy đơn hàng',
    },
    return: {
        title: 'Lý do trả hàng',
        toggle: 'Bắt buộc chọn lý do khi nhận hàng khách trả',
    },
};

/** Lý do hủy, trả hàng, like Sapo: a list per type and "bắt buộc" toggles. */
export default function Reasons({ reasons, types, required }: Props) {
    const can = useCan();
    const form = useForm(required);
    const [editing, setEditing] = useState<Reason | { new: string } | null>(
        null,
    );

    return (
        <>
            <Head title="Lý do hủy, trả hàng" />
            <SettingsPage
                title="Lý do hủy, trả hàng"
                onSave={() =>
                    form.put('/config/reasons/settings', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="Thiết lập"
                    description="Khi bật, nhân viên phải chọn một lý do trong danh sách bên dưới mới hủy hoặc trả được."
                    className="grid gap-4"
                >
                    {(['cancel', 'return'] as const).map((type) => (
                        <label
                            key={type}
                            className="flex items-center gap-3 text-sm"
                        >
                            <Switch
                                checked={form.data[type]}
                                onCheckedChange={(checked) =>
                                    form.setData(type, checked)
                                }
                            />
                            {help[type].toggle}
                        </label>
                    ))}
                </SettingsSection>

                {Object.keys(types).map((type) => {
                    const items = reasons.filter((r) => r.type === type);

                    return (
                        <SettingsSection
                            key={type}
                            title={help[type]?.title ?? types[type]}
                            description={`${items.length} lý do. Lý do ngừng dùng không hiện khi chọn nhưng vẫn giữ trên đơn cũ.`}
                            className="px-0 py-0"
                        >
                            <ul className="divide-y">
                                {items.map((reason) => (
                                    <li
                                        key={reason.id}
                                        className="flex items-center gap-4 px-5 py-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            {can('settings.update') ? (
                                                <button
                                                    type="button"
                                                    className="text-left text-primary hover:underline"
                                                    onClick={() =>
                                                        setEditing(reason)
                                                    }
                                                >
                                                    {reason.name}
                                                </button>
                                            ) : (
                                                reason.name
                                            )}
                                        </div>
                                        {!reason.is_active && (
                                            <Pill>Ngừng dùng</Pill>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {can('settings.update') && (
                                <div className="border-t px-5 py-3">
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="h-auto p-0"
                                        onClick={() =>
                                            setEditing({ new: type })
                                        }
                                    >
                                        <Plus className="size-4" />
                                        Thêm lý do
                                    </Button>
                                </div>
                            )}
                        </SettingsSection>
                    );
                })}
            </SettingsPage>
            {editing && (
                <ReasonDialog
                    reason={'new' in editing ? null : editing}
                    type={'new' in editing ? editing.new : editing.type}
                    typeLabel={
                        types['new' in editing ? editing.new : editing.type]
                    }
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function ReasonDialog({
    reason,
    type,
    typeLabel,
    onClose,
}: {
    reason: Reason | null;
    type: string;
    typeLabel?: string;
    onClose: () => void;
}) {
    const form = useForm({
        type,
        name: reason?.name ?? '',
        is_active: reason?.is_active ?? true,
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };

        if (reason) {
            form.put(`/config/reasons/${reason.id}`, options);
        } else {
            form.post('/config/reasons', options);
        }
    };

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={reason ? 'Sửa lý do' : 'Thêm lý do'}
            description={typeLabel}
            onSubmit={submit}
            processing={form.processing}
            extra={
                reason && (
                    <ConfirmDelete
                        url={`/config/reasons/${reason.id}`}
                        title="Xóa lý do này?"
                        description={reason.name}
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
                <Label htmlFor="reason-name">Lý do *</Label>
                <Input
                    id="reason-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={150}
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <label className="flex items-center gap-3 text-sm">
                <Switch
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData('is_active', checked)
                    }
                />
                Đang dùng
            </label>
            <InputError message={form.errors.is_active} />
        </RecordDialog>
    );
}

Reasons.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Lý do hủy, trả hàng', href: '/config/reasons' },
    ],
};
