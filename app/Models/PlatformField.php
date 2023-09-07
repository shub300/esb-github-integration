<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformField extends Model
{
    protected $table = 'platform_fields';

    protected $fillable = [
        'user_id', 'user_integration_id', 'name', 'description', 'db_field_name', 'platform_id', 'field_type', 'custom_field_type', 'custom_field_id', 'custom_field_option_group_id', 'type', 'status', 'order_val', 'required', 'platform_object_id'
    ];

    public function options() {
        return $this->hasMany(PlatformFieldOptionData::class, 'platform_field_id' , 'id');
    }
}
