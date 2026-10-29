import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, PackageCheck, Settings, Truck } from 'lucide-react';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { PeriodPicker } from '@/components/app/period-picker';
import type { Period } from '@/components/app/period-picker';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';
import { ShippingChart } from '../../components/shipping-chart';
import type { ShippingPoint } from '../../components/shipping-chart';
import { shipmentStatuses } from '../../components/statuses';

type Option = { id: number; name: string };

type Props = {
    states: { status: string; count: number; cod: number }[];
    waiting: { count: number; total: number };
    late: {
        pick: number;
        delivery: number;
        pick_days: number;
        delivery_days: number;
    };
    reconciliation: { key: string; count: number; cod: number; fee: number }[];
    chart: ShippingPoint[];
    partners: {
        carrier: string;
        count: number;
        fee: number;
        cod: number;
        delivered: number;
        failed: number;
    }[];
    filters: {
        branch_id: number | null;
        carrier_id: number | null;
        from: string;
        to: string;
    };
    branches: Option[];
    carriers: Option[];
};

const RECONCILIATION: Record<string, [string, string]> = {
    collecting: ['Đang thu hộ', 'Vận đơn đang giao có thu hộ COD'],
    waiting: ['Chờ đối soát', 'Đã giao/hoàn xong, chưa đối soát với đối tác'],
    reconciled: ['Đã đối soát', 'Đối tác đã thanh toán tiền thu hộ'],
};

const COLORS = [
    '#0088FF',
    '#00B37E',
    '#F5A623',
    '#E5484D',
    '#8E4EC6',
    '#9CD1FF',
];

/**
 * "Tổng quan vận chuyển" like Sapo: delivery and reconciliation of the last
 * 30 days, shipments and fees per day, share and success rate per partner.
 */
export default function ShippingOverview({
    states,
    waiting,
    late,
    reconciliation,
    chart,
    partners,
    filters,
    branches,
    carriers,
}: Props) {
    const can = useCan();
    const reload = (patch: Partial<Props['filters']>) =>
        router.get(
            '/shipping',
            Object.fromEntries(
                Object.entries({ ...filters, ...patch }).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveScroll: true, preserveState: true },
        );
    const total = partners.reduce((sum, partner) => sum + partner.count, 0);
    const delivered = partners.reduce((sum, row) => sum + row.delivered, 0);
    const failed = partners.reduce((sum, row) => sum + row.failed, 0);
    const finished = delivered + failed;

    return (
        <>
            <Head title="Tổng quan vận chuyển" />
            <div className="mx-auto w-full max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="flex flex-wrap items-center gap-3">
                    <OptionSelect
                        className="w-56 bg-card"
                        value={
                            filters.branch_id ? String(filters.branch_id) : null
                        }
                        onChange={(value) =>
                            reload({ branch_id: value ? Number(value) : null })
                        }
                        options={toSelectOptions(branches)}
                        emptyLabel="Tất cả chi nhánh"
                    />
                    {can('settings.view') && (
                        <Link
                            href="/shipping/settings"
                            className="ml-auto flex items-center gap-1.5 text-sm hover:text-primary"
                        >
                            <Settings className="size-4" /> Cấu hình giao hàng
                        </Link>
                    )}
                </div>

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>
                            Tình hình giao hàng
                            <span className="ml-2 text-sm font-normal text-muted-foreground">
                                30 ngày gần nhất
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StateCard
                            href="/orders?filter[status]=approved"
                            label="Chờ đóng gói"
                            count={waiting.count}
                            money={waiting.total}
                            moneyLabel="Giá trị đơn"
                            icon={<PackageCheck className="size-4" />}
                        />
                        {states.map((state) => (
                            <StateCard
                                key={state.status}
                                href={`/shipments?filter[status]=${state.status}`}
                                label={
                                    shipmentStatuses[state.status]?.[0] ??
                                    state.status
                                }
                                count={state.count}
                                money={state.cod}
                                moneyLabel="COD"
                                icon={<Truck className="size-4" />}
                            />
                        ))}
                    </CardContent>
                    {(late.pick > 0 || late.delivery > 0) && (
                        <div className="flex flex-wrap gap-4 border-t px-5 py-3 text-sm text-warning">
                            {late.pick > 0 && (
                                <span className="flex items-center gap-1.5">
                                    <AlertTriangle className="size-4" />
                                    {formatNumber(late.pick)} vận đơn chờ lấy
                                    quá {late.pick_days} ngày
                                </span>
                            )}
                            {late.delivery > 0 && (
                                <span className="flex items-center gap-1.5">
                                    <AlertTriangle className="size-4" />
                                    {formatNumber(late.delivery)} vận đơn giao
                                    quá {late.delivery_days} ngày
                                </span>
                            )}
                        </div>
                    )}
                </Card>

                <Card className="gap-0 py-0">
                    <CardHeader className="flex flex-row flex-wrap items-center gap-3 border-b px-5 py-4">
                        <CardTitle>Tình hình đối soát</CardTitle>
                        <OptionSelect
                            className="ml-auto w-56"
                            value={
                                filters.carrier_id
                                    ? String(filters.carrier_id)
                                    : null
                            }
                            onChange={(value) =>
                                reload({
                                    carrier_id: value ? Number(value) : null,
                                })
                            }
                            options={toSelectOptions(carriers)}
                            emptyLabel="Tất cả đối tác"
                        />
                    </CardHeader>
                    <CardContent className="grid gap-3 px-5 py-4 sm:grid-cols-3">
                        {reconciliation.map((row) => (
                            <Link
                                key={row.key}
                                href={`/shipments?filter[cod_status]=${row.key}${filters.carrier_id ? `&filter[carrier_id]=${filters.carrier_id}` : ''}`}
                                className="rounded-md border px-4 py-3 hover:border-primary"
                            >
                                <div className="text-sm font-medium">
                                    {RECONCILIATION[row.key][0]}
                                </div>
                                <div className="mt-1 text-xl font-semibold tabular-nums">
                                    {formatNumber(row.cod)}
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {formatNumber(row.count)} vận đơn · phí{' '}
                                    {formatNumber(row.fee)}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {RECONCILIATION[row.key][1]}
                                </div>
                            </Link>
                        ))}
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-12">
                    <Card className="gap-0 py-0 lg:col-span-8">
                        <CardHeader className="flex flex-row flex-wrap items-center gap-3 border-b px-5 py-4">
                            <CardTitle>Số vận đơn và phí giao hàng</CardTitle>
                            <PeriodPicker
                                className="ml-auto"
                                label="Ngày tạo"
                                value={{ from: filters.from, to: filters.to }}
                                onChange={(period: Period) => reload(period)}
                            />
                        </CardHeader>
                        <ShippingChart points={chart} />
                    </Card>
                    <Card className="gap-0 py-0 lg:col-span-4">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Tỷ lệ đối tác vận chuyển</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 px-5 py-4 text-sm">
                            {partners.length === 0 ? (
                                <p className="text-muted-foreground">
                                    Chưa có vận đơn trong khoảng thời gian này.
                                </p>
                            ) : (
                                <>
                                    <div className="flex h-3 overflow-hidden rounded-full bg-muted">
                                        {partners.map((partner, index) => (
                                            <span
                                                key={partner.carrier}
                                                style={{
                                                    width: `${(partner.count / total) * 100}%`,
                                                    backgroundColor:
                                                        COLORS[
                                                            index %
                                                                COLORS.length
                                                        ],
                                                }}
                                            />
                                        ))}
                                    </div>
                                    <ul className="grid gap-2">
                                        {partners.map((partner, index) => (
                                            <li
                                                key={partner.carrier}
                                                className="flex items-center gap-2"
                                            >
                                                <span
                                                    className="size-2.5 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            COLORS[
                                                                index %
                                                                    COLORS.length
                                                            ],
                                                    }}
                                                />
                                                <span className="flex-1">
                                                    {partner.carrier}
                                                </span>
                                                <span className="tabular-nums">
                                                    {formatNumber(
                                                        partner.count,
                                                    )}{' '}
                                                    (
                                                    {Math.round(
                                                        (partner.count /
                                                            total) *
                                                            100,
                                                    )}
                                                    %)
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="grid grid-cols-2 gap-3 border-t pt-4">
                                        <div>
                                            <div className="text-muted-foreground">
                                                Giao thành công
                                            </div>
                                            <div className="text-lg font-semibold text-success tabular-nums">
                                                {finished > 0
                                                    ? `${Math.round((delivered / finished) * 100)}%`
                                                    : '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">
                                                Giao thất bại
                                            </div>
                                            <div className="text-lg font-semibold text-destructive tabular-nums">
                                                {finished > 0
                                                    ? `${Math.round((failed / finished) * 100)}%`
                                                    : '—'}
                                            </div>
                                        </div>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {partners.length > 0 && (
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Báo cáo chi tiết theo đối tác</CardTitle>
                        </CardHeader>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-5 py-2.5 font-normal">
                                            Đối tác vận chuyển
                                        </th>
                                        <th className="px-5 py-2.5 text-right font-normal">
                                            Số vận đơn
                                        </th>
                                        <th className="px-5 py-2.5 text-right font-normal">
                                            Tiền thu hộ
                                        </th>
                                        <th className="px-5 py-2.5 text-right font-normal">
                                            Phí giao hàng
                                        </th>
                                        <th className="px-5 py-2.5 text-right font-normal">
                                            Giao thành công
                                        </th>
                                        <th className="px-5 py-2.5 text-right font-normal">
                                            Giao thất bại
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {partners.map((partner) => (
                                        <tr
                                            key={partner.carrier}
                                            className="border-t"
                                        >
                                            <td className="px-5 py-2.5">
                                                {partner.carrier}
                                            </td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">
                                                {formatNumber(partner.count)}
                                            </td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">
                                                {formatNumber(partner.cod)}
                                            </td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">
                                                {formatNumber(partner.fee)}
                                            </td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">
                                                {formatNumber(
                                                    partner.delivered,
                                                )}
                                            </td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">
                                                {formatNumber(partner.failed)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

function StateCard({
    href,
    label,
    count,
    money,
    moneyLabel,
    icon,
}: {
    href: string;
    label: string;
    count: number;
    money: number;
    moneyLabel: string;
    icon: React.ReactNode;
}) {
    return (
        <Link
            href={href}
            className="rounded-md border px-4 py-3 hover:border-primary"
        >
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                {icon} {label}
            </div>
            <div className="mt-1 text-xl font-semibold tabular-nums">
                {formatNumber(count)}
            </div>
            <div className="text-xs text-muted-foreground">
                {moneyLabel}: {formatNumber(money)}
            </div>
        </Link>
    );
}

ShippingOverview.layout = {
    breadcrumbs: [{ title: 'Tổng quan vận chuyển', href: '/shipping' }],
};
