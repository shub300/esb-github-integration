<?php

namespace App\Models;

use App\Models\PlatformOrder;
use App\Models\PlatformOrderRefundLine;
use Illuminate\Database\Eloquent\Model;

class PlatformOrderRefund extends Model
{
    protected $table = 'platform_order_refunds';

    protected $fillable = [
        'user_workflow_rule_id','refund_order_number','platform_order_id', 'api_id', 'date_created', 'amount', 'linked_id', 'sync_status'
    ];

    public function platformOrder()
    {
        return $this->belongsTo(PlatformOrder::class, 'platform_order_id');
    }

    public function platformOrderRefundLine()
    {
        return $this->hasMany(PlatformOrderRefundLine::class, 'platform_order_refund_id', 'id');
    }
}
