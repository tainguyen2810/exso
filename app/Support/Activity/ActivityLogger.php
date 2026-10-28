<?php

namespace App\Support\Activity;

use App\Models\ActivityLog;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the "who did what, when" trail shown on the activity log page.
 */
class ActivityLogger
{
    public function __construct(protected TenantContext $tenancy) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, string $description, ?Model $subject = null, array $properties = []): ?ActivityLog
    {
        if (! $this->tenancy->check()) {
            return null;
        }

        $log = new ActivityLog([
            'event' => $event,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
        $userId = auth()->id();
        $log->user_id = $userId === null ? null : (int) $userId;
        $log->created_at = now();

        if ($subject !== null) {
            $log->subject()->associate($subject);
        }

        $log->save();

        return $log;
    }
}
