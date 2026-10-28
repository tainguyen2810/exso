<?php

namespace Modules\Report\Support;

use App\Models\User;
use App\Support\Hooks\Hook;
use Modules\Report\Reports\Customers\CustomersByArea;
use Modules\Report\Reports\Customers\CustomersByTime;
use Modules\Report\Reports\Finance\CustomerDebtReport;
use Modules\Report\Reports\Finance\ProfitReport;
use Modules\Report\Reports\Finance\SupplierDebtReport;
use Modules\Report\Reports\Inventory\StockAboveMaxReport;
use Modules\Report\Reports\Inventory\StockLevelReport;
use Modules\Report\Reports\Inventory\StockMovementReport;
use Modules\Report\Reports\Inventory\StockReport;
use Modules\Report\Reports\Purchases\PurchasesByProduct;
use Modules\Report\Reports\Purchases\PurchasesBySupplier;
use Modules\Report\Reports\Purchases\PurchasesReport;
use Modules\Report\Reports\Purchases\SupplierPayments;
use Modules\Report\Reports\Report;
use Modules\Report\Reports\Sales\PaymentsReport;
use Modules\Report\Reports\Sales\ReturnsByProduct;
use Modules\Report\Reports\Sales\SalesByBranch;
use Modules\Report\Reports\Sales\SalesByCustomer;
use Modules\Report\Reports\Sales\SalesByCustomerGroup;
use Modules\Report\Reports\Sales\SalesByOrder;
use Modules\Report\Reports\Sales\SalesByProduct;
use Modules\Report\Reports\Sales\SalesBySource;
use Modules\Report\Reports\Sales\SalesByStaff;
use Modules\Report\Reports\Sales\SalesByTime;

/**
 * Every report of the Báo cáo menu. Other modules add theirs with
 * Hook::addFilter('reports.list', fn (array $reports) => [...$reports, new MyReport]).
 */
final class ReportRegistry
{
    /** Groups of the menu, as Sapo: key => [title, description]. */
    public const GROUPS = [
        'sales' => ['Báo cáo bán hàng', 'Doanh thu, lợi nhuận theo thời gian, nhân viên, sản phẩm, chi nhánh, nguồn bán, khách hàng.'],
        'purchases' => ['Báo cáo nhập hàng', 'Hàng nhập theo thời gian, nhà cung cấp, sản phẩm và thanh toán nhập hàng.'],
        'inventory' => ['Báo cáo kho', 'Tồn kho, xuất nhập tồn, hàng dưới và vượt định mức.'],
        'finance' => ['Báo cáo tài chính', 'Lãi lỗ, sổ quỹ, công nợ khách hàng và nhà cung cấp.'],
        'customers' => ['Báo cáo khách hàng', 'Khách hàng mới, khách mua hàng theo thời gian, khu vực.'],
    ];

    /** @var list<Report>|null */
    private ?array $reports = null;

    /**
     * @return list<Report>
     */
    public function all(): array
    {
        return $this->reports ??= array_values(array_filter(
            Hook::filter('reports.list', [
                new SalesByTime, new SalesByStaff, new SalesByProduct, new SalesByOrder, new SalesByBranch,
                new SalesBySource, new SalesByCustomer, new SalesByCustomerGroup, new ReturnsByProduct, new PaymentsReport,
                new PurchasesReport, new PurchasesBySupplier, new PurchasesByProduct, new SupplierPayments,
                new StockReport, new StockMovementReport, new StockLevelReport, new StockAboveMaxReport,
                new ProfitReport, new CustomerDebtReport, new SupplierDebtReport,
                new CustomersByTime, new CustomersByArea,
            ]),
            fn (mixed $report): bool => $report instanceof Report,
        ));
    }

    public function find(string $group, string $name): ?Report
    {
        foreach ($this->all() as $report) {
            if ($report->group() === $group && $report->name() === $name) {
                return $report;
            }
        }

        return null;
    }

    public function allows(Report $report, User $user): bool
    {
        return $user->can('reports.view') && ($report->permission() === null || $user->can($report->permission()));
    }

    /**
     * Reports of a group the user may open.
     *
     * @return list<Report>
     */
    public function group(string $group, User $user): array
    {
        return array_values(array_filter($this->all(), fn (Report $report): bool => $report->group() === $group && $this->allows($report, $user)));
    }
}
