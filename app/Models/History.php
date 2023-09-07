<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class History extends Model
{

    protected $table = 'history';
    protected $fillable = [
        'action', 'action_by', 'user_integration_id', 'old_data','new_data','created_at','updated_at'
    ];

}
