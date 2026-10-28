/** Basis points (550) → percent as typed in Vietnamese ("5,5"). */
export const bpToPercent = (bp: number | null | undefined) =>
    bp == null ? '' : String(bp / 100).replace('.', ',');

/** Percent typed by the user ("5,5") → basis points (550), 0–100%. */
export const percentToBp = (value: string) => {
    const number = Number(value.replace(',', '.'));

    return value.trim() === '' || Number.isNaN(number)
        ? null
        : Math.round(Math.min(Math.max(number, 0), 100) * 100);
};
