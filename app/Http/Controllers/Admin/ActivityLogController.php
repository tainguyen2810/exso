<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->integer('user_id');
        $event = $request->string('event')->toString();

        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->when($userId > 0, fn ($query) => $query->where('user_id', $userId))
            ->when($event !== '', fn ($query) => $query->where('event', $event))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'user' => $log->user?->name,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/activity-log/index', [
            'logs' => $logs,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['user_id' => $userId ?: null, 'event' => $event],
        ]);
    }
}
