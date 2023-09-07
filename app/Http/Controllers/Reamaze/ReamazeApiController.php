<?php

namespace App\Http\Controllers\Reamaze;

use App\Helper\Logger;
use App\Helper\MainModel;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Helper\Api\ReamazeApi;
use App\Models\PlatformApiApp;
use App\Models\PlatformLookup;
use App\Models\PlatformObject;
use App\Models\PlatformAccount;
use App\Models\UserIntegration;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Session;
use Lang;
class ReamazeApiController extends Controller
{
    private static $platformName = 'reamaze';
    public $platformId, $mobj, $api, $log;
    public $joinArr = [];

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->log=new Logger();
        $this->api = new ReamazeApi();
        $platform = PlatformLookup::where('platform_id', '=', self::$platformName)->first();
        if($platform){
            $this->platformId = $platform->id;
        }
    }

    /* AUTH FUNCTIONS -- START */
    public function InitiateReamazeAuth(Request $request)
    {
        $platform = self::$platformName;
        return view("pages.apiauth.reamaze_auth", compact('platform'));
    }

    public function ConnectReamazeAuth(Request $request)
    {
        $data = [];
        // validation
        $validator = $request->validate([
            'email' => 'required|email',
            'client_key' => 'required',
            'domain' => 'required',
        ],[
            'email.required' => 'Email is required.',
            'client_key.required' => 'Client API is required.',
            'domain.required' => 'Sub domain required.',
        ]);

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }
        
        // extract the username
        $accountname = strstr($request->email, '@', true);
        // encrypt the data
        $email = $this->mobj->encrypt_decrypt($request->email);
        $key = $this->mobj->encrypt_decrypt($request->client_key);
        // current user
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];
        // check for user account
        $checkAccount = PlatformAccount::where([
            'user_id' => $user_id,
            'platform_id' => $this->platformId,
            'account_name' => $accountname,
            'app_id' => $email,
            'api_domain' => $request->domain
        ])->select('id')->first();
        if($checkAccount){
            $data['status_code'] = 1;
            $data['status_text'] = 'Account already in use.';
        }else{
            // add new account
            $newaccount = PlatformAccount::create([
                'user_id' => $user_id,
                'platform_id' => $this->platformId,
                'account_name' => $accountname,
                'app_id' => $email,
                'app_secret' => $key,
                'api_domain' => $request->domain
            ]);
            if(isset($newaccount->id)){
                $data['status_code'] = 1;
                $data['status_text'] = 'Account Added Successfully.';
            }
        }
        return json_encode($data);
    }
    /* AUTH FUNCTION -- END */

    /* SYNCING FUNCTION -- START */
    public function syncCustomers(
        $is_initial_sync, $user_id, $user_integration_id,
        $source_platform_name, $platform_workflow_rule_id,
        $user_workflow_rule_id, $record_id
    )
    {
        $returnstatus = true;
        try{
            // get the account sub domain
            $account = $this->getAccountByUserIntegration($user_integration_id);
            if($account){
                // source platform
                $source_platform = PlatformLookup::where(['platform_id' => $source_platform_name, 'status' => 1])->select('id')->first();
                if($source_platform){
                    $source_platform_id = $source_platform->id;
                    // object id
                    $object = PlatformObject::where(['name' => 'customer', 'status' => 1])->select('id')->first();
                    if($object){
                        $object_id = $object->id;
                        if($record_id){
                            $sync_status = 'Failed';
                        }else{
                            $sync_status = 'Ready';
                        }
                        $limit = 50;
                        $parent_customers = PlatformCustomer::where([
                            'platform_id' => $source_platform_id,
                            'user_integration_id' => $user_integration_id,
                            'sync_status' => $sync_status
                        ]);
                        if($record_id){
                            $parent_customers = $parent_customers->where('id', $record_id);
                        }
                        $parent_customers = $parent_customers->limit($limit)->get();
                        if($parent_customers){
                            $insert = $update = false;
                            foreach($parent_customers as $parent_customer){
                                $data = [
                                    'customer_name' => $parent_customer->customer_name,
                                    'first_name' => $parent_customer->first_name,
                                    'last_name' => $parent_customer->last_name,
                                    'company_name' => $parent_customer->company_name,
                                    'phone' => $parent_customer->phone,
                                    'fax' => $parent_customer->fax,
                                    'email' => $parent_customer->email,
                                    'address1' => $parent_customer->address1,
                                    'address2' => $parent_customer->address2,
                                    'address3' => $parent_customer->address3,
                                    'postal_addresses' => $parent_customer->postal_addresses,
                                    'country' => $parent_customer->country,
                                    'company_id' => $parent_customer->company_id,
                                ];
                                $apidata = [
                                    'contact' => [
                                        'name' => $parent_customer->customer_name,
                                        'data' => [
                                            'first_name' => $parent_customer->first_name,
                                            'last_name' => $parent_customer->last_name,
                                            'fax' => $parent_customer->fax,
                                            'address1' => $parent_customer->address1,
                                            'address2' => $parent_customer->address2,
                                            'address3' => $parent_customer->address3,
                                            'postal_addresses' => $parent_customer->postal_addresses,
                                            'country' => $parent_customer->country,
                                            'company_name' => $parent_customer->company_name,
                                            'phone' => $parent_customer->phone,
                                            'company_id' => $parent_customer->company_id,
                                        ],
                                        'email' => $parent_customer->email,
                                        'friendly_name' => $parent_customer->first_name,
                                    ],
                                ];
                                if($parent_customer->linked_id == 0){
                                    $apiresponse = $this->api->callAPI("/contacts", 'POST', $account, $apidata);
                                    if($apiresponse['status']){
                                        $apiData = $apiresponse['data'];
                                        $insert = true;
                                        $data += [
                                            'user_id' => $user_id,
                                            'platform_id' => $this->platformId,
                                            'user_integration_id' => $user_integration_id,
                                            'linked_id' => $parent_customer->id,
                                            'sync_status' => 'Synced',
                                            'api_created_at' => $apiData['created_at'],
                                            'api_updated_at' => $apiData['updated_at'],
                                        ];
                                    }else{
                                        if(isset($apiresponse['message']) && str_contains('logins.email', strtolower($apiresponse['message']))){
                                            $apiresponse = $this->api->callAPI("/contacts/{$parent_customer->email}", 'PUT', $account, $apidata);
                                            if($apiresponse['status']){
                                                $apiData = $apiresponse['data'];
                                                $insert = true;
                                                $data += [
                                                    'user_id' => $user_id,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $user_integration_id,
                                                    'linked_id' => $parent_customer->id,
                                                    'sync_status' => 'Synced',
                                                    'api_created_at' => $apiData['created_at'],
                                                    'api_updated_at' => $apiData['updated_at'],
                                                ];
                                            }
                                        }
                                    }
                                }else{
                                    $apiresponse = $this->api->callAPI("/contacts/{$parent_customer->email}", 'PUT', $account, $apidata);
                                    if($apiresponse['status']){
                                        $apiData = $apiresponse['data'];
                                        $data += [
                                            'api_updated_at' => strtotime($apiData['updated_at']),
                                        ];
                                    }
                                    $update = true;
                                }
                                $parent_customer->sync_status = 'Synced';
                                $statusForSync = 'success';
                                if($insert && $apiresponse['status']){
                                    $child_customer = PlatformCustomer::create($data);
                                    $parent_customer->linked_id = $child_customer->id;
                                    $message = 'Value Added';
                                }elseif($update && $apiresponse['status']){
                                    $child_customer = PlatformCustomer::find($parent_customer->linked_id)->update($data);
                                    $message = 'Value Updated';
                                }else{
                                    $parent_customer->sync_status = 'Failed';
                                    $statusForSync = 'failed';
                                    $message = $apiresponse['message'];
                                }
                                $parent_customer->save();
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_customer->id, $message);
                            }
                        }else{
                            $returnstatus = 'No data to sync.';
                        }
                    }else{
                        $returnstatus = 'No object found.';
                    }
                }else{
                    $returnstatus = 'No platform found.';
                }
            }else{
                $returnstatus = 'No account found.';
            }
            return $returnstatus;
        }catch(\Exception $e){
            return $e->getMessage();
        }
    }

    public function syncSalesOrderForCustomers(
        $is_initial_sync, $user_id, $user_integration_id,
        $source_platform_name, $platform_workflow_rule_id,
        $user_workflow_rule_id, $record_id
    )
    {
        $returnstatus = true;
        try{
            // get the account sub domain
            $account = $this->getAccountByUserIntegration($user_integration_id);
            if($account){
                // source platform
                $source_platform = PlatformLookup::where(['platform_id' => $source_platform_name, 'status' => 1])->select('id')->first();
                if($source_platform){
                    $source_platform_id = $source_platform->id;
                    // object id
                    $object = PlatformObject::where(['name' => 'sales_order', 'status' => 1])->select('id')->first();
                    if($object){
                        $object_id = $object->id;
                        if($record_id){
                            $sync_status = 'Failed';
                        }else{
                            $sync_status = 'Ready';
                        }
                        $limit = 20;
                        $this->joinArr = [
                            'platform_customer.platform_id' => $this->platformId,
                            'platform_customer.user_id' => $user_id,
                            'platform_customer.user_integration_id' => $user_integration_id,
                            'platform_order.platform_id' => $source_platform_id,
                            'platform_order.user_id' => $user_id,
                            'platform_order.user_integration_id' => $user_integration_id,
                            'platform_order.sync_status' => $sync_status,
                        ];
                        $parent_orders = PlatformOrder::select('platform_order.*', 'platform_customer.email as pcemail', 'platform_customer.id as pcid')
                        ->join('platform_customer', function($join){
                            $join->on('platform_order.platform_customer_id', '=', 'platform_customer.linked_id')
                            ->where($this->joinArr);
                        });

                        if($record_id){
                            $parent_orders = $parent_orders->where('platform_order.id', $record_id);
                        }
                        $parent_orders = $parent_orders->limit($limit)->get();
                        if($parent_orders){
                            foreach($parent_orders as $parent_order){
                                // insert or update order
                                $data = [
                                    'trading_partner_id' => $parent_order->trading_partner_id,
                                    'order_type' => $parent_order->order_type,
                                    'customer_email' => $parent_order->customer_email,
                                    'order_number' => $parent_order->order_number,
                                    'currency' => $parent_order->currency,
                                    'order_date' => $parent_order->order_date,
                                    'order_status' => $parent_order->order_status,
                                    'api_order_payment_status' => $parent_order->api_order_payment_status,
                                    'due_days' => $parent_order->due_days,
                                    'department' => $parent_order->department,
                                    'vendor' => $parent_order->vendor,
                                    'total_discount' => $parent_order->total_discount,
                                    'total_tax' => $parent_order->total_tax,
                                    'total_amount' => $parent_order->total_amount,
                                    'net_amount' => $parent_order->net_amount,
                                    'shipping_total' => $parent_order->shipping_total,
                                    'shipping_tax' => $parent_order->shipping_tax,
                                    'discount_tax' => $parent_order->discount_tax,
                                    'payment_date' => $parent_order->payment_date,
                                    'delivery_date' => $parent_order->delivery_date,
                                    'shipping_method' => $parent_order->shipping_method,
                                    'notes' => $parent_order->notes,
                                    'refund_sync_status' => $parent_order->refund_sync_status,
                                    'is_voided' => $parent_order->is_voided,
                                    'invoice_sync_status' => $parent_order->invoice_sync_status,
                                    'file_name' => $parent_order->file_name,
                                    'ship_speed' => $parent_order->ship_speed,
                                    'carrier_code' => $parent_order->carrier_code,
                                    'warehouse_id' => $parent_order->warehouse_id,
                                    'order_update_status' => $parent_order->order_update_status,
                                    'shipment_status' => $parent_order->shipment_status,
                                    'shipment_api_status' => $parent_order->shipment_api_status,
                                    'platform_order_shipment_id' => $parent_order->platform_order_shipment_id,
                                    'order_updated_at' => date('Y-m-d H:i:s')
                                ];
                                $parent_order->sync_status = 'Synced';
                                $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                $statusForSync = 'success';
                                if($parent_order->linked_id == 0){
                                    $data += [
                                        'user_id' => $user_id,
                                        'user_workflow_rule_id' => $user_workflow_rule_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_customer_id' => $parent_order->pcid,
                                        'linked_id' => $parent_order->id,
                                        'sync_status' => 'Ready',
                                    ];
                                    $child_order = PlatformOrder::create($data);
                                    $parent_order->linked_id = $child_order->id;
                                    $message = 'Value Added';
                                }else{
                                    $child_order = PlatformOrder::where('id', $parent_order->linked_id)->update($data);
                                    $message = 'Value Updated';
                                }
                                $parent_order->save();
                                // get order line -> create/update
                                $salesOrderLines = PlatformOrder::find($parent_order->id)->platformOrderLine;
                                if($salesOrderLines){
                                    foreach($salesOrderLines as $parent_orderline){
                                        $insert = $update = false;
                                        $data = [
                                            'platform_order_id' => $child_order->id,
                                            'api_product_id' => $parent_orderline->api_product_id,
                                            'product_name' => $parent_orderline->product_name,
                                            'item_row_sequence' => $parent_orderline->item_row_sequence,
                                            'ean' => $parent_orderline->ean,
                                            'sku' => $parent_orderline->sku,
                                            'gtin' => $parent_orderline->gtin,
                                            'upc' => $parent_orderline->upc,
                                            'mpn' => $parent_orderline->mpn,
                                            'qty' => $parent_orderline->qty,
                                            'subtotal' => $parent_orderline->subtotal,
                                            'subtotal_tax' => $parent_orderline->subtotal_tax,
                                            'total' => $parent_orderline->total,
                                            'total_tax' => $parent_orderline->total_tax,
                                            'taxes' => $parent_orderline->taxes,
                                            'variation_id' => $parent_orderline->variation_id,
                                            'price' => $parent_orderline->price,
                                            'unit_price' => $parent_orderline->unit_price,
                                            'uom' => $parent_orderline->uom,
                                            'description' => $parent_orderline->description,
                                            'notes' => $parent_orderline->notes,
                                            'api_code' => $parent_orderline->api_code,
                                            'row_type' => $parent_orderline->row_type,
                                        ];
                                        $apidata = [
                                            'body' => "
                                            Product Name: $parent_orderline->product_name
                                            Product SKU: $parent_orderline->sku
                                            Quantity: $parent_orderline->qty
                                            Unit Price: $parent_orderline->unit_price
                                            Total: $parent_orderline->total
                                            ",
                                        ];
                                        if($parent_orderline->linked_id == 0){
                                            $apiresponse = $this->api->callAPI("/contacts/{$parent_order->pcemail}/notes", 'POST', $account, $apidata);
                                            if($apiresponse['status']){
                                                $apiData = $apiresponse['data'];
                                                $insert = true;
                                                $data += [
                                                    'api_code' => $apiData['id'],
                                                    'linked_id' => $parent_orderline->id,
                                                ];
                                            }
                                        }else{
                                            $apiresponse = $this->api->callAPI("/contacts/{$parent_order->email}/notes/{$parent_orderline->api_code}", 'PUT', $account, $apidata);
                                            if($apiresponse['status']){
                                                $apiData = $apiresponse['data'];
                                                $update = true;
                                            }
                                        }
                                        if($insert && $apiresponse['status']){
                                            $child_orderline = PlatformOrderLine::create($data);
                                            $parent_orderline->linked_id = $child_orderline->id;
                                            $parent_orderline->save();
                                        }elseif($update && $apiresponse['status']){
                                            $child_orderline = PlatformOrderLine::find($parent_orderline->linked_id)->update($data);
                                        }else{
                                            $parent_order->sync_status = 'Failed';
                                            $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                            $parent_order->save();
                                            $statusForSync = 'failed';
                                            $message = $apiresponse['message'];
                                        }
                                    }
                                }
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_order->id, $message);
                            }
                        }else{
                            $returnstatus = 'No data to sync.';
                        }
                    }else{
                        $returnstatus = 'No object found.';
                    }
                }else{
                    $returnstatus = 'No platform found.';
                }
            }else{
                $returnstatus = 'No account found.';
            }
            return $returnstatus;
        }catch(\Exception $e){
            return $e->getMessage();
        }
    }

    public function getCustomers($user_id, $user_integration_id) // ONLY FOR UPDATED CUSTOMERS
    {
        $return  = true;
        try{
            $account = $this->getAccountByUserIntegration($user_integration_id);
            if($account){
                $customers = $this->api->getCustomers($account);
                if($customers['status']){
                    foreach($customers['data'] as $customer){
                        $db_customer = PlatformCustomer::where([
                            'email' => $customer['email'],
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                        ])
                        ->where('api_updated_at', '<', $customer['updated_at'])
                        ->first();
                        if($db_customer){
                            $db_customer->customer_name = $customer['name'];
                            $db_customer->first_name = $customer['data']['first_name'];
                            $db_customer->last_name = $customer['data']['last_name'];
                            $db_customer->company_name = $customer['data']['company_name'];
                            if(isset($customer['data']['phone'])){
                                if($db_customer->phone == $customer['data']['phone']){
                                    if(isset($customer['mobile']) && !is_null($customer['mobile'])){
                                        $db_customer->phone = $customer['mobile'];
                                    }else{
                                        $db_customer->phone = $customer['data']['phone'];
                                    }
                                }else{
                                    $db_customer->phone = $customer['data']['phone'];
                                }
                            }else{
                                $db_customer->phone = $customer['mobile'];
                            }
                            $db_customer->fax = $customer['data']['fax'];
                            $db_customer->address1 = $customer['data']['address1'];
                            $db_customer->address2 = $customer['data']['address2'];
                            $db_customer->address3 = $customer['data']['address3'];
                            $db_customer->postal_addresses = $customer['data']['postal_addresses'];
                            $db_customer->country = $customer['data']['country'];
                            $db_customer->company_id = $customer['data']['company_id'];
                            $db_customer->sync_status = 'Ready';
                            $db_customer->api_updated_at = $customer['updated_at'];
                            $db_customer->save();
                        }else{
                            $check_customer = PlatformCustomer::where([
                                'email' => $customer['email'],
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                            ])->first();
                            if(!$check_customer){
                                $newCustomer = new PlatformCustomer();
                                $newCustomer->user_id = $user_id;
                                $newCustomer->platform_id = $this->platformId;
                                $newCustomer->user_integration_id = $user_integration_id;
                                $newCustomer->customer_name = $customer['name'];
                                if(isset($customer['data']['phone'])){
                                    $newCustomer->phone = $customer['data']['phone'];
                                }else{
                                    $newCustomer->phone = $customer['mobile'];
                                }
                                $newCustomer->email = $customer['data']['email'];
                                $newCustomer->sync_status = 'Ready';
                                $newCustomer->api_created_at = $customer['created_at'];
                                $newCustomer->api_updated_at = $customer['updated_at'];
                                $newCustomer->save();
                            }
                        }
                    }
                }
            }
        }catch(\Exception $e){
            return $e->getMessage();
        }
    }
    /* SYNCING FUNCTION -- END */

    /* EXTRA FUNCTIONS -- START */
    public function getAccountByUserIntegration($user_integration_id)
    {
        $userIntegration = UserIntegration::where('id', $user_integration_id)->pluck('selected_sc_account_id','selected_dc_account_id')->toArray();
        if(count($userIntegration)){
            $keys = array_keys($userIntegration);
            $values = array_values($userIntegration);
            $merge = array_merge($keys,$values);
            $result = PlatformAccount::where('platform_id',$this->platformId)->whereIn('id',$merge);
            return $result->first();
        }
        return false;
    }
    /* EXTRA FUNCTIONS -- END */

    /* EXECUTE FUNCTION -- START */
    public function ExecuteRemazeApi($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        $response = true;
        try{
            if($method == 'MUTATE' && $event == 'CUSTOMER'){
                $response = $this->syncCustomers($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            }elseif($method == 'MUTATE' && $event == 'SALESORDER'){
                $response = $this->syncSalesOrderForCustomers($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            }elseif($method == 'GET' && $event == 'CUSTOMER'){
                $response = $this->getCustomers($user_id, $user_integration_id);
            }
            return $response;
        }catch(\Exception $e){
            return $e->getMessage();
        }
    }
    /* EXECUTE FUNCTION -- END */
}