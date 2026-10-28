/**
 * Same money rules as OrderService::lines() / totals() on the server, so
 * the form shows what will be saved. The server always recomputes.
 */
export type MoneyLine = { quantity: number; price: number; discount: number };

export const lineTotal = (line: MoneyLine) => {
    const gross = line.quantity * line.price;

    return gross - Math.min(Math.max(0, line.discount), gross);
};

export function orderTotals(
    lines: MoneyLine[],
    discount: number,
    discountBp: number | null,
    shippingFee: number,
) {
    const subtotal = lines.reduce((sum, line) => sum + lineTotal(line), 0);
    let orderDiscount = discount;

    if (discountBp !== null) {
        orderDiscount = Math.floor(
            (subtotal * Math.min(10000, Math.max(0, discountBp)) + 5000) /
                10000,
        );
    }

    orderDiscount = Math.min(Math.max(0, orderDiscount), subtotal);
    const fee = Math.max(0, shippingFee);

    return {
        subtotal,
        discount: orderDiscount,
        shippingFee: fee,
        total: subtotal - orderDiscount + fee,
        quantity: lines.reduce((sum, line) => sum + line.quantity, 0),
    };
}
