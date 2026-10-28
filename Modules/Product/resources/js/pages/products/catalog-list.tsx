import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { PageHeader } from '@/components/app/page-header';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';

type Item = {
    id: number;
    name: string;
    products_count: number;
    created_at: string | null;
};

/** "Loại sản phẩm" and "Nhãn hiệu" lists. */
export default function CatalogList({
    list,
    title,
    items,
}: {
    list: 'categories' | 'brands';
    title: string;
    items: TableData<Item>;
}) {
    const can = useCan();
    const [editing, setEditing] = useState<Item | null | undefined>(undefined);
    const form = useForm({ name: '' });
    const label = title.toLowerCase();

    const open = (item: Item | null) => {
        form.clearErrors();
        form.setData('name', item?.name ?? '');
        setEditing(item);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditing(undefined),
        };

        if (editing) {
            form.put(`/product-lists/${list}/${editing.id}`, options);
        } else {
            form.post(`/product-lists/${list}`, options);
        }
    };

    const columns: Column<Item>[] = [
        {
            key: 'name',
            header: 'Tên',
            sort: 'name',
            fixed: true,
            className: 'font-medium',
            cell: (item) => item.name,
        },
        {
            key: 'products_count',
            header: 'Số sản phẩm',
            sort: 'products_count',
            className: 'text-right tabular-nums',
            cell: (item) => formatNumber(item.products_count),
        },
        {
            key: 'created_at',
            header: 'Ngày tạo',
            sort: 'created_at',
            cell: (item) => formatDateTime(item.created_at),
        },
    ];

    return (
        <>
            <Head title={title} />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title={title}
                    description={`Dùng để phân nhóm, lọc sản phẩm và làm báo cáo. Có thể thêm ${label} mới ngay trong form sản phẩm.`}
                    actions={
                        can('products.create') && (
                            <Button onClick={() => open(null)}>
                                <Plus /> Thêm {label}
                            </Button>
                        )
                    }
                />
                <DataTable
                    tableKey={`product-${list}`}
                    data={items}
                    columns={columns}
                    searchPlaceholder={`Tìm ${label}`}
                    empty={`Chưa có ${label} nào.`}
                    rowActions={(item) => (
                        <>
                            {can('products.update') && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Sửa"
                                    onClick={() => open(item)}
                                >
                                    <Pencil className="size-4" />
                                </Button>
                            )}
                            {can('products.delete') && (
                                <ConfirmDelete
                                    url={`/product-lists/${list}/${item.id}`}
                                    title={`Xóa ${label} ${item.name}?`}
                                    description={`${formatNumber(item.products_count)} sản phẩm đang thuộc ${label} này sẽ được bỏ trống ${label}.`}
                                />
                            )}
                        </>
                    )}
                />
            </div>
            <Dialog
                open={editing !== undefined}
                onOpenChange={(value) => !value && setEditing(undefined)}
            >
                <DialogContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <DialogTitle>
                            {editing ? `Sửa ${label}` : `Thêm ${label}`}
                        </DialogTitle>
                        <div className="grid gap-2">
                            <Input
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                placeholder="Tên"
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(undefined)}
                            >
                                Hủy
                            </Button>
                            <Button disabled={form.processing}>Lưu</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

CatalogList.layout = (props: { title: string; list: string }) => ({
    breadcrumbs: [
        { title: 'Sản phẩm', href: '/products' },
        { title: props.title, href: `/product-lists/${props.list}` },
    ],
});
