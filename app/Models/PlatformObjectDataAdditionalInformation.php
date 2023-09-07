<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformObjectDataAdditionalInformation extends Model
{
    protected $table = 'platform_object_data_additional_information';

    public $timestamps = true;

    protected $dates = ['created_at','updated_at'];

    protected $fillable = [
        'user_integration_id', 'platform_object_data_id', 'api_address_id', 'address1', 'city', 'state', 'name', 'country', 'postal_code','terms_info','lob'
    ];
}
