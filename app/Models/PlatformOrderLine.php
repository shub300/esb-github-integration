<?php

namespace App\Models;

use App\Models\PlatformOrder;
use Illuminate\Database\Eloquent\Model;

class PlatformOrderLine extends Model
{
    protected $table = 'platform_order_line';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];

    protected $fillable = ['platform_order_id', 'api_order_line_id', 'api_product_id', 'product_name', 'item_row_sequence', 'ean', 'sku', 'gtin', 'upc', 'mpn', 'barcode', 'qty', 'subtotal', 'subtotal_tax','discount_amount','discount_tax', 'total', 'total_tax', 'taxes', 'variation_id', 'price', 'unit_price', 'uom', 'description', 'notes', 'api_code', 'row_type', 'linked_id', 'is_deleted'];

    public function platformOrder()
    {
        return $this->belongsTo(PlatformOrder::class, 'platform_order_id');
    }
}
