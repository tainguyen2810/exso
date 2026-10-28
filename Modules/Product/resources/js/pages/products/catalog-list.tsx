import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
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
            cell: (item) =>
                can('products.update') ? (
                    <button
                        type="button"
                        className="text-left text-primary hover:underline"
                        onClick={() => open(item)}
                    >
                        {item.name}
                    </button>
                ) : (
                    item.name
                ),
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
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey={`product-${list}`}
                    data={items}
                    columns={columns}
                    searchPlaceholder={`Tìm ${label}`}
                    empty={`Chưa có ${label} nào. Có thể thêm ${label} mới ngay trong trang sản phẩm.`}
                    actions={
                        can('products.create') && (
                            <Button onClick={() => open(null)}>
                                <Plus /> Thêm {label}
                            </Button>
                        )
                    }
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
                        <DialogFooter className="gap-2">
                            {editing && can('products.delete') && (
                                <ConfirmDelete
                                    url={`/product-lists/${list}/${editing.id}`}
                                    title={`Xóa ${label} ${editing.name}?`}
                                    description={`${formatNumber(editing.products_count)} sản phẩm đang thuộc ${label} này sẽ được bỏ trống ${label}.`}
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="mr-auto border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                        >
                                            Xóa
                                        </Button>
                                    }
                                />
                            )}
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
        { title: 'Cấu hình', href: '/config' },
        { title: props.title, href: `/product-lists/${props.list}` },
    ],
});
