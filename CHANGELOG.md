# Changelog

Theo [Semantic Versioning](https://semver.org/lang/vi/). Bản chưa phát hành ghi ở mục "Chưa phát hành".

## Chưa phát hành

### G3: Khách hàng, Đơn hàng, Bán tại quầy

- Module **Khách hàng**: danh sách (nợ hiện tại, tổng chi tiêu, số đơn), thêm/sửa, chi tiết với tab Lịch sử mua hàng, Công nợ, Liên hệ, Địa chỉ, Ghi chú; số điện thoại duy nhất (chuẩn hóa +84 → 0); khách từ sàn giữ mã trên sàn; nhập/xuất Excel; thao tác hàng loạt (chuyển nhóm, trạng thái, xóa).
- **Nhóm khách hàng** quyết định chính sách giá (giá lẻ/buôn/bảng giá), chiết khấu và hình thức thanh toán mặc định; có sẵn Bán lẻ (mặc định), Bán buôn, Đại lý, VIP.
- Module **Đơn hàng**: tạo đơn kiểu Sapo (F1 lưu, F3 sản phẩm, F4 khách, F6 chiết khấu, F7 phí giao, F9 dịch vụ), lưu tách "Tạo đơn và duyệt / Lưu nháp / Tạo đơn và xuất kho", thanh toán tự điền số còn lại; danh sách với dải "Đơn hàng cần xử lý", xem nhanh, thao tác hàng loạt (duyệt, thanh toán, hủy); chi tiết với thanh 5 bước, lịch sử đơn; sửa, sao chép, in A4/A5/K80/K58 kèm VietQR.
- Trạng thái đơn: Đặt hàng → Duyệt (giữ hàng) → Đóng gói → Xuất kho (trừ kho, ghi nợ khách) → Hoàn thành (giao thành công); giao thất bại → nhận lại hàng. Hủy trước khi hoàn thành (bắt buộc lý do nếu cấu hình), tự hoàn tiền đã thu. Đơn đã xuất kho không sửa được.
- **Khách trả hàng** (SRN): nhập lại kho theo giá vốn lúc bán, giảm công nợ, hoàn tiền.
- **Bán tại quầy (POS)** toàn màn hình: nhiều tab đơn, ô quét mã luôn sẵn sàng, sản phẩm bán chạy, bàn phím số tiền khách đưa, VietQR động, in bill K80/K58; bán = tạo + xuất kho + thu tiền trong một giao dịch; mở/chốt ca với tiền đầu ca và chênh lệch.
- Khối bán hàng ở Tổng quan có số liệu (doanh thu, đơn chờ xử lý, sản phẩm bán chạy).
- Quyền mới `returns.view/create/pay/export` (cấp cho vai trò cũ qua migration); cấu hình "Cho phép sửa giá khi bán hàng".
- Dữ liệu mẫu khách hàng, đơn hàng, ca thu ngân.

### G2b

- Nhà cung cấp và công nợ, đặt hàng nhập, nhập hàng, trả hàng nhập, kiểm hàng, chuyển hàng, điều chỉnh giá vốn.

### G2a

- Sản phẩm (phiên bản, combo, đơn vị quy đổi, chính sách giá, in mã vạch), tồn kho, sổ kho, thẻ kho, nhập Excel từ Sapo.

### G0–G1c

- Khung dự án, trình cài, cửa hàng (tenant), đăng nhập, vai trò theo chi nhánh, giao diện kiểu Sapo, trang Cấu hình, Tổng quan.
