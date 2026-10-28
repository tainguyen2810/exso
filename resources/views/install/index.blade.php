<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cài đặt {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f4f6f8; --card: #fff; --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --primary: #1e3a5f; --ok: #15803d; --bad: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        main { max-width: 720px; margin: 32px auto; padding: 0 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        p.lead { color: var(--muted); margin: 0 0 24px; }
        section { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        ul.checks { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px; font-size: 14px; }
        .ok { color: var(--ok); } .bad { color: var(--bad); }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        input { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; }
        .error { color: var(--bad); font-size: 13px; margin-top: 4px; }
        .alert { background: #fef2f2; border: 1px solid #fecaca; color: var(--bad); padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        button { background: var(--primary); color: #fff; border: 0; border-radius: 6px; padding: 10px 18px; font-size: 15px; cursor: pointer; }
        button:disabled { opacity: .5; cursor: not-allowed; }
    </style>
</head>
<body>
<main>
    <h1>Cài đặt {{ config('app.name') }}</h1>
    <p class="lead">Kiểm tra máy chủ, nhập thông tin MySQL và tạo tài khoản quản trị.</p>

    <section>
        <h2>1. Kiểm tra máy chủ</h2>
        <ul class="checks">
            @foreach ($requirements as $check)
                <li class="{{ $check['ok'] ? 'ok' : 'bad' }}">{{ $check['ok'] ? '✓' : '✗' }} {{ $check['label'] }}</li>
            @endforeach
        </ul>
        @unless ($canInstall)
            <p class="bad" style="margin-bottom:0">Hãy bật các mục đang báo ✗ (trong WAMP: menu PHP → PHP extensions) rồi tải lại trang.</p>
        @endunless
    </section>

    @if ($errors->any())
        <div class="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/install') }}">
        @csrf
        <section>
            <h2>2. Cơ sở dữ liệu MySQL</h2>
            <div class="grid">
                <div><label for="db_host">Máy chủ</label><input id="db_host" name="db_host" value="{{ old('db_host', '127.0.0.1') }}" required></div>
                <div><label for="db_port">Cổng</label><input id="db_port" name="db_port" type="number" value="{{ old('db_port', '3306') }}" required></div>
                <div><label for="db_database">Tên database</label><input id="db_database" name="db_database" value="{{ old('db_database', 'exso') }}" required></div>
                <div><label for="db_username">Tài khoản</label><input id="db_username" name="db_username" value="{{ old('db_username', 'root') }}" required></div>
                <div><label for="db_password">Mật khẩu</label><input id="db_password" name="db_password" type="password" autocomplete="off"></div>
            </div>
        </section>

        <section>
            <h2>3. Cửa hàng và quản trị viên</h2>
            <div class="grid">
                <div style="grid-column: 1 / -1"><label for="store_name">Tên cửa hàng</label><input id="store_name" name="store_name" value="{{ old('store_name') }}" required></div>
                <div><label for="admin_name">Họ tên quản trị</label><input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required></div>
                <div><label for="admin_email">Email đăng nhập</label><input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required></div>
                <div><label for="admin_password">Mật khẩu (tối thiểu 8 ký tự)</label><input id="admin_password" name="admin_password" type="password" required autocomplete="new-password"></div>
                <div><label for="admin_password_confirmation">Nhập lại mật khẩu</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required autocomplete="new-password"></div>
            </div>
        </section>

        <button type="submit" @disabled(! $canInstall)>Cài đặt</button>
    </form>
</main>
</body>
</html>
