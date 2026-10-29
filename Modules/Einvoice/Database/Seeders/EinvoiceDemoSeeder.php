<?php

namespace Modules\Einvoice\Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Customer\Models\Customer;
use Modules\Einvoice\Models\Einvoice;
use Modules\Einvoice\Models\EinvoiceProvider;
use Modules\Einvoice\Services\EinvoiceService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Product\Models\ProductVariant;

/**
 * A Viettel S-Invoice test connection (fake account) and invoices at every
 * status (run inside the store's tenant, after the order demo). Issued
 * invoices get made-up numbers: nothing is sent to Viettel.
 */
class EinvoiceDemoSeeder extends Seeder
{
    public function run(OrderService $orders, EinvoiceService $invoices): void
    {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        Auth::setUser($owner);
        $main = (int) Branch::query()->where('is_default', true)->value('id');

        $provider = new EinvoiceProvider([
            'driver' => 'viettel',
            'name' => 'Viettel S-Invoice',
            'is_active' => true,
            'is_sandbox' => true,
            'settings' => ['seller_tax_code' => '0100109106-507', 'template_code' => '1/001', 'invoice_series' => 'C26TAA'],
            'created_by' => $owner->id,
        ]);
        $provider->credentials = ['username' => '0100109106-507', 'password' => 'demo-chua-co-tai-khoan'];
        $provider->connected_at = now()->subDays(2);
        $provider->save();

        $company = Customer::query()->where('phone', '02838123456')->first();
        $company?->forceFill(['tax_code' => '0312345678', 'email' => 'ketoan@minhphat.vn'])->save();

        $sell = function (string $phone, array $lines, array $extra = []) use ($orders, $owner, $main): Order {
            $items = [];

            foreach ($lines as [$sku, $quantity]) {
                $items[] = ['variant_id' => ProductVariant::query()->where('sku', $sku)->value('id'), 'quantity' => $quantity];
            }

            return $orders->create([
                'branch_id' => $main,
                'customer_id' => Customer::query()->where('phone', $phone)->value('id'),
                'delivery_method' => 'pickup',
                'items' => $items,
                ...$extra,
            ], $owner, 'export');
        };

        $number = 0;
        $issue = function (Einvoice $invoice, int $daysAgo) use ($provider, $owner, &$number): void {
            $number++;
            $invoice->forceFill([
                'status' => Einvoice::ISSUED,
                'provider_id' => $provider->id,
                'template_code' => '1/001',
                'series' => 'C26TAA',
                'invoice_no' => 'C26TAA'.str_pad((string) $number, 7, '0', STR_PAD_LEFT),
                'lookup_code' => strtoupper(substr(md5($invoice->transaction_uuid), 0, 10)),
                'issued_at' => now()->subDays($daysAgo),
                'issued_by' => $owner->id,
            ])->save();
        };

        $issue($invoices->fromOrder($sell('02838123456', [['KEY-K2', 2], ['LOGI-M331', 5]], ['discount' => 200000])), 2);
        $issue($invoices->fromOrder($sell('0901234567', [['UG-HDMI2', 3]])), 1);
        $invoices->fromOrder($sell('0912345678', [['JAB-E230', 1]]));

        $failed = $invoices->fromOrder($sell('0987654321', [['LOGI-M331', 2]]));
        $failed->forceFill(['status' => Einvoice::FAILED, 'provider_id' => $provider->id, 'error' => 'Viettel: Ký hiệu hóa đơn không đúng với mẫu số đã đăng ký.'])->save();

        // Waiting on "Đơn chưa tạo HĐĐT".
        $sell('0933111222', [['UG-HDMI2', 1]]);
    }
}
