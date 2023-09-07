<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformEvent extends Model
{
    protected $table = 'platform_events';

    protected $fillable = [
        'platform_id', 'event_description', 'event_id', 'event_name', 'status', 'run_in_min', 'run_in_min_custom', 'linked_table', 'linked_status_column'
    ];
}
