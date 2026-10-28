<?php

namespace App\Support\Settings;

use App\Models\OrderReason;
use App\Models\PaymentMethod;
use App\Models\SalesSource;
use App\Models\TaxRate;

/**
 * Default configuration rows of a store, as in Sapo. Safe to run again:
 * existing rows (matched by code or name) are left as the store set them.
 * Must run inside the store's tenant context.
 */
class StoreDefaults
{
    public static function install(): void
    {
        foreach ([
            ['Thuế nhập hàng', 'NHAPHANG', 1000, 'purchase'],
            ['Không áp dụng thuế', 'KHONGAPDUNG', 0, 'other'],
            ['Thuế bán hàng', 'BANHANG', 1000, 'sale'],
        ] as [$name, $code, $rate, $type]) {
            TaxRate::query()->firstOrCreate(['code' => $code], ['name' => $name, 'rate_bp' => $rate, 'type' => $type]);
        }

        // Built-in methods are matched by type, so a renamed one is not re-added.
        $sort = 0;
        $system = [];

        foreach (['cash' => 'Tiền mặt', 'bank' => 'Chuyển khoản', 'cod' => 'Thu hộ (COD)', 'points' => 'Thanh toán bằng điểm'] as $type => $name) {
            $system[$type] = PaymentMethod::query()->firstOrCreate(
                ['type' => $type, 'is_system' => true],
                ['name' => $name, 'sort' => $sort++],
            );
        }

        foreach (['Ví Shopee', 'Ví Lazada', 'Ví Tiktok'] as $name) {
            PaymentMethod::query()->firstOrCreate(['name' => $name], ['type' => 'ewallet', 'sort' => $sort++]);
        }

        $settings = app(SettingsRepository::class);
        $values = [];

        if ($settings->get('payment.default_pos') === null) {
            $values['payment.default_pos'] = $system['cash']->id;
        }

        if ($settings->get('payment.default_online') === null) {
            $values['payment.default_online'] = $system['bank']->id;
        }

        if ($values !== []) {
            $settings->setMany($values);
        }

        foreach ([
            ['Tại quầy', 'POS', true], ['Facebook', 'FACEBOOK', false], ['Zalo', 'ZALO', false],
            ['Website', 'WEB', false], ['Shopee', 'SHOPEE', false], ['Lazada', 'LAZADA', false],
            ['Tiktok Shop', 'TIKTOKSHOP', false], ['Tiki', 'TIKI', false], ['Instagram', 'INSTAGRAM', false],
            ['Khác', 'KHAC', false],
        ] as [$name, $code, $default]) {
            SalesSource::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_default' => $default]);
        }

        if (! OrderReason::query()->exists()) {
            foreach ([
                'cancel' => ['Khách đổi ý, không mua nữa', 'Khách đặt nhầm sản phẩm', 'Hết hàng', 'Không liên lạc được khách', 'Khác'],
                'return' => ['Sản phẩm lỗi, hỏng', 'Giao sai sản phẩm', 'Khách đổi ý', 'Khác'],
            ] as $type => $names) {
                foreach ($names as $index => $name) {
                    OrderReason::query()->create(['type' => $type, 'name' => $name, 'sort' => $index]);
                }
            }
        }
    }
}
