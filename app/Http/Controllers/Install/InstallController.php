<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Installer\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * WordPress-style setup wizard: 1. check the server, 2. MySQL, 3. store,
 * admin and optional sample data, 4. done. The MySQL details live in the
 * session between steps and are written to .env only when install succeeds.
 */
class InstallController extends Controller
{
    public function __construct(protected Installer $installer) {}

    public function show(): View
    {
        return view('install.index', [
            'step' => 1,
            'requirements' => $this->installer->requirements(),
            'canInstall' => $this->installer->meetsRequirements(),
        ]);
    }

    public function database(Request $request): View|RedirectResponse
    {
        if (! $this->installer->meetsRequirements()) {
            return redirect('/install');
        }

        /** @var array<string, mixed> $db */
        $db = $request->session()->get('install.db', []);

        return view('install.index', ['step' => 2, 'db' => $db]);
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        abort_unless($this->installer->meetsRequirements(), 422, 'Máy chủ chưa đáp ứng yêu cầu.');

        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ], [], [
            'db_host' => 'máy chủ',
            'db_port' => 'cổng',
            'db_database' => 'tên database',
            'db_username' => 'tài khoản',
        ]);

        $db = [
            'host' => $data['db_host'],
            'port' => (int) $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'] ?? '',
        ];

        try {
            $this->installer->configureDatabase($db);
        } catch (Throwable $e) {
            return back()->withInput($request->except('db_password'))
                ->withErrors(['db_host' => 'Không kết nối được MySQL: '.$e->getMessage()]);
        }

        if ($this->installer->databaseHasTables()) {
            return back()->withInput($request->except('db_password'))
                ->withErrors(['db_database' => "Database \"{$db['database']}\" đã có bảng (có thể từ lần cài trước bị lỗi). Hãy xóa và tạo lại database trống trong phpMyAdmin, hoặc nhập tên database khác."]);
        }

        $request->session()->put('install.db', $db);

        return redirect('/install/store');
    }

    public function store(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('install.db')) {
            return redirect('/install/database');
        }

        return view('install.index', ['step' => 3]);
    }

    public function install(Request $request): RedirectResponse
    {
        abort_unless($this->installer->meetsRequirements(), 422, 'Máy chủ chưa đáp ứng yêu cầu.');

        /** @var array{host: string, port: int, database: string, username: string, password: string}|null $db */
        $db = $request->session()->get('install.db');

        if ($db === null) {
            return redirect('/install/database');
        }

        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'confirmed', Password::min(8)],
            'demo' => ['boolean'],
            'demo_password' => ['exclude_unless:demo,1', 'required', Password::min(8)],
        ], [], [
            'store_name' => 'tên cửa hàng',
            'admin_name' => 'họ tên quản trị',
            'admin_email' => 'email đăng nhập',
            'admin_password' => 'mật khẩu',
            'demo_password' => 'mật khẩu tài khoản demo',
        ]);

        try {
            $this->installer->configureDatabase($db);
        } catch (Throwable $e) {
            return redirect('/install/database')->withErrors(['db_host' => 'Không kết nối được MySQL: '.$e->getMessage()]);
        }

        $demo = $request->boolean('demo');

        // Sample data for every module takes a while on a slow machine.
        if ($demo) {
            @set_time_limit(300);
        }

        $this->installer->install([
            'store_name' => $data['store_name'],
            'admin_name' => $data['admin_name'],
            'admin_email' => $data['admin_email'],
            'admin_password' => $data['admin_password'],
            'app_url' => $request->root(),
            'demo_password' => $demo ? $data['demo_password'] : null,
        ]);

        $request->session()->forget('install.db');
        $request->session()->flash('install.done', [
            'admin_email' => $data['admin_email'],
            'demo_email' => $demo ? Installer::DEMO_LOGIN.'@'.Installer::DEMO_DOMAIN : null,
            'demo_domain' => Installer::DEMO_DOMAIN,
        ]);

        return redirect('/install/done');
    }

    public function done(Request $request): View|RedirectResponse
    {
        /** @var array{admin_email: string, demo_email: string|null, demo_domain: string}|null $summary */
        $summary = $request->session()->get('install.done');

        if ($summary === null) {
            return redirect('/login');
        }

        return view('install.index', ['step' => 4, 'summary' => $summary]);
    }
}
