<?php

namespace App\Support\Activity;

use Illuminate\Database\Eloquent\Model;

/**
 * Logs create / update / delete of a model with the before and after values
 * of the changed attributes. Hidden attributes (passwords, tokens) are never logged.
 *
 * @mixin Model
 */
trait LogsActivity
{
    /**
     * Name of the record as shown in the log, e.g. "nhân viên Nguyễn Văn A".
     */
    abstract public function activityLabel(): string;

    public static function bootLogsActivity(): void
    {
        static::created(function (self $model): void {
            $model->writeActivity('created', 'Thêm '.$model->activityLabel(), ['new' => $model->loggableAttributes($model->getAttributes())]);
        });

        static::updated(function (self $model): void {
            $changes = $model->loggableAttributes($model->getChanges());

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getPrevious(), $changes);

            $model->writeActivity('updated', 'Sửa '.$model->activityLabel(), ['old' => $old, 'new' => $changes]);
        });

        static::deleted(function (self $model): void {
            $model->writeActivity('deleted', 'Xóa '.$model->activityLabel(), ['old' => $model->loggableAttributes($model->getAttributes())]);
        });
    }

    /**
     * Attributes that change too often to be worth logging.
     *
     * @return list<string>
     */
    public function activityIgnored(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function writeActivity(string $event, string $description, array $properties): void
    {
        app(ActivityLogger::class)->log($event, $description, $this, $properties);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function loggableAttributes(array $attributes): array
    {
        $ignored = [...$this->getHidden(), ...$this->activityIgnored(), 'id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at', 'last_login_at'];

        return array_diff_key($attributes, array_flip($ignored));
    }
}
