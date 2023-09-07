<?php

namespace App\Models;

use App\Models\PlatformOrder;
use Illuminate\Database\Eloquent\Model;

class PlatformOrderTransaction extends Model
{
    protected $table = 'platform_order_transactions';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];
    protected $fillable = ['platform_id', 'user_integration_id', 'platform_order_id', 'platform_order_refund_id', 'api_transaction_index_id', 'transaction_id', 'transaction_datetime', 'transaction_type', 'transaction_method', 'transaction_amount', 'transaction_approval', 'transaction_reference', 'transaction_gateway_id', 'transaction_cvv2', 'transaction_avs', 'transaction_response_text', 'transaction_response_code', 'transaction_captured', 'memo', 'row_type', 'sync_status', 'success_response', 'created_at', 'updated_at', 'platform_customer_id', 'currency_code', 'exchange_rate', 'bank_account', 'linked_id'];

    public function platformOrder()
    {
        return $this->belongsTo(PlatformOrder::class);
    }
}
