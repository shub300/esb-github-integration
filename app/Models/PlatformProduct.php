<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PlatformProductPriceList;

class PlatformProduct extends Model
{
    protected $table = 'platform_product';
    public $timestamps = true;
    protected $fillable = [ 'user_id', 'user_integration_id', 'platform_id', 'api_product_id', 'api_product_code', 'api_variant_id', 'inventory_tracking', 'product_name', 'ean', 'sku', 'manufacturer_sku', 'gtin', 'upc', 'isbn', 'mpn', 'barcode', 'brand_id', 'api_warehouse_id', 'bundle', 'weight', 'weight_unit', 'uom', 'stock_track', 'custom_fields', 'product_status', 'price', 'description', 'category_id', 'has_variations',  'product_sync_status', 'inventory_sync_status', 'api_updated_at', 'api_inventory_lastmodified_time', 'parent_product_id', 'linked_id', 'is_deleted','price_type','api_created_at'];
    protected $dates=['created_at','updated_at'];

    public function platformProductPriceList()
    {
        return $this->hasMany(PlatformProductPriceList::class, 'platform_product_id', 'id');
    }
    public function platformProductAttribute()
    {
        return $this->hasOne(PlatformProductDetailAttribute::class, 'platform_product_id', 'id');
    }
    public function inventoryTrails() {
        return $this->hasMany(PlatformInventoryTrail::class, 'platform_product_id', 'id');
    }
    public function linkedProduct() {
        return $this->hasOne(self::class, 'id','linked_id');
    }
    public function kitQuantity() {
        return $this->hasOne(PlatformKitChildProductQuantity::class, 'platform_product_id','id')->where('status',1);
    }
    public function PlatformProductInventory(){
        return $this->hasMany(PlatformProductInventory::class, 'platform_product_id', 'id');
    }
    public function PlatformProductOption()
    {
        return $this->hasMany(PlatformProductOption::class, 'platform_product_id', 'id');
    }
    public function PlatformProductBundle()
    {
        return $this->hasMany(PlatformProductBundle::class, 'platform_product_id', 'id')->where('status',1);
    }
    
}
