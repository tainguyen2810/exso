import type { StatusMap } from '@/components/app/status-badge';

export { statusOptions as toOptions } from '@/components/app/status-badge';

/** Order status as Sapo shows it: the middle steps are "Đang giao dịch". */
export const orderStatuses: StatusMap = {
    pending: ['Đặt hàng', 'warning'],
    approved: ['Đang giao dịch', 'info'],
    packed: ['Đang giao dịch', 'info'],
    shipped: ['Đang giao dịch', 'info'],
    completed: ['Hoàn thành', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

/** Filter / tab options (one "Đang giao dịch" entry for the middle steps). */
export const orderStatusFilters = [
    { value: 'pending', label: 'Đặt hàng' },
    { value: 'approved', label: 'Đã duyệt' },
    { value: 'packed', label: 'Đã đóng gói' },
    { value: 'shipped', label: 'Đã xuất kho' },
    { value: 'completed', label: 'Hoàn thành' },
    { value: 'cancelled', label: 'Đã hủy' },
];

export const paymentStatuses: StatusMap = {
    unpaid: ['Chưa thanh toán', 'danger'],
    partial: ['Thanh toán một phần', 'warning'],
    paid: ['Đã thanh toán', 'success'],
};

export const deliveryStatuses: StatusMap = {
    shipping: ['Đang giao hàng', 'info'],
    delivered: ['Đã giao hàng', 'success'],
    returning: ['Hủy giao - chờ nhận', 'danger'],
};

export const refundStatuses: StatusMap = {
    none: ['Chưa hoàn tiền', 'warning'],
    partial: ['Hoàn tiền một phần', 'info'],
    refunded: ['Đã hoàn tiền', 'success'],
};

export const channels: Record<string, string> = {
    web: 'Tạo đơn',
    pos: 'Bán tại quầy',
};

export const deliveryMethods: Record<string, string> = {
    carrier: 'Đẩy qua hãng vận chuyển',
    pickup: 'Khách nhận tại cửa hàng',
    later: 'Giao hàng sau',
    external: 'Đẩy vận chuyển ngoài',
};
