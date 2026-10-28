import { formatNumber } from '@/lib/format';

export type ChartSeries = { key: string; label: string };

export type ChartData = {
    points: ({ label: string } & Record<string, number | string | null>)[];
    bars: ChartSeries[];
    line: ChartSeries | null;
};

const BAR_COLORS = ['#0088FF', '#9CD1FF'];
const LINE_COLOR = '#F5A623';

/** 0 → 100.000 so an empty chart still has a scale; else 1/2/5 × 10^n. */
function niceCeiling(value: number): number {
    if (value <= 0) {
        return 100000;
    }

    const power = 10 ** Math.floor(Math.log10(value));

    return ([1, 2, 5, 10].find((step) => step * power >= value) ?? 10) * power;
}

const numeric = (value: unknown) => (typeof value === 'number' ? value : 0);

/** Short axis numbers: 1,2 tr, 350 N... */
function shortNumber(value: number): string {
    const abs = Math.abs(value);

    if (abs >= 1e9) {
        return `${formatNumber(Math.round((value / 1e9) * 10) / 10)} tỷ`;
    }

    if (abs >= 1e6) {
        return `${formatNumber(Math.round((value / 1e6) * 10) / 10)} tr`;
    }

    if (abs >= 1e3) {
        return `${formatNumber(Math.round(value / 1e3))} N`;
    }

    return formatNumber(value);
}

/**
 * Bars (one or two series) with an optional line, like Sapo's report
 * charts (doanh thu = cột, lợi nhuận = đường). Negative values sit at 0.
 */
export function ReportChart({ data }: { data: ChartData }) {
    const series = [...data.bars, ...(data.line ? [data.line] : [])];
    const max = Math.max(
        0,
        ...data.points.flatMap((point) =>
            series.map((item) => numeric(point[item.key])),
        ),
    );
    const top = niceCeiling(max);
    const ticks = [4, 3, 2, 1, 0].map((step) => (top / 4) * step);
    const count = Math.max(data.points.length, 1);
    const height = (value: number) =>
        Math.max(0, Math.min(1, value / top)) * 100;
    const step = Math.ceil(count / 12);

    return (
        <div className="px-5 pt-6 pb-4">
            <div className="flex h-60 gap-3">
                <div className="relative w-14 shrink-0 text-right text-xs text-muted-foreground">
                    {ticks.map((tick, index) => (
                        <span
                            key={tick}
                            className="absolute right-0 -translate-y-1/2"
                            style={{ top: `${(index / 4) * 100}%` }}
                        >
                            {shortNumber(tick)}
                        </span>
                    ))}
                </div>
                <div className="relative flex flex-1 items-end border-b">
                    {ticks.slice(0, -1).map((tick, index) => (
                        <span
                            key={tick}
                            className="absolute inset-x-0 border-t border-dashed border-border"
                            style={{ top: `${(index / 4) * 100}%` }}
                        />
                    ))}
                    {data.points.map((point, index) => (
                        <div
                            key={`${point.label}-${index}`}
                            className="relative flex h-full flex-1 items-end justify-center gap-0.5"
                            title={[
                                point.label,
                                ...series.map(
                                    (item) =>
                                        `${item.label}: ${formatNumber(numeric(point[item.key]))}`,
                                ),
                            ].join('\n')}
                        >
                            {data.bars.map((bar, barIndex) => (
                                <div
                                    key={bar.key}
                                    className="w-full max-w-8 rounded-t-sm"
                                    style={{
                                        height: `${height(numeric(point[bar.key]))}%`,
                                        backgroundColor:
                                            BAR_COLORS[
                                                barIndex % BAR_COLORS.length
                                            ],
                                    }}
                                />
                            ))}
                            {index % step === 0 && (
                                <span className="absolute -bottom-6 max-w-24 truncate text-xs whitespace-nowrap text-muted-foreground">
                                    {point.label}
                                </span>
                            )}
                        </div>
                    ))}
                    {data.line && (
                        <svg
                            className="pointer-events-none absolute inset-0 size-full overflow-visible"
                            viewBox="0 0 100 100"
                            preserveAspectRatio="none"
                        >
                            <polyline
                                fill="none"
                                stroke={LINE_COLOR}
                                strokeWidth={2}
                                vectorEffect="non-scaling-stroke"
                                points={data.points
                                    .map(
                                        (point, index) =>
                                            `${((index + 0.5) / count) * 100},${100 - height(numeric(point[data.line!.key]))}`,
                                    )
                                    .join(' ')}
                            />
                        </svg>
                    )}
                </div>
            </div>
            <div className="mt-9 flex flex-wrap justify-center gap-5 text-sm text-muted-foreground">
                {data.bars.map((bar, index) => (
                    <span key={bar.key} className="flex items-center gap-2">
                        <span
                            className="size-3 rounded-sm"
                            style={{
                                backgroundColor:
                                    BAR_COLORS[index % BAR_COLORS.length],
                            }}
                        />
                        {bar.label}
                    </span>
                ))}
                {data.line && (
                    <span className="flex items-center gap-2">
                        <span
                            className="h-0.5 w-4"
                            style={{ backgroundColor: LINE_COLOR }}
                        />
                        {data.line.label}
                    </span>
                )}
            </div>
        </div>
    );
}
