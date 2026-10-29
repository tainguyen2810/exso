import { Head, Link } from '@inertiajs/react';
import { Plus, Settings } from 'lucide-react';
import { useState } from 'react';
import { StatusPill } from '@/components/app/status-badge';
import { TopbarActions } from '@/components/app/topbar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatDateTime } from '@/lib/format';
import { ConnectShopDialog, PlatformLogo, shopStatuses } from '../../components/marketplace';
import type { ShopSummary } from '../../components/marketplace';

/** Sàn TMĐT → Cấu hình chung: the connected shops, like Sapo. */
export default function MarketplaceSettings({
    shops,
    platforms,
    branches,
}: {
    shops: ShopSummary[];
    platforms: { value: string; label: string }[];
    branches: { id: number; name: string }[];
}) {
    const can = useCan();
    const [connecting, setConnecting] = useState(false);
    const [platform, setPlatform] = useState<string | null>(null);
    const counts = platforms.map((item) => ({
        ...item,
        count: shops.filter((shop) => shop.platform === item.value).length,
    }));
    const visible = platform ? shops.filter((shop) => shop.platform === platform) : shops;

    return (
        <>
            <Head title="Cấu hình sàn TMĐT" />
            {can('marketplaces.create') && (
                <TopbarActions>
                    <Button onClick={() => setConnecting(true)}>
                        <Plus /> Kết nối gian hàng mới
                    </Button>
                </TopbarActions>
            )}
            <div className="mx-auto w-full max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="flex flex-wrap gap-2">
                    <Button variant={platform === null ? 'default' : 'outline'} size="sm" onClick={() => setPlatform(null)}>
                        Tất cả gian hàng ({shops.length})
                    </Button>
                    {counts.map((item) => (
                        <Button
                            key={item.value}
                            variant={platform === item.value ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setPlatform(item.value)}
                        >
                            <PlatformLogo platform={item.value} className="size-4 text-[10px]" /> {item.label} ({item.count})
                        </Button>
                    ))}
                </div>
                <Card className="gap-0 py-0">
                    {visible.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                            Chưa có gian hàng nào. Bấm “Kết nối gian hàng mới”, nhập Partner ID / Partner Key của ứng dụng Shopee Open Platform rồi
                            đăng nhập gian hàng để cấp quyền.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-5 py-2.5 font-medium">Gian hàng</th>
                                    <th className="px-3 py-2.5 font-medium">Trạng thái</th>
                                    <th className="px-3 py-2.5 font-medium">Chi nhánh nhận đơn</th>
                                    <th className="px-3 py-2.5 font-medium">Ngày kết nối</th>
                                    <th className="px-3 py-2.5 font-medium">Lấy đơn gần nhất</th>
                                    <th className="px-5 py-2.5 text-right font-medium">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((shop) => (
                                    <tr key={shop.id} className="border-t">
                                        <td className="px-5 py-3">
                                            <div className="flex items-center gap-2">
                                                <PlatformLogo platform={shop.platform} />
                                                <div>
                                                    <Link href={`/marketplaces/shops/${shop.id}`} className="text-primary hover:underline">
                                                        {shop.name}
                                                    </Link>
                                                    <div className="text-xs text-muted-foreground">
                                                        {shop.platform_label}
                                                        {shop.external_id ? ` · Shop ID ${shop.external_id}` : ''}
                                                        {shop.is_sandbox ? ' · Thử nghiệm' : ''}
                                                    </div>
                                                </div>
                                            </div>
                                            {shop.last_error && <div className="mt-1 text-xs text-destructive">{shop.last_error}</div>}
                                        </td>
                                        <td className="px-3 py-3">
                                            <StatusPill statuses={shopStatuses} value={shop.status} />
                                        </td>
                                        <td className="px-3 py-3">{shop.branch}</td>
                                        <td className="px-3 py-3 whitespace-nowrap">{formatDateTime(shop.connected_at)}</td>
                                        <td className="px-3 py-3 whitespace-nowrap">{formatDateTime(shop.orders_synced_at)}</td>
                                        <td className="px-5 py-3 text-right">
                                            <Button variant="ghost" size="sm" asChild title="Cấu hình riêng gian hàng">
                                                <Link href={`/marketplaces/shops/${shop.id}`}>
                                                    <Settings className="size-4" />
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </div>
            <ConnectShopDialog open={connecting} onOpenChange={setConnecting} platforms={platforms} branches={branches} />
        </>
    );
}

MarketplaceSettings.layout = {
    breadcrumbs: [
        { title: 'Sàn TMĐT', href: '/marketplaces' },
        { title: 'Cấu hình', href: '/marketplaces/settings' },
    ],
};
