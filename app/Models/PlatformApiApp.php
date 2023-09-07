<?php
	namespace App\Models;
	use Illuminate\Database\Eloquent\Model;
	
	class PlatformApiApp extends Model
	{
		protected $table = 'platform_api_app';	
		public $timestamps = true;
		protected $dates = ['created_at', 'updated_at'];
		protected $fillable = [
			'organization_id', 'app_ref', 'platform_id', 'client_id', 'client_secret', 'access_key', 'secret_key', 'role_arn', 'env_type'
		];
	
	}