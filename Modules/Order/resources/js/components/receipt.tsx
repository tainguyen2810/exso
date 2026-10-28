import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { QrInfo } from './payment-dialog';

export type PrintableOrder = {
    code: string;
    sale_at: string | null;
    branch: { name: string; address: string | null; phone: string | null };
    assignee: string | null;
    customer_name: string;
    customer_phone: string | null;
    receiver_name: string | null;
    receiver_phone: string | null;
    shipping_address: string | null;
    note: string | null;
    items: {
        name: string;
        sku: string | null;
        unit: string | null;
        quantity: number;
        price: number;
        discount: number;
        total: number;
    }[];
    subtotal: number;
    discount: number;
    shipping_fee: number;
    total: number;
    paid: number;
    returned_total: number;
    remaining: number;
    debt: number | null;
    qr: QrInfo | null;
    /** POS only: cash given and change. */
    cash_given?: number | null;
    change?: number | null;
};

export type StoreInfo = {
    name: string;
    phone: string | null;
    address: string;
    print_copies: number;
};

export type PrintSize = 'a4' | 'a5' | '80' | '58';

/** One invoice: a thermal bill (58/80 mm) or an A4/A5 page. */
export function Receipt({
    order,
    store,
    size,
}: {
    order: PrintableOrder;
    store: StoreInfo;
    size: PrintSize;
}) {
    const thermal = size === '80' || size === '58';

    return (
        <div
            className={cn(
                'receipt mx-auto bg-white text-black',
                thermal ? 'px-1 py-2 font-mono' : 'p-8 text-sm',
                size === '80' && 'w-[72mm] text-[11px]',
                size === '58' && 'w-[48mm] text-[10px]',
                size === 'a5' && 'w-[148mm]',
                size === 'a4' && 'w-[210mm]',
            )}
        >
            <div className={cn('text-center', thermal ? 'mb-2' : 'mb-4')}>
                <div
                    className={cn(
                        'font-bold uppercase',
                        thermal ? 'text-sm' : 'text-lg',
                    )}
                >
                    {store.name}
                </div>
                <div>{order.branch.address ?? store.address}</div>
                {(order.branch.phone ?? store.phone) && (
                    <div>ĐT: {order.branch.phone ?? store.phone}</div>
                )}
                <div
                    className={cn(
                        'mt-2 font-bold uppercase',
                        thermal ? 'text-xs' : 'text-base',
                    )}
                >
                    Hóa đơn bán hàng
                </div>
                <div>Số: {order.code}</div>
                <div>{formatDateTime(order.sale_at)}</div>
            </div>
            <div className="mb-2 grid gap-0.5">
                <div>Khách hàng: {order.customer_name}</div>
                {order.customer_phone && <div>SĐT: {order.customer_phone}</div>}
                {order.shipping_address && (
                    <div>
                        Giao tới: {order.receiver_name ?? ''}{' '}
                        {order.receiver_phone
                            ? `- ${order.receiver_phone}`
                            : ''}
                        , {order.shipping_address}
                    </div>
                )}
                {order.assignee && <div>Thu ngân: {order.assignee}</div>}
            </div>
            {thermal ? (
                <div className="border-y border-dashed border-black py-1">
                    {order.items.map((item, index) => (
                        <div key={index} className="py-0.5">
                            <div>{item.name}</div>
                            <div className="flex justify-between">
                                <span>
                                    {formatNumber(item.quantity)} x{' '}
                                    {formatNumber(item.price)}
                                    {item.discount > 0
                                        ? ` (-${formatNumber(item.discount)})`
                                        : ''}
                                </span>
                                <span>{formatNumber(item.total)}</span>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <table className="w-full border-collapse">
                    <thead>
                        <tr className="border-y border-black text-left">
                            <th className="py-1">STT</th>
                            <th className="py-1">Sản phẩm</th>
                            <th className="py-1 text-right">SL</th>
                            <th className="py-1 text-right">Đơn giá</th>
                            <th className="py-1 text-right">Chiết khấu</th>
                            <th className="py-1 text-right">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.items.map((item, index) => (
                            <tr
                                key={index}
                                className="border-b border-gray-300"
                            >
                                <td className="py-1">{index + 1}</td>
                                <td className="py-1">
                                    {item.name}
                                    {item.sku && (
                                        <span className="text-gray-600">
                                            {' '}
                                            ({item.sku})
                                        </span>
                                    )}
                                </td>
                                <td className="py-1 text-right">
                                    {formatNumber(item.quantity)}{' '}
                                    {item.unit ?? ''}
                                </td>
                                <td className="py-1 text-right">
                                    {formatNumber(item.price)}
                                </td>
                                <td className="py-1 text-right">
                                    {item.discount > 0
                                        ? formatNumber(item.discount)
                                        : ''}
                                </td>
                                <td className="py-1 text-right">
                                    {formatNumber(item.total)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            <div
                className={cn('mt-2 grid gap-0.5', !thermal && 'ml-auto w-72')}
            >
                <Row label="Tổng tiền hàng" value={order.subtotal} />
                {order.discount > 0 && (
                    <Row label="Chiết khấu" value={-order.discount} />
                )}
                {order.shipping_fee > 0 && (
                    <Row label="Phí giao hàng" value={order.shipping_fee} />
                )}
                <Row label="Khách phải trả" value={order.total} strong />
                {order.returned_total > 0 && (
                    <Row label="Hàng trả lại" value={-order.returned_total} />
                )}
                <Row label="Đã thanh toán" value={order.paid} />
                {order.cash_given != null && (
                    <Row label="Tiền khách đưa" value={order.cash_given} />
                )}
                {order.change != null && order.change > 0 && (
                    <Row label="Tiền thừa trả khách" value={order.change} />
                )}
                {order.remaining > 0 && (
                    <Row label="Còn phải trả" value={order.remaining} strong />
                )}
                {order.debt != null && order.debt !== 0 && (
                    <Row label="Tổng nợ hiện tại" value={order.debt} />
                )}
            </div>
            {order.qr && (
                <div className="mt-3 flex flex-col items-center gap-1 text-center">
                    <div
                        className={cn(
                            '[&>svg]:size-full',
                            thermal ? 'size-32' : 'size-40',
                        )}
                        dangerouslySetInnerHTML={{ __html: order.qr.svg }}
                    />
                    <div>
                        Quét VietQR để thanh toán{' '}
                        {formatNumber(order.qr.amount)}đ
                    </div>
                    <div>
                        {order.qr.bank} - {order.qr.account_number}
                    </div>
                    {order.qr.account_name && (
                        <div>{order.qr.account_name}</div>
                    )}
                </div>
            )}
            {order.note && <div className="mt-2">Ghi chú: {order.note}</div>}
            <div className="mt-3 text-center italic">
                Cảm ơn quý khách và hẹn gặp lại!
            </div>
        </div>
    );
}

function Row({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: number;
    strong?: boolean;
}) {
    return (
        <div
            className={cn('flex justify-between gap-2', strong && 'font-bold')}
        >
            <span>{label}</span>
            <span>{formatNumber(value)}</span>
        </div>
    );
}

/** Page CSS for the paper size; the screen shows the same layout. */
export function printCss(size: PrintSize) {
    const page = size === 'a4' ? 'A4' : size === 'a5' ? 'A5' : `${size}mm auto`;

    return `@page { size: ${page}; margin: ${size === '80' || size === '58' ? '0' : '10mm'}; }
@media print { body { background: white !important; } .no-print { display: none !important; } .receipt { page-break-after: always; } }`;
}
