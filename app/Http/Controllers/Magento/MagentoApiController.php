<?php

namespace App\Http\Controllers\Magento;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Logger;
use App\Helper\Api\MagentoApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use Illuminate\Support\Facades\Session;
use Lang;

class MagentoApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $my_platform = 'magento';

    public $mobj,$MagentoApi,$ConnectionHelper, $log,$my_platform_id,$mapping ;

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->MagentoApi = new MagentoApi();
        $this->log = new Logger();
        $this->mapping = new FieldMappingHelper();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->my_platform_id = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform);
    }

    public function InitiateMGAuth(Request $request)
    {
        $platform = self::$my_platform;
        return view("pages.apiauth.auth_magento", compact('platform'));
    }

    public function ConnectMagentoAuth(Request $request)
    {
        //server validation
        $validated = $request->validate([
            'account_name' => 'required',
            'access_token' => 'required',
            'mg_host' => 'required|active_url'
        ]);


        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $data = [];

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }

       try {
                $flag = true;

                $validate =  $this->MagentoApi->validateToken(0,0,$request->access_token,$request->mg_host);              
               
                 if($validate === false)
                 {
                     $flag = false;
                     $data['status_code'] = 0;
                     $data['status_text'] = 'Host/Url or tokens are incorrect';
                 }
                 else
                 {
                $account_name = $request->account_name;
                $access_token = $this->mobj->encrypt_decrypt($request->access_token);
                $host = $request->mg_host;

                $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['api_domain' => $host,'access_token' => $access_token, 'platform_id' => $this->my_platform_id], ['user_id']);
                if ($obj_existing) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'Given details are already in use, Try with other details.';
                    return json_encode($data);
                }

                // store/update skuvault token
                $tokens = array(
                    'user_id' => $user_id,
                    'platform_id' => $this->my_platform_id,
                    'account_name' => $account_name,
                    'api_domain' => $host,
                    'access_token' => $access_token
                );

                 DB::table('platform_accounts')->insert($tokens);
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


    public function GetStores($user_id, $user_integration_id)
    {
        $return_response = false;
        try {

            $object_id = $this->ConnectionHelper->getObjectId('store');

                     $list = $this->MagentoApi->GetStores($user_integration_id, $this->my_platform_id);

                        if($list === false)
                        {
                            $return_response = "API Error";
                        }
                        else if(!empty($list)) {

                                if (isset($object_id)) {
                                    // update users integration warehouse status to 0.
                                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id,  'platform_id' => $this->my_platform_id, 'platform_object_id' => $object_id]);

                                    foreach ($list as $record) {
                                        
                                        if($record->is_active)
                                        {
                                        $insertList = ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'name' => $record->name, 'api_code' => $record->code,'api_id' => $record->id, 'status' => 1, 'platform_object_id' => $object_id];
                                        $findRecord = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $this->my_platform_id,
                                            'platform_object_id' => $object_id,
                                            'api_id' => $record->id,
                                        ], ['id']);
                                        if ($findRecord) {
                                            $this->mobj->makeUpdate(
                                                'platform_object_data',
                                                $insertList,
                                                ['id' => $findRecord->id]
                                            );
                                        } else {
                                            $this->mobj->makeInsert('platform_object_data', $insertList);
                                        }
                                     }
                                    }
                                    $return_response = true;
                                }
                            }
                        


        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    //type = 'vendor' or 'customer'
    public function getCustomerMapping($source_customer, $platform_order_id,$user_workflow_rule_id, $platform_workflow_rule_id  , $destination_platform_id,$object_id,$store_id='')
    {
        $firstInternalId = $firstPlatformCustomerId = 0;
        $error_msg='';

        if(empty($source_customer->email))
        {
            return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
        }

        $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer',
         ['platform_id' => $destination_platform_id, 'email' => $source_customer->email,
         'user_integration_id' => $source_customer->user_integration_id],['api_customer_id','id']);

         if (empty($findCustomer->id))
         {
           $mapping_result = $this->MagentoApi->SearchCustomers($source_customer->user_integration_id,$destination_platform_id,$source_customer->email);
           
           if($mapping_result === false)
           {
            return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $error_msg];
           }
           else if(!empty($mapping_result->total_count) && !empty($mapping_result->items[0]))
           {
                  $customer = $mapping_result->items[0];
                 
                  $firstInternalId = $customer->id;
                  
                  $address = $customer->addresses;                 

                  $fields = array(
                                'user_id' => $source_customer->user_id,
                                'user_integration_id' => $source_customer->user_integration_id,
                                'platform_id' => $destination_platform_id,
                                'api_customer_id' => $firstInternalId,
                                'first_name' => $customer->firstname,
                                'last_name' => $customer->lastname,
                              //  'company_name' => @$customer->companyName,
                             //   'phone' =>  @$customer->phone,
                                'email' => @$customer->email,
                                'company_id' => $customer->store_id
                              //  'postal_addresses' => json_encode($address),

                            );
               $firstPlatformCustomerId =   $this->mobj->makeInsertGetId('platform_customer', $fields);


              return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];

           }
           else if(isset($mapping_result->total_count) && $mapping_result->total_count == 0)
           {
             /** Create Magento Customer */

            $data = ['firstName' => explode(' ',($source_customer->customer_name ? $source_customer->customer_name : $source_customer->company_name),2)[0],
            'lastName' => @explode(' ',($source_customer->customer_name ? $source_customer->customer_name : $source_customer->company_name),2)[1],
            'companyName' => $source_customer->company_name,
            'email' => $source_customer->email,
            'phone' => $source_customer->phone,
            'store_id' => $store_id,
            'address1' => $source_customer->address1,
            'address2' => $source_customer->address2,
            'city' => $source_customer->address3,
            'zip' => $source_customer->postal_addresses,
            'country' => $source_customer->country
            ];

           $response = $this->MagentoApi->CreateCustomers($source_customer->user_integration_id, $destination_platform_id, $data);

               if( $response === false || !empty($response->error))
               {
                   $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $platform_order_id]);
                   $sync_error = 'Customer sync failed';
                   $sync_error.=' '.@$response->error;
                  $this->log->syncLog($source_customer->user_id, $source_customer->user_integration_id, $user_workflow_rule_id, $source_customer->platform_id, $destination_platform_id, $object_id, 'failed', $platform_order_id, $sync_error);

                  return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $sync_error];

            }
            else
            {
                $fields = array(
                                'user_id' => $source_customer->user_id,
                                'user_integration_id' => $source_customer->user_integration_id,
                                'platform_id' => $destination_platform_id,
                                'api_customer_id' => $response->id,
                                'customer_name' => $source_customer->customer_name,
                                'company_name' => $source_customer->company_name,
                                'phone' =>  $source_customer->phone,
                                'email' => $source_customer->email,
                                 'company_id' => $response->store_id
                              //  'postal_addresses' => json_encode($address)

                            );
               $firstPlatformCustomerId =   $this->mobj->makeInsertGetId('platform_customer', $fields);

             
               $firstInternalId = $response->id;
               
            }


             return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
           }

           return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $error_msg];
         }

         return ['api_customer_id' => $findCustomer->api_customer_id, 'platform_customer_id' => $findCustomer->id, 'error_msg' => $error_msg];



    }



    public function getProductMapping($source_product , $destination_platform_id, $source_field_match_by, $destination_field_match_by, $store_id='', $save_record = 1)
    {
        $match_string = $source_product->$source_field_match_by;

        if(empty($match_string))
        {
            return 0;
        }


        $findProduct = $this->mobj->getFirstResultByConditions('platform_product',
         ['platform_id' => $destination_platform_id, $destination_field_match_by => $match_string,
         'user_integration_id' => $source_product->user_integration_id]);

         if (empty($findProduct->id))
         {
           $mapping_result = $this->MagentoApi->SearchProducts($source_product->user_integration_id,$destination_platform_id,$match_string);

           
           if($mapping_result === false)
           {
            return false;
           }
           else if(!empty($mapping_result->total_count) && !empty($mapping_result->items[0]))
           {
              $product = $mapping_result->items[0];
              
              $firstInternalId = $product->id;
                  
                  
                  if($save_record)
                  {

                  $fields = array(
                                'user_id' => $source_product->user_id,
                                'user_integration_id' => $source_product->user_integration_id,
                                'platform_id' => $destination_platform_id,
                                'api_product_id' => $firstInternalId,
                                'product_name' => @$product->name,
                                'api_product_code' => @$product->type_id,
                              //  'upc' => @$product->upcCode,
                                'sku' => @$product->sku,
                                'price' => ((!empty($product->price)) ? $product->price : 0)


                            );
                  $checkProduct = $this->mobj->getFirstResultByConditions('platform_product',
                 ['platform_id' => $destination_platform_id, $destination_field_match_by => $match_string,
                 'user_integration_id' => $source_product->user_integration_id]);
                 
                 if($checkProduct)
                 {                    
                         $this->mobj->makeUpdate('platform_product', $fields, ['id' => $checkProduct->id]);
                 }
                 else
                 {                    
                         $this->mobj->makeInsert('platform_product', $fields);
                 }
                 }         

              

            $findProduct = $this->mobj->getFirstResultByConditions('platform_product',
         ['platform_id' => $destination_platform_id, $destination_field_match_by => $match_string,
         'user_integration_id' => $source_product->user_integration_id]);


              return $findProduct;
           }
           else if(isset($mapping_result->total_count) && $mapping_result->total_count == 0)
           {
             return 0;
           }

           return false;
         }

         return $findProduct;



    }


    public function CreateOrdersByType($user_id, $source_platform_name, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id,$record_id, $type = 'sales_orders')
    {

        $sync_error = false;
        $orderType = ($type == 'purchase_orders') ? 'PO' : 'SO';
        try {
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

            if($type == 'purchase_orders')
            {
            $object_id = $this->ConnectionHelper->getObjectId('purchase_order');
            }
            else if($type == 'sales_orders')
            {
            $object_id = $this->ConnectionHelper->getObjectId('sales_order');
            }
            else
            {
            $object_id = $this->ConnectionHelper->getObjectId('transfer_order');
            }

            $userIntegrationObj = $this->mapping->getUserIntegrationDetailsById($user_integration_id, self::$my_platform);
            if ($userIntegrationObj) {
                $additionalAccountInfo = null;
                if ($source_platform_id == 1) {
                    $additionalAccountInfo = $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['user_integration_id' => $user_integration_id, 'account_id' => $userIntegrationObj->selected_sc_account_id]);
                }

                $conditions = [/*'order_type' => $orderType,*/'user_workflow_rule_id' => $user_workflow_rule_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id];

                if($record_id)
                {
                    $conditions['id'] = $record_id;
                }
                else
                {
                    $conditions['sync_status'] = $sync_status;
                }

                $result_order = $this->mobj->getResultByConditions('platform_order', $conditions, [], ['id' => 'asc'], 10);

                $successs_orders = $failed_orders = array();

                if(count($result_order) > 0)
                {
                    /** Get Product Identity */

                    $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');

                    $maping_data = $this->mapping->getMappedField($user_integration_id, null, $product_identity_obj_id);

                    if(! empty($maping_data))
                    {
                      $source_pi_field_match_by =  $maping_data['source_row_data'];
                      $destination_pi_field_match_by = $maping_data['destination_row_data'];
                    }
                  
                     /** Default Store */
                   $default_store = $this->mapping->getMappedDataByName($user_integration_id, null, "store", ['api_id']);
                   $store = $default_store ? $default_store->api_id : NULL;


                }

                foreach ($result_order as $row) {

                    //Get mapping fields
                    if(empty($source_pi_field_match_by) || empty($destination_pi_field_match_by))
                    {
                          $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                          $sync_error = 'Incorrect Field Mapping';
                          $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);

                          continue;

                     }

                    //Order customer details
                    $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer',
                     ['platform_id' => $source_platform_id, 'id' => $row->platform_customer_id,
                     'user_integration_id' => $user_integration_id]);

                     if (empty($findCustomer->id))
                     {
                          $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                          $sync_error = 'Invalid Order customer';
                          $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);

                          continue;

                     }

                    $getCustomerMapping = $this->getCustomerMapping($findCustomer,$row->id,$user_workflow_rule_id,$platform_workflow_rule_id,$this->my_platform_id,$object_id, $store);
                     $customerInternalId = $getCustomerMapping['api_customer_id'];
                     $destination_platform_customer_id = $getCustomerMapping['platform_customer_id'];



                    if(  $customerInternalId  === false)
                    {
                          $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                          $sync_error = 'Customer mapping error '.$getCustomerMapping['error_msg'];
                          $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);

                          continue;

                    }



                    //order data
                    $order_array = ['entity' => ['base_currency_code' => $row->currency,'order_currency_code' => $row->currency,'base_grand_total' => $row->total_amount,'grand_total' => $row->total_amount, 'base_subtotal' => $row->net_amount, 'subtotal' => $row->net_amount, 'tax_amount' => $row->total_tax]];
                    
                    $order_array['entity']['customer_email'] = $findCustomer->email;
                    $order_array['entity']['customer_id'] = $customerInternalId;
                  //  $order_array['entity']['status'] = $row->order_status;
                     $order_array['entity']['status'] = 'pending';
                      $order_array['entity']['state'] = 'new';
                    
                     //$order_array['entity']['shipping_address'] ;
                      $order_array['entity']['billing_address'] = [];
                     $billing_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type' => 'billing','platform_order_id' => $row->id]);

                     if(!empty($billing_address))
                     {
                        $order_array['entity']['billing_address']['address_type'] = 'billing';
                        $order_array['entity']['billing_address']['city'] = $billing_address->city;                        
                        $order_array['entity']['billing_address']['country_id'] = $billing_address->country;
                        $order_array['entity']['billing_address']['firstname'] = explode(' ',($findCustomer->customer_name ? $findCustomer->customer_name : $findCustomer->company_name),2)[0];
                        $order_array['entity']['billing_address']['lastname'] = @explode(' ',($findCustomer->customer_name ? $findCustomer->customer_name : $findCustomer->company_name),2)[1];                 
                        $order_array['entity']['billing_address']['postcode'] = $billing_address->postal_code;
                        $order_array['entity']['billing_address']['region_code'] = $billing_address->state;
                        $order_array['entity']['billing_address']['street'] = [$billing_address->address1,$billing_address->address2];
                        $order_array['entity']['billing_address']['customer_id'] = $customerInternalId;
                        $order_array['entity']['billing_address']['telephone'] = $findCustomer->phone;
                     }

                  
                    // if($row->api_order_reference)
//                    {
//                    $order_array['memo'] = $row->api_order_reference;
//                    }
                    $order_array['entity']['created_at'] = $row->order_date;
                 //   $order_array['entity']['extension_attributes']['shipping_assignments'][0]['shipping']['method']='flatrate_flatrate';
                     
                    $shipping_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type' => 'shipping','platform_order_id' => $row->id]);
                 
                    $order_array['entity']['extension_attributes']['shipping_assignments'][0]['shipping']['address']=['address_type' => 'shipping',
                    'city' => $shipping_address->city,'company' => $shipping_address->company,'country_id' => $shipping_address->country,
                    'email' => $findCustomer->email,'firstname' => explode(' ',($findCustomer->customer_name ? $findCustomer->customer_name : $findCustomer->company_name),2)[0],
                    'lastname' => @explode(' ',($findCustomer->customer_name ? $findCustomer->customer_name : $findCustomer->company_name),2)[1],
                    'postcode' => $shipping_address->postal_code,'region_code' => $shipping_address->state,
                    'street' =>    [$shipping_address->address1,$shipping_address->address2]  , 'telephone' =>  $findCustomer->phone         
                    ];
                    $order_array['entity']['items'] = [];


                    //get order line items
                    $totalDiscount = $totalBaseDiscount = 0;
                    $totalShipping = $totalBaseShipping = 0;
                    $total_qty_ordered = $subtotal = 0;
                    $order_lines = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $row->id]);
                    $error_msg = 0;
                    foreach($order_lines as $order_line)
                    {
                        if ($order_line->row_type == 'ITEM') {
                            //Order product details
                            $findProduct = $this->mobj->getFirstResultByConditions('platform_product',
                                ['platform_id' => $source_platform_id, 'api_product_id' => $order_line->api_product_id,
                                    'user_integration_id' => $user_integration_id]);
                            if (empty($findProduct->id))
                            {
                                $this->mobj->makeUpdate('platform_product', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $sync_error = 'Invalid Order product';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);
                                $error_msg = 1;
                                break;
                            }
                            $linked_product = $this->getProductMapping($findProduct,$this->my_platform_id,$source_pi_field_match_by,$destination_pi_field_match_by,$store) ;
                            if( $linked_product === false)
                            {
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $sync_error = 'Product mapping error';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);
                                $error_msg = 1;
                                break;
                            }
                            else if( $linked_product === 0)
                            {
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $sync_error = 'Product is not mapped ';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);
                                $error_msg = 1;
                                break;
                            }
                            $productInternalId = $linked_product->api_product_id;
                            
                            $total_qty_ordered+= $order_line->qty;
                            $subtotal+= $order_line->total + $order_line->total_tax;

                            $order_array['entity']['items'][] = ['qty_ordered' => $order_line->qty,'sku' => $linked_product->sku,'product_type' => 'simple','store_id' => $store, 'product_id' => $productInternalId, 'base_price' => $order_line->unit_price, 'price' => $order_line->unit_price,'row_total' => $order_line->total + $order_line->total_tax];
                        } else if ($order_line->row_type == 'DISCOUNT') {
                            $totalDiscount += $order_line->total + $order_line->total_tax;
                            $totalBaseDiscount += $order_line->total;
                        } else if ($order_line->row_type == 'SHIPPING') {
                            $totalShipping += $order_line->total + $order_line->total_tax;
                            $totalBaseShipping += $order_line->total;
                        }
                    }
                    $order_array['entity']['discount_amount'] = $totalDiscount;
                    $order_array['entity']['base_discount_amount'] = $totalBaseDiscount;
                    $order_array['entity']['shipping_amount'] = $totalShipping;
                    $order_array['entity']['base_shipping_amount'] = $totalBaseShipping;
                    $order_array['entity']['store_id'] = $store;
                    $order_array['entity']['total_qty_ordered'] = $total_qty_ordered;
                    $order_array['entity']['total_item_count'] = count($order_lines);
                    $order_array['entity']['payment'] = ['account_status' => 'unpaid', 'additional_information' => [],
                    'cc_last4' => '0000','amount_paid' => 0,'method' => 'N/A'
                    ];
                    
                    if($row->api_order_payment_status != 'unpaid')
                    {
                      $order_array['entity']['payment']['account_status']  =  'paid' ;                        
                      $additional_info = [];
                      $amount_paid=0;
                      $transactions = $this->mobj->getResultByConditions('platform_order_transactions', ['platform_order_id' => $row->id]);
                      
                      foreach($transactions as $transaction)
                      {
                        array_push($additional_info, 'transaction_id: '.$transaction->transaction_id, 'transaction_datetime: '.$transaction->transaction_datetime,
                        
                           'transaction_type: ' .$transaction->transaction_type, 'transaction_method: '.$transaction->transaction_method,
                                                            'transaction_reference: '.$transaction->transaction_reference                                                        
                      
                       ); 
                       
                       $amount_paid+= $transaction->transaction_amount;
                      }
                      $order_array['entity']['payment']['additional_data']= implode(' ',$additional_info);
                    //  $order_array['entity']['payment']['additional_information']= $additional_info;
                      $order_array['entity']['payment']['amount_paid'] = $amount_paid;
                      $order_array['entity']['payment']['cc_last4'] = '0000';                      
                      $order_array['entity']['payment']['method'] = @$transaction->transaction_method;  
                      if(!empty($transaction->transaction_reference))
                      {                    
                      $order_array['entity']['payment']['last_trans_id'] = @$transaction->transaction_reference;
                      }
                                                            
                      
                    }
                   
                  


                    if(! $error_msg)
                    {



                   if(! count($order_array['entity']['items']))
                   {
                          $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                          $sync_error = 'Products missing ';
                          $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);

                          $error_msg = 1;
                          break;

                    }
                    
                    $order_array['base_subtotal'] = $subtotal;
                    $order_array['subtotal'] = $subtotal;
                    


                    //create order api

                     $response =  $this->MagentoApi->CreateSalesOrder($user_integration_id,$this->my_platform_id, $order_array);
                   

                      if( $response === false || $response === 0 ||  !empty($response['error']) )
                    {
                          $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                          $sync_error = 'Order sync failed '.@$response['error'];
                          $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $sync_error);

                          $error_msg = 1;
                          continue;

                    }
                    else
                    {
                            

                           //store magento order details into db and link with parent order
                            $arr_po_order = array();
                            $arr_po_order['user_id'] = $user_id;
                            $arr_po_order['user_workflow_rule_id'] = $user_workflow_rule_id;
                            $arr_po_order['platform_id'] = $this->my_platform_id;
                            $arr_po_order['order_date'] = $row->order_date;
                            $arr_po_order['user_integration_id'] = $user_integration_id;
                            $arr_po_order['order_type'] = $row->order_type;
                            $arr_po_order['api_order_id'] = $response['entity_id'];
                            $arr_po_order['platform_customer_id'] = $destination_platform_customer_id;
                            $arr_po_order['customer_email'] = $row->customer_email;
                            $arr_po_order['order_number'] = $row->order_number;
                            $arr_po_order['api_order_reference'] = $row->api_order_reference;
                            $arr_po_order['total_amount'] = $row->total_amount;
                            $arr_po_order['net_amount'] = $row->total_amount;
                            $arr_po_order['sync_status'] = 'Ready';
                            $arr_po_order['linked_id'] = $row->id; //parent platform order row id
                            
                            $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_po_order);
                            //update acknowledge
                            $update_arr = ['sync_status' => 'Synced', 'linked_id' => $linked_platform_order_id];
                            //update destination order record
                            $this->mobj->makeUpdate('platform_order', $update_arr, ['id' => $row->id]);
                            //sync logger
                            $sync_error = 'Order synced successfully';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $row->id, $sync_error);



                    }


                    }

                }
            }

            return $sync_error;
        } catch (\Exception $e) {
            throw new \Exception('Error: ' . $e->getMessage());
        }
    }


    public function ExecuteEventMagento($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.

            if ($method == 'GET' && $event == 'STORE') {
               $response =  $this->GetStores($user_id, $user_integration_id);
            }            
            else if ($method == 'MUTATE' && $event == 'SALESORDER') {
                $sync_status = 'Ready';
                $this->CreateOrdersByType($user_id, $source_platform_id, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id,$record_id);
            }
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
    public function test(Request $request)
    {
          echo '<pre>';
          
          dd($this->CreateOrdersByType(150,'brightpearl',416,262,'Ready',67,212005));
//      
//   $default_store = $this->mapping->getMappedDataByName(213, null, "store", ['api_id']);
//               echo    $store = $default_store ? $default_store->api_id : NULL;
//                dd($this->MagentoApi->SearchCustomers(213,21,'namrata1.constacloud@gmail.com',$store));
//exit();
//          $select = ['access_token','api_domain'];
    // dd($this->GetStores(1, 243));
       
   //     exit;
          $host = 'https://5798qz73hiki6ptt.mojostratus.io/hq/';
          $access_token='axhj13kt06682zvg1ultfkc8irrb824y';
          
           $host = $host ? trim($host,' /') : $host;
        echo $url = $host."/index.php/rest/default/V1/orders?searchCriteria[pageSize]=1"; 
         
      $header =  $this->MagentoApi->GetMagentoHeader(0,0,$access_token,$host);
      print_r($header);
          $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);print_r($result);
        $httpCode = curl_getinfo($ch );
      curl_close($ch);
        $result = json_decode($result, 1);
        print_r($httpCode);
        var_dump($result);
        return $result;      
          
    }


}