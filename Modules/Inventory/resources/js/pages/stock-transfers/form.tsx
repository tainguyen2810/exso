import { Head, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
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
import { transferStatuses } from '../../components/statuses';

type Line = LineProduct & {
    on_hand: number | null;
    quantity: number;
    received_quantity?: number;
    unit_cost?: number | null;
};

type Transfer = {
    id: number;
    code: string;
    status: string;
    from_branch_id: number;
    to_branch_id: number;
    from_branch: string;
    to_branch: string;
    note: string | null;
    creator: string | null;
    shipper: string | null;
    receiver: string | null;
    created_at: string | null;
    shipped_at: string | null;
    received_at: string | null;
    can_ship: boolean;
    can_receive: boolean;
    items: Line[];
};

type Branch = { id: number; name: string };

function BranchSelect({
    label,
    value,
    branches,
    onChange,
    error,
    disabled,
}: {
    label: string;
    value: number | null | undefined;
    branches: Branch[];
    onChange: (id: number) => void;
    error?: string;
    disabled?: boolean;
}) {
    return (
        <Field label={label} error={error}>
            <Select
                value={value ? String(value) : ''}
                disabled={disabled}
                onValueChange={(next) => onChange(Number(next))}
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
        </Field>
    );
}

/**
 * "Chuyển hàng" like Sapo, in two steps: the sending branch ships (stock
 * out), the receiving branch confirms (stock in). Value is shown only with
 * the transfer price permission.
 */
export default function StockTransferForm({
    transfer,
    fromBranches,
    toBranches,
    currentBranchId,
}: {
    transfer: Transfer | null;
    fromBranches: Branch[];
    toBranches: Branch[];
    currentBranchId: number | null;
}) {
    const can = useCan();
    const draft = transfer === null || transfer.status === 'draft';
    const editable =
        transfer === null
            ? can('inventory.create')
            : draft && transfer.can_ship && can('inventory.update');
    const defaultFrom =
        fromBranches.find((branch) => branch.id === currentBranchId)?.id ??
        fromBranches[0]?.id;
    const form = useForm({
        from_branch_id: transfer?.from_branch_id ?? defaultFrom,
        to_branch_id:
            transfer?.to_branch_id ??
            toBranches.find((branch) => branch.id !== defaultFrom)?.id,
        note: transfer?.note ?? '',
        items: transfer?.items ?? [],
        ship: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lines = form.data.items;
    const showValue = can('inventory.view_transfer_price');
    const quantity = lines.reduce((sum, line) => sum + line.quantity, 0);
    const value = lines.reduce(
        (sum, line) => sum + (line.unit_cost ?? 0) * line.quantity,
        0,
    );

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const pick = (variant: VariantOption) => {
        const index = lines.findIndex((line) => line.variant_id === variant.id);

        if (index >= 0) {
            setLine(index, { quantity: lines[index].quantity + 1 });

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
                on_hand: variant.on_hand ?? null,
                quantity: 1,
            },
        ]);
    };

    const submit = (ship: boolean) => {
        form.transform((data) => ({
            ...data,
            ship,
            items: data.items.map((line) => ({
                variant_id: line.variant_id,
                quantity: line.quantity,
            })),
        }));

        if (transfer) {
            form.put(`/stock-transfers/${transfer.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/stock-transfers', { preserveScroll: true });
        }
    };

    const shipped = transfer !== null && !draft;
    const columns: LineColumn<Line>[] = [
        ...(shipped
            ? []
            : [
                  {
                      key: 'on_hand',
                      header: 'Tồn chi nhánh chuyển',
                      className: 'text-right tabular-nums',
                      cell: (line: Line) => (
                          <span
                              className={cn(
                                  line.on_hand !== null &&
                                      line.quantity > line.on_hand &&
                                      'text-destructive',
                              )}
                          >
                              {line.on_hand === null
                                  ? ''
                                  : formatNumber(line.on_hand)}
                          </span>
                      ),
                  },
              ]),
        {
            key: 'quantity',
            header: 'Số lượng chuyển',
            className: editable ? 'w-32' : 'text-right tabular-nums',
            cell: (line, index) =>
                editable ? (
                    <MoneyInput
                        aria-label={`Số lượng chuyển ${line.name}`}
                        aria-invalid={!!errors[`items.${index}.quantity`]}
                        value={line.quantity}
                        onValueChange={(next) =>
                            setLine(index, { quantity: next ?? 0 })
                        }
                    />
                ) : (
                    formatNumber(line.quantity)
                ),
        },
        ...(transfer?.status === 'received'
            ? [
                  {
                      key: 'received',
                      header: 'Đã nhận',
                      className: 'text-right tabular-nums',
                      cell: (line: Line) =>
                          formatNumber(line.received_quantity ?? 0),
                  },
              ]
            : []),
        ...(shipped && showValue
            ? [
                  {
                      key: 'unit_cost',
                      header: 'Giá vốn',
                      className: 'text-right tabular-nums',
                      cell: (line: Line) => formatNumber(line.unit_cost ?? 0),
                  },
                  {
                      key: 'value',
                      header: 'Thành tiền',
                      className: 'pr-5 text-right tabular-nums',
                      cell: (line: Line) =>
                          formatNumber((line.unit_cost ?? 0) * line.quantity),
                  },
              ]
            : []),
    ];

    const linesCard = (
        <DocumentLines
            lines={lines}
            columns={columns}
            readOnly={!editable}
            branchId={form.data.from_branch_id}
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
                            label="Tổng số lượng"
                            value={formatNumber(quantity)}
                        />
                        {shipped && showValue && (
                            <SummaryRow
                                strong
                                label="Giá trị chuyển"
                                value={formatNumber(value)}
                            />
                        )}
                    </div>
                )
            }
        />
    );

    const route = transfer && (
        <FormSection title="Chi nhánh" className="gap-2">
            <div className="flex items-center gap-2 text-sm font-medium">
                {transfer.from_branch}
                <ArrowRight className="size-4 text-muted-foreground" />
                {transfer.to_branch}
            </div>
            <SummaryRow
                label="Ngày tạo"
                value={formatDateTime(transfer.created_at)}
            />
            <SummaryRow label="Người tạo" value={transfer.creator ?? '—'} />
            {transfer.shipped_at && (
                <SummaryRow
                    label="Ngày chuyển"
                    value={`${formatDateTime(transfer.shipped_at)}${transfer.shipper ? ` · ${transfer.shipper}` : ''}`}
                />
            )}
            {transfer.received_at && (
                <SummaryRow
                    label="Ngày nhận"
                    value={`${formatDateTime(transfer.received_at)}${transfer.receiver ? ` · ${transfer.receiver}` : ''}`}
                />
            )}
        </FormSection>
    );

    if (!editable && transfer) {
        return (
            <>
                <Head title={`Phiếu chuyển ${transfer.code}`} />
                <DocumentView
                    title={transfer.code}
                    badges={
                        <StatusPill
                            statuses={transferStatuses}
                            value={transfer.status}
                        />
                    }
                    backHref="/stock-transfers"
                    backLabel="Quay lại danh sách phiếu chuyển"
                    actions={
                        <>
                            {['draft', 'shipping'].includes(transfer.status) &&
                                transfer.can_ship &&
                                can('inventory.delete') && (
                                    <ConfirmAction
                                        url={`/stock-transfers/${transfer.id}/cancel`}
                                        title={`Hủy phiếu chuyển ${transfer.code}?`}
                                        description={
                                            transfer.status === 'shipping'
                                                ? `Hàng đang chuyển sẽ được nhập trả lại ${transfer.from_branch}.`
                                                : 'Phiếu chưa chuyển nên tồn kho không thay đổi.'
                                        }
                                        confirmLabel="Hủy phiếu"
                                        destructive
                                        trigger={
                                            <Button
                                                variant="outline"
                                                className="text-destructive hover:text-destructive"
                                            >
                                                Hủy phiếu
                                            </Button>
                                        }
                                    />
                                )}
                            {transfer.status === 'draft' &&
                                transfer.can_ship &&
                                can('inventory.approve') && (
                                    <ConfirmAction
                                        url={`/stock-transfers/${transfer.id}/ship`}
                                        title="Chuyển hàng?"
                                        description={`Hàng sẽ được xuất khỏi ${transfer.from_branch}.`}
                                        confirmLabel="Chuyển hàng"
                                        trigger={<Button>Chuyển hàng</Button>}
                                    />
                                )}
                            {transfer.status === 'shipping' &&
                                transfer.can_receive &&
                                can('inventory.approve') && (
                                    <ConfirmAction
                                        url={`/stock-transfers/${transfer.id}/receive`}
                                        title="Nhận hàng?"
                                        description={`${formatNumber(quantity)} sản phẩm sẽ được nhập vào ${transfer.to_branch}.`}
                                        confirmLabel="Nhận hàng"
                                        trigger={<Button>Nhận hàng</Button>}
                                    />
                                )}
                        </>
                    }
                    aside={
                        <>
                            {route}
                            <FormSection title="Ghi chú">
                                <p className="text-sm whitespace-pre-line text-muted-foreground">
                                    {transfer.note || 'Chưa có ghi chú.'}
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

    const title = transfer ? transfer.code : 'Tạo phiếu chuyển hàng';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/stock-transfers"
                backLabel="Quay lại danh sách phiếu chuyển"
                onSubmit={() => submit(false)}
                processing={form.processing}
                submitVariant={can('inventory.approve') ? 'outline' : 'default'}
                extraActions={
                    can('inventory.approve') && (
                        <ConfirmAction
                            title="Chuyển hàng?"
                            description="Phiếu được lưu và hàng được xuất khỏi chi nhánh chuyển ngay. Chi nhánh nhận xác nhận Nhận hàng để nhập kho."
                            confirmLabel="Chuyển hàng"
                            onConfirm={() => submit(true)}
                            trigger={
                                <Button
                                    type="button"
                                    disabled={
                                        form.processing || lines.length === 0
                                    }
                                >
                                    Chuyển hàng
                                </Button>
                            }
                        />
                    )
                }
                actions={
                    transfer &&
                    can('inventory.delete') && (
                        <ConfirmAction
                            url={`/stock-transfers/${transfer.id}/cancel`}
                            title={`Hủy phiếu chuyển ${transfer.code}?`}
                            description="Phiếu chưa chuyển nên tồn kho không thay đổi."
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
                        <FormSection title="Chi nhánh">
                            <BranchSelect
                                label="Chi nhánh chuyển"
                                value={form.data.from_branch_id}
                                branches={fromBranches}
                                disabled={lines.length > 0}
                                error={form.errors.from_branch_id}
                                onChange={(id) =>
                                    form.setData('from_branch_id', id)
                                }
                            />
                            <BranchSelect
                                label="Chi nhánh nhận"
                                value={form.data.to_branch_id}
                                branches={toBranches.filter(
                                    (branch) =>
                                        branch.id !== form.data.from_branch_id,
                                )}
                                error={form.errors.to_branch_id}
                                onChange={(id) =>
                                    form.setData('to_branch_id', id)
                                }
                            />
                            {lines.length > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    Bỏ hết sản phẩm để đổi chi nhánh chuyển.
                                </p>
                            )}
                        </FormSection>
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

StockTransferForm.layout = {
    breadcrumbs: [
        { title: 'Chuyển hàng', href: '/stock-transfers' },
        { title: 'Phiếu chuyển hàng', href: '#' },
    ],
};
