<?php

namespace App\Models;

use App\Models\PlatformOrder;
use Illuminate\Database\Eloquent\Model;

class PlatformOrderAddress extends Model
{
    protected $table = 'platform_order_address';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];

    protected $fillable = ['platform_order_id', 'address_type', 'address_name', 'address_id', 'firstname', 'lastname', 'company', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'email', 'phone_number','phone_number2', 'ship_speed', 'carrier_code'];

    public function platformOrder()
    {
        return $this->belongsTo(PlatformOrder::class, 'platform_order_id');
    }
}
