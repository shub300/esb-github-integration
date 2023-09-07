<?php

namespace App\Models;

use App\Models\PlatformInvoice;
use Illuminate\Database\Eloquent\Model;

class PlatformInvoiceLine extends Model
{
    protected $table = 'platform_invoice_line';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];

    protected $fillable = ['platform_invoice_id', 'api_invoice_line_id', 'api_product_id', 'product_name', 'ean', 'sku', 'gtin', 'upc', 'mpn', 'qty', 'unit_price', 'uom', 'description', 'total', 'api_code', 'row_type', 'linked_id'];

    public function platformInvoice()
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }
}
