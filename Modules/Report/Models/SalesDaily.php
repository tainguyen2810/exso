<?php

namespace Modules\Report\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the daily sales totals (report_sales_daily). Only SalesAggregator writes it.
 */
class SalesDaily extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'report_sales_daily';

    /** @var list<string> */
    protected $guarded = ['id'];
}
