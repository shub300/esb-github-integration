<?php

namespace App\Models;

use App\Models\PlatformOrder;
use Illuminate\Database\Eloquent\Model;

class PlatformOrderAdditionalInformation extends Model
{
    protected $table = 'platform_order_additional_information';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];
    protected $fillable = ['platform_order_id', 'api_channel_id', 'api_owner_id', 'is_drop_ship', 'closed_on', 'parent_order_id', 'store_number',  'exchange_rate','pay_terms' ];

    public function platformOrder()
    {
        return $this->belongsTo(PlatformOrder::class);
    }
}
