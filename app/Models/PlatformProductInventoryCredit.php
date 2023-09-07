<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformProductInventoryCredit extends Model
{
    protected $table = 'platform_product_inventory_credits';
    public $timestamps = true;
    protected $fillable = ['user_workflow_rule_id','platform_inventory_id', 'platform_refund_order_id', 'quantity', 'sync_status'];
    protected $dates=['created_at','updated_at'];

    
}
