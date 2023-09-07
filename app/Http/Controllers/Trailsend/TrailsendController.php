<?php

namespace App\Http\Controllers\Trailsend;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\TrailsendApi;
use App\Helper\ConnectionHelper;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use Lang;

class TrailsendController extends Controller
{
   /**
    * Create a new controller instance.
    *
    * @return void
    */
   public function __construct()
   {
      $this->mobj = new MainModel();
      $this->trailsend_api = new TrailsendApi();
      $this->log = new Logger();
      $this->helper = new ConnectionHelper();
      $this->platform = 'trailsend';
      $this->platformId = $this->helper->getPlatformIdByName($this->platform);
      $this->map=new FieldMappingHelper();
      
   }
   public function InitiateTrailsendAuth(Request $request)
   {
        $platform = $this->platform;
        return view("pages.apiauth.trailsend_auth", compact('platform'));
   }
   public function connectTrailsendAuth(Request $request)
   {
      $validated = $request->validate([
         'customer_number' => 'required',
         'secret' => 'required'
      ]);

      $account_name = trim($request->customer_number);
      $api_key = trim($request->secret);
      
      $env_type = trim($request->env_type);
      if ($env_type == 'on') { // checke account type .
         $env_type = 'production';
      } else {
            $env_type = 'sandbox';
      }

      $user_data =  Session::get('user_data');
      $user_id =  $user_data['id'];
      $data = [];

      if($this->mobj->checkHtmlTags( $request->all() ) ){
        $data['status_code'] = 0;
        $data['status_text'] = Lang::get('tags.validate');
        return json_encode($data);
      }

      try {
         
               $existing_trailsend = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $account_name,'platform_id' => $this->platformId], ['id']);


               $flag = true;
               if (!$existing_trailsend) {

                     //test auth hash by api call  
                     $response = $this->ValidateCredential($api_key,$env_type);

                     if($response=="true")
                     {
                        //insert trailsend account details
                        $trailsend_tokens = array(
                              'user_id' => $user_id,
                              'platform_id' => $this->platformId,
                              'account_name' => $account_name,
                              'access_token' => $this->mobj->encrypt_decrypt($api_key, $action = 'encrypt'),
                              'env_type' => $env_type
                        );
                        DB::table('platform_accounts')->insert($trailsend_tokens);
                     }
                     else
                     {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Sign-in information is incorrect';
                        return json_encode($data);
                     }
                    
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

   //Test Api call  to check credentials are valid
   public function ValidateCredential($api_key,$env_type)
   {
      //test api
      $apiEndpoint = 'fulfillment/staged';
      $apiResp = $this->trailsend_api->ApiCall($api_key,$apiEndpoint, NULL, 'GET',$env_type);
      if($apiResp){
         if( isset($apiResp['Errored']) && isset($apiResp['Errored']) == 'false' ) 
         {
            $status = true;
         } else {
            $status = false;
         }
         
      } else {
         $status = false;
      }
      
      return $status;
      
   }


   //get orders send acknowledment is temporary commented
   public function GetSalesOrder($userId=NULL, $user_integration_id=NULL, $user_workflow_rule_id)
   {
        $return_response = true;
        try {

            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'account_name','env_type']);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                  $account_name = $ufound->account_name;
                  $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                  $apiEndpoint = "fulfillment/staged";
                  $env_type = $ufound->env_type;

                  //call order api
                  $saleOrders = $this->trailsend_api->ApiCall($authHash, $apiEndpoint,null,'GET',$env_type);
        
                  \Storage::disk('local')->append('trailsend_get_orders.txt', 'Get orders  call in trilsend'.' time: ' . date('Y-m-d H:i:s') .json_encode($saleOrders) 
                  .PHP_EOL);

                  if ( isset($saleOrders['Errored']) &&  $saleOrders['Errored']==false) {
                     
                        if( isset($saleOrders['Data']) & count($saleOrders['Data']) > 0 )
                        {
                           foreach ($saleOrders['Data'] as $key => $value) {

                              $Consumer_Order_Id = isset($value['Consumer_Order_Id']) ? $value['Consumer_Order_Id'] : 0;

                              $platform_order_id = $this->insertUpdateOrders($userId,$user_integration_id,$this->platformId,$value,$user_workflow_rule_id);
                              if($platform_order_id){
                                
                                 //send acknowledgement
                                 $ack_status = $this->sendAcknowledgement($authHash, $userId, $user_integration_id, $Consumer_Order_Id, $env_type);
                                 if($ack_status){
                                    \Storage::disk('local')->append('trailsend_get_orders.txt', 'Order recieved & Acknowleged platform_order_id-'. $platform_order_id. ' time-' .date('Y-m-d H:i:s') 
                                    .PHP_EOL);
                                 }

                              }
                           }

                        }

                  } else {

                    if (isset($saleOrders['Errored'])){

                        if ( isset($saleOrders['Data']['Status_Code']) && $saleOrders['Data']['Status_Code'] == 'failure' ) {
                            $return_response = $saleOrders['Data']['Status_Code'].'_'.$saleOrders['Data']['Status_Message'];
                        } else {
                            $return_response = "API Error - No application exists or check api endpoint";
                        }

                    } else {
                        $return_response = "API Error - No application exists or check api endpoint";
                    }
                    
         
                        
                  }


               }
            }
                

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;

   }
   //send acknowledgement for stored orders
   public function sendAcknowledgement($authHash, $user_id, $user_integration_id, $Consumer_Order_Id,$env_type)
   {
      $apiEndpoint = 'fulfillment/receive/'.$Consumer_Order_Id;

      $ackStatus = $this->trailsend_api->ApiCall($authHash, $apiEndpoint, null, 'PUT',$env_type);

      if($ackStatus){
         if( isset($ackStatus['Status_Code']) && $ackStatus['Status_Code'] == 'success' ){
            $status = true;
         } else if( isset($ackStatus['Errored']) && $ackStatus['Errored'] == 'true' ){
            $status = false;
         } 
      } else {
         $status = false;
      }

      return $status;

   }

   //insert or update order details
   public function insertUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $user_workflow_rule_id)
   {
        //customer data
        $platform_customer_id = "";
        $cust_address = $ord['Address'];
        if($cust_address){
            $arr_customer = array();
            $arr_customer['type'] = 'Customer';
            $arr_customer['user_id'] = $user_id;
            $arr_customer['platform_id'] = $this->platformId;
            $arr_customer['user_integration_id'] = $user_integration_id;
            $arr_customer['email'] = @$cust_address['Email'];
            $arr_customer['customer_name'] = $cust_address['Full_Name'];
            $arr_customer['first_name'] = $cust_address['Full_Name'];
            $arr_customer['address1'] = $cust_address['Line1_Address'];
            $arr_customer['address2'] = $cust_address['Line2_Address'];
            $arr_customer['address3'] = $cust_address['City'];
            $arr_customer['country'] = $cust_address['Country_Code'];
            $arr_customer['postal_addresses'] = $cust_address['Postal_Code'];
            $arr_customer['phone'] = $cust_address['Phone_Number'];
            $arr_customer['sync_status'] = 'Ready';

            //insert or update in platform customer
            $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer', [
                'platform_id' => $this->platformId, 'email' => $arr_customer['email'], 
                'user_integration_id' => $user_integration_id,
            ], ['id']);

            if (!empty($findCustomer->id)) {
                $platform_customer_id = $findCustomer->id;
                $this->mobj->makeUpdate('platform_customer', $arr_customer, [
                    'id' => $platform_customer_id
                ]);
            } else {
               $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
            }
        } 



        //orders data
        $arr_order = array();

        $arr_order['user_id'] = $user_id;
        $arr_order['platform_id'] = $this->platformId;
        if($platform_customer_id){
            $arr_order['platform_customer_id'] = $platform_customer_id;
            $arr_order['customer_email'] = @$cust_address['Email'];;
        }
        $arr_order['user_integration_id'] = $user_integration_id;
        $arr_order['user_workflow_rule_id'] = $user_workflow_rule_id;
        $arr_order['order_type'] = "SO";

        //Consumer_Order_Id as api_order_id used for acknowlegement
        $arr_order['api_order_id'] = @$ord['Consumer_Order_Id'];

         //$arr_order['api_order_payment_status'] = (@$ord['fulfillmentStatus']=='FULFILLED')? 'paid' : 'unpaid';
        $arr_order['order_number'] = @$ord['External_Order_Number'];
        //expected ship date is stored as order date
        $arr_order['order_date'] = date('Y-m-d H:i:s', strtotime($ord['Expected_Ship_Date']));

        $arr_order['order_status'] = 'Default';


        $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_number' => @$ord['External_Order_Number']], ['id','sync_status']);

        if ($order_details) {

            $platform_order_id = $order_details->id;

            if($order_details->sync_status !='Synced'){
                $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
            }
            
        } else {
            $arr_order['sync_status'] = 'Ready';
            $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
        }



        // $order_shipping_method = NULL;
        //store order items
        foreach (@$ord['Line_Items'] as $lineitem) {

            $arr_order_line = array();
            $arr_order_line['platform_order_id'] = $platform_order_id;
            $arr_order_line['row_type'] = 'ITEM';
            //Order_Line_Item_Id as api_product_id
            $arr_order_line['api_order_line_id'] = @$lineitem['Order_Line_Item_Id'];
            $arr_order_line['api_product_id'] = @$lineitem['Order_Line_Item_Id'];
            $arr_order_line['product_name'] = @$lineitem['Full_Name'];
            // $arr_order_line['api_order_line_id'] = @$lineitem['id'];
            // $arr_order_line['variation_id'] = isset($lineitem['variantId']) ? $lineitem['variantId'] : 0;
            $arr_order_line['sku'] = @$lineitem['Sku'];
            $arr_order_line['qty'] = @$lineitem['Line_Item_Quantity'];
            $arr_order_line['unit_price'] = @$lineitem['Unit_Price'];
            $arr_order_line['subtotal'] = $arr_order_line['unit_price'] * $arr_order_line['qty'];
            $arr_order_line['total'] = $arr_order_line['subtotal'];

            $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku'], 'row_type' => 'ITEM']);

            if ($ct_order_line > 0) {
                $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku'], 'row_type' => 'ITEM']);
            } else {
               $this->mobj->makeInsert('platform_order_line', $arr_order_line);
            }
        }

     
        
        //get total order
        $totalOrderAmount = DB::table('platform_order_line')->where(['platform_order_id' => $platform_order_id, 'row_type' => 'ITEM'])
        ->sum('total');
      
        $ordtotal['total_amount'] =  $totalOrderAmount;
        $ordtotal['net_amount'] =  $totalOrderAmount;
        $this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);

        

        //if shipping address exist then store this otherwise store billing address
        $address = $ord['Address'];
        //if address true
        if($address){
            $arr_order_address = array();
            $arr_order_address['platform_order_id'] = $platform_order_id;
            $arr_order_address['address_type'] = 'Shipping';
            $arr_order_address['firstname'] = @$address['Full_Name'];
            $arr_order_address['address_name'] = @$address['Full_Name'];
            $arr_order_address['address1'] = @$address['Line1_Address'];
            $arr_order_address['address2'] = @$address['Line2_Address'];
            $arr_order_address['address3'] = @$address['Line3_Address'];
            $arr_order_address['city'] = @$address['City'];
            $arr_order_address['state'] = @$address['State_Provence'];
            $arr_order_address['postal_code'] = @$address['Postal_Code'];
            $arr_order_address['country'] = @$address['Country_Code'];
            $arr_order_address['phone_number'] = @$address['Phone_Number'];
            // $arr_order_address['ship_speed'] = @$ord['fulfillments'][0]['service'];
            // $arr_order_address['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
            $arr_order_address['email'] = $address['Email'];

            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);

            if ($ct_address > 0) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
            } else {
               $this->mobj->makeInsert('platform_order_address', $arr_order_address);
            }
        }
        

        //if billing address exist then store this otherwise store shipping address
        $billaddress = $ord['Address'];
        //if billing address true
        if($billaddress){
            $arr_order_bill_address = array();
            $arr_order_bill_address['platform_order_id'] = $platform_order_id;
            $arr_order_bill_address['address_type'] = 'Billing';

            $arr_order_bill_address['firstname'] = @$billaddress['Full_Name'];
            $arr_order_bill_address['address_name'] = @$billaddress['Full_Name'];
            $arr_order_bill_address['address1'] = @$billaddress['Line1_Address'];
            $arr_order_bill_address['address2'] = @$billaddress['Line2_Address'];
            $arr_order_bill_address['address3'] = @$billaddress['Line3_Address'];
            $arr_order_bill_address['city'] = @$billaddress['City'];
            $arr_order_bill_address['state'] = @$billaddress['State_Provence'];
            $arr_order_bill_address['postal_code'] = @$billaddress['Postal_Code'];
            $arr_order_bill_address['country'] = @$billaddress['Country_Code'];
            $arr_order_bill_address['phone_number'] = @$billaddress['Phone_Number'];
            // $arr_order_bill_address['ship_speed'] = @$ord['fulfillments'][0]['service'];
            // $arr_order_bill_address['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
            $arr_order_bill_address['email'] = $billaddress['Email'];


            $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);

            if ($bill_ct_address > 0) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
            } else {
               $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
            }
        }
        

        return $platform_order_id;

   }


    // Shipment push to trailsend new with partial shipment logic
   public function createShipment($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SorucePlatformName = NULL, $sync_status = 'Ready',$RecordID=NULL)
   {
       try {
        
           $return_response = false;
           $ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['platform_id','access_token', 'env_type']);

           if ($ufound) {

               $object_id = $this->helper->getObjectId('sales_order_shipment');
               $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
               $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
               $apiEndpoint = "fulfillment/complete";
               $env_type = $ufound->env_type;


               $query = DB::table('platform_order_shipments as s')
               ->select('s.tracking_info', 's.shipping_method', 's.realease_date',  's.tracking_url', 's.shipment_status', 'o.id as order_primary_id', 's.id','a.vendor','a.id as trailsend_order_id','a.api_order_id', 'a.order_number','s.created_on','s.shipment_id','s.attempt','o.shipment_api_status','o.linked_id')
                ->join('platform_order as o', 'o.id', '=', 's.platform_order_id')   
                ->join('platform_order as a', 'a.id', '=', 'o.linked_id');  
                
                if($RecordID)
				{
					$query->where('o.id', $RecordID)->where('s.sync_status','Failed');
				} else {
                    //get ready status shipment & also Failed as auto retry
                    $query->whereIn('s.sync_status',[$sync_status,'Failed'])->where('s.attempt', '<=', 2);
                }
				

                $list = $query->where(['s.user_id' => $userId, 's.platform_id' => $SourcePlatformId, 's.user_integration_id' => $userIntegrationId])
                ->orderBy('s.updated_at','asc')->limit(6)->get();


               if (!empty($list) && count($list) > 0) {

                   $orderIds = [];
                   foreach ($list as $key => $value) {

                    if(!$value->linked_id) {

                        $this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed', 'attempt' => 5], ['id' => $value->id]);
                        $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ignore'], ['id' => $value->order_primary_id]);
                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $value->order_primary_id, 'Order Not Found in Trailsend');
                       return true;

                    }


                        $Carrier_Code = '';
                        
                        // $shipping_maping_data = $this->map->getMappedDataByName($userIntegrationId, NULL, "sorder_shipping_method", ['api_id'], 'regular',$value->shipping_method);
                        // if($shipping_maping_data){
                        //     $Carrier_Code = $shipping_maping_data->api_id;
                        // } 

                        //prepare shipment line
                       $items_posting = $this->makeShipmentLineItems($userId,$userIntegrationId,$WorkFlowID,$value->id,$value->trailsend_order_id,$SourcePlatformId); 

                       $items_shipment = [];
                       $items_package = [];   

                       $payload = [];
                       $payload['Consumer_Order_Id'] = $value->api_order_id;
                       $payload['External_Order_Ref'] = $value->order_number;

                       $shipment_data['External_Shipment_Ref'] = $value->shipment_id;
                       $shipment_data['Ship_Date'] = date("Y-m-d H:i:s", strtotime($value->created_on));

                       $package_data['External_Package_Ref'] = $value->tracking_info;
                       $package_data['Package_Tracking'] = $value->tracking_info;
                       $package_data['Shipping_Cost'] = '';
                       //give mapping for this if found then pass code otherwise send null
                       $package_data['Carrier_Code'] = $Carrier_Code;
                        //add extra tracking url
                       $package_data['Tracking_Url'] = $value->tracking_url;

                       //prepare line items for fulfillment/complete
                       $package_data['Contents'] = $items_posting['items_posting'];


                        //set package in array
                       $items_package[] = $package_data;
                       $shipment_data['Packages'] = $items_package;

                        //set shipment in array
                       $items_shipment[] = $shipment_data;
                       $payload['Shipments'] = $items_shipment;
        
                       $shipment_payload = json_encode($payload);
                            
                       //post api call for create shipment in trialsend
                       $response = $this->trailsend_api->ApiCall($authHash, $apiEndpoint, $shipment_payload, 'PUT',$env_type,'yes');  

                      
                       \Storage::disk('local')->append('trailsend_create_shipment.txt', 'createShipment create in trialsend shipment_api_status -'.$value->shipment_api_status.' time: '.date('Y-m-d H:i:s').' postData - '. $shipment_payload. ' response-'. json_encode($response) .PHP_EOL);

                       //update response type
                       
                       if ( isset($response['Errored']) &&  $response['Errored']==false ) {


                           $shipmentLinked = $this->mobj->makeInsertGetId('platform_order_shipments', [
                               'user_id' => $userId,
                               'platform_id' => $this->platformId,
                               'user_integration_id' => $userIntegrationId,
                               'shipment_id' => $value->shipment_id,
                               'sync_status' => 'Synced',
                               'linked_id' => $value->id,
                           ]);
                           
                           //update platform_order_shipments
                           $this->mobj->makeUpdate('platform_order_shipments', ['linked_id' => $shipmentLinked, 'sync_status' => 'Synced'], ['id' => $value->id]);
                           
                           //update order shipment status based on 
                           if( $value->shipment_api_status == 'PartiallyFulfilled'){

                                $this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Partial'], ['id'=>$value->order_primary_id]);
                                
                           } 
                           //if( $value->shipment_api_status == 'Fulfilled')
                           else {
                            
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $value->order_primary_id]);

                           } 


                           $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,$object_id, 'success', $value->order_primary_id, NULL);
                           $return_response = true;

                       } else {

                           $error = @$response['Status_Message'];
                           
                           if(empty($response)){
                            $error = 'No application exists or API Error';
                           }

                           $this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed', 'attempt' => $value->attempt + 1], ['id' => $value->id]);
                           $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $value->order_primary_id]);
                           $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $value->order_primary_id, $error);
                           $return_response = true;
                       }

                   }

               }
                   
           }


       } catch (\Exception $e) {
           \Log::error($e->getMessage());
           return $e->getMessage();
       }
       return $return_response;
       
   }


   //used for shipmentConfirmation
   public function makeShipmentLineItems($userId,$userIntegrationId,$WorkFlowID,$shipmentID,$trailsend_order_id,$platformId)
   {
   
       $items_posting = [];
       $product_identity_obj_id = $this->helper->getObjectId('product_identity');
       $maping_data = $this->map->getMappedField($userIntegrationId, $WorkFlowID, $product_identity_obj_id);


       if ($maping_data) {

           $source_row_data = $destination_row_data = '';
           if ($maping_data['source_platform_id'] == 'trailsend') {
               $destination_row_data = $maping_data['source_row_data'];
               $source_row_data = $maping_data['destination_row_data'];
           }


           $shipment_line_array = $this->mobj->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $shipmentID], ['product_id','quantity','sku','user_batch_reference']);


           if (!empty($shipment_line_array)) {

               foreach ($shipment_line_array as $v) {
                
                   $lotCode = [];
                   $get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $trailsend_order_id,$destination_row_data => $v->sku ], ['id','api_order_line_id','product_name','qty']);

                   if($get_po_line){
                        $line['Line_Item_Id'] = $get_po_line->api_order_line_id;
                        $line['Line_Item_Name'] = $get_po_line->product_name;
                        $line['Order_Quantity'] = $get_po_line->qty;
                        $line['Shipped_Quantity'] = $v->quantity;

                        //put lot code
                        $lotLine['Lot_Code'] = ($v->user_batch_reference) ? $v->user_batch_reference : 'NotRecieved';
                        $lotCode[] = $lotLine;
                        $line['Lot_Codes'] = $lotCode;
                      
                        $items_posting[] = $line; 
                   }
      

               }
           }
       }

       return ['items_posting' => $items_posting];
       
   }
 

   public function ExecuteTrailsend($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
   {
      try {

        $response = true;

        //get orders & send acknowledgement
        if ($method == 'GET' && $event == 'SALESORDER') {
           
            $response = $this->GetSalesOrder($user_id, $user_integration_id, $user_workflow_rule_id);
        } 
         else if($method == 'MUTATE' && $event == 'SHIPMENT'){
            $response = $this->createShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, 'Ready', $record_id);
      
        }  

         return $response;
      } catch (\Exception $e) {
         return $e->getMessage();
      }
   }

   //Test  test_trailsend
   public function testTrailsend()
   {
        // $env_type = 'sandbox';
        //get order
        // $testOrders = $this->GetSalesOrder(212, 526, 996);
        // dd($testOrders);

        // create shipment
        // $test_shipment = $this->createShipment(212, 526, 158, 997, 'shipbob', $sync_status = "Ready");
        // dd($test_shipment);


   }

   
}