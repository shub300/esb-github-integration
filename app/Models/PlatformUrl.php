<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformUrl extends Model
{
	public $timestamps = false;

	protected $table = 'platform_urls';

	protected $fillable = ['user_id', 'platform_id', 'user_integration_id', 'url', 'url_name', 'status', 'option_status', 'response', 'url_filter', 'allow_retain'];
}
