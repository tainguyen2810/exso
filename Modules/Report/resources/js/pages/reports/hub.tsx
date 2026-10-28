import { Head, Link } from '@inertiajs/react';
import {
    BarChart3,
    ChevronRight,
    Coins,
    Package,
    ShoppingCart,
    Truck,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { Card } from '@/components/ui/card';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ReportChart } from '../../components/report-chart';

type Item = { key?: string; title: string; description: string; url: string };

type Section = {
    key: string;
    title: string;
    description: string;
    reports: Item[];
    links: Item[];
};

type Saved = {
    id: number;
    name: string;
    report: string;
    url: string;
    is_shared: boolean;
    mine: boolean;
};

type Overview = {
    chart: { date: string; revenue: number; profit: number | null }[];
    revenue: number;
    orders: number;
    returned: number;
    profit: number | null;
};

const ICONS: Record<string, LucideIcon> = {
    sales: ShoppingCart,
    purchases: Truck,
    inventory: Package,
    finance: Coins,
    customers: Users,
};

/** Báo cáo: cards of every report the user may open, like Sapo. */
export default function ReportHub({
    current,
    groups,
    sections,
    saved,
    overview,
}: {
    current: string | null;
    groups: Record<string, string>;
    sections: Section[];
    saved: Saved[];
    overview: Overview | null;
}) {
    const title = current ? groups[current] : 'Tổng quan báo cáo';

    return (
        <>
            <Head title={title} />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <nav className="flex flex-wrap gap-1 border-b text-sm">
                    {[['', 'Tổng quan'], ...Object.entries(groups)].map(
                        ([key, label]) => (
                            <Link
                                key={key}
                                href={key ? `/reports/${key}` : '/reports'}
                                className={cn(
                                    '-mb-px border-b-2 border-transparent px-3 py-2 hover:text-primary',
                                    (current ?? '') === key &&
                                        'border-primary font-medium text-primary',
                                )}
                            >
                                {label}
                            </Link>
                        ),
                    )}
                </nav>

                {overview && <SalesOverview overview={overview} />}

                {sections.map((section) => {
                    const Icon = ICONS[section.key] ?? BarChart3;

                    return (
                        <Card key={section.key} className="gap-0 py-0">
                            <div className="flex items-center gap-3 border-b px-5 py-4">
                                <span className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <Icon className="size-5" />
                                </span>
                                <div>
                                    <h2 className="font-medium">
                                        {section.title}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {section.description}
                                    </p>
                                </div>
                            </div>
                            <div className="grid gap-x-6 p-2 sm:grid-cols-2 xl:grid-cols-3">
                                {[...section.reports, ...section.links].map(
                                    (item) => (
                                        <Link
                                            key={item.url}
                                            href={item.url}
                                            className="group flex items-start gap-3 rounded-md px-3 py-3 hover:bg-accent"
                                        >
                                            <BarChart3 className="mt-0.5 size-4 shrink-0 text-muted-foreground group-hover:text-primary" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-medium text-primary">
                                                    {item.title}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {item.description}
                                                </span>
                                            </span>
                                            <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        </Link>
                                    ),
                                )}
                            </div>
                        </Card>
                    );
                })}

                {sections.length === 0 && (
                    <Card className="px-5 py-10 text-center text-sm text-muted-foreground">
                        Bạn chưa có quyền xem báo cáo nào trong mục này.
                    </Card>
                )}

                {saved.length > 0 && (
                    <Card className="gap-0 py-0">
                        <h2 className="border-b px-5 py-4 font-medium">
                            Báo cáo tùy chỉnh đã lưu
                        </h2>
                        <ul className="divide-y">
                            {saved.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-center gap-3 px-5 py-3 text-sm"
                                >
                                    <Link
                                        href={item.url}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {item.name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {item.report}
                                        {item.is_shared && ' · Dùng chung'}
                                    </span>
                                    {item.mine && (
                                        <span className="ml-auto">
                                            <ConfirmDelete
                                                url={`/reports/saved/${item.id}`}
                                                title={`Xóa báo cáo ${item.name}?`}
                                                description="Chỉ xóa bộ lọc đã lưu, không ảnh hưởng dữ liệu."
                                            />
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </>
    );
}

function SalesOverview({ overview }: { overview: Overview }) {
    const stats = [
        { label: 'Doanh thu', value: overview.revenue },
        { label: 'Số đơn hàng', value: overview.orders },
        { label: 'Tiền hàng trả lại', value: overview.returned },
        ...(overview.profit !== null
            ? [{ label: 'Lợi nhuận gộp', value: overview.profit }]
            : []),
    ];

    return (
        <Card className="gap-0 py-0">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4">
                <h2 className="font-medium">Doanh thu 7 ngày gần nhất</h2>
                <Link
                    href="/reports/sales/time"
                    className="text-sm text-primary hover:underline"
                >
                    Xem báo cáo
                </Link>
            </div>
            <div className="grid grid-cols-2 border-b md:grid-cols-4">
                {stats.map((stat) => (
                    <div key={stat.label} className="px-5 py-3">
                        <p className="text-sm text-muted-foreground">
                            {stat.label}
                        </p>
                        <p className="text-lg font-semibold">
                            {formatNumber(stat.value)}
                        </p>
                    </div>
                ))}
            </div>
            <ReportChart
                data={{
                    points: overview.chart.map((day) => ({
                        label: `${day.date.slice(8, 10)}/${day.date.slice(5, 7)}`,
                        revenue: day.revenue,
                        profit: day.profit,
                    })),
                    bars: [{ key: 'revenue', label: 'Doanh thu' }],
                    line:
                        overview.profit !== null
                            ? { key: 'profit', label: 'Lợi nhuận gộp' }
                            : null,
                }}
            />
        </Card>
    );
}

ReportHub.layout = (props: {
    current: string | null;
    groups: Record<string, string>;
}) => ({
    breadcrumbs: [
        { title: 'Báo cáo', href: '/reports' },
        ...(props.current
            ? [
                  {
                      title: props.groups[props.current],
                      href: `/reports/${props.current}`,
                  },
              ]
            : []),
    ],
});
