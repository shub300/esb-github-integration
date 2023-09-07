<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformProductDetailAttribute extends Model
{
    protected $table = 'platform_product_detail_attributes';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];
    protected $fillable = ['platform_product_id', 'shortdescription', 'fulldescription', 'lenght', 'height', 'width', 'volume', 'taxcode_ids', 'product_type_ids', 'primary_supplier_id', 'language_code', 'merch', 'material', 'product_type_desc', 'gender', 'style_type', 'color_code', 'size_desc', 'dimension', 'division', 'lbl_code', 'season', 'taxable', 'forward_lot_mixing_rule', 'storage_lot_mixing_rule', 'forward_item_mixing_rule', 'storage_item_mixing_rule', 'allocation_rule','lob'];
}
