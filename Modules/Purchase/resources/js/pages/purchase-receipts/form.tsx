import { PurchaseForm } from '../../components/purchase-form';
import type { Branch, PurchaseDraft } from '../../components/purchase-form';

export default function PurchaseReceiptForm({
    receipt,
    branches,
    currentBranchId,
}: {
    receipt: PurchaseDraft | null;
    branches: Branch[];
    currentBranchId: number | null;
}) {
    return (
        <PurchaseForm
            kind="receipt"
            draft={receipt}
            branches={branches}
            currentBranchId={currentBranchId}
        />
    );
}

PurchaseReceiptForm.layout = {
    breadcrumbs: [
        { title: 'Nhập hàng', href: '/purchase-receipts' },
        { title: 'Phiếu nhập hàng', href: '#' },
    ],
};
