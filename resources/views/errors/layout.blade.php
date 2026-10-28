<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f6f8; color: #1f2937; }
        main { text-align: center; padding: 24px; max-width: 420px; }
        .code { font-size: 56px; font-weight: 700; color: #1e3a5f; margin: 0; }
        h1 { font-size: 20px; margin: 8px 0; }
        p { color: #6b7280; margin: 0 0 20px; }
        a { display: inline-block; background: #1e5bb8; color: #fff; text-decoration: none; padding: 9px 18px; border-radius: 6px; }
    </style>
</head>
<body>
<main>
    <p class="code">@yield('code')</p>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <a href="{{ url('/dashboard') }}">Về trang tổng quan</a>
</main>
</body>
</html>
