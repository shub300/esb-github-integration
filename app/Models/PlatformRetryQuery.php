<?php

namespace App\models;

use Illuminate\Database\Eloquent\Model;

class PlatformRetryQuery extends Model
{
  protected $table = 'platform_retry_queries';
  public $timestamps = true;
  protected $dates = ['created_at', 'updated_at'];
  protected $fillable = [
    'user_integration_id', 'platform_id', 'record_id', 'table_name', 'flow_type', 'record_info', 'status'
  ];
}
