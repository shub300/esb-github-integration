<?php
	namespace App\Models;
	
	use Illuminate\Database\Eloquent\Model;
	
	class PlatformOrderShipmentLine extends Model
	{
		protected $table = 'platform_order_shipment_lines';

		public $timestamps = true;
    
		protected $dates=['created_at','updated_at'];
		
		protected $fillable = ['platform_order_shipment_id', 'row_id', 'product_id', 'sku', 'barcode', 'location_id', 'currency', 'price', 'warehouse_id', 'quantity', 'user_batch_reference', 'sync_status'];
	}