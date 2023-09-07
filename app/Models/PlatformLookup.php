<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformLookup extends Model
{
    protected $table = 'platform_lookup';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];

    protected $fillable = [
      'platform_id', 'platform_name', 'platform_image', 'auth_endpoint', 'status', 'auth_type'
    ];
}
