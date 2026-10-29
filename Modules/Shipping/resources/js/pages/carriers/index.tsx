import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy, Plus, RefreshCw, Truck } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { Field } from '@/components/app/field';
import { OptionSelect } from '@/components/app/option-select';
import { RecordDialog } from '@/components/app/settings-page';
import { Pill, StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { HttpError, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';

type Integrated = {
    driver: string;
    name: string;
    connected: boolean;
    is_active: boolean;
    is_sandbox: boolean;
    token: string | null;
    shop_id: string | null;
    shop_name: string | null;
    from_district_id: number | null;
    branch_shops: Record<string, string>;
    webhook_url: string | null;
    connected_at: string | null;
};

type Partner = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    note: string | null;
    is_active: boolean;
    shipments_count: number;
    debt: number;
    fee_total: number;
};

type Option = { id: number; name: string };

/** "Kết nối đối tác" like Sapo: integrated carriers and own partners. */
export default function CarriersIndex({
    integrated,
    partners,
    branches,
}: {
    integrated: Integrated[];
    partners: Partner[];
    branches: Option[];
}) {
    const can = useCan();
    const [tab, setTab] = useState<'integrated' | 'manual'>('integrated');
    const [connecting, setConnecting] = useState<Integrated | null>(null);
    const [partner, setPartner] = useState<Partner | 'new' | null>(null);

    return (
        <>
            <Head title="Kết nối đối tác" />
            <div className="mx-auto w-full max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <Card className="gap-0 py-0">
                    <div className="flex flex-wrap items-center gap-5 border-b px-5 text-sm">
                        {(
                            [
                                ['integrated', 'Đối tác tích hợp'],
                                ['manual', 'Đối tác tự liên hệ'],
                            ] as const
                        ).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setTab(key)}
                                className={cn(
                                    '-mb-px border-b-2 py-3',
                                    tab === key
                                        ? 'border-primary font-medium text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                        {tab === 'manual' && can('carriers.create') && (
                            <Button
                                size="sm"
                                className="ml-auto"
                                onClick={() => setPartner('new')}
                            >
                                <Plus /> Thêm đối tác
                            </Button>
                        )}
                    </div>

                    {tab === 'integrated' ? (
                        <div className="grid gap-4 p-5 md:grid-cols-2">
                            {integrated.map((carrier) => (
                                <div
                                    key={carrier.driver}
                                    className="grid gap-3 rounded-md border p-4 text-sm"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-10 items-center justify-center rounded-md bg-orange-500 font-bold text-white">
                                            {carrier.driver.toUpperCase()}
                                        </span>
                                        <div className="flex-1">
                                            <div className="font-medium">
                                                {carrier.name}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {carrier.connected
                                                    ? `Kết nối từ ${formatDateTime(carrier.connected_at)}`
                                                    : 'Tạo vận đơn, tính phí, in nhãn và tự cập nhật trạng thái'}
                                            </div>
                                        </div>
                                        {carrier.connected ? (
                                            carrier.is_active ? (
                                                <Pill tone="success">
                                                    Đã kết nối
                                                </Pill>
                                            ) : (
                                                <Pill>Ngừng kết nối</Pill>
                                            )
                                        ) : (
                                            <Pill>Chưa kết nối</Pill>
                                        )}
                                    </div>
                                    {carrier.connected && (
                                        <div className="grid gap-1 text-muted-foreground">
                                            <span>
                                                Cửa hàng:{' '}
                                                {carrier.shop_name ??
                                                    carrier.shop_id}
                                                {carrier.is_sandbox &&
                                                    ' · Môi trường thử nghiệm'}
                                            </span>
                                            <span>Token: {carrier.token}</span>
                                        </div>
                                    )}
                                    {carrier.webhook_url && (
                                        <WebhookUrl carrier={carrier} />
                                    )}
                                    {can('carriers.update') && (
                                        <div>
                                            <Button
                                                variant={
                                                    carrier.connected
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                onClick={() =>
                                                    setConnecting(carrier)
                                                }
                                            >
                                                {carrier.connected
                                                    ? 'Cấu hình kết nối'
                                                    : 'Kết nối'}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : partners.length === 0 ? (
                        <div className="grid justify-items-center gap-2 px-5 py-12 text-center text-sm text-muted-foreground">
                            <Truck className="size-10" />
                            Chưa có đối tác tự liên hệ. Thêm đối tác (shipper,
                            nhà xe...) để tạo vận đơn và đối soát COD.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-5 py-2.5 font-normal">
                                            Mã đối tác
                                        </th>
                                        <th className="px-3 py-2.5 font-normal">
                                            Tên đối tác
                                        </th>
                                        <th className="px-3 py-2.5 font-normal">
                                            Số điện thoại
                                        </th>
                                        <th className="px-3 py-2.5 text-right font-normal">
                                            Số vận đơn
                                        </th>
                                        <th className="px-3 py-2.5 text-right font-normal">
                                            Công nợ
                                        </th>
                                        <th className="px-3 py-2.5 text-right font-normal">
                                            Tổng phí
                                        </th>
                                        <th className="px-5 py-2.5 font-normal">
                                            Trạng thái
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {partners.map((row) => (
                                        <tr key={row.id} className="border-t">
                                            <td className="px-5 py-2.5">
                                                <button
                                                    type="button"
                                                    className="text-primary hover:underline"
                                                    onClick={() =>
                                                        setPartner(row)
                                                    }
                                                >
                                                    {row.code}
                                                </button>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {row.name}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {row.phone ?? '—'}
                                            </td>
                                            <td className="px-3 py-2.5 text-right tabular-nums">
                                                <Link
                                                    href={`/shipments?filter[carrier_id]=${row.id}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {formatNumber(
                                                        row.shipments_count,
                                                    )}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2.5 text-right tabular-nums">
                                                {can(
                                                    'cod_reconciliations.create',
                                                ) && row.debt !== 0 ? (
                                                    <Link
                                                        href={`/cod-reconciliations/create?carrier_id=${row.id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {formatNumber(row.debt)}
                                                    </Link>
                                                ) : (
                                                    formatNumber(row.debt)
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5 text-right tabular-nums">
                                                {formatNumber(row.fee_total)}
                                            </td>
                                            <td className="px-5 py-2.5">
                                                <StatusBadge
                                                    status={
                                                        row.is_active
                                                            ? 'active'
                                                            : 'inactive'
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
                <p className="text-sm text-muted-foreground">
                    Công nợ = tiền thu hộ của vận đơn đã giao chưa đối soát trừ
                    phí giao hàng của vận đơn đã xong chưa đối soát.
                </p>
            </div>

            {connecting && (
                <ConnectDialog
                    key={connecting.driver}
                    carrier={connecting}
                    branches={branches}
                    onClose={() => setConnecting(null)}
                />
            )}
            {partner && (
                <PartnerDialog
                    key={partner === 'new' ? 'new' : partner.id}
                    partner={partner === 'new' ? null : partner}
                    onClose={() => setPartner(null)}
                />
            )}
        </>
    );
}

function WebhookUrl({ carrier }: { carrier: Integrated }) {
    const can = useCan();
    const [copied, setCopied] = useState(false);

    return (
        <div className="grid gap-1.5">
            <span className="text-muted-foreground">
                Địa chỉ webhook (dán vào trang quản lý của {carrier.name} để
                trạng thái cập nhật ngay):
            </span>
            <div className="flex gap-2">
                <Input
                    readOnly
                    value={carrier.webhook_url ?? ''}
                    className="font-mono text-xs"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Sao chép"
                    onClick={() => {
                        void navigator.clipboard?.writeText(
                            carrier.webhook_url ?? '',
                        );
                        setCopied(true);
                    }}
                >
                    <Copy />
                </Button>
                {can('carriers.update') && (
                    <ConfirmAction
                        url={`/shipping/partners/${carrier.driver}/webhook`}
                        title="Tạo địa chỉ webhook mới?"
                        description="Địa chỉ cũ ngừng hoạt động. Hãy dán địa chỉ mới vào trang quản lý của đối tác."
                        confirmLabel="Tạo mới"
                        trigger={
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Tạo địa chỉ mới"
                            >
                                <RefreshCw />
                            </Button>
                        }
                    />
                )}
            </div>
            {copied && (
                <span className="text-xs text-success">Đã sao chép.</span>
            )}
        </div>
    );
}

type Shop = {
    id: string;
    name: string;
    phone?: string | null;
    address?: string | null;
    district_id?: number | null;
};

function ConnectDialog({
    carrier,
    branches,
    onClose,
}: {
    carrier: Integrated;
    branches: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        token: '',
        is_sandbox: carrier.connected ? carrier.is_sandbox : false,
        is_active: carrier.connected ? carrier.is_active : true,
        shop_id: carrier.shop_id ?? '',
        shop_name: carrier.shop_name ?? '',
        from_district_id: carrier.from_district_id,
        branch_shops: { ...carrier.branch_shops } as Record<string, string>,
    });
    const [shops, setShops] = useState<Shop[] | null>(null);
    const [checking, setChecking] = useState(false);
    const [checkError, setCheckError] = useState<string | null>(null);
    const shopOptions = (shops ?? []).map((shop) => ({
        value: String(shop.id),
        label: `${shop.name} (${shop.id})`,
    }));

    const verify = async () => {
        setChecking(true);
        setCheckError(null);

        try {
            const result = await postJson<{ shops: Shop[] }>(
                `/shipping/partners/${carrier.driver}/verify`,
                {
                    token: form.data.token || null,
                    is_sandbox: form.data.is_sandbox,
                },
            );
            setShops(result.shops);
            const first =
                result.shops.find(
                    (shop) => String(shop.id) === form.data.shop_id,
                ) ?? result.shops[0];

            if (first) {
                form.setData((previous) => ({
                    ...previous,
                    shop_id: String(first.id),
                    shop_name: first.name,
                    from_district_id:
                        first.district_id ?? previous.from_district_id,
                }));
            }
        } catch (exception) {
            setShops(null);
            setCheckError(
                exception instanceof HttpError
                    ? exception.message
                    : 'Không kiểm tra được kết nối.',
            );
        } finally {
            setChecking(false);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        form.put(`/shipping/partners/${carrier.driver}`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div>
                        <DialogTitle>Kết nối {carrier.name}</DialogTitle>
                        <DialogDescription className="mt-1">
                            Lấy Token API ở trang quản lý của {carrier.name}{' '}
                            (Chủ cửa hàng › Xem thông tin Token). Token được mã
                            hóa khi lưu và không hiển thị lại.
                        </DialogDescription>
                    </div>
                    <Field label="Token API" error={form.errors.token}>
                        <div className="flex gap-2">
                            <Input
                                type="password"
                                autoComplete="off"
                                value={form.data.token}
                                placeholder={
                                    carrier.token
                                        ? `Đã lưu ${carrier.token} - để trống nếu không đổi`
                                        : ''
                                }
                                onChange={(event) =>
                                    form.setData('token', event.target.value)
                                }
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={checking}
                                onClick={() => void verify()}
                            >
                                Kiểm tra
                            </Button>
                        </div>
                    </Field>
                    {checkError && (
                        <p className="text-sm text-destructive">{checkError}</p>
                    )}
                    <label className="flex items-center justify-between gap-4 text-sm">
                        <span>
                            <span className="block font-medium">
                                Môi trường thử nghiệm
                            </span>
                            <span className="text-muted-foreground">
                                Dùng tài khoản sandbox của {carrier.name}
                            </span>
                        </span>
                        <Switch
                            checked={form.data.is_sandbox}
                            onCheckedChange={(checked) =>
                                form.setData('is_sandbox', checked)
                            }
                        />
                    </label>
                    <Field
                        label="Cửa hàng gửi hàng (ShopId)"
                        error={form.errors.shop_id}
                    >
                        {shops ? (
                            <OptionSelect
                                value={form.data.shop_id || null}
                                onChange={(value) => {
                                    const shop = shops.find(
                                        (item) => String(item.id) === value,
                                    );
                                    form.setData((previous) => ({
                                        ...previous,
                                        shop_id: value ?? '',
                                        shop_name: shop?.name ?? '',
                                        from_district_id:
                                            shop?.district_id ??
                                            previous.from_district_id,
                                    }));
                                }}
                                options={shopOptions}
                            />
                        ) : (
                            <Input
                                value={form.data.shop_id}
                                placeholder="Bấm Kiểm tra để chọn cửa hàng"
                                onChange={(event) =>
                                    form.setData('shop_id', event.target.value)
                                }
                            />
                        )}
                    </Field>
                    {branches.length > 1 && (
                        <div className="grid gap-2 text-sm">
                            <span className="font-medium">
                                Cửa hàng theo chi nhánh
                            </span>
                            <span className="text-muted-foreground">
                                Để trống thì chi nhánh dùng cửa hàng mặc định ở
                                trên.
                            </span>
                            {branches.map((branch) => (
                                <div
                                    key={branch.id}
                                    className="grid grid-cols-[1fr_1fr] items-center gap-3"
                                >
                                    <span>{branch.name}</span>
                                    {shops ? (
                                        <OptionSelect
                                            value={
                                                form.data.branch_shops[
                                                    branch.id
                                                ] ?? null
                                            }
                                            onChange={(value) =>
                                                form.setData('branch_shops', {
                                                    ...form.data.branch_shops,
                                                    [branch.id]: value ?? '',
                                                })
                                            }
                                            options={shopOptions}
                                            emptyLabel="Mặc định"
                                        />
                                    ) : (
                                        <Input
                                            value={
                                                form.data.branch_shops[
                                                    branch.id
                                                ] ?? ''
                                            }
                                            placeholder="ShopId"
                                            onChange={(event) =>
                                                form.setData('branch_shops', {
                                                    ...form.data.branch_shops,
                                                    [branch.id]:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                    <label className="flex items-center justify-between gap-4 text-sm">
                        <span className="font-medium">Đang sử dụng</span>
                        <Switch
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked)
                            }
                        />
                    </label>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Hủy
                        </Button>
                        <Button disabled={form.processing}>Lưu kết nối</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PartnerDialog({
    partner,
    onClose,
}: {
    partner: Partner | null;
    onClose: () => void;
}) {
    const can = useCan();
    const form = useForm({
        name: partner?.name ?? '',
        phone: partner?.phone ?? '',
        note: partner?.note ?? '',
        is_active: partner?.is_active ?? true,
    });

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={
                partner
                    ? `Sửa đối tác ${partner.code}`
                    : 'Thêm đối tác vận chuyển'
            }
            processing={form.processing}
            onSubmit={() =>
                partner
                    ? form.put(`/shipping/partners/manual/${partner.id}`, {
                          preserveScroll: true,
                          onSuccess: onClose,
                      })
                    : form.post('/shipping/partners', {
                          preserveScroll: true,
                          onSuccess: onClose,
                      })
            }
            extra={
                partner &&
                can('carriers.delete') &&
                partner.shipments_count === 0 && (
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={() =>
                            router.delete(
                                `/shipping/partners/manual/${partner.id}`,
                                {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                },
                            )
                        }
                    >
                        Xóa
                    </Button>
                )
            }
        >
            <Field label="Tên đối tác" error={form.errors.name}>
                <Input
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field label="Số điện thoại" error={form.errors.phone}>
                <Input
                    value={form.data.phone}
                    inputMode="tel"
                    onChange={(event) =>
                        form.setData('phone', event.target.value)
                    }
                />
            </Field>
            <Field label="Ghi chú" error={form.errors.note}>
                <Input
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
            <label className="flex items-center justify-between gap-4 text-sm">
                <span className="font-medium">Đang hoạt động</span>
                <Switch
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData('is_active', checked)
                    }
                />
            </label>
        </RecordDialog>
    );
}

CarriersIndex.layout = {
    breadcrumbs: [{ title: 'Kết nối đối tác', href: '/shipping/partners' }],
};
