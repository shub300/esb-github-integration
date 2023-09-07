<?php

namespace App\Http\Controllers\Intacct;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Api\IntacctApi;
use App\Helper\FieldMappingHelper;
use App\Models\Enum\PlatformStatus;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformObject;
use App\Models\PlatformObjectData;
use App\Models\PlatformObjectDataAdditionalInformation;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use App\Models\PlatformCustomer;
use App\Models\PlatformCustomerAdditionalInformation;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IntacctApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        $this->intacctapi = new IntacctApi();
        $this->helper = new ConnectionHelper();
        $this->wfsnip = new WorkflowSnippet();
        $this->my_platform = 'intacct';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);

    }

    public function InitiateIntacctAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.intacct_auth", compact('platform'));
    }

    public function ConnectIntacctOauth(Request $request)
    {

        $account_id = trim($request->account_id);
        $app_id = trim($request->app_id);
        $app_secret = trim($request->app_secret);

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            Session::put('auth_msg', Lang::get('tags.validate'));
            return redirect()->back();
         }

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $isAllowed =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id], ['app_ref', 'client_id', 'client_secret']);




        if ($isAllowed) {
            $app_ref = $this->mobj->encrypt_decrypt($isAllowed->app_ref,'decrypt');
            $client_id = $this->mobj->encrypt_decrypt($isAllowed->client_id,'decrypt');
            $client_secret = $this->mobj->encrypt_decrypt($isAllowed->client_secret,'decrypt');

            $response = $this->intacctapi->CheckAPIAndReturnSession($user_id,$account_id, $app_id, $app_secret);

            if ($response['api_status'] == 'success') {
                if (isset($response['operation']['result']['data']['api']['sessionid'])) {
                    //return $response['operation']['result']['data']['api']['sessionid'];

                    $OauthData = [
                        'access_token' => $this->mobj->encrypt_decrypt($response['operation']['result']['data']['api']['sessionid'],'encrypt'),
                        'app_id' => $this->mobj->encrypt_decrypt($app_id,'encrypt'),
                        'app_secret' => $this->mobj->encrypt_decrypt($app_secret,'encrypt'),
                        'account_name' => $account_id,
                        'user_id' => $user_id,
                        'platform_id' => $this->my_platform_id,
                        'expires_in' => 3600,
                        'token_refresh_time' => time()
                    ];

                    $ufound =  $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id,'platform_id' => $this->my_platform_id,'account_name' => $account_id],['id']);


                    if ($ufound) {
                        $this->mobj->makeUpdate('platform_accounts',$OauthData,['id' => $ufound->id]);
                    } else {
                        $this->mobj->makeInsert('platform_accounts',$OauthData);
                    }

                } else {
                    Session::put('auth_msg', 'Sign-in information is incorrect');
               }
            }else{
                Session::put('auth_msg', $response['api_error']);
            }
        } else {
            Session::put('auth_msg', 'Authentication Error');
        }

        echo '<script>window.close();</script>';
    }


    public function GetRefreshSession($user_id,$id,$account_id,$app_id,$app_secret)
    {
        try{
            $isAllowed =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id], ['app_ref', 'client_id', 'client_secret']);
            if ($isAllowed) {
                $app_ref = $this->mobj->encrypt_decrypt($isAllowed->app_ref,'decrypt');
                $client_id = $this->mobj->encrypt_decrypt($isAllowed->client_id,'decrypt');
                $client_secret = $this->mobj->encrypt_decrypt($isAllowed->client_secret,'decrypt');

                $account_id = $account_id;
                $app_id = $this->mobj->encrypt_decrypt($app_id,'decrypt');
                $app_secret = $this->mobj->encrypt_decrypt($app_secret,'decrypt');

                $response = $this->intacctapi->CheckAPIAndReturnSession($user_id,$account_id, $app_id, $app_secret);

                if ($response['api_status'] == 'success') {
                    if (isset($response['operation']['result']['data']['api']['sessionid'])) {

                        $OauthData = [
                            'access_token' => $this->mobj->encrypt_decrypt($response['operation']['result']['data']['api']['sessionid'],'encrypt'),
                            'expires_in' => 3600,
                            'token_refresh_time' => time(),
                            'updated_at' => now()
                        ];

                        $this->mobj->makeUpdate('platform_accounts',$OauthData,['id' => $id]);

                    } else {
                    // fail
                }
                }else{
                // fail
                }

            }
        }catch (\Exception $e) {
            $return_response = $e->getMessage();
			\Storage::disk('local')->append('testCrone.txt', 'Intact Refresh Token Resp : '.json_encode($return_response));
        }


    }

    public function ExecuteEventIntacct($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '',$source_platform_id='',$platform_workflow_rule_id='', $record_id = '')
    {
        //Log::info("ExecuteEventIntacct: Method=".$method.", Event=".$event );
        $response = true;
        ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
        if ($method == 'GET' && $event == 'LOCATION') {
            $response = $this->IntacctGetLocations($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'GET' && $event == 'WAREHOUSE') {
            $response = $this->IntacctGetWarehouses($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->IntacctGetAllProducts($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'GET' && $event == 'CUSTOMER') {
            $response = $this->IntacctGetAllCustomers($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'GET' && $event == 'INVOICE') {
            $response = $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,$is_initial_sync);
        } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
            $response = $this->IntacctCreateSalesOrder($user_id,$source_platform_id,$user_integration_id,$user_workflow_rule_id,'PO','Ready',$record_id);
        } else if ($method == 'GET' && $event == 'TERMS') {
            $response = $this->IntacctGetTerms($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'MUTATE' && $event == 'PAYMENT') {//added by @GK
            $response = $this->createARPaymentSynchronize( $user_id, $user_integration_id, $is_initial_sync, $destination_platform_id, $source_platform_id );
        } else if ($method == 'GET' && $event == 'SOTRANSACTIONTYPES') {
            $response = $this->IntacctGetTransactionTypes($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'GET' && $event == 'INVOICEBACKUP') {
            $response = $this->IntacctGetInvoicesBackup($user_id,$user_integration_id);
        }

        return $response;
    }

    public function IntacctCreateCustomer($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,$email)
    {


        try{


            $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
            $sync_object_id = $this->helper->getObjectId('customer');

            $result =  $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id,'email'=>$email],['api_customer_code','id']);


            if($result){
                return $result->api_customer_code;
            }else{

                $res_customer_data =  $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id'=>$user_integration_id,'platform_id'=>$source_platform_id,'email'=>$email],['id','customer_name','phone','fax']);

                if($res_customer_data){

                    $id = $res_customer_data->id;
                    $customer_name = $res_customer_data->customer_name;
                    $phone = $res_customer_data->phone;
                    $fax = $res_customer_data->fax;

                    // create contact
                    $body="<create>
                                <CONTACT>
                                    <CONTACTNAME>$customer_name</CONTACTNAME>
                                    <PRINTAS>$customer_name</PRINTAS>
                                </CONTACT>
                            </create>";

                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$body);
                    //echo "<pre>";
                    //print_r($response);

                    if($response['api_status']=='success'){

                        $customer_code = 'C'.time();
                        // create customer
                        $body="<create>
                                <CUSTOMER>
                                    <CUSTOMERID>$customer_code</CUSTOMERID>
                                    <NAME>$customer_name</NAME>
                                    <DISPLAYCONTACT>
                                        <PRINTAS>$customer_name</PRINTAS>
                                        <PHONE1>$phone</PHONE1>
                                        <FAX>$fax</FAX>
                                        <EMAIL1>$email</EMAIL1>
                                    </DISPLAYCONTACT>
                                    <STATUS>active</STATUS>
                                    <CONTACTINFO>
                                        <CONTACTNAME>$customer_name</CONTACTNAME>
                                    </CONTACTINFO>
                                    <BILLTO>
                                        <CONTACTNAME>$customer_name</CONTACTNAME>
                                    </BILLTO>
                                    <SHIPTO>
                                        <CONTACTNAME>$customer_name</CONTACTNAME>
                                    </SHIPTO>
                                    <CONTACT_LIST_INFO>
                                        <CATEGORYNAME>Contact</CATEGORYNAME>
                                        <CONTACT>
                                            <NAME>$customer_name</NAME>
                                        </CONTACT>
                                    </CONTACT_LIST_INFO>
                                </CUSTOMER>
                            </create>";


                        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$body);
                        //echo "<pre>";
                    // print_r($response);

                        //assign contact to customer
                        if($response['api_status']=='success'){
                            if(isset($response['operation']['result']['data']['customer']['RECORDNO'])){
                                $RECORDNO = $response['operation']['result']['data']['customer']['RECORDNO'];
                                //$CUSTOMERID = $response['operation']['result']['data']['customer']['CUSTOMERID'];


                                $this->mobj->makeUpdate('platform_customer',['sync_status'=>'Synced'],['user_integration_id'=>$user_integration_id,'platform_id'=>$source_platform_id,'email'=>$email]);


                                $arr_customer = array();
                                $arr_customer['user_id'] = $user_id;
                                $arr_customer['platform_id'] = $this->my_platform_id;
                                $arr_customer['user_integration_id'] = $user_integration_id;
                                $arr_customer['api_customer_id'] = $RECORDNO;
                                $arr_customer['api_customer_code'] = $customer_code;
                                $arr_customer['customer_name'] = $customer_name;
                                $arr_customer['phone'] = $phone;
                                $arr_customer['fax'] = $fax;
                                $arr_customer['email'] = $email;
                                $arr_customer['sync_status'] = 'Pending';

                                $this->mobj->makeInsert('platform_customer',$arr_customer);

                                $sync_error = null;

                                $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'success',$id,$sync_error);
                                return $customer_code;
                            }else{
                                $sync_error = json_encode($response,true);
                                $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                                return false;
                            }
                        }else{
                            $sync_error = $response['api_error'];

                            $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);
                            return false;
                        }
                    }else{
                        $sync_error = $response['api_error'];

                        $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                        return false;
                    }
                }/*else{
                    $id = $result->id;
                    $sync_error = 'Customer not found';

                    $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                    return false;
                }*/

            }




        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctCreateCustomer-->".$e->getMessage());
            //$return_response = $e->getMessage();
        }


    }

    public function IntacctCreateSalesOrder($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,$order_type,$sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        $process_limit = 30;
        $offset = 0;



        try{


            $source_platform_id = $this->helper->getPlatformIdByName($source_platform);


            //do{

                $allow_next_call = false; // This flag will help for pagination


                if($record_id!=''){

                    $result_order = $this->mobj->getResultByConditions('platform_order',['user_integration_id'=>$user_integration_id,'platform_id'=>$source_platform_id,'order_type'=>$order_type,'id' => $record_id],['order_date','due_days','order_number','notes','trading_partner_id','id','customer_email','file_name','vendor','api_order_reference','delivery_date'],['id'=>'asc'],$process_limit,$offset);

                }else{

                    $result_order = $this->mobj->getResultByConditions('platform_order',['user_integration_id'=>$user_integration_id,'platform_id'=>$source_platform_id,'order_type'=>$order_type,'sync_status'=>$sync_status],['order_date','due_days','order_number','notes','trading_partner_id','id','customer_email','file_name','vendor','api_order_reference','delivery_date'],['id'=>'asc'],$process_limit,$offset);
                }


                if(count($result_order) > 0){


                    $sync_object_id = $this->helper->getObjectId('sales_order');
                    $exchratetype = 'Intacct Daily Rate';
                    $currency = 'USD';


                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                    $maping_data = $this->map->getMappedField($user_integration_id, null, $product_identity_obj_id);

                    if ($maping_data) {

                        $source_row_data = $destination_row_data = '';
                        if ($maping_data['source_platform_id'] == 'intacct') {
                            $destination_row_data = $maping_data['source_row_data'];
                            $source_row_data = $maping_data['destination_row_data'];
                        } else {
                            $destination_row_data = $maping_data['destination_row_data'];
                            $source_row_data = $maping_data['source_row_data'];
                        }
                    }


                    $default_order_location_data = DB::table('platform_data_mapping as pdm')
                            ->join("platform_objects as po",function($join){
                            $join->on("pdm.platform_object_id","=","po.id")
                                ->on("pdm.status","=","po.status");
                            })->join("platform_object_data as pod",function($join){
                                $join->on("pdm.destination_row_id","=","pod.id");
                            })->where(['pod.user_id' => $user_id,'pdm.user_integration_id' => $user_integration_id,'pdm.mapping_type' => 'default','pdm.data_map_type' => 'object','po.name' => 'sorder_location'])->select(['api_code'])->first();
                    $default_order_location = @$default_order_location_data->api_code;
                    //echo $default_order_location;

                    $default_order_warehouse_data = DB::table('platform_data_mapping as pdm')
                            ->join("platform_objects as po",function($join){
                            $join->on("pdm.platform_object_id","=","po.id")
                                ->on("pdm.status","=","po.status");
                            })->join("platform_object_data as pod",function($join){
                                $join->on("pdm.destination_row_id","=","pod.id");
                            })->where(['pod.user_id' => $user_id,'pdm.user_integration_id' => $user_integration_id,'pdm.mapping_type' => 'default','pdm.data_map_type' => 'object','po.name' => 'order_warehouse'])->select(['api_code'])->first();
                    $default_order_warehouse = @$default_order_warehouse_data->api_code;


                    $AddressPriority = $this->map->getMappedDataByName($user_integration_id, null, "address_priority", ['api_code']);
                    $address_priority = @$AddressPriority->api_code ? $AddressPriority->api_code : 'bill_to_customer';

                    $address_to_object_id = $this->helper->getObjectId($address_priority);

                    $CustomDataAddressTo = $this->map->getMappedDataByName($user_integration_id,null,$address_priority, ['custom_data'], "default");
                    $intacct_customer_code = @$CustomDataAddressTo->custom_data ? $CustomDataAddressTo->custom_data : '';
                    //echo $intacct_customer_code;

                    $transactiontype_map=$this->map->getMappedDataByName($user_integration_id, null, "default_sales_order_transaction_type", ['name']);
                    $transactiontype= @$transactiontype_map->name ? $transactiontype_map->name : 'Sales Order';

                    $charges_allowances_item_order_map=$this->map->getMappedDataByName($user_integration_id,'',"charges_allowances_item_order", ['custom_data'], "default");
                    $charges_allowances_item= @$charges_allowances_item_order_map->custom_data ? $charges_allowances_item_order_map->custom_data : '';

                    $customer_name = $price_list_name = "";
                    if($intacct_customer_code!=''){
                        $address_to = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $this->my_platform_id, $address_to_object_id, 'api_id', $intacct_customer_code,["name","api_code","description"]);
                        if($address_to){
                            $customer_name = @$address_to->name;
                            $description = @$address_to->description ? json_decode($address_to->description,true) : '';
                            if($description){
                                $price_list_name = $description['price_list'];
                            }

                        }
                    }


                    if(count($result_order)==$process_limit){
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset+=$process_limit;
                    }



                    //dd($result_order);


                    foreach($result_order as $roworder){

                     //   $transactiontype = "Sales Order";
                        $order_date = $roworder->order_date;
                        $due_days = $roworder->due_days;
                        $order_reference = $roworder->api_order_reference;
                        $notes = $roworder->notes;
                        $trading_partner_id = $roworder->trading_partner_id;
                        $id = $roworder->id;
                        $customer_email = $roworder->customer_email;
                        $file_name = $roworder->file_name;
                        $vendor = $roworder->vendor;
                        $delivery_date = @$roworder->delivery_date ? date('m/d/Y',strtotime($roworder->delivery_date)) : '';

                        //if($customer_email!=''){
                            //echo $customer_email;
                            //$intacct_customer_code = $this->IntacctCreateCustomer($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,$customer_email);




                            if($intacct_customer_code==''){

                                $address_type = "shipping";
                                if($address_priority=='bill_to_customer'){
                                    $address_type = "billing";
                                }

                                $order_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type'=>$address_type,'platform_order_id'=>$id],['address_id']);
                                $location_id = @$order_address->address_id;

                                if($location_id!=''){
                                $address_to = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $this->my_platform_id, $address_to_object_id, 'api_code', $location_id,["name","api_id","description"]);
                                if($address_to) {
                                  $intacct_customer_code = @$address_to->api_id;
                                    $customer_name = @$address_to->name;

                                    $description = @$address_to->description ? json_decode($address_to->description,true) : '';
                                    if($description){
                                        $price_list_name = $description['price_list'];
                                    }
                                 }  else {
                                    $result_customer = PlatformCustomer::select(['platform_customer.customer_name', 'platform_customer.api_customer_code'])
                                    ->join("platform_customer_additional_information","platform_customer.id", "=", "platform_customer_additional_information.platform_customer_id")
                                    ->where(['platform_customer.user_id' => $user_id, 'platform_customer.user_integration_id' => $user_integration_id, 'platform_customer.platform_id' => $this->my_platform_id, 'platform_customer_additional_information.location_id' => $location_id])
                                    ->first();
                                    if($result_customer) {
                                        $intacct_customer_code = @$result_customer->api_customer_code;
                                      //  $customer_name = @$result_customer->customer_name;
                                       //Get Contact By Customer
                                       $intacct_contact=$this->IntacctGetCustomerDetailById($user_id,$user_integration_id,$intacct_customer_code);
                                       if(!empty($intacct_contact)) {
                                           $customer_name = htmlspecialchars($intacct_contact, ENT_XML1);
                                       }
                                    }
                                 }
                                }

                            }else{

                                if($customer_name=='' && $customer_email!=''){
                                    $result_customer =  $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id,'email'=>$customer_email]);
                                    $customer_name = @$result_customer->customer_name;
                                }
                            }



                            if($intacct_customer_code){



                                $crt_date_y = date('Y',strtotime($order_date));
                                $crt_date_m = date('m',strtotime($order_date));
                                $crt_date_d = date('d',strtotime($order_date));


                                $due_date_y = date('Y',strtotime($order_date. ' + '.$due_days.' days'));
                                $due_date_m = date('m',strtotime($order_date. ' + '.$due_days.' days'));
                                $due_date_d = date('d',strtotime($order_date. ' + '.$due_days.' days'));

                                $crt_date_y = date('Y',strtotime($order_date));

                                $line_items = $itemids = $itemidswithprice = $itemidswithunitweight = $item_price = [];
                                $ct_linked_products = 0;

                                $result_order_line = $this->mobj->getResultByConditions('platform_order_line',['platform_order_id'=>$id],['api_product_id','sku','upc','mpn','gtin','ean','unit_price','qty','price','uom']);



                                if(count($result_order_line) > 0){

                                    foreach($result_order_line as $rowline){

                                        $sku = $rowline->sku;
                                        $upc = $rowline->upc;
                                        $mpn = $rowline->mpn;
                                        $gtin = $rowline->gtin;
                                        $ean = $rowline->ean;

                                        $intacct_api_product_code = "";

                                        if($destination_row_data=='item_cross_reference'){

                                            $query ="<query>
                                                    <object>ITEMCROSSREF</object>
                                                    <select>
                                                    <field>ITEMID</field>
                                                    <field>REFTYPE</field>
                                                    </select>
                                                    <filter>
                                                    <equalto>
                                                        <field>ITEMALIASID</field>
                                                        <value>".${$source_row_data}."</value>
                                                    </equalto>
                                                    </filter>
                                                </query>";


                                            $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                                            if($response['api_status']=='success'){

                                                if(isset($response['operation']['result']['data']['ITEMCROSSREF']['ITEMID'])){
                                                    $intacct_api_product_code = @$response['operation']['result']['data']['ITEMCROSSREF']['ITEMID'];

                                                }else if(isset($response['operation']['result']['data']['ITEMCROSSREF'][0]['ITEMID'])){
                                                    $intacct_api_product_code = @$response['operation']['result']['data']['ITEMCROSSREF'][0]['ITEMID'];
                                                }

                                            }

                                        }


                                        //client requirement
                                        //$intacct_api_product_code = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->CustomItemExchange(['intacct_api_product_code'=>$intacct_api_product_code,'user_integration_id'=>$user_integration_id]);

                                        $whereupdateproduct = $update_product_data = [];
                                        $whereupdateproduct['user_id'] = $user_id;
                                        $whereupdateproduct['user_integration_id'] = $user_integration_id;
                                        $whereupdateproduct['platform_id'] = $this->my_platform_id;
                                        if($destination_row_data=='item_cross_reference' && $intacct_api_product_code!=''){
                                            $whereupdateproduct['api_product_code'] = $intacct_api_product_code;
                                        }else if($destination_row_data!='item_cross_reference' && $intacct_api_product_code==''){
                                            $whereupdateproduct[$destination_row_data] = ${$source_row_data};
                                        }

                                        $price = 0;
                                        $update_product_id = $uom = "";
                                        if(($destination_row_data=='item_cross_reference' && $intacct_api_product_code!='') || ($destination_row_data!='item_cross_reference' && $intacct_api_product_code=='')){

                                            $res_product =  $this->mobj->getFirstResultByConditions('platform_product', $whereupdateproduct,['api_product_code','uom','id','sku','upc','mpn','gtin','ean','price','weight']);


                                            if(isset($res_product->id)){
                                                $intacct_api_product_code = $res_product->api_product_code;

                                                //$intacct_api_product_code = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->CustomItemExchange(['intacct_api_product_code'=>$intacct_api_product_code,'user_integration_id'=>$user_integration_id]);


                                                $uom = $res_product->uom;
                                                $weight = @$res_product->weight ? $res_product->weight : 0;
                                                $update_product_id = $res_product->id;
                                                //$price = $res_product->price;

                                                if($sku!='' && $res_product->sku==null){
                                                    $update_product_data['sku'] = $sku;
                                                }
                                                if($upc!='' && $res_product->upc==null){
                                                    $update_product_data['upc'] = $upc;
                                                }
                                                if($mpn!='' && $res_product->mpn==null){
                                                    $update_product_data['mpn'] = $mpn;
                                                }
                                                if($gtin!='' && $res_product->gtin==null){
                                                    $update_product_data['gtin'] = $gtin;
                                                }
                                                if($ean!='' && $res_product->ean==null){
                                                    $update_product_data['ean'] = $ean;
                                                }

                                            }

                                            //file_put_contents('Bhoopendra.txt', "\r\n" . "user_integration_id : " . $user_integration_id  . "--> update_product_id : " . $update_product_id. " | update_product_data : " . json_encode($update_product_data, true), FILE_APPEND);

                                            if($update_product_id!='' && count($update_product_data) > 0){
                                                $this->mobj->makeUpdate('platform_product',$update_product_data,['id'=>$update_product_id]);
                                            }
                                        }



                                        if($intacct_api_product_code!=''){


                                            if($uom!='' && strpos(strtolower($uom), 'case of') !== false){
                                                $unit = $uom;
                                            }else{
                                                $unit = 'Case';
                                            }

                                            $itemids[] = $intacct_api_product_code;

                                            $itemidswithprice[$intacct_api_product_code] = $rowline->price;

                                            $itemidswithunitweight[$intacct_api_product_code]['uom'] = $uom;
                                            $itemidswithunitweight[$intacct_api_product_code]['weight'] = $weight;

                                            $line_items[$ct_linked_products]['itemid'] = $intacct_api_product_code;
                                            $line_items[$ct_linked_products]['price'] = $rowline->price;
                                            $line_items[$ct_linked_products]['unit'] = $unit;
                                            $line_items[$ct_linked_products]['qty'] = $rowline->qty;

											//ChargesAllowances
                                            $ChargesAllowances_amt=0;
                                            if(!empty($rowline->taxes)) {
                                            $ChargesAllowances=json_decode($rowline->taxes, true);
                                            foreach($ChargesAllowances as $ChargesAllowance) {
                                                if(isset($ChargesAllowance['AllowChrgAmt'])) {
                                                    $ChargesAllowances_amt+=$ChargesAllowance['AllowChrgAmt'];
                                                }
                                              }
                                             }
											 
                                            $ct_linked_products++;

                                        }

                                    }



                                    if(count($result_order_line)==$ct_linked_products){

                                        if($price_list_name!=''){
                                            $item_price = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->CustomPrice(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'price_list_name'=>$price_list_name,'itemids'=>array_unique($itemids),'itemidswithunitweight'=>$itemidswithunitweight]);
                                        }else{
                                            $item_price = $itemidswithprice;
                                        }


                                        $items = "";
                                        $is_item_price_found = 1;
                                        foreach($line_items as $line){

                                            if(isset($item_price[$line['itemid']])){

                                                $items.="<sotransitem>
                                                <itemid>".$line['itemid']."</itemid>";

                                                if($destination_row_data=='item_cross_reference'){
                                                    //$items.="<itemaliasid>".${$source_row_data}."</itemaliasid>";
                                                }

                                                $items.="<warehouseid>".$default_order_warehouse."</warehouseid>
                                                    <quantity>".$line['qty']."</quantity>
                                                    <unit>".$line['unit']."</unit>
                                                    <price>".$item_price[$line['itemid']]."</price>
                                                    <locationid>".$default_order_location."</locationid>
                                                </sotransitem>";

                                            }else{
                                                $is_item_price_found = 0;
                                            }

                                        }

										if($ChargesAllowances_amt>0 && !empty($charges_allowances_item))
                                        {
                                            $items.="<sotransitem>
                                            <itemid>".$charges_allowances_item."</itemid>
                                            <warehouseid>".$default_order_warehouse."</warehouseid>
                                                <quantity>1</quantity>
                                                <unit>Each</unit>
                                                <price>".$ChargesAllowances_amt."</price>
                                                <locationid>".$default_order_location."</locationid>
                                            </sotransitem>";
                                        }
										

                                        if($is_item_price_found==1){

                                            //$customer_id = $intacct_customer_code." -- ".$customer_name."*";
                                            //$customer_id = htmlspecialchars($customer_id, ENT_XML1);
                                            $customer_id = $intacct_customer_code;
											$customer_name = htmlspecialchars($customer_name, ENT_XML1);
                                            $body="<create_sotransaction>
                                                <transactiontype>$transactiontype</transactiontype>
                                                    <datecreated>
                                                        <year>$crt_date_y</year>
                                                        <month>$crt_date_m</month>
                                                        <day>$crt_date_d</day>
                                                    </datecreated>
                                                    <customerid>$customer_id</customerid>

                                                <datedue>
                                                    <year>$due_date_y</year>
                                                    <month>$due_date_m</month>
                                                    <day>$due_date_d</day>
                                                </datedue>
                                                <message>".htmlspecialchars($notes, ENT_XML1)."</message>
                                                <shipto>
                                                    <contactname>$customer_name</contactname>
                                                </shipto>
                                                <billto>
                                                    <contactname>$customer_name</contactname>
                                                </billto>
                                                <currency>$currency</currency>
                                                <exchratedate>
                                                    <year>$crt_date_y</year>
                                                    <month>$crt_date_m</month>
                                                    <day>$crt_date_d</day>
                                                </exchratedate>
                                                <exchratetype>$exchratetype</exchratetype>
                                                <customfields>
                                                    <customfield>
                                                                <customfieldname>REQ_DELY_DATE</customfieldname>
                                                                <customfieldvalue>$delivery_date</customfieldvalue>
                                                    </customfield>
                                                    <customfield>
                                                                <customfieldname>EDI_NOTES</customfieldname>
                                                                <customfieldvalue>".htmlspecialchars($notes, ENT_XML1)."</customfieldvalue>
                                                    </customfield>
                                                </customfields>
                                                <customerponumber>$order_reference</customerponumber>
                                                <sotransitems>
                                                    $items
                                                </sotransitems>

                                            </create_sotransaction>";



                                        // echo htmlspecialchars($body);


                                            $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$body);
                                        // echo "<pre>";
                                            //print_r($response);


                                            if($response['api_status']=='success'){
                                                if(isset($response['operation']['result']['key'])){
                                                    $key = $response['operation']['result']['key'];//Sales Order-SO1066
                                                    $arrres = explode('-',$key);
                                                    $sales_order_doc_number = str_replace($transactiontype.'-','',$key);



                                                    $api_order_id = $this->IntacctGetOrderIdUsingKey($user_id,$user_integration_id,$key,$transactiontype);


                                                    $arr_so_order = array();
                                                    $arr_so_order['user_id'] = $user_id;
                                                    $arr_so_order['platform_id'] = $this->my_platform_id;
                                                    $arr_so_order['user_integration_id'] = $user_integration_id;
                                                    $arr_so_order['api_order_id'] = $api_order_id;
                                                    $arr_so_order['trading_partner_id'] = $trading_partner_id;
                                                    $arr_so_order['order_type'] = 'SO';
                                                    $arr_so_order['api_order_reference'] = $order_reference;
                                                    $arr_so_order['order_number'] = $sales_order_doc_number;
                                                    $arr_so_order['vendor'] = $vendor;
                                                    $arr_so_order['sync_status'] = 'Pending';
                                                    $arr_so_order['linked_id'] = $id;
                                                    $arr_so_order['file_name'] = $file_name;

                                                    $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order',$arr_so_order);

                                                    $this->mobj->makeUpdate('platform_order',['sync_status'=>'Synced','order_updated_at'=>date('Y-m-d H:i:s'),'linked_id'=>$linked_platform_order_id],['id'=>$id]);

                                                    $sync_error = null;
                                                    $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'success',$id,$sync_error);


                                                    $return_response = true;
                                                }else{

                                                    $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);

                                                    $return_response = $sync_error = json_encode($response,true);

                                                    $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);


                                                }
                                            }else{

                                                $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);

                                                $return_response = $sync_error = $response['api_error'];

                                                $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                                            }

                                        }else{

                                            $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);

                                            $return_response = $sync_error = "Items price not found for some item.";
                                            $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                                        }



                                    }else{

                                        $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);

                                        $return_response = $sync_error = "Some items are missing.";
                                        $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                                    }

                                }else{

                                    $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);

                                    $return_response = $sync_error = "Order does not have any line items.";

                                    $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);


                                }

                            }else{

                                $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);

                                $return_response = $sync_error = "Invalid Location for customer.";

                                $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                            }

                        /*}else{

                            $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed'],['id'=>$id]);

                            $return_response = $sync_error = "Email Id not found.";

                            $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                        }*/
                    }


                }


            //}while($allow_next_call);


        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctCreateSalesOrder-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

		return $return_response;
    }

    public function IntacctGetOrderIdUsingKey($user_id,$user_integration_id,$key,$transactiontype)
    {

        $query ="<readByQuery>
            <object>SODOCUMENT</object>
            <fields>*</fields>
            <query>DOCID = '".$key."'</query>
            <pagesize>1</pagesize>
            <docparid>$transactiontype</docparid>
        </readByQuery>";

        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['sodocument'])){
                $row = $response['operation']['result']['data']['sodocument'];

                if(!is_array(@$row['RECORDNO']) && isset($row['RECORDNO'])){
                    return $row['RECORDNO'];
                }else{
                    return 0;
                }
            }
        }
    }

    public function IntacctGetOrderDetailById($user_id,$user_integration_id,$trading_partner_id='',$order_id='')
    {

        $transactiontype_map=$this->map->getMappedDataByName($user_integration_id, null, "default_sales_order_transaction_type", ['name']);
        $transactiontype= @$transactiontype_map->name ? $transactiontype_map->name : 'Sales Order';

        $query ="<read>
            <object>SODOCUMENT</object>
            <keys>".$order_id."</keys>
            <fields>*</fields>
            <docparid>".$transactiontype."</docparid>
        </read>";


        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);


        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['SODOCUMENT'])){
                $row = $response['operation']['result']['data']['SODOCUMENT'];

                if(!is_array(@$row['RECORDNO']) && isset($row['RECORDNO'])){

                    $platform_customer_id = 0;
                    $Email = "";
                    if(isset($row['CONTACT'])){

                        $Email = (!is_array(@$row['CONTACT']['EMAIL1'])) ? @$row['CONTACT']['EMAIL1'] : null;

                        $arr_customer = [];
                        $arr_customer['user_id'] = $user_id;
                        $arr_customer['platform_id'] = $this->my_platform_id;
                        $arr_customer['user_integration_id'] = $user_integration_id;
                        $arr_customer['api_customer_id'] = (!is_array(@$row['CUSTREC'])) ? @$row['CUSTREC'] : null;
                        $arr_customer['api_customer_code'] = (!is_array(@$row['CUSTVENDID'])) ? @$row['CUSTVENDID'] : null;
                        $arr_customer['customer_name'] = (!is_array(@$row['CUSTVENDNAME'])) ? @$row['CUSTVENDNAME'] : null;
                        $arr_customer['first_name'] = (!is_array(@$row['CONTACT']['FIRSTNAME'])) ? @$row['CONTACT']['FIRSTNAME'] : null;
                        $arr_customer['last_name'] = (!is_array(@$row['CONTACT']['LASTNAME'])) ? @$row['CONTACT']['LASTNAME'] : null;
                        $arr_customer['company_name'] = (!is_array(@$row['CONTACT']['COMPANYNAME'])) ? @$row['CONTACT']['COMPANYNAME'] : null;
                        $arr_customer['email'] = (!is_array(@$row['CONTACT']['EMAIL1'])) ? @$row['CONTACT']['EMAIL1'] : null;
                        $arr_customer['fax'] = (!is_array(@$row['CONTACT']['FAX'])) ? @$row['CONTACT']['FAX'] : null;
                        $arr_customer['phone'] = (!is_array(@$row['CONTACT']['PHONE1'])) ? @$row['CONTACT']['PHONE1'] : null;
                        $arr_customer['address1'] = (!is_array(@$row['CONTACT']['MAILADDRESS']['ADDRESS1'])) ? @$row['CONTACT']['MAILADDRESS']['ADDRESS1'] : null;
                        $arr_customer['address2'] = (!is_array(@$row['CONTACT']['MAILADDRESS']['ADDRESS2'])) ? @$row['CONTACT']['MAILADDRESS']['ADDRESS2'] : null;
                        //$arr_customer['city'] = (!is_array(@$row['CONTACT']['MAILADDRESS']['CITY'])) ? @$row['CONTACT']['MAILADDRESS']['CITY'] : null;
                        //$arr_customer['state'] = (!is_array(@$row['CONTACT']['MAILADDRESS']['STATE'])) ? @$row['CONTACT']['MAILADDRESS']['STATE'] : null;
                        //$arr_customer['postal_code'] = (!is_array(@$row['CONTACT']['MAILADDRESS']['ZIP'])) ? @$row['CONTACT']['MAILADDRESS']['ZIP'] : null;
                        $arr_customer['country'] = (!is_array(@$row['CONTACT']['MAILADDRESS']['COUNTRYCODE'])) ? @$row['CONTACT']['MAILADDRESS']['COUNTRYCODE'] : null; ////COUNTRY


                        $customer_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => $Email], ['id']);

                        if ($customer_details) {
                            $platform_customer_id = $customer_details->id;
                            $this->mobj->makeUpdate('platform_customer', $arr_customer, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => $Email]);
                        } else {
                            $arr_customer['sync_status'] = 'Pending';
                            $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                        }

                    }



                    $arr_order = array();
                    $arr_order['user_id'] = $user_id;
                    $arr_order['platform_id'] = $this->my_platform_id;
                    $arr_order['platform_customer_id'] = $platform_customer_id;
                    $arr_order['user_integration_id'] = $user_integration_id;
                    $arr_order['order_type'] = "SO";
                    $arr_order['customer_email'] = @$Email;
                    $arr_order['trading_partner_id'] = @$trading_partner_id;
                    $arr_order['api_order_id'] = @$order_id;
                    $arr_order['order_number'] = (!is_array(@$row['DOCNO'])) ? @$row['DOCNO'] : null;
                    $arr_order['api_order_reference'] = (!is_array(@$row['CUSTOMERPONUMBER'])) ? @$row['CUSTOMERPONUMBER'] : null;// (!is_array(@$row['PONUMBER'])) ? @$row['PONUMBER'] : null;
                    $arr_order['order_date'] = (!is_array(@$row['SO_DATE'])) ? @$row['SO_DATE'] : null;
                    $arr_order['delivery_date'] = (!is_array(@$row['REQ_DELY_DATE'])) ? @$row['REQ_DELY_DATE'] : null;
                    //$arr_order['department'] = (!is_array(@$row['CUSTREC'])) ? @$row['CUSTREC'] : null;
                    //$arr_order['vendor'] = (!is_array(@$row['CUSTREC'])) ? @$row['CUSTREC'] : null;
                    $arr_order['total_discount'] = 0;
                    $arr_order['total_tax'] = 0;
                    $arr_order['total_amount'] = (!is_array(@$row['TOTAL'])) ? @$row['TOTAL'] : 0;
                    $arr_order['notes'] = (!is_array(@$row['MESSAGE'])) ? @$row['MESSAGE'] : null;
                    $arr_order['currency'] = (!is_array(@$row['CURRENCY'])) ? @$row['CURRENCY'] : null;
                    $arr_order['shipping_method'] = (!is_array(@$row['SHIPVIA'])) ? @$row['SHIPVIA'] : null;


                    $po = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$order_id], ['id']);

                    if ($po) {
                        $platform_order_id = $po->id;
                        $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
                    } else {
                        $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                    }


                    $arr_order_address_billing = [];
                    $arr_order_address_billing['address_type'] = "billing";
                    $arr_order_address_billing['platform_order_id'] = $platform_order_id;
                    $arr_order_address_billing['address_name'] = (!is_array(@$row['BILLTO']['CONTACTNAME'])) ? @$row['BILLTO']['CONTACTNAME'] : null;
                    $arr_order_address_billing['firstname'] = (!is_array(@$row['BILLTO']['FIRSTNAME'])) ? @$row['BILLTO']['FIRSTNAME'] : null;
                    $arr_order_address_billing['lastname'] = (!is_array(@$row['BILLTO']['LASTNAME'])) ? @$row['BILLTO']['LASTNAME'] : null;
                    $arr_order_address_billing['company'] = (!is_array(@$row['BILLTO']['COMPANYNAME'])) ? @$row['BILLTO']['COMPANYNAME'] : null;
                    $arr_order_address_billing['email'] = (!is_array(@$row['BILLTO']['EMAIL1'])) ? @$row['BILLTO']['EMAIL1'] : null;
                    $arr_order_address_billing['phone_number'] = (!is_array(@$row['BILLTO']['PHONE1'])) ? @$row['BILLTO']['PHONE1'] : null;
                    $arr_order_address_billing['address1'] = (!is_array(@$row['BILLTO']['MAILADDRESS']['ADDRESS1'])) ? @$row['BILLTO']['MAILADDRESS']['ADDRESS1'] : null;
                    $arr_order_address_billing['address2'] = (!is_array(@$row['BILLTO']['MAILADDRESS']['ADDRESS2'])) ? @$row['BILLTO']['MAILADDRESS']['ADDRESS2'] : null;
                    $arr_order_address_billing['city'] = (!is_array(@$row['BILLTO']['MAILADDRESS']['CITY'])) ? @$row['BILLTO']['MAILADDRESS']['CITY'] : null;
                    $arr_order_address_billing['state'] = (!is_array(@$row['BILLTO']['MAILADDRESS']['STATE'])) ? @$row['BILLTO']['MAILADDRESS']['STATE'] : null;
                    $arr_order_address_billing['postal_code'] = (!is_array(@$row['BILLTO']['MAILADDRESS']['ZIP'])) ? @$row['BILLTO']['MAILADDRESS']['ZIP'] : null;
                    $arr_order_address_billing['country'] = (!is_array(@$row['BILLTO']['MAILADDRESS']['COUNTRYCODE'])) ? @$row['BILLTO']['MAILADDRESS']['COUNTRYCODE'] : null; //COUNTRY

                    $ct_address = $this->mobj->getCountsByConditions('platform_order_address',['platform_order_id'=>$platform_order_id,'address_type'=>"billing"]);

                    if($ct_address > 0){
                        $this->mobj->makeUpdate('platform_order_address',$arr_order_address_billing,['platform_order_id'=>$platform_order_id,'address_type'=>"billing"]);
                    }else{
                        $this->mobj->makeInsert('platform_order_address',$arr_order_address_billing);
                    }



                    $arr_order_address_shipping = [];
                    $arr_order_address_shipping['address_type'] = "billing";
                    $arr_order_address_shipping['platform_order_id'] = $platform_order_id;
                    $arr_order_address_shipping['address_name'] = (!is_array(@$row['SHIPTO']['CONTACTNAME'])) ? @$row['SHIPTO']['CONTACTNAME'] : null;
                    $arr_order_address_shipping['firstname'] = (!is_array(@$row['SHIPTO']['FIRSTNAME'])) ? @$row['SHIPTO']['FIRSTNAME'] : null;
                    $arr_order_address_shipping['lastname'] = (!is_array(@$row['SHIPTO']['LASTNAME'])) ? @$row['SHIPTO']['LASTNAME'] : null;
                    $arr_order_address_shipping['company'] = (!is_array(@$row['SHIPTO']['COMPANYNAME'])) ? @$row['SHIPTO']['COMPANYNAME'] : null;
                    $arr_order_address_shipping['email'] = (!is_array(@$row['SHIPTO']['EMAIL1'])) ? @$row['SHIPTO']['EMAIL1'] : null;
                    $arr_order_address_shipping['phone_number'] = (!is_array(@$row['SHIPTO']['PHONE1'])) ? @$row['SHIPTO']['PHONE1'] : null;
                    $arr_order_address_shipping['address1'] = (!is_array(@$row['SHIPTO']['MAILADDRESS']['ADDRESS1'])) ? @$row['SHIPTO']['MAILADDRESS']['ADDRESS1'] : null;
                    $arr_order_address_shipping['address2'] = (!is_array(@$row['SHIPTO']['MAILADDRESS']['ADDRESS2'])) ? @$row['SHIPTO']['MAILADDRESS']['ADDRESS2'] : null;
                    $arr_order_address_shipping['city'] = (!is_array(@$row['SHIPTO']['MAILADDRESS']['CITY'])) ? @$row['SHIPTO']['MAILADDRESS']['CITY'] : null;
                    $arr_order_address_shipping['state'] = (!is_array(@$row['SHIPTO']['MAILADDRESS']['STATE'])) ? @$row['SHIPTO']['MAILADDRESS']['STATE'] : null;
                    $arr_order_address_shipping['postal_code'] = (!is_array(@$row['SHIPTO']['MAILADDRESS']['ZIP'])) ? @$row['SHIPTO']['MAILADDRESS']['ZIP'] : null;
                    $arr_order_address_shipping['country'] = (!is_array(@$row['SHIPTO']['MAILADDRESS']['COUNTRYCODE'])) ? @$row['SHIPTO']['MAILADDRESS']['COUNTRYCODE'] : null; //COUNTRY

                    $ct_address = $this->mobj->getCountsByConditions('platform_order_address',['platform_order_id'=>$platform_order_id,'address_type'=>"shipping"]);

                    if($ct_address > 0){
                        $this->mobj->makeUpdate('platform_order_address',$arr_order_address_shipping,['platform_order_id'=>$platform_order_id,'address_type'=>"shipping"]);
                    }else{
                        $this->mobj->makeInsert('platform_order_address',$arr_order_address_shipping);
                    }


                    $sodocumententry = array();
                    if(isset($row['SODOCUMENTENTRIES']['sodocumententry']['RECORDNO'])){
                        $sodocumententry[0] = $row['SODOCUMENTENTRIES']['sodocumententry'];
                    }else{
                        $sodocumententry = $row['SODOCUMENTENTRIES']['sodocumententry'];
                    }


                    if(count($sodocumententry) > 0){
                        foreach($sodocumententry as $line){
                            $item_code = '';
                            if((!is_array(@$line['ITEMID']))){
                                $arritemid = explode('-',$line['ITEMID']);
                                $item_code = @$arritemid[0];
                            }


                            $unit_price = 0;
                            if(isset($line['UIPRICE']) && (!is_array(@$line['UIPRICE']))){
                                $unit_price = $line['UIPRICE'];
                            } else if(isset($line['PRICE']) && (!is_array(@$line['PRICE']))){
                                $unit_price = $line['PRICE'];
                            }


                            $pp = $this->mobj->getFirstResultByConditions('platform_product',['user_integration_id' => $user_integration_id,'platform_id' => $this->my_platform_id,'api_product_code' => $item_code], ['api_product_id']);


                            $arr_order_line = array();
                            $arr_order_line['platform_order_id'] = $platform_order_id;
                            $arr_order_line['api_order_line_id'] = (!is_array(@$line['RECORDNO'])) ? $line['RECORDNO'] : 0;
                            $arr_order_line['item_row_sequence'] = (!is_array(@$line['LINE_NO'])) ? $line['LINE_NO'] : 0;
                            $arr_order_line['api_product_id'] = @$pp->api_product_id;
                            $arr_order_line['product_name'] = (!is_array(@$line['ITEMNAME'])) ? $line['ITEMNAME'] : null;
                            $arr_order_line['qty'] = (!is_array(@$line['QUANTITY'])) ? $line['QUANTITY'] : 0;
                            $arr_order_line['price'] = (!is_array(@$line['PRICE'])) ? $line['PRICE'] : 0;
                            $arr_order_line['unit_price'] = $unit_price;
                            $arr_order_line['subtotal'] = (!is_array(@$line['TOTAL'])) ? $line['TOTAL'] : 0;
                            $arr_order_line['total'] = (!is_array(@$line['TOTAL'])) ? $line['TOTAL'] : 0;
                            $arr_order_line['description'] = (!is_array(@$line['EXTENDED_DESCRIPTION'])) ? $line['EXTENDED_DESCRIPTION'] : null;
                            $arr_order_line['uom'] = (!is_array(@$line['UNIT'])) ? $line['UNIT'] : null;
                            $arr_order_line['api_code'] = $item_code;
                            $arr_order_line['row_type'] = 'ITEM';



                            //$arr_order_line['sku'] = @$lineitem['OrderLine']['BuyerPartNumber'];//@$lineitem['OrderLine']['ProductID'][0]['PartNumber'];
                            //$arr_order_line['ean'] = @$lineitem['OrderLine']['EAN'];
                            //$arr_order_line['gtin'] = @$lineitem['OrderLine']['GTIN'];
                            //$arr_order_line['upc'] = @$lineitem['OrderLine']['UPCCaseCode'];
                            //$arr_order_line['mpn'] = @$lineitem['OrderLine']['VendorPartNumber'];



                            $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line',['platform_order_id'=>$platform_order_id,'api_order_line_id'=>@$line['RECORDNO']]);

                            if($ct_order_line > 0){
                                $this->mobj->makeUpdate('platform_order_line',$arr_order_line,['platform_order_id'=>$platform_order_id,'api_order_line_id'=>@$line['RECORDNO']]);
                            }else{
                                $this->mobj->makeInsert('platform_order_line',$arr_order_line);

                            }

                        }
                    }

                }

            }

        }

    }


     /* Get Warehouse */
    public function IntacctGetWarehouses($user_id,$user_integration_id,$is_initial_sync=0)
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $process_limit = 100;
            $offset = 0;

            $fields = ['RECORDNO','LOCATIONID','WAREHOUSEID','NAME','STATUS','WHENCREATED','WHENMODIFIED'];

            $select = '';
            foreach($fields as $field){
                $select.='<field>'.$field.'</field>';
            }


            $objects = $this->mobj->getFirstResultByConditions('platform_objects',['name' => "warehouse"], ['id']);

            if (isset($objects->id)) {
                $platform_object_id = $objects->id;

                //do{
                    $allow_next_call = false; // This flag will help for pagination


                    $query ="<query>
                        <object>WAREHOUSE</object>
                        <select>".$select."</select>
                        <pagesize>".$process_limit."</pagesize>
                        <offset>".$offset."</offset>
                    </query>";


                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                    if($response['api_status']=='success'){

                        if(isset($response['operation']['result']['data']['WAREHOUSE'])){

                            $warehouses = array();
                            if(isset($response['operation']['result']['data']['WAREHOUSE']['RECORDNO'])){
                                $warehouses[0] = $response['operation']['result']['data']['WAREHOUSE'];
                            }else{
                                $warehouses = $response['operation']['result']['data']['WAREHOUSE'];
                            }


                            // continue looping
                            if(count($warehouses)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }

                            if(count($warehouses) > 0){

                                foreach($warehouses as $row){

                                    $arr_warehouse = array();
                                    $arr_warehouse['user_id'] = $user_id;
                                    $arr_warehouse['platform_id'] = $this->my_platform_id;
                                    $arr_warehouse['user_integration_id'] = $user_integration_id;
                                    $arr_warehouse['platform_object_id'] = $platform_object_id;
                                    $arr_warehouse['api_id'] = $row['RECORDNO'];
                                    $arr_warehouse['name'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_warehouse['api_code'] = (!is_array(@$row['WAREHOUSEID'])) ? @$row['WAREHOUSEID'] : null;

                                    $ct = $this->mobj->getCountsByConditions('platform_object_data', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']]);

                                    if ($ct > 0) {
                                        $this->mobj->makeUpdate('platform_object_data', $arr_warehouse, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']]);
                                    } else {
                                    $this->mobj->makeInsertGetId('platform_object_data', $arr_warehouse);
                                    }

                                }
                            }

                        }


                    }else{
                        $return_response = $response['api_error'];
                    }


                //}while($allow_next_call);
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetWarehouses-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

         return $return_response;

    }

    /* Get Locations */
    public function IntacctGetLocations($user_id,$user_integration_id,$is_initial_sync=0)
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $process_limit = 100;
            $offset = 0;


            $fields = ['RECORDNO','LOCATIONID','NAME','STATUS','WHENCREATED','WHENMODIFIED'];

            $select = '';
            foreach($fields as $field){
                $select.='<field>'.$field.'</field>';
            }


            $objects = $this->mobj->getFirstResultByConditions('platform_objects',['name' => "location"], ['id']);

            if (isset($objects->id)) {
                $platform_object_id = $objects->id;

                //do{
                    $allow_next_call = false; // This flag will help for pagination


                    $query ="<query>
                        <object>LOCATION</object>
                        <select>".$select."</select>
                        <pagesize>".$process_limit."</pagesize>
                        <offset>".$offset."</offset>
                    </query>";


                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                    if($response['api_status']=='success'){
                        if(isset($response['operation']['result']['data']['LOCATION'])){

                            $locations = array();
                            if(isset($response['operation']['result']['data']['LOCATION']['RECORDNO'])){
                                $locations[0] = $response['operation']['result']['data']['LOCATION'];
                            }else{
                                $locations = $response['operation']['result']['data']['LOCATION'];
                            }


                            // continue looping
                            if(count($locations)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }
                            if(count($locations) > 0){

                                foreach($locations as $row){

                                    $arr_location = array();
                                    $arr_location['user_id'] = $user_id;
                                    $arr_location['platform_id'] = $this->my_platform_id;
                                    $arr_location['user_integration_id'] = $user_integration_id;
                                    $arr_location['platform_object_id'] = $platform_object_id;
                                    $arr_location['api_id'] = $row['RECORDNO'];
                                    $arr_location['name'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_location['api_code'] = (!is_array(@$row['LOCATIONID'])) ? @$row['LOCATIONID'] : null;

                                    $ct = $this->mobj->getCountsByConditions('platform_object_data', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']]);

                                    if ($ct > 0) {
                                        $this->mobj->makeUpdate('platform_object_data', $arr_location, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']]);
                                    } else {
                                    $this->mobj->makeInsertGetId('platform_object_data', $arr_location);
                                    }

                                }
                            }

                        }


                    }else{
                        $return_response = $response['api_error'];
                    }



                //}while($allow_next_call);
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetLocations-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;

    }


    public function __IntacctGetAllCustomers($user_id,$user_integration_id,$is_initial_sync=0)
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try{
            $process_limit = 100;
            $offset = 0;

            if($is_initial_sync==0){
                $res = DB::table('platform_customer')->select('api_updated_at')->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                $whencreated = date('m/d/Y H:i:s');
                if(isset($res->api_updated_at) && $res->api_updated_at!=''){
                    $whencreated = date('m/d/Y H:i:s',strtotime($res->api_updated_at));
                }
            }




            //do{
                $allow_next_call = false; // This flag will help for pagination




                $select = '<field>RECORDNO</field><field>WHENCREATED</field>';
                $query ="<query>
                        <object>CUSTOMER</object>
                        <select>".$select."</select>";

                if($is_initial_sync==0){
                $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENCREATED</field>
                                <value>".$whencreated."</value>
                            </greaterthanorequalto>
                        </filter>";
                }
                $query.="<orderby>
                        <order>
                            <field>WHENCREATED</field>
                            <ascending />
                        </order>
                    </orderby>";

                $query.="<pagesize>".$process_limit."</pagesize>
                            <offset>".$offset."</offset>
                        </query>";

                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
                $customerids = array();
                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['CUSTOMER'])){
                        $customers = array();
                        if(isset($response['operation']['result']['data']['CUSTOMER']['RECORDNO'])){
                            $customers[0] = $response['operation']['result']['data']['CUSTOMER'];
                        }else{
                            $customers = $response['operation']['result']['data']['CUSTOMER'];
                        }

                        foreach($customers as $customerid){
                            $customerids[] = $customerid['RECORDNO'];
                        }
                    }
                }

                if(count($customerids) > 0){

                    $query ='<read>
                    <object>CUSTOMER</object>
                    <keys>'.implode(',',$customerids).'</keys>
                    <fields>*</fields>
                    </read>';


                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);



                    if($response['api_status']=='success'){
                        if(isset($response['operation']['result']['data']['CUSTOMER'])){
                            $customers = array();
                            if(isset($response['operation']['result']['data']['CUSTOMER']['RECORDNO'])){
                                $customers[0] = $response['operation']['result']['data']['CUSTOMER'];
                            }else{
                                $customers = $response['operation']['result']['data']['CUSTOMER'];
                            }


                            // continue looping
                            if(count($customers)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }

                            if(count($customers) > 0){

                                foreach($customers as $row){

                                    $arr_customer = array();
                                    $arr_customer['user_id'] = $user_id;
                                    $arr_customer['platform_id'] = $this->my_platform_id;
                                    $arr_customer['user_integration_id'] = $user_integration_id;
                                    $arr_customer['api_customer_id'] = $row['RECORDNO'];
                                    $arr_customer['api_customer_code'] = (!is_array(@$row['CUSTOMERID'])) ? @$row['CUSTOMERID'] : null;
                                    $arr_customer['customer_name'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_customer['first_name'] = (!is_array(@$row['DISPLAYCONTACT']['FIRSTNAME'])) ? @$row['DISPLAYCONTACT']['FIRSTNAME'] : null;
                                    $arr_customer['last_name'] = (!is_array(@$row['DISPLAYCONTACT']['LASTNAME'])) ? @$row['DISPLAYCONTACT']['LASTNAME'] : null;
                                    $arr_customer['phone'] = (!is_array(@$row['[DISPLAYCONTACT']['PHONE1'])) ? @$row['[DISPLAYCONTACT']['PHONE1'] : null;
                                    $arr_customer['fax'] = (!is_array(@$row['DISPLAYCONTACT']['FAX'])) ? @$row['DISPLAYCONTACT']['FAX'] : null;
                                    $arr_customer['email'] = (!is_array(@$row['DISPLAYCONTACT']['EMAIL1'])) ? @$row['DISPLAYCONTACT']['EMAIL1'] : 0;
                                    //$arr_customer['api_created_at'] = (!is_array(@$row['WHENCREATED'])) ? @$row['WHENCREATED'] : null;
                                    $arr_customer['api_updated_at'] = (!is_array(@$row['WHENMODIFIED'])) ? @$row['WHENMODIFIED'] : null;
                                    $arr_customer['sync_status'] = 'Pending';


                                    $pc = PlatformCustomer::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $row['RECORDNO']])->select('id')->first();

                                    if ($pc) {
                                        $platform_customer_id = $pc->id;
                                        PlatformCustomer::where(['id' => $platform_customer_id])->update($arr_customer);
                                    } else {
                                        $platform_customer_id = PlatformCustomer::insertGetId($arr_customer);
                                    }


                                    $arr_customer_add_info = array();
                                    $arr_customer_add_info['platform_customer_id'] = $platform_customer_id;
                                    $arr_customer_add_info['location_id'] = (!is_array(@$row['DUNS_NUMBER'])) ? @$row['DUNS_NUMBER'] : null;;

                                    $pc_add_info = PlatformCustomerAdditionalInformation::where(['platform_customer_id' => $platform_customer_id])->select('id')->count();

                                    if ($pc_add_info > 0) {
                                        PlatformCustomerAdditionalInformation::where(['platform_customer_id' => $platform_customer_id])->update($arr_customer_add_info);
                                    } else {
                                        PlatformCustomerAdditionalInformation::insertGetId($arr_customer_add_info);
                                    }

                                }
                            }

                        }

                    }else{
                        $return_response = $response['api_error'];
                    }

                }


            //}while($allow_next_call);

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetAllCustomers-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;

    }

    /**
     *
     */
    public function IntacctGetAllCustomers( $user_id, $user_integration_id, $is_initial_sync=0 )
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try{
            $offset = 0;
            $pagesize = 100;
            $limit = [];

            if ($is_initial_sync) {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->my_platform_id,
                    'url_name' => 'customer_limit'
                ],
                ['url', 'id']);

                if ($limit) {
                    $offset = $limit->url;
                }
            }

            if($is_initial_sync==0){
                $res = DB::table('platform_customer')->select('api_updated_at')->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                $whencreated = date('m/d/Y H:i:s');
                if(isset($res->api_updated_at) && $res->api_updated_at!=''){
                    $whencreated = date('m/d/Y H:i:s',strtotime($res->api_updated_at));
                }
            }

            //do{
                $allow_next_call = false; // This flag will help for pagination

                $select = '<field>RECORDNO</field><field>WHENCREATED</field>';
                $query ="<query>
                        <object>CUSTOMER</object>
                        <select>".$select."</select>";

                if($is_initial_sync==0){
                    $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENCREATED</field>
                                <value>".$whencreated."</value>
                            </greaterthanorequalto>
                        </filter>";
                }
                $query.="<orderby>
                        <order>
                            <field>WHENCREATED</field>
                            <ascending />
                        </order>
                    </orderby>";

                $query.="<pagesize>".$pagesize ."</pagesize>
                            <offset>".$offset."</offset>
                        </query>";

                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                $customerids = array();
                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['CUSTOMER'])){
                        $customers = array();
                        if(isset($response['operation']['result']['data']['CUSTOMER']['RECORDNO'])){
                            $customers[0] = $response['operation']['result']['data']['CUSTOMER'];
                        }else{
                            $customers = $response['operation']['result']['data']['CUSTOMER'];
                        }

                        foreach($customers as $customerid){
                            $customerids[] = $customerid['RECORDNO'];
                        }
                    }
                } else{//added by @gk
                    $return_response = $response['api_error'];
                }

                if(count($customerids) > 0){
                    $query ='<read>
                    <object>CUSTOMER</object>
                    <keys>'.implode(',',$customerids).'</keys>
                    <fields>*</fields>
                    </read>';

                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                    if($response['api_status'] == 'success'){
                        if(isset($response['operation']['result']['data']['CUSTOMER'])){
                            $customers = array();
                            if(isset($response['operation']['result']['data']['CUSTOMER']['RECORDNO'])){
                                $customers[0] = $response['operation']['result']['data']['CUSTOMER'];
                            }else{
                                $customers = $response['operation']['result']['data']['CUSTOMER'];
                            }

                            if(count($customers) > 0){
                                foreach($customers as $row){
                                    $arr_customer = array();
                                    $arr_customer['user_id'] = $user_id;
                                    $arr_customer['platform_id'] = $this->my_platform_id;
                                    $arr_customer['user_integration_id'] = $user_integration_id;
                                    $arr_customer['api_customer_id'] = $row['RECORDNO'];
                                    $arr_customer['api_customer_code'] = $row['CUSTOMERID'] ?? null;
                                    $arr_customer['customer_name'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_customer['first_name'] = (!is_array(@$row['DISPLAYCONTACT']['FIRSTNAME'])) ? @$row['DISPLAYCONTACT']['FIRSTNAME'] : null;
                                    $arr_customer['last_name'] = (!is_array(@$row['DISPLAYCONTACT']['LASTNAME'])) ? @$row['DISPLAYCONTACT']['LASTNAME'] : null;
                                    $arr_customer['phone'] = (!is_array(@$row['[DISPLAYCONTACT']['PHONE1'])) ? @$row['[DISPLAYCONTACT']['PHONE1'] : null;
                                    $arr_customer['fax'] = (!is_array(@$row['DISPLAYCONTACT']['FAX'])) ? @$row['DISPLAYCONTACT']['FAX'] : null;
                                    $arr_customer['email'] = (!is_array(@$row['DISPLAYCONTACT']['EMAIL1'])) ? @$row['DISPLAYCONTACT']['EMAIL1'] : '-';
                                    $arr_customer['api_updated_at'] = (!is_array(@$row['WHENMODIFIED'])) ? @$row['WHENMODIFIED'] : null;

                                    $arr_customer['account_status'] = $row['DISPLAYCONTACT']['STATUS'] ?? 'inactive';//added by @GK
                                    $arr_customer['is_deleted'] = ( $arr_customer['account_status'] === "active" ) ? 0 : 1;

                                    $address1 = (!is_array( $row['DISPLAYCONTACT']['MAILADDRESS']['ADDRESS1'] ) ) ? $row['DISPLAYCONTACT']['MAILADDRESS']['ADDRESS1'] : '';
                                    $address2 = (!is_array( $row['DISPLAYCONTACT']['MAILADDRESS']['ADDRESS2'] ) ) ? $row['DISPLAYCONTACT']['MAILADDRESS']['ADDRESS2'] : '';
                                    $arr_customer['address1'] = $address1.", ".$address2;//added by @GK
                                    $arr_customer['address2'] = (!is_array( @$row['DISPLAYCONTACT']['MAILADDRESS']['CITY'] ) ) ? @$row['DISPLAYCONTACT']['MAILADDRESS']['CITY'] : '';//added by @GK
                                    $arr_customer['address3'] = (!is_array( @$row['DISPLAYCONTACT']['MAILADDRESS']['STATE'] ) ) ? @$row['DISPLAYCONTACT']['MAILADDRESS']['STATE'] : '';//added by @GK
                                    $arr_customer['postal_addresses'] = (!is_array( $row['DISPLAYCONTACT']['MAILADDRESS']['ZIP'] ) ) ? $row['DISPLAYCONTACT']['MAILADDRESS']['ZIP'] : '';//added by @GK
                                    $pc = PlatformCustomer::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $row['RECORDNO']])->select('id')->first();

                                    if ($pc) {
                                        $platform_customer_id = $pc->id;
                                        if( $pc->api_updated_at != $arr_customer['api_updated_at'] ){
                                            $arr_customer['sync_status'] = 'Ready';
                                        }
                                        PlatformCustomer::where(['id' => $platform_customer_id])->update($arr_customer);
                                    } else {
                                        $arr_customer['sync_status'] = 'Ready';//Pending -> Ready @GK
                                        $platform_customer_id = PlatformCustomer::insertGetId($arr_customer);
                                    }

                                    $arr_customer_add_info = array();
                                    $arr_customer_add_info['platform_customer_id'] = $platform_customer_id;
                                    $arr_customer_add_info['location_id'] = (!is_array(@$row['DUNS_NUMBER'])) ? @$row['DUNS_NUMBER'] : null;;

                                    $pc_add_info = PlatformCustomerAdditionalInformation::where(['platform_customer_id' => $platform_customer_id])->select('id')->count();

                                    if ($pc_add_info > 0) {
                                        PlatformCustomerAdditionalInformation::where(['platform_customer_id' => $platform_customer_id])->update($arr_customer_add_info);
                                    } else {
                                        PlatformCustomerAdditionalInformation::insertGetId($arr_customer_add_info);
                                    }
                                }

                                if ($is_initial_sync) {////added by @GK
                                    $return_response = 'data Remaining';
                                }
                            }
                        }
                    }else{
                        $return_response = $response['api_error'];
                    }
                }

                if( $is_initial_sync ){//added by @GK
                    if ($limit) {
                        $this->mobj->makeUpdate('platform_urls', ['url' => ( $offset + $pagesize )], ['id' => $limit->id]);
                    } else {
                        $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => ( $offset + $pagesize ), 'url_name' => 'customer_limit']);
                    }
                }
            //}while($allow_next_call);
        } catch (\Exception $e) {
            Log::error($user_integration_id."--IntacctGetAllCustomers-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    public function IntacctGetAllProducts($user_id,$user_integration_id,$is_initial_sync=0){
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $process_limit = 30;
            $offset = 0;

            if($is_initial_sync==0){
                $res = DB::table('platform_product')->select('api_updated_at')->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderBy("api_updated_at","DESC")->first();
                //->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")

                //$whenmodified = date('m/d/Y H:i:s');
                $whenmodified = date('m/d/Y');
                if(isset($res->api_updated_at) && $res->api_updated_at!=''){
                    $whenmodified = date('m/d/Y H:i:s',strtotime($res->api_updated_at));
                }

            }



            //do{

                $allow_next_call = false; // This flag will help for pagination


                $select = '<field>RECORDNO</field><field>WHENMODIFIED</field>';
                $query ="<query>
                        <object>ITEM</object>
                        <select>".$select."</select>";

                if($is_initial_sync==0){
                $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENMODIFIED</field>
                                <value>".$whenmodified."</value>
                            </greaterthanorequalto>
                        </filter>";
                }
               $query.="<orderby>
                        <order>
                            <field>WHENMODIFIED</field>
                            <ascending />
                        </order>
                    </orderby>";


                $query.="<pagesize>".$process_limit."</pagesize>
                            <offset>".$offset."</offset>
                        </query>";
                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                $productids = array();
                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['ITEM'])){
                        $products = array();
                        if(isset($response['operation']['result']['data']['ITEM']['RECORDNO'])){
                            $products[0] = $response['operation']['result']['data']['ITEM'];
                        }else{
                            $products = $response['operation']['result']['data']['ITEM'];
                        }

                        foreach($products as $productid){
                            $productids[] = $productid['RECORDNO'];
                        }
                    }
                }

                if(count($productids) > 0){


                    $query ='<read>
                    <object>ITEM</object>
                    <keys>'.implode(',',$productids).'</keys>
                    <fields>*</fields>
                    </read>';

                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);


                    if($response['api_status']=='success'){

                        if(isset($response['operation']['result']['data']['ITEM'])){ //ITEM

                            $items = array();
                            if(isset($response['operation']['result']['data']['ITEM']['RECORDNO'])){
                                $items[0] = $response['operation']['result']['data']['ITEM'];
                            }else{
                                $items = $response['operation']['result']['data']['ITEM'];
                            }

                            // continue looping
                            if(count($items)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }

                            if(count($items) > 0){

                                foreach($items as $row){
                                    $upc = null;
                                    if(isset($row['EDI_CODE']) && (!is_array(@$row['EDI_CODE']))){
                                        $upc = $row['EDI_CODE'];
                                    } else if(isset($row['ITEM_UPC']) && (!is_array(@$row['ITEM_UPC']))){
                                        $upc = $row['ITEM_UPC'];
                                    }else if(isset($row['UPC']) && (!is_array(@$row['UPC']))){
                                        $upc = $row['UPC'];
                                    }else if(isset($row['UPC12']) && (!is_array(@$row['UPC12']))){
                                        $upc = $row['UPC12'];
                                    }



                                    $arr_item = array();
                                    $arr_item['api_product_code'] = (!is_array(@$row['ITEMID'])) ? @$row['ITEMID'] : null;
                                    $arr_item['product_name'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_item['description'] = (!is_array(@$row['EXTENDED_DESCRIPTION'])) ? @$row['EXTENDED_DESCRIPTION'] : null;

                                    $arr_item['ean'] = (!is_array(@$row['EAN13'])) ? @$row['EAN13'] : null;
                                    $arr_item['upc'] = $upc;
                                    $arr_item['sku'] = (!is_array(@$row['SKU'])) ? @$row['SKU'] : null;
                                    $arr_item['gtin'] = (!is_array(@$row['GTIN '])) ? @$row['GTIN '] : null;
                                    $arr_item['price'] = (!is_array(@$row['BASEPRICE'])) ? @$row['BASEPRICE'] : 0;
                                    //$arr_item['warehouse'] = (!is_array(@$row['DEFAULT_WAREHOUSE'])) ? @$row['DEFAULT_WAREHOUSE'] : null;
                                    $arr_item['api_updated_at'] = (!is_array(@$row['WHENMODIFIED'])) ? @$row['WHENMODIFIED'] : null;
                                    $arr_item['uom'] = (!is_array(@$row['BASEUOM'])) ? @$row['BASEUOM'] : null;
                                    $arr_item['stock_track'] = (!is_array(@$row['ITEMTYPE'])) ? ((@$row['ITEMTYPE']=='Inventory') ? 1 : 0) : 0;
                                    $arr_item['weight'] = (isset($row['SHIP_WEIGHT']) && (!is_array(@$row['SHIP_WEIGHT']))) ? @$row['SHIP_WEIGHT'] : null;
                                    $arr_item['product_sync_status'] = 'Pending';

                                    $arr_item['custom_fields'] = json_encode(['pack_size'=> (!is_array(@$row['PACK_SIZE'])) ? @$row['PACK_SIZE'] : 0,'pack_value'=> (!is_array(@$row['PACK_VALUE'])) ? @$row['PACK_VALUE'] : 0],true);


                                    $products = PlatformProduct::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_product_id' => $row['RECORDNO']])->select(['id'])->first();

                                    if ($products) {

                                        PlatformProduct::where(['id' => $products->id])->update($arr_item);
                                        /*unset($arr_item['api_product_code']);
                                        unset($arr_item['ean']);
                                        unset($arr_item['upc']);
                                        unset($arr_item['sku']);
                                        unset($arr_item['gtin']);
                                        unset($arr_item['price']);
                                        unset($arr_item['uom']);
                                        unset($arr_item['stock_track']);
                                        unset($arr_item['weight']);
                                        unset($arr_item['product_sync_status']);
                                        */
                                        //We need to resolve the error Deadlock getting after resolving we can use updates
                                        //[2022-02-03 12:31:09] prod.ERROR: 178--IntacctGetAllProducts-->SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction (SQL: update `platform_product` set `api_product_code` = 10835, `product_name` = RED ARROW 11016-275 SMOKE, `description` = ?, `ean` = ?, `upc` = ?, `sku` = ?, `price` = 0, `api_updated_at` = 02/03/2022 12:28:02, `uom` = Gallons, `stock_track` = 1, `product_sync_status` = Pending, `custom_fields` = {"pack_size":0}, `platform_product`.`updated_at` = 2022-02-03 12:31:09 where (`user_id` = 187 and `platform_id` = 3 and `user_integration_id` = 178 and `api_product_id` = 18))

                                        //PlatformProduct::where(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_product_id' => $row['RECORDNO']])->update($arr_item);

                                        /*try{
                                            DB::transaction(function () use ($user_id,$user_integration_id,$arr_item,$row) {
                                                PlatformProduct::where(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_product_id' => $row['RECORDNO']])->update($arr_item);
                                                    DB::commit();
                                            });
                                        }catch(\Exception $e){
                                            DB::rollBack();
                                            \Log::error($user_integration_id."--IntacctGetAllProducts-->".$e->getMessage());
                                        }*/

                                    } else {
                                        $arr_item['user_id'] = $user_id;
                                        $arr_item['platform_id'] = $this->my_platform_id;
                                        $arr_item['user_integration_id'] = $user_integration_id;
                                        $arr_item['api_product_id'] = $row['RECORDNO'];

                                        PlatformProduct::insert($arr_item);

                                    }

                                }
                            }

                        }


                    }else{
                        $return_response = $response['api_error'];
                    }

                }


            //}while($allow_next_call);

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetAllProducts-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
        /*
        $whenmodified = '05/13/2021 10:59:23';
        $item_query ="<readByQuery>
                        <object>ITEM</object>
                        <fields>*</fields>
                        <query>WHENMODIFIED >= '".$whenmodified."'</query>
                        <pagesize>10</pagesize>
                    </readByQuery>"; //[WHENMODIFIED] => 04/12/2021 10:19:43
                    */


    }

    public function __IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,$is_initial_sync){
        $this->mobj->AddMemory();
        $return_response = true;
        try{

            $process_limit = 30;
            $offset = 0;


            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,null,"trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;

            $CustomDataChargeAllowItem = $this->map->getMappedDataByName($user_integration_id,null,"charges_allowances_item", ['custom_data'], "default");
            $chargesandallowanceitem = @$CustomDataChargeAllowItem->custom_data;


            $sync_start_date = date('m/d/Y H:i:s');

            $getflowEvents = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);
            $sync_start_date_initial = '';
            if ($getflowEvents && $getflowEvents->sync_start_date) {
                $sync_start_date_initial = @$getflowEvents->sync_start_date ? date('m/d/Y H:i:s',strtotime(trim($getflowEvents->sync_start_date))) : date('m/d/Y H:i:s');
            }

            if ($is_initial_sync) {

                $sync_start_date = @$sync_start_date_initial ? $sync_start_date_initial : date('m/d/Y H:i:s');

            } else {
                //Get last fetched invoice's time
                $invdetail = DB::table('platform_invoice')->select('api_updated_at')->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderBy('api_updated_at', 'DESC')->first();
                //->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")

                if(isset($invdetail->api_updated_at) && $invdetail->api_updated_at!=''){
                    $sync_start_date = $invdetail->api_updated_at;
                }else{
                    $sync_start_date = @$sync_start_date_initial ? $sync_start_date_initial : date('m/d/Y H:i:s');
                }
            }
            $sync_start_date = date('m/d/Y H:i:s',strtotime($sync_start_date));
            //$sync_start_date = '02/07/2022 23:10:20';


            //do{

                $allow_next_call = false; // This flag will help for pagination



                $select = '<field>RECORDNO</field><field>WHENMODIFIED</field>';
                $query ="<query>
                        <object>SODOCUMENT</object>
                        <select>".$select."</select>";

                //if($is_initial_sync==0){
                $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENMODIFIED</field>
                                <value>".$sync_start_date."</value>
                            </greaterthanorequalto>
                        </filter>";
                //}
                $query.="<orderby>
                        <order>
                            <field>WHENMODIFIED</field>
                            <ascending />
                        </order>
                    </orderby>";


                $query.="<docparid>Sales Invoice</docparid>
                <pagesize>".$process_limit."</pagesize>
                            <offset>".$offset."</offset>
                        </query>";


                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
                $invoiceids = array();
                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['SODOCUMENT'])){
                        $sales_invoices = array();
                        if(isset($response['operation']['result']['data']['SODOCUMENT']['RECORDNO'])){
                            $sales_invoices[0] = $response['operation']['result']['data']['SODOCUMENT'];
                        }else{
                            $sales_invoices = $response['operation']['result']['data']['SODOCUMENT'];
                        }

                        foreach($sales_invoices as $invoiceid){
                            $invoiceids[] = $invoiceid['RECORDNO'];
                        }

                    }

                }

                if(count($invoiceids) > 0){


                    $AllowedInvoiceUpdates = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->AllowedInvoiceUpdates(['user_integration_id'=>$user_integration_id]);

                    $query ='<read>
                    <object>SODOCUMENT</object>
                    <keys>'.implode(',',$invoiceids).'</keys>
                    <fields>*</fields>
                    <docparid>Sales Invoice</docparid>
                    </read>';


                    //Note : intacct record number change by using differenct API like Object = ARINVOICE | Object = SODOCUMENT for invoice having different record number for same invoice

                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                    if($response['api_status']=='success'){
                        if(isset($response['operation']['result']['data']['SODOCUMENT'])){ //ARINVOICE
                            $invoice = array();
                            if(isset($response['operation']['result']['data']['SODOCUMENT']['RECORDNO'])){
                                $invoice[0] = $response['operation']['result']['data']['SODOCUMENT'];
                            }else{
                                $invoice = $response['operation']['result']['data']['SODOCUMENT'];
                            }
                            // continue looping
                            if(count($invoice)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }



                            foreach($invoice as $rowinv){
                                $sync_status = 'Ready';
                                $platform_order_id = 0;
                                $order_id = $order_trading_partner_id = '';

                                if(isset($rowinv['CREATEDFROM']) && !is_array($rowinv['CREATEDFROM'])){ //removed due to same po number for different orders  CUSTOMERPONUMBER

                                    $order_number = str_replace('Sales Order-','',$rowinv['CREATEDFROM']);

                                    // Maintain Order Status For Log
                                    $result_order =  $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'order_number' => $order_number],['id','api_order_id','trading_partner_id']);

                                    if($result_order){
                                        $platform_order_id = $result_order->id;
                                        $order_id = $result_order->api_order_id;
                                        $order_trading_partner_id = $result_order->trading_partner_id;
                                        $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => $sync_status], ['id' => $platform_order_id]);

                                        $this->IntacctGetOrderDetailById($user_id,$user_integration_id,$trading_partner_id,$order_id);
                                    }else{
                                        $sync_status = 'Pending';
                                    }
                                }else{
                                    $sync_status = 'Pending';
                                }




                                $arr_invoice = array();
                                $arr_invoice['user_id'] = $user_id;
                                $arr_invoice['platform_id'] = $this->my_platform_id;
                                $arr_invoice['user_integration_id'] = $user_integration_id;
                                $arr_invoice['platform_order_id'] = $platform_order_id;
                                $arr_invoice['trading_partner_id'] = $trading_partner_id;
                                $arr_invoice['api_invoice_id'] = $rowinv['RECORDNO'];
                                $arr_invoice['invoice_code'] = (!is_array(@$rowinv['DOCNO'])) ? @$rowinv['DOCNO'] : null;
                                $arr_invoice['invoice_state'] = (!is_array(@$rowinv['STATE'])) ? @$rowinv['STATE'] : null;
                                $arr_invoice['ref_number'] = (!is_array(@$rowinv['CUSTOMERPONUMBER'])) ? @$rowinv['CUSTOMERPONUMBER'] : null;
                                $arr_invoice['order_doc_number'] = (!is_array(@$rowinv['PONUMBER'])) ? @$rowinv['PONUMBER'] : null;

                                $arr_invoice['invoice_date'] = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;
                                $arr_invoice['gl_posting_date'] = (!is_array(@$rowinv['WHENPOSTED'])) ? @$rowinv['WHENPOSTED'] : null;
                                $arr_invoice['total_amt'] = (!is_array(@$rowinv['TOTALENTERED'])) ? @$rowinv['TOTALENTERED'] : 0;
                                $arr_invoice['total_paid_amt'] = (!is_array(@$rowinv['TOTALPAID'])) ? @$rowinv['TOTALPAID'] : 0;
                                $arr_invoice['api_created_at'] = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;
                                $arr_invoice['api_updated_at'] = (!is_array(@$rowinv['WHENMODIFIED'])) ? @$rowinv['WHENMODIFIED'] : null;
                                $arr_invoice['ship_date'] = (!is_array(@$rowinv['WHENDUE'])) ? @$rowinv['WHENDUE'] : null;
                                $arr_invoice['ship_via'] = (!is_array(@$rowinv['SHIPVIA'])) ? @$rowinv['SHIPVIA'] : null;
                                $arr_invoice['tracking_number'] = (!is_array(@$rowinv['TRACKINGNUMBER'])) ? @$rowinv['TRACKINGNUMBER'] : null;
                                $arr_invoice['ship_by_date'] = (!is_array(@$rowinv['SHIPBYDATE'])) ? @$rowinv['SHIPBYDATE'] : null;

                                    //$arr_invoice['state'] = (!is_array(@$row['DUNS_NUMBER'])) ? @$row['DUNS_NUMBER'] : null; // need to add column on db

                                $arr_invoice['customer_name'] = (!is_array(@$rowinv['CUSTVENDNAME'])) ? @$rowinv['CUSTVENDNAME'] : null;
                                $arr_invoice['message'] = (!is_array(@$rowinv['MESSAGE'])) ? @$rowinv['MESSAGE'] : null;
                                $arr_invoice['payment_terms'] = (!is_array(@$rowinv['TERM']['NAME'])) ? @$rowinv['TERM']['NAME'] : null;
                                //$arr_invoice['pay_date'] = (!is_array(@$rowinv['WHENPAID'])) ? @$rowinv['WHENPAID'] : null;
                                //$arr_invoice['due_days'] = (!is_array(@$rowinv['DUE_IN_DAYS'])) ? @$rowinv['DUE_IN_DAYS'] : 0;
                                //echo "WHENMODIFIED-->".$rowinv['RECORDNO']."-->".$rowinv['WHENMODIFIED']."<br/>";

                                $pi = PlatformInvoice::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_invoice_id' => $rowinv['RECORDNO']])->select('id','api_updated_at','created_at')->first();

                                if ($pi) {
                                    $platform_invoice_id = $pi->id;


                                    $AllowedInvoiceUpdatesBasedOnTime = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->AllowedInvoiceUpdatesBasedOnTime(['user_integration_id'=>$user_integration_id,'created_at'=>$pi->created_at]);

                                    \Storage::disk('local')->append('BhoopendraIntacct.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') .  " | userIntegrationId : " . $user_integration_id . " | platform_order_id : " . $platform_order_id . " | db api_updated_at : " . $pi->api_updated_at . " | api_updated_at : " . $arr_invoice['api_updated_at']. " | created_at : " . $pi->created_at . " | new_time : " . date("Y-m-d H:i:s", strtotime('+6 hours',strtotime($pi->created_at))) . " | arr_invoice : " . json_encode($arr_invoice,true));

                                    if($AllowedInvoiceUpdates && $AllowedInvoiceUpdatesBasedOnTime && $platform_order_id && $pi->api_updated_at!=$arr_invoice['api_updated_at']){
                                        $arr_invoice['sync_status'] = 'Ready';
                                    }
                                    PlatformInvoice::where(['id' => $platform_invoice_id])->update($arr_invoice);
                                } else {

                                    if($trading_partner_id!='' && $order_trading_partner_id==$trading_partner_id){
                                        $arr_invoice['sync_status'] = $sync_status;
                                    }else{
                                        $arr_invoice['sync_status'] = 'Pending';
                                    }


                                    $platform_invoice_id = PlatformInvoice::insertGetId($arr_invoice);
                                }

                                $total_qty = 0;

                                $sodocumententry = array();
                                if(isset($rowinv['SODOCUMENTENTRIES']['sodocumententry']['RECORDNO'])){
                                    $sodocumententry[0] = $rowinv['SODOCUMENTENTRIES']['sodocumententry'];
                                }else{
                                    $sodocumententry = $rowinv['SODOCUMENTENTRIES']['sodocumententry'];
                                }

                                if(count($sodocumententry)){
                                    foreach($sodocumententry as $line){
                                        $item_code = $api_product_id = "";
                                        if((!is_array(@$line['ITEMID']))!=''){
                                            $arritemid = explode('-',$line['ITEMID']);
                                            $item_code = @$arritemid[0];

                                            $pp = PlatformProduct::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_product_code' => $item_code])->select('api_product_id')->first();

                                            if ($pp) {
                                                $api_product_id = $pp->api_product_id;
                                            }

                                        }




                                        $unit_price = 0;
                                        if(isset($line['UIPRICE']) && (!is_array(@$line['UIPRICE']))){
                                            $unit_price = $line['UIPRICE'];
                                        } else if(isset($line['PRICE']) && (!is_array(@$line['PRICE']))){
                                            $unit_price = $line['PRICE'];
                                        }


                                        $shipped_qty = (isset($line['SHIPPED_QTY']) && !is_array(@$line['SHIPPED_QTY'])) ? $line['SHIPPED_QTY'] : 0;

                                        $qty = (!is_array(@$line['QUANTITY'])) ? $line['QUANTITY'] : 0;

                                        if($chargesandallowanceitem!=$item_code && isset($line['SHIPPED_QTY'])){
                                            $total_qty+=$shipped_qty;
                                        }else if($chargesandallowanceitem!=$item_code && isset($line['SHIPPED_QTY'])){
                                            $total_qty+=$qty;
                                        }


                                        $arr_line = array();
                                        $arr_line['platform_invoice_id'] = $platform_invoice_id;
                                        $arr_line['api_invoice_line_id'] = @$line['RECORDNO'];
                                        $arr_line['api_product_id'] = $api_product_id;
                                        $arr_line['product_name'] = (!is_array(@$line['ITEMNAME'])) ? $line['ITEMNAME'] : null;
                                        $arr_line['qty'] = $qty;
                                        $arr_line['shipped_qty'] = $shipped_qty;
                                        $arr_line['unit_price'] = $unit_price;
                                        $arr_line['price'] =(!is_array(@$line['PRICE'])) ? $line['PRICE'] : 0;
                                        $arr_line['uom'] = (!is_array(@$line['UNIT'])) ? $line['UNIT'] : null;
                                        $arr_line['description'] = (!is_array(@$line['EXTENDED_DESCRIPTION'])) ? $line['EXTENDED_DESCRIPTION'] : null;
                                        $arr_line['total'] = (!is_array(@$line['TOTAL'])) ? $line['TOTAL'] : 0;
                                        $arr_line['total_weight'] = (!is_array(@$line['TOTAL_WEIGHT'])) ? $line['TOTAL_WEIGHT'] : 0;

                                        $arr_line['api_code'] = $item_code;
                                        $arr_line['row_type'] = 'ITEM';

                                        //echo "<pre>";
                                        //print_r($arr_line);


                                        $pil = PlatformInvoiceLine::where(['platform_invoice_id' => $platform_invoice_id, 'api_invoice_line_id' => @$line['RECORDNO']])->select('id')->count();

                                        if ($pil > 0) {
                                            PlatformInvoiceLine::where(['platform_invoice_id' => $platform_invoice_id, 'api_invoice_line_id' => @$line['RECORDNO']])->update($arr_line);
                                        } else {
                                            PlatformInvoiceLine::insertGetId($arr_line);
                                        }

                                    }
                                }


                                PlatformInvoice::where(['id' => $platform_invoice_id])->update(['total_qty'=>$total_qty]);




                            }
                        }
                    }else{
                        $return_response = $response['api_error'];
                    }


                }


            //}while($allow_next_call);

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetInvoices-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;

    }

    /**
     *
     */
    public function IntacctGetInvoices( $user_id,$user_integration_id,$user_workflow_rule_id,$is_initial_sync, $destination_platform = '' ){
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $offset = 0;
            $pagesize = 50;
            $urlname = 'invoice_last_modified_time';


            if ($is_initial_sync) {
                $platform_urls_limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->my_platform_id,
                    'url_name' => 'order_invoice_limit'
                ],
                ['url', 'id']);

                if ($platform_urls_limit) {
                    $offset = $platform_urls_limit->url;
                }
            }

            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,null,"trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;

            $CustomDataChargeAllowItem = $this->map->getMappedDataByName($user_integration_id,null,"charges_allowances_item", ['custom_data'], "default");
            $chargesandallowanceitem = @$CustomDataChargeAllowItem->custom_data;

            $sync_start_date = date('m/d/Y H:i:s');

            $getflowEvents = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);

            $sync_start_date_initial = '';
            if ($getflowEvents && $getflowEvents->sync_start_date) {
                $sync_start_date_initial = @$getflowEvents->sync_start_date ? date('m/d/Y H:i:s',strtotime(trim($getflowEvents->sync_start_date))) : date('m/d/Y H:i:s');
            }

            if ($is_initial_sync) {
                $sync_start_date = @$sync_start_date_initial ? $sync_start_date_initial : date('m/d/Y H:i:s');
            } else {

                $url_modified = PlatformUrl::select('url', 'id', 'status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $urlname])->first();
                if (isset($url_modified->url) && $url_modified->url != '') {
                    $arrurl = explode('|', $url_modified->url);
                    $sync_start_date = $arrurl[0];
                    $offset = intval($arrurl[1]);

                }else{

                    //Get last fetched invoice's time
                    $invdetail = DB::table('platform_invoice')->select('api_updated_at')->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderBy('api_updated_at', 'DESC')->first();
                    //->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")

                    if(isset($invdetail->api_updated_at) && $invdetail->api_updated_at!=''){
                        $sync_start_date = $invdetail->api_updated_at;
                    }else{
                        $sync_start_date = @$sync_start_date_initial ? $sync_start_date_initial : date('m/d/Y H:i:s');
                    }

                }

            }
            $last_modified_date = $latest_modified_date = $sync_start_date;

            \Storage::disk('local')->append('Bhoopendra_Intacct_Missing_Invoice.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') . " | " . "user_integration_id : " . $user_integration_id  . "--> sync_start_date : " . $sync_start_date);


            $sync_start_date = date('m/d/Y H:i:s',strtotime($sync_start_date));

            //do{
                $allow_next_call = false; // This flag will help for pagination
                $select = '<field>RECORDNO</field><field>WHENMODIFIED</field>';
                $query ="<query>
                        <object>SODOCUMENT</object>
                        <select>".$select."</select>";

                /**
                 * added by @GK
                 * check payment status rules available(open/close/null)
                 */
                $paymentFilter = $this->helper->getObjectId('payment_status');//sorder_payment_filter
                $getAcceptPaymentStatus = $this->map->getMappedApiIdByObjectId($user_integration_id, $paymentFilter );
                if( strtolower( $getAcceptPaymentStatus ) == "open-partially" ) {
                    $query.="<filter>
                                <or>
                                    ".$this->getOpenFilterQuery()."
                                    ".$this->getPartialFilterQuery()."
                                </or>
                            </filter>";
                } elseif( strtolower( $getAcceptPaymentStatus ) == "open-partially-dropship" ) {
                    $query.="<filter>
                                <or>
                                    ".$this->getOpenFilterQuery()."
                                    ".$this->getPartialFilterQuery()."
                                </or>
                            </filter>";
                } elseif( strtolower( $getAcceptPaymentStatus ) == "open" ) {
                    $query.="<filter>
                                <or>
                                ".$this->getOpenFilterQuery()."
                                </or>
                            </filter>";
                } elseif( strtolower( $getAcceptPaymentStatus ) == "partially" ) {
                    $query.="<filter>
                                <or>
                                    ".$this->getPartialFilterQuery()."
                                </or>
                            </filter>";
                } elseif( strtolower( $getAcceptPaymentStatus ) == "dropship" ) {
                    $query.="<filter>
                                <or>
                                    ".$this->getDropshipFilterQuery()."
                                </or>
                            </filter>";
                } else if( strtolower( $getAcceptPaymentStatus ) == "close" ) {
                    $query.="<filter>
                                <equalto>
                                    <field>PAYMENTSTATUS</field>
                                    <value>Close</value>
                                </equalto>
                            </filter>";
                }


                $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENMODIFIED</field>
                                <value>".$sync_start_date."</value>
                            </greaterthanorequalto>
                        </filter>";


                $query.="<orderby>
                        <order>
                            <field>WHENMODIFIED</field>
                            <ascending />
                        </order>
                    </orderby>";

                    $transactiontype_map=$this->map->getMappedDataByName($user_integration_id, null, "default_sales_invioice_transaction_type", ['name']);
                    $transactiontype= @$transactiontype_map->name ? $transactiontype_map->name : 'Sales Invoice';
           
                $query.="<pagesize>".$pagesize."</pagesize>
                            <offset>".$offset."</offset>
                            <docparid>".$transactiontype."</docparid>
                        </query>";

                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
                $invoiceids = array();
                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['SODOCUMENT']) || isset($response['operation']['result']['data']['sodocument'] )){
                        $sales_invoices = array();
                        if(isset($response['operation']['result']['data']['SODOCUMENT']['RECORDNO'])  || isset($response['operation']['result']['data']['sodocument']['RECORDNO'] ) ){
                            $sales_invoices[0] = $response['operation']['result']['data']['SODOCUMENT'] ?? $response['operation']['result']['data']['sodocument'];
                        }else{
                            $sales_invoices = $response['operation']['result']['data']['SODOCUMENT'] ?? $response['operation']['result']['data']['sodocument'];
                        }

                        foreach($sales_invoices as $invoiceid){
                            $invoiceids[] = $invoiceid['RECORDNO'];
                        }
                    }
                }

                //added by @GK check dropship invoice available
                if( strtolower( $getAcceptPaymentStatus ) == "open-partially-dropship" || strtolower( $getAcceptPaymentStatus ) == "dropship" ){
                    $query = "<readByQuery>
                                <object>SODOCUMENT</object>
                                <fields>RECORDNO</fields>
                                <pagesize>50</pagesize>
                                <docparid>Drop Ship Invoice</docparid>
                            </readByQuery>";

                    $responseARPayment = $this->intacctapi->CallAPI( $user_id,$user_integration_id, $query );

                    if( isset( $responseARPayment ) && $responseARPayment['api_status']=='success'){
                        if(isset($responseARPayment['operation']['result']['data']['SODOCUMENT']) || isset($responseARPayment['operation']['result']['data']['sodocument'] )){
                            $sales_invoices = array();
                            if(isset($responseARPayment['operation']['result']['data']['SODOCUMENT']['RECORDNO']) || isset($responseARPayment['operation']['result']['data']['sodocument']['RECORDNO'] ) ){
                                $sales_invoices[0] = $responseARPayment['operation']['result']['data']['SODOCUMENT'] ?? $responseARPayment['operation']['result']['data']['sodocument'];
                            }else{
                                $sales_invoices = $responseARPayment['operation']['result']['data']['SODOCUMENT'] ?? $responseARPayment['operation']['result']['data']['sodocument'];
                            }

                            foreach($sales_invoices as $invoiceid){
                                $invoiceids[] = $invoiceid['RECORDNO'];
                            }
                        }
                    }
                }

                \Storage::disk('local')->append('Bhoopendra_Intacct_Missing_Invoice.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') . " | " . "user_integration_id : " . $user_integration_id  . "--> sync_start_date : " . $sync_start_date. " | invoiceids : " . json_encode($invoiceids, true));


                if(count($invoiceids) > 0){
                    $this->IntacctGetInvoicesStore($user_id,$user_integration_id,$invoiceids,$trading_partner_id,$chargesandallowanceitem,$url_modified,$urlname,$transactiontype,$sync_start_date,$offset,$pagesize);
                }else{
                    PlatformUrl::where(['id' => $url_modified->id])->update(['url' => null]);
                }

                if( $is_initial_sync ){//added by @GK
                    if ($platform_urls_limit) {
                        $this->mobj->makeUpdate('platform_urls', ['url' => ( $offset + $pagesize )], ['id' => $platform_urls_limit->id]);
                    } else {
                        $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => ( $offset + $pagesize ), 'url_name' => 'order_invoice_limit']);
                    }
                }
            //}while($allow_next_call);
        } catch (\Exception $e) {
            Log::error($user_integration_id."--IntacctGetInvoices-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     *
     */
    public function getOpenFilterQuery()
    {
        return '
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Open</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Sales Return Receiver</value> </equalto>
                </and>
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Open</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Sales Return</value> </equalto>
                </and>
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Open</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Credit Memo</value> </equalto>
                </and>
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Open</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Sales Invoice</value> </equalto>
                </and>';
    }

    /**
     *
     */
    public function getPartialFilterQuery()
    {
        return '
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Partially Paid</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Sales Return Receiver</value> </equalto>
                </and>
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Partially Paid</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Sales Return</value> </equalto>
                </and>
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Partially Paid</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Credit Memo</value> </equalto>
                </and>
                <and>
                    <equalto> <field>PAYMENTSTATUS</field> <value>Partially Paid</value> </equalto>
                    <equalto> <field>DOCPARID</field> <value>Sales Invoice</value> </equalto>
                </and>';
    }

    /**
     *
     */
    public function getDropshipFilterQuery()
    {
        return '<and>
                    <equalto> <field>DOCPARID</field> <value>Drop Ship Invoice</value> </equalto>
                </and>';
    }

    public function IntacctGetOrderById($user_id,$user_integration_id,$sync_status='Pending',$ref_number=null){
        $return_response = true;
        try{

            $fields = ['RECORDNO','DOCNO','STATE','WHENCREATED','AUWHENCREATED','WHENMODIFIED','CONTACT.CONTACTNAME','PROJECTNAME','BILLTO.CONTACTNAME','SHIPTO.CONTACTNAME','TERM.NAME','SHIPVIA','CITY','WHENDUE','CSTATE','PONUMBER','CUSTOMERPONUMBER','CZIP','COUNTRY','NEEDBYDATE','DONOTSHIPBEFOREDATE','TRACKINGNUMBER','SHIPBYDATE','DONOTSHIPAFTERDATE','SHIPPEDDATE','CANCELAFTERDATE','SERVICEDELIVERYDATE','TOTAL_ORDER_CASE_COUNT'];
            $select = '';
            foreach($fields as $field){
                $select.='<field>'.$field.'</field>';
            }


            $item_query ="<readByQuery>
                <object>SODOCUMENT</object>
                <fields>*</fields>
                <query>CUSTOMERPONUMBER = '".$ref_number."'</query>
                <pagesize>10</pagesize>
                <docparid>Sales Order</docparid>
            </readByQuery>";


            $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$item_query);


            if($response['api_status']=='success'){
                if(isset($response['operation']['result']['data']['sodocument'])){
                    $row = $response['operation']['result']['data']['sodocument'];

                    if(!is_array(@$row['CUSTOMERPONUMBER']) && isset($row['CUSTOMERPONUMBER'])){

                        $arr_order = array();
                        $arr_order['user_id'] = $user_id;
                        $arr_order['platform_id'] = $this->my_platform_id;
                        $arr_order['user_integration_id'] = $user_integration_id;
                        //$arr_order['api_invoice_id'] = $row['RECORDNO'];
                        //$arr_order['invoice_code'] = (!is_array(@$row['DOCNO'])) ? @$row['DOCNO'] : null;
                        $arr_order['order_state'] = (!is_array(@$row['STATE'])) ? @$row['STATE'] : null;
                        $arr_order['customer_name'] = (!is_array(@$row['CONTACT.CONTACTNAME'])) ? @$row['CONTACT.CONTACTNAME'] : null;

                        $arr_order['ref_number'] = (!is_array(@$row['CUSTOMERPONUMBER'])) ? @$row['CUSTOMERPONUMBER'] : null;
                        $arr_order['order_doc_number'] = (!is_array(@$row['PONUMBER'])) ? @$row['PONUMBER'] : null;
                        //$arr_order['message'] = (!is_array(@$row['DESCRIPTION'])) ? @$row['DESCRIPTION'] : null;
                        $arr_order['payment_terms'] = (!is_array(@$row['TERM.NAME'])) ? @$row['TERM.NAME'] : null;
                        $arr_order['invoice_date'] = (!is_array(@$row['WHENCREATED'])) ? @$row['WHENCREATED'] : null;
                        //$arr_order['gl_posting_date'] = (!is_array(@$row['WHENPOSTED'])) ? @$row['WHENPOSTED'] : null;
                        $arr_order['ship_date'] = (!is_array(@$row['WHENDUE'])) ? @$row['WHENDUE'] : null;
                        //$arr_order['pay_date'] = (!is_array(@$row['WHENPAID'])) ? @$row['WHENPAID'] : null;
                        $arr_order['total_amt'] = (!is_array(@$row['TOTAL'])) ? @$row['TOTAL'] : 0;
                        // $arr_order['total_paid_amt'] = (!is_array(@$row['TOTAL'])) ? @$row['TOTAL'] : 0;
                        $arr_order['ship_via'] = (!is_array(@$row['SHIPVIA'])) ? @$row['SHIPVIA'] : null;
                        $arr_order['city'] = (!is_array(@$row['CITY'])) ? @$row['CITY'] : null;
                        $arr_order['state'] = (!is_array(@$row['CSTATE'])) ? @$row['CSTATE'] : null;
                        $arr_order['zip'] = (!is_array(@$row['CZIP'])) ? @$row['CZIP'] : null;
                        $arr_order['country'] = (!is_array(@$row['COUNTRY'])) ? @$row['COUNTRY'] : null;
                        $arr_order['tracking_number'] = (!is_array(@$row['TRACKINGNUMBER'])) ? @$row['TRACKINGNUMBER'] : null;
                        $arr_order['ship_by_date'] = (!is_array(@$row['SHIPBYDATE'])) ? @$row['SHIPBYDATE'] : null;
                        $arr_order['total_qty'] = (!is_array(@$row['TOTAL_ORDER_CASE_COUNT'])) ? @$row['TOTAL_ORDER_CASE_COUNT'] : 0;
                        $arr_order['sync_status'] = $sync_status;



                        $ct_order = $this->mobj->getCountsByConditions('platform_invoice', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'ref_number' => $ref_number]);

                        if ($ct_order > 0) {
                            $this->mobj->makeUpdate('platform_invoice', $arr_order, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'ref_number' => $ref_number]);

                        } else {
                            //$this->mobj->makeInsertGetId('platform_invoice', $arr_order);
                        }

                    }


                }
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetOrderById-->".$e->getMessage());
            $return_response = $e->getMessage();
        }


    }

    public function IntacctGetInvoicesOld($user_id,$user_integration_id,$user_workflow_rule_id,$is_initial_sync){
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $sync_status = 'Ready';
            $process_limit = 30;
            $offset = 0;
            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,null,"trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;


            $fields = ['RECORDNO','RECORDID','STATE','CUSTOMERNAME','DOCNUMBER','DESCRIPTION','TERMNAME','WHENCREATED','WHENPOSTED','WHENDUE','WHENPAID','TOTALENTERED','TOTALPAID','AUWHENCREATED','WHENMODIFIED','DUE_IN_DAYS'];
            $select = '';
            foreach($fields as $field){
                $select.='<field>'.$field.'</field>';
            }


            $sync_start_date = date('m/d/Y H:i:s');

            $getflowEvents = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);
            $sync_start_date_initial = '';
            if ($getflowEvents && $getflowEvents->sync_start_date) {
                $sync_start_date_initial = @$getflowEvents->sync_start_date ? date('m/d/Y H:i:s',strtotime(trim($getflowEvents->sync_start_date))) : date('m/d/Y H:i:s');
            }

            if ($is_initial_sync) {

                $sync_start_date = @$sync_start_date_initial ? $sync_start_date_initial : date('m/d/Y H:i:s');

            } else {
                //Get last fetched invoice's time
                $invdetail = DB::table('platform_invoice')->select('api_updated_at')->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderBy('api_updated_at', 'DESC')->first();
                //->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")

                if(isset($invdetail->api_updated_at) && $invdetail->api_updated_at!=''){
                    $sync_start_date = $invdetail->api_updated_at;
                }else{
                    $sync_start_date = @$sync_start_date_initial ? $sync_start_date_initial : date('m/d/Y H:i:s');
                }
            }



            do{

                $allow_next_call = false; // This flag will help for pagination

                $query ="<query>
                    <object>ARINVOICE</object>
                    <select>".$select."</select>
                    <filter>
                        <greaterthanorequalto>
                            <field>AUWHENCREATED</field>
                            <value>".$sync_start_date."</value>
                        </greaterthanorequalto>
                    </filter>
                    <pagesize>".$process_limit."</pagesize>
                    <offset>".$offset."</offset>
                </query>";


                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);


                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['ARINVOICE'])){
                        $invoice = array();
                        if(isset($response['operation']['result']['data']['ARINVOICE']['RECORDNO'])){
                            $invoice[0] = $response['operation']['result']['data']['ARINVOICE'];
                        }else{
                            $invoice = $response['operation']['result']['data']['ARINVOICE'];
                        }
                        // continue looping
                        if(count($invoice)==$process_limit){
                            $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                            $offset+=$process_limit;
                        }

                        foreach($invoice as $row){

                            if(!is_array(@$row['DOCNUMBER']) && isset($row['DOCNUMBER'])){


                                // Maintain Order Status For Log
                                $result_order =  $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'order_number' => $row['DOCNUMBER']],['id']);
                                $platform_order_id = '';
                                if($result_order){
                                    $platform_order_id = $result_order->id;

                                    $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => $sync_status], ['id' => $platform_order_id]);

                                }


                                $arr_invoice = array();
                                $arr_invoice['user_id'] = $user_id;
                                $arr_invoice['platform_id'] = $this->my_platform_id;
                                $arr_invoice['user_integration_id'] = $user_integration_id;
                                $arr_invoice['platform_order_id'] = $platform_order_id;
                                $arr_invoice['trading_partner_id'] = $trading_partner_id;
                                $arr_invoice['api_invoice_id'] = $row['RECORDNO'];
                                $arr_invoice['invoice_code'] = (!is_array(@$row['RECORDID'])) ? @$row['RECORDID'] : null;
                                $arr_invoice['invoice_state'] = (!is_array(@$row['STATE'])) ? @$row['STATE'] : null;
                                $arr_invoice['customer_name'] = (!is_array(@$row['CUSTOMERNAME'])) ? @$row['CUSTOMERNAME'] : null;
                                $arr_invoice['ref_number'] = (!is_array(@$row['DOCNUMBER'])) ? @$row['DOCNUMBER'] : null;
                                $arr_invoice['message'] = (!is_array(@$row['DESCRIPTION'])) ? @$row['DESCRIPTION'] : null;
                                $arr_invoice['payment_terms'] = (!is_array(@$row['TERMNAME'])) ? @$row['TERMNAME'] : null;
                                $arr_invoice['invoice_date'] = (!is_array(@$row['WHENCREATED'])) ? @$row['WHENCREATED'] : null;
                                $arr_invoice['gl_posting_date'] = (!is_array(@$row['WHENPOSTED'])) ? @$row['WHENPOSTED'] : null;
                                //$arr_invoice['state'] = (!is_array(@$row['WHENDISCOUNT'])) ? @$row['WHENDISCOUNT'] : null;
                                //$arr_invoice['ship_date'] = (!is_array(@$row['WHENDUE'])) ? @$row['WHENDUE'] : null;
                                $arr_invoice['pay_date'] = (!is_array(@$row['WHENPAID'])) ? @$row['WHENPAID'] : null;
                                $arr_invoice['total_amt'] = (!is_array(@$row['TOTALENTERED'])) ? @$row['TOTALENTERED'] : 0;
                                $arr_invoice['total_paid_amt'] = (!is_array(@$row['TOTALPAID'])) ? @$row['TOTALPAID'] : 0;
                                $arr_invoice['api_created_at'] = (!is_array(@$row['AUWHENCREATED'])) ? @$row['AUWHENCREATED'] : null;
                                $arr_invoice['api_updated_at'] = (!is_array(@$row['WHENMODIFIED'])) ? @$row['WHENMODIFIED'] : null;
                                $arr_invoice['due_days'] = (!is_array(@$row['DUE_IN_DAYS'])) ? @$row['DUE_IN_DAYS'] : 0;
                                $arr_invoice['sync_status'] = $sync_status;



                                $ct_inv = $this->mobj->getCountsByConditions('platform_invoice', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'ref_number' => $row['DOCNUMBER']]);

                                if ($ct_inv > 0) {
                                    $this->mobj->makeUpdate('platform_invoice', $arr_invoice, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'ref_number' => $row['DOCNUMBER']]);

                                } else {
                                    $this->mobj->makeInsertGetId('platform_invoice', $arr_invoice);
                                }

                                // get half of order details for invoice record
                                $this->IntacctGetOrderById($user_id,$user_integration_id,$sync_status,$row['DOCNUMBER']);


                            }

                        }
                    }
                }else{
                    $return_response = $response['api_error'];
                }

            }while($allow_next_call);

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetInvoices-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get Terms */
    public function IntacctGetTerms($user_id,$user_integration_id,$is_initial_sync=0)
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $process_limit = 100;
            $offset = 0;




            $fields = ['RECORDNO','NAME','DESCRIPTION','DUEDATE','DISCDATE','WHENMODIFIED','DISCAMOUNT','DISCPERCAMN'];

            $select = '';
            foreach($fields as $field){
                $select.='<field>'.$field.'</field>';
            }

            $objects = PlatformObject::where(['name' => "terms"])->select('id')->first();

            if (isset($objects->id)) {
                $platform_object_id = $objects->id;

                do{
                    $allow_next_call = false; // This flag will help for pagination


                    $query ="<query>
                        <object>ARTERM</object>
                        <select>".$select."</select>
                        <pagesize>".$process_limit."</pagesize>
                        <offset>".$offset."</offset>
                    </query>";


                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                    if($response['api_status']=='success'){
                        if(isset($response['operation']['result']['data']['ARTERM'])){

                            $terms = array();
                            if(isset($response['operation']['result']['data']['ARTERM']['RECORDNO'])){
                                $terms[0] = $response['operation']['result']['data']['ARTERM'];
                            }else{
                                $terms = $response['operation']['result']['data']['ARTERM'];
                            }



                            // continue looping
                            if(count($terms)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }

                            //echo "<pre>";
                            //print_r($terms);
                            if(count($terms) > 0){

                                foreach($terms as $row){

                                    $arr_term = array();
                                    $arr_term['user_id'] = $user_id;
                                    $arr_term['platform_id'] = $this->my_platform_id;
                                    $arr_term['user_integration_id'] = $user_integration_id;
                                    $arr_term['platform_object_id'] = $platform_object_id;
                                    $arr_term['api_id'] = $row['RECORDNO'];
                                    $arr_term['name'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_term['api_code'] = (!is_array(@$row['NAME'])) ? @$row['NAME'] : null;
                                    $arr_term['description'] = (!is_array(@$row['DESCRIPTION'])) ? @$row['DESCRIPTION'] : null;

                                    $term_info = ['due_days'=> (!is_array(@$row['DUEDATE'])) ? @$row['DUEDATE'] : 0,'discount_days'=> (!is_array(@$row['DISCDATE'])) ? @$row['DISCDATE'] : 0,'discount_days'=> (!is_array(@$row['DISCDATE'])) ? @$row['DISCDATE'] : 0,'discount_amount'=> (!is_array(@$row['DISCAMOUNT'])) ? @$row['DISCAMOUNT'] : 0,'discount_type'=> (!is_array(@$row['DISCPERCAMN'])) ? @$row['DISCPERCAMN'] : ''];




                                    $pod = PlatformObjectData::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']])->select('id')->first();

                                    if ($pod) {
                                        $platform_object_data_id = $pod->id;
                                        PlatformObjectData::where(['id' => $platform_object_data_id])->update($arr_term);
                                    } else {
                                        $platform_object_data_id = PlatformObjectData::insertGetId($arr_term);
                                    }


                                    $arr_term_additional_info = array();
                                    $arr_term_additional_info['user_integration_id'] = $user_integration_id;
                                    $arr_term_additional_info['platform_object_data_id'] = $platform_object_data_id;
                                    $arr_term_additional_info['terms_info'] = json_encode($term_info,true);

                                    $pod_add_info = PlatformObjectDataAdditionalInformation::where(['user_integration_id' => $user_integration_id, 'platform_object_data_id' => $platform_object_data_id])->select('id')->count();

                                    if ($pod_add_info > 0) {
                                        PlatformObjectDataAdditionalInformation::where(['user_integration_id' => $user_integration_id, 'platform_object_data_id' => $platform_object_data_id])->update($arr_term_additional_info);
                                    } else {
                                       PlatformObjectDataAdditionalInformation::insertGetId($arr_term_additional_info);
                                    }

                                }
                            }

                        }


                    }else{
                        $return_response = $response['api_error'];
                    }



                }while($allow_next_call);
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetTerms-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;

    }

    /*
     * Scenario 1: Overpayment to be Written Off
     * This is indicated by two “T” line transactions and the 4030 G/L account code in the first “T” line.
     *
     * base on Shriti Ma'am
     * Step 1: Since this is an overpayment, but we want to pay the invoice in full, step 1 is create an Adjustment as a Debit Memo
     * Step 2: Select both the overpayment adjustment as well as the invoice
     * Step 3: Apply payment to the invoice in the journal file and the adjustment created in Step 1.
     * Question:
     *              H|AA3W12|4|4694.13
     *              P|C-10770|100USD|CHK_28/03/2022_Pon Foods||4694.13|CHK|28/03/2022|1000|168|15|Pon Foods|003264|USD||||||
     *              C|C-10770|100USD|CHK_28/03/2022_003264||4694.13|CHK|28/03/2022|1000|168|15|Pon Foods|003264||||||
     *              T|10724|10724-INV|20/02/2022|4694.09|0.00||INV|||||||||||||||||168|16
     *              A|POA|C-10770|100USD|CHK_28/03/2022_overpay||0.04|CHK|28/03/2022|1000|168|15|Pon Foods|003264|||||
     * Solution:
     *              All A passed by AR Adjustment with T receive new payment
     */
    public function scenario1( $user_id, $user_integration_id, $destination_platform, $customer_id, $invoiceno, $adjustmentAmount=0, $transactionAmount=0 ){

        $status = false;
        // Step 1: Since this is an overpayment, but we want to pay the invoice in full, step 1 is create an Adjustment as a Debit Memo
        $overpaymentKey = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $customer_id, $invoiceno, $adjustmentAmount );
        if( $overpaymentKey ) {
            $getAdjustment = true;//$this->getARAdjustmentDetail($user_id, $user_integration_id, $overpaymentKey);//, $customer_id, $invoiceno
            if( $getAdjustment ){
                $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount );
                if( $applyPayent ){
                    $status = true;
                    echo "<br>Apply transaction payment successfully.";
                }
            }
        }

        if( !$status ){
            echo "<br>Check the log file for more information.";
        }
    }

    /**
     * Scenario 2: One payment to one customer that includes a deduction to a different customer.
     * Step 1: Create Credit Memo adjustment for the “A” lines
     * Step 2: Create a Debit Memo and Credit Memo for the “A” line adjustment of the customer that does not have an invoice (“T” line) on the customer that does have an invoice (“T” line)
     * Step 3: Apply payment to the invoices and the Credit Memo adjustments created in Steps 1 and 2.
     *      a.	Select the invoices from the “T” lines and complete the top portion
     *      b.	Select the Credit Memo adjustments for C-10665 created in Steps 1 and 2 and apply payment.
     * Question:
     *          P|C-10664|100USD|CHK_21/03/2022_Associated (MS) Wholesale Groc||16280.04|CHK|21/03/2022|100|147|9|Associated (MS) Wholesale Groc|3140229|USD||||||
     *          C|C-10664|100USD|CHK_21/03/2022_3140229|418.70||CHK|21/03/2022|100|147|12|Associated (MS) Wholesale Groc|3140229||||||
     *          A|4050|C-10664|100USD|CHK_21/03/2022_deduction go to 2 customer accounta example|418.70||CHK|21/03/2022|1000|147|12|Associated (MS) Wholesale Groc|3140229||||||
     *          C|C-10665|100USD|CHK_21/03/2022_3140229||16698.74|CHK|21/03/2022|100|147|9|Associated (MS) Wholesale Groc|3140229||||||
     *          T|10715|10714-INV|18/02/2022|3399.00|0.00||INV|||||||||||||||||147|10
     *          T|10715|10715-INV|18/02/2022|14226.82|0.00||INV|||||||||||||||||147|11
     *          A|4030|C-10665|100USD|CHK_21/03/2022_deduction goes to 2 difference customers accounts|927.08||CHK|21/03/2022|1000|147|9|Associated (MS) Wholesale Groc|3140229|||||
     * Solution:
     *
     */
    public function scenario2( $user_id, $user_integration_id, $destination_platform ){
        $scenarioArr = [
            0 => "H|AA3W16|12|47637.78",
            1 => "P|C-10664|100USD|CHK_21/03/2022_Associated (MS) Wholesale Groc||16280.04|CHK|21/03/2022|1000|147|9|Associated (MS) Wholesale Groc|3140229|USD||||||",
            2 => "C|C-10383|100USD|ACHWIRE_24/06/2022_9991000065 TRN*1*8510199\ ID: 148192||161.09|ACHWIRE|24/06/2022|1000|209|620| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192||||||",
            3 => "A|OVERPAY|C-10383|100USD|ACHWIRE_24/06/2022_Manual remit - 15228-INV||161.09|ACHWIRE|24/06/2022|1000|209|620| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192|||||",
        ];

        $invoiceArr = [];
        $fileKey = -1;
        $paymentKey = -1;
        $customerKey = -1;
        foreach( $scenarioArr as $line ){
            if( $line != "" ){
                $data = explode( "|", $line );

                //H: Header
                if( $data['0'] == "H" ){
                    $fileKey++;
                    $invoiceArr[$fileKey]['header'] = $data;
                }

                // P: Payment
                if( $data['0'] == "P" && $fileKey >= 0 ){
                    $paymentKey++;
                    $customerKey = -1;
                    $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                }

                // C: Customers
                if( $data['0'] == "C" && $fileKey >= 0 ){
                    $customerKey++;
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey] = $data;
                }

                //T: Transaction Level/Open Invoice
                if( $data['0'] == "T" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                }

                //A: Individual Allocation
                if( $data['0'] == "A" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                }

                //L: Holding/Suspend
                if( $data['0'] == "L" && $customerKey >= 0){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                }
            }
        }

        $adjustmentKey = [];
        $useTransaction = false;
        $customer_id = $invoiceno = $transactionAmount = $overpaymentKey = $adjustmentAmount = 0;
        foreach($invoiceArr as $k=>$invArr){
            foreach( $invArr as $i=>$ar ){
                if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){
                    if( COUNT( $ar['customer'] ) == 2 //check total 2 customer available
                        && !isset( $ar['customer'][0]['transaction'] ) && isset( $ar['customer'][0]['allocation'] ) //check only 2nd customer available in allocation
                        && isset( $ar['customer'][1]['transaction'] ) && COUNT( $ar['customer'][1]['transaction'] ) == 2 //check 2nd customer available 2 transaction
                        && isset( $ar['customer'][1]['allocation'] ) // check 2nd customer available 1 allocation
                    ){
                        //Step 1: Create Credit Memo adjustment for the “A” lines without any transaction available
                        if( !isset( $ar['customer'][0]['transaction'] ) && isset( $ar['customer'][0]['allocation'] ) ){
                            $adjustmentKey[] = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][0]['allocation'][0][2], "", "-".$ar['customer'][0]['allocation'][0][5], $ar['customer'][0]['allocation'][0][2]." ".$ar['customer'][0]['allocation'][0][12] );
                        }

                        /**
                         * Step 2: Create a Debit Memo and Credit Memo for the “A” line adjustment of the customer that does not have an invoice (“T” line) on the customer that does have an invoice (“T” line)
                         * In this example, the adjustment for customer C-10664 in the amount of $418.70 should create two adjustments on customer C-10665. The Credit Memo for C-10665 will be used in the application of this payment.
                         * Please note: the Memo field should be formulated to equal customer C-10664 Name + Item Name.  In this example, Memo = “Associated (LA) Wholesale Groc – Ads”.
                         */
                        if( isset( $ar['customer'][1]['allocation'] ) ){
                            $adjustmentKey[] = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][1]['allocation'][0][2], "", "-".$ar['customer'][1]['allocation'][0][5], $ar['customer'][1]['allocation'][0][2]." ".$ar['customer'][1]['allocation'][0][12] );
                            echo "<br> Customer: ".$ar['customer'][1]['allocation'][0][2]." Allocation: ".$ar['customer'][1]['allocation'][0][5];
                            $adjustmentKey[] = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][1]['allocation'][0][2], "", "-".$ar['customer'][0]['allocation'][0][5], $ar['customer'][1]['allocation'][0][2]." ".$ar['customer'][0]['allocation'][0][12] );
                            echo "<br> Customer: ".$ar['customer'][1]['allocation'][0][2]." Allocation: ".$ar['customer'][0]['allocation'][0][5];
                        }

                        $useTransaction = true;

                    } else if( COUNT( $ar['customer'] ) == 1 //check total 1 customer available
                        && !isset( $ar['customer'][0]['transaction'] ) && isset( $ar['customer'][0]['allocation'] ) //check only 2nd customer available in allocation
                    ){
                        //Step 1: Create Credit Memo adjustment for the “A” lines without any transaction available
                        if( !isset( $ar['customer'][0]['transaction'] ) && isset( $ar['customer'][0]['allocation'] ) ){
                            $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][0]['allocation'][0][2], "", "-".$ar['customer'][0]['allocation'][0][5], $ar['customer'][0]['allocation'][0][2]." ".$ar['customer'][0]['allocation'][0][12] );
                        }
                    }

                    // $customer_id = $ar['customer'][0][2];
                    // $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount;
                }
            }
        }

        if(COUNT( $adjustmentKey ) > 0 ){
            // $getAdjustment = true;//$this->getARAdjustmentDetail($user_id, $user_integration_id, $overpaymentKey);//, $customer_id, $invoiceno
            // if( $getAdjustment ){
                $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount, false );
                if( $applyPayent ){
                    $status = true;
                    echo "<br>Apply transaction payment successfully.";
                }
            // }
        }
    }

    /**
     * Scenario 3: One payment to one customer with several different deductions.
     * Step 1: Create a Credit Memo adjustment with multiple lines for each “A” line
     * Step 2: Apply payment to the invoice in the journal file and the adjustment created in Step 1.
     * Question:
     *           P|C-10679|100USD|CHK_17/03/2022_C & S Wholesale||30870.11|CHK|17/03/2022|1000|138|3|C & S Wholesale|0007113171|USD||||||
     *           C|C-10319|100USD|ACHWIRE_22/06/2022_9991000065 ID: 148192*TRN*1*8498459\||526.62|ACHWIRE|22/06/2022|1000|224|426| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8498459\||||||
     *           T|14443|14443-INV|26/05/2022|200.00|0.00|Manual remit|INV|||||||||||||||||224|481
     *           T|14924|14924-INV|09/06/2022|333.96|0.00|Manual remit|INV|||||||||||||||||224|482
     *           A|4030|C-10319|100USD|ACHWIRE_22/06/2022_Manual remit|4.00||ACHWIRE|22/06/2022|1000|224|426| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8498459\|||||
     *           A|4030|C-10319|100USD|ACHWIRE_22/06/2022_Manual remit|3.34||ACHWIRE|22/06/2022|1000|224|427| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8498459\|||||
     * Solution:
     *           All A store with AR Adjustment module( Step 1: )
     */
    public function scenario3( $user_id, $user_integration_id, $destination_platform ){
        $scenarioArr = [
            0 => "H|AA3W15|6|1000.00",
            1 => "P|C-10679|100USD|CHK_28/06/2022_C & S Wholesale||30986.04|CHK|28/06/2022|1000|31|149|C & S Wholesale|0007139461|USD|06/21/22|||||",
            2 => "C|C-10396|100USD|ACHWIRE_24/06/2022_9991000065 TRN*1*8510199\ ID: 148192||2051.80|ACHWIRE|24/06/2022|1000|209|624| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192||||||",
            3 => "T|14543|14543-INV|30/05/2022|0.12|0.00|Manual remit|4030|||||||||||||||||209|650",
            4 => "T|14543|14543-INV|30/05/2022|2093.55|0.00|Manual remit|INV|||||||||||||||||209|651",
            5 => "A|4030|C-10396|100USD|ACHWIRE_24/06/2022_Manual remit|41.87||ACHWIRE|24/06/2022|1000|209|624| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192|||||",
        ];//For multiple T line acceptable


        $invoiceArr = [];
        $fileKey = -1;
        $paymentKey = -1;
        $customerKey = -1;
        foreach( $scenarioArr as $line ){
            if( $line != "" ){
                $data = explode( "|", $line );

                //H: Header
                if( $data['0'] == "H" ){
                    $fileKey++;
                    $invoiceArr[$fileKey]['header'] = $data;
                }

                // P: Payment
                if( $data['0'] == "P" && $fileKey >= 0 ){
                    $paymentKey++;
                    $customerKey = -1;
                    $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                }

                // C: Customers
                if( $data['0'] == "C" && $fileKey >= 0 ){
                    $customerKey++;
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey] = $data;
                }

                //T: Transaction Level/Open Invoice
                if( $data['0'] == "T" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                }

                //A: Individual Allocation
                if( $data['0'] == "A" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                }

                //L: Holding/Suspend
                if( $data['0'] == "L" && $customerKey >= 0){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                }
            }
        }

        $customer_id = $transactionAmount = $adjustmentAmount = $isAmount = 0;
        $overpaymentKey = $invoiceno = '';
        foreach($invoiceArr as $k=>$invArr){
            foreach( $invArr as $i=>$ar ){
                if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){
                    if( isset( $ar['customer'][0]['transaction'] ) && COUNT( $ar['customer'][0]['transaction'] ) >= 1 //check customer only 1 transaction available
                        && isset( $ar['customer'][0]['allocation'] ) && COUNT( $ar['customer'][0]['allocation'] ) >= 2 //check customer only 3 allocation available
                    ){
                        $lineitem = $memo = $account = [];
                        foreach( $ar['customer'][0]['allocation'] as $a=>$allocation ){
                                $isAmount = ( $allocation[5] != "" ) ? "-".$allocation[5] : $allocation[6];
                                $lineitem[] = $isAmount;
                                $mArr = explode( " ", trim( $allocation[4] ) );
                                $memo[] = trim( str_ireplace( $mArr[0], "", $allocation[4] ) );
                                $adjustmentAmount+= $isAmount;
                                $account[] = $ar['customer'][0]['allocation'][0][1];
                        }
                        $overpaymentKey = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][0][1], $ar['customer'][0]['transaction'][0][2], $adjustmentAmount, $memo, $lineitem, $account );
                        $invoiceno = $ar['customer'][0]['transaction'][1][2];
                        $transactionAmount = $ar['customer'][0]['transaction'][1][4];
                    }
                }
            }
        }

        //pass first T line data if Overpay exist in transaction
        $transactionAmountT = $invoiceArr[0][0]['customer'][0]['transaction'][0][4];
        $invoicenoT = $invoiceArr[0][0]['customer'][0]['transaction'][0][2];
        $customer_id = $invoiceArr[0][0]['customer'][0][1];
        if( isset( $invoiceArr[0][0]['customer'][0]['transaction'][0][7] ) && $invoiceArr[0][0]['customer'][0]['transaction'][0][7] === "OVERPAY" ){
            $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoicenoT, $transactionAmountT, '', 0, false, true );
        } else {
            $paymentMapping = [];
            $paymentMapping['payment_method'] = $invoiceArr[0][0]['customer'][0][6] ?? '';//CHK = Check, BAI = Record Transfer, ACHWIRE = "Credit Card
            $paymentMapping['receipt_date'] = $invoiceArr[0][0]['payment'][7];
            $paymentMapping['reference_no'] = $invoiceArr[0][0]['payment'][12];
            $paymentMapping['payment_date'] = $invoiceArr[0][0]['payment'][14];
            $applyPayent = $this->selectOverpaymentAdjustmentInvoicePaymentLegacy( $user_id, $user_integration_id, $customer_id, $invoicenoT, $transactionAmountT, '', 0, false, $paymentMapping );
        }

        if( $applyPayent ){
            if( $overpaymentKey ){
                $getAdjustment = true;//$this->getARAdjustmentDetail($user_id, $user_integration_id, $overpaymentKey);//, $customer_id, $invoiceno
                if( $getAdjustment ){
                    $isDebit = true;
                    if( $isAmount <= 0 ){
                        $isDebit = false;
                    }
                    $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount, $isDebit, false );
                    if( $applyPayent ){
                        echo "<br>Apply transaction payment successfully.";
                    } else {
                        echo "<br>Check Adjustment transaction log file.";
                    }
                }
            }
        } else {
            echo "<br>Check Custom Log";
        }
    }

    /**
     * Scenario 4: Short Pay Write Off with Adjustment
     * Step 1: Create a Credit Memo adjustment
     * Question:
     *          C|C-10377|100USD|BAI_07/04/2022_9991000065||918.28|BAI|07/04/2022|1000|56|119| TRADE PYMT WAL-MART STORES |9991000065|142|||||
     *          T|10575|10575-INV|15/02/2022|-0.67|0.00|Manual remit|4040||5774|||||||||||||||56|151
     *          T|10575|10575-INV|15/02/2022|937.69|0.00|Manual remit|INV||5774|||||||||||||||56|152
     *          A|4030|C-10377|100USD|BAI_07/04/2022_Manual remit|18.74||BAI|07/04/2022|1000|56|119| TRADE PYMT WAL-MART STORES |9991000065|||||
     * Solution:
     *          Check first T amount less then or negative & second T available INV
     */
    public function scenario4( $user_id, $user_integration_id, $destination_platform ){
        $status = false;

        $scenarioArr = [
            0 => "H|AA3W18|102|321.00",
            1 => "P|20004|100USD|BAI_22/06/2022_ TRADE PYMT WAL-MART STORES ||321.00|BAI|22/06/2022|1000|56|112| TRADE PYMT WAL-MART STORES |9991000065|USD|142|||||",
            2 => "C|C-10403|100USD|ACHWIRE_24/06/2022_9991000065 TRN*1*8510199\ ID: 148192||1106.00|ACHWIRE|24/06/2022|1000|209|625| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192||||||",
            3 => "T|14530|14530-INV|30/05/2022|0.05|0.00|Manual remit|4030|||||||||||||||||209|652",
            4 => "T|14530|14530-INV|30/05/2022|1128.52|0.00|Manual remit|INV|||||||||||||||||209|653",
            5 => "A|4030|C-10403|100USD|ACHWIRE_24/06/2022_Manual remit|22.57||ACHWIRE|24/06/2022|1000|209|625| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192|||||",
        ];

        $invoiceArr = [];
        $fileKey = -1;
        $paymentKey = -1;
        $customerKey = -1;
        foreach( $scenarioArr as $line ){
            if( $line != "" ){
                $data = explode( "|", $line );

                //H: Header
                if( $data['0'] == "H" ){
                    $fileKey++;
                    $invoiceArr[$fileKey]['header'] = $data;
                }

                // P: Payment
                if( $data['0'] == "P" && $fileKey >= 0 ){
                    $paymentKey++;
                    $customerKey = -1;
                    $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                }

                // C: Customers
                if( $data['0'] == "C" && $fileKey >= 0 ){
                    $customerKey++;
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey] = $data;
                }

                //T: Transaction Level/Open Invoice
                if( $data['0'] == "T" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                }

                //A: Individual Allocation
                if( $data['0'] == "A" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                }

                //L: Holding/Suspend
                if( $data['0'] == "L" && $customerKey >= 0){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                }
            }
        }

        $customer_id = $transactionAmount = $adjustmentAmount = 0;
        $overpaymentKey = $invoiceno = '';
        foreach($invoiceArr as $k=>$invArr){
            foreach( $invArr as $i=>$ar ){
                if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){
                    if( true ){
                        if( isset( $ar['customer'][0]['transaction'] ) && COUNT( $ar['customer'][0]['transaction'] ) == 2 //check customer only 2 transaction available
                            && isset( $ar['customer'][0]['allocation'] ) && COUNT( $ar['customer'][0]['allocation'] ) == 1 // check customer only 1 allocation payment
                            && ( $ar['customer'][0]['transaction'][0][7] != "" && is_numeric( $ar['customer'][0]['transaction'][0][7] ) ) //check 1st transaction available reference number in +ve format
                            && $ar['customer'][0]['transaction'][0][4] < 0 //check 1st transaction available amount in -ve format
                            && $ar['customer'][0]['transaction'][1][7] == "INV" //check 2nd transaction place amount to INV
                        ){
                            $lineitem = $memo = $account = [];
                            $lineitem[] = abs( $ar['customer'][0]['transaction'][0][4] );
                            $memo[] = $ar['customer'][0]['transaction'][0][6];
                            $account[] = $ar['customer'][0]['transaction'][0][7];

                            $lineitem[] = $ar['customer'][0]['allocation'][0][5];
                            $memo[] = "";//
                            $account[] = $ar['customer'][0]['allocation'][0][1];

                            $adjustmentAmount = ( abs( $ar['customer'][0]['transaction'][0][4] ) + $ar['customer'][0]['allocation'][0][5] );
                            $overpaymentKey = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][0]['allocation'][0][2], $ar['customer'][0]['transaction'][1][2], 0, $memo, $lineitem, $account );
                        }
                    } else {
                        $overpaymentKey = 1423;
                        $adjustmentAmount = 25.00;
                    }

                    $customer_id = $ar['customer'][0][1];
                    $invoiceno = $ar['customer'][0]['transaction'][0][2];
                    $transactionAmount = $ar['customer'][0]['transaction'][1][4];
                }
            }
        }

        if( $overpaymentKey ){
            $getAdjustment = true;//$this->getARAdjustmentDetail($user_id, $user_integration_id, $overpaymentKey);//, $customer_id, $invoiceno
            if( $getAdjustment ){
                $paymentMapping = [];
                $paymentMapping['payment_method'] = 'ACHWIRE';// P[6]CHK = Check, BAI = Record Transfer, ACHWIRE = Credit Card
                $paymentMapping['receipt_date'] = '24/06/2022';//date('d/m/Y'); // P[7]
                $paymentMapping['reference_no'] = '8510199'; // P[12]
                $paymentMapping['payment_date'] = date('d/m/Y'); // P[14]
                $paymentMapping['memo'] = "Manual remit";
                $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount, false, false, $paymentMapping );
                if( $applyPayent ){
                    $status = true;
                    echo "<br>Apply transaction payment successfully.";
                }
            }
        }

        if( !$status ){
            echo "Check the log file for more information.";
        }
    }

    /**
     * Scenario 5: Part Pay that Leaves a Remaining Balance
     * Step 1: Create Credit Memo adjustment for the “A” line
     * Step 2: Apply Payment and Credit memo to invoice for the shorted total payment of $2,635.51.
     * Question:
     *           C|C-10416|100USD|BAI_22/06/2022_9991000065||2635.51|BAI|07/04/2022|1000|56|126| TRADE PYMT WAL-MART STORES |9991000065|142|||||
     *           T|10563|10563-INV|15/02/2022|2689.30|0.00|Manual remit|INV||1206|||||||||||||||56|162
     *           A|4030|C-10416|100USD|BAI_07/04/2022_Manual remit|53.79||BAI|07/04/2022|1000|56|126| TRADE PYMT WAL-MART STORES |9991000065|||||
     *           A|OVERPAY|C-10389|100USD|ACHWIRE_27/06/2022_Manual remit - Invoices not in Sandbox||318.70|ACHWIRE|27/06/2022|1000|204|702| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8516405\|||||
     * Solution:
     *           Count(T) - Count(A) = Customer(C) Amount
     */
    public function scenario5( $user_id, $user_integration_id, $destination_platform, $customer_id, $invoiceno, $transactionAmount, $adjustmentAmount ){
        $status = false;

        // $customer_id = 'C-10348';
        // $invoiceno = '14546-INV';
        // $transactionAmount = '846.24';
        // $adjustmentAmount = '-8.46';//if used to credit amount then pass negative value
        $datecreated = '24/06/2022';
        $isDebit = ( $adjustmentAmount >= 0 ) ? true : false;

        // check OVERPAY: Invoices not in Sandbox exist
        if( false ){
            //5(description): allocation[1](GL Account) + transaction[2](Invoice Id)
            //6(advanceItems): allocation[6](Amount)
            $this->createAdvancePayment( $user_id, $user_integration_id, $customer_id, "EFT", "TRADE PYMT WAL-MART STORES", [72.17], "8516405");
        } else {
            // Step 1: Create Credit Memo adjustment for the “A” line
            $overpaymentKey = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $customer_id, $invoiceno, $adjustmentAmount, ['Manual remit'], [], [], $datecreated );
            if( $overpaymentKey ) {
                $getAdjustment = true;//$this->getARAdjustmentDetail( $user_id, $user_integration_id, $overpaymentKey );
                if( $getAdjustment ){
                    $paymentMapping = [];
                    $paymentMapping['payment_method'] = 'ACHWIRE';// P[6]CHK = Check, BAI = Record Transfer, ACHWIRE = Credit Card
                    $paymentMapping['receipt_date'] = '24/06/2022';//date('d/m/Y'); // P[7]
                    $paymentMapping['reference_no'] = '8510199'; // P[12]
                    $paymentMapping['payment_date'] = date('d/m/Y'); // P[14]
                    $paymentMapping['memo'] = "Manual remit";
                    // $applyPayent = $this->selectOverpaymentAdjustmentInvoicePaymentLegacy( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount, ( $adjustmentAmount < 0 ) ? true : false, $paymentMapping  );
                    $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount, $isDebit, true, $paymentMapping );
                    if( $applyPayent ){
                        $status = true;
                        echo "Apply transaction payment successfully.";
                    }
                }
            }
        }

        if( !$status ){
            echo "Check the log file for more information.";
        }
    }

    /**
     * Scenario 6: One customer or one or more only transaction
     * Question:
     *           C|C-10212|100USD|CHK_16/06/2022_183010||727.24|CHK|16/06/2022|1000|8|91|Froogel #3 - Long Beach|183010|06/10/22|||||
     *           T|14843|14843-INV|07/06/2022|727.24|0.00||INV|||||||||||||||||8|91
     * OR
     *           C|C-10244|100USD|CHK_16/06/2022_183010||4769.95|CHK|16/06/2022|1000|8|12|Froogel #3 - Long Beach|183010|06/10/22|||||
     *           T|13771|13771-INV|10/05/2022|2003.62|0.00||INV|||||||||||||||||8|12
     *           T|14608|14608-INV|31/05/2022|413.35|0.00||INV|||||||||||||||||8|13
     *           T|14849|14849-INV|07/06/2022|2352.98|0.00||INV|||||||||||||||||8|14
     */
    public function scenario6( $user_id, $user_integration_id, $destination_platform, $customer_id, $invoiceno, $amount ){
        $status = false;

        $scenarioArr = [
            0 => "H|AA3W22|16|8735.33",
            1 => "P|C-10763|100USD|ACHWIRE_27/06/2022_ PAYMENTS SYSCO ||5138.04|ACHWIRE|27/06/2022|1000|207|679| PAYMENTS SYSCO |0032002081 5\GE*1*22513\IEA*1*000022513\ ID: 003CA000122160|USD||||||",
            2 => "C|C-10763|100USD|ACHWIRE_27/06/2022_0032002081 5\GE*1*22513\IEA*1*000022513\ ID: 003CA000122160||5138.04|ACHWIRE|27/06/2022|1000|207|679| PAYMENTS SYSCO |0032002081 5\GE*1*22513\IEA*1*000022513\ ID: 003CA000122160||||||",
            3 => "T|14649|14649-INV|01/06/2022|5138.04|0.00||INV|6/7 DELIVERY DATE||||||||||||||||207|679",
        ];

        $invoiceArr = [];
        $fileKey = -1;
        $paymentKey = -1;
        $customerKey = -1;
        foreach( $scenarioArr as $line ){
            if( $line != "" ){
                $data = explode( "|", $line );

                //H: Header
                if( $data['0'] == "H" ){
                    $fileKey++;
                    $invoiceArr[$fileKey]['header'] = $data;
                }

                // P: Payment
                if( $data['0'] == "P" && $fileKey >= 0 ){
                    $paymentKey++;
                    $customerKey = -1;
                    $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                }

                // C: Customers
                if( $data['0'] == "C" && $fileKey >= 0 ){
                    $customerKey++;
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey] = $data;
                }

                //T: Transaction Level/Open Invoice
                if( $data['0'] == "T" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                }

                //A: Individual Allocation
                if( $data['0'] == "A" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                }

                //L: Holding/Suspend
                if( $data['0'] == "L" && $customerKey >= 0){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                }
            }
        }

        $transactionAmount = $adjustmentAmount = 0;
        $overpaymentKey = $invoiceno = '';

        foreach($invoiceArr as $k=>$invArr){
            foreach( $invArr as $i=>$ar ){
                if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){
                    foreach( $ar['customer'] as $c=>$customer ){
                        if( isset( $customer['transaction'] ) && COUNT( $customer['transaction'] ) > 0 //check customer transaction available
                            && !isset( $customer['allocation'] ) // check customer allocation not available
                        ){
                            foreach( $customer['transaction'] as $trxn )
                            {
                                if( $trxn[4] != 0 ){
                                    $paymentMapping = [];
                                    $paymentMapping['payment_method'] = $ar['payment'][6] ?? '';//CHK = Check, BAI = Record Transfer, ACHWIRE = "Credit Card
                                    $paymentMapping['receipt_date'] = $ar['payment'][7];
                                    $paymentMapping['reference_no'] = $ar['payment'][12];
                                    $paymentMapping['payment_date'] = $ar['payment'][14];
                                    $paymentMapping['memo'] = $trxn[6];// "Manual remit";
                                    $transactionAmount = $trxn[4];
                                    $invoiceno = $trxn[2];
                                    $customer_id = $ar['customer'][$c][1];
                                    $status = $this->selectOverpaymentAdjustmentInvoicePaymentLegacy( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, '', 0, false, $paymentMapping );
                                } else {
                                    Log::info( "Invoice: ".$trxn[2]." amount is passed 0" );
                                }
                            }
                        }
                    }
                }
            }
        }

        if( !$status ){
            echo "<br>Check the log file for more information.";
        }
    }

    /**
     * Scenario 7: One payment to one customer with several different deductions.
     * Step 1: Create a Credit/Debit Memo adjustment with multiple lines for each “A” line
     * Step 2: Apply payment to the invoice in the journal file and the adjustment created in Step 1.
     * Question:
     *           P|C-10679|100USD|CHK_17/03/2022_C & S Wholesale||30870.11|CHK|17/03/2022|1000|138|3|C & S Wholesale|0007113171|USD||||||
     *           C|C-10319|100USD|ACHWIRE_22/06/2022_9991000065 ID: 148192*TRN*1*8498459\||526.62|ACHWIRE|22/06/2022|1000|224|426| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8498459\||||||
     *           T|14924|14924-INV|09/06/2022|333.96|0.00|Manual remit|INV|||||||||||||||||224|482
     *           A|4030|C-10319|100USD|ACHWIRE_22/06/2022_Manual remit|4.00||ACHWIRE|22/06/2022|1000|224|426| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8498459\|||||
     *           A|4030|C-10319|100USD|ACHWIRE_22/06/2022_Manual remit|3.34||ACHWIRE|22/06/2022|1000|224|427| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8498459\|||||
     *
     *           A|OVERPAY|C-10389|100USD|ACHWIRE_27/06/2022_Manual remit - Invoices not in Sandbox||318.70|ACHWIRE|27/06/2022|1000|204|702| TRADE PYMT WAL-MART STORES |9991000065 ID: 148192*TRN*1*8516405\|||||
     * Solution:
     *           All A store with AR Adjustment module( Step 1: )
     */
    public function scenario7( $user_id, $user_integration_id, $destination_platform ){
        $scenarioArr = [
            0 => "H|AA3W15|6|1000.00",
            1 => "C|C-10364|100USD|ACHWIRE_24/06/2022_9991000065 TRN*1*8510199\ ID: 148192||438.55|ACHWIRE|24/06/2022|1000|209|613| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192||||||",
            2 => "T|14451|14451-INV|29/05/2022|455.01|0.00|Manual remit|INV|||||||||||||||||209|642",
            3 => "A|4030|C-10364|100USD|ACHWIRE_24/06/2022_Manual remit|9.10||ACHWIRE|24/06/2022|1000|209|613| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192|||||",
            4 => "A|4030|C-10364|100USD|ACHWIRE_24/06/2022_Manual remit|7.36||ACHWIRE|24/06/2022|1000|209|614| TRADE PYMT WAL-MART STORES |9991000065 TRN*1*8510199\ ID: 148192|||||",
        ];//For multiple T line acceptable


        $invoiceArr = [];
        $fileKey = -1;
        $paymentKey = -1;
        $customerKey = -1;
        foreach( $scenarioArr as $line ){
            if( $line != "" ){
                $data = explode( "|", $line );

                //H: Header
                if( $data['0'] == "H" ){
                    $fileKey++;
                    $invoiceArr[$fileKey]['header'] = $data;
                }

                // P: Payment
                if( $data['0'] == "P" && $fileKey >= 0 ){
                    $paymentKey++;
                    $customerKey = -1;
                    $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                }

                // C: Customers
                if( $data['0'] == "C" && $fileKey >= 0 ){
                    $customerKey++;
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey] = $data;
                }

                //T: Transaction Level/Open Invoice
                if( $data['0'] == "T" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                }

                //A: Individual Allocation
                if( $data['0'] == "A" && $customerKey >= 0 ){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                }

                //L: Holding/Suspend
                if( $data['0'] == "L" && $customerKey >= 0){
                    $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                }
            }
        }

        $customer_id = $transactionAmount = $adjustmentAmount = $isAmount = 0;
        $overpaymentKey = $invoiceno = '';
        foreach($invoiceArr as $k=>$invArr){
            foreach( $invArr as $i=>$ar ){
                if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){
                    if( isset( $ar['customer'][0]['transaction'] ) && COUNT( $ar['customer'][0]['transaction'] ) >= 1 //check customer only 1 transaction available
                        && isset( $ar['customer'][0]['allocation'] ) && COUNT( $ar['customer'][0]['allocation'] ) >= 2 //check customer only 3 allocation available
                    ){
                        $isAdvancePayment = false;
                        $description = "";
                        $lineitem = $memo = $account = $advanceItems = [];
                        foreach( $ar['customer'][0]['allocation'] as $a=>$allocation ){
                                if( $allocation[1] == "OVERPAY" ){
                                    $isAdvancePayment = true;
                                    $advanceItems[] = $allocation[6];
                                    $description = $allocation[1]." ".$ar['customer'][0]['transaction'][0][2];
                                } else {
                                    $isAmount = ( $allocation[5] != "" ) ? "-".$allocation[5] : $allocation[6];
                                    $lineitem[] = $isAmount;
                                    $mArr = explode( " ", trim( $allocation[4] ) );
                                    $memo[] = trim( str_ireplace( $mArr[0], "", $allocation[4] ) );
                                    $adjustmentAmount+= $isAmount;
                                    $account[] = $ar['customer'][0]['allocation'][0][1];
                                }
                        }

                        $overpaymentKey = $this->createARAdjustment( $user_id, $user_integration_id, $destination_platform, $ar['customer'][0][1], $ar['customer'][0]['transaction'][0][2], $adjustmentAmount, $memo, $lineitem, $account );
                        $customer_id = $ar['customer'][0][1];
                        $invoiceno = $ar['customer'][0]['transaction'][0][2];
                        $transactionAmount = $ar['customer'][0]['transaction'][0][4];

                        //if advance payment available
                        if( $isAdvancePayment ){
                            $this->createAdvancePayment( $user_id, $user_integration_id, $customer_id, "EFT", $description, $advanceItems, "8516405");
                        }
                    }
                }
            }
        }

        if( $overpaymentKey ){
            $getAdjustment = true;//$this->getARAdjustmentDetail($user_id, $user_integration_id, $overpaymentKey);//, $customer_id, $invoiceno
            if( $getAdjustment ){
                $isDebit = true;
                if( $isAmount <= 0 ){
                    $isDebit = false;
                }

                $paymentMapping = [];
                $paymentMapping['payment_method'] = 'ACHWIRE';//CHK = Check, BAI = Record Transfer, ACHWIRE = "Credit Card
                $paymentMapping['receipt_date'] = '27/06/2022';
                $paymentMapping['reference_no'] = '8516405';
                $paymentMapping['payment_date'] = '31/05/2022';
                $paymentMapping['memo'] = "Manual remit";

                $applyPayent = $this->selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customer_id, $invoiceno, $transactionAmount, $overpaymentKey, $adjustmentAmount, $isDebit, false, $paymentMapping );
                if( $applyPayent ){
                    echo "<br>Apply transaction payment successfully.";
                } else {
                    echo "<br>Check Adjustment transaction log file.";
                }
            }
        } else {
            echo "<br>Check Custom Log";
        }
    }

    /**
     * Step 1
     * createARAdjustment It's Work
     */
    public function createARAdjustment( $user_id, $user_integration_id, $destination_platform, $customer_id, $invoiceno="11612-INV", $amount=0, $memo=[], $lineitemArr=[], $account=[], $datecreated='' ){
        if( $amount == 0 ){
            return true;
        }

        $paymentType = "Debit";
        if( $amount < 0)
            $paymentType = "Credit";

        if( COUNT( $memo ) == 0 ){
            $memo = "Create ".$paymentType." AR Payment for ".str_ireplace( "-", "", $amount )." amount";
        } else if( COUNT( $memo ) == 1 ){
            $memo = $memo[0];
        }

        $lineitem = '';
        if( $lineitemArr ){
            foreach( $lineitemArr as $k=>$amount ){
                $lineitem .= '<lineitem>
                                <glaccountno>'.$account[$k].'</glaccountno>
                                <amount>'.$amount.'</amount>
                                <memo>'.$memo[$k].'</memo>
                                <locationid>100</locationid>
                                <departmentid></departmentid>
                                <projectid></projectid>
                                <customerid>'.$customer_id.'</customerid>
                                <vendorid></vendorid>
                                <employeeid></employeeid>
                                <itemid></itemid>
                                <classid></classid>
                            </lineitem>';
            }
        } else {
            $lineitem = '<lineitem>
                    <glaccountno>4030</glaccountno>
                    <amount>'.$amount.'</amount>
                    <memo>'.$memo.'</memo>
                    <locationid>100</locationid>
                    <departmentid></departmentid>
                    <projectid></projectid>
                    <customerid>'.$customer_id.'</customerid>
                    <vendorid></vendorid>
                    <employeeid></employeeid>
                    <itemid></itemid>
                    <classid></classid>
                </lineitem>';
            }

        $date = explode( "/", ( $datecreated != "" ) ? $datecreated : date('d/m/Y') );
        $query='<create_aradjustment>
                <customerid>'.$customer_id.'</customerid>
                <datecreated>
                    <year>'.$date[2].'</year>
                    <month>'.$date[1].'</month>
                    <day>'.$date[0].'</day>
                </datecreated>
                <adjustmentno></adjustmentno>';

                if( $invoiceno != "-" ){
                    $query .= '<invoiceno>'.$invoiceno.'</invoiceno>';
                }

                $query .= '<description></description>
                <aradjustmentitems>
                    '.$lineitem.'
                </aradjustmentitems>
            </create_aradjustment>';

        $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

        $key = '';
        if($responseARPayment['api_status']=='success'){
            if(isset($responseARPayment['operation']['result']['key'])){
                $key = $responseARPayment['operation']['result']['key'];
            }
        }

        if( $key ) {
            return $key;
        } else {
            //Log::info("Error Create Adjustment - Integration: ".$user_integration_id." ".json_encode( $responseARPayment ) );
            return false;
        }
    }

    /**
     * Step 2
     * getARAdjustmentDetail It's Working
     */
    public function getARAdjustmentDetail( $user_id, $user_integration_id, $resultKey="22116" )//, $customerid="C-10826", $invoiceno="11612-INV" ){
    {
        $query='<read>
                    <object>ARADJUSTMENT</object>
                    <keys>'.$resultKey.'</keys>
                    <fields>*</fields>
                </read>';

        $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

        if( $responseARPayment && $responseARPayment['api_status'] == 'success' )
        {
            return true;
        }
        else {
            //Log::info("Error Get Adjustment Details - Integration: ".$user_integration_id." ".json_encode( $responseARPayment ) );
            return false;
        }
    }

    /**
     * Step 3
     * selectOverpaymentAdjustmentInvoicePaymentLegacy It's Work (with Legacy)
     */
    public function selectOverpaymentAdjustmentInvoicePaymentLegacy( $user_id, $user_integration_id, $customerid="C-10826", $invRecordID="-", $transactionAmount=0, $adjustmentKey="", $adjustmentAmount=0, $isDebit=true, $paymentMapping=[]){
        if( $invRecordID == '-' && ($transactionAmount == 0 || $adjustmentAmount == 0) ){
            return true;
        }

        $fields = ['RECORDNO', 'RECORDID'];
        $select = '';
        foreach($fields as $field){
            $select.='<field>'.$field.'</field>';
        }

        $query = '<query>
                    <object>ARINVOICE</object>
                    <select>
                        '.$select.'
                    </select>
                    <filter>
                        <like>
                            <field>RECORDID</field>
                            <value>'.$invRecordID.'</value>
                        </like>
                    </filter>
                </query>';

        $response = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

        // get invoice unique id (RecordNo.)
        $invoiceid = '';
        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['ARINVOICE'])){
                $invoiceid = $response['operation']['result']['data']['ARINVOICE']['RECORDNO'];
            }
        }

        if( $invoiceid ){
            //get Invoice details
            $query ='<read>
                        <object>ARINVOICE</object>
                        <keys>'.$invoiceid.'</keys>
                        <fields>*</fields>
                    </read>';

            $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

            if($responseARPayment['api_status']=='success'){
                $dateReceivedArr = explode( "/", $paymentMapping['receipt_date'] ?? date('d/m/Y') );

                $amount = $transactionAmount;
                if( !$isDebit ){
                    $amount = ( $transactionAmount - abs( $adjustmentAmount ) );
                }

                $paymentmethod = "Cash";
                if( $paymentMapping['payment_method'] === "CHK" ){
                    $paymentmethod = "Check";
                } else if( $paymentMapping['payment_method'] === "BAI" ){
                    $paymentmethod = "Record Transfer";
                } else if( $paymentMapping['payment_method'] === "ACHWIRE" ){
                    $paymentmethod = "EFT";
                }

                $query = '<create_arpayment>
                    <customerid>'.$customerid.'</customerid>
                    <paymentamount>'.( $amount ).'</paymentamount>
                    <undepfundsacct>1050</undepfundsacct>';

                    if( $paymentMapping['reference_no'] ){
                        $query .= '<refid>'.$paymentMapping['reference_no'].'</refid>';
                    }

                    $query .= '<datereceived>
                        <year>'.$dateReceivedArr[2].'</year>
                        <month>'.$dateReceivedArr[1].'</month>
                        <day>'.$dateReceivedArr[0].'</day>
                    </datereceived>
                    <paymentmethod>'.$paymentmethod.'</paymentmethod>
                    <arpaymentitem>
                        <invoicekey>'.$invoiceid.'</invoicekey>
                        <amount>'.( $amount ).'</amount>
                    </arpaymentitem>
                </create_arpayment>';

                $responseCreateARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

                if( $adjustmentAmount == 0 ){
                    return true;
                } else if( $responseCreateARPayment && $responseCreateARPayment['api_status'] == "success" ){
                    $paymentdateArr = explode( "/", $paymentMapping['payment_date'] ?? date('d/m/Y') );
                    $query = '<apply_arpayment>
                        <arpaymentkey>'.$responseCreateARPayment['operation']['result']['key'].'</arpaymentkey>
                        <paymentdate>
                            <year>'.$paymentdateArr[2].'</year>
                            <month>'.$paymentdateArr[1].'</month>
                            <day>'.$paymentdateArr[0].'</day>
                        </paymentdate>
                        <memo>'.$paymentMapping['memo'].'</memo>
                        <overpaylocid/>
                        <overpaydeptid/>
                        <arpaymentitems>
                            <arpaymentitem>
                                <invoicekey>'.$invoiceid.'</invoicekey>
                                <amount>'.abs( $adjustmentAmount ).'</amount>
                            </arpaymentitem>
                        </arpaymentitems>
                    </apply_arpayment>';

                    $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

                    $updateSyncStatus = false;
                    if( $responseARPayment['api_status'] == "success" ){
                        $updateSyncStatus = true;
                    } else if( "There is no due on the invoice ".$invoiceid == $responseARPayment['api_error'] ){
                        $updateSyncStatus = true;
                    } else {
                        $updateSyncStatus = false;
                    }

                    if( !$updateSyncStatus ){
                        //Log::info("Error Apply Payment - Integration: ".$user_integration_id." ".json_encode( $responseARPayment ) );
                    }
                    return $updateSyncStatus;
                } else {
                    //Log::info("Error Apply Payment - Integration: ".$user_integration_id." ".json_encode( $responseCreateARPayment ) );
                    return false;
                }
            }
        } else {
            //Log::info("Error Apply Payment - Integration: ".$user_integration_id." Invoice key not exist" );
            return false;
        }
    }

    /**
     * Step 3
     * selectOverpaymentAdjustmentInvoicePayment It's Work (without Legacy)
     */
    public function selectOverpaymentAdjustmentInvoicePayment( $user_id, $user_integration_id, $customerid="C-10826", $invRecordID="11612-INV", $transactionAmount=0, $adjustmentKey="", $adjustmentAmount=0, $isDebit=true, $isOverPay = false, $paymentMapping=[]){

        if( $invRecordID == "-" || ( $adjustmentAmount == 0 && !$isOverPay ) ){
            return true;
        }

        $fields = ['RECORDNO', 'RECORDID'];
        $select = '';
        foreach($fields as $field){
            $select.='<field>'.$field.'</field>';
        }

        $query = '<query>
                    <object>ARINVOICE</object>
                    <select>
                        '.$select.'
                    </select>
                    <filter>
                        <like>
                            <field>RECORDID</field>
                            <value>'.$invRecordID.'</value>
                        </like>
                    </filter>
                </query>';

        $response = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

        // get invoice unique id (RecordNo.)
        $invoiceid = '';
        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['ARINVOICE'])){
                $invoiceid = $response['operation']['result']['data']['ARINVOICE']['RECORDNO'];
            }
        }

        if( $invoiceid ){
            //get Invoice details
            $query ='<read>
                        <object>ARINVOICE</object>
                        <keys>'.$invoiceid.'</keys>
                        <fields>*</fields>
                    </read>';

            $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

            if($responseARPayment['api_status']=='success'){

                $amount = $transactionAmount;
                $overPaymentAmount = 0;
                if( $isOverPay ){
                    echo "<br>Total Due Amount: ".$responseARPayment['operation']['result']['data']['ARINVOICE']['TRX_TOTALDUE'];
                    $overPaymentAmount = ( $transactionAmount - $responseARPayment['operation']['result']['data']['ARINVOICE']['TRX_TOTALDUE'] );
                    $transactionAmount = $transactionAmount - round( $overPaymentAmount, 2 );
                }

                if( !$isDebit ){
                    $amount = ( $transactionAmount - abs( $adjustmentAmount ) );
                }

                $paymentmethod = "Cash";
                if( $paymentMapping['payment_method'] === "CHK" ){
                    $paymentmethod = "Check";
                } else if( $paymentMapping['payment_method'] === "BAI" ){
                    $paymentmethod = "Record Transfer";
                } else if( $paymentMapping['payment_method'] === "ACHWIRE" ){
                    $paymentmethod = "EFT";
                }

                $paymentdateArr = explode( "/", $paymentMapping['payment_date'] ?? date('d/m/Y') );
                $dateReceivedArr = explode( "/", $paymentMapping['receipt_date'] ?? date('d/m/Y') );

                $query = '<create>
                            <ARPYMT>
                                <PAYMENTMETHOD>'.$paymentmethod.'</PAYMENTMETHOD>
                                <UNDEPOSITEDACCOUNTNO>1050</UNDEPOSITEDACCOUNTNO>
                                <CUSTOMERID>'.$customerid.'</CUSTOMERID>
                                <DOCNUMBER>8485972</DOCNUMBER>';//'.$invRecordID.'

                                if( $paymentMapping['reference_no'] ){
                                    $query .= '<refid>'.$paymentMapping['reference_no'].'</refid>';
                                }

                                $query .= '<RECEIPTDATE>'.$dateReceivedArr[1].'/'.$dateReceivedArr[0].'/'.$dateReceivedArr[2].'</RECEIPTDATE>
                                <PAYMENTDATE>'.$paymentdateArr[1].'/'.$paymentdateArr[0].'/'.$paymentdateArr[2].'</PAYMENTDATE>
                                <MEMO>'.$paymentMapping['memo'].'</MEMO>
                                <ARPYMTDETAILS>
                                    <ARPYMTDETAIL>
                                        <RECORDKEY>'.$invoiceid.'</RECORDKEY>
                                        <TRX_PAYMENTAMOUNT>'.$amount.'</TRX_PAYMENTAMOUNT>';

                                        if( $adjustmentKey > 1 ){
                                            $query .= '<ADJUSTMENTKEY>'.$adjustmentKey.'</ADJUSTMENTKEY>
                                                        <TRX_ADJUSTMENTAMOUNT>'.abs( $adjustmentAmount ).'</TRX_ADJUSTMENTAMOUNT>';
                                        }

                                    $query .= '</ARPYMTDETAIL>
                                </ARPYMTDETAILS>
                            </ARPYMT>
                        </create>';
                $response = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

                if($response['api_status'] === 'success'){
                    return true;
                } else {
                    //Log::info("Error Create AR Payment - Integration: ".$user_integration_id." ".json_encode( $response ) );
                    return false;
                }
            } else {
                //Log::info("Error Read Invoice - Integration: ".$user_integration_id." ".json_encode( $responseARPayment ) );
                return false;
            }
        }
        else {
            //Log::info("Error Read Invoice - Integration: ".$user_integration_id." ".json_encode( $response ) );
            return false;
        }
    }

    /**
     *
     */
    public function createAdvancePayment( $user_id, $user_integration_id, $customer_id="", $payment_method="EFT", $description="", $advanceItems=[], $document_no=""){

        $advance = '';
        foreach( $advanceItems as $amt ){
            $advance .= '<ARADVANCEITEM>
                            <ACCOUNTNO>4030</ACCOUNTNO>
                            <TRX_AMOUNT>'.$amt.'</TRX_AMOUNT>
                        </ARADVANCEITEM>';
        }

        $query='<create>
                <ARADVANCE>
                    <CUSTOMERID>'.$customer_id.'</CUSTOMERID>
                    <PAYMENTDATE>06/27/2022</PAYMENTDATE>
                    <RECEIPTDATE>06/27/2022</RECEIPTDATE>
                    <PAYMENTMETHOD>'.$payment_method.'</PAYMENTMETHOD>
                    <FINANCIALENTITY>B-001</FINANCIALENTITY>
                    <DESCRIPTION>'.$description.'</DESCRIPTION>
                    <DOCNUMBER>'.$document_no.'</DOCNUMBER>
                    <ARADVANCEITEMS>
                        '.$advance.'
                    </ARADVANCEITEMS>
                </ARADVANCE>
            </create>';
        $result = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );
        //Log::info("AR Advance Payment ID: </b>".$result['operation']['result']['data']['aradvance']['RECORDNO']);
    }

    /**
     *
     */
    public function createARPaymentSynchronize( $user_id, $user_integration_id, $is_initial_sync=0, $destination_platform_id, $source_platform_id ){

        $return_response = true;
        try{
            $offset = 0;
            $pagesize = 50;
            $limit = [];

            if ($is_initial_sync) {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->my_platform_id,
                    'url_name' => 'payment_sync_intacct_limit'
                ],
                ['url', 'id']);

                if ($limit) {
                    $offset = $limit->url;
                }
            }

            $resultArr = PlatformInvoice::
                select('platform_invoice.id as id', 'platform_customer.api_customer_code', 'platform_invoice.total_amt',
                'platform_invoice.invoice_code', 'platform_invoice.due_date', 'platform_invoice.invoice_date',
                'platform_invoice.gl_posting_date', 'platform_invoice.ship_date', 'platform_invoice.api_created_at',
                'platform_order_transactions.transaction_id', 'platform_order_transactions.id as platform_order_id')
                ->join("platform_order_transactions","platform_order_transactions.platform_order_id","=","platform_invoice.platform_order_id")
                ->join("platform_customer","platform_customer.id","=","platform_invoice.platform_customer_id")
                ->where(
                    ['platform_invoice.user_id' => $user_id,
                    'platform_invoice.platform_id' => $this->my_platform_id,
                    'platform_invoice.user_integration_id' => $user_integration_id,
                    'platform_invoice.sync_status' => PlatformStatus::READY
                    ] )
                ->offset($offset)
                ->limit($pagesize)
                ->get();

            if( COUNT( $resultArr ) >0 ){

                //get customer intacct account id unique number
                $undepfundsacct = $this->map->getMappedDataByName($user_integration_id, null, "custom_payment_consent", ['custom_data']);

                if( $undepfundsacct && $undepfundsacct->custom_data ){
                    foreach( $resultArr as $ar ){
                        $query = '<create_arpayment>
                            <customerid>'.$ar['api_customer_code'].'</customerid>
                            <paymentamount>'.$ar['total_amt'].'</paymentamount>
                            <undepfundsacct>'.$undepfundsacct->custom_data.'</undepfundsacct>
                        </create_arpayment>';

                        $responseCreateARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

                        if( $responseCreateARPayment['api_status'] == "success" ){
                            $dateReceivedArr = explode( "-", $ar['ship_date'] );
                            $query = '<apply_arpayment>
                                <arpaymentkey>'.$responseCreateARPayment['operation']['result']['key'].'</arpaymentkey>
                                <paymentdate>
                                    <year>'.$dateReceivedArr[0].'</year>
                                    <month>'.$dateReceivedArr[1].'</month>
                                    <day>'.$dateReceivedArr[2].'</day>
                                </paymentdate>
                                <memo>Apply AR Payment from API</memo>
                                <overpaylocid/>
                                <overpaydeptid/>
                                <arpaymentitems>
                                    <arpaymentitem>
                                        <invoicekey>'.$ar['transaction_id'].'</invoicekey>
                                        <amount>'.$ar['total_amt'].'</amount>
                                    </arpaymentitem>
                                </arpaymentitems>
                            </apply_arpayment>';

                            $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

                            $updateSyncStatus = false;
                            if( $responseARPayment['api_status'] == "success" ){
                                $updateSyncStatus = true;
                            } else if( "There is no due on the invoice ".$ar['transaction_id'] == $responseARPayment['api_error'] ){
                                $updateSyncStatus = true;
                            } else {
                                $updateSyncStatus = false;
                            }

                            if( $updateSyncStatus ){
                                //update synchronize status ready to Synced
                                $platform_order_transactions['transaction_response_text'] = json_encode( $responseARPayment['operation'] );
                                $platform_order_transactions['transaction_response_code'] = $responseARPayment['operation']['result']['key'];
                                $platform_order_transactions['sync_status'] = "Synced";
                                PlatformOrderTransaction::where( 'id', $ar['platform_order_id'] )->update( $platform_order_transactions );

                                PlatformInvoice::where( 'id', $ar['id'] )->update(['sync_status' => 'Synced' ]);
                            }
                        } else {
                            Log::info( json_encode( $user_integration_id.": ".$responseCreateARPayment ) );
                            $return_response = json_encode( $user_integration_id.": ".$responseCreateARPayment );
                        }
                    }
                }

                if ($is_initial_sync) {////added by @GK
                    $return_response = 'data Remaining';
                    // $offset = $offset + 1;
                }
            }

            if( $is_initial_sync ){//added by @GK
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => ( $offset + $pagesize )], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => ( $offset + $pagesize ), 'url_name' => 'customer_limit']);
                }
            }
        } catch (\Exception $e) {
            Log::info($user_integration_id."--Blackline_IntacctPaymentSync-->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     *
     */
    public function intacctGetAdvanceInvoices( $user_id, $user_integration_id, $is_initial_sync ){
        $this->mobj->AddMemory();
        $return_response = true;
        try{

            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,null,"trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;

            $sync_start_date = date('m/d/Y H:i:s');

            /**
             * added by @GK
             * check payment status rules available(open/close/null)
             */
            $getInvoicePaymentStatus = $this->map->getMappedDataByName($user_integration_id, NULL, "payment_status", ['api_id'], 'regular', '', 'multiple');//$this->map->getMappedDataByName($user_integration_id, $paymentFilter );//getMappedApiIdByObjectId

            if( COUNT( $getInvoicePaymentStatus ) > 0 ){

                $getAdvanceInvoiceDate = DB::table('platform_urls')
                    ->where([
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->my_platform_id,
                        'url_name' => 'getAdvanceInvoiceDate'
                        ])
                    ->select('id','url')
                    ->first();

                $run = false;
                if( $getAdvanceInvoiceDate == null
                    ||
                    ( isset( $getAdvanceInvoiceDate ) && strtotime( $getAdvanceInvoiceDate->url ) === strtotime( date( 'Y-m-d' ) ) )
                ){
                    $run = true;
                }

                if( COUNT( $getInvoicePaymentStatus ) > 0 && in_array( "Advance", $getInvoicePaymentStatus) && $run ){
                    $invoiceids = [];
                    $query = "<query>
                                <object>ARADVANCE</object>
                                <select>
                                    <field>RECORDNO</field>
                                </select>
                            </query>";
                    $responseARPayment = $this->intacctapi->CallAPI( $user_id,$user_integration_id, $query );
                    if( $responseARPayment['api_status'] == 'success' ){
                        if(isset($responseARPayment['operation']['result']['data']['ARADVANCE'] ) ){
                            $advanceInvoices = array();
                            if(isset($responseARPayment['operation']['result']['data']['ARADVANCE']['RECORDNO']['RECORDNO'] ) ){
                                $advanceInvoices[0] = $responseARPayment['operation']['result']['data']['ARADVANCE'];
                            }else{
                                $advanceInvoices = $responseARPayment['operation']['result']['data']['ARADVANCE'];
                            }

                            foreach($advanceInvoices as $invoiceid){
                                $invoiceids[] = $invoiceid['RECORDNO'];
                            }
                        }
                    }

                    if(count($invoiceids) > 0){
                        $query ='<read>
                                    <object>ARADVANCE</object>
                                    <keys>'.implode(',', $invoiceids).'</keys>
                                    <fields>*</fields>
                                </read>';

                        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
                        if($response['api_status']=='success'){
                            if(isset($response['operation']['result']['data']['ARADVANCE'])){ //ARINVOICE
                                $invoice = array();
                                if(isset($response['operation']['result']['data']['ARADVANCE']['RECORDNO'])){
                                    $invoice[0] = $response['operation']['result']['data']['ARADVANCE'];
                                }else{
                                    $invoice = $response['operation']['result']['data']['ARADVANCE'];
                                }

                                foreach($invoice as $k=>$rowinv){
                                    $arr_invoice = [];
                                    $arr_invoice['invoice_code'] = (!is_array(@$rowinv['DOCNUMBER'])) ? @$rowinv['DOCNUMBER'] : null;
                                    $arr_invoice['due_amt'] = (!is_array(@$rowinv['TOTALDUE'])) ? @$rowinv['TOTALDUE'] : 0;

                                    if( $arr_invoice['due_amt'] == 0 )
                                        continue;

                                    echo $arr_invoice['invoice_code']." ";
                                    $platform_order_id = 0;
                                    $arr_invoice['due_date'] = (!is_array(@$rowinv['WHENPAID'])) ? @$rowinv['WHENPAID'] : null;
                                    $arr_invoice['net_total'] = (!is_array(@$rowinv['TRX_TOTALENTERED'])) ? @$rowinv['TRX_TOTALENTERED'] : 0;
                                    $arr_invoice['total_amt'] = (!is_array(@$rowinv['TOTALENTERED'])) ? @$rowinv['TOTALENTERED'] : 0;


                                    $arr_invoice['total_paid_amt'] = (!is_array(@$rowinv['TOTALPAID'])) ? @$rowinv['TOTALPAID'] : 0;
                                    $arr_invoice['payment_terms'] = ( !is_array( @$rowinv['ARADVANCEITEMS']['aradvanceitem']['ENTRYDESCRIPTION'] ) ) ? @$rowinv['ARADVANCEITEMS']['aradvanceitem']['ENTRYDESCRIPTION'] : null;
                                    $api_customer_code = $rowinv['CUSTOMERID'] ??  null;

                                    //fetch Customer Details
                                    $customerArr = $this->mobj->getFirstResultByConditions('platform_customer', ['api_customer_code' => $api_customer_code, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id] );

                                    $arr_invoice['user_id'] = $user_id;
                                    $arr_invoice['platform_id'] = $this->my_platform_id;
                                    $arr_invoice['user_integration_id'] = $user_integration_id;
                                    $arr_invoice['platform_order_id'] = $platform_order_id;
                                    $arr_invoice['platform_customer_id'] = $customerArr->id ?? 0;
                                    $arr_invoice['trading_partner_id'] = $trading_partner_id;
                                    $arr_invoice['api_invoice_id'] = $rowinv['RECORDNO'];
                                    $arr_invoice['invoice_state'] = (!is_array(@$rowinv['STATE'])) ? @$rowinv['STATE'] : null;
                                    $arr_invoice['invoice_payment_status'] = null;
                                    $arr_invoice['ref_number'] = null;
                                    $arr_invoice['order_doc_number'] = null;
                                    $arr_invoice['invoice_date'] = (!is_array(@$rowinv['AUWHENCREATED'])) ? @$rowinv['AUWHENCREATED'] : null;
                                    $arr_invoice['gl_posting_date'] = null;
                                    $arr_invoice['api_created_at'] = (!is_array(@$rowinv['AUWHENCREATED'])) ? @$rowinv['AUWHENCREATED'] : null;
                                    $arr_invoice['api_updated_at'] = (!is_array(@$rowinv['WHENMODIFIED'])) ? @$rowinv['WHENMODIFIED'] : null;
                                    $arr_invoice['ship_date'] = null;
                                    $arr_invoice['ship_via'] = null;
                                    $arr_invoice['tracking_number'] = null;
                                    $arr_invoice['ship_by_date'] = null;
                                    $arr_invoice['customer_name'] = (!is_array(@$rowinv['CUSTOMERNAME'])) ? @$rowinv['CUSTOMERNAME'] : null;
                                    $arr_invoice['message'] = (!is_array(@$rowinv['PRBATCH'])) ? @$rowinv['PRBATCH'] : null;
                                    $arr_invoice['pay_date'] = (!is_array(@$rowinv['WHENPAID'])) ? @$rowinv['WHENPAID'] : null;
                                    $arr_invoice['due_days'] = 0;

                                    $arr_invoice['invoice_type'] = "advance";
                                    $arr_invoice['api_customer_code'] = $api_customer_code;
                                    $arr_invoice['linked_id'] = 0;

                                    $pi = PlatformInvoice::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_invoice_id' => $rowinv['RECORDNO']])->select('id','api_updated_at','created_at')->first();

                                    if ($pi) {
                                        $platform_invoice_id = $pi->id;
                                        if( $pi->api_updated_at != $arr_invoice['api_updated_at'] ){
                                            $arr_invoice['sync_status'] = 'Ready';
                                        }
                                        $arr_invoice['sync_status'] = 'Ready';
                                        PlatformInvoice::where(['id' => $platform_invoice_id])->update($arr_invoice);
                                    } else {
                                        $arr_invoice['sync_status'] = 'Ready';
                                        $platform_invoice_id = PlatformInvoice::insertGetId($arr_invoice);
                                    }
                                }

                                if ($is_initial_sync) {
                                    $return_response = 'data Remaining';
                                }
                            }
                        }else{
                            $return_response = $response['api_error'];
                        }
                    }

                    if( COUNT( $getInvoicePaymentStatus ) > 0 ){
                        $nextDate = date('Y-m-d', strtotime("+1 days") );
                        if ($getAdvanceInvoiceDate) {
                            $this->mobj->makeUpdate('platform_urls', ['url' => $nextDate ], ['id' => $getAdvanceInvoiceDate->id]);
                        } else {
                            $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => $nextDate, 'url_name' => 'getAdvanceInvoiceDate']);
                        }
                    }
                }
            } else {
                //
            }
        } catch (\Exception $e) {
            Log::error($user_integration_id."--intacctGetAdvanceInvoices-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    
    public function IntacctGetTransactionTypes($user_id,$user_integration_id,$is_initial_sync=0)
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $process_limit = 100;
            $offset = 0;


            $fields = ['RECORDNO','DOCID','DESCRIPTION','STATUS','WHENCREATED','WHENMODIFIED'];

            $select = '';
            foreach($fields as $field){
                $select.='<field>'.$field.'</field>';
            }


            $objects = $this->mobj->getFirstResultByConditions('platform_objects',['name' => "transaction_type"], ['id']);

            if (isset($objects->id)) {
                $platform_object_id = $objects->id;

                //do{
                    $allow_next_call = false; // This flag will help for pagination


                    $query ="<query>
                        <object>SODOCUMENTPARAMS</object>
                        <select>".$select."</select>
                        <pagesize>".$process_limit."</pagesize>
                        <offset>".$offset."</offset>
                    </query>";


                    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

                    if($response['api_status']=='success'){
                        if(isset($response['operation']['result']['data']['SODOCUMENTPARAMS'])){

                            $transaction_type = array();
                            if(isset($response['operation']['result']['data']['SODOCUMENTPARAMS']['RECORDNO'])){
                                $transaction_type[0] = $response['operation']['result']['data']['SODOCUMENTPARAMS'];
                            }else{
                                $transaction_type = $response['operation']['result']['data']['SODOCUMENTPARAMS'];
                            }


                            // continue looping
                            if(count($transaction_type)==$process_limit){
                                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                                $offset+=$process_limit;
                            }
                            if(count($transaction_type) > 0){

                                foreach($transaction_type as $row){

                                    $arr_transaction_type = array();
                                    $arr_transaction_type['user_id'] = $user_id;
                                    $arr_transaction_type['platform_id'] = $this->my_platform_id;
                                    $arr_transaction_type['user_integration_id'] = $user_integration_id;
                                    $arr_transaction_type['platform_object_id'] = $platform_object_id;
                                    $arr_transaction_type['api_id'] = $row['RECORDNO'];
                                    $arr_transaction_type['name'] = (!is_array(@$row['DOCID'])) ? @$row['DOCID'] : null;

                                    $ct = $this->mobj->getCountsByConditions('platform_object_data', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']]);

                                    if ($ct > 0) {
                                        $this->mobj->makeUpdate('platform_object_data', $arr_transaction_type, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $platform_object_id, 'api_id' => $row['RECORDNO']]);
                                    } else {
                                    $this->mobj->makeInsertGetId('platform_object_data', $arr_transaction_type);
                                    }

                                }
                            }

                        }


                    }else{
                        $return_response = $response['api_error'];
                    }



                //}while($allow_next_call);
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id."--IntacctGetLocations-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;

    }

    
    public function IntacctGetInvoicesBackup( $user_id,$user_integration_id){
        $this->mobj->AddMemory();
        $return_response = true;
        try{
            $offset = 0;
            $pagesize = 50;
            $urlname = 'invoice_backup_last_time';
            $sync_start_date = date('Y-m-d',strtotime('-1 day'));
            $today_date = date('Y-m-d');

            $url_modified = PlatformUrl::select('url', 'id', 'status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $urlname])->first();
            if (isset($url_modified->url) && $url_modified->url != '') {
                $arrurl = explode('|', $url_modified->url);
                $sync_start_date = $arrurl[0];
                if(strtotime($sync_start_date)==strtotime($today_date)) {
                    return true;
                }
                $offset = intval($arrurl[1]);
            }
           // echo "$user_id,$user_integration_id,$offset,$pagesize,$sync_start_date";// exit;

            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,null,"trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;

            $CustomDataChargeAllowItem = $this->map->getMappedDataByName($user_integration_id,null,"charges_allowances_item", ['custom_data'], "default");
            $chargesandallowanceitem = @$CustomDataChargeAllowItem->custom_data;

           
            $sync_start_date1 = date('m/d/Y',strtotime($sync_start_date))." 00:00:00";
          //  $sync_start_date1 = date('m/d/Y',strtotime('-6 day'))." 00:00:00";
            $sync_start_date2 = date('m/d/Y',strtotime($sync_start_date))." 23:59:59";

            //do{
                $allow_next_call = false; // This flag will help for pagination
                $select = '<field>RECORDNO</field><field>WHENMODIFIED</field>';
                $query ="<query>
                        <object>SODOCUMENT</object>
                        <select>".$select."</select>";

               


                $query.="<filter>
                            <between>
                                <field>WHENMODIFIED</field>
                                <value>".$sync_start_date1."</value>
                                <value>".$sync_start_date2."</value>
                           </between>
                        </filter>";


                $query.="<orderby>
                        <order>
                            <field>WHENMODIFIED</field>
                            <ascending />
                        </order>
                    </orderby>";

                    $transactiontype_map=$this->map->getMappedDataByName($user_integration_id, null, "default_sales_invioice_transaction_type", ['name']);
                    $transactiontype= @$transactiontype_map->name ? $transactiontype_map->name : 'Sales Invoice';

                $query.="<pagesize>".$pagesize."</pagesize>
                            <offset>".$offset."</offset>
                            <docparid>".$transactiontype."</docparid>
                        </query>";

    //\Storage::disk('local')->append('Bhoopendra_Intacct_Backup_Invoice.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') . " | " . "user_integration_id : " . $user_integration_id  . "--> sync_start_date : " . $sync_start_date. " | invoiceids : " . $query);


                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
             //   echo "<pre>"; print_r($response); //exit;
                $invoiceids = array();
                if($response['api_status']=='success'){
                    if(isset($response['operation']['result']['data']['SODOCUMENT']) || isset($response['operation']['result']['data']['sodocument'] )){
                        $sales_invoices = array();
                        if(isset($response['operation']['result']['data']['SODOCUMENT']['RECORDNO'])  || isset($response['operation']['result']['data']['sodocument']['RECORDNO'] ) ){
                            $sales_invoices[0] = $response['operation']['result']['data']['SODOCUMENT'] ?? $response['operation']['result']['data']['sodocument'];
                        }else{
                            $sales_invoices = $response['operation']['result']['data']['SODOCUMENT'] ?? $response['operation']['result']['data']['sodocument'];
                        }

                        foreach($sales_invoices as $invoiceid){
                            $invoiceids[] = $invoiceid['RECORDNO'];
                        }
                    }
                }

                
                \Storage::disk('local')->append('Bhoopendra_Intacct_Missing_Invoice.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') . " | " . "user_integration_id : " . $user_integration_id  . "--> sync_start_date : " . $sync_start_date. " | invoiceids : " . json_encode($invoiceids, true));

                if(count($invoiceids) > 0){
                    $this->IntacctGetInvoicesStore($user_id,$user_integration_id,$invoiceids,$trading_partner_id,$chargesandallowanceitem,$url_modified,$urlname,$transactiontype,$sync_start_date,$offset,$pagesize);
                }else{
                    $offset = 0;
                     $latest_modified_date = date('Y-m-d',strtotime($sync_start_date),strtotime('+1 day'));
                     if($url_modified){
                        PlatformUrl::where(['id' => $url_modified->id])->update(['url' => $latest_modified_date . '|' . $offset]);
                    }else{
                        PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $urlname, 'url' => $latest_modified_date . '|' . $offset]);
                    }
                }
            //}while($allow_next_call);
        } catch (\Exception $e) {
            Log::error($user_integration_id."--IntacctGetInvoices-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    public function IntacctGetInvoicesStore($user_id,$user_integration_id,$invoiceids,$trading_partner_id,$chargesandallowanceitem,$url_modified,$urlname,$transactiontype,$last_modified_date,$offset,$pagesize,$destination_platform = '') {
       // echo 123; exit;
        $sync_start_date = $latest_modified_date = $last_modified_date;
        $AllowedInvoiceUpdates = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->AllowedInvoiceUpdates(['user_integration_id'=>$user_integration_id]);
           
        $query ='<read>
            <object>SODOCUMENT</object>
            <keys>'.implode(',',$invoiceids).'</keys>
            <fields>*</fields>
            <docparid>'.$transactiontype.'</docparid>
            </read>';

        //Note : intacct record number change by using differenct API like Object = ARINVOICE | Object = SODOCUMENT for invoice having different record number for same invoice
        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
     //   echo "<pre>"; print_r($response);
        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['SODOCUMENT'])){ //ARINVOICE
                $invoice = array();
                if(isset($response['operation']['result']['data']['SODOCUMENT']['RECORDNO'])){
                    $invoice[0] = $response['operation']['result']['data']['SODOCUMENT'];
                }else{
                    $invoice = $response['operation']['result']['data']['SODOCUMENT'];
                }

                //fetch Destination platform id by name
                $destination_platform_id = $this->helper->getPlatformIdByName( $destination_platform );

                //Log::info("Total Invoice: ".count($invoice));

                foreach($invoice as $k=>$rowinv){
                    $arr_invoice = [];


                    $DOCNO = (!is_array(@$rowinv['DOCNO'])) ? @$rowinv['DOCNO'] : null;
                    $WHENMODIFIED = (!is_array(@$rowinv['WHENMODIFIED'])) ? @$rowinv['WHENMODIFIED'] : null;
                    $WHENCREATED = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;


                    \Storage::disk('local')->append('Bhoopendra_Intacct_Missing_Invoice.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') . " | " . "user_integration_id : " . $user_integration_id  . "--> sync_start_date : " . $sync_start_date. " | record_invoice_missing : " . $rowinv['RECORDNO'].' ~ '. $DOCNO.' ~ '. $WHENCREATED . ' ~ '.$WHENMODIFIED);


                    $arr_invoice['invoice_code'] = (!is_array(@$rowinv['DOCNO'])) ? @$rowinv['DOCNO'] : null;
                    $arr_invoice['due_amt'] = (!is_array(@$rowinv['TOTALDUE'])) ? @$rowinv['TOTALDUE'] : 0;

                    /*if( $arr_invoice['due_amt'] == 0 )
                        continue;*/

                    $arr_invoice['due_date'] = (!is_array(@$rowinv['WHENDUE'])) ? @$rowinv['WHENDUE'] : null;
                    $arr_invoice['net_total'] = (!is_array(@$rowinv['TOTAL'])) ? @$rowinv['TOTAL'] : 0;
                    $arr_invoice['total_amt'] = (!is_array(@$rowinv['TOTALENTERED'])) ? @$rowinv['TOTALENTERED'] : 0;

                    $sync_status = 'Ready';
                    $platform_order_id = 0;
                    $order_id = $order_trading_partner_id = '';

                    $arr_invoice['total_paid_amt'] = (!is_array(@$rowinv['TOTALPAID'])) ? @$rowinv['TOTALPAID'] : 0;
                    $arr_invoice['payment_terms'] = (!is_array(@$rowinv['TERM']['NAME'])) ? @$rowinv['TERM']['NAME'] : null;
                    $api_customer_code = $rowinv['CUSTVENDID'] ??  null;

                    $discount = 0;
                    // if( $arr_invoice['payment_terms'] && str_contains( $arr_invoice['payment_terms'], '%' ) ){
                    //     $disArr = explode( "%", $arr_invoice['payment_terms'] );
                    //     $discount = $disArr[0];
                    // }

                    // if( $discount > 0 && $arr_invoice['total_amt'] > 0){
                    //     $total_paid_amt = ( $arr_invoice['total_amt'] * $discount ) / 100;
                    //     $arr_invoice['total_paid_amt'] = round( $arr_invoice['total_amt'] - $total_paid_amt, 2 );

                    //     $total_due_amt = ( $arr_invoice['due_amt'] * $discount ) / 100;
                    //     $arr_invoice['due_amt'] = round( $arr_invoice['due_amt'] - $total_due_amt, 2 );
                    // }

                    if(isset($rowinv['CREATEDFROM']) && !is_array($rowinv['CREATEDFROM'])){ //removed due to same po number for different orders  CUSTOMERPONUMBER
                    
                        $transactiontype_map=$this->map->getMappedDataByName($user_integration_id, null, "default_sales_order_transaction_type", ['name']);
                        $transactiontype_order= @$transactiontype_map->name ? $transactiontype_map->name : 'Sales Order';

                        $order_number = str_replace($transactiontype_order.'-','',$rowinv['CREATEDFROM']);

                        //fetch Customer Details
                        $customerArr = $this->mobj->getFirstResultByConditions('platform_customer', ['api_customer_code' => $api_customer_code, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id] );

                        // Maintain Order Status For Log
                        $result_order =  $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'order_number' => $order_number],['id','api_order_id','trading_partner_id']);
                        if($result_order){
                            $platform_order_id = $result_order->id;
                            $order_id = $result_order->api_order_id;
                            $order_trading_partner_id = $result_order->trading_partner_id;
                            $this->mobj->makeUpdate('platform_order',
                                ['invoice_sync_status' => $sync_status ],
                                ['id' => $platform_order_id]
                            );
                            $this->IntacctGetOrderDetailById($user_id,$user_integration_id,$trading_partner_id,$order_id);
                        }else{
                            // $sync_status = 'Pending';
                            //create platform orders
                            $orderArr['user_id'] = $user_id;
                            $orderArr['platform_id'] = $this->my_platform_id;
                            $orderArr['user_integration_id'] = $user_integration_id;
                            $orderArr['platform_customer_id'] = $customerArr->id ?? 0;
                            $orderArr['order_type'] = "SO";
                            $orderArr['api_order_id'] = preg_replace('/[^0-9\-]/', '', str_replace('-', '', $order_number ) );
                            $orderArr['api_order_reference'] = (!is_array(@$rowinv['CUSTOMERPONUMBER'])) ? @$rowinv['CUSTOMERPONUMBER'] : null;
                            $orderArr['order_number'] = $order_number;
                            $orderArr['currency'] = (!is_array(@$rowinv['CURRENCY'])) ? @$rowinv['CURRENCY'] : null;
                            $orderArr['order_date'] = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;
                            $orderArr['total_discount'] = $discount;
                            $orderArr['total_tax'] = 0;
                            $orderArr['total_amount'] = $arr_invoice['total_amt'];
                            $orderArr['net_amount'] = $arr_invoice['net_total'];
                            $orderArr['payment_date'] = null;
                            $orderArr['notes'] = null;
                            $orderArr['sync_status'] = $sync_status;//"Synced";
                            $orderArr['linked_id'] = $destination_platform_id;
                            $checkDestinationPlatformArr = Config::get('apisettings.SaveAdditionalOrderFromInvoiceOnIntacctWhenDest');//
                            if( isset( $checkDestinationPlatformArr[ strtolower( $destination_platform ) ] ) ){
                                $platform_order_id = PlatformOrder::insertGetId($orderArr);
                                $store_number = ( !is_array( @$rowinv['STORE_NUMBERS'] ) ) ? @$rowinv['STORE_NUMBERS'] : null;
                                PlatformOrderAdditionalInformation::updateOrCreate(
                                    ['platform_order_id' => $platform_order_id ],
                                    ['store_number' => $store_number, 'platform_order_id' => $platform_order_id ],
                                );
                            }
                        }
                    }else{
                        $sync_status = 'Pending';
                    }

                    $arr_invoice['user_id'] = $user_id;
                    $arr_invoice['platform_id'] = $this->my_platform_id;
                    $arr_invoice['user_integration_id'] = $user_integration_id;
                    $arr_invoice['platform_order_id'] = $platform_order_id;
                    $arr_invoice['platform_customer_id'] = $customerArr->id ?? 0;
                    $arr_invoice['trading_partner_id'] = $trading_partner_id;
                    $arr_invoice['api_invoice_id'] = $rowinv['RECORDNO'];
                    $arr_invoice['invoice_state'] = (!is_array(@$rowinv['STATE'])) ? @$rowinv['STATE'] : null;
                    $arr_invoice['invoice_payment_status'] = (!is_array(@$rowinv['PAYMENTSTATUS'])) ? @$rowinv['PAYMENTSTATUS'] : null;
                    $arr_invoice['ref_number'] = (!is_array(@$rowinv['CUSTOMERPONUMBER'])) ? @$rowinv['CUSTOMERPONUMBER'] : null;
                    $arr_invoice['order_doc_number'] = (!is_array(@$rowinv['PONUMBER'])) ? @$rowinv['PONUMBER'] : null;
                    $arr_invoice['invoice_date'] = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;
                    $arr_invoice['gl_posting_date'] = (!is_array(@$rowinv['WHENPOSTED'])) ? @$rowinv['WHENPOSTED'] : null;
                    $arr_invoice['api_created_at'] = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;
                    $arr_invoice['api_updated_at'] = (!is_array(@$rowinv['WHENMODIFIED'])) ? @$rowinv['WHENMODIFIED'] : null;
                    $arr_invoice['ship_date'] = (!is_array(@$rowinv['WHENDUE'])) ? @$rowinv['WHENDUE'] : null;
                    $arr_invoice['ship_via'] = (!is_array(@$rowinv['SHIPVIA'])) ? @$rowinv['SHIPVIA'] : null;
                    $arr_invoice['tracking_number'] = (!is_array(@$rowinv['TRACKINGNUMBER'])) ? @$rowinv['TRACKINGNUMBER'] : null;
                    $arr_invoice['ship_by_date'] = (!is_array(@$rowinv['SHIPBYDATE'])) ? @$rowinv['SHIPBYDATE'] : null;
                    $arr_invoice['customer_name'] = (!is_array(@$rowinv['CUSTVENDNAME'])) ? @$rowinv['CUSTVENDNAME'] : null;
                    $arr_invoice['message'] = (!is_array(@$rowinv['MESSAGE'])) ? @$rowinv['MESSAGE'] : null;
                    $arr_invoice['pay_date'] = (!is_array(@$rowinv['WHENPAID'])) ? @$rowinv['WHENPAID'] : null;
                    $arr_invoice['due_days'] = (!is_array(@$rowinv['DUE_IN_DAYS'])) ? @$rowinv['DUE_IN_DAYS'] : 0;
                    $arr_invoice['api_customer_code'] = $api_customer_code;
                    //$arr_invoice['linked_id'] = $destination_platform_id;
                    // if( $arr_invoice['api_customer_code'] ){
                    //     $customer_code[] = $arr_invoice['api_customer_code'];
                    // }

                    if(strtotime($latest_modified_date) < strtotime($arr_invoice['api_updated_at'])){
                        $latest_modified_date = $arr_invoice['api_updated_at'];
                    }

                    $pi = PlatformInvoice::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_invoice_id' => $rowinv['RECORDNO']])->select('id','api_updated_at','created_at')->first();

                    if ($pi) {
                        $platform_invoice_id = $pi->id;
                        if($AllowedInvoiceUpdates && $platform_order_id && $pi->api_updated_at!=$arr_invoice['api_updated_at']){
                            $AllowedInvoiceUpdatesBasedOnTime = app('App\Http\Controllers\Intacct\IntacctIntegrationCustomLogic')->AllowedInvoiceUpdatesBasedOnTime(['user_integration_id'=>$user_integration_id,'created_at'=>$pi->created_at]);
                            //Storage::disk('local')->append('BhoopendraIntacct.txt', "\r\n\r\n" . "Date -> " . date('Y-m-d H:i:s') .  " | userIntegrationId : " . $user_integration_id . " | platform_order_id : " . $platform_order_id . " | db api_updated_at : " . $pi->api_updated_at . " | api_updated_at : " . $arr_invoice['api_updated_at']. " | created_at : " . $pi->created_at . " | new_time : " . date("Y-m-d H:i:s", strtotime('+6 hours',strtotime($pi->created_at))) . " | arr_invoice : " . json_encode($arr_invoice,true));
                            if($AllowedInvoiceUpdates && $AllowedInvoiceUpdatesBasedOnTime && $platform_order_id && $pi->api_updated_at!=$arr_invoice['api_updated_at']){
                                $arr_invoice['sync_status'] = 'Ready';
                            }
                        }

                        PlatformInvoice::where(['id' => $platform_invoice_id])->update($arr_invoice);
                    } else {
                        if($trading_partner_id!='' && $order_trading_partner_id==$trading_partner_id){
                            $arr_invoice['sync_status'] = $sync_status;
                        }else{
                            $arr_invoice['sync_status'] = 'Pending';
                        }
                        $platform_invoice_id = PlatformInvoice::insertGetId($arr_invoice);
                    }

                    $total_qty = 0;
                    $sodocumententry = array();
                    if(isset($rowinv['SODOCUMENTENTRIES']['sodocumententry']['RECORDNO'])){
                        $sodocumententry[0] = $rowinv['SODOCUMENTENTRIES']['sodocumententry'];
                    }else{
                        $sodocumententry = $rowinv['SODOCUMENTENTRIES']['sodocumententry'];
                    }



                    if(count($sodocumententry)){
                        foreach($sodocumententry as $line){
                            $item_code = $api_product_id = "";
                            if((!is_array(@$line['ITEMID']))!=''){
                                $arritemid = explode('-',$line['ITEMID']);
                                $item_code = @$arritemid[0];
                                $pp = PlatformProduct::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_product_code' => $item_code])->select('api_product_id')->first();

                                if ($pp) {
                                    $api_product_id = $pp->api_product_id;
                                }
                            }

                            $unit_price = 0;
                            if(isset($line['UIPRICE']) && (!is_array(@$line['UIPRICE']))){
                                $unit_price = $line['UIPRICE'];
                            } else if(isset($line['PRICE']) && (!is_array(@$line['PRICE']))){
                                $unit_price = $line['PRICE'];
                            }
                            $shipped_qty = (isset($line['SHIPPED_QTY']) && !is_array(@$line['SHIPPED_QTY'])) ? $line['SHIPPED_QTY'] : 0;

                            $qty = (!is_array(@$line['QUANTITY'])) ? $line['QUANTITY'] : 0;
                            if($chargesandallowanceitem!=$item_code && isset($line['SHIPPED_QTY'])){
                                $total_qty+=$shipped_qty;
                            }else if($chargesandallowanceitem!=$item_code && isset($line['SHIPPED_QTY'])){
                                $total_qty+=$qty;
                            }

                            $arr_line = array();
                            $arr_line['api_code'] = $item_code;
                            $arr_line['row_type'] = 'ITEM';
                            $arr_line['platform_invoice_id'] = $platform_invoice_id;
                            $arr_line['api_invoice_line_id'] = @$line['RECORDNO'];
                            $arr_line['api_product_id'] = $api_product_id;
                            $arr_line['product_name'] = (!is_array(@$line['ITEMNAME'])) ? $line['ITEMNAME'] : null;
                            $arr_line['qty'] = $qty;
                            $arr_line['shipped_qty'] = $shipped_qty;
                            $arr_line['unit_price'] = $unit_price;
                            $arr_line['price'] =(!is_array(@$line['PRICE'])) ? $line['PRICE'] : 0;
                            $arr_line['uom'] = (!is_array(@$line['UNIT'])) ? $line['UNIT'] : null;
                            $arr_line['description'] = (!is_array(@$line['EXTENDED_DESCRIPTION'])) ? $line['EXTENDED_DESCRIPTION'] : null;
                            $arr_line['total'] = (!is_array(@$line['TOTAL'])) ? $line['TOTAL'] : 0;
                            $arr_line['total_weight'] = (!is_array(@$line['TOTAL_WEIGHT'])) ? (int)@$line['TOTAL_WEIGHT'] : 0;
                            // $arr_line['linked_id'] = $destination_platform_id;
                            $pil = PlatformInvoiceLine::where(['platform_invoice_id' => $platform_invoice_id, 'api_invoice_line_id' => @$line['RECORDNO']])->select('id')->count();

                            if ($pil > 0) {
                                PlatformInvoiceLine::where(['platform_invoice_id' => $platform_invoice_id, 'api_invoice_line_id' => @$line['RECORDNO']])->update($arr_line);
                            } else {
                                PlatformInvoiceLine::insertGetId($arr_line);
                            }
                        }
                    }
                    PlatformInvoice::where(['id' => $platform_invoice_id])->update(['total_qty'=>$total_qty]);
                }




                //if($last_modified_date==$latest_modified_date){
                    if(count($invoiceids) < $pagesize){
                        $offset = 0;
                        if($urlname=='invoice_backup_last_time') {
                            $latest_modified_date = date('Y-m-d',strtotime($sync_start_date),strtotime('+1 day'));
                        }
                    }else{
                        $offset = $offset + intval($pagesize);
                        $latest_modified_date = $last_modified_date;
                        if($urlname=='invoice_backup_last_time') {
                            $latest_modified_date = date('Y-m-d',strtotime($sync_start_date));
                        }
                    }


                    if($url_modified){
                        PlatformUrl::where(['id' => $url_modified->id])->update(['url' => $latest_modified_date . '|' . $offset]);
                    }else{
                        PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $urlname, 'url' => $latest_modified_date . '|' . $offset]);
                    }

                /*} else {
                    if($url_modified){
                        PlatformUrl::where(['id' => $url_modified->id])->update(['url' => null]);
                    }
                }*/

                // if ($is_initial_sync) {////added by @GK
                //     $return_response = 'data Remaining';
                //     // $offset = $offset + 1;
                // }
            } 
        }else{
            $return_response = $response['api_error'];
        }
    }
	
	
    public function IntacctGetCustomerDetailById($user_id,$user_integration_id,$customer_id)
    {

              $query ="<readByName>
            <object>CUSTOMER</object>
            <keys>".$customer_id."</keys>
            <fields>DISPLAYCONTACT.CONTACTNAME</fields>
        </readByName>";

        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['CUSTOMER'])){
                $row = $response['operation']['result']['data']['CUSTOMER'];
                if(isset($row['DISPLAYCONTACT']['CONTACTNAME']) && !is_array($row['DISPLAYCONTACT']['CONTACTNAME'])) {
                    return $row['DISPLAYCONTACT']['CONTACTNAME'];
                }
            }
        }
        return null;
    }
	

    public function test_intacct(){
        die;
        $user_id = 187;
        $user_integration_id = 178;
        $this->IntacctGetAllProducts($user_id,$user_integration_id,1);
die;


        $user_id = 187;
        $user_integration_id = 176;
        $query ="<readByQuery>
        <object>ITEM</object>
        <fields>*</fields>
        <query>ITEMID = '00045'</query>
        <pagesize>20</pagesize>
    </readByQuery>";
        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

        echo "<pre>";
        print_r($response);
        die;




        $user_id = 187;
        $user_integration_id = 176;
        $user_workflow_rule_id = 424;
        $sync_start_date = '03/14/2022 23:55:00';

        $select = '<field>RECORDNO</field><field>WHENMODIFIED</field>';
                $query ="<query>
                        <object>SODOCUMENT</object>
                        <select>".$select."</select>";

                //if($is_initial_sync==0){
                $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENMODIFIED</field>
                                <value>".$sync_start_date."</value>
                            </greaterthanorequalto>
                        </filter>";
                //}
                $query.="<orderby>
                        <order>
                            <field>WHENMODIFIED</field>
                            <ascending />
                        </order>
                    </orderby>";


                $query.="<docparid>Sales Invoice</docparid>
                <pagesize>500</pagesize>
                            <offset>0</offset>
                        </query>";


                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
                echo "<pre>";
                print_r($response);
                die;



        $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,0);
        die;
        $user_id = 187;
        $user_integration_id = 176;
        $query ="<readByQuery>
        <object>SODOCUMENT</object>
        <fields>*</fields>
        <query/>
        <pagesize>100</pagesize>
        <docparid>Sales Invoice</docparid>
      </readByQuery>";

      $query ="<read>
      <object>SODOCUMENT</object>
      <keys>23623</keys>
      <fields>*</fields>
      <docparid>Sales Invoice</docparid>
  </read>";

        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

        echo "<pre>";
        print_r($response);
        die;


        $user_id = 187;
        $user_integration_id = 176;
        $itemids = array('00110','00237','00108','00110');
        $info =  ['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'price_list_name'=>'Reinhart','itemids'=>implode(',',$itemids)];

        $query_item_price_list ='<readByQuery>
                <object>SOPRICELISTENTRY</object>
                <fields>*</fields>
                <query>Name = \''.$info['price_list_name'].'\' AND ITEMID IN ('.$info['itemids'].')</query>
                <pagesize>200</pagesize>
            </readByQuery>';

           /* $query_item_price_list ='<readByQuery>
          <object>SOPRICELISTENTRY</object>
          <fields>*</fields>
          <query>Name = \'Reinhart\' AND ITEMID IN (00237,00108,00110)</query>
          <pagesize>100</pagesize>
        </readByQuery>';*/

            $response_price = $this->intacctapi->CallAPI($info['user_id'],$info['user_integration_id'],$query_item_price_list);
            echo "<pre>";
            print_r($response_price);
            die;


        $user_id = 187;
        $user_integration_id = 176;
        $source_platform = 'spscommerce';
        $user_workflow_rule_id = 423;
        $this->IntacctCreateSalesOrder($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,'PO','Ready');

        die;
        $user_id = 187;
        $user_integration_id = 174;

        $query ="<query>
                <object>ITEMCROSSREF</object>
                <select>
                <field>ITEMID</field>
                <field>REFTYPE</field>
                </select>
                <filter>
                <equalto>
                    <field>ITEMALIASID</field>
                    <value>008079726</value>
                </equalto>
                </filter>
            </query>";

        $query ="<readByQuery>
            <object>ITEMCROSSREF</object>
            <fields>*</fields>
            <query>ITEMALIASID = '008079726'</query>
            <pagesize>20</pagesize>
        </readByQuery>";


    $query ='<read>
                <object>ITEMCROSSREF</object>
                <keys>50226,50244,50262,50280</keys>
                <fields>*</fields>
                </read>';



            $query ="<readByQuery>
        <object>ITEMCROSSREF</object>
        <fields>*</fields>
        <query>ITEMALIASID = 'W0620'</query>
        <pagesize>20</pagesize>
    </readByQuery>";



$query ="<readByQuery>
<object>PRODUCTLINE</object>
<fields>*</fields>
<query></query>
<pagesize>100</pagesize>
</readByQuery>";

$query ="<readByQuery>
    <object>ITEM</object>
    <fields>*</fields>
    <query>ITEMID = '00027'</query>
    <pagesize>20</pagesize>
</readByQuery>";

$query ="<readByQuery>
    <object>SOPRICELIST</object>
    <fields>*</fields>
    <query></query>
    <pagesize>100</pagesize>
</readByQuery>";

        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);

        echo "<pre>";
        print_r($response);
        die;


      $query ="<readByQuery>
      <object>SODOCUMENT</object>
      <fields>*</fields>
      <query/>
      <pagesize>50</pagesize>
      <docparid>Sales Order</docparid>
    </readByQuery>";

    $query ="<lookup>
    <object>SODOCUMENT</object>
       <docparid>Sales Order</docparid>
   </lookup>";

    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
    echo "<pre>";
    print_r($response);
    die;


        $user_id = 187;
        $user_integration_id = 174;
        $user_workflow_rule_id = 370;
        $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,0);
        die;


        $user_id = 187;
        $user_integration_id = 175;
        $sync_start_date = '02/07/2022 23:10:20';

        $offset = 0;
        $process_limit = 200;
        $select = '<field>RECORDNO</field><field>WHENMODIFIED</field>';
        $query ="<query>
                <object>CUSTOMER</object>
                <select>".$select."</select>";

        //if($is_initial_sync==0){
        $query.="<filter>
                    <greaterthanorequalto>
                        <field>WHENMODIFIED</field>
                        <value>".$sync_start_date."</value>
                    </greaterthanorequalto>
                </filter><orderby>
                <order>
                    <field>WHENMODIFIED</field>
                    <ascending />
                </order>
            </orderby>";
        //}

        $query.="<pagesize>".$process_limit."</pagesize>
                    <offset>".$offset."</offset>
                </query>";
        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
        echo "<pre>";
        print_r($response);
        //die;

        if($response['api_status']=='success'){
            if(isset($response['operation']['result']['data']['CUSTOMER'])){ //ARINVOICE
                $products = array();
                if(isset($response['operation']['result']['data']['CUSTOMER']['RECORDNO'])){
                    $products[0] = $response['operation']['result']['data']['CUSTOMER'];
                }else{
                    $products = $response['operation']['result']['data']['CUSTOMER'];
                }

                $productids = array();
                foreach($products as $productid){
                    $productids[] = $productid['RECORDNO'];
                }

            }

        }


            //do{



                $query ='<read>
                <object>CUSTOMER</object>
                <keys>'.implode(',',$productids).'</keys>
                <fields>*</fields>
                </read>';


                /*
                $query ="<query>
                        <object>ITEM</object>
                        <select>".$select."</select>";

                if($is_initial_sync==0){
                $query.="<filter>
                            <greaterthanorequalto>
                                <field>WHENMODIFIED</field>
                                <value>".$whenmodified."</value>
                            </greaterthanorequalto>
                        </filter>";
                }

                $query.="<pagesize>".$process_limit."</pagesize>
                            <offset>".$offset."</offset>
                        </query>";
                */


                $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
                echo "<pre>";
                print_r($response);
                die;


        $user_id = 187;
        $user_integration_id = 175;
   /*     $query ="<readByQuery>
        <object>SODOCUMENT</object>
        <fields>*</fields>
        <query/>
        <pagesize>500</pagesize>
        <docparid>Sales Invoice</docparid>
      </readByQuery>";
*/


      $query ="<read>
      <object>SODOCUMENT</object>
      <keys>1794</keys>
      <fields>*</fields>
      <docparid>Sales Invoice</docparid>
  </read>";


/*
      $query ="<readByQuery>
      <object>SODOCUMENT</object>
      <fields>*</fields>
      <query/>
      <pagesize>500</pagesize>
      <docparid>Sales Invoice</docparid>
    </readByQuery>";
*/
    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
    echo "<pre>";
    print_r($response);
    die;


        die;




        die;
        $user_id = 187;
        $user_integration_id = 174;
        $user_workflow_rule_id = 370;
        $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,0);
        die;




        die;

        $arr_so_order = array();
        $arr_so_order['user_id'] = 187;
        $arr_so_order['platform_id'] = $this->my_platform_id;
        $arr_so_order['user_integration_id'] = 174;
        $arr_so_order['api_order_id'] = 832;
        $arr_so_order['trading_partner_id'] = '755ALLMANDAPACK';
        $arr_so_order['order_type'] = 'SO';
        $arr_so_order['api_order_reference'] = '85586';
        $arr_so_order['order_number'] = '100264-SO';
        $arr_so_order['vendor'] = null;
        $arr_so_order['sync_status'] = 'Pending';
        $arr_so_order['linked_id'] = 165234;

        $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order',$arr_so_order);

        $this->mobj->makeUpdate('platform_order',['linked_id'=>$linked_platform_order_id],['id'=>165234]);


        $arr_so_order = array();
        $arr_so_order['user_id'] = 187;
        $arr_so_order['platform_id'] = $this->my_platform_id;
        $arr_so_order['user_integration_id'] = 174;
        $arr_so_order['api_order_id'] = 329;
        $arr_so_order['trading_partner_id'] = '755ALLMANDAPACK';
        $arr_so_order['order_type'] = 'SO';
        $arr_so_order['api_order_reference'] = '93163';
        $arr_so_order['order_number'] = '100137-SO';
        $arr_so_order['vendor'] = null;
        $arr_so_order['sync_status'] = 'Pending';
        $arr_so_order['linked_id'] = 165201;

        $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order',$arr_so_order);

        $this->mobj->makeUpdate('platform_order',['linked_id'=>$linked_platform_order_id],['id'=>165201]);

        $arr_so_order = array();
        $arr_so_order['user_id'] = 187;
        $arr_so_order['platform_id'] = $this->my_platform_id;
        $arr_so_order['user_integration_id'] = 174;
        $arr_so_order['api_order_id'] = 336;
        $arr_so_order['trading_partner_id'] = '755ALLMANDAPACK';
        $arr_so_order['order_type'] = 'SO';
        $arr_so_order['api_order_reference'] = '93111';
        $arr_so_order['order_number'] = '100140-SO';
        $arr_so_order['vendor'] = null;
        $arr_so_order['sync_status'] = 'Pending';
        $arr_so_order['linked_id'] = 165209;

        $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order',$arr_so_order);

        $this->mobj->makeUpdate('platform_order',['linked_id'=>$linked_platform_order_id],['id'=>165209]);



        $arr_so_order = array();
        $arr_so_order['user_id'] = 187;
        $arr_so_order['platform_id'] = $this->my_platform_id;
        $arr_so_order['user_integration_id'] = 174;
        $arr_so_order['api_order_id'] = 324;
        $arr_so_order['trading_partner_id'] = '755ALLMANDAPACK';
        $arr_so_order['order_type'] = 'SO';
        $arr_so_order['api_order_reference'] = '94042';
        $arr_so_order['order_number'] = '100136-SO';
        $arr_so_order['vendor'] = null;
        $arr_so_order['sync_status'] = 'Pending';
        $arr_so_order['linked_id'] = 165199;

        $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order',$arr_so_order);

        $this->mobj->makeUpdate('platform_order',['linked_id'=>$linked_platform_order_id],['id'=>165199]);
        die;


        $user_id = 187;
        $user_integration_id = 175;
        $query ="<readByQuery>
        <object>SODOCUMENT</object>
        <fields>*</fields>
        <query/>
        <pagesize>500</pagesize>
        <docparid>Sales Order</docparid>
      </readByQuery>";

/*
      $query ="<readByQuery>
      <object>SODOCUMENT</object>
      <fields>*</fields>
      <query/>
      <pagesize>500</pagesize>
      <docparid>Sales Invoice</docparid>
    </readByQuery>";
*/
    $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
    echo "<pre>";
    print_r($response);
    die;






        $user_id = 187;
        $user_integration_id = 175;
        $source_platform = 'spscommerce';
        $user_workflow_rule_id = 411;
        $order_type = 'SO';
        $this->IntacctCreateSalesOrder($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,$order_type,'Ready', $record_id = '');

die;

        //Associated Grocers of the South-810
        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 178;
        $platform_id = 3;
        $api_id = 'C-10662';
        $name = 'Associated Grocers AL';
        $api_code = '0040271080000';
        $description = '{"address1":"P.O. Box 11044","address2":"","city":"Birmingham","state":"AL","postal_code":"35202","country":"United States","price_list":"Associated Grocers AL"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 178;
        $platform_id = 3;
        $api_id = 'C-10662';
        $name = 'Associated Grocers AL';
        $api_code = '0040271080000';
        $description = '{"address1":"3600 Vanderbilt Road","address2":"","city":"Birmingham","state":"AL","postal_code":"35202","country":"United States","price_list":"Associated Grocers AL"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        //Associated Grocers - LA-810

        $sync_object_id = $this->helper->getObjectId('vendor_address');
        $user_id = 187;
        $user_integration_id = 175;
        $platform_id = 3;
        $api_id = 'VN';
        $name = 'Distribution Center';
        $api_code = '0081711830010';
        $description = '{"address1":"2191 Wooddale Blvd.","address2":"","city":"Baton Rouge","state":"LA","postal_code":"70806","country":"US","price_list":""}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 175;
        $platform_id = 3;
        $api_id = 'C-10663';
        $name = 'Associated Grocers, Inc.';
        $api_code = '0038514410000';
        $description = '{"address1":"8600 Anselmo Lane","address2":"","city":"Baton Rouge","state":"LA","postal_code":"70810","country":"United States","price_list":"Associated Grocers Inc"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 175;
        $platform_id = 3;
        $api_id = 'C-10663';
        $name = 'Associated Grocers, Inc.';
        $api_code = '0038514410000';
        $description = '{"address1":"9393 Perkins Road","address2":"","city":"Baton Rouge","state":"LA","postal_code":"70810","country":"United States","price_list":"Associated Grocers Inc"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        //Brookshire Grocery Company-810

        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 177;
        $platform_id = 3;
        $api_id = 'C-10675';
        $name = 'Brookshire Tyler';
        $api_code = '0089536630000';
        $description = '{"address1":"P.O. Box 1411","address2":"","city":"Tyler","state":"TX","postal_code":"75710","country":"United States","price_list":"Brookshire Tyler"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 177;
        $platform_id = 3;
        $api_id = 'C-10675';
        $name = 'Brookshire Tyler';
        $api_code = '0089536630001';
        $description = '{"address1":"4376 Old Jacksonville Hwy","address2":"","city":"Tyler","state":"TX","postal_code":"75703","country":"United States","price_list":"Brookshire Tyler"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        //Walmart-USA-810

        $sync_object_id = $this->helper->getObjectId('vendor_address');
        $user_id = 187;
        $user_integration_id = 163;
        $platform_id = 3;
        $api_id = 'VN';
        $name = 'Distribution Center';
        $api_code = '0081711830010';
        $description = '{"address1":"2191 Wooddale Blvd.","address2":"","city":"Baton Rouge","state":"LA","postal_code":"70806","country":"US","price_list":""}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 163;
        $platform_id = 3;
        $api_id = 'C-10118';
        $name = 'Walmart DC';
        $api_code = '0078742042428';
        $description = '{"address1":"Wal Mart Stores/Accts.Payable","address2":"1108 S.E. 10th Street","city":"Bentonville","state":"AR","postal_code":"727168006","country":"US","price_list":""}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 163;
        $platform_id = 3;
        $api_id = 'C-10457';
        $name = 'Walmart Brun, AL #7019';
        $api_code = '0078742045870';
        $description = '{"address1":"1005 Sara G.Lott Blvd","address2":"","city":"Brundidge","state":"AL","postal_code":"36010","country":"US","price_list":"Wal Mart DC"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 163;
        $platform_id = 3;
        $api_id = 'C-10786';
        $name = 'Walmart New Caney, TX #7010';
        $api_code = '0078742042428';
        $description = '{"address1":"20131 GENE CAMPBELL BLVD","address2":"","city":"NEW CANEY","state":"TX","postal_code":"77357","country":"US","price_list":"Wal Mart DC"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 163;
        $platform_id = 3;
        $api_id = 'C-10458';
        $name = 'Walmart Roberts #6057';
        $api_code = '0078742033808';
        $description = '{"address1":"45346 Parkway Blvd","address2":"","city":"Robert","state":"LA","postal_code":"70455","country":"US","price_list":"Wal Mart DC"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);




        //Associated Wholesale Grocers (AWG)


        $sync_object_id = $this->helper->getObjectId('vendor_address');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'VN';
        $name = 'Distribution Center';
        $api_code = '0081711830010';
        $description = '{"address1":"2191 Wooddale Blvd.","address2":"","city":"Baton Rouge","state":"LA","postal_code":"70806","country":"US","price_list":""}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10664';
        $name = 'AWG LA';
        $api_code = '006943062PRLA';
        $description = '{"address1":"5000 Kansas Ave.","address2":"Louisiana Division","city":"Kansas City","state":"KS","postal_code":"66106","country":"United States","price_list":"AWG"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);

        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10664';
        $name = 'Associated (LA) Wholesale Groc';
        $api_code = '006943062PRLA';
        $description = '{"address1":"63331 Old Military Road","address2":"","city":"Pearl River","state":"LA","postal_code":"70452","country":"US","price_list":"AWG"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);



        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10665';
        $name = 'AWG MS';
        $api_code = '006943062SHMS';
        $description = '{"address1":"5000 Kansas Ave.","address2":"Southaven Division","city":"Kansas City","state":"KS","postal_code":"66106","country":"United States","price_list":"AWG"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10665';
        $name = 'Associated (MS) Wholesale Groc';
        $api_code = '006943062SHMS';
        $description = '{"address1":"8690 Tulane Road","address2":"","city":"Southaven","state":"MS","postal_code":"386711043","country":"US","price_list":"AWG"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10666';
        $name = 'AWG OK';
        $api_code = '006943062SCOK';
        $description = '{"address1":"5000 Kansas Ave.","address2":"Oklahoma City Division","city":"Kansas City","state":"KS","postal_code":"66106","country":"United States","price_list":"Associated OK Wholesale Groc"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10666';
        $name = 'Associated (OK) Wholesale Groc';
        $api_code = '006943062SCOK';
        $description = '{"address1":"5600 South Council","address2":"","city":"Oklahoma City","state":"OK","postal_code":"73179","country":"US","price_list":"Associated OK Wholesale Groc"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);



        $sync_object_id = $this->helper->getObjectId('bill_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10666';
        $name = 'AWG OK';
        $api_code = '006943062OCOK';
        $description = '{"address1":"5000 Kansas Ave.","address2":"Oklahoma City Division","city":"Kansas City","state":"KS","postal_code":"66106","country":"United States","price_list":"Associated OK Wholesale Groc"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


        $sync_object_id = $this->helper->getObjectId('ship_to_customer');
        $user_id = 187;
        $user_integration_id = 174;
        $platform_id = 3;
        $api_id = 'C-10666';
        $name = 'Associated (OK) Wholesale Groc';
        $api_code = '006943062OCOK';
        $description = '{"address1":"5600 South Council","address2":"","city":"Oklahoma City","state":"OK","postal_code":"73179","country":"US","price_list":"Associated OK Wholesale Groc"}';
        DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);



         //Reinhart Foodservice OSR-810

         $sync_object_id = $this->helper->getObjectId('bill_to_customer');
         $user_id = 187;
         $user_integration_id = 176;
         $platform_id = 3;
         $api_id = 'C-10077';
         $name = 'Reinhart Foodservice LLC';
         $api_code = '134968726';
         $description = '{"address1":"dba Conco Foodservice","address2":"P.O. Box 0728","city":"LaCrosse","state":"WI","postal_code":"546020728","country":"US","price_list":""}';
         DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


         $sync_object_id = $this->helper->getObjectId('ship_to_customer');
         $user_id = 187;
         $user_integration_id = 176;
         $platform_id = 3;
         $api_id = 'C-10254';
         $name = 'Reinhart Foodservice LLC';
         $api_code = '061441556023';
         $description = '{"address1":"107 B Avenue","address2":"","city":"Valdosta","state":"GA","postal_code":"31601","country":"United States","price_list":"Reinhart"}';
         DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);




         $sync_object_id = $this->helper->getObjectId('ship_to_customer');
         $user_id = 187;
         $user_integration_id = 176;
         $platform_id = 3;
         $api_id = 'C-10252';
         $name = 'Conco Food Service NO';
         $api_code = '061441556027';
         $description = '{"address1":"918 Edwards Ave.","address2":"","city":"Harahan","state":"LA","postal_code":"70123","country":"US","price_list":"Reinhart"}';
         DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);


         $sync_object_id = $this->helper->getObjectId('ship_to_customer');
         $user_id = 187;
         $user_integration_id = 176;
         $platform_id = 3;
         $api_id = 'C-10253';
         $name = 'Conco Shreveport';
         $api_code = '061441556028';
         $description = '{"address1":"524 West 61st Street","address2":"","city":"Shreveport","state":"LA","postal_code":"71106","country":"US","price_list":"Reinhart"}';
         DB::table('platform_object_data')->insert(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$sync_object_id,'api_id'=>$api_id,'name'=>$name,'api_code'=>$api_code,'description'=>$description]);



        die;


        $user_id = 187;
        $user_integration_id = 178;
        $this->IntacctGetAllProducts($user_id,$user_integration_id,1);


die;


       $user_id = 187;
        $user_integration_id = 178;
        $this->IntacctGetAllProducts($user_id,$user_integration_id,1);
        die;
        $query ="<readByQuery>
          <object>ITEM</object>
          <fields>*</fields>
          <query/>
          <pagesize>500</pagesize>
        </readByQuery>";

 /*
        $query ="<readByQuery>
        <object>SODOCUMENT</object>
        <fields>*</fields>
        <query/>
        <pagesize>500</pagesize>
        <docparid>Sales Invoice</docparid>
      </readByQuery>";
*/
      $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
      echo "<pre>";
      print_r($response);
      die;

      $user_id = 187;
        $user_integration_id = 174;
        $user_workflow_rule_id = 370;

        $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,0);
      die;





        $user_id = 149;
        $user_integration_id = 259;
        $user_workflow_rule_id = 414;
        $response = $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,0);
        dd($response);
            die;
        $user_id = 149;
        $user_integration_id = 361;


      $query ="<readByQuery>
          <object>SODOCUMENT</object>
          <fields>*</fields>
          <query/>
          <pagesize>500</pagesize>
          <docparid>Sales Invoice</docparid>
        </readByQuery>";
        $query ="<read>
        <object>SODOCUMENT</object>
        <keys>53155</keys>
        <fields>*</fields>
        <docparid>Sales Invoice</docparid>
      </read>";

      $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
      echo "<pre>";
      print_r($response);
      die;



        $user_id = 149;
        $user_integration_id = 365;
        /*$query ="<readByQuery>
    <object>ITEM</object>
    <fields>*</fields>
    <query></query>
    <pagesize>1000</pagesize>
</readByQuery>";*/

         /*$query ="<readByQuery>
          <object>SODOCUMENT</object>
          <fields>*</fields>
          <query/>
          <pagesize>500</pagesize>
          <docparid>Sales Invoice</docparid>
        </readByQuery>";*/
       $query ="<read>
        <object>SODOCUMENT</object>
        <keys>54465</keys>
        <fields>*</fields>
        <docparid>Sales Invoice</docparid>
      </read>";


      $query ="<readByQuery>
          <object>CUSTOMER</object>
          <fields>*</fields>
          <query/>
          <pagesize>500</pagesize>
        </readByQuery>";

        $response = $this->intacctapi->CallAPI($user_id,$user_integration_id,$query);
        echo "<pre>";
        print_r($response);
        die;


        $user_id = 149;
        $query ="<read>
        <object>SODOCUMENT</object>
        <keys>53127</keys>
        <fields>*</fields>
        <docparid>Sales Invoice</docparid>
      </read>";

        $response = $this->intacctapi->CallAPI($user_id,$query);
        echo "<pre>";
        print_r($response);
        die;


        $user_id = 149;
        $user_integration_id = 361;

        $response = $this->IntacctGetAllCustomers($user_id,$user_integration_id,0);
dd($response);





        $user_id = 149;
        $query ="<readByQuery>
        <object>ARINVOICE</object>
        <fields>*</fields>
        <query></query>
        <pagesize>500</pagesize>
    </readByQuery>";


        $response = $this->intacctapi->CallAPI($user_id,$query);
        echo "<pre>";
        print_r($response);
        die;



        $user_id = 149;
        $id = 'W0620';
        $query ="<query>
                    <object>ITEMCROSSREF</object>
                    <select>
                    <field>ITEMID</field>
                    <field>UNIT</field>
                    </select>
                    <filter>
                    <equalto>
                        <field>ITEMALIASID</field>
                        <value>'.$id.'</value>
                    </equalto>
                    </filter>
                </query>";


                $response = $this->intacctapi->CallAPI($user_id,$query);
                echo "<pre>";
                print_r($response);
                die;



      /*
       $user_id = 149;
        $user_integration_id = 130;
        $this->IntacctGetAllProducts($user_id,$user_integration_id,1);
       die;

       echo $ref = $this->mobj->encrypt_decrypt('TGN2ZElqOUtuaTRDa0t0VjFzNWFUOFNmZXY2dVhmUXd2Sk9YVkhEZ20ycWM0VmhVY29kbGlPcXR4aHVWeDBEYQ==','decrypt');

       die;
*/
        $user_id = 149;
        $user_integration_id = 130;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform_id = 'spscommerce';
        $user_workflow_rule_id = 190;
        $is_initial_sync = 1;

        $this->IntacctGetInvoices($user_id,$user_integration_id,$user_workflow_rule_id,$is_initial_sync);


        //$this->IntacctCreateSalesOrder($user_id,$source_platform_id,$user_integration_id,$user_workflow_rule_id,$order_type,$sync_status);

die();

$ref = "b2RDU1JKT3JNYzlsZjhhV0czZEJRdz09";
        $clientid = "YUx0RmU4OTl3S0t4b2U4QXlJMGRyQT09";
        $clientsecret = "aTh3cnIyV2MxdFU0NVFUK2JrSnhJdz09";

        $ref = $this->mobj->encrypt_decrypt($ref,'decrypt');
        $clientid = $this->mobj->encrypt_decrypt($clientid,'decrypt');
        $clientsecret = $this->mobj->encrypt_decrypt($clientsecret,'decrypt');
        echo "<br/>".$ref;
        echo "<br/>".$clientid;
        echo "<br/>".$clientsecret;
        die;
/*
        echo "hii";
        $ref = $this->mobj->decryptString('MTU1MTk2NDAzMg==');
        $clientid = $this->mobj->decryptString('QVBJV09SWE1QUA==');
        $clientsecret = $this->mobj->decryptString('QFdFY2VjZWM0MkBAIw==');
        */



        $user_id = 133;
        $user_integration_id = 29;
        $user_workflow_rule_id = 63;
        $platform_id = 'intacct';
        //$this->IntacctGetWarehouses($user_id,$user_integration_id);
        //$this->IntacctGetLocations($user_id,$user_integration_id);
        //$this->IntacctGetAllProducts($user_id,$user_integration_id,1);
        //$this->IntacctGetAllCustomers($user_id,$user_integration_id,1);
        //die;


        /*
        $default_order_location_data = DB::table('platform_data_mapping as pdm')
        ->join("platform_objects as po",function($join){
        $join->on("pdm.platform_object_id","=","po.id")
            ->on("pdm.status","=","po.status");
        })->join("platform_object_data as pod",function($join){
            $join->on("pdm.destination_row_id","=","pod.id");
        })->where(['pod.user_id' => $user_id,'pdm.user_integration_id' => $user_integration_id,'pdm.mapping_type' => 'default','pdm.data_map_type' => 'object','po.id' => 'order_location'])->select(['api_code'])->first();
*/



        die;

        $user_id = 97;
        $platform_id = 'intacct';

        $user_integration_id = 12;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform_id = 'spscommerce';
        $user_workflow_rule_id = 2;

        //$this->GetInvoicesFromIntacct($user_id,$platform_id,$user_integration_id);

        $this->IntacctCreateSalesOrder($user_id,$source_platform_id,$user_integration_id,$user_workflow_rule_id,$order_type,$sync_status);

        //$this->GetAllProductsFromIntacct($user_id,$platform_id,$user_integration_id);
        die;

    }




}
