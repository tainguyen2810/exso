import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    CircleAlert,
    ClipboardCheck,
    CreditCard,
    PackageCheck,
    PackageOpen,
    ReceiptText,
    RotateCcw,
    Truck,
    Undo2,
    Wallet,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { filterMenu, menu } from '@/config/menu';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

type Sales = {
    /** False until an orders module reports; the numbers are then zeros. */
    ready: boolean;
    today: {
        revenue: number;
        orders: number;
        returns: number;
        cancelled: number;
    };
    chart: { date: string; revenue: number }[];
    pending: Record<string, number>;
    top: { name: string; sku: string | null; quantity: number }[];
};

type Stock = {
    below_min: number;
    on_hand: number;
    value: number | null;
    links: { below_min: string; inventory: string };
};

type Activity = {
    id: number;
    user: string | null;
    description: string;
    created_at: string;
};

type Props = {
    branches: { id: number; name: string }[];
    branch: number | null;
    sales: Sales | null;
    stock: Stock | null;
    activity: Activity[] | null;
};

const kpis: {
    key: keyof Sales['today'];
    label: string;
    icon: LucideIcon;
    tone: string;
}[] = [
    { key: 'revenue', label: 'Doanh thu', icon: Wallet, tone: 'bg-[#0088FF]' },
    {
        key: 'orders',
        label: 'Đơn hàng mới',
        icon: ReceiptText,
        tone: 'bg-[#0DB473]',
    },
    {
        key: 'returns',
        label: 'Đơn trả hàng',
        icon: RotateCcw,
        tone: 'bg-[#FFB400]',
    },
    { key: 'cancelled', label: 'Đơn hủy', icon: XCircle, tone: 'bg-[#EE4747]' },
];

const pending: { key: string; label: string; icon: LucideIcon }[] = [
    { key: 'approval', label: 'Chờ duyệt', icon: ClipboardCheck },
    { key: 'payment', label: 'Chờ thanh toán', icon: CreditCard },
    { key: 'packing', label: 'Chờ đóng gói', icon: PackageOpen },
    { key: 'pickup', label: 'Chờ lấy hàng', icon: PackageCheck },
    { key: 'shipping', label: 'Đang giao hàng', icon: Truck },
    { key: 'returning', label: 'Hủy giao - chờ nhận', icon: Undo2 },
];

/** Tổng quan, laid out like Sapo's dashboard. */
export default function Dashboard({
    branches,
    branch,
    sales,
    stock,
    activity,
}: Props) {
    const { auth } = usePage().props;
    const can = useCan();

    const changeBranch = (value: string) =>
        router.get('/dashboard', value === 'all' ? {} : { branch: value }, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <>
            <Head title="Tổng quan" />
            <div className="grid items-start gap-4 px-4 pt-5 pb-10 md:px-8 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="grid min-w-0 gap-4">
                    {sales ? (
                        <>
                            <Panel
                                title="Kết quả kinh doanh trong ngày"
                                action={
                                    branches.length > 1 && (
                                        <Select
                                            value={
                                                branch ? String(branch) : 'all'
                                            }
                                            onValueChange={changeBranch}
                                        >
                                            <SelectTrigger className="h-8 w-48">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">
                                                    Tất cả chi nhánh
                                                </SelectItem>
                                                {branches.map((item) => (
                                                    <SelectItem
                                                        key={item.id}
                                                        value={String(item.id)}
                                                    >
                                                        {item.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )
                                }
                            >
                                <div className="grid grid-cols-2 gap-y-4 px-5 py-4 md:grid-cols-4 md:divide-x">
                                    {kpis.map((kpi) => (
                                        <div
                                            key={kpi.key}
                                            className="flex items-center gap-3 md:px-4 md:first:pl-0"
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-9 shrink-0 items-center justify-center rounded-full text-white',
                                                    kpi.tone,
                                                )}
                                            >
                                                <kpi.icon className="size-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <div className="text-sm">
                                                    {kpi.label}
                                                </div>
                                                <div className="truncate font-medium text-primary">
                                                    {formatNumber(
                                                        sales.today[kpi.key],
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </Panel>

                            <Panel title="Doanh thu bán hàng" tab>
                                <RevenueChart data={sales.chart} />
                                {!sales.ready && (
                                    <p className="px-5 pb-4 text-center text-xs text-muted-foreground">
                                        Số liệu bán hàng sẽ hiện khi bắt đầu tạo
                                        đơn và bán tại quầy.
                                    </p>
                                )}
                            </Panel>

                            <Panel title="Đơn hàng chờ xử lý">
                                <div className="grid grid-cols-2 py-4 sm:grid-cols-3 lg:grid-cols-6 lg:divide-x">
                                    {pending.map((item) => (
                                        <div
                                            key={item.key}
                                            className="flex flex-col items-center gap-1.5 px-2 py-2 text-center"
                                        >
                                            <span className="flex size-8 items-center justify-center rounded-full bg-accent text-primary">
                                                <item.icon className="size-4" />
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {item.label}
                                            </span>
                                            <span className="font-medium">
                                                {formatNumber(
                                                    sales.pending[item.key] ??
                                                        0,
                                                )}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </Panel>
                        </>
                    ) : (
                        <Welcome name={auth.user.name} />
                    )}

                    <div className="grid gap-4 lg:grid-cols-2">
                        {sales && (
                            <Panel title="Top sản phẩm" subtitle="7 ngày qua">
                                {sales.top.length > 0 ? (
                                    <ol className="grid gap-3 px-5 py-4">
                                        {sales.top.map((item, index) => (
                                            <li
                                                key={`${item.sku}-${index}`}
                                                className="flex items-center gap-3"
                                            >
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs text-white">
                                                    {String(index + 1).padStart(
                                                        2,
                                                        '0',
                                                    )}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="truncate text-sm">
                                                        {item.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {item.sku}
                                                    </div>
                                                </div>
                                                <span className="font-medium">
                                                    {formatNumber(
                                                        item.quantity,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ol>
                                ) : (
                                    <Empty text="Chưa có sản phẩm bán ra trong 7 ngày qua." />
                                )}
                            </Panel>
                        )}

                        {stock && (
                            <Panel title="Thông tin kho">
                                <div className="grid gap-3 px-5 py-4">
                                    <StockRow
                                        href={stock.links.below_min}
                                        label="Sản phẩm dưới định mức"
                                        value={stock.below_min}
                                        warn={stock.below_min > 0}
                                    />
                                    <StockRow
                                        href={stock.links.inventory}
                                        label="Số tồn kho chi nhánh"
                                        value={stock.on_hand}
                                    />
                                    {stock.value !== null &&
                                        can('products.view_cost') && (
                                            <StockRow
                                                href={stock.links.inventory}
                                                label="Giá trị tồn kho chi nhánh"
                                                value={stock.value}
                                            />
                                        )}
                                </div>
                            </Panel>
                        )}
                    </div>
                </div>

                <div className="grid gap-4">
                    <Shortcuts />
                    {activity && (
                        <Panel title="Hoạt động gần đây">
                            {activity.length > 0 ? (
                                <ol className="relative mx-5 my-4 border-l border-primary/30">
                                    {activity.map((item) => (
                                        <li
                                            key={item.id}
                                            className="relative pb-3 pl-4 last:pb-0"
                                        >
                                            <span className="absolute top-1.5 -left-[5px] size-2.5 rounded-full bg-primary" />
                                            <div className="text-sm">
                                                <span className="font-medium">
                                                    {item.user ?? 'Hệ thống'}
                                                </span>{' '}
                                                {item.description
                                                    .charAt(0)
                                                    .toLowerCase() +
                                                    item.description.slice(1)}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    item.created_at,
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            ) : (
                                <Empty text="Chưa có hoạt động nào." />
                            )}
                            {can('activity_log.view') && (
                                <Link
                                    href="/activity-log"
                                    className="block border-t px-5 py-3 text-sm text-primary hover:underline"
                                >
                                    Xem tất cả
                                </Link>
                            )}
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}

/** A dashboard card with an upper-case title like Sapo's. */
function Panel({
    title,
    subtitle,
    action,
    tab = false,
    children,
}: {
    title: string;
    subtitle?: string;
    action?: React.ReactNode;
    /** Title drawn as the selected tab (blue, underlined). */
    tab?: boolean;
    children: React.ReactNode;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <div className="flex min-h-12 items-center justify-between gap-3 border-b px-5">
                <h2
                    className={cn(
                        'text-sm font-medium uppercase',
                        tab &&
                            '-mb-px self-stretch border-b-2 border-primary pt-4 text-primary',
                    )}
                >
                    {title}
                </h2>
                {subtitle && (
                    <span className="text-xs text-muted-foreground">
                        {subtitle}
                    </span>
                )}
                {action}
            </div>
            {children}
        </Card>
    );
}

function Empty({ text }: { text: string }) {
    return (
        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}

function StockRow({
    href,
    label,
    value,
    warn = false,
}: {
    href: string;
    label: string;
    value: number;
    warn?: boolean;
}) {
    return (
        <Link
            href={href}
            className="flex items-center justify-between rounded-md bg-muted/60 px-4 py-3 transition-colors hover:bg-muted"
        >
            <div>
                <div className="flex items-center gap-1 text-xs text-muted-foreground">
                    {label}
                    {warn && (
                        <CircleAlert className="size-3.5 text-destructive" />
                    )}
                </div>
                <div className="text-lg font-medium">{formatNumber(value)}</div>
            </div>
            <ChevronRight className="size-4 text-muted-foreground" />
        </Link>
    );
}

/** Revenue per day as bars, with a scale on the left (no chart library). */
function RevenueChart({ data }: { data: Sales['chart'] }) {
    const max = Math.max(...data.map((day) => day.revenue), 0);
    const top = niceCeiling(max);
    const ticks = [4, 3, 2, 1, 0].map((step) => (top / 4) * step);
    const total = data.reduce((sum, day) => sum + day.revenue, 0);

    return (
        <div className="px-5 pt-6 pb-4">
            <div className="flex h-56 gap-3">
                <div className="relative w-14 shrink-0 text-right text-xs text-muted-foreground sm:w-20">
                    {ticks.map((tick, index) => (
                        <span
                            key={tick}
                            className="absolute right-0 -translate-y-1/2"
                            style={{ top: `${(index / 4) * 100}%` }}
                        >
                            {formatNumber(tick)}
                        </span>
                    ))}
                </div>
                <div className="relative flex flex-1 items-end justify-around border-b pb-0">
                    {ticks.slice(0, -1).map((tick, index) => (
                        <span
                            key={tick}
                            className="absolute inset-x-0 border-t border-dashed border-border"
                            style={{ top: `${(index / 4) * 100}%` }}
                        />
                    ))}
                    {data.map((day) => (
                        <div
                            key={day.date}
                            className="relative flex h-full w-full max-w-16 flex-col items-center justify-end"
                        >
                            <div
                                className="w-3/5 rounded-t-sm bg-[#0088FF]"
                                style={{
                                    height: `${top ? (day.revenue / top) * 100 : 0}%`,
                                }}
                                title={`${formatNumber(day.revenue)} đ`}
                            />
                            <span className="absolute -bottom-6 text-xs text-muted-foreground">
                                {day.date.slice(8, 10)}
                                <span className="hidden sm:inline">
                                    /{day.date.slice(5, 7)}
                                </span>
                            </span>
                        </div>
                    ))}
                </div>
            </div>
            <p className="mt-8 text-center text-sm text-muted-foreground">
                Tổng doanh thu:{' '}
                <span className="font-medium text-foreground">
                    {formatNumber(total)}
                </span>
            </p>
        </div>
    );
}

/** 0 → 100.000 so the empty chart still has a scale; else 1/2/5 × 10^n. */
function niceCeiling(value: number): number {
    if (value <= 0) {
        return 100000;
    }

    const power = 10 ** Math.floor(Math.log10(value));

    return ([1, 2, 5, 10].find((step) => step * power >= value) ?? 10) * power;
}

function Welcome({ name }: { name: string }) {
    return (
        <Card className="px-5 py-5">
            <h2 className="text-lg font-medium">Xin chào, {name}</h2>
            <p className="text-sm text-muted-foreground">
                Chọn một mục ở bên phải hoặc trên menu để bắt đầu làm việc.
            </p>
        </Card>
    );
}

/** Quick links to the pages this user can open. */
function Shortcuts() {
    const can = useCan();
    const items = filterMenu(menu, can)
        .flatMap((group) => group.items)
        .filter((item) => item.href !== '/dashboard')
        .map((item) => ({
            ...item,
            href: item.children?.[0]?.href ?? item.href,
        }))
        .slice(0, 8);

    if (items.length === 0) {
        return null;
    }

    return (
        <Panel title="Truy cập nhanh">
            <div className="grid grid-cols-2 gap-1 p-2">
                {items.map((item) => (
                    <Link
                        key={item.title}
                        href={item.href}
                        prefetch
                        className="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"
                    >
                        {item.icon && (
                            <item.icon className="size-4 text-primary" />
                        )}
                        <span className="truncate">{item.title}</span>
                    </Link>
                ))}
            </div>
        </Panel>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Tổng quan',
            href: dashboard(),
        },
    ],
};
