<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PlatformKitChildProductQuantity extends Model
{
    protected $table = 'platform_kit_child_product_quantities';
    public $timestamps = true;
    protected $fillable = ['platform_product_id', 'platform_bundle_product_id', 'quantity', 'status'];
    protected $dates = ['created_at', 'updated_at'];
}
