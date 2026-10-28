import type { StatusMap } from '@/components/app/status-badge';

/** Status label and pill colour of stock documents, as on Sapo. */
export const stocktakeStatuses: StatusMap = {
    draft: ['Đang kiểm hàng', 'warning'],
    balanced: ['Đã cân bằng', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const transferStatuses: StatusMap = {
    draft: ['Chờ chuyển', 'warning'],
    shipping: ['Đang chuyển', 'info'],
    received: ['Đã nhận', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const adjustmentStatuses: StatusMap = {
    draft: ['Chưa điều chỉnh', 'warning'],
    applied: ['Đã điều chỉnh', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};
