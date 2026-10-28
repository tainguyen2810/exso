import { Head, Link, router, useForm } from '@inertiajs/react';
import { History, Plus, Printer, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { MoneyInput } from '@/components/app/money-input';
import { NameCombobox } from '@/components/app/name-combobox';
import { VariantPicker } from '@/components/app/variant-picker';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
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
import { formatNumber } from '@/lib/format';

type Stock = { branch: string; on_hand: number; available: number };

type VariantRow = {
    id: number | null;
    name?: string;
    options: string[];
    sku: string;
    barcode: string;
    retail_price: number | null;
    wholesale_price: number | null;
    import_price: number | null;
    cost_price?: number;
    weight: number | null;
    prices: Record<string, number | null>;
    opening_quantity: number | null;
    opening_cost: number | null;
    stock?: Stock[];
};

type UnitRow = {
    id: number | null;
    unit: string;
    ratio: number | null;
    sku: string;
    barcode: string;
    retail_price: number | null;
    wholesale_price: number | null;
};

type ComboRow = {
    variant_id: number;
    name: string;
    sku: string;
    retail_price: number;
    quantity: number;
};

type Product = {
    id: number;
    type: string;
    name: string;
    category: string | null;
    brand: string | null;
    description: string | null;
    tags: string | null;
    unit: string | null;
    image_url: string | null;
    is_sellable: boolean;
    allow_negative_stock: boolean;
    options: string[];
    variants: (Omit<VariantRow, 'opening_quantity' | 'opening_cost'> & {
        barcode: string | null;
    })[];
    units: (UnitRow & { barcode: string | null })[];
    combo_items: ComboRow[];
};

type Option = { id: number; name: string };

type Attribute = { name: string; values: string[] };

const emptyVariant = (options: string[] = []): VariantRow => ({
    id: null,
    options,
    sku: '',
    barcode: '',
    retail_price: null,
    wholesale_price: null,
    import_price: null,
    weight: null,
    prices: {},
    opening_quantity: null,
    opening_cost: null,
});

/** Every combination of the attribute values, in order. */
function combinations(attributes: Attribute[]): string[][] {
    const filled = attributes.filter(
        (attribute) => attribute.name.trim() && attribute.values.length,
    );

    if (filled.length === 0) {
        return [];
    }

    return filled.reduce<string[][]>(
        (result, attribute) =>
            result.flatMap((prefix) =>
                attribute.values.map((value) => [...prefix, value]),
            ),
        [[]],
    );
}

function attributesOf(product: Product | null): Attribute[] {
    if (!product || product.options.length === 0) {
        return [{ name: '', values: [] }];
    }

    return product.options.map((name, index) => ({
        name,
        values: [
            ...new Set(
                product.variants
                    .map((variant) => variant.options[index] ?? '')
                    .filter(Boolean),
            ),
        ],
    }));
}

function Field({
    label,
    error,
    children,
    className,
}: {
    label: string;
    error?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`grid gap-2 ${className ?? ''}`}>
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function ToggleSection({
    title,
    description,
    checked,
    onCheckedChange,
    disabled,
    children,
}: {
    title: string;
    description: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="rounded-xl border bg-card text-card-foreground shadow-sm">
            <label className="flex cursor-pointer items-start justify-between gap-4 p-6">
                <span>
                    <span className="block leading-none font-semibold">
                        {title}
                    </span>
                    <span className="mt-1.5 block text-sm text-muted-foreground">
                        {description}
                    </span>
                </span>
                <Switch
                    checked={checked}
                    onCheckedChange={onCheckedChange}
                    disabled={disabled}
                />
            </label>
            {checked && <div className="grid gap-4 px-6 pb-6">{children}</div>}
        </div>
    );
}

function ValuesInput({
    values,
    onChange,
    disabled,
}: {
    values: string[];
    onChange: (values: string[]) => void;
    disabled?: boolean;
}) {
    const [text, setText] = useState('');

    const add = () => {
        const parts = text
            .split(',')
            .map((part) => part.trim())
            .filter((part) => part && !values.includes(part));

        if (parts.length) {
            onChange([...values, ...parts]);
        }

        setText('');
    };

    return (
        <div className="flex min-h-9 flex-wrap items-center gap-1 rounded-md border px-2 py-1">
            {values.map((value) => (
                <Badge key={value} variant="secondary" className="gap-1">
                    {value}
                    {!disabled && (
                        <button
                            type="button"
                            aria-label={`Bỏ ${value}`}
                            onClick={() =>
                                onChange(
                                    values.filter((item) => item !== value),
                                )
                            }
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </Badge>
            ))}
            <input
                className="min-w-24 flex-1 bg-transparent text-sm outline-none"
                value={text}
                disabled={disabled}
                placeholder={values.length ? '' : 'Nhập giá trị, Enter để thêm'}
                onChange={(event) => setText(event.target.value)}
                onBlur={add}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ',') {
                        event.preventDefault();
                        add();
                    } else if (
                        event.key === 'Backspace' &&
                        text === '' &&
                        values.length
                    ) {
                        onChange(values.slice(0, -1));
                    }
                }}
            />
        </div>
    );
}

export default function ProductForm({
    product,
    categories,
    brands,
    priceLists,
    branches,
    currentBranchId,
    types,
}: {
    product: Product | null;
    categories: string[];
    brands: string[];
    priceLists: Option[];
    branches: Option[];
    currentBranchId: number | null;
    types: { value: string; label: string }[];
}) {
    const can = useCan();
    const editable = product ? can('products.update') : can('products.create');

    const form = useForm({
        type: product?.type ?? 'normal',
        name: product?.name ?? '',
        category: product?.category ?? '',
        brand: product?.brand ?? '',
        description: product?.description ?? '',
        tags: product?.tags ?? '',
        unit: product?.unit ?? '',
        image_url: product?.image_url ?? '',
        is_sellable: product?.is_sellable ?? true,
        allow_negative_stock: product?.allow_negative_stock ?? false,
        options: product?.options ?? ([] as string[]),
        variants: (product?.variants.map((variant) => ({
            ...variant,
            barcode: variant.barcode ?? '',
            opening_quantity: null,
            opening_cost: null,
        })) ?? [emptyVariant()]) as VariantRow[],
        units: (product?.units.map((unit) => ({
            ...unit,
            barcode: unit.barcode ?? '',
        })) ?? []) as UnitRow[],
        combo_items: product?.combo_items ?? ([] as ComboRow[]),
        opening_branch_id: currentBranchId,
        stay: false,
    });

    const [attributes, setAttributes] = useState<Attribute[]>(
        attributesOf(product),
    );
    const [hasAttributes, setHasAttributes] = useState(
        (product?.options.length ?? 0) > 0,
    );
    const [hasUnits, setHasUnits] = useState((product?.units.length ?? 0) > 0);
    const [initStock, setInitStock] = useState(false);

    const errors = form.errors as Record<string, string | undefined>;
    const isNormal = form.data.type === 'normal';
    const isCombo = form.data.type === 'combo';
    const single = form.data.variants[0];

    const setSingle = (patch: Partial<VariantRow>) =>
        form.setData('variants', [
            { ...single, ...patch },
            ...form.data.variants.slice(1),
        ]);

    const setVariant = (index: number, patch: Partial<VariantRow>) =>
        form.setData(
            'variants',
            form.data.variants.map((variant, i) =>
                i === index ? { ...variant, ...patch } : variant,
            ),
        );

    /** Rebuild the variant table from the attributes, keeping rows already filled. */
    const applyAttributes = (next: Attribute[]) => {
        setAttributes(next);
        const names = next
            .filter(
                (attribute) => attribute.name.trim() && attribute.values.length,
            )
            .map((attribute) => attribute.name.trim());
        const rows = combinations(next).map((options) => {
            const existing = form.data.variants.find(
                (variant) => variant.options.join('|') === options.join('|'),
            );

            return (
                existing ?? {
                    ...emptyVariant(options),
                    retail_price: single?.retail_price ?? null,
                    wholesale_price: single?.wholesale_price ?? null,
                    import_price: single?.import_price ?? null,
                }
            );
        });

        form.setData((data) => ({
            ...data,
            options: names,
            variants: rows.length ? rows : [data.variants[0] ?? emptyVariant()],
        }));
    };

    const toggleAttributes = (checked: boolean) => {
        setHasAttributes(checked);

        if (!checked) {
            form.setData((data) => ({
                ...data,
                options: [],
                variants: [
                    { ...(data.variants[0] ?? emptyVariant()), options: [] },
                ],
            }));
        } else {
            setHasUnits(false);
            form.setData('units', []);
        }
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            options: hasAttributes && isNormal ? data.options : [],
            variants: (hasAttributes && isNormal
                ? data.variants
                : [data.variants[0]]
            ).map((variant) =>
                initStock && !product
                    ? variant
                    : {
                          ...variant,
                          opening_quantity: null,
                          opening_cost: null,
                      },
            ),
            units: hasUnits && isNormal && !hasAttributes ? data.units : [],
            combo_items: isCombo ? data.combo_items : [],
        }));

        if (product) {
            form.put(`/products/${product.id}`, { preserveScroll: true });
        } else {
            form.post('/products');
        }
    };

    const title = product ? product.name : 'Thêm sản phẩm';
    const priceFields: [keyof VariantRow, string][] = [
        ['retail_price', 'Giá bán lẻ'],
        ['wholesale_price', 'Giá bán buôn'],
        ['import_price', 'Giá nhập'],
    ];

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/products"
                onSubmit={submit}
                processing={form.processing}
                isDirty={editable}
                actions={
                    product && (
                        <>
                            <Button variant="outline" type="button" asChild>
                                <Link
                                    href={`/products/barcodes?products=${product.id}`}
                                >
                                    <Printer /> In mã vạch
                                </Link>
                            </Button>
                            {can('products.delete') && (
                                <ConfirmDelete
                                    url={`/products/${product.id}`}
                                    title={`Xóa sản phẩm ${product.name}?`}
                                    description="Chỉ xóa được khi tồn kho ở mọi chi nhánh bằng 0."
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="text-destructive"
                                        >
                                            <Trash2 /> Xóa
                                        </Button>
                                    }
                                />
                            )}
                        </>
                    )
                }
                aside={
                    <>
                        <FormSection title="Phân loại">
                            <Field label="Loại hình">
                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) => {
                                        form.setData('type', value);

                                        if (value !== 'normal') {
                                            toggleAttributes(false);
                                            setHasUnits(false);
                                            setInitStock(false);
                                        }
                                    }}
                                    disabled={!!product || !editable}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.data.type === 'service' && (
                                    <p className="text-xs text-muted-foreground">
                                        Không quản lý tồn kho, không lưu key bản
                                        quyền.
                                    </p>
                                )}
                            </Field>
                            <Field
                                label="Loại sản phẩm"
                                error={errors.category}
                            >
                                <NameCombobox
                                    value={form.data.category}
                                    onChange={(value) =>
                                        form.setData('category', value)
                                    }
                                    options={categories}
                                    placeholder="Chọn loại sản phẩm"
                                    addLabel="Thêm loại"
                                    disabled={!editable}
                                />
                            </Field>
                            <Field label="Nhãn hiệu" error={errors.brand}>
                                <NameCombobox
                                    value={form.data.brand}
                                    onChange={(value) =>
                                        form.setData('brand', value)
                                    }
                                    options={brands}
                                    placeholder="Chọn nhãn hiệu"
                                    addLabel="Thêm nhãn hiệu"
                                    disabled={!editable}
                                />
                            </Field>
                            <Field label="Tags" error={errors.tags}>
                                <Input
                                    value={form.data.tags}
                                    onChange={(event) =>
                                        form.setData('tags', event.target.value)
                                    }
                                    placeholder="VD: office, license"
                                    disabled={!editable}
                                />
                            </Field>
                        </FormSection>
                        <FormSection title="Bán hàng">
                            <label className="flex items-center justify-between gap-2 text-sm">
                                Cho phép bán
                                <Switch
                                    checked={form.data.is_sellable}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_sellable', checked)
                                    }
                                    disabled={!editable}
                                />
                            </label>
                            {isNormal && (
                                <label className="flex items-center justify-between gap-2 text-sm">
                                    <span>
                                        Cho bán khi hết hàng (tồn âm)
                                        <span className="block text-xs text-muted-foreground">
                                            Tắt thì không xuất kho quá số tồn.
                                        </span>
                                    </span>
                                    <Switch
                                        checked={form.data.allow_negative_stock}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'allow_negative_stock',
                                                checked,
                                            )
                                        }
                                        disabled={!editable}
                                    />
                                </label>
                            )}
                        </FormSection>
                        <FormSection title="Ảnh sản phẩm">
                            {form.data.image_url && (
                                <img
                                    src={form.data.image_url}
                                    alt=""
                                    className="aspect-square w-full rounded-md border object-contain"
                                />
                            )}
                            <Field
                                label="Đường dẫn ảnh"
                                error={errors.image_url}
                            >
                                <Input
                                    value={form.data.image_url}
                                    onChange={(event) =>
                                        form.setData(
                                            'image_url',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="https://..."
                                    disabled={!editable}
                                />
                            </Field>
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Thông tin chung" className="sm:grid-cols-2">
                    <Field
                        label="Tên sản phẩm *"
                        error={errors.name}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            autoFocus={!product}
                            disabled={!editable}
                        />
                    </Field>
                    {!(hasAttributes && isNormal) && (
                        <>
                            <Field
                                label="Mã SKU"
                                error={errors['variants.0.sku'] ?? errors.sku}
                            >
                                <Input
                                    value={single.sku}
                                    onChange={(event) =>
                                        setSingle({ sku: event.target.value })
                                    }
                                    placeholder="Để trống sẽ tự sinh"
                                    disabled={!editable}
                                />
                            </Field>
                            <Field
                                label="Mã vạch / Barcode"
                                error={
                                    errors['variants.0.barcode'] ??
                                    errors.barcode
                                }
                            >
                                <Input
                                    value={single.barcode}
                                    onChange={(event) =>
                                        setSingle({
                                            barcode: event.target.value,
                                        })
                                    }
                                    disabled={!editable}
                                />
                            </Field>
                        </>
                    )}
                    <Field label="Đơn vị tính" error={errors.unit}>
                        <Input
                            value={form.data.unit}
                            onChange={(event) =>
                                form.setData('unit', event.target.value)
                            }
                            placeholder="VD: Cái, Hộp, Gói"
                            disabled={!editable}
                        />
                    </Field>
                    {!(hasAttributes && isNormal) && (
                        <Field label="Khối lượng (gram)">
                            <MoneyInput
                                value={single.weight}
                                onValueChange={(weight) =>
                                    setSingle({ weight })
                                }
                                disabled={!editable}
                            />
                        </Field>
                    )}
                    <Field
                        label="Mô tả"
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            rows={3}
                            disabled={!editable}
                        />
                    </Field>
                </FormSection>

                {!(hasAttributes && isNormal) && (
                    <FormSection
                        title="Giá sản phẩm"
                        className="sm:grid-cols-3"
                    >
                        {priceFields.map(([field, label]) => (
                            <Field
                                key={field}
                                label={label}
                                error={errors[`variants.0.${field}`]}
                            >
                                <MoneyInput
                                    value={single[field] as number | null}
                                    onValueChange={(value) =>
                                        setSingle({ [field]: value })
                                    }
                                    disabled={!editable}
                                />
                            </Field>
                        ))}
                        {priceLists.map((list) => (
                            <Field key={list.id} label={list.name}>
                                <MoneyInput
                                    value={single.prices[list.id] ?? null}
                                    onValueChange={(value) =>
                                        setSingle({
                                            prices: {
                                                ...single.prices,
                                                [list.id]: value,
                                            },
                                        })
                                    }
                                    disabled={!editable}
                                />
                            </Field>
                        ))}
                        {product && single.cost_price !== undefined && (
                            <Field label="Giá vốn (bình quân)">
                                <Input
                                    value={formatNumber(single.cost_price)}
                                    disabled
                                    className="text-right"
                                />
                            </Field>
                        )}
                    </FormSection>
                )}

                {isCombo && (
                    <FormSection
                        title="Thành phần combo"
                        description="Bán 1 combo sẽ xuất kho các sản phẩm thành phần theo số lượng."
                    >
                        {editable && (
                            <VariantPicker
                                types={['normal', 'service']}
                                onPick={(variant) => {
                                    if (
                                        form.data.combo_items.some(
                                            (item) =>
                                                item.variant_id === variant.id,
                                        )
                                    ) {
                                        return;
                                    }

                                    form.setData('combo_items', [
                                        ...form.data.combo_items,
                                        {
                                            variant_id: variant.id,
                                            name: variant.name,
                                            sku: variant.sku,
                                            retail_price: variant.retail_price,
                                            quantity: 1,
                                        },
                                    ]);
                                }}
                            />
                        )}
                        <InputError message={errors.combo_items} />
                        {form.data.combo_items.length > 0 && (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Sản phẩm</TableHead>
                                        <TableHead className="w-28 text-right">
                                            Số lượng
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Giá lẻ
                                        </TableHead>
                                        <TableHead className="w-10" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {form.data.combo_items.map(
                                        (item, index) => (
                                            <TableRow key={item.variant_id}>
                                                <TableCell>
                                                    {item.name}
                                                    <div className="text-xs text-muted-foreground">
                                                        {item.sku}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <MoneyInput
                                                        value={item.quantity}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            form.setData(
                                                                'combo_items',
                                                                form.data.combo_items.map(
                                                                    (row, i) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...row,
                                                                                  quantity:
                                                                                      value ??
                                                                                      1,
                                                                              }
                                                                            : row,
                                                                ),
                                                            )
                                                        }
                                                        disabled={!editable}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatNumber(
                                                        item.retail_price *
                                                            item.quantity,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {editable && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Bỏ"
                                                            onClick={() =>
                                                                form.setData(
                                                                    'combo_items',
                                                                    form.data.combo_items.filter(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) =>
                                                                            i !==
                                                                            index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <X className="size-4" />
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        )}
                    </FormSection>
                )}

                {isNormal && !product && (
                    <ToggleSection
                        title="Khởi tạo kho hàng"
                        description="Nhập tồn kho ban đầu và giá vốn cho một chi nhánh."
                        checked={initStock}
                        onCheckedChange={setInitStock}
                        disabled={!editable}
                    >
                        <Field label="Chi nhánh">
                            <Select
                                value={String(
                                    form.data.opening_branch_id ?? '',
                                )}
                                onValueChange={(value) =>
                                    form.setData(
                                        'opening_branch_id',
                                        Number(value),
                                    )
                                }
                            >
                                <SelectTrigger className="w-full sm:w-64">
                                    <SelectValue placeholder="Chọn chi nhánh" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((branch) => (
                                        <SelectItem
                                            key={branch.id}
                                            value={String(branch.id)}
                                        >
                                            {branch.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        {!hasAttributes && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Tồn kho ban đầu">
                                    <MoneyInput
                                        allowNegative
                                        value={single.opening_quantity}
                                        onValueChange={(value) =>
                                            setSingle({
                                                opening_quantity: value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Giá vốn">
                                    <MoneyInput
                                        value={
                                            single.opening_cost ??
                                            single.import_price
                                        }
                                        onValueChange={(value) =>
                                            setSingle({ opening_cost: value })
                                        }
                                    />
                                </Field>
                            </div>
                        )}
                        {hasAttributes && (
                            <p className="text-sm text-muted-foreground">
                                Nhập tồn kho ban đầu ở bảng phiên bản bên dưới.
                            </p>
                        )}
                    </ToggleSection>
                )}

                {isNormal && (
                    <ToggleSection
                        title="Thuộc tính"
                        description="Sản phẩm có nhiều phiên bản theo màu, size, dung lượng..."
                        checked={hasAttributes}
                        onCheckedChange={toggleAttributes}
                        disabled={!editable}
                    >
                        {attributes.map((attribute, index) => (
                            <div
                                key={index}
                                className="grid gap-2 sm:grid-cols-[12rem_1fr_auto]"
                            >
                                <Input
                                    value={attribute.name}
                                    placeholder={`Thuộc tính ${index + 1} (VD: Màu)`}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        applyAttributes(
                                            attributes.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          name: event.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                                <ValuesInput
                                    values={attribute.values}
                                    disabled={!editable}
                                    onChange={(values) =>
                                        applyAttributes(
                                            attributes.map((item, i) =>
                                                i === index
                                                    ? { ...item, values }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Bỏ thuộc tính"
                                    disabled={
                                        !editable || attributes.length === 1
                                    }
                                    onClick={() =>
                                        applyAttributes(
                                            attributes.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                        ))}
                        {attributes.length < 3 && editable && (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                onClick={() =>
                                    setAttributes([
                                        ...attributes,
                                        { name: '', values: [] },
                                    ])
                                }
                            >
                                <Plus /> Thêm thuộc tính
                            </Button>
                        )}
                        <InputError
                            message={errors.variants ?? errors.options}
                        />
                        {form.data.options.length > 0 && (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Phiên bản</TableHead>
                                            <TableHead>Mã SKU</TableHead>
                                            <TableHead>Mã vạch</TableHead>
                                            <TableHead className="text-right">
                                                Giá bán lẻ
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Giá bán buôn
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Giá nhập
                                            </TableHead>
                                            {initStock && !product && (
                                                <TableHead className="text-right">
                                                    Tồn ban đầu
                                                </TableHead>
                                            )}
                                            {product && (
                                                <TableHead className="text-right">
                                                    Tồn kho
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {form.data.variants.map(
                                            (variant, index) => (
                                                <TableRow
                                                    key={variant.options.join(
                                                        '|',
                                                    )}
                                                >
                                                    <TableCell className="font-medium whitespace-nowrap">
                                                        {variant.options.join(
                                                            ' / ',
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="min-w-32">
                                                        <Input
                                                            value={variant.sku}
                                                            placeholder="Tự sinh"
                                                            disabled={!editable}
                                                            onChange={(event) =>
                                                                setVariant(
                                                                    index,
                                                                    {
                                                                        sku: event
                                                                            .target
                                                                            .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    `variants.${index}.sku`
                                                                ]
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="min-w-32">
                                                        <Input
                                                            value={
                                                                variant.barcode
                                                            }
                                                            disabled={!editable}
                                                            onChange={(event) =>
                                                                setVariant(
                                                                    index,
                                                                    {
                                                                        barcode:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </TableCell>
                                                    {(
                                                        [
                                                            'retail_price',
                                                            'wholesale_price',
                                                            'import_price',
                                                        ] as const
                                                    ).map((field) => (
                                                        <TableCell
                                                            key={field}
                                                            className="min-w-28"
                                                        >
                                                            <MoneyInput
                                                                value={
                                                                    variant[
                                                                        field
                                                                    ]
                                                                }
                                                                disabled={
                                                                    !editable
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    setVariant(
                                                                        index,
                                                                        {
                                                                            [field]:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                    ))}
                                                    {initStock && !product && (
                                                        <TableCell className="min-w-24">
                                                            <MoneyInput
                                                                allowNegative
                                                                value={
                                                                    variant.opening_quantity
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    setVariant(
                                                                        index,
                                                                        {
                                                                            opening_quantity:
                                                                                value,
                                                                            opening_cost:
                                                                                variant.import_price,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                    )}
                                                    {product && (
                                                        <TableCell className="text-right tabular-nums">
                                                            {variant.id ? (
                                                                <Link
                                                                    href={`/inventory/variants/${variant.id}`}
                                                                    className="text-primary hover:underline"
                                                                >
                                                                    {formatNumber(
                                                                        (
                                                                            variant.stock ??
                                                                            []
                                                                        ).reduce(
                                                                            (
                                                                                sum,
                                                                                row,
                                                                            ) =>
                                                                                sum +
                                                                                row.on_hand,
                                                                            0,
                                                                        ),
                                                                    )}
                                                                </Link>
                                                            ) : (
                                                                '—'
                                                            )}
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            ),
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </ToggleSection>
                )}

                {isNormal && !hasAttributes && (
                    <ToggleSection
                        title="Đơn vị quy đổi"
                        description="Bán theo thùng, hộp... Tồn kho luôn tính theo đơn vị gốc."
                        checked={hasUnits}
                        onCheckedChange={(checked) => {
                            setHasUnits(checked);

                            if (checked && form.data.units.length === 0) {
                                form.setData('units', [
                                    {
                                        id: null,
                                        unit: '',
                                        ratio: null,
                                        sku: '',
                                        barcode: '',
                                        retail_price: null,
                                        wholesale_price: null,
                                    },
                                ]);
                            }
                        }}
                        disabled={!editable}
                    >
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Đơn vị</TableHead>
                                        <TableHead className="text-right">
                                            = Số{' '}
                                            {form.data.unit || 'đơn vị gốc'}
                                        </TableHead>
                                        <TableHead>Mã SKU</TableHead>
                                        <TableHead>Mã vạch</TableHead>
                                        <TableHead className="text-right">
                                            Giá bán lẻ
                                        </TableHead>
                                        <TableHead className="w-10" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {form.data.units.map((unit, index) => {
                                        const setUnit = (
                                            patch: Partial<UnitRow>,
                                        ) =>
                                            form.setData(
                                                'units',
                                                form.data.units.map((row, i) =>
                                                    i === index
                                                        ? { ...row, ...patch }
                                                        : row,
                                                ),
                                            );

                                        return (
                                            <TableRow key={index}>
                                                <TableCell className="min-w-28">
                                                    <Input
                                                        value={unit.unit}
                                                        placeholder="VD: Thùng"
                                                        disabled={!editable}
                                                        onChange={(event) =>
                                                            setUnit({
                                                                unit: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `units.${index}.unit`
                                                            ]
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="min-w-24">
                                                    <MoneyInput
                                                        value={unit.ratio}
                                                        disabled={!editable}
                                                        onValueChange={(
                                                            ratio,
                                                        ) => setUnit({ ratio })}
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `units.${index}.ratio`
                                                            ]
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="min-w-28">
                                                    <Input
                                                        value={unit.sku}
                                                        placeholder="Tự sinh"
                                                        disabled={!editable}
                                                        onChange={(event) =>
                                                            setUnit({
                                                                sku: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="min-w-28">
                                                    <Input
                                                        value={unit.barcode}
                                                        disabled={!editable}
                                                        onChange={(event) =>
                                                            setUnit({
                                                                barcode:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="min-w-28">
                                                    <MoneyInput
                                                        value={
                                                            unit.retail_price
                                                        }
                                                        disabled={!editable}
                                                        onValueChange={(
                                                            retail_price,
                                                        ) =>
                                                            setUnit({
                                                                retail_price,
                                                            })
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {editable && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Bỏ đơn vị"
                                                            onClick={() =>
                                                                form.setData(
                                                                    'units',
                                                                    form.data.units.filter(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) =>
                                                                            i !==
                                                                            index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <X className="size-4" />
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                        {editable && form.data.units.length < 10 && (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                onClick={() =>
                                    form.setData('units', [
                                        ...form.data.units,
                                        {
                                            id: null,
                                            unit: '',
                                            ratio: null,
                                            sku: '',
                                            barcode: '',
                                            retail_price: null,
                                            wholesale_price: null,
                                        },
                                    ])
                                }
                            >
                                <Plus /> Thêm đơn vị
                            </Button>
                        )}
                    </ToggleSection>
                )}

                {product && isNormal && !hasAttributes && single.id && (
                    <FormSection title="Tồn kho theo chi nhánh">
                        {(single.stock ?? []).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Chưa có tồn kho ở chi nhánh nào.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Chi nhánh</TableHead>
                                        <TableHead className="text-right">
                                            Tồn kho
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Có thể bán
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(single.stock ?? []).map((row) => (
                                        <TableRow key={row.branch}>
                                            <TableCell>{row.branch}</TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(row.on_hand)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(row.available)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        {can('inventory.view') && (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                onClick={() =>
                                    router.visit(
                                        `/inventory/variants/${single.id}`,
                                    )
                                }
                            >
                                <History /> Xem thẻ kho
                            </Button>
                        )}
                    </FormSection>
                )}
            </DocumentForm>
        </>
    );
}

ProductForm.layout = {
    breadcrumbs: [
        { title: 'Sản phẩm', href: '/products' },
        { title: 'Chi tiết', href: '#' },
    ],
};
