<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PlatformProductPriceList;

class PlatformProductBundle extends Model
{
    protected $table = 'platform_product_bundle_items';
    public $timestamps = true;
    protected $fillable = [ 'api_product_bundle_id', 'platform_product_id', 'platform_product_bundle_id', 'sku', 'bundle_qty','status'];
    protected $dates=['created_at','updated_at'];
    public function PlatformProductChild() {
        return $this->hasOne(PlatformProduct::class, 'id','platform_product_bundle_id')->where('is_deleted',0);
    }
}
