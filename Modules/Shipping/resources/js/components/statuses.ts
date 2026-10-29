import type { StatusMap } from '@/components/app/status-badge';

export { statusOptions as toOptions } from '@/components/app/status-badge';

/** Shipment status as Sapo names it (tabs of "Quản lý vận đơn"). */
export const shipmentStatuses: StatusMap = {
    pending: ['Chờ lấy hàng', 'warning'],
    shipping: ['Đang giao hàng', 'info'],
    redelivery: ['Chờ giao lại', 'warning'],
    delivered: ['Đã giao hàng', 'success'],
    returning: ['Hủy giao - chờ nhận', 'danger'],
    returned: ['Hủy giao - đã nhận', 'neutral'],
    cancelled: ['Hủy đóng gói', 'neutral'],
};

export const codStatuses: StatusMap = {
    none: ['Không thu hộ', 'neutral'],
    collecting: ['Chờ thu hộ COD', 'warning'],
    waiting: ['Chờ đối soát', 'warning'],
    reconciling: ['Đang đối soát', 'info'],
    reconciled: ['Đã đối soát', 'success'],
};

export const reconciliationStatuses: StatusMap = {
    reconciling: ['Đang đối soát', 'warning'],
    done: ['Đã đối soát', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const ACTIVE = ['pending', 'shipping', 'redelivery', 'returning'];
