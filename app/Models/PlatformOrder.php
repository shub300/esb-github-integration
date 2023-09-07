<?php

namespace App\Models;

use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderRefund;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderRefundLine;
use Illuminate\Database\Eloquent\Model;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformInvoice;

class PlatformOrder extends Model
{
    protected $table = 'platform_order';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];
    protected $fillable = [
        'user_id', 'user_workflow_rule_id', 'platform_id', 'user_integration_id', 'platform_customer_id', 'platform_customer_emp_id', 'trading_partner_id', 'order_type', 'api_order_id', 'api_order_reference', 'customer_email', 'order_number', 'currency', 'order_date', 'tax_date', 'order_status', 'api_order_payment_status', 'due_days', 'department', 'vendor', 'total_discount', 'total_tax', 'total_amount', 'net_amount', 'shipping_total', 'shipping_tax', 'discount_tax', 'payment_date', 'delivery_date', 'shipping_method', 'notes', 'sync_status', 'refund_sync_status', 'is_voided', 'is_fully_synced', 'is_deleted', 'invoice_sync_status', 'linked_id', 'created_at', 'order_updated_at', 'updated_at', 'file_name', 'ship_speed', 'carrier_code', 'warehouse_id', 'order_update_status', 'api_updated_at', 'shipment_status', 'shipment_api_status', 'platform_order_shipment_id', 'api_pricelist_id', 'transaction_sync_status', 'attempt', 'allow_check', 'linked_api_order_id'
    ];

    public function platformCustomer()
    {
        return $this->belongsTo(PlatformCustomer::class, 'platform_customer_id');
    }

    public function platformSupplier()
    {
        return $this->hasOne(PlatformCustomer::class, 'api_customer_code', 'platform_customer_id');
    }

    public function platformOrderAddress()
    {
        return $this->hasMany(PlatformOrderAddress::class, 'platform_order_id', 'id');
    }

    public function linkedOrder()
    {
        return $this->hasOne(PlatformOrder::class, 'id', 'linked_id');
    }

    public function GetGoodsOutNote()
    {
        return $this->hasOne(PlatformOrderShipment::class, 'platform_order_id', 'id');
    }
    public function getShipmentReadyAndFailed()
    {
        return $this->hasOne(PlatformOrderShipment::class, 'platform_order_id', 'id')->whereIn('sync_status', ['Ready', 'Failed']);
    }

    public function platformOrderLine()
    {
        return $this->hasMany(PlatformOrderLine::class, 'platform_order_id', 'id');
    }

    public function platformOrderRefund()
    {
        return $this->hasMany(PlatformOrderRefund::class, 'platform_order_id', 'id');
    }

    public function platformOrderRefundLine()
    {
        return $this->hasManyThrough(PlatformOrderRefundLine::class, PlatformOrderRefund::class, 'platform_order_id', 'platform_order_refund_id', 'id', 'id');
    }

    public function platformOrderTransaction()
    {
        return $this->hasMany(PlatformOrderTransaction::class, 'platform_order_id', 'id');
    }

    public function shipments()
    {
        return $this->hasMany(PlatformOrderShipment::class, 'platform_order_id', 'id');
    }
    public function shipmentsFailedAndReady()
    {
        return $this->hasMany(PlatformOrderShipment::class, 'platform_order_id', 'id')->whereIn('sync_status', ['Ready', 'Failed']);
    }

    public function PlatformOrderAdditionalInformation()
    {
        return $this->hasMany(PlatformOrderAdditionalInformation::class, 'platform_order_id', 'id');
    }
    public function order_extra_information()
    {
        return $this->hasOne(PlatformOrderAdditionalInformation::class);
    }
    public function order_address()
    {
        return $this->hasOne(PlatformOrderAddress::class, 'platform_order_id', 'id');
    }
    public function order_transaction()
    {
        return $this->hasOne(PlatformOrderTransaction::class, 'platform_order_id', 'id');
    }


    public function PlatformInvoice()
    {
        return $this->hasMany(PlatformInvoice::class, 'platform_order_id', 'id');
    }
}
