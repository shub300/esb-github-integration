<?php

namespace App\Models;

use App\Models\PlatformOrder;
use Illuminate\Database\Eloquent\Model;

class PlatformInvoice extends Model
{
    protected $table = 'platform_invoice';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];
    protected $fillable = ['user_id', 'platform_id', 'user_integration_id', 'platform_order_id', 'platform_customer_id', 'trading_partner_id', 'order_doc_number', 'order_state', 'api_invoice_id', 'invoice_code', 'invoice_state', 'customer_name', 'ref_number', 'payment_terms', 'invoice_date', 'gl_posting_date', 'ship_date', 'pay_date', 'total_amt', 'total_paid_amt', 'message', 'api_tax_code', 'currency', 'exchange_rate', 'net_total', 'total_tax', 'ship_via', 'city', 'state', 'zip', 'country', 'tracking_number', 'ship_by_date', 'due_date', 'api_created_at', 'api_updated_at', 'sync_status', 'linked_id', 'is_pre_payment', 'api_customer_code'];

    public function platformOrder()
    {
        return $this->belongsTo(PlatformOrder::class, 'platform_order_id');
    }

    public function platformInvoiceLine()
    {
        return $this->hasMany(PlatformInvoiceLine::class, 'platform_invoice_id', 'id');
    }

    public function platformCustomer()
    {
        return $this->belongsTo(PlatformCustomer::class, 'platform_customer_id');
    }
}
