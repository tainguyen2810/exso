const numberFormat = new Intl.NumberFormat('vi-VN');

/** 1234567 → "1.234.567" */
export function formatNumber(value: number): string {
    return numberFormat.format(value);
}

/** ISO date → "28/10/2026 17:05" in Vietnam time. */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone: 'Asia/Ho_Chi_Minh',
        })
            .formatToParts(new Date(value))
            .map((part) => [part.type, part.value]),
    );

    return `${parts.day}/${parts.month}/${parts.year} ${parts.hour}:${parts.minute}`;
}
