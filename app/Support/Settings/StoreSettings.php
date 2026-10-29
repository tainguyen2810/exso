<?php

namespace App\Support\Settings;

/**
 * Defaults and validation of the store settings edited on the Cấu hình
 * pages. settings('key') falls back to these, so a new key works on stores
 * created before it existed (no migration needed).
 */
class StoreSettings
{
    public const DEFAULTS = [
        'store.phone' => null,
        'store.email' => null,
        'store.business_field' => null,
        'store.address' => null,
        'store.province' => null,
        'store.district' => null,
        'store.ward' => null,
        'store.logo' => null,

        'tax.default_sale' => 'BANHANG',
        'tax.default_purchase' => 'NHAPHANG',
        'tax.price_includes_tax' => true,

        'payment.default_pos' => null,
        'payment.default_online' => null,

        'reasons.require_cancel' => false,
        'reasons.require_return' => false,

        'sales.print_copies' => 1,
        'sales.allow_change_sale_time' => false,
        'sales.allow_edit_price' => true,
        'sales.allow_cancel_after_export' => true,
        'sales.allow_negative_order' => false,
        'sales.approve_negative_order' => false,
        'sales.allow_negative_export' => false,
        'pos.require_paid_amount' => false,
        'pos.print_draft' => true,
        'online.separate_packing' => false,
        'returns.allow_other_branch' => true,
        'returns.no_refund_smaller_exchange' => false,
        'cashbook.allow_change_date' => false,
        // Khuyến mại (G5).
        'promotions.enabled' => true,
        // one: one program per order | many: several programs.
        'promotions.apply_mode' => 'one',
        'coupons.enabled' => true,
        'loyalty.enabled' => false,
        // Đồng spent for 1 point, and đồng 1 point is worth when paying.
        'loyalty.earn_amount' => 100000,
        'loyalty.redeem_value' => 1000,
        // Vận chuyển (G6): package defaults sent to the partners.
        // product: weight of the products in the order | custom: always default_weight.
        'shipping.weight_mode' => 'product',
        'shipping.default_weight' => 500,
        'shipping.default_length' => 10,
        'shipping.default_width' => 10,
        'shipping.default_height' => 10,
        'shipping.required_note' => 'CHOXEMHANGKHONGTHU',
        'shipping.note' => null,
        // The order is exported when the partner picks the parcel up.
        'shipping.auto_export' => true,
        // A parcel back at the store ("Hủy giao - đã nhận") returns to stock by itself.
        'shipping.auto_receive_return' => false,
        'shipping.late_pick_days' => 2,
        'shipping.late_delivery_days' => 5,
        // Issue the e-invoice by itself when an order is completed.
        'einvoice.auto_issue' => false,
    ];

    /** On/off options on the "Cấu hình bán hàng" page, grouped like Sapo. */
    public const SALES_TOGGLES = [
        'Bán hàng' => [
            'sales.allow_change_sale_time' => ['Cho phép thay đổi thời gian bán hàng', 'Nhân viên được chọn ngày giờ bán khác thời điểm tạo đơn.'],
            'sales.allow_edit_price' => ['Cho phép sửa giá khi bán hàng', 'Tắt thì đơn luôn lấy giá theo chính sách giá (chủ cửa hàng vẫn sửa được).'],
            'sales.allow_cancel_after_export' => ['Cho phép hủy đơn đã xuất kho', 'Hàng sẽ được nhập lại kho khi hủy.'],
            'sales.allow_negative_order' => ['Cho phép tạo đơn khi không đủ tồn', 'Tạo đơn đặt hàng với sản phẩm đã hết hàng.'],
            'sales.approve_negative_order' => ['Cho phép duyệt đơn khi không đủ tồn', null],
            'sales.allow_negative_export' => ['Cho phép xuất kho âm', 'Tồn kho có thể xuống dưới 0 khi giao hàng.'],
        ],
        'Bán tại quầy' => [
            'pos.require_paid_amount' => ['Bắt buộc nhập tiền khách đưa', null],
            'pos.print_draft' => ['Cho phép in phiếu tạm tính', null],
        ],
        'Bán online' => [
            'online.separate_packing' => ['Đóng gói là một bước riêng', 'Đơn online phải qua bước đóng gói trước khi giao.'],
        ],
        'Đổi trả hàng' => [
            'returns.allow_other_branch' => ['Cho phép trả hàng ở chi nhánh khác', null],
            'returns.no_refund_smaller_exchange' => ['Không hoàn tiền khi đổi sang hàng giá thấp hơn', null],
        ],
        'Quản lý khác' => [
            'cashbook.allow_change_date' => ['Cho phép điều chỉnh ngày ghi nhận phiếu thu, phiếu chi', null],
        ],
    ];

    public const BUSINESS_FIELDS = [
        'Điện thoại & Điện máy', 'Máy tính & Linh kiện', 'Thời trang', 'Mỹ phẩm', 'Mẹ & Bé',
        'Nhà cửa & Đời sống', 'Thực phẩm', 'Nhà thuốc', 'Sách & Văn phòng phẩm', 'Khác',
    ];

    public static function default(string $key): mixed
    {
        return self::DEFAULTS[$key] ?? null;
    }
}
