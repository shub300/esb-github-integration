<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class KernelUWFLimit extends Model
{
    protected $table = 'kernal_uwf_limit';
    public $timestamps = true;
    protected $dates = ['created_at', 'updated_at'];
    protected $fillable = ['url', 'type', 'max_limit'];
}
