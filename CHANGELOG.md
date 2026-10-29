# Changelog

Theo [Semantic Versioning](https://semver.org/lang/vi/). Bản chưa phát hành ghi ở mục "Chưa phát hành".

## Chưa phát hành

### G7: Sàn TMĐT và Hóa đơn điện tử

- Module **Sàn TMĐT** (Shopee, Open Platform v2): kết nối gian hàng bằng cấp quyền của người bán (token lưu mã hóa, tự làm mới, báo "Hết hạn" khi cần cấp quyền lại), mỗi gian hàng gắn một chi nhánh lấy tồn và tạo đơn, hình thức thanh toán ghi nhận khi đơn sàn hoàn thành.
- **Sản phẩm sàn**: tải danh sách sản phẩm/phân loại từ sàn, tự liên kết theo SKU, liên kết/hủy liên kết tay với phiên bản EXSO, lọc chưa liên kết.
- **Đơn sàn tự về**: webhook có kiểm chữ ký (địa chỉ bí mật từng gian hàng) và lệnh `marketplace:sync` mỗi 5 phút; đơn mới tạo đơn EXSO với Nguồn = sàn, Mã tham chiếu = mã đơn sàn, giá theo sàn; sàn chuẩn bị hàng → duyệt (giữ hàng), giao cho vận chuyển → xuất kho, giao thành công → hoàn thành + thanh toán, hủy → hủy đơn (nhập lại kho nếu đã xuất). Đơn có sản phẩm chưa liên kết chờ ở trạng thái "Chưa liên kết" và tự đồng bộ lại sau khi liên kết. Chạy lại không tạo đơn trùng.
- **Đẩy tồn "Có thể bán"** (tồn − đang giữ) lên sàn qua hàng đợi khi tồn hoặc hàng giữ thay đổi, gộp nhiều thay đổi thành một lần gửi; đẩy lại toàn bộ mỗi giờ.
- Trang **Tổng quan sàn**, **Đơn hàng sàn** (trạng thái trên sàn, trạng thái đồng bộ, đồng bộ lại, xuất Excel), **Sản phẩm sàn**, **Cấu hình** từng gian hàng; thẻ "Đơn sàn TMĐT" ở chi tiết đơn hàng.
- Module **Hóa đơn điện tử** (Viettel S-Invoice): kết nối tài khoản (kiểm tra trước khi lưu, mật khẩu mã hóa, môi trường thử nghiệm), mẫu số, ký hiệu; tạo hóa đơn nháp từ đơn đã xuất kho với người mua lấy từ khách hàng (MST, tên đơn vị, địa chỉ, email), chiết khấu đơn chia vào từng dòng, phí giao hàng thành một dòng, thuế theo cấu hình thuế (giá đã gồm thuế hoặc chưa).
- Phát hành, hủy, **lập hóa đơn điều chỉnh** (tăng, giảm, thông tin), tải PDF; phát hành lỗi giữ lại lý do để sửa và phát hành lại, không phát hành trùng (mã giao dịch). Tùy chọn **tự xuất hóa đơn khi đơn hoàn thành**.
- Đơn đã có hóa đơn điện tử phát hành không sửa, không hủy được (phải hủy hoặc điều chỉnh hóa đơn trước); thẻ "Hóa đơn điện tử" ở chi tiết đơn hàng.
- Trang **Hóa đơn điện tử** (các cột như Sapo, phát hành hàng loạt, xuất Excel) và **Đơn chưa tạo HĐĐT** (tạo nháp hoặc tạo và phát hành hàng loạt).
- Quyền mới `marketplaces.*`, `einvoices.*` (cấp cho vai trò cũ qua migration). Bỏ trang "sắp có": mọi mục menu đã có trang.

### G6: Vận chuyển

- Module **Vận chuyển**: phiếu giao hàng (FUN) từ đơn hàng với 4 cách giao trong Tạo đơn như Sapo (đẩy qua hãng vận chuyển, đẩy vận chuyển ngoài, nhận tại cửa hàng, giao sau) và nút Giao hàng ở chi tiết đơn; khối lượng, kích thước, COD, khai giá, người trả phí, yêu cầu khi giao.
- **Kết nối GHN** (API v2): tính phí và chọn gói cước, tạo/hủy vận đơn, in nhãn A5 của GHN, Token lưu mã hóa theo cửa hàng, cửa hàng GHN theo chi nhánh, môi trường thử nghiệm. Trạng thái GHN quy về trạng thái chung (Chờ lấy hàng, Đang giao, Chờ giao lại, Đã giao, Hủy giao - chờ nhận / đã nhận, Hủy đóng gói); webhook theo địa chỉ bí mật của từng cửa hàng, luôn hỏi lại GHN rồi cập nhật qua hàng đợi; lệnh `shipping:sync` mỗi 30 phút cho hosting không nhận được webhook.
- Đơn hàng đi theo vận đơn: đối tác lấy hàng → xuất kho, giao thành công → hoàn thành, giao thất bại → chờ giao lại hoặc hoàn hàng, hàng về → nhập lại kho (tùy cấu hình); hủy đơn thì hủy vận đơn ở GHN.
- **Đối tác tự liên hệ** (DTVC): thêm/sửa/ngừng, cập nhật trạng thái vận đơn bằng tay, công nợ COD.
- **Quản lý vận đơn** với tab trạng thái, lọc đối tác/đối soát/ngày, xuất Excel, chi tiết vận đơn và lịch sử, in phiếu giao A5/K80.
- **Đối soát COD và phí** (DSCOD): chọn vận đơn đã giao/hoàn (F3, Chọn nhiều), số liệu hệ thống và đối tác, tab vận đơn chênh lệch; Thanh toán công nợ ghi tiền COD vào từng đơn hàng (phiếu thu tự động) và phí giao hàng thành phiếu chi tự động tính vào lãi lỗ.
- **Tổng quan vận chuyển**: tình hình giao hàng 30 ngày, tình hình đối soát, biểu đồ số vận đơn và phí, tỷ lệ đối tác, tỷ lệ giao thành công, cảnh báo lấy/giao trễ. **Cấu hình giao hàng**: khối lượng, kích thước, yêu cầu mặc định, tự xuất kho, tự nhận hàng hoàn, số ngày cảnh báo.
- Quyền mới `shipments.*`, `cod_reconciliations.*`, `carriers.*` (cấp cho vai trò cũ qua migration).
- Dữ liệu mẫu đối tác, vận đơn ở mọi trạng thái, phiếu đối soát.

### G5: Khuyến mại và tích điểm

- Module **Khuyến mại**: chương trình chiết khấu (theo tổng giá trị đơn, theo từng sản phẩm, sản phẩm mua thêm theo giá trị đơn, theo số lượng sản phẩm, mua sản phẩm - giảm giá sản phẩm) và tặng quà (theo tổng giá trị đơn, theo sản phẩm mua); áp dụng cho phiên bản, loại hoặc nhãn hiệu; nhiều điều kiện/mức; số lượng áp dụng tối đa; thời gian, khung giờ, ngày trong tuần/tháng; chi nhánh, nguồn đơn, nhóm khách hàng; tạm ngừng, kích hoạt, kết thúc.
- **Mã giảm giá**: đợt phát hành (giảm % có mức tối đa hoặc số tiền), đơn tối thiểu, sản phẩm áp dụng, số lần dùng mỗi mã / mỗi khách, dùng chung với khuyến mại; thêm mã tay hoặc tạo hàng loạt ngẫu nhiên; ngừng/xóa từng mã.
- **Cấu hình khuyến mại**: bật/tắt, áp dụng 1 hay nhiều chương trình cho một đơn, bật mã giảm giá.
- Nút **Áp dụng chương trình khuyến mại** ở Tạo đơn và Bán tại quầy: gợi ý chương trình có lợi nhất, chọn quà tặng, nhập mã giảm giá; server tính lại khi lưu (kiểm thời gian, phạm vi, lượt dùng) nên hai màn hình cho cùng kết quả. Đơn hàng, hóa đơn hiển thị chiết khấu khuyến mại và quà tặng; hủy đơn trả lại lượt dùng.
- **Tích điểm khách hàng**: số tiền tích 1 điểm, giá trị 1 điểm, hạng thành viên theo tổng chi tiêu với hệ số tích điểm; cộng điểm khi đơn hoàn thành, trừ khi khách trả hàng; thanh toán bằng điểm; lịch sử điểm và điều chỉnh điểm có lý do; điểm hiện ở chi tiết khách hàng và ô chọn khách.
- Quyền mới `promotions.*`, `coupons.*`, `loyalty.view/update` (cấp cho vai trò cũ qua migration).
- Dữ liệu mẫu chương trình khuyến mại, mã giảm giá, hạng thành viên.

### G4: Sổ quỹ và Báo cáo

- Module **Sổ quỹ**: phiếu thu (RVN), phiếu chi (PVN) với tab Hoàn thành / Đã hủy, nhóm người nộp/nhận (khách hàng, nhà cung cấp, nhân viên, đối tác VC, khác), thay đổi công nợ đối tượng, hạch toán kết quả kinh doanh, in phiếu kèm số tiền bằng chữ, xuất Excel.
- **Phiếu tự động** (loại TUDONG, có chứng từ gốc, không sửa/hủy tay): thanh toán và hoàn tiền đơn hàng, khách trả hàng, thanh toán nhà cung cấp. Lệnh `cashbook:sync` tạo phiếu cho dữ liệu cũ.
- **Loại phiếu thu/chi** có sẵn như Sapo, thêm/sửa/xóa loại riêng.
- **Sổ quỹ** theo kỳ: số dư đầu kỳ + tổng thu − tổng chi = tồn cuối kỳ, lọc theo quỹ (tiền mặt, ngân hàng, ví), chi nhánh, hình thức thanh toán, người tạo.
- Module **Báo cáo** với 23 báo cáo, lọc chi nhánh / thời gian / nguồn bán và xuất Excel: bán hàng theo thời gian, nhân viên, sản phẩm, đơn hàng, chi nhánh, nguồn, khách hàng, nhóm khách, trả hàng, thanh toán; nhập hàng theo thời gian, NCC, sản phẩm, thanh toán nhập hàng; tồn kho, xuất nhập tồn, dưới/vượt định mức; lãi lỗ (kỳ trước, kỳ hiện tại, % thay đổi), công nợ khách hàng, công nợ NCC; khách hàng theo thời gian, khu vực. Lưu báo cáo tùy chỉnh (riêng hoặc dùng chung).
- Bảng tổng hợp theo ngày (`report_sales_daily`, `report_product_daily`) cập nhật qua hàng đợi khi đơn hoàn thành hoặc khách trả hàng; dựng lại hằng đêm (`reports:rebuild --days=3`). Doanh thu ghi nhận theo ngày giao hàng thành công như Sapo.
- Tổng quan: doanh thu hôm nay, biểu đồ 7 ngày và sản phẩm bán chạy lấy từ bảng tổng hợp, khớp với báo cáo.
- Cron `schedule:run` chạy thêm `queue:work --stop-when-empty` mỗi phút (hosting không có Supervisor).
- Dữ liệu mẫu bán hàng 7 ngày, phiếu chi thuê mặt bằng, phiếu thu thanh lý.

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
