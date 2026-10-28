import { Head, useForm } from '@inertiajs/react';
import { Check, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { RecordDialog } from '@/components/app/settings-page';
import { Pill } from '@/components/app/status-badge';
import { TopbarActions } from '@/components/app/topbar';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { formatDate } from '@/lib/format';
import { kindText } from '../../components/statuses';
import type { VoucherKind } from '../../components/statuses';

type VoucherType = {
    id: number;
    code: string;
    name: string;
    affects_profit: boolean;
    description: string | null;
    is_system: boolean;
    is_auto: boolean;
    in_use: boolean;
    created_at: string | null;
};

/** Quản lý loại phiếu thu / loại phiếu chi, like Sapo. */
export default function CashVoucherTypes({
    kind,
    types,
}: {
    kind: VoucherKind;
    types: VoucherType[];
}) {
    const can = useCan();
    const text = kindText(kind);
    const [editing, setEditing] = useState<VoucherType | 'new' | null>(null);
    const [search, setSearch] = useState('');
    const shown = useMemo(() => {
        const term = search.trim().toLowerCase();

        return term === ''
            ? types
            : types.filter((type) =>
                  `${type.code} ${type.name}`.toLowerCase().includes(term),
              );
    }, [types, search]);

    return (
        <>
            <Head title={`Quản lý ${text.typeName.toLowerCase()}`} />
            {can('cashbook.update') && (
                <TopbarActions>
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> Thêm {text.typeName.toLowerCase()}
                    </Button>
                </TopbarActions>
            )}
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <Card className="gap-0 overflow-hidden py-0">
                    <div className="border-b p-4">
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Tìm kiếm theo mã loại, tên loại"
                        />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Mã loại</TableHead>
                                <TableHead>Tên loại</TableHead>
                                <TableHead className="text-center">
                                    Hạch toán kết quả kinh doanh
                                </TableHead>
                                <TableHead>Ngày tạo</TableHead>
                                <TableHead className="pr-5">Mô tả</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((type) => (
                                <TableRow key={type.id}>
                                    <TableCell className="pl-5">
                                        {can('cashbook.update') ? (
                                            <button
                                                type="button"
                                                className="text-primary hover:underline"
                                                onClick={() => setEditing(type)}
                                            >
                                                {type.code}
                                            </button>
                                        ) : (
                                            type.code
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {type.name}
                                        {type.is_system && (
                                            <Pill className="ml-2">Có sẵn</Pill>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {type.affects_profit && (
                                            <Check className="mx-auto size-4 text-primary" />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(type.created_at)}
                                    </TableCell>
                                    <TableCell className="pr-5 text-muted-foreground">
                                        {type.description}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {shown.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Không có loại phiếu nào.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>
            {editing && (
                <TypeDialog
                    kind={kind}
                    type={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function TypeDialog({
    kind,
    type,
    onClose,
}: {
    kind: VoucherKind;
    type: VoucherType | null;
    onClose: () => void;
}) {
    const can = useCan();
    const text = kindText(kind);
    const form = useForm({
        kind,
        name: type?.name ?? '',
        code: type?.code ?? '',
        affects_profit: type?.affects_profit ?? true,
        description: type?.description ?? '',
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };

        if (type) {
            form.put(`/cashbook/types/${type.id}`, options);
        } else {
            form.post('/cashbook/types', options);
        }
    };

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={
                type
                    ? `Sửa ${text.typeName.toLowerCase()} ${type.name}`
                    : `Thêm ${text.typeName.toLowerCase()}`
            }
            onSubmit={submit}
            processing={form.processing}
            extra={
                type &&
                !type.is_system &&
                !type.in_use &&
                can('cashbook.delete') && (
                    <ConfirmDelete
                        url={`/cashbook/types/${type.id}`}
                        title={`Xóa loại phiếu ${type.name}?`}
                        description="Chỉ xóa được loại phiếu chưa có phiếu nào."
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
                <Label htmlFor="type-name">Tên loại *</Label>
                <Input
                    id="type-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    maxLength={255}
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="type-code">Mã loại</Label>
                <Input
                    id="type-code"
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value)
                    }
                    maxLength={50}
                    placeholder="Để trống để tự sinh"
                    disabled={type?.is_system}
                />
                <InputError message={form.errors.code} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="type-description">Mô tả</Label>
                <Textarea
                    id="type-description"
                    value={form.data.description}
                    rows={2}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </div>
            <label className="flex items-center gap-3 text-sm">
                <Checkbox
                    checked={form.data.affects_profit}
                    disabled={type?.is_auto}
                    onCheckedChange={(checked) =>
                        form.setData('affects_profit', checked === true)
                    }
                />
                Hạch toán kết quả kinh doanh (tính vào báo cáo lãi lỗ)
            </label>
        </RecordDialog>
    );
}

CashVoucherTypes.layout = (props: { kind: VoucherKind }) => ({
    breadcrumbs: [
        { title: kindText(props.kind).title, href: kindText(props.kind).list },
        {
            title: `Quản lý ${kindText(props.kind).typeName.toLowerCase()}`,
            href: kindText(props.kind).types,
        },
    ],
});
