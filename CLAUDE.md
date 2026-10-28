# EXSO — phần mềm bán hàng của ITSO

Phần mềm quản lý bán hàng đa kênh, tham khảo nghiệp vụ Sapo GO (đơn hàng, kho, khách hàng, sổ quỹ, báo cáo).
Tài liệu thiết kế gốc: "Thiết kế phần mềm bán hàng (tham khảo Sapo GO)". Làm theo giai đoạn G0 → G8, mỗi giai đoạn một nhánh + PR.

## Stack

Laravel 13, PHP 8.3+, MySQL 8, Inertia 3 + React 19 + TypeScript, Tailwind 4, shadcn/ui, Pest 4.
Fortify (đăng nhập), Wayfinder (route TS), nwidart/laravel-modules (module). Redis tùy chọn.

## Chế độ chạy

- `APP_MODE=standalone` (mặc định): tự cài kiểu WordPress, 1 cửa hàng (tenant), có trình cài `/install`.
- `APP_MODE=saas`: nhiều cửa hàng, nhận biết theo `<slug>.TENANCY_CENTRAL_DOMAIN` hoặc tên miền riêng (`tenants.domain`).
- Phải chạy được trên WAMP / hosting PHP thường: không phụ thuộc Docker, SSH hay Supervisor; chỉ cần cron `schedule:run`.

## Lệnh

- Dev: `composer dev` (server + vite + queue + log). Dịch vụ phụ: `docker compose up -d` (MySQL, Redis, Mailpit) — tùy chọn.
- Test: `php artisan test` — chạy trước mọi commit.
- Lint PHP: `composer lint` (Pint). Kiểm tra JS/TS: `npm run check`, `npm run types:check`. PHPStan: `composer types:check`.
- Dữ liệu mẫu: `INSTALLER_INSTALLED=true php artisan migrate:fresh --seed` → chủ cửa hàng `admin@exso.test`, thu ngân `thungan@exso.test`, mật khẩu `password`.

## Cấu trúc

- `app/` = core: `Tenancy/` (TenantContext, BelongsToTenant, TenantScope), `Installer/`, `Support/Hooks` (Hook::filter), `Support/Settings` (settings theo tenant).
- `Modules/<Module>/` = nghiệp vụ (từ G2): Models, Services, Http, Database/Migrations, resources/js, module.json.
- `resources/js/components/ui` (shadcn), `resources/js/components` (component app), `resources/js/pages` (trang Inertia).
- Trình cài: `routes/web.php` (`/install`), `resources/views/install` (Blade thuần, không cần build asset).

## Quy tắc nghiệp vụ (bắt buộc)

- Tiền: BIGINT đơn vị đồng, không dùng float.
- Tồn kho chỉ thay đổi qua InventoryService, trong DB transaction + `lockForUpdate`, luôn ghi `stock_movements`.
- Server tính lại tổng tiền đơn, không tin số từ client.
- Mọi bảng nghiệp vụ có `tenant_id` (+ `branch_id` từ G1) và model dùng trait `BelongsToTenant`. Không query vượt tenant; nếu thật sự cần thì dùng `withoutGlobalScope(TenantScope::class)` và ghi chú lý do.
- Thiếu tenant trong chế độ SaaS thì query trả rỗng (fail closed). Job/command chạy cho một cửa hàng phải bọc trong `tenancy()->run($tenant, ...)`.
- Quyền: định nghĩa trong `app/Authorization/Permissions.php` dạng `<module>.<action>` (view, view_all, create, update, delete, approve, cancel, ship, pay, export, import). Mỗi module có `group` (nhóm hiện trên trang Vai trò như Sapo) và `labels` để đổi tên thao tác. Module có `view_all` thì `view` = chỉ xem bản ghi mình phụ trách. Thêm module/thao tác mới → thêm vào `modules()`, chạy `php artisan permissions:sync`, thêm mục menu trong `resources/js/config/menu.ts` với `permission`. Chỉ thêm, không đổi tên quyền; quyền mới cho vai trò cũ thì cấp bằng migration (xem `2026_10_31_000001_...`).
- Xuất/nhập Excel kiểm quyền riêng `<module>.export` / `<module>.import` (cả server lẫn nút).
- Quyền dữ liệu (`Permissions::dataPermissions()`, vd `products.view_cost` xem giá vốn/giá nhập) gán theo từng nhân viên, không nằm trong vai trò. Ẩn giá vốn ở server khi `! $user->can('products.view_cost')`.
- Vai trò theo chi nhánh: `role_assignments` (branch_id null = mọi chi nhánh làm việc). Quyền hiệu lực = vai trò áp dụng ở chi nhánh đang chọn + quyền dữ liệu; kiểm bằng `Gate::before` (spatie `register_permission_check_method` = false). Luôn kiểm bằng `can()`, không dùng `hasPermissionTo()`.
- Chống tự nâng quyền: người không phải chủ chỉ cấp được vai trò/quyền/chi nhánh mình có, không sửa được chính mình, chủ cửa hàng hay người mạnh hơn (`User::canManage`, `grantablePermissions`).
- Mọi route kiểm tra quyền bằng middleware `can:<module>.<action>` (hoặc Policy khi cần kiểm theo bản ghi). Chủ cửa hàng (`users.is_owner`) qua `Gate::before` có toàn quyền.
- Vai trò là của từng cửa hàng (spatie/laravel-permission, teams = tenant_id). `TenantContext` tự đặt team id.
- Chi nhánh: user chỉ làm việc ở chi nhánh được gán (`$user->branchIds()`), chi nhánh đang chọn lấy từ `CurrentBranch`. Từ G2, dữ liệu theo chi nhánh phải lọc theo `branchIds()`.
- Model nghiệp vụ quan trọng dùng trait `LogsActivity` (ghi giá trị trước/sau vào `activity_logs`, tự bỏ trường hidden).
- Index bắt đầu bằng `tenant_id` cho các bảng lớn.

## Quy tắc nâng cấp

- Semver + CHANGELOG.md. Migration chỉ thêm, không sửa migration đã phát hành (G0 chưa phát hành nên còn sửa được).
- Đổi cột qua 2 bản: thêm cột mới + chuyển dữ liệu, bản sau mới bỏ cột cũ.
- Module không sửa file core, chỉ dùng Events (việc "sau khi") và `Hook::filter()` (chỉnh giá trị).
- Cấu hình nghiệp vụ lưu bảng `settings` qua `settings('nhom.khoa')`, không thêm biến `.env` mới nếu không bắt buộc.

## Giao diện

- Tiếng Việt; số 1.234.567; ngày dd/MM/yyyy; múi giờ Asia/Ho_Chi_Minh.
- Bố cục giống Sapo GO (sidebar xanh navy 230px, topbar trắng 52px, nền #F0F1F1, nút/link #0088FF) nhưng giữ tên, logo EXSO/ITSO. Ghi chú khảo sát Sapo: `exso-docs/sapo-khao-sat.md` trong thư mục dự án.
- Menu: `resources/js/config/menu.ts` theo cây menu Sapo; trang chưa làm trỏ tới route "sắp có" trong `$upcoming` (routes/web.php) — giai đoạn nào làm trang đó thì xóa dòng tương ứng. Trang cài đặt cửa hàng nằm dưới Cấu hình (`/config`, mục trong `configEntries`); `/settings/*` là tài khoản cá nhân.
- Tiêu đề trang = breadcrumb cuối, hiện trên topbar; muốn đổi thì dùng `<PageTitle>`, nút trên topbar dùng `<TopbarActions>` (`@/components/app/topbar`).
- Trang danh sách: nút chính (+ Thêm …) truyền vào `DataTable actions`, "Xuất file / Nhập file" nằm trên thẻ bên trái; tên bản ghi là link xanh (`RecordLink`) mở trang sửa, không dùng icon bút/thùng rác ở dòng; xóa nằm ở cuối trang sửa (nút đỏ, `ConfirmDelete trigger`) hoặc thao tác hàng loạt. Bộ lọc đã lưu hiện thành tab. Trạng thái dùng `Pill`/`StatusBadge`.
- Trang danh sách: server dùng `App\Support\DataTable\DataTable::for($query, $request, '<key>')` (->search, ->filterExact, ->filterDateRange, ->filter, ->sortable, ->paginate / ->export); trang React dùng `<DataTable>` (`@/components/data-table`). Query string: `search`, `filter[key]`, `sort=-cot`, `per_page` (20/50/100). Xuất Excel = cùng route + `?export=xlsx`.
- Thao tác hàng loạt: route `POST <module>/bulk` {action, ids}, kiểm quyền như thao tác đơn lẻ.
- Excel: `App\Support\Excel\ExcelExport` / `ExcelImport` (OpenSpout, ghi/đọc từng dòng). Nhập: có dòng lỗi thì không nhập dòng nào, báo lỗi theo số dòng; luôn có tệp mẫu.
- Trang tạo/sửa dùng `DocumentForm` (+ `FormSection`) 2 cột 8/4; topbar có "‹ Quay lại …" (`backLabel`) + Hủy/Lưu, cuối trang có Xóa (trái) và Lưu (phải); F1/Ctrl+S lưu.
- Prop của trang không trùng tên prop dùng chung (`auth`, `store`, `branchContext`, `name`, `sidebarOpen`), vì prop trang sẽ đè lên.
- Ngày nhập tay dạng dd/mm/yyyy (server nhận cả d/m/Y và Y-m-d).
- Phím tắt giữ như Sapo: F1 lưu đơn, F3 tìm sản phẩm, F4 tìm khách, F6 chiết khấu đơn, F7 phí/chi phí, F9 thêm dịch vụ, F10 bộ lọc.

## Cách làm việc

- Mỗi giai đoạn một nhánh `g<n>-<tên>`, kết thúc khi test xanh và đã thử trên trình duyệt.
- Logic tiền và kho: viết test trước, chạy đến khi xanh.
- Sau mỗi giai đoạn cập nhật file này với quy ước mới.
