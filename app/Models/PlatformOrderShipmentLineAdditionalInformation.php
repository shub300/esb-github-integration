<?php
	namespace App\Models;
	
	use Illuminate\Database\Eloquent\Model;
	
	class PlatformOrderShipmentLineAdditionalInformation extends Model
	{
		protected $table = 'platform_order_shipment_line_additional_information';
		
		public $timestamps = true;
		
		protected $dates = ['created_at', 'updated_at'];
		
		protected $fillable = ['platform_order_shipment_line_id', 'country_of_origin', 'carton_id', 'waybill_number', 'serial_number', 'tca_revision', 'tla_revision', 'pca_revision', 'pgc_date_code'];
	}