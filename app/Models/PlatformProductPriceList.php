<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformProductPriceList extends Model
{
    protected $table = 'platform_porduct_price_list';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];
    protected $fillable = ['platform_product_id', 'platform_object_data_id', 'price', 'api_currency_code', 'status'];
}
