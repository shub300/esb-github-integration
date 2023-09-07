<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PlatformReceiveWebhook extends Model
{
    protected $table = 'platform_receive_webhooks';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];
    protected $fillable = [
        'user_id', 'user_integration_id', 'platform_id', 'webhook_data', 'type', 'status'];


}
