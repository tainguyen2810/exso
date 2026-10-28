import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Receipt, printCss } from '../../components/receipt';
import type {
    PrintSize,
    PrintableOrder,
    StoreInfo,
} from '../../components/receipt';

/** Printable invoices; the print dialog opens by itself. */
export default function OrderPrint({
    orders,
    size,
    storeInfo: store,
}: {
    orders: PrintableOrder[];
    size: PrintSize;
    storeInfo: StoreInfo;
}) {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 300);

        return () => clearTimeout(timer);
    }, []);

    const copies = Math.max(1, Math.min(5, store.print_copies || 1));

    return (
        <>
            <Head
                title={`In đơn ${orders.map((order) => order.code).join(', ')}`}
            />
            <style>{printCss(size)}</style>
            <div className="min-h-screen bg-muted py-6 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex w-fit gap-2">
                    {(['a4', 'a5', '80', '58'] as const).map((item) => (
                        <Button
                            key={item}
                            size="sm"
                            variant={item === size ? 'default' : 'outline'}
                            asChild
                        >
                            <a href={`?size=${item}`}>
                                {item === 'a4' || item === 'a5'
                                    ? item.toUpperCase()
                                    : `K${item}`}
                            </a>
                        </Button>
                    ))}
                    <Button size="sm" onClick={() => window.print()}>
                        <Printer /> In
                    </Button>
                </div>
                <div className="grid gap-4 print:gap-0">
                    {orders.flatMap((order) =>
                        Array.from({ length: copies }, (_, copy) => (
                            <Receipt
                                key={`${order.code}-${copy}`}
                                order={order}
                                store={store}
                                size={size}
                            />
                        )),
                    )}
                </div>
            </div>
        </>
    );
}
