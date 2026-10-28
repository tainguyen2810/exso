import { Link } from '@inertiajs/react';
import { PackageSearch, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { ProductThumb } from '@/components/app/product-thumb';
import { VariantPicker } from '@/components/app/variant-picker';
import type { VariantOption } from '@/components/app/variant-picker';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

/** What every document line shows about its product. */
export type LineProduct = {
    variant_id: number;
    product_id?: number;
    name: string;
    sku: string;
    unit: string | null;
    image_url?: string | null;
};

export type LineColumn<T> = {
    key: string;
    header: ReactNode;
    className?: string;
    cell: (line: T, index: number) => ReactNode;
};

/**
 * Product lines of a document (purchase, stocktake, transfer...), like
 * Sapo: a search box on top (F3), then one row per product with the
 * columns the document needs.
 */
export function DocumentLines<T extends LineProduct>({
    title = 'Sản phẩm',
    lines,
    columns,
    onPick,
    onRemove,
    readOnly = false,
    types = ['normal'],
    branchId,
    baseOnly = false,
    price,
    headerExtra,
    footer,
    error,
}: {
    title?: string;
    lines: T[];
    columns: LineColumn<T>[];
    onPick?: (variant: VariantOption) => void;
    onRemove?: (index: number) => void;
    readOnly?: boolean;
    types?: string[];
    branchId?: number | null;
    baseOnly?: boolean;
    price?: 'retail_price' | 'import_price' | 'cost_price';
    headerExtra?: ReactNode;
    footer?: ReactNode;
    error?: string;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <div className="flex flex-wrap items-center gap-3 border-b px-5 py-4">
                <h3 className="font-medium">{title}</h3>
                {headerExtra && (
                    <div className="ml-auto flex items-center gap-3">
                        {headerExtra}
                    </div>
                )}
                {!readOnly && onPick && (
                    <div className="w-full">
                        <VariantPicker
                            hotkey
                            types={types}
                            branchId={branchId}
                            baseOnly={baseOnly}
                            price={price}
                            onPick={onPick}
                        />
                    </div>
                )}
                {error && (
                    <p className="w-full text-sm text-destructive">{error}</p>
                )}
            </div>
            {lines.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-5 py-10 text-sm text-muted-foreground">
                    <PackageSearch className="size-8" />
                    {readOnly
                        ? 'Chưa có sản phẩm.'
                        : 'Tìm và chọn sản phẩm để thêm vào phiếu (F3).'}
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <Table className="[&_td]:align-middle">
                        <TableHeader>
                            <TableRow className="bg-muted/50">
                                <TableHead className="w-10 pl-5">STT</TableHead>
                                <TableHead>Sản phẩm</TableHead>
                                {columns.map((column) => (
                                    <TableHead
                                        key={column.key}
                                        className={cn(
                                            'whitespace-nowrap',
                                            column.className,
                                        )}
                                    >
                                        {column.header}
                                    </TableHead>
                                ))}
                                {!readOnly && onRemove && (
                                    <TableHead className="w-10" />
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((line, index) => (
                                <TableRow key={`${line.variant_id}-${index}`}>
                                    <TableCell className="pl-5 text-muted-foreground">
                                        {index + 1}
                                    </TableCell>
                                    <TableCell className="min-w-56">
                                        <div className="flex items-center gap-3">
                                            <ProductThumb
                                                url={line.image_url}
                                                className="size-9"
                                            />
                                            <div className="min-w-0">
                                                {line.product_id ? (
                                                    <Link
                                                        href={`/products/${line.product_id}/edit`}
                                                        className="block truncate text-primary hover:underline"
                                                    >
                                                        {line.name}
                                                    </Link>
                                                ) : (
                                                    <span className="block truncate">
                                                        {line.name}
                                                    </span>
                                                )}
                                                <span className="text-xs text-muted-foreground">
                                                    {line.sku}
                                                    {line.unit
                                                        ? ` · ${line.unit}`
                                                        : ''}
                                                </span>
                                            </div>
                                        </div>
                                    </TableCell>
                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className={column.className}
                                        >
                                            {column.cell(line, index)}
                                        </TableCell>
                                    ))}
                                    {!readOnly && onRemove && (
                                        <TableCell>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Bỏ dòng này"
                                                className="text-muted-foreground"
                                                onClick={() => onRemove(index)}
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
            {footer}
        </Card>
    );
}

/** A label / value row in a document's totals box. */
export function SummaryRow({
    label,
    value,
    strong = false,
    children,
}: {
    label: ReactNode;
    value?: ReactNode;
    strong?: boolean;
    children?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-4 text-sm',
                strong && 'text-base font-medium',
            )}
        >
            <span className={cn(!strong && 'text-muted-foreground')}>
                {label}
            </span>
            {children ?? <span className="tabular-nums">{value}</span>}
        </div>
    );
}
