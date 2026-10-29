import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Label = {
    code: string;
    tracking_code: string | null;
    order_code: string;
    carrier: string;
    service_name: string | null;
    cod_amount: number;
    fee: number;
    fee_payer: string;
    weight: number;
    required_note: string | null;
    note: string | null;
    receiver_name: string;
    receiver_phone: string;
    address: string;
    created_at: string | null;
    branch: { name: string; address: string | null; phone: string | null };
    items: { name: string; sku: string | null; quantity: number }[];
};

/** Phiếu giao hàng (A5 or K80) for partners the store contacts itself. */
export default function ShipmentPrint({
    labels,
    storeName,
    size,
}: {
    labels: Label[];
    storeName: string;
    size: 'a5' | '80';
}) {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 300);

        return () => clearTimeout(timer);
    }, []);

    const narrow = size === '80';

    return (
        <>
            <Head title="In phiếu giao hàng" />
            <style>
                {`@page { size: ${narrow ? '80mm auto' : 'A5'}; margin: ${narrow ? '3mm' : '8mm'}; } @media print { .no-print { display: none !important; } .label { break-after: page; } }`}
            </style>
            <div className="min-h-screen bg-muted py-6 text-black print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex w-fit gap-2">
                    <Button size="sm" onClick={() => window.print()}>
                        <Printer /> In phiếu
                    </Button>
                    <Button size="sm" variant="outline" asChild>
                        <a href={`?size=${narrow ? 'a5' : '80'}`}>
                            {narrow ? 'Khổ A5' : 'Khổ K80'}
                        </a>
                    </Button>
                </div>
                {labels.map((label) => (
                    <div
                        key={label.code}
                        className={cn(
                            'label mx-auto mb-4 bg-white text-sm print:m-0 print:p-0',
                            narrow ? 'w-[74mm] p-3 text-xs' : 'w-[132mm] p-6',
                        )}
                    >
                        <div className="flex items-start justify-between gap-3 border-b border-black pb-2">
                            <div>
                                <p className="font-semibold uppercase">
                                    {storeName}
                                </p>
                                <p>{label.branch.name}</p>
                                {label.branch.phone && (
                                    <p>ĐT: {label.branch.phone}</p>
                                )}
                            </div>
                            <div className="text-right">
                                <p className="font-semibold">{label.carrier}</p>
                                {label.service_name && (
                                    <p>{label.service_name}</p>
                                )}
                            </div>
                        </div>
                        <div className="border-b border-black py-2 text-center">
                            <p className="text-xs">Mã vận đơn</p>
                            <p className="font-mono text-2xl font-bold tracking-wider">
                                {label.tracking_code ?? label.code}
                            </p>
                            <p className="text-xs">
                                {label.code} · Đơn {label.order_code} ·{' '}
                                {formatDateTime(label.created_at)}
                            </p>
                        </div>
                        <div className="border-b border-black py-2">
                            <p className="text-xs">Người nhận</p>
                            <p className="font-semibold">
                                {label.receiver_name} · {label.receiver_phone}
                            </p>
                            <p>{label.address}</p>
                        </div>
                        <ul className="border-b border-black py-2">
                            {label.items.map((item, index) => (
                                <li
                                    key={index}
                                    className="flex justify-between gap-2"
                                >
                                    <span>
                                        {item.name}
                                        {item.sku ? ` (${item.sku})` : ''}
                                    </span>
                                    <span>x{formatNumber(item.quantity)}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="grid gap-0.5 py-2">
                            <p>Khối lượng: {formatNumber(label.weight)} g</p>
                            {label.required_note && (
                                <p>Yêu cầu: {label.required_note}</p>
                            )}
                            {label.note && <p>Ghi chú: {label.note}</p>}
                            <p>Người trả phí: {label.fee_payer}</p>
                        </div>
                        <div className="flex items-center justify-between border-2 border-black px-3 py-2">
                            <span className="font-semibold">
                                Tiền thu người nhận
                            </span>
                            <span className="text-xl font-bold">
                                {formatNumber(label.cod_amount)} đ
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
}
