<?php
	namespace App\Models;
	
	use Illuminate\Database\Eloquent\Model;
	
	class PlatformWebhookInformation extends Model
	{
		protected $table = 'platform_webhook_info';
		
		protected $fillable = ['user_id', 'platform_id', 'user_integration_id', 'api_id', 'description', 'status'];
	}