import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { MoneyInput } from '@/components/app/money-input';
import { PageTitle } from '@/components/app/topbar';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    sku: string;
    unit: string | null;
    retail_price: number;
    wholesale_price: number;
    import_price: number;
    list_price: number | null;
};

/** Saves when the field loses focus or on Enter. */
function PriceCell({
    listId,
    row,
    editable,
}: {
    listId: number;
    row: Row;
    editable: boolean;
}) {
    const [value, setValue] = useState<number | null>(row.list_price);

    const save = () => {
        if (value === row.list_price) {
            return;
        }

        router.put(
            `/price-lists/${listId}/prices`,
            { variant_id: row.id, price: value },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <MoneyInput
            value={value}
            onValueChange={setValue}
            onBlur={save}
            onKeyDown={(event) => event.key === 'Enter' && save()}
            disabled={!editable}
            placeholder="Chưa có giá"
            className="ml-auto w-36"
        />
    );
}

export default function PriceListShow({
    priceList,
    variants,
}: {
    priceList: { id: number; code: string; name: string; status: string };
    variants: TableData<Row>;
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Phiên bản',
            sort: 'name',
            fixed: true,
            cell: (row) => (
                <>
                    <div className="font-medium">{row.name}</div>
                    <div className="text-xs text-muted-foreground">
                        {row.sku}
                        {row.unit ? ` · ${row.unit}` : ''}
                    </div>
                </>
            ),
        },
        {
            key: 'import_price',
            header: 'Giá nhập',
            hidden: true,
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.import_price),
        },
        {
            key: 'retail_price',
            header: 'Giá bán lẻ',
            sort: 'retail_price',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.retail_price),
        },
        {
            key: 'wholesale_price',
            header: 'Giá bán buôn',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.wholesale_price),
        },
        {
            key: 'list_price',
            header: priceList.name,
            sort: 'list_price',
            fixed: true,
            className: 'text-right',
            cell: (row) => (
                <PriceCell
                    key={`${row.id}-${row.list_price}`}
                    listId={priceList.id}
                    row={row}
                    editable={can('products.update')}
                />
            ),
        },
    ];

    return (
        <>
            <Head title={priceList.name} />
            <PageTitle>
                <Link
                    href="/price-lists"
                    className="inline-flex items-center gap-1 truncate text-sm text-muted-foreground hover:text-primary"
                >
                    <ChevronLeft className="size-4" />
                    Quay lại danh sách chính sách giá
                </Link>
            </PageTitle>
            <div className="mx-auto max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div>
                    <h2 className="text-xl font-medium">
                        {priceList.name}{' '}
                        <span className="text-muted-foreground">
                            ({priceList.code})
                        </span>
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Nhập giá rồi bấm Enter hoặc chuyển ô để lưu. Để trống là
                        chưa áp dụng chính sách này cho phiên bản đó.
                    </p>
                </div>
                <DataTable
                    tableKey="price-list-items"
                    data={variants}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên, mã SKU, mã vạch"
                    filters={[
                        {
                            key: 'priced',
                            label: 'Đã có giá',
                            type: 'select',
                            options: [
                                { value: '1', label: 'Đã có giá' },
                                { value: '0', label: 'Chưa có giá' },
                            ],
                        },
                    ]}
                    empty="Chưa có sản phẩm nào."
                />
                {can('products.delete') && (
                    <div className="border-t pt-4">
                        <ConfirmDelete
                            url={`/price-lists/${priceList.id}`}
                            title={`Xóa chính sách giá ${priceList.name}?`}
                            description="Giá của chính sách này ở mọi phiên bản sẽ bị xóa."
                            trigger={
                                <Button
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa chính sách giá
                                </Button>
                            }
                        />
                    </div>
                )}
            </div>
        </>
    );
}

PriceListShow.layout = (props: {
    priceList: { id: number; name: string };
}) => ({
    breadcrumbs: [
        { title: 'Chính sách giá', href: '/price-lists' },
        {
            title: props.priceList.name,
            href: `/price-lists/${props.priceList.id}`,
        },
    ],
});
