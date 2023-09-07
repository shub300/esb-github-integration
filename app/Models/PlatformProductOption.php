<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformProductOption extends Model
{
    protected $table = 'platform_product_options';
    public $timestamps = true;
    protected $fillable = ['api_option_id','api_option_value_id','option_name', 'option_value', 'platform_product_id','status'];
    protected $dates=['created_at','updated_at'];
    public function linkedProduct() {
        return $this->hasOne(PlatformProduct::class, 'id','platform_product_id');
    }
}
