import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ImportDialog } from '@/components/app/import-dialog';
import { PageHeader } from '@/components/app/page-header';
import { DataTable } from '@/components/data-table';
import type {
    BulkAction,
    Column,
    FilterOption,
    TableData,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ProductThumb } from '../../components/product-thumb';

type Row = {
    id: number;
    name: string;
    type: string;
    image_url: string | null;
    category: string | null;
    brand: string | null;
    sku: string | null;
    price: number | null;
    variants_count: number;
    on_hand: number | null;
    available: number | null;
    is_sellable: boolean;
    created_at: string | null;
};

type Option = { id: number; name: string };

const typeLabels: Record<string, string> = {
    normal: 'Thường',
    service: 'Dịch vụ',
    combo: 'Combo',
};

const stockOptions: FilterOption[] = [
    { value: 'in_stock', label: 'Còn hàng' },
    { value: 'out_of_stock', label: 'Hết hàng' },
    { value: 'negative', label: 'Tồn âm' },
];

function Quantity({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className={cn(value < 0 && 'font-medium text-destructive')}>
            {formatNumber(value)}
        </span>
    );
}

const columns: Column<Row>[] = [
    {
        key: 'name',
        header: 'Sản phẩm',
        sort: 'name',
        fixed: true,
        cell: (product) => (
            <Link
                href={`/products/${product.id}/edit`}
                className="flex items-center gap-3"
            >
                <ProductThumb url={product.image_url} />
                <span className="min-w-0">
                    <span className="block font-medium text-primary hover:underline">
                        {product.name}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {product.variants_count > 1
                            ? `${product.variants_count} phiên bản`
                            : product.sku}
                    </span>
                </span>
            </Link>
        ),
    },
    {
        key: 'type',
        header: 'Loại hình',
        cell: (product) => (
            <Badge variant="outline">{typeLabels[product.type]}</Badge>
        ),
    },
    {
        key: 'category',
        header: 'Loại sản phẩm',
        cell: (product) => product.category,
    },
    { key: 'brand', header: 'Nhãn hiệu', cell: (product) => product.brand },
    {
        key: 'price',
        header: 'Giá bán lẻ',
        className: 'text-right tabular-nums',
        cell: (product) =>
            product.price === null ? null : formatNumber(product.price),
    },
    {
        key: 'on_hand',
        header: 'Tồn kho',
        sort: 'on_hand',
        className: 'text-right tabular-nums',
        cell: (product) => <Quantity value={product.on_hand} />,
    },
    {
        key: 'available',
        header: 'Có thể bán',
        sort: 'available',
        className: 'text-right tabular-nums',
        cell: (product) => <Quantity value={product.available} />,
    },
    {
        key: 'is_sellable',
        header: 'Trạng thái',
        cell: (product) =>
            product.is_sellable ? (
                <Badge variant="secondary">Đang bán</Badge>
            ) : (
                <Badge variant="outline" className="text-muted-foreground">
                    Ngừng bán
                </Badge>
            ),
    },
    {
        key: 'created_at',
        header: 'Ngày tạo',
        sort: 'created_at',
        hidden: true,
        cell: (product) => formatDateTime(product.created_at),
    },
];

export default function ProductsIndex({
    products,
    categories,
    brands,
    types,
}: {
    products: TableData<Row>;
    categories: Option[];
    brands: Option[];
    types: FilterOption[];
}) {
    const can = useCan();

    const bulk = (action: string) => (ids: number[]) =>
        router.post(
            '/products/bulk',
            { action, ids },
            { preserveScroll: true, preserveState: true },
        );

    const bulkActions: BulkAction[] = [
        {
            label: 'In mã vạch',
            run: (ids) =>
                router.visit(`/products/barcodes?products=${ids.join(',')}`),
        },
        ...(can('products.update')
            ? [
                  { label: 'Cho phép bán', run: bulk('sellable') },
                  { label: 'Ngừng bán', run: bulk('unsellable') },
              ]
            : []),
        ...(can('products.delete')
            ? [
                  {
                      label: 'Xóa sản phẩm',
                      run: bulk('delete'),
                      destructive: true,
                      confirm:
                          'Sản phẩm còn tồn kho sẽ được bỏ qua. Sản phẩm đã xóa không bán được nữa.',
                  },
              ]
            : []),
    ];

    const toOptions = (items: Option[]): FilterOption[] =>
        items.map((item) => ({ value: String(item.id), label: item.name }));

    return (
        <>
            <Head title="Sản phẩm" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Sản phẩm"
                    description="Hàng hóa, dịch vụ/license và combo. Giá, mã vạch và tồn kho nằm ở từng phiên bản."
                    actions={
                        can('products.create') && (
                            <Button asChild>
                                <Link href="/products/create">
                                    <Plus /> Thêm sản phẩm
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    tableKey="products"
                    data={products}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên, mã SKU, mã vạch"
                    tabs={{ filter: 'type', options: types }}
                    filters={[
                        {
                            key: 'type',
                            label: 'Loại hình',
                            type: 'select',
                            options: types,
                        },
                        {
                            key: 'category_id',
                            label: 'Loại sản phẩm',
                            type: 'select',
                            options: toOptions(categories),
                        },
                        {
                            key: 'brand_id',
                            label: 'Nhãn hiệu',
                            type: 'select',
                            options: toOptions(brands),
                        },
                        {
                            key: 'stock',
                            label: 'Tồn kho',
                            type: 'select',
                            options: stockOptions,
                        },
                        {
                            key: 'is_sellable',
                            label: 'Trạng thái bán',
                            type: 'select',
                            options: [
                                { value: '1', label: 'Đang bán' },
                                { value: '0', label: 'Ngừng bán' },
                            ],
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    bulkActions={bulkActions}
                    exportable
                    toolbar={
                        can('products.create') && (
                            <ImportDialog
                                url="/products/import"
                                templateUrl="/products/import/template"
                                title="Nhập sản phẩm từ Excel"
                                description="Dùng tệp mẫu hoặc tệp xuất từ Sapo. Mã SKU đã có sẽ được cập nhật giá và thông tin (tồn kho giữ nguyên); mã mới được thêm kèm tồn kho ban đầu. Có dòng lỗi thì không dòng nào được nhập."
                            />
                        )
                    }
                    empty="Chưa có sản phẩm nào."
                />
            </div>
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [{ title: 'Sản phẩm', href: '/products' }],
};
