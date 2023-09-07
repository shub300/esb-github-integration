<?php
	namespace App\Models;
	use Illuminate\Database\Eloquent\Model;
	class PlatformInvoiceHistory extends Model
	{
		protected $table = 'platform_invoice_history';

		protected $fillable = ['platform_invoice_id', 'invoice_status', 'notes', 'api_created_at', 'api_updated_at', 'status'];
		public function invoice_detail()
		{
			return $this->hasOne(PlatformInvoice::class, 'platform_invoice_id', 'id');
		}
	}
