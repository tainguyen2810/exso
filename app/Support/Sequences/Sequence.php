<?php

namespace App\Support\Sequences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Document codes per store, like Sapo: PN00001, PN00002, ...
 *
 * The counter row is locked, so two people saving at once never get the
 * same code. Codes already taken (e.g. typed by hand) are skipped.
 */
class Sequence
{
    /**
     * @param  class-string<Model>  $model  model whose `code` column must stay unique
     */
    public static function next(string $prefix, string $model, string $column = 'code', int $pad = 5): string
    {
        $tenantId = tenancy()->id() ?? throw new RuntimeException('Sequences need a current store.');

        return DB::transaction(function () use ($tenantId, $prefix, $model, $column, $pad): string {
            DB::table('sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'key' => $prefix,
                'value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $value = (int) DB::table('sequences')
                ->where('tenant_id', $tenantId)
                ->where('key', $prefix)
                ->lockForUpdate()
                ->value('value');

            do {
                $code = $prefix.str_pad((string) ++$value, $pad, '0', STR_PAD_LEFT);
            } while ($model::query()->where($column, $code)->exists());

            DB::table('sequences')
                ->where('tenant_id', $tenantId)
                ->where('key', $prefix)
                ->update(['value' => $value, 'updated_at' => now()]);

            return $code;
        });
    }
}
