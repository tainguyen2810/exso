<?php

namespace Modules\Inventory\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\StockLedger;

/**
 * Nightly check: stock totals must equal the sum of the stock ledger.
 */
class VerifyStockCommand extends Command
{
    protected $signature = 'inventory:verify';

    protected $description = 'Kiểm tra tồn kho khớp với lịch sử nhập xuất (stock_movements)';

    public function handle(StockLedger $ledger): int
    {
        $failed = false;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $mismatches = tenancy()->run($tenant, fn (): array => $ledger->mismatches());

            if ($mismatches === []) {
                $this->info("{$tenant->name}: tồn kho khớp.");

                continue;
            }

            $failed = true;
            $this->error("{$tenant->name}: ".count($mismatches).' dòng tồn kho lệch.');
            $this->table(['Chi nhánh', 'Phiên bản', 'Tồn kho', 'Theo lịch sử'], $mismatches);
            Log::error('Tồn kho lệch với stock_movements', ['tenant_id' => $tenant->id, 'rows' => array_slice($mismatches, 0, 50)]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
