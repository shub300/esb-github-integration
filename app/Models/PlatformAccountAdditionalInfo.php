<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAccountAdditionalInfo extends Model
{
    protected $table = 'platform_account_addtional_information';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];
    protected $fillable = [
      'account_id', 'user_integration_id', 'account_currency_code', 'account_product_lenght_unit', 'account_product_weight_unit', 'account_shipping_nominal_code', 'account_discount_nominal_code', 'account_sale_nominal_code', 'account_purchase_nominal_code', 'account_timezone', 'account_tax_scheme','account_giftcard_nominal_code',
    ];
}
