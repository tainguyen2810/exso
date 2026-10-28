<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    protected const EVENTS = [
        'created' => 'Thêm',
        'updated' => 'Sửa',
        'deleted' => 'Xóa',
        'login' => 'Đăng nhập',
    ];

    public function index(Request $request): Response|StreamedResponse
    {
        $table = DataTable::for(ActivityLog::query()->with('user:id,name'), $request, 'activity-log')
            ->search(['description', 'ip_address'])
            ->filterExact('user', 'user_id')
            ->filterExact('event')
            ->filterDateRange('created_at')
            ->sortable(['created_at'], '-id');

        if ($request->query('export') === 'xlsx') {
            abort_unless($request->user()?->can('activity_log.export'), 403);

            return $table->export('nhat-ky-'.now()->format('Ymd-His'), [
                'Thời gian' => fn (ActivityLog $log) => $log->created_at,
                'Người thực hiện' => fn (ActivityLog $log) => $log->user?->name,
                'Thao tác' => fn (ActivityLog $log) => self::EVENTS[$log->event] ?? $log->event,
                'Nội dung' => fn (ActivityLog $log) => $log->description,
                'IP' => fn (ActivityLog $log) => $log->ip_address,
            ]);
        }

        return Inertia::render('admin/activity-log/index', [
            'logs' => $table->paginate(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'user' => $log->user?->name,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
