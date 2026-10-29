import { Link } from '@inertiajs/react';
import type { StatusMap } from '@/components/app/status-badge';
import { cn } from '@/lib/utils';

export const einvoiceStatuses: StatusMap = {
    draft: ['Chưa phát hành', 'warning'],
    issued: ['Đã phát hành', 'success'],
    failed: ['Phát hành lỗi', 'danger'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const einvoiceKinds: StatusMap = {
    original: ['Hóa đơn gốc', 'neutral'],
    adjustment: ['Hóa đơn điều chỉnh', 'info'],
};

export const adjustmentLabels: Record<string, string> = {
    increase: 'Điều chỉnh tăng',
    decrease: 'Điều chỉnh giảm',
    info: 'Điều chỉnh thông tin',
};

/** Tabs above the two lists, like Sapo's "Hóa đơn điện tử" screen. */
export function EinvoiceTabs({ active }: { active: 'invoices' | 'pending' }) {
    const tabs = [
        ['invoices', 'Danh sách hóa đơn', '/e-invoices'],
        ['pending', 'Đơn chưa tạo HĐĐT', '/e-invoices/pending'],
    ] as const;

    return (
        <div className="mb-4 flex gap-6 border-b text-sm">
            {tabs.map(([key, label, href]) => (
                <Link
                    key={key}
                    href={href}
                    className={cn(
                        '-mb-px border-b-2 px-1 pb-2.5 font-medium',
                        key === active
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {label}
                </Link>
            ))}
        </div>
    );
}

/** Banner while no provider is connected. */
export function ConnectBanner({ canConfigure }: { canConfigure: boolean }) {
    return (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning-border bg-warning-soft px-4 py-3 text-sm">
            <span>
                Chưa kết nối nhà cung cấp hóa đơn điện tử. Vẫn tạo được hóa đơn
                nháp, kết nối để phát hành.
            </span>
            {canConfigure && (
                <Link
                    href="/e-invoices/settings"
                    className="font-medium text-primary hover:underline"
                >
                    Kết nối ngay
                </Link>
            )}
        </div>
    );
}
