<?php

namespace App\Http\Controllers\MarketTime;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Logger;
use App\Helper\Api\MarketTimeApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\WorkflowController;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderShipment;
use Lang;
use Illuminate\Support\Facades\Session;

class MarketTimeApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $my_platform_name = 'markettime';
    //public static $my_platform_id='';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->markettime = new MarketTimeApi();
        $this->log = new Logger();
        $this->mapping = new FieldMappingHelper();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->my_platform_id = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform_name);
    }

    public function InitiateMarketTimeAuth(Request $request)
    {
        $platform = self::$my_platform_name;
        return view("pages.apiauth.auth_markettime", compact('platform'));
    }

    public function ConnectMarkettimeAuth(Request $request)
    {
        //server validation
        $validated = $request->validate([
            'markettime_companyID' => 'required',
            'markettime_api_key' => 'required',
            'account_name' => 'required',
        ]);

        $account_name = trim($request->account_name);
        $markettime_companyID = trim($request->markettime_companyID);
        $markettime_api_key = trim($request->markettime_api_key);

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $data = [];

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }
        
        try {
            $existing_MarketTime = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $account_name, 'platform_id' => $this->my_platform_id], ['id']);
            $flag = true;
            if (!$existing_MarketTime) {


                //  if (isset($MarketTime_result['TenantToken']) && $MarketTime_result['TenantToken'] != null) {

                $enc_access_token = $this->mobj->encrypt_decrypt($markettime_api_key, $action = 'encrypt');
                $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['access_token' => $enc_access_token, 'platform_id' => $this->my_platform_id], ['user_id']);
                if ($obj_existing) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'Given details are already in use, Try with other details.';
                    return json_encode($data);
                }

                // store/update MarketTime token
                $MarketTime_tokens = array(
                    'user_id' => $user_id,
                    'platform_id' => $this->my_platform_id,
                    'account_name' => $account_name,
                    'app_id' => $markettime_companyID,
                    'access_token' => $this->mobj->encrypt_decrypt($markettime_api_key, $action = 'encrypt'),
                    'env_type' => 'production'
                );

                DB::table('platform_accounts')->insert($MarketTime_tokens);
                // } else {
                //     $flag = false;
                //     $data['status_code'] = 0;
                //     $data['status_text'] = 'Sign-in information is incorrect';
                // }
            } else {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = 'Account name identifier is already exist with the same user, Try with another name.';
            }

            if ($flag) {
                $data['status_code'] = 1;
                $data['status_text'] = 'Account connected successfully.';
            }
            return json_encode($data);
        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }



    public function ExecuteEventMarketTime($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        $response = true;
        ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
        if ($method == 'GET' && $event == 'PURCHASEORDER') {
            $response = $this->MarketTimeGetOrders($user_id, $user_integration_id);
        } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
            $response = $this->MarketTimeShipOrders($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
        }

        return $response;
    }

    public function MarketTimeShipOrders($user_id = 0, $user_integration_id = 0, $source_platform_id = 0, $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
    {


        $return_data = true;
        $process_limit = 100;
        try {

            $destination_platform_id = $this->ConnectionHelper->getPlatformIdByName('markettime');
            $object_id = $this->ConnectionHelper->getObjectId('sales_order_shipment');

            $destination_platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $destination_platform_id, ['id', 'app_id', 'access_token']);
            if ($destination_platform_account) {

                do {
                    $allow_next_call = false;

                    $platform_order_shipments = PlatformOrderShipment::where(['sync_status' => 'Ready', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id])
                        ->where(function ($query) use ($record_id) {
                            if ($record_id > 0) {
                                $query->where('platform_order_id', $record_id)->orWhere('id', $record_id);
                            }
                        })
                        ->select('shipment_id', 'sync_status', 'platform_order_id', 'order_id', 'warehouse_id', 'tracking_info', 'shipping_method')
                        ->limit($process_limit)
                        ->orderBy('id', 'asc')
                        ->get();

                    //    echo "<pre>"; print_r($platform_order_shipments); //exit;



                    if (count($platform_order_shipments) == $process_limit) {
                        //want to loop contineously
                        $allow_next_call = true;
                    }

                    if (count($platform_order_shipments) > 0) {
                        foreach ($platform_order_shipments as $platform_order_shipment) {
                            $destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'linked_id' => $platform_order_shipment->platform_order_id], ['id', 'api_order_id', 'shipment_status', 'order_number']);
                            echo "<pre>";
                            print_r($destination_platform_order);
                            if ($destination_platform_order) {


                           //     $ShipmentData = array('sentTrackingNumber' => $platform_order_shipment->tracking_info, 'shippingMethodForTracking' => $platform_order_shipment->shipping_method, 'trackinglink' => 1);
                                $ShipmentData = array('status' => 'Shipped'); 

                                echo $response = $this->markettime->CreateOrderShipment($user_id, $destination_platform_order->api_order_id, $ShipmentData);
                                echo 1234; // exit;
                                $results = json_decode($response, true);
                                if (isset($results['status'])) {
                                    if ($results['status'] == 200) {

                                        $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Synced'], ['id' => $platform_order_shipment->id, 'sync_status' => 'Ready']);

                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $destination_platform_order->id, 'Shipment synced successfully!');
 

                                        $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $destination_platform_order->id]);
                                        $curl_put_data1 = array('OrderStatusID' => 4);

                                        $request_data_json1 = json_encode($curl_put_data1);

                                        $this->markettime->UpdateOrderByOrderID($this->mobj->decryptString($destination_platform_account->access_token), $destination_platform_order->api_order_id, $request_data_json1);
                                    } else {
                                        $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id, 'sync_status' => 'Ready']);

                                        $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $platform_order_shipment->id, $response['error']);
                                    }
                                }
                            }
                        }
                    }
                } while ($allow_next_call);
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;
    }


    public function MarketTimeGetOrders($user_id, $user_integration_id)
    {
        // date_default_timezone_set('US/Eastern');
        // if(date('h')==12) 
		// { 
        $orders_res = $this->markettime->GetOrders($user_id);
        $orders = json_decode($orders_res, true);
      //  echo "<pre>";
      //  print_r($orders); //exit;

        if (isset($orders['response'])) {

            if (!empty($orders['response'])) {

                foreach ($orders['response'] as $ord) {
                   // echo "<pre>";
                  //  print_r($ord); //exit;
                    //customer data
               if (isset($ord['repGroupRetailerManufacturers']['accountNumber']) && !empty($ord['repGroupRetailerManufacturers']['accountNumber'])) 
                  {
                    $customer_email = $api_customer_id= NULL;  $customer_fname = $customer_lname = '';
                    if (isset($ord['retailerContact']['email'])) {
                        $customer_email = $ord['retailerContact']['email'];
                        $customer_fname = $ord['retailerContact']['firstName'];
                        $customer_lname = $ord['retailerContact']['lastName'];
                    }
                    if (isset($ord['repGroupRetailerManufacturers']['accountNumber'])) {
                     $api_customer_id=$ord['repGroupRetailerManufacturers']['accountNumber'];
                    } 
                    $arr_customer = array();
                    $arr_customer['user_id'] = $user_id;
                    $arr_customer['platform_id'] = $this->my_platform_id;
                    $arr_customer['user_integration_id'] = $user_integration_id;
                    $arr_customer['type'] = 'Customer';
                    $arr_customer['api_customer_id'] = $api_customer_id;
                    $arr_customer['email'] = $customer_email;
                    $arr_customer['first_name'] = $customer_fname;
                    $arr_customer['last_name'] = $customer_lname;  
                    $arr_customer['customer_name'] = $customer_fname . ' ' . $customer_lname;

                    $customer_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'type' => 'Customer', 'api_customer_id' => $api_customer_id], ['id']);
                    if ($customer_details) {
                        $platform_customer_id = $customer_details->id;
                        $this->mobj->makeUpdate('platform_customer', $arr_customer, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'type' => 'Customer', 'api_customer_id' => $api_customer_id]);
                    } else {
                        $arr_customer['sync_status'] = 'Ready';
                        $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                    }
                    //echo $platform_customer_id; //exit;

                    //customer Sales Person data
                    $platform_emp_customer_id =  NULL;   
                    if (isset($ord['salesperson1']['name']) && !empty($ord['salesperson1']['name'])) {
                        $emp_customer_email = $ord['salesperson1']['email'];
                        $emp_customer_fname = $ord['salesperson1']['name'];
                    $arr_emp_customer = array();
                    $arr_emp_customer['user_id'] = $user_id;
                    $arr_emp_customer['platform_id'] = $this->my_platform_id;
                    $arr_emp_customer['user_integration_id'] = $user_integration_id;
                    $arr_emp_customer['type'] = 'Employee';
                    $arr_emp_customer['email'] = $emp_customer_email;
                    $arr_emp_customer['first_name'] = $emp_customer_fname;
                    $arr_emp_customer['last_name'] = NULL;  
                    $arr_emp_customer['customer_name'] = $emp_customer_fname;

                    $emp_customer_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => $emp_customer_email, 'type' => 'Employee'], ['id']);
                    if ($emp_customer_details) {
                        $platform_emp_customer_id = $emp_customer_details->id;
                        $this->mobj->makeUpdate('platform_customer', $arr_emp_customer, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => $emp_customer_email, 'type' => 'Employee']);
                    } else {
                        $arr_emp_customer['sync_status'] = 'Ready';
                        $platform_emp_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_emp_customer);
                    }
                   }
 
                    //orders data
                    $delivery_date=NULL; 
                    if(isset($ord['shipDate']) && !empty($ord['shipDate'])) {
                        $delivery_date = date('Y-m-d', strtotime($ord['shipDate']));
                    } 

                    $arr_order = array();
                    $arr_order['user_id'] = $user_id;
                    $arr_order['platform_id'] = $this->my_platform_id;
                    $arr_order['platform_customer_id'] = $platform_customer_id;
                    $arr_order['user_integration_id'] = $user_integration_id;
                    $arr_order['order_type'] = "PO";
                    
//                    $arr_order['api_order_id'] = @$ord['externalID']; 
                    $arr_order['api_order_id'] = @$ord['pONumber'];
                    $arr_order['order_number'] = @$ord['pONumber'];
                    $arr_order['order_date'] = date('Y-m-d', strtotime($ord['orderDate']));
                    $arr_order['delivery_date'] = $delivery_date;
                    $arr_order['platform_customer_emp_id'] = $platform_emp_customer_id;
                    $arr_order['shipping_method'] = @$ord['shippingMethod'];

                    $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'order_number' => @$ord['pONumber']], ['id']);

                    if ($order_details) {
                        $platform_order_id = $order_details->id;
                        $this->mobj->makeUpdate('platform_order', $arr_order, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'order_number' => @$ord['pONumber']]);
                    } else {
                        $arr_order['sync_status'] = 'Ready';
                        $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                    }
                   // echo $platform_order_id; //exit;
                    $order_total = 0;
                    //store order items
                    foreach (@$ord['orderDetails'] as $lineitem) {
                        $arr_order_line = array();
                        $arr_order_line['platform_order_id'] = $platform_order_id;
                        $arr_order_line['api_product_id'] = @$lineitem['itemID'];
                        $arr_order_line['sku'] = @$lineitem['itemNumber'];
                        $arr_order_line['upc'] = @$lineitem['upc'];
                        $arr_order_line['product_name'] = @$lineitem['name'];
                        $arr_order_line['qty'] = @$lineitem['quantity'] ? @$lineitem['quantity'] : 0;
                        $arr_order_line['price'] = @$lineitem['unitPrice'] ? @$lineitem['unitPrice'] : 0;
                        $arr_order_line['total'] = @$lineitem['unitPrice'] ? @$lineitem['unitPrice']*$lineitem['quantity'] : 0;
                        $arr_order_line['subtotal'] = @$lineitem['unitPrice'] ? @$lineitem['unitPrice']*$lineitem['quantity'] : 0;
 
                        $order_total += floatval($arr_order_line['total']);

                        $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku']]);

                        if ($ct_order_line > 0) {
                            $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku']]);
                        } else {
                            $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                        }
                    }

                    //update total amount into order table
                    $ordtotal['total_amount'] =  $order_total;
                    $this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);


                    //shipping address
                    $shipToCountry="US";
                    if(isset($ord['shipToCountry']) && !empty($ord['shipToCountry'])) { $shipToCountry=$ord['shipToCountry'];}
                    else if(isset($ord['billToCountry']) && !empty($ord['billToCountry'])) { $shipToCountry=$ord['billToCountry'];}
                     
                    $ship_first_name=$ship_last_name='';
                    if(isset($ord['shipToName'])) {
                        $exp_name=explode(" ",$ord['shipToName']);
                        if(isset($exp_name[0])) { $ship_first_name=$exp_name[0]; }
                        if(isset($exp_name[1])) { $ship_last_name=$exp_name[1]; }
                    }

                    $arr_order_address = array();
                    $arr_order_address['platform_order_id'] = $platform_order_id;
                    $arr_order_address['address_type'] = 'Shipping';
                    $arr_order_address['address_name'] = @$ord['shipToName'];
                    $arr_order_address['firstname'] = $ship_first_name;
                    $arr_order_address['lastname'] = $ship_last_name;
                    $arr_order_address['address1'] = @$ord['shipToAddress1'];
                    $arr_order_address['address2'] = @$ord['shipToAddress2'];
                    $arr_order_address['city'] = @$ord['shipToCity'];
                    $arr_order_address['state'] = @$ord['shipToState'];
                    $arr_order_address['postal_code'] = @$ord['shipToZip'];
                    $arr_order_address['country'] = $shipToCountry;
                    $arr_order_address['phone_number'] = @$ord['shipToPhoneNumber'];
                    $arr_order_address['email'] = $customer_email;
                    


                    $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);

                    if ($ct_address > 0) {
                        $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
                    } else {
                        $this->mobj->makeInsert('platform_order_address', $arr_order_address);
                    }

                    //billing address
                    $billToCountry="US";
                    if(isset($ord['billToCountry']) && !empty($ord['billToCountry'])) { $billToCountry=$ord['billToCountry'];}
                    else if(isset($ord['shipToCountry']) && !empty($ord['shipToCountry'])) { $billToCountry=$ord['shipToCountry'];}
                   
                    $bill_first_name=$bill_last_name='';
                    if(isset($ord['billToName'])) {
                        $exp_name=explode(" ",$ord['billToName']);
                        if(isset($exp_name[0])) { $bill_first_name=$exp_name[0]; }
                        if(isset($exp_name[1])) { $bill_last_name=$exp_name[1]; }
                    }

                    $arr_order_bill_address = array();
                    $arr_order_bill_address['platform_order_id'] = $platform_order_id;
                    $arr_order_bill_address['address_type'] = 'Billing';
                    $arr_order_bill_address['address_name'] = @$ord['billToName'];
                    $arr_order_bill_address['firstname'] = $bill_first_name;
                    $arr_order_bill_address['lastname'] = $bill_last_name;
                    $arr_order_bill_address['address1'] = @$ord['billToAddress1'];
                    $arr_order_bill_address['address2'] = @$ord['billToAddress2'];
                    $arr_order_bill_address['city'] = @$ord['billToCity'];
                    $arr_order_bill_address['state'] = @$ord['billToState'];
                    $arr_order_bill_address['postal_code'] = @$ord['billToZip'];
                    $arr_order_bill_address['country'] = $billToCountry;
                    $arr_order_bill_address['phone_number'] = @$ord['billToPhoneNumber'];
                    $arr_order_bill_address['email'] = $customer_email;

                    $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);

                    if ($bill_ct_address > 0) {
                        $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
                    } else {
                        $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
                    }
                }
             }
            } else {
            }
         }
	//	}
        return true;
    }


    public function TestMarketTime()
    {
        $dd='aEJVbUVFbXhQWXhEWk5QKzNFamVWeTJBK2YxbjBXMzdRZ0FMZDZOWGpCaGIrRXNvcldTMnkycjVqM2pEN1JEeA==';

     echo   $this->mobj->encrypt_decrypt($dd, 'decrypt');
        exit;
        $user_id = 97;
        $user_integration_id = 123;
        $source_platform_id = 1;
      //  $this->MarketTimeShipOrders($user_id, $user_integration_id, $source_platform_id);
        $this->MarketTimeGetOrders($user_id, $user_integration_id);
    }
}