import { PurchaseForm } from '../../components/purchase-form';
import type { Branch, PurchaseDraft } from '../../components/purchase-form';

export default function PurchaseOrderForm({
    order,
    branches,
    currentBranchId,
}: {
    order: PurchaseDraft | null;
    branches: Branch[];
    currentBranchId: number | null;
}) {
    return (
        <PurchaseForm
            kind="order"
            draft={order}
            branches={branches}
            currentBranchId={currentBranchId}
        />
    );
}

PurchaseOrderForm.layout = {
    breadcrumbs: [
        { title: 'Đặt hàng nhập', href: '/purchase-orders' },
        { title: 'Đơn đặt hàng nhập', href: '#' },
    ],
};
