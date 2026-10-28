<?php

namespace App\Http\Controllers\Config;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\FileLog;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Xuất/nhập file": who exported or imported which list, and how it went.
 */
class FileLogController extends Controller
{
    /** Lists whose key is not a permission module name. */
    protected const MODULES = [
        'activity-log' => 'Nhật ký hoạt động',
        'price-lists' => 'Chính sách giá',
        'price-list-items' => 'Chính sách giá',
        'prices' => 'Bảng giá',
        'product-brands' => 'Nhãn hiệu',
        'product-categories' => 'Loại sản phẩm',
        'users' => 'Nhân viên',
    ];

    public const STATUSES = ['processing' => 'Đang xử lý', 'done' => 'Hoàn thành', 'failed' => 'Lỗi'];

    public function index(Request $request): Response
    {
        $table = DataTable::for(FileLog::query()->with('user:id,name'), $request, 'file-logs')
            ->search(['file_name'])
            ->filterExact('user', 'user_id')
            ->filterExact('action')
            ->filterExact('module')
            ->filterExact('status')
            ->filterDateRange('created_at')
            ->sortable(['created_at'], '-id');

        $modules = FileLog::query()->distinct()->orderBy('module')->pluck('module')
            ->mapWithKeys(fn (string $module): array => [$module => self::moduleLabel($module)]);

        return Inertia::render('config/file-logs', [
            'logs' => $table->paginate(fn (FileLog $log): array => [
                'id' => $log->id,
                'file_name' => $log->file_name,
                'user' => $log->user?->name,
                'module' => self::moduleLabel($log->module),
                'action' => $log->action,
                'status' => $log->status,
                'rows' => $log->rows,
                'message' => $log->message,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'modules' => $modules,
            'statuses' => self::STATUSES,
        ]);
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module]
            ?? Permissions::modules()[str_replace('-', '_', $module)]['label']
            ?? $module;
    }
}
