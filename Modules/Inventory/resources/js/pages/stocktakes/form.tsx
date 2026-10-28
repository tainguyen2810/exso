import { Head, useForm } from '@inertiajs/react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { DocumentLines, SummaryRow } from '@/components/app/document-lines';
import type { LineColumn, LineProduct } from '@/components/app/document-lines';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { PageErrors } from '@/components/app/page-errors';
import { StatusPill } from '@/components/app/status-badge';
import type { VariantOption } from '@/components/app/variant-picker';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { stocktakeStatuses } from '../../components/statuses';

type Line = LineProduct & {
    system_quantity: number;
    actual_quantity: number | null;
    reason: string | null;
};

type Stocktake = {
    id: number;
    code: string;
    status: string;
    branch_id: number;
    branch: string;
    note: string | null;
    creator: string | null;
    balancer: string | null;
    created_at: string | null;
    balanced_at: string | null;
    items: Line[];
};

type Branch = { id: number; name: string };

const difference = (line: Line) =>
    line.actual_quantity === null
        ? 0
        : line.actual_quantity - line.system_quantity;

function Difference({ value }: { value: number }) {
    return (
        <span
            className={cn(
                'tabular-nums',
                value > 0 && 'text-success',
                value < 0 && 'text-destructive',
            )}
        >
            {value > 0 ? '+' : ''}
            {formatNumber(value)}
        </span>
    );
}

/**
 * "Kiểm hàng" like Sapo: count products of a branch, see the difference
 * with the system, then balance the stock. A draft stays editable; a
 * balanced or cancelled one is shown read-only.
 */
export default function StocktakeForm({
    stocktake,
    branches,
    currentBranchId,
    reasons,
}: {
    stocktake: Stocktake | null;
    branches: Branch[];
    currentBranchId: number | null;
    reasons: string[];
}) {
    const can = useCan();
    const editable =
        stocktake === null
            ? can('inventory.create')
            : stocktake.status === 'draft' && can('inventory.update');
    const form = useForm({
        branch_id: stocktake?.branch_id ?? currentBranchId ?? branches[0]?.id,
        note: stocktake?.note ?? '',
        items: stocktake?.items ?? [],
        balance: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lines = form.data.items;
    const totals = lines.reduce(
        (sum, line) => {
            const diff = difference(line);

            return {
                up: sum.up + Math.max(diff, 0),
                down: sum.down + Math.min(diff, 0),
            };
        },
        { up: 0, down: 0 },
    );

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const pick = (variant: VariantOption) => {
        if (lines.some((line) => line.variant_id === variant.id)) {
            return;
        }

        form.setData('items', [
            ...lines,
            {
                variant_id: variant.id,
                product_id: variant.product_id,
                name: variant.name,
                sku: variant.sku,
                unit: variant.unit,
                image_url: variant.image_url,
                system_quantity: variant.on_hand ?? 0,
                actual_quantity: null,
                reason: null,
            },
        ]);
    };

    const submit = (balance: boolean) => {
        form.transform((data) => ({
            ...data,
            balance,
            items: data.items.map((line) => ({
                variant_id: line.variant_id,
                actual_quantity: line.actual_quantity,
                reason: line.reason,
            })),
        }));

        if (stocktake) {
            form.put(`/stocktakes/${stocktake.id}`, { preserveScroll: true });
        } else {
            form.post('/stocktakes', { preserveScroll: true });
        }
    };

    const columns: LineColumn<Line>[] = [
        {
            key: 'system',
            header: 'Tồn hệ thống',
            className: 'text-right tabular-nums',
            cell: (line) => formatNumber(line.system_quantity),
        },
        {
            key: 'actual',
            header: 'Tồn thực tế',
            className: editable ? 'w-32' : 'text-right tabular-nums',
            cell: (line, index) =>
                editable ? (
                    <MoneyInput
                        aria-label={`Tồn thực tế ${line.name}`}
                        placeholder="Nhập số đếm"
                        aria-invalid={
                            !!errors[`items.${index}.actual_quantity`]
                        }
                        value={line.actual_quantity}
                        onValueChange={(value) =>
                            setLine(index, { actual_quantity: value })
                        }
                    />
                ) : (
                    formatNumber(line.actual_quantity ?? 0)
                ),
        },
        {
            key: 'difference',
            header: 'Lệch',
            className: 'text-right',
            cell: (line) => <Difference value={difference(line)} />,
        },
        {
            key: 'reason',
            header: 'Lý do',
            className: 'w-40 pr-5',
            cell: (line, index) =>
                !editable ? (
                    (line.reason ?? '')
                ) : difference(line) === 0 ? null : (
                    <Select
                        value={line.reason ?? ''}
                        onValueChange={(value) =>
                            setLine(index, { reason: value })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Chọn lý do" />
                        </SelectTrigger>
                        <SelectContent>
                            {reasons.map((reason) => (
                                <SelectItem key={reason} value={reason}>
                                    {reason}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ),
        },
    ];

    const branchName =
        stocktake?.branch ??
        branches.find((branch) => branch.id === form.data.branch_id)?.name;

    const linesCard = (
        <DocumentLines
            lines={lines}
            columns={columns}
            readOnly={!editable}
            branchId={form.data.branch_id}
            baseOnly
            onPick={pick}
            onRemove={(index) =>
                form.setData(
                    'items',
                    lines.filter((_, i) => i !== index),
                )
            }
            error={form.errors.items}
            footer={
                lines.length > 0 && (
                    <div className="grid gap-2 border-t px-5 py-4 md:ml-auto md:w-80">
                        <SummaryRow
                            label="Số sản phẩm kiểm"
                            value={formatNumber(lines.length)}
                        />
                        <SummaryRow label="Lệch tăng">
                            <Difference value={totals.up} />
                        </SummaryRow>
                        <SummaryRow label="Lệch giảm">
                            <Difference value={totals.down} />
                        </SummaryRow>
                    </div>
                )
            }
        />
    );

    const branchSelect = (
        <Field label="Chi nhánh kiểm" error={form.errors.branch_id}>
            <Select
                value={String(form.data.branch_id ?? '')}
                disabled={lines.length > 0}
                onValueChange={(value) =>
                    form.setData('branch_id', Number(value))
                }
            >
                <SelectTrigger className="w-full">
                    <SelectValue placeholder="Chọn chi nhánh" />
                </SelectTrigger>
                <SelectContent>
                    {branches.map((branch) => (
                        <SelectItem key={branch.id} value={String(branch.id)}>
                            {branch.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {lines.length > 0 && !stocktake && (
                <p className="text-xs text-muted-foreground">
                    Bỏ hết sản phẩm để đổi chi nhánh.
                </p>
            )}
        </Field>
    );

    const info = stocktake && (
        <FormSection title="Thông tin phiếu" className="gap-2">
            <SummaryRow label="Mã phiếu" value={stocktake.code} />
            <SummaryRow label="Chi nhánh" value={stocktake.branch} />
            <SummaryRow
                label="Ngày tạo"
                value={formatDateTime(stocktake.created_at)}
            />
            <SummaryRow label="Người tạo" value={stocktake.creator ?? '—'} />
            {stocktake.balanced_at && (
                <>
                    <SummaryRow
                        label="Ngày cân bằng"
                        value={formatDateTime(stocktake.balanced_at)}
                    />
                    <SummaryRow
                        label="Người cân bằng"
                        value={stocktake.balancer ?? '—'}
                    />
                </>
            )}
        </FormSection>
    );

    const title = stocktake ? stocktake.code : 'Tạo phiếu kiểm hàng';

    if (!editable && stocktake) {
        return (
            <>
                <Head title={`Phiếu kiểm hàng ${stocktake.code}`} />
                <DocumentView
                    title={stocktake.code}
                    badges={
                        <StatusPill
                            statuses={stocktakeStatuses}
                            value={stocktake.status}
                        />
                    }
                    backHref="/stocktakes"
                    backLabel="Quay lại danh sách phiếu kiểm"
                    aside={
                        <>
                            {info}
                            <FormSection title="Ghi chú">
                                <p className="text-sm whitespace-pre-line text-muted-foreground">
                                    {stocktake.note || 'Chưa có ghi chú.'}
                                </p>
                            </FormSection>
                        </>
                    }
                >
                    {linesCard}
                </DocumentView>
            </>
        );
    }

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/stocktakes"
                backLabel="Quay lại danh sách phiếu kiểm"
                onSubmit={() => submit(false)}
                processing={form.processing}
                submitVariant={can('inventory.approve') ? 'outline' : 'default'}
                extraActions={
                    can('inventory.approve') && (
                        <ConfirmAction
                            title="Cân bằng kho?"
                            description={`Tồn kho của ${lines.length} sản phẩm tại ${branchName ?? 'chi nhánh'} sẽ được đặt bằng số thực tế đã đếm. Sau khi cân bằng không sửa được phiếu.`}
                            confirmLabel="Cân bằng kho"
                            onConfirm={() => submit(true)}
                            trigger={
                                <Button
                                    type="button"
                                    disabled={
                                        form.processing || lines.length === 0
                                    }
                                >
                                    Cân bằng kho
                                </Button>
                            }
                        />
                    )
                }
                actions={
                    stocktake &&
                    can('inventory.delete') && (
                        <ConfirmAction
                            url={`/stocktakes/${stocktake.id}/cancel`}
                            title={`Hủy phiếu kiểm ${stocktake.code}?`}
                            description="Phiếu chưa cân bằng nên tồn kho không thay đổi."
                            confirmLabel="Hủy phiếu"
                            destructive
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Hủy phiếu
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        {info ?? (
                            <FormSection title="Thông tin phiếu">
                                {branchSelect}
                            </FormSection>
                        )}
                        <FormSection title="Ghi chú">
                            <Textarea
                                aria-label="Ghi chú"
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                        </FormSection>
                    </>
                }
            >
                <PageErrors />
                {linesCard}
            </DocumentForm>
        </>
    );
}

StocktakeForm.layout = {
    breadcrumbs: [
        { title: 'Kiểm hàng', href: '/stocktakes' },
        { title: 'Phiếu kiểm hàng', href: '#' },
    ],
};
