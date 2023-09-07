<?php

namespace App\Models;

use App\Models\PlatformObjectData;
use Illuminate\Database\Eloquent\Model;

class PlatformObject extends Model
{
    public $timestamps = false;
    
    protected $table = 'platform_objects';

    protected $fillable = [
        'name','description','display_name','object_type'
    ];

    public function platformObjectData()
    {
        return $this->hasOne(PlatformObjectData::class, 'platform_object_id', 'id');
    }
}
