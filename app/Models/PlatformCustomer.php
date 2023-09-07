<?php

namespace App\Models;

use App\Models\PlatformOrder;
use Illuminate\Database\Eloquent\Model;

class PlatformCustomer extends Model
{
    protected $table = 'platform_customer';

    protected $fillable = ['company_name', 'first_name', 'last_name', 'email', 'email2', 'email3', 'phone', 'fax', 'trade_status', 'ebay_username', 'skype', 'job_title', 'contact_owner', 'linked_id', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'sync_status', 'api_customer_id',  'api_customer_code', 'user_id', 'user_integration_id', 'platform_id', 'customer_name', 'api_created_at', 'api_updated_at', 'is_deleted', 'api_customer_group_id', 'type'];

    public function platformOrder()
    {
        return $this->hasMany(PlatformOrder::class, 'platform_customer_id', 'id');
    }

    public function linkedCustomer()
    {
        return $this->belongsTo(PlatformCustomer::class,  'linked_id', 'id');
    }

    public function extraInfo()
    {
        return $this->belongsTo(PlatformCustomerAdditionalInformation::class, 'id', 'platform_customer_id');
    }
}
