<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCustomFieldValue extends Model
{
    protected $table = 'platform_custom_field_values';

    protected $fillable = [
        'platform_field_id', 'user_integration_id', 'platform_id', 'field_value', 'record_id', 'status'
    ];
}
