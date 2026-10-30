<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Cài đặt {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f0f1f1; --card: #fff; --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --navy: #182537; --primary: #0088ff; --ok: #15803d; --bad: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        main { max-width: 640px; margin: 40px auto; padding: 0 16px; }
        .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 20px; }
        .brand span.logo { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: var(--primary); color: #fff; font-weight: 700; font-size: 20px; }
        .brand strong { font-size: 22px; color: var(--navy); }
        ol.steps { display: flex; gap: 8px; list-style: none; padding: 0; margin: 0 0 16px; counter-reset: step; }
        ol.steps li { flex: 1; font-size: 13px; color: var(--muted); border-top: 3px solid var(--border); padding-top: 6px; counter-increment: step; }
        ol.steps li::before { content: counter(step) ". "; }
        ol.steps li.active { color: var(--primary); border-color: var(--primary); font-weight: 600; }
        ol.steps li.done { color: var(--ok); border-color: var(--ok); }
        section { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 24px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p.lead { color: var(--muted); margin: 0 0 20px; font-size: 14px; line-height: 1.5; }
        ul.checks { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px; font-size: 14px; }
        .ok { color: var(--ok); } .bad { color: var(--bad); }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .full { grid-column: 1 / -1; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .hint { color: var(--muted); font-size: 12px; margin-top: 4px; font-weight: 400; }
        input[type=text], input[type=email], input[type=password], input[type=number] { width: 100%; padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        input:focus { outline: 2px solid var(--primary); outline-offset: -1px; }
        .check { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; font-size: 14px; }
        .check input { margin-top: 3px; }
        .alert { background: #fef2f2; border: 1px solid #fecaca; color: var(--bad); padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .note { background: #fffbeb; border: 1px solid #fde68a; padding: 12px; border-radius: 6px; font-size: 13px; line-height: 1.5; }
        .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; }
        .button { display: inline-block; background: var(--primary); color: #fff; border: 0; border-radius: 6px; padding: 10px 20px; font-size: 15px; cursor: pointer; text-decoration: none; }
        .button:disabled { opacity: .5; cursor: not-allowed; }
        a.back { color: var(--muted); font-size: 14px; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td { padding: 8px 0; border-bottom: 1px solid var(--border); }
        td:first-child { color: var(--muted); width: 45%; }
        #demo-fields[hidden] { display: none; }
    </style>
</head>
<body>
<main>
    <div class="brand"><span class="logo">E</span><strong>{{ config('app.name') }}</strong></div>

    <ol class="steps">
        @foreach (['Kiểm tra máy chủ', 'Cơ sở dữ liệu', 'Cửa hàng', 'Hoàn tất'] as $index => $label)
            <li class="{{ $index + 1 === $step ? 'active' : ($index + 1 < $step ? 'done' : '') }}">{{ $label }}</li>
        @endforeach
    </ol>

    @if ($errors->any())
        <div class="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if ($step === 1)
        <section>
            <h1>Chào mừng đến với {{ config('app.name') }}</h1>
            <p class="lead">Trình cài đặt sẽ kiểm tra máy chủ, kết nối MySQL và tạo cửa hàng cùng tài khoản quản trị. Hãy chuẩn bị sẵn một database MySQL trống (trong WAMP: phpMyAdmin → Databases → Create).</p>
            <ul class="checks">
                @foreach ($requirements as $check)
                    <li class="{{ $check['ok'] ? 'ok' : 'bad' }}">{{ $check['ok'] ? '✓' : '✗' }} {{ $check['label'] }}</li>
                @endforeach
            </ul>
            @unless ($canInstall)
                <p class="bad" style="margin-bottom:0">Hãy bật các mục đang báo ✗ (trong WAMP: menu PHP → PHP extensions) rồi tải lại trang.</p>
            @endunless
            <div class="actions">
                <span></span>
                @if ($canInstall)
                    <a class="button" href="{{ url('/install/database') }}">Bắt đầu cài đặt</a>
                @else
                    <button class="button" type="button" disabled>Bắt đầu cài đặt</button>
                @endif
            </div>
        </section>
    @elseif ($step === 2)
        <form method="POST" action="{{ url('/install/database') }}">
            @csrf
            <section>
                <h1>Kết nối cơ sở dữ liệu</h1>
                <p class="lead">Nhập thông tin MySQL. Mặc định của WAMP: máy chủ 127.0.0.1, tài khoản root, mật khẩu trống.</p>
                <div class="grid">
                    <div><label for="db_host">Máy chủ</label><input type="text" id="db_host" name="db_host" value="{{ old('db_host', $db['host'] ?? '127.0.0.1') }}" required></div>
                    <div><label for="db_port">Cổng</label><input type="number" id="db_port" name="db_port" value="{{ old('db_port', $db['port'] ?? '3306') }}" required></div>
                    <div class="full"><label for="db_database">Tên database</label><input type="text" id="db_database" name="db_database" value="{{ old('db_database', $db['database'] ?? 'exso') }}" required><div class="hint">Database phải được tạo trước và nên để trống.</div></div>
                    <div><label for="db_username">Tài khoản</label><input type="text" id="db_username" name="db_username" value="{{ old('db_username', $db['username'] ?? 'root') }}" required></div>
                    <div><label for="db_password">Mật khẩu</label><input type="password" id="db_password" name="db_password" autocomplete="off"></div>
                </div>
                <div class="actions">
                    <a class="back" href="{{ url('/install') }}">‹ Quay lại</a>
                    <button class="button" type="submit">Kiểm tra kết nối và tiếp tục</button>
                </div>
            </section>
        </form>
    @elseif ($step === 3)
        <form method="POST" action="{{ url('/install/store') }}" id="install-form">
            @csrf
            <section>
                <h1>Thông tin cửa hàng</h1>
                <p class="lead">Kết nối MySQL thành công. Đặt tên cửa hàng và tạo tài khoản chủ cửa hàng (toàn quyền).</p>
                <div class="grid">
                    <div class="full"><label for="store_name">Tên cửa hàng</label><input type="text" id="store_name" name="store_name" value="{{ old('store_name') }}" required></div>
                    <div><label for="admin_name">Họ tên chủ cửa hàng</label><input type="text" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required></div>
                    <div><label for="admin_email">Email đăng nhập</label><input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required></div>
                    <div><label for="admin_password">Mật khẩu (tối thiểu 8 ký tự)</label><input type="password" id="admin_password" name="admin_password" required autocomplete="new-password"></div>
                    <div><label for="admin_password_confirmation">Nhập lại mật khẩu</label><input type="password" id="admin_password_confirmation" name="admin_password_confirmation" required autocomplete="new-password"></div>
                    <div class="full">
                        <input type="hidden" name="demo" value="0">
                        <label class="check"><input type="checkbox" id="demo" name="demo" value="1" @checked(old('demo'))> <span>Cài dữ liệu mẫu và tài khoản demo<span class="hint" style="display:block">Thêm chi nhánh, nhân viên, sản phẩm, nhập hàng, khách hàng, đơn hàng, khuyến mại, vận chuyển... để dùng thử. Không nên chọn khi cài cửa hàng thật.</span></span></label>
                    </div>
                    <div class="full" id="demo-fields" @if (! old('demo')) hidden @endif>
                        <label for="demo_password">Mật khẩu cho các tài khoản demo</label>
                        <input type="password" id="demo_password" name="demo_password" autocomplete="new-password">
                        <div class="hint">Tài khoản demo@{{ \App\Installer\Installer::DEMO_DOMAIN }} (quản lý) và các nhân viên mẫu (thungan@, nhanvien1@... {{ \App\Installer\Installer::DEMO_DOMAIN }}) đều dùng mật khẩu này.</div>
                    </div>
                </div>
                <div class="actions">
                    <a class="back" href="{{ url('/install/database') }}">‹ Quay lại</a>
                    <button class="button" type="submit" id="install-button">Cài đặt</button>
                </div>
            </section>
        </form>
        <script>
            const demo = document.getElementById('demo');
            const fields = document.getElementById('demo-fields');
            demo.addEventListener('change', () => { fields.hidden = ! demo.checked; });
            document.getElementById('install-form').addEventListener('submit', () => {
                const button = document.getElementById('install-button');
                button.disabled = true;
                button.textContent = 'Đang cài đặt, vui lòng đợi...';
            });
        </script>
    @else
        <section>
            <h1>Cài đặt thành công!</h1>
            <p class="lead">{{ config('app.name') }} đã sẵn sàng. Trình cài đặt đã được khóa.</p>
            <table>
                <tr><td>Chủ cửa hàng</td><td>{{ $summary['admin_email'] }}</td></tr>
                @if ($summary['demo_email'])
                    <tr><td>Tài khoản demo (quản lý)</td><td>{{ $summary['demo_email'] }}</td></tr>
                    <tr><td>Thu ngân mẫu</td><td>thungan@{{ $summary['demo_domain'] }}</td></tr>
                    <tr><td>Nhân viên mẫu</td><td>nhanvien1@{{ $summary['demo_domain'] }} … nhanvien6@{{ $summary['demo_domain'] }}</td></tr>
                @endif
            </table>
            @if ($summary['demo_email'])
                <p class="note">Các tài khoản demo đăng nhập bằng mật khẩu demo bạn vừa đặt. Khi dùng thật, hãy khóa hoặc xóa chúng ở trang Nhân viên.</p>
            @endif
            <div class="actions">
                <span></span>
                <a class="button" href="{{ url('/login') }}">Đăng nhập</a>
            </div>
        </section>
    @endif
</main>
</body>
</html>
