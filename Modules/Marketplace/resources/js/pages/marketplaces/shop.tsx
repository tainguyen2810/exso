import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeft, Copy, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { Field } from '@/components/app/field';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { RecordDialog, SettingsSection } from '@/components/app/settings-page';
import { StatusPill } from '@/components/app/status-badge';
import { PageTitle, TopbarActions } from '@/components/app/topbar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';
import { formatDateTime } from '@/lib/format';
import { PlatformLogo, shopStatuses } from '../../components/marketplace';
import type { ShopSummary } from '../../components/marketplace';

type Option = { id: number; name: string };

type Shop = ShopSummary & {
    partner_id: string | null;
    partner_key: string | null;
    webhook_url: string | null;
    token_expires_at: string | null;
    refresh_expires_at: string | null;
    settings: {
        price_policy: string | null;
        assigned_to: number | null;
        source_id: number | null;
        payment_method_id: number | null;
        sync_orders: boolean;
        sync_stock: boolean;
        stock_branch_ids: number[];
        auto_receive_return: boolean;
    };
};

/** "Cấu hình riêng từng gian hàng": how a shop syncs with EXSO, like Sapo. */
export default function MarketplaceShop({
    shop,
    shops,
    branches,
    pricePolicies,
    staff,
    sources,
    paymentMethods,
}: {
    shop: Shop;
    shops: { id: number; name: string; platform: string }[];
    branches: Option[];
    pricePolicies: { value: string; label: string }[];
    staff: Option[];
    sources: Option[];
    paymentMethods: Option[];
}) {
    const can = useCan();
    const editable = can('marketplaces.update');
    const [reauthorizing, setReauthorizing] = useState(false);
    const form = useForm({
        name: shop.name.slice(0, 32),
        branch_id: shop.branch_id,
        ...shop.settings,
    });
    const id = (value: number | null) => (value ? String(value) : null);
    const save = useRef<() => void>(() => undefined);
    save.current = () => form.put(`/marketplaces/shops/${shop.id}`, { preserveScroll: true });

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F1' || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's')) {
                event.preventDefault();
                save.current();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <>
            <Head title={`Cấu hình gian hàng ${shop.name}`} />
            <PageTitle>
                <Link
                    href="/marketplaces/settings"
                    className="inline-flex items-center gap-1 truncate text-sm text-muted-foreground hover:text-primary"
                >
                    <ChevronLeft className="size-4" /> Cấu hình sàn TMĐT
                </Link>
            </PageTitle>
            {editable && (
                <TopbarActions>
                    <Button variant="outline" disabled={!form.isDirty || form.processing} onClick={() => form.reset()}>
                        Hủy
                    </Button>
                    <Button disabled={!form.isDirty || form.processing} onClick={() => save.current()} title="Lưu (F1 hoặc Ctrl+S)">
                        Lưu
                    </Button>
                </TopbarActions>
            )}
            <div className="mx-auto w-full max-w-[1100px] px-4 pt-5 pb-10 md:px-8">
                <div className="mb-5 flex flex-wrap items-center gap-3">
                    <PlatformLogo platform={shop.platform} className="size-8 text-sm" />
                    <h2 className="text-xl font-medium">Thiết lập đồng bộ giữa EXSO và gian hàng {shop.platform_label}</h2>
                    <StatusPill statuses={shopStatuses} value={shop.status} />
                    {shops.length > 1 && (
                        <OptionSelect
                            className="ml-auto w-60 bg-card"
                            value={String(shop.id)}
                            onChange={(value) => value && router.visit(`/marketplaces/shops/${value}`)}
                            options={shops.map((item) => ({ value: String(item.id), label: item.name }))}
                        />
                    )}
                </div>
                {shop.last_error && (
                    <div className="mb-5 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {shop.last_error}
                    </div>
                )}
                <fieldset disabled={!editable} className="grid min-w-0 gap-8">
                    <SettingsSection title="Thiết lập chung">
                        <Field label="Tên rút gọn" htmlFor="name" error={form.errors.name}>
                            <p className="-mt-1 text-xs text-muted-foreground">
                                Tên viết tắt của gian hàng trên EXSO giúp nhận biết và phân biệt các gian hàng với nhau.
                            </p>
                            <Input id="name" maxLength={32} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        </Field>
                    </SettingsSection>

                    <SettingsSection title="Cấu hình đồng bộ đơn hàng" description="Thiết lập đồng bộ đơn hàng giữa sàn và EXSO.">
                        <div className="grid gap-5">
                            <Toggle
                                label="Tự động lấy đơn hàng từ sàn"
                                help="Đơn mới và thay đổi trạng thái về EXSO trong vài phút (qua thông báo của sàn và lịch 5 phút/lần)."
                                checked={form.data.sync_orders}
                                onChange={(checked) => form.setData('sync_orders', checked)}
                            />
                            <Field label="Chính sách giá đồng bộ đơn hàng về EXSO" error={form.errors.price_policy}>
                                <OptionSelect
                                    value={form.data.price_policy}
                                    onChange={(value) => form.setData('price_policy', value)}
                                    options={pricePolicies}
                                    emptyLabel="Giá bán trên sàn (khách đã trả)"
                                />
                            </Field>
                            <Toggle
                                label="Tự động xác nhận nhận hàng hoàn với đơn “Giao hàng thất bại”"
                                help="Đơn bị hủy sau khi đã giao cho vận chuyển: hàng tự nhập lại kho khi sàn báo hủy."
                                checked={form.data.auto_receive_return}
                                onChange={(checked) => form.setData('auto_receive_return', checked)}
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Chi nhánh nhận đơn hàng từ gian hàng" error={form.errors.branch_id}>
                                    <OptionSelect
                                        value={String(form.data.branch_id)}
                                        onChange={(value) => form.setData('branch_id', Number(value))}
                                        options={toSelectOptions(branches)}
                                    />
                                </Field>
                                <Field label="Nhân viên phụ trách đơn hàng" error={form.errors.assigned_to}>
                                    <OptionSelect
                                        value={id(form.data.assigned_to)}
                                        onChange={(value) => form.setData('assigned_to', value ? Number(value) : null)}
                                        options={toSelectOptions(staff)}
                                        emptyLabel="Người kết nối gian hàng"
                                    />
                                </Field>
                                <Field label="Nguồn đơn" error={form.errors.source_id}>
                                    <OptionSelect
                                        value={id(form.data.source_id)}
                                        onChange={(value) => form.setData('source_id', value ? Number(value) : null)}
                                        options={toSelectOptions(sources)}
                                        emptyLabel={`Nguồn ${shop.platform_label}`}
                                    />
                                </Field>
                                <Field label="Ghi nhận thanh toán khi đơn hoàn thành" error={form.errors.payment_method_id}>
                                    <OptionSelect
                                        value={id(form.data.payment_method_id)}
                                        onChange={(value) => form.setData('payment_method_id', value ? Number(value) : null)}
                                        options={toSelectOptions(paymentMethods)}
                                        emptyLabel="Không ghi nhận"
                                    />
                                </Field>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection title="Cấu hình đồng bộ sản phẩm" description="Tồn kho trên sàn lấy theo “Có thể bán” (tồn kho − đang giao dịch) của các chi nhánh đã chọn.">
                        <div className="grid gap-4">
                            <Toggle
                                label="Tự động đồng bộ tồn kho sản phẩm"
                                checked={form.data.sync_stock}
                                onChange={(checked) => form.setData('sync_stock', checked)}
                            />
                            {form.data.sync_stock && (
                                <Field label="Chọn chi nhánh đồng bộ tồn kho với gian hàng" error={form.errors.stock_branch_ids}>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {branches.map((branch) => (
                                            <label key={branch.id} className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={form.data.stock_branch_ids.includes(branch.id)}
                                                    onCheckedChange={(checked) =>
                                                        form.setData(
                                                            'stock_branch_ids',
                                                            checked
                                                                ? [...form.data.stock_branch_ids, branch.id]
                                                                : form.data.stock_branch_ids.filter((item) => item !== branch.id),
                                                        )
                                                    }
                                                />
                                                {branch.name}
                                            </label>
                                        ))}
                                    </div>
                                </Field>
                            )}
                        </div>
                    </SettingsSection>
                </fieldset>

                <div className="mt-8">
                    <SettingsSection
                        title="Kết nối"
                        description="Partner Key và token được mã hóa, không bao giờ gửi ra trình duyệt. Token tự gia hạn; sau 30 ngày không dùng cần cấp quyền lại."
                    >
                        <div className="grid gap-3 text-sm">
                            <Info label="Shop ID">{shop.external_id ?? '—'}</Info>
                            <Info label="Partner ID">{shop.partner_id ?? '—'}</Info>
                            <Info label="Partner Key">{shop.partner_key ?? '—'}</Info>
                            <Info label="Môi trường">{shop.is_sandbox ? 'Thử nghiệm (Sandbox)' : 'Chính thức'}</Info>
                            <Info label="Ngày kết nối">{formatDateTime(shop.connected_at)}</Info>
                            <Info label="Hạn cấp quyền">{formatDateTime(shop.refresh_expires_at)}</Info>
                            <Info label="Lấy đơn gần nhất">{formatDateTime(shop.orders_synced_at)}</Info>
                            <Info label="Tải sản phẩm gần nhất">{formatDateTime(shop.products_synced_at)}</Info>
                            {shop.webhook_url && (
                                <Info label="URL nhận thông báo (Push)">
                                    <div className="flex items-center gap-2">
                                        <code className="truncate rounded bg-muted px-2 py-1 text-xs">{shop.webhook_url}</code>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Sao chép"
                                            onClick={() => navigator.clipboard?.writeText(shop.webhook_url ?? '')}
                                        >
                                            <Copy className="size-4" />
                                        </Button>
                                        {editable && (
                                            <ConfirmAction
                                                url={`/marketplaces/shops/${shop.id}/webhook`}
                                                title="Tạo URL nhận thông báo mới?"
                                                description="URL cũ sẽ ngừng hoạt động. Hãy dán URL mới vào mục Push Mechanism của ứng dụng trên Shopee Open Platform."
                                                confirmLabel="Tạo URL mới"
                                                trigger={
                                                    <Button variant="ghost" size="sm" title="Tạo URL mới">
                                                        <RefreshCw className="size-4" />
                                                    </Button>
                                                }
                                            />
                                        )}
                                    </div>
                                </Info>
                            )}
                            <div className="mt-2 flex flex-wrap gap-2">
                                {editable && <Button onClick={() => setReauthorizing(true)}>Cấp quyền lại</Button>}
                                {can('marketplaces.delete') && shop.status !== 'disconnected' && (
                                    <ConfirmAction
                                        url={`/marketplaces/shops/${shop.id}/disconnect`}
                                        title={`Ngắt kết nối ${shop.name}?`}
                                        description="EXSO ngừng lấy đơn và đẩy tồn kho lên gian hàng này. Liên kết sản phẩm và đơn đã về được giữ lại."
                                        confirmLabel="Ngắt kết nối"
                                        destructive
                                        trigger={<Button variant="outline">Ngắt kết nối</Button>}
                                    />
                                )}
                                {can('marketplaces.delete') && (
                                    <ConfirmAction
                                        onConfirm={() => router.delete(`/marketplaces/shops/${shop.id}`)}
                                        title={`Xóa gian hàng ${shop.name}?`}
                                        description="Chỉ xóa được gian hàng chưa có đơn hàng về EXSO."
                                        confirmLabel="Xóa"
                                        destructive
                                        trigger={
                                            <Button variant="outline" className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive">
                                                Xóa gian hàng
                                            </Button>
                                        }
                                    />
                                )}
                            </div>
                        </div>
                    </SettingsSection>
                </div>
            </div>
            <ReauthorizeDialog shop={shop} open={reauthorizing} onOpenChange={setReauthorizing} />
        </>
    );
}

function ReauthorizeDialog({ shop, open, onOpenChange }: { shop: Shop; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ partner_id: '', partner_key: '', is_sandbox: shop.is_sandbox });

    return (
        <RecordDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Cấp quyền lại"
            description="Để trống Partner ID / Partner Key nếu không đổi. Sau khi lưu, đăng nhập gian hàng trên sàn để cấp quyền cho EXSO."
            processing={form.processing}
            onSubmit={() => form.post(`/marketplaces/shops/${shop.id}/authorize`)}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Partner ID mới" htmlFor="re_partner_id" error={form.errors.partner_id}>
                    <Input id="re_partner_id" placeholder={shop.partner_id ?? ''} value={form.data.partner_id} onChange={(event) => form.setData('partner_id', event.target.value)} />
                </Field>
                <Field label="Partner Key mới" htmlFor="re_partner_key" error={form.errors.partner_key}>
                    <Input
                        id="re_partner_key"
                        type="password"
                        autoComplete="off"
                        placeholder={shop.partner_key ?? ''}
                        value={form.data.partner_key}
                        onChange={(event) => form.setData('partner_key', event.target.value)}
                    />
                </Field>
            </div>
            <label className="flex items-center gap-3 text-sm">
                <Switch checked={form.data.is_sandbox} onCheckedChange={(checked) => form.setData('is_sandbox', checked)} />
                Môi trường thử nghiệm (Sandbox)
            </label>
        </RecordDialog>
    );
}

function Toggle({ label, help, checked, onChange }: { label: string; help?: string; checked: boolean; onChange: (checked: boolean) => void }) {
    return (
        <label className="flex items-start justify-between gap-4">
            <span>
                <span className="text-sm font-medium">{label}</span>
                {help && <span className="mt-0.5 block text-xs text-muted-foreground">{help}</span>}
            </span>
            <Switch checked={checked} onCheckedChange={onChange} />
        </label>
    );
}

function Info({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[180px_minmax(0,1fr)] sm:items-center">
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0">{children}</span>
        </div>
    );
}

MarketplaceShop.layout = {
    breadcrumbs: [
        { title: 'Sàn TMĐT', href: '/marketplaces' },
        { title: 'Cấu hình gian hàng', href: '/marketplaces/settings' },
    ],
};
