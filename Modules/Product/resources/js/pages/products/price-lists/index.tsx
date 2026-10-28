import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';

type PriceList = {
    id: number;
    code: string;
    name: string;
    status: string;
    prices_count: number;
};

const columns: Column<PriceList>[] = [
    {
        key: 'code',
        header: 'Mã',
        sort: 'code',
        fixed: true,
        className: 'font-medium',
        cell: (list) => list.code,
    },
    {
        key: 'name',
        header: 'Tên chính sách giá',
        sort: 'name',
        cell: (list) => (
            <Link
                href={`/price-lists/${list.id}`}
                className="text-primary hover:underline"
            >
                {list.name}
            </Link>
        ),
    },
    {
        key: 'prices_count',
        header: 'Số phiên bản có giá',
        sort: 'prices_count',
        className: 'text-right tabular-nums',
        cell: (list) => formatNumber(list.prices_count),
    },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (list) => <StatusBadge status={list.status} />,
    },
];

export default function PriceListsIndex({
    priceLists,
    builtIn,
}: {
    priceLists: TableData<PriceList>;
    builtIn: { key: string; name: string }[];
}) {
    const can = useCan();
    const [open, setOpen] = useState(false);
    const form = useForm({ code: '', name: '', status: 'active' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/price-lists');
    };

    return (
        <>
            <Head title="Chính sách giá" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Chính sách giá"
                    description="Giá bán lẻ, bán buôn, giá nhập luôn có sẵn ở mỗi phiên bản. Thêm chính sách riêng như Giá đại lý, Giá CTV."
                    actions={
                        can('products.create') && (
                            <Button onClick={() => setOpen(true)}>
                                <Plus /> Thêm chính sách giá
                            </Button>
                        )
                    }
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Chính sách mặc định</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        {builtIn.map((list) => (
                            <Badge key={list.key} variant="secondary">
                                {list.name}
                            </Badge>
                        ))}
                        <span className="text-sm text-muted-foreground">
                            Sửa trong form sản phẩm hoặc nhập Excel.
                        </span>
                    </CardContent>
                </Card>
                <DataTable
                    tableKey="price-lists"
                    data={priceLists}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã, tên"
                    empty="Chưa có chính sách giá riêng."
                />
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="grid gap-4">
                        <DialogTitle>Thêm chính sách giá</DialogTitle>
                        <DialogDescription>
                            Sau khi tạo, nhập giá cho từng phiên bản.
                        </DialogDescription>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Tên *</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                placeholder="VD: Giá đại lý"
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="code">Mã *</Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(event) =>
                                    form.setData(
                                        'code',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="VD: DAILY"
                            />
                            <InputError message={form.errors.code} />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Hủy
                            </Button>
                            <Button disabled={form.processing}>Tạo</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

PriceListsIndex.layout = {
    breadcrumbs: [
        { title: 'Sản phẩm', href: '/products' },
        { title: 'Chính sách giá', href: '/price-lists' },
    ],
};
