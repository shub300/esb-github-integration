<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformProductInventory extends Model
{
    protected $table = 'platform_product_inventory';
    public $timestamps = true;
    protected $fillable = ['user_id', 'user_integration_id', 'platform_id', 'platform_product_id', 'api_product_id', 'api_warehouse_id', 'quantity', 'sku', 'location_code','inventory_channel_id', 'sync_status', 'api_updated_at'];

    protected $dates=['created_at','updated_at'];

    public function platformProduct() {
        return $this->hasOne(PlatformProduct::class, 'id', 'platform_product_id');
    }
}
