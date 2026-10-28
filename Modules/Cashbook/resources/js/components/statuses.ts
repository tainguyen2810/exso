import type { StatusMap } from '@/components/app/status-badge';

export { statusOptions as toOptions } from '@/components/app/status-badge';

export type VoucherKind = 'receipt' | 'payment';

export const voucherStatuses: StatusMap = {
    completed: ['Hoàn thành', 'success'],
    cancelled: ['Đã hủy', 'neutral'],
};

export const partyTypes: Record<string, string> = {
    customer: 'Khách hàng',
    supplier: 'Nhà cung cấp',
    staff: 'Nhân viên',
    other: 'Khác',
};

/** Words that change between phiếu thu and phiếu chi, as Sapo names them. */
export const kindText = (kind: VoucherKind) =>
    kind === 'receipt'
        ? {
              name: 'phiếu thu',
              title: 'Phiếu thu',
              list: '/cashbook/receipts',
              create: '/cashbook/receipts/create',
              types: '/cashbook/receipt-types',
              typeName: 'Loại phiếu thu',
              party: 'người nộp',
              amount: 'Số tiền thu',
          }
        : {
              name: 'phiếu chi',
              title: 'Phiếu chi',
              list: '/cashbook/payments',
              create: '/cashbook/payments/create',
              types: '/cashbook/payment-types',
              typeName: 'Loại phiếu chi',
              party: 'người nhận',
              amount: 'Số tiền chi',
          };
