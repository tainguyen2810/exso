<?php

namespace Modules\Report\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the daily sales totals (report_product_daily). Only SalesAggregator writes it.
 */
class ProductDaily extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'report_product_daily';

    /** @var list<string> */
    protected $guarded = ['id'];
}
