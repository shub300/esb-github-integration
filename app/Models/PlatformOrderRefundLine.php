<?php

namespace App\Models;

use App\Models\PlatformOrderRefund;
use Illuminate\Database\Eloquent\Model;

class PlatformOrderRefundLine extends Model
{
    protected $table = 'platform_order_refund_lines';

    protected $fillable = [
       'platform_order_refund_id', 'api_order_line_id', 'api_product_id', 'variation_id', 'product_name', 'sku', 'qty', 'price', 'subtotal', 'subtotal_tax', 'total', 'total_tax', 'taxes', 'row_type',
        'api_warehouse_id',	'api_release_date'
    ];

    public function platformOrderRefund()
    {
        return $this->belongsTo(PlatformOrderRefund::class, 'platform_order_refund_id');
    }
}
