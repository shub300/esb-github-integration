<?php
	namespace App\Models;
	use Illuminate\Database\Eloquent\Model;
	class PlatformAccount extends Model
	{
		protected $table = 'platform_accounts';
		
		protected $fillable = ['user_id', 'platform_id', 'account_name', 'app_id', 'app_secret', 'status', 'refresh_token', 'access_token', 'env_type', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'installation_instance_id', 'api_domain', 'custom_domain', 'token_type', 'expires_in', 'token_refresh_time'];
		public function account_info()
		{
			return $this->hasOne(PlatformAccountAdditionalInfo::class, 'account_id');
		}
	}