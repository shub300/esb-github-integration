<?php

namespace App\Models;

use App\Models\PlatformObject;
use Illuminate\Database\Eloquent\Model;

class PlatformObjectData extends Model
{
    protected $table = 'platform_object_data';
    public $timestamps = true;
    protected $dates=['created_at','updated_at'];
    protected $fillable = [
        'user_id', 'user_integration_id', 'platform_id', 'platform_object_id', 'api_id', 'name', 'api_code', 'description','parent_id', 'status', 'other_code'
    ];

    public function platformObject()
    {
        return $this->belongsTo(PlatformObject::class, 'platform_object_id');
    }
    public function platformObjectExtraInformation()
    {
        return $this->belongsTo(PlatformObjectDataAdditionalInformation::class, 'platform_object_data_id');
    }
    public function getPlatformObjectExtraInformation()
    {
        return $this->belongsTo(PlatformObjectDataAdditionalInformation::class, 'id','platform_object_data_id');
    }
}
