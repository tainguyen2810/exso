<?php

namespace Modules\Cashbook\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;

/**
 * Create the automatic vouchers that are missing (payments recorded before
 * the cash book was installed). Safe to run again.
 */
class SyncVouchersCommand extends Command
{
    protected $signature = 'cashbook:sync';

    protected $description = 'Tạo phiếu thu/chi tự động còn thiếu cho các khoản thanh toán đơn hàng, nhập hàng';

    public function handle(CashbookService $cashbook): int
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $created = tenancy()->run($tenant, function () use ($cashbook): int {
                CashVoucherType::installDefaults();

                return $cashbook->sync();
            });

            $this->info("{$tenant->name}: đã tạo {$created} phiếu.");
        }

        return self::SUCCESS;
    }
}
