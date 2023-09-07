<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformPreProcessData extends Model
{
    protected $table = 'platform_pre_process_data';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];
    protected $fillable = [
      'user_id', 'user_integration_id', 'platform_id', 'module', 'api_id', 'sub_api_id',
    ];
}
