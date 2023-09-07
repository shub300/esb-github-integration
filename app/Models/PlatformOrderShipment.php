<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformOrderShipment extends Model
{
    protected $table = 'platform_order_shipments';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];

    protected $fillable = [
      'user_id', 'platform_id', 'user_integration_id', 'shipment_id', 'sync_status', 'platform_order_id', 'order_id', 'warehouse_id', 'shipment_transfer', 'shipment_status', 'boxes', 'tracking_info', 'shipping_method', 'carrier_code', 'ship_class', 'realease_date', 'created_on', 'weight', 'created_by', 'tracking_url', 'shipment_file_name','linked_id','shipment_sequence_number','event_owner_id','stock_transfer_id','type','is_shipped','attempt','transaction_id'
    ];
    public function platformShippingLines()
    {
        return $this->hasMany(PlatformOrderShipmentLine::class, 'platform_order_shipment_id', 'id');
    }
    public function platformShipment()
    {
        return $this->hasOne(self::class,'id','linked_id');
    }
    public function platformOrder()
    {
        return $this->hasOne(PlatformOrder::class,'id','platform_order_id');
    }
    public function platformOrderShipmentStatusReady()
    {
        return $this->hasOne(PlatformOrder::class,'id','platform_order_id')->where('shipment_status','Ready');
    }
    public static function getShipmentsByUserId($userId, $platformId = 0, $syncStatus = '') {
        $query = self::where('user_id', '=', $userId);

        if($platformId != 0) {
            $query->where('platform_id', '=', $platformId);
        }

        if ($syncStatus != '') {
            $query->where('sync_status', '=', $syncStatus);
        }

        return $query->get();
    }
}
