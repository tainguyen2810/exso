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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

type Source = {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
    is_active: boolean;
};

/** Nguồn bán hàng, like Sapo: name, whether it is used, the default one. */
export default function Sources({ sources }: { sources: Source[] }) {
    const can = useCan();
    const [editing, setEditing] = useState<Source | 'new' | null>(null);

    return (
        <>
            <Head title="Nguồn bán hàng" />
            <SettingsPage title="Nguồn bán hàng">
                <SettingsSection
                    title="Danh sách nguồn"
                    description="Nguồn cho biết khách đặt hàng từ đâu, dùng để lọc đơn và xem báo cáo theo kênh. Nguồn mặc định được chọn sẵn khi tạo đơn."
                    className="px-0 py-0"
                >
                    <div className="flex items-center justify-between border-b px-5 py-3">
                        <span className="text-sm text-muted-foreground">
                            {sources.length} nguồn
                        </span>
                        {can('settings.update') && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing('new')}
                            >
                                <Plus className="size-4" />
                                Thêm nguồn
                            </Button>
                        )}
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">
                                    Tên nguồn
                                </TableHead>
                                <TableHead>Trạng thái</TableHead>
                                <TableHead className="pr-5 text-center">
                                    Mặc định
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sources.map((source) => (
                                <TableRow key={source.id}>
                                    <TableCell className="pl-5">
                                        {can('settings.update') ? (
                                            <button
                                                type="button"
                                                className="text-primary hover:underline"
                                                onClick={() =>
                                                    setEditing(source)
                                                }
                                            >
                                                {source.name}
                                            </button>
                                        ) : (
                                            source.name
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {source.is_active ? (
                                            <Pill tone="success">
                                                Đang áp dụng
                                            </Pill>
                                        ) : (
                                            <Pill>Ngừng áp dụng</Pill>
                                        )}
                                    </TableCell>
                                    <TableCell className="pr-5 text-center">
                                        {source.is_default && (
                                            <span
                                                className="text-primary"
                                                aria-label="Mặc định"
                                            >
                                                ✓
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SettingsSection>
            </SettingsPage>
            {editing && (
                <SourceDialog
                    source={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function SourceDialog({
    source,
    onClose,
}: {
    source: Source | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: source?.name ?? '',
        is_active: source?.is_active ?? true,
        is_default: source?.is_default ?? false,
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };

        if (source) {
            form.put(`/config/sources/${source.id}`, options);
        } else {
            form.post('/config/sources', options);
        }
    };

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={source ? `Sửa nguồn ${source.name}` : 'Thêm nguồn bán hàng'}
            onSubmit={submit}
            processing={form.processing}
            extra={
                source &&
                !source.is_default &&
                source.code !== 'POS' && (
                    <ConfirmDelete
                        url={`/config/sources/${source.id}`}
                        title={`Xóa nguồn ${source.name}?`}
                        description="Đơn hàng cũ vẫn giữ nguồn này, chỉ không chọn được cho đơn mới."
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
                <Label htmlFor="source-name">Tên nguồn *</Label>
                <Input
                    id="source-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={100}
                    placeholder="VD: Hotline"
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <label className="flex items-center gap-3 text-sm">
                <Switch
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData({
                            ...form.data,
                            is_active: checked,
                            is_default: checked && form.data.is_default,
                        })
                    }
                    disabled={source?.is_default}
                />
                Áp dụng cho cửa hàng
            </label>
            <label className="flex items-center gap-3 text-sm">
                <Checkbox
                    checked={form.data.is_default}
                    onCheckedChange={(checked) =>
                        form.setData('is_default', checked === true)
                    }
                    disabled={source?.is_default || !form.data.is_active}
                />
                Đặt làm nguồn mặc định
            </label>
            <InputError message={form.errors.is_default} />
        </RecordDialog>
    );
}

Sources.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Nguồn bán hàng', href: '/config/sources' },
    ],
};
