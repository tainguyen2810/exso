import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatNumber } from '@/lib/format';
import { moneyInWords } from '@/lib/money-words';
import { partyTypes } from '../../components/statuses';
import type { VoucherKind } from '../../components/statuses';

type Voucher = {
    kind: VoucherKind;
    code: string;
    type: string;
    status: string;
    party_type: string;
    party_name: string;
    amount: number;
    method_name: string;
    reference: string | null;
    document_code: string | null;
    description: string | null;
    branch: string;
    creator: string | null;
    recorded_at: string;
};

/** Phiếu thu / phiếu chi on A5, the print dialog opens by itself. */
export default function CashVoucherPrint({
    voucher,
    storeInfo,
}: {
    voucher: Voucher;
    storeInfo: { name: string; phone: string | null; address: string };
}) {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 300);

        return () => clearTimeout(timer);
    }, []);

    const receipt = voucher.kind === 'receipt';
    const rows: [string, string | null][] = [
        [receipt ? 'Người nộp tiền' : 'Người nhận tiền', voucher.party_name],
        ['Nhóm', partyTypes[voucher.party_type] ?? voucher.party_type],
        [receipt ? 'Lý do thu' : 'Lý do chi', voucher.description || voucher.type],
        ['Loại phiếu', voucher.type],
        ['Hình thức thanh toán', voucher.method_name],
        ['Chứng từ gốc', voucher.document_code],
        ['Tham chiếu', voucher.reference],
        ['Số tiền', `${formatNumber(voucher.amount)} đ`],
        ['Bằng chữ', moneyInWords(voucher.amount)],
    ];

    return (
        <>
            <Head title={`In ${voucher.code}`} />
            <style>{'@page { size: A5 landscape; margin: 10mm; } @media print { .no-print { display: none !important; } }'}</style>
            <div className="min-h-screen bg-muted py-6 text-black print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex w-fit">
                    <Button size="sm" onClick={() => window.print()}>
                        <Printer /> In phiếu
                    </Button>
                </div>
                <div className="mx-auto max-w-[200mm] bg-white p-8 text-sm print:p-0">
                    <div className="flex justify-between gap-4">
                        <div>
                            <p className="font-semibold uppercase">
                                {storeInfo.name}
                            </p>
                            <p>{voucher.branch}</p>
                            {storeInfo.address && <p>{storeInfo.address}</p>}
                            {storeInfo.phone && <p>ĐT: {storeInfo.phone}</p>}
                        </div>
                        <div className="text-right">
                            <p>
                                Số: <strong>{voucher.code}</strong>
                            </p>
                            <p>{formatDateTime(voucher.recorded_at)}</p>
                        </div>
                    </div>
                    <h1 className="my-6 text-center text-2xl font-bold uppercase">
                        {receipt ? 'Phiếu thu' : 'Phiếu chi'}
                        {voucher.status === 'cancelled' && ' (đã hủy)'}
                    </h1>
                    <dl className="grid grid-cols-[170px_1fr] gap-y-2">
                        {rows
                            .filter(([, value]) => value)
                            .map(([label, value]) => (
                                <div key={label} className="contents">
                                    <dt>{label}:</dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            ))}
                    </dl>
                    <div className="mt-10 grid grid-cols-3 text-center">
                        <div>
                            <p className="font-semibold">Người lập phiếu</p>
                            <p className="text-xs italic">(Ký, họ tên)</p>
                            <p className="mt-14">{voucher.creator}</p>
                        </div>
                        <div>
                            <p className="font-semibold">
                                {receipt ? 'Người nộp tiền' : 'Người nhận tiền'}
                            </p>
                            <p className="text-xs italic">(Ký, họ tên)</p>
                            <p className="mt-14">{voucher.party_name}</p>
                        </div>
                        <div>
                            <p className="font-semibold">Thủ quỹ</p>
                            <p className="text-xs italic">(Ký, họ tên)</p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
