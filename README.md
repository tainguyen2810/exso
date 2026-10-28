# EXSO — phần mềm bán hàng

Phần mềm quản lý bán hàng đa kênh cho ITSO: sản phẩm, kho, đơn hàng, POS, khách hàng, sổ quỹ, báo cáo.
Chạy được trên WAMP, aaPanel hoặc hosting PHP thường (giống WordPress), và có sẵn nền để chạy SaaS nhiều cửa hàng.

> Trạng thái: **G1b — bảng dữ liệu và form dùng chung**. Đã có đăng nhập, trình cài đặt, nhân viên, vai trò + ma trận quyền, chi nhánh, nhật ký hoạt động; danh sách có bộ lọc, lưu bộ lọc, chọn cột, thao tác hàng loạt, xuất/nhập Excel. Các module bán hàng làm từ G2 trở đi.

## Yêu cầu

- PHP **8.3** trở lên, với các extension: `ctype`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `xmlreader`, `zip`
- MySQL **8**
- Để sửa code: Composer 2 và Node.js 22+

## Chạy thử trên WAMP (Windows)

1. **Chọn phiên bản**: chuột trái biểu tượng WAMP ở khay → PHP → Version → chọn 8.3 trở lên; MySQL → Version → 8.x.
2. **Bật extension**: WAMP → PHP → PHP extensions → tick các extension ở mục Yêu cầu (thường chỉ thiếu `intl`, `zip`, `gd`, `fileinfo`).
   Lưu ý bật cho cả PHP dòng lệnh: sửa file `php.ini` trong `C:\wamp64\bin\php\php8.x.x\` (bỏ dấu `;` trước `extension=intl`...).
3. **Lấy code** vào `C:\wamp64\www\exso`:
    ```bash
    git clone https://github.com/tainguyen2810/exso.git C:\wamp64\www\exso
    cd C:\wamp64\www\exso
    composer install
    npm install
    npm run build
    ```
    (Khi có bản phát hành .zip thì chỉ cần giải nén, không cần 3 lệnh cuối.)
4. **Tạo database**: mở phpMyAdmin (`http://localhost/phpmyadmin`) → tạo database `exso`, bảng mã `utf8mb4_unicode_ci`.
5. **Tạo Virtual Host**: mở `http://localhost` → Add a Virtual Host → tên `exso.local`, đường dẫn `C:/wamp64/www/exso/public` → sau đó chuột phải WAMP → Tools → Restart DNS.
6. **Cài đặt**: mở `http://exso.local` → tự chuyển sang trang `/install` → kiểm tra các dòng đều ✓ → nhập MySQL (mặc định WAMP: tài khoản `root`, mật khẩu trống), tên cửa hàng, tài khoản quản trị → **Cài đặt**.
7. **Tác vụ định kỳ** (thay cho cron, cần từ khi có hàng đợi/báo cáo): Task Scheduler → Create Task → Trigger mỗi 1 phút →
   Action: `C:\wamp64\bin\php\php8.x.x\php.exe` với tham số `C:\wamp64\www\exso\artisan schedule:run`.

Muốn cho máy khác trong cửa hàng dùng: mở cổng 80 trên Windows Firewall, rồi truy cập bằng IP máy chủ (ví dụ `http://192.168.1.10`) sau khi đặt Virtual Host đó làm mặc định.

## Phát triển

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
docker compose up -d          # tùy chọn: MySQL + Redis + Mailpit (hoặc dùng MySQL của WAMP)
INSTALLER_INSTALLED=true php artisan migrate --seed   # dữ liệu mẫu: admin@exso.test / password (hoặc mở /install)
composer dev                  # http://localhost:8000
php artisan test              # chạy test
```

Quy ước code và quy tắc nghiệp vụ: xem [CLAUDE.md](CLAUDE.md).

## Triển khai lên aaPanel

1. Tạo site PHP 8.3 + database MySQL trên aaPanel, bật SSL Let's Encrypt.
2. Upload code (hoặc gói .zip phát hành), trỏ thư mục chạy (running directory) vào `public/`.
3. Mở `https://<tên miền>/install` và làm theo hướng dẫn.
4. Thêm cron: `* * * * * php /www/wwwroot/<site>/artisan schedule:run`.
5. Sao lưu MySQL hằng ngày ra ngoài server.

## Chế độ SaaS

Đặt `APP_MODE=saas` và `TENANCY_CENTRAL_DOMAIN=<tên miền>`; mỗi cửa hàng chạy ở `<slug>.<tên miền>` hoặc tên miền riêng.
Phần đăng ký, gói cước, super admin làm ở giai đoạn G8.
