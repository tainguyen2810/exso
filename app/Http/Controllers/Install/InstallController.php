<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Installer\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class InstallController extends Controller
{
    public function __construct(protected Installer $installer) {}

    public function show(): View
    {
        return view('install.index', [
            'requirements' => $this->installer->requirements(),
            'canInstall' => $this->installer->meetsRequirements(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->installer->meetsRequirements(), 422, 'Máy chủ chưa đáp ứng yêu cầu.');

        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'store_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        try {
            $this->installer->configureDatabase([
                'host' => $data['db_host'],
                'port' => $data['db_port'],
                'database' => $data['db_database'],
                'username' => $data['db_username'],
                'password' => $data['db_password'] ?? '',
            ]);
        } catch (Throwable $e) {
            return back()->withInput($request->except('db_password', 'admin_password', 'admin_password_confirmation'))
                ->withErrors(['db_host' => 'Không kết nối được MySQL: '.$e->getMessage()]);
        }

        $this->installer->install([
            'store_name' => $data['store_name'],
            'admin_name' => $data['admin_name'],
            'admin_email' => $data['admin_email'],
            'admin_password' => $data['admin_password'],
            'app_url' => $request->root(),
        ]);

        return redirect('/login')->with('status', 'Cài đặt xong. Đăng nhập bằng tài khoản quản trị vừa tạo.');
    }
}
