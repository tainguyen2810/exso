import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Link2Off,
    PackageCheck,
    Plus,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { StatusPill } from '@/components/app/status-badge';
import { TopbarActions } from '@/components/app/topbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatNumber } from '@/lib/format';
import {
    ConnectShopDialog,
    PlatformLogo,
    shopStatuses,
} from '../../components/marketplace';
import type { ShopSummary } from '../../components/marketplace';

type Shop = ShopSummary & {
    listings: number;
    listings_linked: number;
    orders: number;
    orders_linked: number;
    today_value: number;
};

/** Sàn TMĐT → Tổng quan, like Sapo: today's figures and the connected shops. */
export default function MarketplaceOverview({
    today,
    states,
    shops,
    platforms,
    branches,
}: {
    today: { value: number; orders: number };
    states: Record<string, number>;
    shops: Shop[];
    platforms: { value: string; label: string }[];
    branches: { id: number; name: string }[];
}) {
    const can = useCan();
    const [connecting, setConnecting] = useState(false);

    return (
        <>
            <Head title="Sàn TMĐT" />
            {can('marketplaces.create') && (
                <TopbarActions>
                    <Button onClick={() => setConnecting(true)}>
                        <Plus /> Kết nối gian hàng mới
                    </Button>
                </TopbarActions>
            )}
            <div className="mx-auto w-full max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="grid gap-4 lg:grid-cols-[1fr_2fr]">
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Báo cáo nhanh hôm nay</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-3 px-5 py-4">
                            <Figure
                                label="Tổng giá trị"
                                value={formatNumber(today.value)}
                            />
                            <Figure
                                label="Đơn hàng"
                                value={formatNumber(today.orders)}
                            />
                        </CardContent>
                    </Card>
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>
                                Danh sách đơn
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    30 ngày gần nhất
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-3 px-5 py-4 md:grid-cols-3">
                            <State
                                href="/marketplaces/orders?filter[state]=to_ship"
                                icon={<PackageCheck className="size-4" />}
                                label="Chờ lấy hàng"
                                count={states.to_ship}
                            />
                            <State
                                href="/marketplaces/orders?filter[state]=shipped"
                                icon={<Truck className="size-4" />}
                                label="Đang giao"
                                count={states.shipped}
                            />
                            <State
                                href="/marketplaces/orders?filter[state]=completed"
                                icon={<CheckCircle2 className="size-4" />}
                                label="Hoàn thành"
                                count={states.completed}
                            />
                            <State
                                href="/marketplaces/orders?filter[state]=cancelled"
                                icon={<Ban className="size-4" />}
                                label="Đơn hủy"
                                count={states.cancelled}
                            />
                            <State
                                href="/marketplaces/orders?filter[sync_status]=error"
                                icon={<AlertTriangle className="size-4" />}
                                label="Đồng bộ lỗi"
                                count={states.errors}
                                warn
                            />
                            <State
                                href="/marketplaces/products?filter[link]=unlinked"
                                icon={<Link2Off className="size-4" />}
                                label="Sản phẩm chưa liên kết"
                                count={states.unlinked}
                                warn
                            />
                        </CardContent>
                    </Card>
                </div>

                <Card className="gap-0 py-0">
                    <CardHeader className="flex-row items-center justify-between border-b px-5 py-4">
                        <CardTitle>Kết nối sàn TMĐT</CardTitle>
                        <Link
                            href="/marketplaces/settings"
                            className="text-sm text-primary hover:underline"
                        >
                            Cấu hình
                        </Link>
                    </CardHeader>
                    {shops.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                            Chưa kết nối gian hàng nào. Kết nối gian hàng Shopee
                            để đơn hàng tự về EXSO và tồn kho tự cập nhật lên
                            sàn.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-5 py-2.5 font-medium">
                                            STT
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Tên gian hàng
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Trạng thái
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Ngày kết nối
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Sản phẩm liên kết
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Đơn hàng liên kết
                                        </th>
                                        <th className="px-5 py-2.5 text-right font-medium">
                                            Tổng giá trị hôm nay
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shops.map((shop, index) => (
                                        <tr key={shop.id} className="border-t">
                                            <td className="px-5 py-3">
                                                {index + 1}
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center gap-2">
                                                    <PlatformLogo
                                                        platform={shop.platform}
                                                    />
                                                    <div>
                                                        <Link
                                                            href={`/marketplaces/shops/${shop.id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {shop.name}
                                                        </Link>
                                                        <div className="text-xs text-muted-foreground">
                                                            {
                                                                shop.platform_label
                                                            }{' '}
                                                            · {shop.branch}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-3">
                                                <StatusPill
                                                    statuses={shopStatuses}
                                                    value={shop.status}
                                                />
                                            </td>
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                {formatDate(shop.connected_at)}
                                            </td>
                                            <td className="px-3 py-3">
                                                <Progress
                                                    href={`/marketplaces/products?filter[shop_id]=${shop.id}`}
                                                    done={shop.listings_linked}
                                                    total={shop.listings}
                                                />
                                            </td>
                                            <td className="px-3 py-3">
                                                <Progress
                                                    href={`/marketplaces/orders?filter[shop_id]=${shop.id}`}
                                                    done={shop.orders_linked}
                                                    total={shop.orders}
                                                />
                                            </td>
                                            <td className="px-5 py-3 text-right tabular-nums">
                                                {formatNumber(shop.today_value)}{' '}
                                                đ
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
            <ConnectShopDialog
                open={connecting}
                onOpenChange={setConnecting}
                platforms={platforms}
                branches={branches}
            />
        </>
    );
}

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md bg-accent px-4 py-3">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-1 text-xl font-semibold text-primary tabular-nums">
                {value}
            </div>
        </div>
    );
}

function State({
    href,
    icon,
    label,
    count,
    warn = false,
}: {
    href: string;
    icon: ReactNode;
    label: string;
    count: number;
    warn?: boolean;
}) {
    return (
        <Link
            href={href}
            className="rounded-md border px-4 py-3 hover:border-primary"
        >
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                {icon} {label}
            </div>
            <div
                className={`mt-1 text-xl font-semibold tabular-nums ${warn && count > 0 ? 'text-warning' : ''}`}
            >
                {formatNumber(count)}
            </div>
        </Link>
    );
}

function Progress({
    href,
    done,
    total,
}: {
    href: string;
    done: number;
    total: number;
}) {
    const percent = total === 0 ? 0 : Math.round((done / total) * 100);

    return (
        <Link href={href} className="block min-w-36 hover:opacity-80">
            <div className="text-center text-primary tabular-nums">
                {formatNumber(done)}/{formatNumber(total)}
            </div>
            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </Link>
    );
}

MarketplaceOverview.layout = {
    breadcrumbs: [{ title: 'Sàn TMĐT', href: '/marketplaces' }],
};
