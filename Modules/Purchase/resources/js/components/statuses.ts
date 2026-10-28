import type { StatusMap } from '@/components/app/status-badge';

export { statusOptions as toOptions } from '@/components/app/status-badge';

/** Status label and pill colour of purchase documents, as on Sapo. */
export const receiptStatuses: StatusMap = {
    draft: ['Chưa nhập kho', 'warning'],
    received: ['Đã nhập kho', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const paymentStatuses: StatusMap = {
    unpaid: ['Chưa thanh toán', 'danger'],
    partial: ['Thanh toán một phần', 'warning'],
    paid: ['Đã thanh toán', 'success'],
};

export const orderStatuses: StatusMap = {
    pending: ['Đang giao dịch', 'info'],
    completed: ['Hoàn thành', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const receiveStatuses: StatusMap = {
    none: ['Chưa nhập', 'warning'],
    partial: ['Nhập một phần', 'info'],
    full: ['Đã nhập', 'success'],
};
