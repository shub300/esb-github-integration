<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDataMapping extends Model
{
    protected $table = 'platform_data_mapping';

    protected $fillable = ['mapping_type','data_map_type','platform_workflow_rule_id','source_row_id','destination_row_id','custom_data','user_integration_id','platform_integration_id','platform_object_id','status','created_at','updated_at'];
}
