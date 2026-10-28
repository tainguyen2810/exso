import { Head } from '@inertiajs/react';
import JsBarcode from 'jsbarcode';
import { Printer, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { MoneyInput } from '@/components/app/money-input';
import { TopbarActions } from '@/components/app/topbar';
import { VariantPicker } from '@/components/app/variant-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatNumber } from '@/lib/format';

type LabelItem = {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    retail_price: number;
    unit: string | null;
};

type Line = LabelItem & { copies: number };

/** Label sheets: size of one label and how many per row. */
const templates = {
    roll2: {
        label: 'Cuộn 2 tem (35 × 22 mm)',
        width: 35,
        height: 22,
        columns: 2,
    },
    roll3: {
        label: 'Cuộn 3 tem (35 × 22 mm)',
        width: 35,
        height: 22,
        columns: 3,
    },
    a4: {
        label: 'Giấy A4 65 tem (38 × 21 mm)',
        width: 38,
        height: 21,
        columns: 5,
    },
} as const;

type TemplateKey = keyof typeof templates;

function Barcode({ value }: { value: string }) {
    const ref = useRef<SVGSVGElement>(null);

    useEffect(() => {
        if (!ref.current) {
            return;
        }

        try {
            JsBarcode(ref.current, value, {
                format: 'CODE128',
                displayValue: true,
                fontSize: 10,
                height: 28,
                margin: 0,
                width: 1.2,
            });
        } catch {
            ref.current.innerHTML = '';
        }
    }, [value]);

    return <svg ref={ref} className="h-auto max-w-full" />;
}

export default function Barcodes({
    initial,
    storeName,
}: {
    initial: LabelItem[];
    storeName: string;
}) {
    const [lines, setLines] = useState<Line[]>(
        initial.map((label) => ({ ...label, copies: 1 })),
    );
    const [template, setTemplate] = useState<TemplateKey>('roll2');
    const [show, setShow] = useState({ store: true, name: true, price: true });
    const size = templates[template];

    const labels = lines.flatMap((line) =>
        Array.from({ length: Math.min(line.copies, 500) }, () => line),
    );

    return (
        <>
            <Head title="In mã vạch" />
            <style>{`
                @media print {
                    @page { margin: 2mm; }
                    body * { visibility: hidden; }
                    .print-area, .print-area * { visibility: visible; }
                    .print-area { position: absolute; left: 0; top: 0; }
                }
            `}</style>
            <TopbarActions>
                <Button
                    onClick={() => window.print()}
                    disabled={labels.length === 0}
                >
                    <Printer /> In {formatNumber(labels.length)} tem
                </Button>
            </TopbarActions>
            <div className="mx-auto max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8 print:hidden">
                <p className="text-sm text-muted-foreground">
                    Chọn sản phẩm, số tem, khổ giấy rồi bấm In. Tem dùng mã vạch
                    của phiên bản, chưa có thì dùng mã SKU.
                </p>
                <div className="grid items-start gap-4 lg:grid-cols-12">
                    <Card className="lg:col-span-8">
                        <CardHeader>
                            <CardTitle>Sản phẩm</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <VariantPicker
                                hotkey
                                types={['normal', 'combo']}
                                onPick={(variant) =>
                                    setLines((current) =>
                                        current.some(
                                            (line) => line.id === variant.id,
                                        )
                                            ? current.map((line) =>
                                                  line.id === variant.id
                                                      ? {
                                                            ...line,
                                                            copies:
                                                                line.copies + 1,
                                                        }
                                                      : line,
                                              )
                                            : [
                                                  ...current,
                                                  { ...variant, copies: 1 },
                                              ],
                                    )
                                }
                            />
                            {lines.length > 0 && (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Phiên bản</TableHead>
                                            <TableHead className="text-right">
                                                Giá bán lẻ
                                            </TableHead>
                                            <TableHead className="w-28 text-right">
                                                Số tem
                                            </TableHead>
                                            <TableHead className="w-10" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.map((line) => (
                                            <TableRow key={line.id}>
                                                <TableCell>
                                                    {line.name}
                                                    <div className="text-xs text-muted-foreground">
                                                        {line.barcode ??
                                                            line.sku}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatNumber(
                                                        line.retail_price,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <MoneyInput
                                                        value={line.copies}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setLines(
                                                                (current) =>
                                                                    current.map(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.id ===
                                                                            line.id
                                                                                ? {
                                                                                      ...item,
                                                                                      copies:
                                                                                          value ??
                                                                                          0,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Bỏ"
                                                        onClick={() =>
                                                            setLines(
                                                                (current) =>
                                                                    current.filter(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.id !==
                                                                            line.id,
                                                                    ),
                                                            )
                                                        }
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                    <Card className="lg:col-span-4">
                        <CardHeader>
                            <CardTitle>Mẫu tem</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <div className="grid gap-2">
                                <Label>Khổ giấy</Label>
                                <Select
                                    value={template}
                                    onValueChange={(value) =>
                                        setTemplate(value as TemplateKey)
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(templates).map(
                                            ([key, value]) => (
                                                <SelectItem
                                                    key={key}
                                                    value={key}
                                                >
                                                    {value.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            {(
                                [
                                    ['store', 'Tên cửa hàng'],
                                    ['name', 'Tên sản phẩm'],
                                    ['price', 'Giá bán lẻ'],
                                ] as const
                            ).map(([key, label]) => (
                                <label
                                    key={key}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={show[key]}
                                        onCheckedChange={(checked) =>
                                            setShow({
                                                ...show,
                                                [key]: checked === true,
                                            })
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        </CardContent>
                    </Card>
                </div>
                {labels.length > 0 && (
                    <p className="text-sm text-muted-foreground">Xem trước:</p>
                )}
            </div>
            <div className="px-4 pb-6 md:px-6 print:p-0">
                <div
                    className="print-area grid gap-[2mm] bg-white text-black"
                    style={{
                        gridTemplateColumns: `repeat(${size.columns}, ${size.width}mm)`,
                    }}
                >
                    {labels.map((line, index) => (
                        <div
                            key={`${line.id}-${index}`}
                            className="flex flex-col items-center justify-center overflow-hidden border border-dashed border-gray-300 p-[1mm] text-center leading-tight print:border-0"
                            style={{
                                width: `${size.width}mm`,
                                height: `${size.height}mm`,
                            }}
                        >
                            {show.store && (
                                <div className="text-[7px] font-semibold uppercase">
                                    {storeName}
                                </div>
                            )}
                            {show.name && (
                                <div className="line-clamp-1 text-[7px]">
                                    {line.name}
                                </div>
                            )}
                            <Barcode value={line.barcode ?? line.sku} />
                            {show.price && (
                                <div className="text-[8px] font-semibold">
                                    {formatNumber(line.retail_price)} đ
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

Barcodes.layout = {
    breadcrumbs: [
        { title: 'Sản phẩm', href: '/products' },
        { title: 'In mã vạch', href: '/products/barcodes' },
    ],
};
