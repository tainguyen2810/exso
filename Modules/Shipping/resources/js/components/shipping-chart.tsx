import { formatNumber } from '@/lib/format';

export type ShippingPoint = { label: string; count: number; fee: number };

const BAR_COLOR = '#0088FF';
const LINE_COLOR = '#F5A623';

/** 0 → 4 so an empty chart still has a scale; else 1/2/5 × 10^n. */
function niceCeiling(value: number, empty: number): number {
    if (value <= 0) {
        return empty;
    }

    const power = 10 ** Math.floor(Math.log10(value));

    return ([1, 2, 5, 10].find((step) => step * power >= value) ?? 10) * power;
}

function shortMoney(value: number): string {
    if (value >= 1e6) {
        return `${formatNumber(Math.round((value / 1e6) * 10) / 10)} tr`;
    }

    if (value >= 1e3) {
        return `${formatNumber(Math.round(value / 1e3))} N`;
    }

    return formatNumber(value);
}

/**
 * Shipments per day (bars, left axis) and their fees (line, right axis),
 * like the chart of Sapo's "Tổng quan vận chuyển".
 */
export function ShippingChart({ points }: { points: ShippingPoint[] }) {
    const countTop = niceCeiling(
        Math.max(0, ...points.map((point) => point.count)),
        4,
    );
    const feeTop = niceCeiling(
        Math.max(0, ...points.map((point) => point.fee)),
        100000,
    );
    const steps = [4, 3, 2, 1, 0];
    const count = Math.max(points.length, 1);
    const every = Math.ceil(count / 12);
    const ratio = (value: number, top: number) =>
        Math.max(0, Math.min(1, value / top)) * 100;

    return (
        <div className="px-5 pt-6 pb-4">
            <div className="flex h-60 gap-3">
                <Axis
                    values={steps.map((step) => (countTop / 4) * step)}
                    format={formatNumber}
                    align="right"
                />
                <div className="relative flex flex-1 items-end border-b">
                    {steps.slice(0, -1).map((step, index) => (
                        <span
                            key={step}
                            className="absolute inset-x-0 border-t border-dashed border-border"
                            style={{ top: `${(index / 4) * 100}%` }}
                        />
                    ))}
                    {points.map((point, index) => (
                        <div
                            key={`${point.label}-${index}`}
                            className="relative flex h-full flex-1 items-end justify-center"
                            title={`${point.label}\nSố vận đơn: ${formatNumber(point.count)}\nPhí giao hàng: ${formatNumber(point.fee)}`}
                        >
                            <div
                                className="w-full max-w-8 rounded-t-sm"
                                style={{
                                    height: `${ratio(point.count, countTop)}%`,
                                    backgroundColor: BAR_COLOR,
                                }}
                            />
                            {index % every === 0 && (
                                <span className="absolute -bottom-6 text-xs whitespace-nowrap text-muted-foreground">
                                    {point.label}
                                </span>
                            )}
                        </div>
                    ))}
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
                            points={points
                                .map(
                                    (point, index) =>
                                        `${((index + 0.5) / count) * 100},${100 - ratio(point.fee, feeTop)}`,
                                )
                                .join(' ')}
                        />
                    </svg>
                </div>
                <Axis
                    values={steps.map((step) => (feeTop / 4) * step)}
                    format={shortMoney}
                    align="left"
                />
            </div>
            <div className="mt-9 flex flex-wrap justify-center gap-5 text-sm text-muted-foreground">
                <span className="flex items-center gap-2">
                    <span
                        className="size-3 rounded-sm"
                        style={{ backgroundColor: BAR_COLOR }}
                    />
                    Số vận đơn
                </span>
                <span className="flex items-center gap-2">
                    <span
                        className="h-0.5 w-4"
                        style={{ backgroundColor: LINE_COLOR }}
                    />
                    Phí giao hàng
                </span>
            </div>
        </div>
    );
}

function Axis({
    values,
    format,
    align,
}: {
    values: number[];
    format: (value: number) => string;
    align: 'left' | 'right';
}) {
    return (
        <div
            className={`relative w-12 shrink-0 text-xs text-muted-foreground ${align === 'right' ? 'text-right' : 'text-left'}`}
        >
            {values.map((value, index) => (
                <span
                    key={value}
                    className={`absolute -translate-y-1/2 ${align === 'right' ? 'right-0' : 'left-0'}`}
                    style={{ top: `${(index / 4) * 100}%` }}
                >
                    {format(value)}
                </span>
            ))}
        </div>
    );
}
