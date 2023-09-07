<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class PlatformCustomerAdditionalInformation extends Model
{
    protected $table = 'platform_customer_additional_information';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];
    protected $fillable = ['platform_customer_id', 'api_tag_id', 'location_id','pay_terms','currency'];
}
