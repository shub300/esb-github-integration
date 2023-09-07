<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformInventoryTrail extends Model
{
    protected $table = 'platform_inventory_trails';
    public $timestamps = true;
    protected $fillable = [ 'user_id', 'user_integration_id', 'platform_id', 'api_id','platform_product_id','api_product_id', 'api_warehouse_id', 'api_type_code', 'api_quantity', 'api_location_id', 'api_currency_code', 'sync_status', 'api_updated_at'];
    protected $dates=['created_at','updated_at'];

    public function platformProduct() {
        return $this->hasOne(PlatformProduct::class, 'id', 'platform_product_id');
    }
}
