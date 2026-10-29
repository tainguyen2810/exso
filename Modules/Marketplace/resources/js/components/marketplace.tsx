import { useForm } from '@inertiajs/react';
import { Field } from '@/components/app/field';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { RecordDialog } from '@/components/app/settings-page';
import type { StatusMap } from '@/components/app/status-badge';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

export { statusOptions as toOptions } from '@/components/app/status-badge';

export const shopStatuses: StatusMap = {
    pending: ['Chờ cấp quyền', 'warning'],
    active: ['Đang hoạt động', 'success'],
    expired: ['Hết hạn', 'danger'],
    disconnected: ['Ngắt kết nối', 'neutral'],
};

/** Common state of a platform order (MarketplaceOrder::STATES). */
export const orderStates: StatusMap = {
    unpaid: ['Chờ thanh toán', 'neutral'],
    to_ship: ['Chờ lấy hàng', 'warning'],
    shipped: ['Đang giao', 'info'],
    completed: ['Hoàn thành', 'success'],
    cancelled: ['Đã hủy', 'danger'],
    returned: ['Trả hàng/Hoàn tiền', 'danger'],
};

export const syncStatuses: StatusMap = {
    synced: ['Thành công', 'success'],
    unlinked: ['Chưa liên kết sản phẩm', 'warning'],
    error: ['Lỗi', 'danger'],
};

export type ShopSummary = {
    id: number;
    platform: string;
    platform_label: string;
    external_id: string | null;
    name: string;
    status: string;
    branch_id: number;
    branch: string;
    is_sandbox: boolean;
    last_error: string | null;
    connected_at: string | null;
    orders_synced_at: string | null;
    products_synced_at: string | null;
};

const logos: Record<string, [string, string]> = {
    shopee: ['S', 'bg-[#EE4D2D]'],
    lazada: ['L', 'bg-[#0F146D]'],
    tiktok: ['T', 'bg-black'],
    tiki: ['T', 'bg-[#1A94FF]'],
};

/** Small square logo of the platform (letter on its brand colour). */
export function PlatformLogo({
    platform,
    className,
}: {
    platform: string;
    className?: string;
}) {
    const [letter, color] = logos[platform] ?? [
        platform.charAt(0).toUpperCase(),
        'bg-muted-foreground',
    ];

    return (
        <span
            className={cn(
                'inline-flex size-6 shrink-0 items-center justify-center rounded text-xs font-bold text-white',
                color,
                className,
            )}
        >
            {letter}
        </span>
    );
}

/**
 * "Kết nối gian hàng mới": the app keys of the platform, then the browser
 * goes to the platform for the seller to authorise EXSO.
 */
export function ConnectShopDialog({
    open,
    onOpenChange,
    platforms,
    branches,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    platforms: { value: string; label: string }[];
    branches: { id: number; name: string }[];
}) {
    const form = useForm({
        platform: platforms[0]?.value ?? 'shopee',
        partner_id: '',
        partner_key: '',
        branch_id: branches[0] ? String(branches[0].id) : '',
        is_sandbox: false,
    });

    return (
        <RecordDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Kết nối gian hàng mới"
            description="Nhập Partner ID và Partner Key của ứng dụng trên Shopee Open Platform, sau đó đăng nhập gian hàng để cấp quyền cho EXSO."
            processing={form.processing}
            onSubmit={() => form.post('/marketplaces/shops')}
        >
            <Field label="Sàn TMĐT" error={form.errors.platform}>
                <OptionSelect
                    value={form.data.platform}
                    onChange={(value) => form.setData('platform', value ?? '')}
                    options={platforms}
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Partner ID"
                    htmlFor="partner_id"
                    error={form.errors.partner_id}
                >
                    <Input
                        id="partner_id"
                        inputMode="numeric"
                        value={form.data.partner_id}
                        onChange={(event) =>
                            form.setData('partner_id', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Partner Key"
                    htmlFor="partner_key"
                    error={form.errors.partner_key}
                >
                    <Input
                        id="partner_key"
                        type="password"
                        autoComplete="off"
                        value={form.data.partner_key}
                        onChange={(event) =>
                            form.setData('partner_key', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field label="Chi nhánh nhận đơn" error={form.errors.branch_id}>
                <OptionSelect
                    value={form.data.branch_id}
                    onChange={(value) => form.setData('branch_id', value ?? '')}
                    options={toSelectOptions(branches)}
                />
            </Field>
            <label className="flex items-center gap-3 text-sm">
                <Switch
                    checked={form.data.is_sandbox}
                    onCheckedChange={(checked) =>
                        form.setData('is_sandbox', checked)
                    }
                />
                Môi trường thử nghiệm (Sandbox)
            </label>
        </RecordDialog>
    );
}
