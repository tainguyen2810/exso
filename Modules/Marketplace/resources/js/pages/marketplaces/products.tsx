import { Head, Link, router } from '@inertiajs/react';
import { Link2, Link2Off, RefreshCw, UploadCloud, Zap } from 'lucide-react';
import { useState } from 'react';
import { toSelectOptions } from '@/components/app/option-select';
import { Pill } from '@/components/app/status-badge';
import { VariantPicker } from '@/components/app/variant-picker';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';

type Row = {
    id: number;
    shop: string;
    item_id: string;
    model_id: string;
    name: string;
    model_name: string | null;
    sku: string | null;
    image_url: string | null;
    price: number;
    stock: number;
    variant: { id: number; name: string; sku: string; product_id: number } | null;
    pushed_stock: number | null;
    pushed_at: string | null;
    push_error: string | null;
    stock_dirty: boolean;
};

const LINK_TABS = [
    { value: 'linked', label: 'Liên kết thành công' },
    { value: 'unlinked', label: 'Chưa liên kết' },
    { value: 'push_failed', label: 'Đồng bộ tồn lỗi' },
];

/** Sàn TMĐT → Sản phẩm: link platform products to EXSO variants, like Sapo. */
export default function MarketplaceProducts({
    listings,
    shops,
}: {
    listings: TableData<Row>;
    shops: { id: number; name: string }[];
}) {
    const can = useCan();
    const editable = can('marketplaces.update');
    const [linking, setLinking] = useState<Row | null>(null);
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Tên sản phẩm',
            sort: 'name',
            fixed: true,
            cell: (row) => (
                <div className="flex min-w-72 items-center gap-3">
                    {row.image_url ? (
                        <img src={row.image_url} alt="" className="size-10 rounded border object-cover" />
                    ) : (
                        <div className="size-10 rounded border bg-muted" />
                    )}
                    <div>
                        <div className="text-primary">{row.name}</div>
                        {row.model_name && <div className="text-sm">{row.model_name}</div>}
                        <div className="text-xs text-muted-foreground">
                            ID: {row.model_id !== '0' ? row.model_id : row.item_id}
                            {row.sku ? ` · SKU: ${row.sku}` : ''}
                        </div>
                    </div>
                </div>
            ),
        },
        { key: 'shop', header: 'Gian hàng', cell: (row) => row.shop },
        {
            key: 'price',
            header: 'Giá trên sàn',
            sort: 'price',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.price),
        },
        {
            key: 'link',
            header: 'Trạng thái liên kết',
            cell: (row) =>
                row.variant ? <Pill tone="success">Liên kết thành công</Pill> : <Pill tone="warning">Chưa liên kết</Pill>,
        },
        {
            key: 'variant',
            header: 'Sản phẩm liên kết',
            cell: (row) =>
                row.variant ? (
                    <div>
                        <Link href={`/products/${row.variant.product_id}/edit`} className="text-primary hover:underline">
                            {row.variant.name}
                        </Link>
                        <div className="text-xs text-muted-foreground">{row.variant.sku}</div>
                    </div>
                ) : (
                    '—'
                ),
        },
        {
            key: 'stock',
            header: 'Tồn trên sàn',
            sort: 'stock',
            className: 'text-right tabular-nums',
            cell: (row) => (
                <div>
                    <div>{formatNumber(row.stock)}</div>
                    {row.push_error ? (
                        <div className="max-w-56 text-xs text-destructive">{row.push_error}</div>
                    ) : row.pushed_at ? (
                        <div className="text-xs text-muted-foreground">Đẩy lúc {formatDateTime(row.pushed_at)}</div>
                    ) : null}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Sản phẩm sàn TMĐT" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="marketplace_listings"
                    data={listings}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên, SKU, ID sản phẩm sàn hoặc sản phẩm liên kết"
                    tabs={{ filter: 'link', options: LINK_TABS }}
                    filters={[{ key: 'shop_id', label: 'Gian hàng', type: 'select', options: toSelectOptions(shops) }]}
                    rowActions={
                        editable
                            ? (row) =>
                                  row.variant ? (
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          title="Hủy liên kết"
                                          className="text-destructive"
                                          onClick={() =>
                                              router.delete(`/marketplaces/products/${row.id}/link`, { preserveScroll: true })
                                          }
                                      >
                                          <Link2Off className="size-4" />
                                      </Button>
                                  ) : (
                                      <Button variant="ghost" size="sm" title="Liên kết" onClick={() => setLinking(row)}>
                                          <Link2 className="size-4" />
                                      </Button>
                                  )
                            : undefined
                    }
                    actions={
                        editable && (
                            <>
                                <Button variant="outline" onClick={() => post('/marketplaces/products/push-stock')}>
                                    <UploadCloud /> Đồng bộ tồn kho
                                </Button>
                                <Button variant="outline" onClick={() => post('/marketplaces/products/auto-link')}>
                                    <Zap /> Liên kết nhanh
                                </Button>
                                <Button onClick={() => post('/marketplaces/products/fetch')}>
                                    <RefreshCw /> Cập nhật dữ liệu sản phẩm
                                </Button>
                            </>
                        )
                    }
                    empty="Chưa có sản phẩm sàn. Bấm “Cập nhật dữ liệu sản phẩm” để tải sản phẩm từ gian hàng."
                />
            </div>
            <Dialog open={linking !== null} onOpenChange={(open) => !open && setLinking(null)}>
                <DialogContent>
                    <DialogTitle>Liên kết sản phẩm</DialogTitle>
                    <DialogDescription>
                        {linking?.name}
                        {linking?.model_name ? ` - ${linking.model_name}` : ''}: chọn phiên bản trên EXSO. Đơn sàn của sản phẩm này sẽ
                        trừ kho phiên bản đó, tồn “Có thể bán” được đẩy lên sàn.
                    </DialogDescription>
                    <VariantPicker
                        types={['normal', 'combo']}
                        placeholder="Tìm theo tên, mã SKU, mã vạch (F3)"
                        hotkey
                        onPick={(variant) => {
                            if (linking) {
                                router.put(
                                    `/marketplaces/products/${linking.id}/link`,
                                    { variant_id: variant.id },
                                    { preserveScroll: true, onSuccess: () => setLinking(null) },
                                );
                            }
                        }}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

MarketplaceProducts.layout = {
    breadcrumbs: [
        { title: 'Sàn TMĐT', href: '/marketplaces' },
        { title: 'Sản phẩm', href: '/marketplaces/products' },
    ],
};
