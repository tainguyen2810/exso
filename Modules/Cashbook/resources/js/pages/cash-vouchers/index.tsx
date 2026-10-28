import { Head, Link } from '@inertiajs/react';
import { Plus, Tags } from 'lucide-react';
import { toSelectOptions } from '@/components/app/option-select';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    kindText,
    partyTypes,
    toOptions,
    voucherStatuses,
} from '../../components/statuses';
import type { VoucherKind } from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    type: string;
    status: string;
    is_auto: boolean;
    amount: number;
    party_type: string;
    party_name: string;
    party_url: string | null;
    method_name: string;
    document_code: string | null;
    document_url: string | null;
    reference: string | null;
    branch: string;
    creator: string | null;
    recorded_at: string;
    created_at: string | null;
};

type Option = { id: number; name: string };

const linkOrText = (text: string | null, url: string | null) =>
    url ? (
        <Link href={url} className="text-primary hover:underline">
            {text}
        </Link>
    ) : (
        text
    );

/** Phiếu thu / phiếu chi list, like Sapo's: tabs Hoàn thành / Đã hủy. */
export default function CashVouchersIndex({
    kind,
    vouchers,
    types,
    branches,
    paymentMethods,
    staff,
}: {
    kind: VoucherKind;
    vouchers: TableData<Row>;
    types: Option[];
    branches: Option[];
    paymentMethods: Option[];
    staff: Option[];
}) {
    const can = useCan();
    const text = kindText(kind);

    const columns: Column<Row>[] = [
        {
            key: 'recorded_at',
            header: 'Ngày ghi nhận',
            sort: 'recorded_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.recorded_at),
        },
        {
            key: 'code',
            header: 'Mã phiếu',
            sort: 'code',
            fixed: true,
            className: 'whitespace-nowrap',
            cell: (row) => (
                <RecordLink href={`/cashbook/vouchers/${row.id}`}>
                    {row.code}
                </RecordLink>
            ),
        },
        { key: 'type', header: 'Loại phiếu', cell: (row) => row.type },
        {
            key: 'status',
            header: 'Trạng thái',
            cell: (row) => (
                <StatusPill statuses={voucherStatuses} value={row.status} />
            ),
        },
        {
            key: 'amount',
            header: text.amount,
            sort: 'amount',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.amount),
        },
        {
            key: 'party_type',
            header: `Nhóm ${text.party}`,
            cell: (row) => partyTypes[row.party_type] ?? row.party_type,
        },
        {
            key: 'document',
            header: 'Chứng từ gốc',
            className: 'whitespace-nowrap',
            cell: (row) => linkOrText(row.document_code, row.document_url),
        },
        {
            key: 'party_name',
            header: `Tên ${text.party}`,
            cell: (row) => linkOrText(row.party_name, row.party_url),
        },
        {
            key: 'method',
            header: 'Hình thức thanh toán',
            hidden: true,
            cell: (row) => row.method_name,
        },
        {
            key: 'reference',
            header: 'Tham chiếu',
            hidden: true,
            cell: (row) => row.reference,
        },
        {
            key: 'branch',
            header: 'Chi nhánh',
            hidden: true,
            cell: (row) => row.branch,
        },
        {
            key: 'creator',
            header: 'Nhân viên tạo',
            hidden: true,
            cell: (row) => row.creator,
        },
        {
            key: 'created_at',
            header: 'Ngày tạo',
            sort: 'created_at',
            hidden: true,
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.created_at),
        },
    ];

    return (
        <>
            <Head title={text.title} />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey={
                        kind === 'receipt' ? 'cash-receipts' : 'cash-payments'
                    }
                    data={vouchers}
                    columns={columns}
                    searchPlaceholder={`Tìm kiếm theo mã ${text.name}, tham chiếu, mã chứng từ gốc, tên ${text.party}`}
                    tabs={{
                        filter: 'status',
                        options: toOptions(voucherStatuses).map((option) => ({
                            ...option,
                            label: `${text.title} ${option.label.toLowerCase()}`,
                        })),
                    }}
                    filters={[
                        {
                            key: 'recorded_at',
                            label: 'Ngày ghi nhận',
                            type: 'date-range',
                        },
                        {
                            key: 'party_type',
                            label: `Nhóm ${text.party}`,
                            type: 'select',
                            options: Object.entries(partyTypes).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                        {
                            key: 'type_id',
                            label: text.typeName,
                            type: 'select',
                            options: toSelectOptions(types),
                        },
                        {
                            key: 'payment_method_id',
                            label: 'Hình thức thanh toán',
                            type: 'select',
                            options: toSelectOptions(paymentMethods),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'created_by',
                            label: 'Nhân viên tạo',
                            type: 'select',
                            options: toSelectOptions(staff),
                        },
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: toOptions(voucherStatuses),
                        },
                    ]}
                    exportable={can('cashbook.export')}
                    toolbar={
                        <Link
                            href={text.types}
                            className="flex items-center gap-1.5 text-sm font-medium hover:text-primary"
                        >
                            <Tags className="size-4" /> {text.typeName}
                        </Link>
                    }
                    empty={`Chưa có ${text.name} nào.`}
                    actions={
                        can('cashbook.create') && (
                            <Button asChild>
                                <Link href={text.create}>
                                    <Plus /> Tạo {text.name}
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

CashVouchersIndex.layout = (props: { kind: VoucherKind }) => ({
    breadcrumbs: [
        { title: 'Sổ quỹ', href: '/cashbook' },
        {
            title: kindText(props.kind).title,
            href: kindText(props.kind).list,
        },
    ],
});
