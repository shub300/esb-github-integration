<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organizations extends Model
{
    protected $table = 'es_organizations';

    protected $fillable = [];

    public function style()
    {
        return $this->hasOne('App\Models\PersonalizeOrganization','organization_id');
    }

}
