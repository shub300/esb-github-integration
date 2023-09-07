<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCountry extends Model
{
    public $timestamps = true;

    protected $table = 'es_country_codes';

    protected $fillable = [];
}
