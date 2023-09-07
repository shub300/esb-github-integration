<?php

namespace App\Http\Controllers\Spscommerce;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Http\Controllers\Spscommerce\SpscommerceUtility;
use App\Http\Controllers\Spscommerce\Api\SpscommerceApi;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformOrder;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use App\Models\PlatformProduct;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformInvoice;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformProductInventory;
use App\Models\PlatformCustomerAdditionalInformation;
use App\Models\PlatformInvoiceLine;
use App\Models\PlatformUrl;
use Lang;

class SpscommerceApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->spsapi = new SpscommerceApi();
        $this->utisps = new SpscommerceUtility();
        $this->helper = new ConnectionHelper();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        $this->my_platform = 'spscommerce';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    public function InitiateSpsAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.spscommerce_auth", compact('platform'));
    }



    public function InitiateSpscomOauth(Request $request)
    {

        $account_id = trim($request->account_id);
        $client_id = trim($request->app_id);
        $client_secret = trim($request->app_secret);
        $env_type = trim($request->env_type);

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            Session::put('auth_msg', Lang::get('tags.validate'));
            return redirect()->back();
         }

        if ($env_type == 'on') { // checke account type .
            $env_type = 'production';
        } else {
            $env_type = 'sandbox';
        }

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        //$isAllowed =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id], ['app_ref', 'client_id', 'client_secret']);
        if ($client_id != '' && $client_secret != '') {
            $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/RedirectHandlerSpsCom'));

            $state_i = $user_id . '-account-' . $account_id . '-account-' . $client_id . '-account-' . $client_secret . '-account-' . $env_type;

            $url = \Config::get('apiconfig.SpscomOauthUrl') . "/authorize?audience=api://api.spscommerce.com/&scope=offline_access&response_type=code&client_id=" . $client_id . "&redirect_uri=" . $redirect_url . "&state=" . $state_i;
            return redirect($url);
        } else {
            Session::put('auth_msg', 'Authentication Error');
            echo '<script>window.close();</script>';
        }
    }


    public function RedirectHandlerSpsCom(Request $request)
    {
        if (isset($request->code)) {
            $code = $request->code;
            // $AccountCode = Crypt::decryptString($record->account_code);

            $state = $request->state;

            if ($state) { // Valid request
                $arrstate = explode('-account-', $state);
                $user_id = $arrstate[0];
                $AccountCode = $arrstate[1];
                $client_id = $arrstate[2];
                $client_secret = $arrstate[3];
                $env_type = $arrstate[4];

                // $record = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $this->my_platform_id,'user_id' => $user_id], ['app_id', 'app_secret']);

                // $client_id = $this->mobj->encrypt_decrypt($record->app_id,'decrypt');
                // $client_secret = $this->mobj->encrypt_decrypt($record->app_secret,'decrypt');
                $scope = 'openid offline_access ';
                $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/RedirectHandlerSpsCom'));

                //echo $user_id."--".$AccountCode;

                // curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
                //         curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($curl_post_data));
                $curl_post_data = array(
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirect_url,
                );

                $headers = ['Content-Type' => 'application/json'];
                $response = $this->mobj->makeRequest('POST', \Config::get('apiconfig.SpscomOauthUrl') . '/oauth/token', $curl_post_data, $headers);

                if ($response) {
                    //$decode_val = $response;
                    $decode_val = json_decode($response->getBody()->getContents(), true);
                    if (isset($decode_val['access_token'])) {
                        $OauthData = [
                            'access_token' => $this->mobj->encrypt_decrypt($decode_val['access_token'], 'encrypt'),
                            'refresh_token' => $this->mobj->encrypt_decrypt($decode_val['refresh_token'], 'encrypt'),
                            'token_type' => $decode_val['token_type'],
                            'expires_in' => $decode_val['expires_in'],
                            'user_id' => $user_id,
                            'app_id' => $this->mobj->encrypt_decrypt($client_id, 'encrypt'),
                            'app_secret' => $this->mobj->encrypt_decrypt($client_secret, 'encrypt'),
                            'platform_id' => $this->my_platform_id,
                            'env_type' => $env_type,
                            'token_refresh_time' => time()
                        ];

                        // $meheaders = ['Content-Type' => 'application/x-www-form-urlencoded','Authorization'=> 'Bearer ' .$decode_val['access_token']];
                        // $meresponse = $this->mobj->makeRequest('GET',\Config::get('apiconfig.SpscomOauthUrl').'/me',[],$meheaders);

                        $ufound = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'account_name' => $AccountCode], ['id']);

                        if ($ufound) {
                            $this->mobj->makeUpdate('platform_accounts', $OauthData, ['id' => $ufound->id]);
                        } else {
                            $OauthData['account_name'] = $AccountCode;
                            $this->mobj->makeInsert('platform_accounts', $OauthData);
                        }
                    } else { // When Token not found
                        $ufound = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'account_name' => $AccountCode], ['id']);

                        if ($ufound) {
                            $this->mobj->makeUpdate('platform_accounts', ['access_token' => null, 'refresh_token' => null], ['id' => $ufound->id]);
                        }
                    }
                    echo '<script>window.close();</script>';
                } else {
                    Session::put('auth_msg', 'Authentication Error');
                    echo '<script>window.close();</script>';
                }
            }
        } else { // When code not received from BP
            Session::put('auth_msg', 'Authentication Error');
            echo '<script>window.close();</script>';
        }
    }


    public function GetAccessTokenUsingRefreshToken($user_id, $id, $refresh_token)
    {
        try {

            $app_info = $this->mobj->getFirstResultByConditions('platform_accounts', ['id' => $id], ['app_id', 'app_secret', 'refresh_token']);


            if ($app_info) {
                $client_id = $this->mobj->encrypt_decrypt($app_info->app_id, 'decrypt');
                $client_secret = $this->mobj->encrypt_decrypt($app_info->app_secret, 'decrypt');

                $refresh_token = $this->mobj->encrypt_decrypt($app_info->refresh_token, 'decrypt');

                $curl_post_data = array(
                    'grant_type' => 'refresh_token',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token
                );

                $headers = ['Content-Type: application/json'];
                $response = $this->mobj->makeRequest('POST', \Config::get('apiconfig.SpscomOauthUrl') . '/oauth/token', $curl_post_data, $headers);

                if ($response) {
                    //$decode_val = $response;
                    $decode_val = json_decode($response->getBody()->getContents(), true);
                    echo "<pre>";
                    print_r($decode_val);

                    if (isset($decode_val['access_token'])) {
                        $OauthData = [
                            'access_token' => $this->mobj->encrypt_decrypt($decode_val['access_token'], 'encrypt'),
                            'expires_in' => $decode_val['expires_in'],
                            'token_refresh_time' => time(),
                            'updated_at' => now()
                        ];


                        $this->mobj->makeUpdate('platform_accounts', $OauthData, ['id' => $id]);
                    } else { // error

                        //$this->mobj->makeUpdate('platform_accounts',['access_token' => null,'refresh_token' => null],['id'=>$id]);
                    }
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
            \Storage::disk('local')->append('testCrone.txt', 'SPS_refresh_resp : ' . json_encode($return_response));
        }
    }




    public function ExecuteEventSpscommerce($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        $response = true;

        ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
        if ($method == 'GET' && $event == 'PURCHASEORDER') {
            $response = $this->SpsGetAllPO($user_id, $user_integration_id, $platform_workflow_rule_id);
        } else if ($method == 'GET' && $event == 'ACKNOWLEDGEMENT') {
            $response = $this->SpsGetAllAcknowledgement($user_id, $user_integration_id, $platform_workflow_rule_id);
        } else if ($method == 'GET' && $event == 'INVENTORY') {
            $response = $this->SpsGetAllInventory($user_id, $user_integration_id, $platform_workflow_rule_id);
        } else if ($method == 'MUTATE' && $event == 'INVOICE') {
            //$response = $this->SpsCreateInvoice($user_id,$source_platform_id,$user_integration_id,$platform_workflow_rule_id,$user_workflow_rule_id,$record_id);
            $response = $this->SpsCreateUpdateInvoice($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id);
        } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $response = $this->SpsCreateUpdatePO($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'PO', 'Ready', $record_id);
        } else if ($method == 'GET' && $event == 'PURCHASEORDERINVOICE') {
            $response = $this->SpsGetAllInvoices($user_id, $user_integration_id, $platform_workflow_rule_id);
        } else if ($method == 'GET' && $event == 'GOODSINNOTE') {
            $response = $this->SpsGetAllShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $destination_platform_id);
        } else if ($method == 'MUTATE' && $event == 'ACKNOWLEDGEMENT') {
            $response = $this->SpsCreateUpdateAcknowledgement($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id);
        } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
            $response = $this->SpsCreateUpdateShipment($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id);
        } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
            // $response = $this->SPSUpdateInventory($user_id,$source_platform_id,$user_integration_id,$platform_workflow_rule_id,$user_workflow_rule_id,'Ready',$record_id);
        }




        return $response;
    }


    public function SpsGetAllPO($user_id, $user_integration_id, $platform_workflow_rule_id)

    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {


            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {


                //PAGINATION TIME DATE Filter
                $result = $this->spsapi->GetAllPO($sps_account, $user_id, $user_integration_id);
                $listpo = json_decode($result, true);
                //echo "<pre>";
                //print_r($listpo);
                //die;
                //$orderdetail = DB::table('platform_order')->select('order_number')->where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                //$last_order_number = @$orderdetail->order_number;
                $last_order_number = null;

                if (is_array($listpo)) {

                    if (count($listpo) > 0) {
                        foreach ($listpo as $row) {
                            if (isset($row['key'])) {

                                /*$po = $this->spsapi->GetPOById($sps_account,$user_id, $row['key']);

                                $podetail = json_decode($po, true);
                                echo "<pre>";
                                print_r($podetail);
                                */

                                $return_response = $this->SpsGetPOById($sps_account, $user_id, $user_integration_id, $platform_workflow_rule_id, $last_order_number, $row['key']);
                            }

                            /*if($return_response=='break'){
                                break;
                            }*/
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsGetAllPO-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }



    public function getMappingFileFieldValueSPS($user_id, $user_integration_id, $mapped_file, $check_field_name)
    {

        $csvFile = file(public_path() . '/', $mapped_file);
        $i = 0;
        $return_value = '';
        foreach ($csvFile as $line) {
            if ($i != 0) {
                $arrrow = str_getcsv($line);

                $fields = explode('/', $arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[2] having default value
                if ($fields[0] == $check_field_name && trim($arrrow[2]) != '') {
                    $return_value = $arrrow[2];
                }
            }
            $i++;
        }
    }

    public function getFileNameUsingObject($user_id, $user_integration_id, $platform_workflow_rule_id, $object_name, $check_file_name)
    {

        $CustomData = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, $object_name, ['custom_data'], "default");
        $mapped_file = "";
        if ($CustomData) {
            $arrfile = explode(',', $CustomData->custom_data);
            foreach ($arrfile as $rowfile) {
                if (strpos($rowfile, $check_file_name) !== false) {
                    $mapped_file = $rowfile;
                }
            }
        }

        return $mapped_file;
    }

    public function SpsGetPOById($sps_account, $user_id, $user_integration_id, $platform_workflow_rule_id, $last_order_number, $po_id)

    { //$po_id -> it contain spscommerce folder like testout/PO11111

        $return_response = true;
        try {

            $product_identity_obj_id = $this->helper->getObjectId('product_identity');

            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;


            $maping_data = $this->map->getMappedField($user_integration_id, null, $product_identity_obj_id);
            $source_row_data = $destination_row_data = '';
            if ($maping_data) {
                if ($maping_data['destination_platform_id'] == 'spscommerce') {
                    $destination_row_data = $maping_data['source_row_data'];
                    $source_row_data = $maping_data['destination_row_data'];
                } else {
                    $destination_row_data = $maping_data['destination_row_data'];
                    $source_row_data = $maping_data['source_row_data'];
                }
            }

            $po = $this->spsapi->GetPOById($sps_account, $user_id, $po_id);

            $podetail = json_decode($po, true);
            //echo "<pre>";
            //print_r($podetail);
            if (isset($podetail['Header']['OrderHeader']['TradingPartnerId'])) {

                $TradingPartnerId = @$podetail['Header']['OrderHeader']['TradingPartnerId'];

                if ($trading_partner_id == $TradingPartnerId) {

                    // Getting User Integration ID for trading partner because in a SPS API we get all trafing partner order data which we have to identify for which order for which integration
                    /*if($TradingPartnerId!=''){

                        $user_integration = DB::table('platform_data_mapping as pdm')
                    ->join("platform_objects as po",function($join){
                    $join->on("pdm.platform_object_id","=","po.id")
                        ->on("pdm.status","=","po.status");
                    })->where(['pdm.mapping_type' => 'default','po.name' => 'trading_partner_id','pdm.data_map_type' => 'custom','pdm.custom_data' => $TradingPartnerId])->select(['user_integration_id'])->first();

                    $user_integration_id = @$user_integration->user_integration_id ? $user_integration->user_integration_id : $user_integration_id;


                    }

                    $maping_data = $this->map->getMappedField($user_integration_id, null, $product_identity_obj_id);
                    $source_row_data = $destination_row_data = '';
                    if ($maping_data) {
                        if ($maping_data['destination_platform_id'] == 'spscommerce') {
                            $destination_row_data = $maping_data['source_row_data'];
                            $source_row_data = $maping_data['destination_row_data'];
                        } else {
                            $destination_row_data = $maping_data['destination_row_data'];
                            $source_row_data = $maping_data['source_row_data'];
                        }
                    }*/



                    $PurchaseOrderNumber = @$podetail['Header']['OrderHeader']['PurchaseOrderNumber'];

                    if ($PurchaseOrderNumber == $last_order_number) {
                        $return_response = 'break'; //break
                    } else {

                        //storing entries into files
                        $path = public_path() . '/esb_asset/spscommerce/' . $user_integration_id;
                        if (!file_exists($path)) {
                            mkdir($path, 0777, true);
                        }

                        $file_name = "PO_" . $PurchaseOrderNumber . "_" . $user_integration_id . '.txt';
                        file_put_contents($path . '/' . $file_name, $po);


                        $PrimaryEmail = @$podetail['Header']['Contacts'][0]['PrimaryEmail'];
                        $ContactName = @$podetail['Header']['Contacts'][0]['ContactName'];
                        $platform_customer_id = 0;
                        if (trim($PrimaryEmail) != '' || trim($ContactName) != '') {

                            $arr_customer = array();
                            $arr_customer['user_id'] = $user_id;
                            $arr_customer['platform_id'] = $this->my_platform_id;
                            $arr_customer['user_integration_id'] = $user_integration_id;
                            $arr_customer['customer_name'] = @$podetail['Header']['Contacts'][0]['ContactName'];
                            $arr_customer['phone'] = @$podetail['Header']['Contacts'][0]['PrimaryPhone'];
                            $arr_customer['fax'] = @$podetail['Header']['Contacts'][0]['PrimaryFax'];
                            $arr_customer['email'] = @$podetail['Header']['Contacts'][0]['PrimaryEmail'];


                            $customer_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => @$podetail['Header']['Contacts'][0]['PrimaryEmail']], ['id']);


                            if ($customer_details) {
                                $platform_customer_id = $customer_details->id;
                                $this->mobj->makeUpdate('platform_customer', $arr_customer, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => @$podetail['Header']['Contacts'][0]['PrimaryEmail']]);
                            } else {
                                $arr_customer['sync_status'] = 'Ready';
                                $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                            }
                        }



                        $total_discount = 0;
                        if (isset($podetail['Header']['PaymentTerms'])) {
                            foreach ($podetail['Header']['PaymentTerms'] as $rowdis) {
                                $TermsDiscountAmount = @$rowdis['TermsDiscountAmount'] ? @$rowdis['TermsDiscountAmount'] : 0;
                                $total_discount += floatval($TermsDiscountAmount);
                            }
                        }

                        $delivery_date = "";
                        if (isset($podetail['Header']['Dates'])) {
                            foreach ($podetail['Header']['Dates'] as $rowdate) {
                                if (isset($rowdate['DateTimeQualifier']) && ($rowdate['DateTimeQualifier'] == '10' || $rowdate['DateTimeQualifier'] == '010' || $rowdate['DateTimeQualifier'] == '002' || $rowdate['DateTimeQualifier'] == '02')) {
                                    $delivery_date = $rowdate['Date'];
                                }
                            }
                        }


                        $notes = array();
                        if (isset($podetail['Header']['Notes'])) {
                            foreach ($podetail['Header']['Notes'] as $rownote) {
                                $notes[] = $rownote['Note'];
                            }
                        }


                        $arr_order = array();
                        $arr_order['platform_customer_id'] = $platform_customer_id;
                        $arr_order['customer_email'] = @$podetail['Header']['Contacts'][0]['PrimaryEmail'];
                        $arr_order['trading_partner_id'] = @$podetail['Header']['OrderHeader']['TradingPartnerId'];
                        $arr_order['api_order_reference'] = $PurchaseOrderNumber;
                        $arr_order['order_number'] = $PurchaseOrderNumber;
                        $arr_order['order_date'] = @$podetail['Header']['OrderHeader']['PurchaseOrderDate'];
                        $arr_order['department'] = @$podetail['Header']['OrderHeader']['Department'];
                        $arr_order['vendor'] = @$podetail['Header']['OrderHeader']['Vendor'];
                        $arr_order['total_discount'] = $total_discount;
                        $arr_order['total_tax'] = 0;
                        $arr_order['total_amount'] = @$podetail['Summary']['TotalAmount'] ? @$podetail['Summary']['TotalAmount'] : 0;

                        $arr_order['delivery_date'] = @$delivery_date;
                        $arr_order['notes'] = implode(' | ', $notes);
                        $arr_order['due_days'] = @$podetail['Header']['PaymentTerms'][0]['TermsDueDay'] ? @$podetail['Header']['PaymentTerms'][0]['TermsDueDay'] : 0;
                        $arr_order['file_name'] =  $file_name;



                        $whereorder = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->OrderAdditinalWhereConditions(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_order_id' => $PurchaseOrderNumber, 'order_date' => @$podetail['Header']['OrderHeader']['PurchaseOrderDate']]);

                        $order_details = PlatformOrder::where($whereorder)->select('id')->first();

                        //$order_details = $this->mobj->getFirstResultByConditions('platform_order', $whereorder, ['id']);

                        if ($order_details) {
                            $platform_order_id = $order_details->id;
                            //$this->mobj->makeUpdate('platform_order', $arr_order, $whereorder);
                            $order_details->platform_customer_id = $arr_order['platform_customer_id'];
                            $order_details->customer_email = $arr_order['customer_email'];
                            $order_details->trading_partner_id = $arr_order['trading_partner_id'];
                            $order_details->api_order_reference = $arr_order['api_order_reference'];
                            $order_details->order_number = $arr_order['order_number'];
                            $order_details->order_date = $arr_order['order_date'];
                            $order_details->department = $arr_order['department'];
                            $order_details->vendor = $arr_order['vendor'];
                            $order_details->total_discount = $arr_order['total_discount'];
                            $order_details->total_tax = $arr_order['total_tax'];
                            $order_details->total_amount = $arr_order['total_amount'];
                            $order_details->delivery_date = $arr_order['delivery_date'];
                            $order_details->notes = $arr_order['notes'];
                            $order_details->due_days = $arr_order['due_days'];
                            $order_details->file_name = $arr_order['file_name'];
                            $order_details->save();
                        } else {
                            $arr_order['user_id'] = $user_id;
                            $arr_order['platform_id'] = $this->my_platform_id;
                            $arr_order['user_integration_id'] = $user_integration_id;
                            $arr_order['api_order_id'] = $PurchaseOrderNumber;
                            $arr_order['order_type'] = "PO";
                            $arr_order['sync_status'] = 'Pending';
                            $arr_order['order_updated_at'] = date('Y-m-d H:i:s');
                            $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                        }




                        $arr_order_address_billing = array();
                        $arr_order_address_shipping = array();
                        foreach (@$podetail['Header']['Address'] as $address) {

                            if ($address['AddressTypeCode'] == 'BT') {
                                $arr_order_address_billing['address_type'] = "billing";
                                $arr_order_address_billing['platform_order_id'] = $platform_order_id;
                                $arr_order_address_billing['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address_billing['address_name'] = @$address['AddressName'];
                                $arr_order_address_billing['address1'] = @$address['Address1'];
                                $arr_order_address_billing['address2'] = @$address['Address2'];
                                $arr_order_address_billing['address3'] = @$address['Address3'];
                                $arr_order_address_billing['address4'] = @$address['Address4'];
                                $arr_order_address_billing['city'] = @$address['City'];
                                $arr_order_address_billing['state'] = @$address['State'];
                                $arr_order_address_billing['postal_code'] = @$address['PostalCode'];
                                $arr_order_address_billing['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address_billing, ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address_billing);
                                }
                            } else if ($address['AddressTypeCode'] == 'ST' || $address['AddressTypeCode'] == 'BY') {
                                $arr_order_address_shipping['address_type'] = "shipping";
                                $arr_order_address_shipping['platform_order_id'] = $platform_order_id;
                                $arr_order_address_shipping['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address_shipping['address_name'] = @$address['AddressName'];
                                $arr_order_address_shipping['address1'] = @$address['Address1'];
                                $arr_order_address_shipping['address2'] = @$address['Address2'];
                                $arr_order_address_shipping['address3'] = @$address['Address3'];
                                $arr_order_address_shipping['address4'] = @$address['Address4'];
                                $arr_order_address_shipping['city'] = @$address['City'];
                                $arr_order_address_shipping['state'] = @$address['State'];
                                $arr_order_address_shipping['postal_code'] = @$address['PostalCode'];
                                $arr_order_address_shipping['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address_shipping, ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address_shipping);
                                }
                            } else if ($address['AddressTypeCode'] == 'VN') {
                                $arr_order_address_vendor = array();
                                $arr_order_address_vendor['address_type'] = "vendor";
                                $arr_order_address_vendor['platform_order_id'] = $platform_order_id;
                                $arr_order_address_vendor['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address_vendor['address_name'] = @$address['AddressName'];
                                $arr_order_address_vendor['address1'] = @$address['Address1'];
                                $arr_order_address_vendor['address2'] = @$address['Address2'];
                                $arr_order_address_vendor['address3'] = @$address['Address3'];
                                $arr_order_address_vendor['address4'] = @$address['Address4'];
                                $arr_order_address_vendor['city'] = @$address['City'];
                                $arr_order_address_vendor['state'] = @$address['State'];
                                $arr_order_address_vendor['postal_code'] = @$address['PostalCode'];
                                $arr_order_address_vendor['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "vendor"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address_vendor, ['platform_order_id' => $platform_order_id, 'address_type' => "vendor"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address_vendor);
                                }
                            } else {

                                $arr_order_address = array();
                                $arr_order_address['address_type'] = "other";
                                $arr_order_address['platform_order_id'] = $platform_order_id;
                                $arr_order_address['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address['address_name'] = @$address['AddressName'];
                                $arr_order_address['address1'] = @$address['Address1'];
                                $arr_order_address['address2'] = @$address['Address2'];
                                $arr_order_address['address3'] = @$address['Address3'];
                                $arr_order_address['address4'] = @$address['Address4'];
                                $arr_order_address['city'] = @$address['City'];
                                $arr_order_address['state'] = @$address['State'];
                                $arr_order_address['postal_code'] = @$address['PostalCode'];
                                $arr_order_address['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "other"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => "other"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address);
                                }
                            }
                        }

                        if (count($arr_order_address_billing) > 0 && count($arr_order_address_shipping) == 0) {
                            $arr_order_address_billing['address_type'] = "shipping";

                            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);

                            if ($ct_address > 0) {
                                $this->mobj->makeUpdate('platform_order_address', $arr_order_address_billing, ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);
                            } else {
                                $this->mobj->makeInsert('platform_order_address', $arr_order_address_billing);
                            }
                        } else if (count($arr_order_address_billing) == 0 && count($arr_order_address_shipping) > 0) {
                            $arr_order_address_shipping['address_type'] = "billing";

                            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);

                            if ($ct_address > 0) {
                                $this->mobj->makeUpdate('platform_order_address', $arr_order_address_shipping, ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);
                            } else {
                                $this->mobj->makeInsert('platform_order_address', $arr_order_address_shipping);
                            }
                        }

                        foreach (@$podetail['LineItem'] as $lineitem) {

                            $api_product_id = '';
                            if ($source_row_data == 'sku') {
                                $api_product_id = @$lineitem['OrderLine']['BuyerPartNumber'];
                            } else if ($source_row_data == 'ean') {
                                $api_product_id = @$lineitem['OrderLine']['EAN'];
                            } else if ($source_row_data == 'gtin') {
                                $api_product_id = @$lineitem['OrderLine']['GTIN'];
                            } else if ($source_row_data == 'upc') {
                                $api_product_id = @$lineitem['OrderLine']['UPCCaseCode'] ? @$lineitem['OrderLine']['UPCCaseCode'] : @$lineitem['OrderLine']['ConsumerPackageCode'];
                            } else if ($source_row_data == 'mpn') {
                                $api_product_id = @$lineitem['OrderLine']['VendorPartNumber'];
                            }

                            //echo "source_row_data-->".$source_row_data."-->destination_row_data-->".$destination_row_data."<br/>";
                            //echo "api_product_id-->".$api_product_id."-->platform_order_id-->".$platform_order_id."<br/>";


                            $arr_order_line = array();
                            $arr_order_line['platform_order_id'] = $platform_order_id;
                            $arr_order_line['item_row_sequence'] = @$lineitem['OrderLine']['LineSequenceNumber'] ? $lineitem['OrderLine']['LineSequenceNumber'] : 0;
                            $arr_order_line['api_product_id'] = $api_product_id; //@$lineitem['OrderLine']['ProductID'][0]['PartNumber'];
                            $arr_order_line['sku'] = @$lineitem['OrderLine']['BuyerPartNumber']; //@$lineitem['OrderLine']['ProductID'][0]['PartNumber'];
                            $arr_order_line['ean'] = @$lineitem['OrderLine']['EAN'];
                            $arr_order_line['gtin'] = @$lineitem['OrderLine']['GTIN'];
                            $arr_order_line['upc'] = @$lineitem['OrderLine']['UPCCaseCode'] ? @$lineitem['OrderLine']['UPCCaseCode'] : @$lineitem['OrderLine']['ConsumerPackageCode'];
                            $arr_order_line['mpn'] = @$lineitem['OrderLine']['VendorPartNumber'];
                            $arr_order_line['qty'] = @$lineitem['OrderLine']['OrderQty'] ? @$lineitem['OrderLine']['OrderQty'] : 0;
                            $arr_order_line['uom'] = @$lineitem['OrderLine']['OrderQtyUOM'] ? @$lineitem['OrderLine']['OrderQtyUOM'] : null;
                            $arr_order_line['price'] = @$lineitem['OrderLine']['PurchasePrice'] ? @$lineitem['OrderLine']['PurchasePrice'] : 0;
                            $arr_order_line['unit_price'] = @$lineitem['PriceInformation'][0]['UnitPrice'] ? @$lineitem['PriceInformation'][0]['UnitPrice'] : 0;
                            $arr_order_line['description'] = @$lineitem['ProductOrItemDescription'][0]['ProductDescription'];
                            $arr_order_line['notes'] = @$lineitem['Notes'][0]['Note'];
                            $arr_order_line['subtotal'] = (isset($lineitem['OrderLine']['PurchasePrice']) && isset($lineitem['OrderLine']['OrderQty'])) ? round(floatval($lineitem['OrderLine']['PurchasePrice']) * floatval($lineitem['OrderLine']['OrderQty']), 2) : 0;
							$arr_order_line['taxes'] = @json_encode($lineitem['ChargesAllowances']);




                            $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'api_product_id' => $api_product_id]);

                            if ($ct_order_line > 0) {
                                $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'api_product_id' => $api_product_id]);
                            } else {
                                $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                            }
                        }


                        if(isset($arr_order['sync_status'])){
                            if($arr_order['sync_status']=='Pending'){
                                PlatformOrder::where(['id'=>$platform_order_id])->update(['sync_status'=>'Ready']);
                            }
                        }

                        //Delete SPS File After Use
                        if ($user_integration_id == 163 || $user_integration_id == 174 || $user_integration_id == 175 || $user_integration_id == 176 || $user_integration_id == 177 || $user_integration_id == 178 || $user_integration_id == 149 || $user_integration_id == 189 || $user_integration_id == 191 || $user_integration_id == 192 || $user_integration_id == 193 || $user_integration_id == 194 || $user_integration_id == 301) {
                            $delete_response = $this->spsapi->DeleteTransactions($sps_account, $user_id, $po_id);
                        }






                        $return_response = true;
                    }
                }
            } else {

                $return_response = 'Trading Partner ID Not Found';
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsGetPOById-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }


        return $return_response;
    }





    public function SpsCreateInvoice($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {


            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {


                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");

                $CustomDataMappedFile = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "invoice_file_mapping", ['custom_data'], "default");


                if ($CustomDataMappedFile) {

                    $trading_partner_id = @$CustomDataTradingPartner->custom_data;
                    $mapped_file = @$CustomDataMappedFile->custom_data;


                    $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                    $sync_object_id = $this->helper->getObjectId('invoice');
                    $sync_status = "Ready";
                    $order_state = "Converted";


                    $process_limit = 30;
                    $offset = 0;

                    //do{
                    $allow_next_call = false; // This flag will help for pagination

                    if ($record_id != '') {

                        $results = DB::table('platform_invoice as pi')
                            ->join("platform_order as po", function ($join) {
                                $join->on("pi.platform_order_id", "=", "po.id");
                            })->where(['pi.user_id' => $user_id, 'pi.platform_id' => $source_platform_id, 'pi.user_integration_id' => $user_integration_id, 'pi.id' => $record_id])->select(['po.trading_partner_id', 'po.file_name', 'po.order_number', 'pi.platform_order_id', 'pi.order_doc_number', 'pi.invoice_code', 'pi.ref_number', 'pi.invoice_date', 'pi.ship_date', 'pi.ship_via', 'pi.tracking_number', 'pi.id', 'pi.total_amt', 'pi.due_days', 'pi.total_qty'])->orderBy('pi.id', 'asc')->skip($offset)->take($process_limit)->get();
                        //,'pi.order_state' => $order_state,'po.trading_partner_id' => $trading_partner_id

                    } else {

                        $results = DB::table('platform_invoice as pi')
                            ->join("platform_order as po", function ($join) {
                                $join->on("pi.platform_order_id", "=", "po.id");
                            })->where(['pi.user_id' => $user_id, 'pi.platform_id' => $source_platform_id, 'pi.user_integration_id' => $user_integration_id, 'pi.sync_status' => $sync_status])->select(['po.trading_partner_id', 'po.file_name', 'po.order_number', 'pi.platform_order_id', 'pi.order_doc_number', 'pi.invoice_code', 'pi.ref_number', 'pi.invoice_date', 'pi.ship_date', 'pi.ship_via', 'pi.tracking_number', 'pi.id', 'pi.total_amt', 'pi.due_days', 'pi.total_qty'])->orderBy('pi.id', 'asc')->skip($offset)->take($process_limit)->get();
                        //,'pi.order_state' => $order_state,'po.trading_partner_id' => $trading_partner_id

                    }


                    if (count($results) == $process_limit) {
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset += $process_limit;
                    }


                    if ($results) {
                        foreach ($results as $row) {


                            $ref_number = $row->ref_number;
                            $invoice_code = $row->invoice_code;
                            $id = $row->id;
                            $platform_order_id = $row->platform_order_id;
                            $order_number = $row->order_number;


                            $postdata = $this->utisps->GetStructuredInvoicePostData($user_id, $user_integration_id, $platform_workflow_rule_id, $mapped_file, $row);


                            $result_invoice = $this->spsapi->CreateInvoice($sps_account, $user_id, $invoice_code, $postdata);
                            $result = json_decode($result_invoice, true);


                            if ($result) {

                                if (isset($result['key'])) {

                                    $invoice_id = $result['key'];

                                    $arr_invoice = array();
                                    $arr_invoice['user_id'] = $user_id;
                                    $arr_invoice['platform_id'] = $this->my_platform_id;
                                    $arr_invoice['user_integration_id'] = $user_integration_id;
                                    $arr_invoice['api_invoice_id'] = $invoice_id;
                                    $arr_invoice['trading_partner_id'] = $trading_partner_id;
                                    $arr_invoice['platform_order_id'] = $platform_order_id;
                                    $arr_invoice['ref_number'] = $ref_number;
                                    $arr_invoice['sync_status'] = 'Pending';
                                    $arr_invoice['linked_id'] = $id;

                                    $linked_platform_invoice_id = $this->mobj->makeInsertGetId('platform_invoice', $arr_invoice);

                                    $this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Synced', 'linked_id' => $linked_platform_invoice_id], ['id' => $id]);

                                    $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Synced'], ['id' => $platform_order_id]);


                                    $sync_error = null;

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $id, $sync_error);
                                } else {

                                    $this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Failed'], ['id' => $id]);

                                    $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Failed'], ['id' => $platform_order_id]);

                                    $sync_error = @$result['error']['errorDescription'] ? $result['error']['errorDescription'] : "Error";
                                    //error
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                                }
                            }
                        }
                    }

                    //}while($allow_next_call);


                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsCreateInvoice-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }




    public function SpsCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();

        $return_response = true;
        try {



            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {

                $offset = 0;
                $process_limit = 30;

                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");

                $CustomDataMappedFile = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "po_file_mapping", ['custom_data'], "default");


                if ($CustomDataMappedFile) {

                    $trading_partner_id = @$CustomDataTradingPartner->custom_data;


                    $mapped_file = @$CustomDataMappedFile->custom_data;

                    $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                    $sync_object_id = $this->helper->getObjectId('purchase_order');



                    //do{
                    $allow_next_call = false; // This flag will help for pagination

                    if ($record_id != '') {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type, 'id' => $record_id], ['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'platform_customer_id', 'shipping_method', 'delivery_date', 'api_order_reference', 'order_status', 'linked_id','updated_at'], ['id' => 'asc'], $process_limit, $offset);
                    } else {

                        $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type, 'sync_status' => $sync_status], ['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'platform_customer_id', 'shipping_method', 'delivery_date', 'api_order_reference', 'order_status', 'linked_id','updated_at'], ['id' => 'asc'], $process_limit, $offset);
                        //,'trading_partner_id'=>$trading_partner_id
                    }

                    if (count($result_order) == $process_limit) {
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset += $process_limit;
                    }

                    if ($result_order) {
                        foreach ($result_order as $roworder) {

                            $id = $roworder->id;
                            $api_order_id = $roworder->api_order_id;
                            $linked_id = $roworder->linked_id;
                            $order_number = $roworder->order_number;
                            if($linked_id){
                                $po_id = 'PC' . $api_order_id . '.json';
                            }else{
                                $po_id = 'PO' . $api_order_id . '.json';
                            }


                            $result_order_line = DB::table('platform_order_line as pol')->join('platform_product as pp', 'pol.api_product_id', '=', 'pp.api_product_id')->select('pol.product_name', 'pol.sku', 'pol.qty', 'pol.unit_price', 'pol.price', 'pol.total', 'pp.description', 'pp.mpn', 'pp.ean', 'pp.gtin', 'pp.upc')->where(['pol.platform_order_id' => $id, 'pp.user_id' => $user_id, 'pp.user_integration_id' => $user_integration_id, 'pp.platform_id' => $source_platform_id])->get();

                            if(count($result_order_line) > 0){


                                $result_customer = array();
                                if ($roworder->platform_customer_id != '') {
                                    $result_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $roworder->platform_customer_id], ['customer_name', 'api_customer_id']);
                                }


                                $result_order_additional_info = $this->mobj->getFirstResultByConditions('platform_order_additional_information', ['platform_order_id' => $id], ['is_drop_ship', 'parent_order_id']);

                                //$result_order_line = $this->mobj->getResultByConditions('platform_order_line',['platform_order_id'=>$id],['product_name','ean','sku','gtin','upc','qty','unit_price','description','price','mpn','total']);




                                $result_order_address = $this->mobj->getResultByConditions('platform_order_address', ['platform_order_id' => $id], ['address_type', 'address_name', 'firstname', 'lastname', 'company', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'email', 'phone_number']);


                                $postdata = $this->utisps->GetStructuredPOPostData($user_id, $user_integration_id, $platform_workflow_rule_id, $source_platform_id, $trading_partner_id, $sync_object_id, $mapped_file, $result_customer, $roworder, $result_order_line, $result_order_address, $result_order_additional_info);

                                echo $postdata;
                                //die;
                                $result_sps_order = $this->spsapi->CreatePO($sps_account, $user_id, $po_id, $postdata);
                                $result = json_decode($result_sps_order, true);
                                echo "<pre>";
                                print_r($result);

                                if ($result) {

                                    if (isset($result['key'])) {


                                        $arr_order = array();
                                        $arr_order['user_id'] = $user_id;
                                        $arr_order['platform_id'] = $this->my_platform_id;
                                        $arr_order['user_integration_id'] = $user_integration_id;
                                        $arr_order['trading_partner_id'] = $trading_partner_id;
                                        $arr_order['api_order_id'] = $api_order_id;
                                        $arr_order['order_number'] = $order_number;
                                        $arr_order['order_type'] = 'PO';
                                        $arr_order['linked_id'] = $id;
                                        $arr_order['api_order_reference'] = $result['key'];
                                        $arr_order['sync_status'] = 'Pending';

                                        if ($linked_id) {
                                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $linked_id]);
                                        } else {
                                            $linked_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                                        }

                                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s'), 'linked_id' => $linked_id], ['id' => $id]);


                                        $sync_error = null;

                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $id, $sync_error);
                                    } else {

                                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

                                        $sync_error = @$result['error']['errorDescription'] ? $result['error']['errorDescription'] : "Error";
                                        //error
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                                    }
                                }

                            }else{

                                if($source_platform=='brightpearl'){

                                    $ct_url = PlatformUrl::where(['status' => 1, 'user_integration_id' => $user_integration_id,'platform_id' => $source_platform_id,'url_name' => 'purchase_orders', 'response' => 'reattempt', 'url' => '/order/'.$api_order_id])->count();
                                    if($ct_url < 4){

                                        PlatformUrl::insert(['url' => '/order/'.$api_order_id, 'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'url_name' => 'purchase_orders', 'response' => 'reattempt']);

                                        $this->mobj->makeUpdate('platform_order',['sync_status'=>'Pending'],['id'=>$id]);

                                    }else{
                                        $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);
                                        $sync_error = "Line items Missing.";
                                        $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);
                                    }

                                }else{

                                    $this->mobj->makeUpdate('platform_order',['sync_status'=>'Failed','order_updated_at'=>date('Y-m-d H:i:s')],['id'=>$id]);
                                        $sync_error = "Line items Missing.";
                                        $this->log->syncLog($user_id,$user_integration_id,$user_workflow_rule_id,$source_platform_id,$this->my_platform_id,$sync_object_id,'failed',$id,$sync_error);

                                }



                            }



                        }
                    }

                    //}while($allow_next_call);

                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsCreateUpdatePO-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }




    public function SpsGetAllAcknowledgement($user_id, $user_integration_id, $platform_workflow_rule_id)

    {

        $this->mobj->AddMemory();
        $return_response = true;

        try {


            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {
                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");
                $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;



                //if($CustomDataTradingPartner){
                //$trading_partner_id = $CustomDataTradingPartner->custom_data;


                //PAGINATION TIME DATE Filter
                $result = $this->spsapi->GetAllAcknowledgments($sps_account, $user_id);
                $listack = json_decode($result, true);
                echo "<pre>";
                print_r($listack);
                //die;
                //$orderdetail = DB::table('platform_order')->select('api_order_id')->where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(api_created_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                //$last_order_id = @$orderdetail->api_order_id;
                if (is_array($listack)) {

                    if (count($listack) > 0) {
                        foreach ($listack as $row) {

                            if (isset($row['key'])) {

                                $po_id = $row['key'];

                                $ack = $this->spsapi->GetAcknowledgmentById($sps_account, $user_id, $po_id);
                                $ackdetail = json_decode($ack, true);
                                //echo "<pre>";
                                //print_r($ackdetail);
                                //die;
                                if (isset($ackdetail['Header']['OrderHeader']['PurchaseOrderNumber'])) {

                                    //if(isset($ackdetail['Header']['OrderHeader']['TradingPartnerId']) && $ackdetail['Header']['OrderHeader']['TradingPartnerId']==$trading_partner_id){


                                    /*$TradingPartnerId = @$ackdetail['Header']['OrderHeader']['TradingPartnerId'] ? $ackdetail['Header']['OrderHeader']['TradingPartnerId'] : $ackdetail['Header']['OrderHeader']['Vendor'];

                                            $user_integration = DB::table('platform_data_mapping as pdm')
                                            ->join("platform_objects as po",function($join){
                                            $join->on("pdm.platform_object_id","=","po.id")
                                                ->on("pdm.status","=","po.status");
                                            })->where(['pdm.mapping_type' => 'default','po.name' => 'trading_partner_id','pdm.data_map_type' => 'custom','pdm.custom_data' => $TradingPartnerId])->select(['user_integration_id'])->first();

                                            $user_integration_id = @$user_integration->user_integration_id ? $user_integration->user_integration_id : $user_integration_id;
                                            */

                                    $PurchaseOrderNumber = @$ackdetail['Header']['OrderHeader']['PurchaseOrderNumber'];
                                    $AcknowledgementType = @$ackdetail['Header']['OrderHeader']['AcknowledgementType'];

                                    $note = "";
                                    if (isset($ackdetail['Header']['Notes']['Note'])) {
                                        $note = $ackdetail['Header']['Notes']['Note'];
                                    } else {
                                        if (isset($ackdetail['Header']['Notes'])) {
                                            $arr_note = array();
                                            foreach ($ackdetail['Header']['Notes'] as $rownote) {
                                                $arr_note[] = $rownote['Note'];
                                            }
                                            $note = implode(', ', $arr_note);
                                        }
                                    }

                                    //if($PurchaseOrderNumber==$last_order_id){
                                    //    break;
                                    //}

                                    //storing entries into files
                                    $path = public_path() . '/esb_asset/spscommerce/' . $user_integration_id;
                                    if (!file_exists($path)) {
                                        mkdir($path, 0777, true);
                                    }


                                    $file_name = "ACK_" . $PurchaseOrderNumber . "_" . $user_integration_id . '.txt';
                                    file_put_contents($path . '/' . $file_name, $ack);


                                    $arr_order = array();
                                    $arr_order['user_id'] = $user_id;
                                    $arr_order['platform_id'] = $this->my_platform_id;
                                    $arr_order['user_integration_id'] = $user_integration_id;
                                    $arr_order['order_type'] = "PO";
                                    $arr_order['trading_partner_id'] = $trading_partner_id;
                                    $arr_order['order_number'] = $PurchaseOrderNumber;
                                    $arr_order['order_status'] = $AcknowledgementType;
                                    $arr_order['api_order_id'] = $PurchaseOrderNumber;
                                    $arr_order['file_name'] =  $file_name;
                                    $arr_order['notes'] =  $note;


                                    $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_order_id' => $PurchaseOrderNumber], ['id', 'sync_status']);


                                    if ($order_details) {
                                        if ($order_details->sync_status == 'Ready' || $order_details->sync_status == 'Pending') {
                                            $arr_order['sync_status'] = 'Ready';
                                        }


                                        $platform_order_id = $order_details->id;
                                        DB::beginTransaction();
                                        try{
                                            $this->mobj->makeUpdate('platform_order', $arr_order, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_order_id' => $PurchaseOrderNumber]);
                                            DB::commit();
                                        } catch (\Exception $ex) {
                                            DB::rollback(); //if rollback due to error occurs in query 3 then no data will be saved in table 1 and 2...Not Mandatory
                                        }
                                    } else {

                                        //$platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                                    }


                                    //Delete SPS File After Use
                                    //$delete_response = $this->spsapi->DeleteTransactions($sps_account,$user_id,$po_id);


                                    /*}else{
                                            $return_response = $ack;
                                        }*/
                                } else {
                                    $return_response = $ack;
                                }
                            }
                        }
                    }
                }



                //}


            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsGetAllAcknowledgement-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }



    public function SpsGetAllInventory($user_id, $user_integration_id, $platform_workflow_rule_id)

    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {


            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {


                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");
                $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;



                $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                $maping_data = $this->map->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);
                if ($maping_data) {

                    $source_row_data = $destination_row_data = '';
                    if ($maping_data['source_platform_id'] == 'spscommerce') {
                        $destination_row_data = $maping_data['destination_row_data'];
                        $source_row_data = $maping_data['source_row_data'];
                    } else {
                        $destination_row_data = $maping_data['source_row_data'];
                        $source_row_data = $maping_data['destination_row_data'];
                    }
                }


                //PAGINATION TIME DATE Filter
                $result = $this->spsapi->GetAllInventory($sps_account, $user_id);
                $listinv = json_decode($result, true);
                echo "<pre>";
                print_r($listinv);
                //die;
                if (is_array($listinv)) {

                    if (count($listinv) > 0) {
                        foreach ($listinv as $row) {
                            if (isset($row['key'])) {
                                $inv_id = $row['key'];

                                $inv = $this->spsapi->GetInventoryById($sps_account, $user_id, $inv_id);
                                $invdetail = json_decode($inv, true);
                                if (isset($invdetail['Header']['HeaderReport']['DocumentId'])) {


                                    //if(isset($invdetail['Header']['HeaderReport']['TradingPartnerId']) && $invdetail['Header']['HeaderReport']['TradingPartnerId']==$trading_partner_id){


                                    /*
                                        $TradingPartnerId = @$invdetail['Header']['HeaderReport']['TradingPartnerId'] ? $invdetail['Header']['HeaderReport']['TradingPartnerId'] : $invdetail['Header']['HeaderReport']['Vendor'];

                                        $user_integration = DB::table('platform_data_mapping as pdm')
                                        ->join("platform_objects as po",function($join){
                                        $join->on("pdm.platform_object_id","=","po.id")
                                            ->on("pdm.status","=","po.status");
                                        })->where(['pdm.mapping_type' => 'default','po.name' => 'trading_partner_id','pdm.data_map_type' => 'custom','pdm.custom_data' => $TradingPartnerId])->select(['user_integration_id'])->first();

                                        $user_integration_id = @$user_integration->user_integration_id ? $user_integration->user_integration_id : $user_integration_id;
                                        */

                                    $DocumentId = @$invdetail['Header']['HeaderReport']['DocumentId'];


                                    //storing entries into files
                                    $path = public_path() . '/esb_asset/spscommerce/' . $user_integration_id;
                                    if (!file_exists($path)) {
                                        mkdir($path, 0777, true);
                                    }


                                    $file_name = "INVENTORY_" . $DocumentId . "_" . $user_integration_id . '.txt';
                                    file_put_contents($path . '/' . $file_name, $inv);

                                    $list_line = array();
                                    if(isset($invdetail['Structure']['LineItem'])){

                                        if (isset($invdetail['Structure']['LineItem']['InventoryLine'])) {
                                            $list_line[] = $invdetail['Structure']['LineItem'];
                                        } else {
                                            $list_line = $invdetail['Structure']['LineItem'];
                                        }


                                        // SPS Commerce Does not have any unique id so we made $api_product_id as unique id
                                        foreach ($list_line as $lineitem) {

                                            $api_product_id = '';
                                            if ($source_row_data == 'sku') {
                                                $api_product_id = @$lineitem['InventoryLine']['BuyerPartNumber'];
                                            } else if ($source_row_data == 'ean') {
                                                $api_product_id = @$lineitem['InventoryLine']['EAN'];
                                            } else if ($source_row_data == 'gtin') {
                                                $api_product_id = @$lineitem['InventoryLine']['GTIN'];
                                            } else if ($source_row_data == 'upc') {
                                                $api_product_id = @$lineitem['InventoryLine']['UPCCaseCode'] ? @$lineitem['InventoryLine']['UPCCaseCode'] : @$lineitem['InventoryLine']['ConsumerPackageCode'];
                                            } else if ($source_row_data == 'mpn') {
                                                $api_product_id = @$lineitem['InventoryLine']['VendorPartNumber'];
                                            }

                                            $arr_product = array();
                                            $arr_product['user_id'] = $user_id;
                                            $arr_product['user_integration_id'] = $user_integration_id;
                                            $arr_product['platform_id'] = $this->my_platform_id;
                                            $arr_product['api_product_id'] = $api_product_id;
                                            $arr_product['sku'] = @$lineitem['InventoryLine']['BuyerPartNumber'];
                                            $arr_product['ean'] = @$lineitem['InventoryLine']['EAN'];
                                            $arr_product['gtin'] = @$lineitem['InventoryLine']['GTIN'];
                                            $arr_product['upc'] = @$lineitem['InventoryLine']['UPCCaseCode'] ? @$lineitem['InventoryLine']['UPCCaseCode'] : @$lineitem['InventoryLine']['ConsumerPackageCode'];
                                            $arr_product['mpn'] = @$lineitem['InventoryLine']['VendorPartNumber'];
                                            $arr_product['product_sync_status'] = 'Pending';
                                            $arr_product['inventory_sync_status'] = 'Ready';


                                            $products = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'api_product_id' => $api_product_id], ['id']);
                                            if ($products) {
                                                $platform_product_id = @$products->id;
                                                $this->mobj->makeUpdate('platform_product', $arr_product, ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'api_product_id' => $api_product_id]);
                                            } else {
                                                $platform_product_id =  $this->mobj->makeInsertGetId('platform_product', $arr_product);
                                            }


                                            $arr_product_inventory = array();
                                            $arr_product_inventory['user_id'] = $user_id;
                                            $arr_product_inventory['user_integration_id'] = $user_integration_id;
                                            $arr_product_inventory['platform_id'] = $this->my_platform_id;
                                            $arr_product_inventory['platform_product_id'] = $platform_product_id;
                                            $arr_product_inventory['api_product_id'] = $api_product_id;
                                            $arr_product_inventory['quantity'] = @$lineitem['QuantitiesSchedulesLocations']['TotalQty'] ? @$lineitem['QuantitiesSchedulesLocations']['TotalQty'] : 0;
                                            $arr_product_inventory['sync_status'] = 'Ready';


                                            $ct_inventory = $this->mobj->getCountsByConditions('platform_product_inventory', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'api_product_id' => $api_product_id, 'platform_product_id' => $platform_product_id]);
                                            if ($ct_inventory > 0) {
                                                $this->mobj->makeUpdate('platform_product_inventory', $arr_product_inventory, ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'api_product_id' => $api_product_id, 'platform_product_id' => $platform_product_id]);
                                            } else {
                                                $this->mobj->makeInsertGetId('platform_product_inventory', $arr_product_inventory);
                                            }
                                        }


                                        //Delete SPS File After Use
                                        //$delete_response = $this->spsapi->DeleteTransactions($sps_account,$user_id,$inv_id);


                                        /*}else{
                                            $return_response = $inv;
                                        }*/

                                    }

                                } else {
                                    $return_response = $inv;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsGetAllInventory-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }


    public function GetCustomFieldValues($user_id, $user_integration_id, $source_platform_id, $sync_object_id, $custom_field_name = null, $record_id = null)
    {


        $custom_field_value = DB::table('platform_custom_field_values as pcfv')
            ->join("platform_fields as pf", function ($join) {
                $join->on("pcfv.user_integration_id", "=", "pf.user_integration_id")
                    ->on("pcfv.platform_id", "=", "pf.platform_id")
                    ->on("pcfv.status", "=", "pf.status")
                    ->on("pcfv.platform_field_id", "=", "pf.id");
            })->where(['pcfv.platform_id' => $source_platform_id, 'pcfv.user_integration_id' => $user_integration_id, 'pf.platform_object_id' => $sync_object_id, 'pf.field_type' => 'custom', 'pf.name' => $custom_field_name, 'pcfv.record_id' => $record_id])->select(['field_value'])->first();

        $field_value = @$custom_field_value->field_value ? $custom_field_value->field_value : '';
        return $field_value;
    }




    public function SpsGetAllInvoices($user_id, $user_integration_id, $platform_workflow_rule_id)

    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {



            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {


                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");
                $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;


                //$CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,$platform_workflow_rule_id,"trading_partner_id", ['custom_data'], "default");

                //if($CustomDataTradingPartner){
                //$trading_partner_id = $CustomDataTradingPartner->custom_data;


                //PAGINATION TIME DATE Filter
                $result = $this->spsapi->GetAllInvoices($sps_account, $user_id);
                $listinv = json_decode($result, true);
                //echo "<pre>";
                //print_r($listinv);
                //die;
                //$orderdetail = DB::table('platform_order')->select('api_order_id')->where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(api_created_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                //$last_order_id = @$orderdetail->api_order_id;

                if (is_array($listinv)) {

                    if (count($listinv) > 0) {
                        foreach ($listinv as $row) {

                            if (isset($row['key'])) {

                                $po_id = $row['key'];

                                $inv = $this->spsapi->GetInvoiceById($sps_account, $user_id, $po_id);
                                $invdetail = json_decode($inv, true);

                                if (isset($invdetail['Header']['InvoiceHeader']['PurchaseOrderNumber'])) {

                                    //if(isset($invdetail['Header']['InvoiceHeader']['TradingPartnerId']) && $invdetail['Header']['InvoiceHeader']['TradingPartnerId']==$trading_partner_id){

                                    /*
                                            $TradingPartnerId = @$invdetail['Header']['InvoiceHeader']['TradingPartnerId'] ? $invdetail['Header']['InvoiceHeader']['TradingPartnerId'] : $invdetail['Header']['InvoiceHeader']['Vendor'];

                                            $user_integration = DB::table('platform_data_mapping as pdm')
                                            ->join("platform_objects as po",function($join){
                                            $join->on("pdm.platform_object_id","=","po.id")
                                                ->on("pdm.status","=","po.status");
                                            })->where(['pdm.mapping_type' => 'default','po.name' => 'trading_partner_id','pdm.data_map_type' => 'custom','pdm.custom_data' => $TradingPartnerId])->select(['user_integration_id'])->first();

                                            $user_integration_id = @$user_integration->user_integration_id ? $user_integration->user_integration_id : $user_integration_id;
                                            */

                                    $PurchaseOrderNumber = @$invdetail['Header']['InvoiceHeader']['PurchaseOrderNumber'];
                                    $InvoiceNumber = @$invdetail['Header']['InvoiceHeader']['InvoiceNumber'];
                                    $InvoiceDate = @$invdetail['Header']['InvoiceHeader']['InvoiceDate'];
                                    $Vendor = @$invdetail['Header']['InvoiceHeader']['Vendor'];
                                    $ExchangeRate = @$invdetail['Header']['InvoiceHeader']['ExchangeRate'] ? $invdetail['Header']['InvoiceHeader']['ExchangeRate'] : 0;
                                    $TotalAmount = @$invdetail['Summary']['TotalAmount'] ? $invdetail['Summary']['TotalAmount'] : 0;
                                    $TotalNetSalesAmount = @$invdetail['Summary']['TotalNetSalesAmount'] ? $invdetail['Summary']['TotalNetSalesAmount'] : 0;
                                    $NominalCode = '';
                                    $TaxAmount = 0;
                                    $TaxCode = '-';



                                    $note = "";
                                    if (isset($invdetail['Header']['Notes']['Note'])) {
                                        $note = $invdetail['Header']['Notes']['Note'];
                                    } else if (isset($invdetail['Header']['Notes'])) {
                                        $arr_note = array();

                                        foreach ($invdetail['Header']['Notes'] as $rownote) {
                                            $arr_note[] = $rownote['Note'];
                                        }
                                        $note = implode(', ', $arr_note);
                                    }

                                    //if($PurchaseOrderNumber==$last_order_id){
                                    //    break;
                                    //}

                                    //storing entries into files
                                    $path = public_path() . '/esb_asset/spscommerce/' . $user_integration_id;
                                    if (!file_exists($path)) {
                                        mkdir($path, 0777, true);
                                    }


                                    $file_name = "INVOICE_" . $InvoiceNumber . "_" . $user_integration_id . '.txt';
                                    file_put_contents($path . '/' . $file_name, $inv);




                                    //Add Order Invoice For Log
                                    $result_order =  $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'api_order_id' => $PurchaseOrderNumber], ['id', 'currency']);
                                    $platform_order_id = $currency = '';

                                    if ($result_order) {

                                        $platform_order_id = $result_order->id;
                                        $currency = $result_order->currency;



                                        $arr_invoice = array();
                                        $arr_invoice['user_id'] = $user_id;
                                        $arr_invoice['platform_id'] = $this->my_platform_id;
                                        $arr_invoice['user_integration_id'] = $user_integration_id;
                                        $arr_invoice['platform_order_id'] = $platform_order_id;
                                        $arr_invoice['trading_partner_id'] = $trading_partner_id;
                                        $arr_invoice['order_doc_number'] = $PurchaseOrderNumber;
                                        $arr_invoice['invoice_date'] = $InvoiceDate;
                                        $arr_invoice['invoice_code'] = $NominalCode;
                                        $arr_invoice['customer_name'] = $Vendor;
                                        $arr_invoice['ref_number'] = $InvoiceNumber;
                                        $arr_invoice['message'] = null;
                                        $arr_invoice['api_tax_code'] = $TaxCode;
                                        $arr_invoice['currency'] = $currency;
                                        $arr_invoice['exchange_rate'] = $ExchangeRate;
                                        $arr_invoice['net_total'] = $TotalNetSalesAmount;
                                        $arr_invoice['total_tax'] = $TaxAmount;
                                        $arr_invoice['total_amt'] = $TotalAmount;
                                        $arr_invoice['api_created_at'] = date('Y-m-d H:i:s');
                                        $arr_invoice['api_updated_at'] = date('Y-m-d H:i:s');
                                        $arr_invoice['sync_status'] = 'Ready';



                                        $ct_inv = $this->mobj->getCountsByConditions('platform_invoice', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id]);

                                        if ($ct_inv > 0) {
                                            //$this->mobj->makeUpdate('platform_invoice', $arr_invoice, ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id]);

                                        } else {
                                            $this->mobj->makeInsertGetId('platform_invoice', $arr_invoice);
                                            $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Ready'], ['id' => $platform_order_id]);
                                        }
                                    }


                                    //Delete SPS File After Use
                                    //$delete_response = $this->spsapi->DeleteTransactions($sps_account,$user_id,$po_id);

                                    /*}else{
                                            $return_response = $inv;
                                        }*/
                                } else {
                                    $return_response = $inv;
                                }
                            }
                        }
                    }
                }


                //}


            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsGetAllInvoices-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }


    public function SpsGetAllShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $destination_platform)
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {



            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {

                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");
                $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;

                //$CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id,$platform_workflow_rule_id,"trading_partner_id", ['custom_data'], "default");

                //if($CustomDataTradingPartner){
                //$trading_partner_id = $CustomDataTradingPartner->custom_data;


                $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                $maping_data = $this->map->getMappedField($user_integration_id, null, $product_identity_obj_id);
                if ($maping_data) {

                    $source_row_data = $destination_row_data = '';
                    if ($maping_data['source_platform_id'] == 'spscommerce') {
                        $destination_row_data = $maping_data['destination_row_data'];
                        $source_row_data = $maping_data['source_row_data'];
                    } else {
                        $destination_row_data = $maping_data['source_row_data'];
                        $source_row_data = $maping_data['destination_row_data'];
                    }
                }

                $destination_platform_id = $this->helper->getPlatformIdByName($destination_platform);

                //PAGINATION TIME DATE Filter
                $result = $this->spsapi->GetAllShipments($sps_account, $user_id);
                $listship = json_decode($result, true);

                //$orderdetail = DB::table('platform_order')->select('api_order_id')->where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(api_created_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                //$last_order_id = @$orderdetail->api_order_id;
                if (is_array($listship)) {

                    if (count($listship) > 0) {
                        foreach ($listship as $row) {

                            if (isset($row['key'])) {

                                $po_id = $row['key'];

                                $ship = $this->spsapi->GetShipmentById($sps_account, $user_id, $po_id);
                                $shipdetail = json_decode($ship, true);
                                //echo "<br><br>".$po_id."<br><br>";
                                if (isset($shipdetail['Header']['ShipmentHeader']['ShipmentIdentification'])) {



                                    $ShipmentIdentification = @$shipdetail['Header']['ShipmentHeader']['ShipmentIdentification'];
                                    $ShipDate = @$shipdetail['Header']['ShipmentHeader']['ShipDate'];
                                    $ShipNoticeDate = @$shipdetail['Header']['ShipmentHeader']['ShipNoticeDate'];
                                    $ShipNoticeTime = @$shipdetail['Header']['ShipmentHeader']['ShipNoticeTime'];

                                    $BillOfLadingNumber = @$shipdetail['Header']['ShipmentHeader']['BillOfLadingNumber'];
                                    $CarrierProNumber = @$shipdetail['Header']['ShipmentHeader']['CarrierProNumber'];
                                    $CurrentScheduledDeliveryDate = @$shipdetail['Header']['ShipmentHeader']['CurrentScheduledDeliveryDate'];
                                    $Tracking_Number = @$BillOfLadingNumber ? $BillOfLadingNumber : $CarrierProNumber;

                                    $CarrierRouting = @$shipdetail['Header']['CarrierInformation'][0]['CarrierRouting'];
                                    $CarrierAlphaCode = @$shipdetail['Header']['CarrierInformation'][0]['CarrierAlphaCode'];

                                    $AddressLocationNumber = "";
                                    if (isset($shipdetail['Header']['Address'])) {
                                        foreach ($shipdetail['Header']['Address'] as $rowaddress) {
                                            if ($rowaddress['AddressTypeCode'] == 'ST') {
                                                $AddressLocationNumber = @$rowaddress['AddressLocationNumber'];
                                            }
                                        }
                                    }

                                    //if($PurchaseOrderNumber==$last_order_id){
                                    //    break;
                                    //}

                                    //storing entries into files
                                    $path = public_path() . '/esb_asset/spscommerce/' . $user_integration_id;
                                    if (!file_exists($path)) {
                                        mkdir($path, 0777, true);
                                    }


                                    $file_name = "SHIP_" . $ShipmentIdentification . "_" . $user_integration_id . '.txt';
                                    file_put_contents($path . '/' . $file_name, $ship);


                                    if (isset($shipdetail['OrderLevel'])) {

                                        foreach ($shipdetail['OrderLevel'] as $row) {
                                            $PurchaseOrderNumber = @$row['OrderHeader']['PurchaseOrderNumber'];
                                            $Vendor = @$row['OrderHeader']['Vendor'];


                                            $result_order =  $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'api_order_id' => $PurchaseOrderNumber], ['id', 'currency', 'linked_id']);
                                            //dd($result_order);
                                            $platform_order_id = $currency = $linked_id = '';
                                            if ($result_order) {

                                                $platform_order_id = $result_order->id;

                                                $linked_id = $result_order->linked_id;

                                                $result_order_destination =  $this->mobj->getFirstResultByConditions('platform_order', ['id' => $linked_id], ['currency']);
                                                if ($result_order_destination) {
                                                    $currency = $result_order_destination->currency;
                                                }
                                            }

                                            if (isset($row['PackLevel'])) {

                                                foreach ($row['PackLevel'] as $rowpack) {
                                                    $ShippingSerialID = @$rowpack['Pack']['ShippingSerialID'];
                                                    $CarrierPackageID = @$rowpack['Pack']['CarrierPackageID'];

                                                    $ShipmentData = [];
                                                    $ShipmentData['user_id'] = $user_id;
                                                    $ShipmentData['platform_id'] = $this->my_platform_id;
                                                    $ShipmentData['user_integration_id'] = $user_integration_id;
                                                    $ShipmentData['shipment_sequence_number'] = $ShipmentIdentification;
                                                    $ShipmentData['shipment_id'] = $ShippingSerialID;
                                                    $ShipmentData['platform_order_id'] = $platform_order_id;
                                                    $ShipmentData['order_id'] = $PurchaseOrderNumber;
                                                    $ShipmentData['warehouse_id'] = $AddressLocationNumber;
                                                    $ShipmentData['shipment_status'] = '';
                                                    $ShipmentData['tracking_info'] = $Tracking_Number;
                                                    $ShipmentData['shipping_method'] = $CarrierAlphaCode;
                                                    $ShipmentData['carrier_code'] = $CarrierRouting;
                                                    $ShipmentData['tracking_url'] = '';
                                                    $ShipmentData['realease_date'] = $CurrentScheduledDeliveryDate;
                                                    $ShipmentData['created_on'] = $ShipDate;
                                                    $ShipmentData['type'] = "POShipment";


                                                    $result_shipment =  $this->mobj->getFirstResultByConditions('platform_order_shipments', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'platform_order_id' => $platform_order_id, 'shipment_id' => $ShippingSerialID, 'type' => "POShipment"], ['id']);

                                                    if ($result_shipment) {
                                                        $platform_order_shipment_id = $result_shipment->id;
                                                        $this->mobj->makeUpdate('platform_order_shipments', $ShipmentData, ['id' => $platform_order_shipment_id]);
                                                    } else {
                                                        $ShipmentData['sync_status'] = "Ready";

                                                        $platform_order_shipment_id = $this->mobj->makeInsertGetId('platform_order_shipments', $ShipmentData);

                                                        $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ready'], ['id' => $platform_order_id]);
                                                    }







                                                    if (isset($rowpack['ItemLevel'])) {

                                                        foreach ($rowpack['ItemLevel'] as $rowitem) {
                                                            $BuyerPartNumber = @$rowitem['ShipmentLine']['BuyerPartNumber'];
                                                            $VendorPartNumber = @$rowitem['ShipmentLine']['VendorPartNumber'];
                                                            $ConsumerPackageCode = @$rowitem['ShipmentLine']['ConsumerPackageCode'];
                                                            $GTIN = @$rowitem['ShipmentLine']['GTIN'];
                                                            $EAN = @$rowitem['ShipmentLine']['EAN'];
                                                            $UPCCaseCode = @$rowitem['ShipmentLine']['UPCCaseCode'];
                                                            $OrderQty = @$rowitem['ShipmentLine']['OrderQty'] ? @$rowitem['ShipmentLine']['OrderQty'] : 0;
                                                            $ShipQty = @$rowitem['ShipmentLine']['ShipQty'] ? @$rowitem['ShipmentLine']['ShipQty'] : 0;
                                                            $PurchasePrice = @$rowitem['ShipmentLine']['PurchasePrice'] ? @$rowitem['ShipmentLine']['PurchasePrice'] : 0;


                                                            $whereproduct = array();
                                                            $api_product_id = '';
                                                            if ($source_row_data == 'sku') {
                                                                $api_product_id = $BuyerPartNumber;
                                                                $whereproduct['pp.' . $destination_row_data] = $BuyerPartNumber;
                                                            } else if ($source_row_data == 'ean') {
                                                                $api_product_id = $EAN;
                                                                $whereproduct['pp.' . $destination_row_data] = $EAN;
                                                            } else if ($source_row_data == 'gtin') {
                                                                $api_product_id = $GTIN;
                                                                $whereproduct['pp.' . $destination_row_data] = $GTIN;
                                                            } else if ($source_row_data == 'upc') {
                                                                $api_product_id = @$UPCCaseCode ? $UPCCaseCode : $ConsumerPackageCode;
                                                                $whereproduct['pp.' . $destination_row_data] = @$UPCCaseCode ? $UPCCaseCode : $ConsumerPackageCode;;
                                                            } else if ($source_row_data == 'mpn') {
                                                                $api_product_id = $VendorPartNumber;
                                                                $whereproduct['pp.' . $destination_row_data] = $VendorPartNumber;
                                                            }

                                                            $whereproduct['pp.user_id'] = $user_id;
                                                            $whereproduct['pp.user_integration_id'] = $user_integration_id;
                                                            $whereproduct['pp.platform_id'] = $destination_platform_id;
                                                            $whereproduct['pol.platform_order_id'] = $linked_id;

                                                            $platform_product = DB::table('platform_order_line as pol')->join('platform_product as pp', 'pol.api_product_id', '=', 'pp.api_product_id')->select('pol.api_product_id', 'pol.api_order_line_id')->where($whereproduct)->first();
                                                            $api_product_id = @$platform_product->api_product_id;
                                                            $api_order_line_id = @$platform_product->api_order_line_id;
                                                            $ProductData = [];
                                                            $ProductData['platform_order_shipment_id'] = $platform_order_shipment_id;
                                                            $ProductData['product_id'] = $api_product_id;
                                                            $ProductData['row_id'] = $api_order_line_id;
                                                            //$ProductData['sku'] = @$platform_product->sku;
                                                            $ProductData['quantity'] = $ShipQty;
                                                            //$ProductData['warehouse_id'] = '';
                                                            //$ProductData['location_id'] = '';
                                                            $ProductData['currency'] = $currency;
                                                            $ProductData['price'] = $PurchasePrice;


                                                            if ($platform_order_shipment_id != '' && $api_product_id != '' && $api_order_line_id != '') {

                                                                $result_shipment_line =  $this->mobj->getFirstResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $platform_order_shipment_id, 'product_id' => $api_product_id, 'row_id' => $api_order_line_id], ['id']);
                                                                if ($result_shipment_line) {

                                                                    $this->mobj->makeUpdate('platform_order_shipment_lines', $ProductData, ['platform_order_shipment_id' => $platform_order_shipment_id, 'product_id' => $api_product_id, 'row_id' => $api_order_line_id]);
                                                                } else {
                                                                    $this->mobj->makeInsert('platform_order_shipment_lines', $ProductData);
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }


                                    //Delete SPS File After Use
                                    if($user_id==458){
                                    $delete_response = $this->spsapi->DeleteTransactions($sps_account,$user_id,$po_id);
                                    }


                                } else {
                                    $return_response = $ship;
                                }
                            }
                        }
                    }
                }



                //}

            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsGetAllShipment-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }



    public function SpsCreateUpdateAcknowledgement($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {

            $is_order_acknowledge = 1;

            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {

                $offset = 0;
                $process_limit = 30;

                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");

                $CustomDataMappedFile = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "acknowledgement_file_mapping", ['custom_data'], "default");


                if ($CustomDataMappedFile) {

                    $trading_partner_id = @$CustomDataTradingPartner->custom_data;
                    $mapped_file = @$CustomDataMappedFile->custom_data;

                    $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                    $sync_object_id = $this->helper->getObjectId('purchase_order');



                    //do{
                    $allow_next_call = false; // This flag will help for pagination

                    $result_order = PlatformOrder::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type]) ->where(function($query) use ($record_id,$sync_status){
                        if($record_id!=''){
                            $query->where('id','=',$record_id);
                        }else{
                            $query->where('sync_status','=',$sync_status);
                        }
                   })->select(['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'platform_customer_id', 'shipping_method', 'delivery_date', 'api_order_reference', 'order_status', 'linked_id', 'total_amount'])->orderBy('id', 'asc')->skip($offset)->take($process_limit)->get();

                   //removed wherenotin because of having too much ids & lots of data query not woeking we have to think different way to handle this situation. still not tested till now
                   //->whereNotIn('id', PlatformOrderShipment::select('platform_order_id')->where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id])->get()->toArray())

                    if (count($result_order) == $process_limit) {
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset += $process_limit;
                    }

                    if ($result_order) {
                        foreach ($result_order as $roworder) {

                            $id = $roworder->id;
                            $api_order_id = $roworder->api_order_id;
                            $linked_id = $roworder->linked_id;
                            $order_number = $roworder->order_number;
                            $po_id = 'PR' . $api_order_id . '.json';

                            $result_customer = array();
                            if ($roworder->platform_customer_id != '') {
                                $result_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $roworder->platform_customer_id], ['customer_name', 'api_customer_id', 'phone', 'email', 'fax']);
                            }


                            $result_order_additional_info = $this->mobj->getFirstResultByConditions('platform_order_additional_information', ['platform_order_id' => $id], ['is_drop_ship', 'parent_order_id']);

                            //$result_order_line = $this->mobj->getResultByConditions('platform_order_line',['platform_order_id'=>$id],['product_name','ean','sku','gtin','upc','qty','unit_price','description','price','mpn','total']);


                            $result_order_line = DB::table('platform_order_line as pol')->join('platform_product as pp', 'pol.api_product_id', '=', 'pp.api_product_id')->select('pol.product_name', 'pol.sku', 'pol.qty', 'pol.unit_price', 'pol.price', 'pol.total', 'pp.description', 'pp.mpn', 'pp.ean', 'pp.gtin', 'pp.upc')->where(['pol.platform_order_id' => $id, 'pp.user_id' => $user_id, 'pp.user_integration_id' => $user_integration_id, 'pp.platform_id' => $source_platform_id])->get();

                            $result_order_address = $this->mobj->getResultByConditions('platform_order_address', ['platform_order_id' => $id], ['address_type', 'address_name', 'firstname', 'lastname', 'company', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'email', 'phone_number']);


                            $postdata = $this->utisps->GetStructuredPOPostData($user_id, $user_integration_id, $platform_workflow_rule_id, $source_platform_id, $trading_partner_id, $sync_object_id, $mapped_file, $result_customer, $roworder, $result_order_line, $result_order_address, $result_order_additional_info, $is_order_acknowledge);


                            //echo $postdata;
                            //die;
                            $result_sps_order = $this->spsapi->CreateAcknowledgement($sps_account, $user_id, $po_id, $postdata);
                            $result = json_decode($result_sps_order, true);
                            //echo "<pre>";
                            //print_r($result);
                            //die;


                            if ($result) {

                                if (isset($result['key'])) {


                                    $arr_order = array();
                                    $arr_order['user_id'] = $user_id;
                                    $arr_order['platform_id'] = $this->my_platform_id;
                                    $arr_order['user_integration_id'] = $user_integration_id;
                                    $arr_order['trading_partner_id'] = $trading_partner_id;
                                    $arr_order['api_order_id'] = $api_order_id;
                                    $arr_order['order_number'] = $order_number;
                                    $arr_order['order_type'] = 'PO';
                                    $arr_order['linked_id'] = $id;
                                    $arr_order['api_order_reference'] = $result['key'];
                                    $arr_order['sync_status'] = 'Pending';

                                    if ($linked_id) {
                                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $linked_id]);
                                    } else {
                                        $linked_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                                    }

                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s'), 'linked_id' => $linked_id], ['id' => $id]);


                                    $sync_error = null;

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $id, $sync_error);
                                } else {

                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

                                    $sync_error = @$result['error']['errorDescription'] ? $result['error']['errorDescription'] : "Error";
                                    //error
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                                }
                            }
                        }
                    }

                    //}while($allow_next_call);

                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsCreateUpdateAcknowledgement-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }


    public function SpsCreateUpdateInvoice($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '')
    {

        $this->mobj->AddMemory();
        $return_response = true;
        try {



            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {

                $offset = 0;
                $process_limit = 30;

                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");


                $CustomDataMappedFile = $this->map->getMappedDataByName($user_integration_id, null, "invoice_file_mapping", ['custom_data'], "default");


                if ($CustomDataMappedFile) {

                    $trading_partner_id = @$CustomDataTradingPartner->custom_data;
                    $mapped_file = @$CustomDataMappedFile->custom_data;

                    $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                    $sync_object_id = $this->helper->getObjectId('invoice');
                    $order_state = "Converted";


                    //do{
                    $allow_next_call = false; // This flag will help for pagination

                    if ($record_id != '') {
                        $result_invoice = $this->mobj->getResultByConditions('platform_invoice', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $record_id], ['id', 'platform_order_id', 'order_doc_number', 'invoice_code', 'ref_number', 'invoice_date', 'ship_date', 'ship_via', 'tracking_number', 'total_amt', 'due_days', 'total_qty', 'linked_id', 'payment_terms'], ['id' => 'asc'], $process_limit, $offset);
                    } else {

                        $result_invoice = $this->mobj->getResultByConditions('platform_invoice', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => $sync_status], ['id', 'platform_order_id', 'order_doc_number', 'invoice_code', 'ref_number', 'invoice_date', 'ship_date', 'ship_via', 'tracking_number', 'total_amt', 'due_days', 'total_qty', 'linked_id', 'payment_terms'], ['id' => 'asc'], $process_limit, $offset);
                        //,'pi.order_state' => $order_state,'po.trading_partner_id' => $trading_partner_id
                    }

                    if (count($result_invoice) == $process_limit) {
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset += $process_limit;
                    }


                    if ($result_invoice) {

                        $ids =  $platform_order_ids = [];
                        foreach ($result_invoice as $rowinvoice) {
                            $ids[] = $rowinvoice->id;
                            $platform_order_ids[] = $rowinvoice->platform_order_id;
                        }

                        if(count($ids) > 0){
                            PlatformInvoice::whereIn('id', $ids)->update(['sync_status'=>'Processing']);
                        }
                        if(count($platform_order_ids) > 0){
                            PlatformOrder::whereIn('id', $platform_order_ids)->update(['invoice_sync_status'=>'Processing']);
                        }

                        foreach ($result_invoice as $rowinvoice) {

                            $id = $rowinvoice->id;
                            $platform_order_id = $rowinvoice->platform_order_id;
                            $invoice_code = $rowinvoice->invoice_code;
                            $ref_number = $rowinvoice->ref_number;
                            $linked_id = $rowinvoice->linked_id;

                            $inv_id = 'IN' . $invoice_code . '.json';



                            $result_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $platform_order_id], ['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'platform_customer_id', 'shipping_method', 'delivery_date', 'api_order_reference', 'order_status', 'linked_id', 'vendor'], ['id' => 'asc']);

                            if ($result_order) {

                                $result_customer = array();
                                if ($result_order->platform_customer_id != '') {

                                    $result_customer = DB::table('platform_customer as pc')
                                        ->join("platform_customer_additional_information as pcai", function ($join) {
                                            $join->on("pc.id", "=", "pcai.platform_customer_id");
                                        })->where(['pc.user_id' => $user_id, 'pc.user_integration_id' => $user_integration_id, 'pc.platform_id' => $source_platform_id, 'pc.id' => $result_order->platform_customer_id])->select(['customer_name', 'api_customer_id', 'phone', 'email', 'fax', 'pcai.location_id'])->first();
                                }


                                $result_order_additional_info = $this->mobj->getFirstResultByConditions('platform_order_additional_information', ['platform_order_id' => $platform_order_id], ['is_drop_ship', 'parent_order_id']);

                                //$result_order_line = $this->mobj->getResultByConditions('platform_order_line',['platform_order_id'=>$id],['product_name','ean','sku','gtin','upc','qty','unit_price','description','price','mpn','total']);


                                if ($source_platform == 'intacct') {

                                    $result_order_line = DB::table('platform_invoice_line as pil')->join('platform_product as pp', 'pil.api_product_id', '=', 'pp.api_product_id')->select('pil.product_name', 'pil.qty', 'pil.unit_price', 'pil.price', 'pil.total', 'pil.uom', 'pil.shipped_qty', 'pil.api_code', 'pp.description', 'pp.mpn', 'pp.sku', 'pp.ean', 'pp.gtin', 'pp.upc', 'pp.custom_fields', 'pp.api_product_id')->where(['pil.platform_invoice_id' => $id, 'pp.user_id' => $user_id, 'pp.user_integration_id' => $user_integration_id, 'pp.platform_id' => $source_platform_id])->where('pil.qty', '<>', 0)->get();

                                    $result_order->total_weight = PlatformInvoiceLine::where(['platform_invoice_id' => $id])->where('qty', '<>', 0)->sum('total_weight');
                                } else {

                                    $result_order_line = DB::table('platform_order_line as pol')->join('platform_product as pp', 'pol.api_product_id', '=', 'pp.api_product_id')->select('pol.product_name', 'pol.qty', 'pol.unit_price', 'pol.price', 'pol.total', 'pol.uom', 'pol.api_code', 'pp.description', 'pp.mpn', 'pp.sku', 'pp.ean', 'pp.gtin', 'pp.upc', 'pp.custom_fields', 'pp.api_product_id')->where(['pol.platform_order_id' => $platform_order_id, 'pp.user_id' => $user_id, 'pp.user_integration_id' => $user_integration_id, 'pp.platform_id' => $source_platform_id])->get();
                                }



                                $result_order_address = $this->mobj->getResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id], ['address_type', 'address_id', 'address_name', 'firstname', 'lastname', 'company', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'email', 'phone_number']);


                                $postdata = $this->utisps->GetStructuredInvoicePostDataNew($user_id, $user_integration_id, $platform_workflow_rule_id, $source_platform_id, $trading_partner_id, $sync_object_id, $mapped_file, $result_customer, $result_order, $result_order_line, $result_order_address, $result_order_additional_info, $rowinvoice);
                                echo $postdata;
                                //die;


                                $result_sps_invoice = $this->spsapi->CreateInvoice($sps_account, $user_id, $inv_id, $postdata);
                                $result = json_decode($result_sps_invoice, true);
                                echo "<pre>";
                                print_r($result);
                                //                                    die;
                                if ($result) {

                                    if (isset($result['key'])) {


                                        $invoice_id = $result['key'];

                                        $arr_invoice = array();
                                        $arr_invoice['user_id'] = $user_id;
                                        $arr_invoice['platform_id'] = $this->my_platform_id;
                                        $arr_invoice['user_integration_id'] = $user_integration_id;
                                        $arr_invoice['api_invoice_id'] = $invoice_id;
                                        $arr_invoice['trading_partner_id'] = $trading_partner_id;
                                        $arr_invoice['platform_order_id'] = $platform_order_id;
                                        $arr_invoice['ref_number'] = $ref_number;
                                        $arr_invoice['invoice_code'] = $invoice_code;
                                        $arr_invoice['sync_status'] = 'Pending';
                                        $arr_invoice['linked_id'] = $id;


                                        if ($linked_id) {
                                            $this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Synced'], ['linked_id' => $linked_id]);
                                        } else {
                                            $linked_id = $this->mobj->makeInsertGetId('platform_invoice', $arr_invoice);
                                        }


                                        $this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Synced', 'linked_id' => $linked_id], ['id' => $id]);

                                        $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Synced'], ['id' => $platform_order_id]);


                                        $sync_error = null;

                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $id, $sync_error);
                                    } else {

                                        $this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Failed'], ['id' => $id]);

                                        $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Failed'], ['id' => $platform_order_id]);

                                        $sync_error = @$result['error']['errorDescription'] ? $result['error']['errorDescription'] : "Error";
                                        //error
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                                    }
                                }
                            } else {
                                //error for order not associate with invoice

                                $this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Failed'], ['id' => $id]);

                                $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Failed'], ['id' => $platform_order_id]);

                                $sync_error = "Invoice is not associated with any order";
                                //error
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                            }
                        }
                    }

                    //}while($allow_next_call);

                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsCreateUpdateInvoice-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }




    public function SpsCreateUpdateShipment($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '')
    {

        $this->mobj->AddMemory();
        $return_response = true;
        try {



            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {

                $offset = 0;
                $process_limit = 30;

                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");

                $CustomDataMappedFile = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "shipment_file_mapping", ['custom_data'], "default");


                if ($CustomDataMappedFile) {

                    $trading_partner_id = @$CustomDataTradingPartner->custom_data;
                    $mapped_file = @$CustomDataMappedFile->custom_data;

                    $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                    $sync_object_id = $this->helper->getObjectId('sales_order_shipment');

                    //do{
                    $allow_next_call = false; // This flag will help for pagination

                    if ($record_id != '') {

                        $result_shipment = PlatformOrderShipment::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id,'type'=>'Shipment', 'platform_order_id' => $record_id])->select(['id', 'platform_order_id', 'shipment_id', 'order_id', 'warehouse_id', 'shipping_method', 'carrier_code', 'created_on', 'tracking_info', 'realease_date', 'linked_id'])->orderBy('id', 'asc')->skip($offset)->take($process_limit)->get();
                    } else {

                        $result_shipment = PlatformOrderShipment::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => $sync_status,'type'=>'Shipment'])->select(['id', 'platform_order_id', 'shipment_id', 'order_id', 'warehouse_id', 'shipping_method', 'carrier_code', 'created_on', 'tracking_info', 'realease_date', 'linked_id'])->orderBy('id', 'asc')->skip($offset)->take($process_limit)->get();

                        //,'pi.order_state' => $order_state,'po.trading_partner_id' => $trading_partner_id
                    }

                    if (count($result_shipment) == $process_limit) {
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset += $process_limit;
                    }




                    if ($result_shipment) {
                        foreach ($result_shipment as $rowship) {

                            $id = $rowship->id;
                            $platform_order_id = $rowship->platform_order_id;
                            $shipment_id = $rowship->shipment_id;
                            $ship_id = 'SH' . $shipment_id . '.json';



                            $result_order = PlatformOrder::where(['id' => $platform_order_id])->select(['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'platform_customer_id', 'shipping_method', 'delivery_date', 'api_order_reference', 'order_status', 'shipment_status', 'linked_id', 'trading_partner_id', 'total_tax', 'total_amount'])->first();


                            if ($result_order) {



                                $result_customer = array();
                                if ($result_order->platform_customer_id != '') {
                                    $result_customer = PlatformCustomer::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $result_order->platform_customer_id])->select(['customer_name', 'api_customer_id'])->first();
                                }


                                $result_order_additional_info = PlatformOrderAdditionalInformation::where(['platform_order_id' => $platform_order_id])->select(['is_drop_ship', 'parent_order_id'])->first();

                                /*
                                $result_order_line = $this->mobj->getResultByConditions('platform_order_line',['platform_order_id'=>$platform_order_id],['product_name','ean','sku','gtin','upc','qty','unit_price','description','price','mpn','total']);
                                */

                                if (isset(\Config::get('apisettings.AllowQueryingOrderLineItemForShipmentInSpscommerce')[$source_platform])) {

                                    $result_order_line = DB::table('platform_order_shipment_lines as posl')
                                    ->join('platform_order_line as pol', function ($join) use ($platform_order_id) {

                                        $join->on('posl.row_id', '=', 'pol.api_order_line_id');
                                        $join->where('pol.platform_order_id', '=', $platform_order_id);
                                    })->join('platform_product as pp', 'posl.product_id', '=', 'pp.api_product_id')
                                    ->select('posl.quantity as qty', 'posl.product_id', 'pol.unit_price', 'pol.price', 'pol.total', 'pp.product_name', 'pp.sku', 'pp.description', 'pp.mpn', 'pp.ean', 'pp.gtin', 'pp.upc')->where(['posl.platform_order_shipment_id' => $id, 'pp.user_id' => $user_id, 'pp.user_integration_id' => $user_integration_id, 'pp.platform_id' => $source_platform_id])->get();

                                }else{

                                    $result_order_line = DB::table('platform_order_shipment_lines as posl')->join('platform_product as pp', 'posl.row_id', '=', 'pp.api_product_id')
                                    ->select('posl.quantity as qty', 'posl.product_id', 'pp.price as unit_price', 'posl.price', 'posl.price as total', 'pp.product_name', 'pp.sku', 'pp.description', 'pp.mpn', 'pp.ean', 'pp.gtin', 'pp.upc')->where(['posl.platform_order_shipment_id' => $id, 'pp.user_id' => $user_id, 'pp.user_integration_id' => $user_integration_id, 'pp.platform_id' => $source_platform_id])->get();
                                }

                                $total_ship_qty = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $id])->sum('quantity');
                                $rowship->total_ship_qty = @$total_ship_qty ? $total_ship_qty : 0;


                                $result_order_address = PlatformOrderAddress::where(['platform_order_id' => $platform_order_id])->select(['address_type', 'address_name', 'firstname', 'lastname', 'company', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'email', 'phone_number'])->get();


                                $postdata = $this->utisps->GetStructuredShipmentPostData($user_id, $user_integration_id, $platform_workflow_rule_id, $source_platform_id, $trading_partner_id, $sync_object_id, $mapped_file, $result_customer, $result_order, $result_order_line, $result_order_address, $result_order_additional_info, $rowship);
                                echo $postdata;
                                //die;

                                $result_sps_shipment = $this->spsapi->CreateShipment($sps_account, $user_id, $ship_id, $postdata);
                                $result = json_decode($result_sps_shipment, true);
                                echo "<Pre>";
                                print_r($result);
                                if ($result) {

                                    if (isset($result['key'])) {


                                        $sps_shipment_id = $result['key'];

                                        $arr_ship = array();
                                        $arr_ship['user_id'] = $user_id;
                                        $arr_ship['platform_id'] = $this->my_platform_id;
                                        $arr_ship['user_integration_id'] = $user_integration_id;
                                        $arr_ship['shipment_id'] = $sps_shipment_id;
                                        //$arr_ship['platform_order_id'] = $platform_order_id;
                                        $arr_ship['sync_status'] = 'Pending';
                                        $arr_ship['type'] = 'Shipment';
                                        $arr_ship['linked_id'] = $id;


                                        $linked_platform_shipment_id = PlatformOrderShipment::insertGetId($arr_ship);

                                        PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Synced', 'linked_id' => $linked_platform_shipment_id]);


                                        PlatformOrder::where(['id' => $platform_order_id])->update(['sync_status' => 'Synced','shipment_status' => 'Synced']);


                                        $sync_error = null;

                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $platform_order_id, $sync_error);
                                    } else {

                                        PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);

                                        PlatformOrder::where(['id' => $platform_order_id])->update(['sync_status' => 'Failed','shipment_status' => 'Failed']);

                                        $sync_error = @$result['error']['errorDescription'] ? $result['error']['errorDescription'] : "Error";
                                        //error
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $platform_order_id, $sync_error);
                                    }
                                }
                            } else {
                                 //error for order not associate with shipment

                                 PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Pending']);

                                 PlatformOrder::where(['id' => $platform_order_id])->update(['sync_status' => 'Pending','shipment_status' => 'Pending']);


                                /*
                                PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);

                                PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Failed']);

                                $sync_error = "Shipment is not associated with any order";
                                //error
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $platform_order_id, $sync_error);
                                */
                            }
                        }
                    }

                    //}while($allow_next_call);

                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SpsCreateUpdateShipment-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }



    public function SPSUpdateInventory($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {


            $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($sps_account) {

                $offset = 0;
                $process_limit = 30;

                $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");

                $CustomDataMappedFile = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_file_mapping", ['custom_data'], "default");

                if ($CustomDataMappedFile) {

                    $trading_partner_id = @$CustomDataTradingPartner->custom_data;
                    $mapped_file = @$CustomDataMappedFile->custom_data;

                    $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                    $sync_object_id = $this->helper->getObjectId('inventory');


                    /*
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                    $maping_data = $this->map->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);
                    if ($maping_data) {
                        $source_row_data = $destination_row_data = '';

                        if ($maping_data['destination_platform_id'] == 'spscommerce') {
                            $destination_row_data = $maping_data['destination_row_data'];
                            $source_row_data = $maping_data['source_row_data'];
                        } else {
                            $destination_row_data = $maping_data['source_row_data'];
                            $source_row_data = $maping_data['destination_row_data'];
                        }
                    }*/


                    //do{
                    $allow_next_call = false; // This flag will help for pagination

                    if ($record_id != '') {

                        $result_product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $record_id])->select(['id', 'api_product_id', 'api_product_code', 'product_name', 'ean', 'sku', 'gtin', 'upc', 'mpn', 'price', 'description', 'api_updated_at', 'updated_at'])->orderBy('updated_at', 'asc')->skip($offset)->take($process_limit)->get();
                    } else {

                        $result_product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'inventory_sync_status' => $sync_status])->select(['id', 'api_product_id', 'api_product_code', 'product_name', 'ean', 'sku', 'gtin', 'upc', 'mpn', 'price', 'description', 'api_updated_at', 'updated_at'])->orderBy('updated_at', 'asc')->skip($offset)->take($process_limit)->get();

                        //,'pi.order_state' => $order_state,'po.trading_partner_id' => $trading_partner_id
                    }

                    if (count($result_product) == $process_limit) {
                        $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                        $offset += $process_limit;
                    }



                    if ($result_product) {
                        foreach ($result_product as $rowproduct) {

                            $platform_product_id = $rowproduct->id;
                            $sps_product_id = 'IB' . $platform_product_id;


                            $result_inventory = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_product_id' => $platform_product_id])->select(['id', 'api_warehouse_id', 'quantity', 'location_code'])->get();


                            $product_sync_error = '';
                            foreach ($result_inventory as $rowinventory) {

                                $id = $rowinventory->id;
                                $sps_inv_id = 'IB' . $id . '.json';

                                $postdata = $this->utisps->GetStructuredInventoryPostData($user_id, $user_integration_id, $platform_workflow_rule_id, $source_platform_id, $trading_partner_id, $sync_object_id, $mapped_file, $rowproduct, $rowinventory);


                                //echo $postdata;

                                $result_sps_inventory = $this->spsapi->UpdateInventory($sps_account, $user_id, $sps_inv_id, $postdata);
                                $result = json_decode($result_sps_inventory, true);

                                //echo "<pre>";
                                // print_r($result);
                                // die;

                                if ($result) {

                                    if (isset($result['key'])) {
                                        //$sps_shipment_id = $result['key'];

                                        PlatformProductInventory::where(['id' => $id])->update(['sync_status' => 'Synced']);
                                    } else {

                                        $product_sync_error = $sync_error = @$result['error']['errorDescription'] ? $result['error']['errorDescription'] : "Error";

                                        PlatformProductInventory::where(['id' => $id])->update(['sync_status' => 'Failed']);
                                    }
                                }
                            }

                            if ($product_sync_error != '') {

                                PlatformProduct::where(['id' => $platform_product_id])->update(['inventory_sync_status' => 'Failed']);

                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, "failed", $platform_product_id, $product_sync_error);
                            } else {

                                PlatformProduct::where(['id' => $platform_product_id])->update(['inventory_sync_status' => 'Synced']);

                                $product_sync_error = null;
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $platform_product_id, $product_sync_error);
                            }
                        }
                    }

                    //}while($allow_next_call);

                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--SPSUpdateInventory-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    public function GetTestManualOrderUsingJson()

    { //$po_id -> it contain spscommerce folder like testout/PO11111
        $last_order_number = null;
        $user_id = 209;
        $user_integration_id = 510;
        $po = '{
            "Header": {
              "OrderHeader": {
                "TradingPartnerId": "Test",
                "PurchaseOrderNumber": "2102180",
                "TsetPurposeCode": "00",
                "PrimaryPOTypeCode": "SA",
                "PurchaseOrderDate": "2021-10-12",
                "Vendor": "1379"
              },
              "Dates": [
                {
                  "DateTimeQualifier": "010",
                  "Date": "2021-10-15"
                },
                {
                  "DateTimeQualifier": "038",
                  "Date": "2021-11-05"
                }
              ],
              "Address": [
                {
                  "AddressTypeCode": "BT",
                  "AddressName": "Rally House / Kansas Sampler",
                  "Address1": "9750 Quivira Road",
                  "City": "Lenexa",
                  "State": "KS",
                  "PostalCode": "66215"
                },
                {
                  "AddressTypeCode": "ST",
                  "LocationCodeQualifier": "92",
                  "AddressLocationNumber": "73",
                  "AddressName": "Store 059 - Fairview Park",
                  "Address1": "3140 Westgate",
                  "Address2": "Suite C328",
                  "City": "Fairview Park",
                  "State": "OH",
                  "PostalCode": "44126",
                  "Country": "USA"
                }
              ],
              "CarrierInformation": [
                {
                  "ServiceLevelCodes": [
                    {
                      "ServiceLevelCode": "CG"
                    }
                  ]
                }
              ]
            },
            "LineItem": [
              {
                "OrderLine": {
                  "LineSequenceNumber": "1",
                  "BuyerPartNumber": "16660152-8X32",
                  "VendorPartNumber": "44046",
                  "ConsumerPackageCode": "674088440463",
                  "OrderQty": 6,
                  "OrderQtyUOM": "EA",
                  "PurchasePrice": 15,
                  "ProductColorDescription": "BROWN"
                },
                "PriceInformation": [
                  {
                    "PriceTypeIDCode": "RTL",
                    "UnitPrice": 32.99
                  }
                ],
                "ProductOrItemDescription": [
                  {
                    "ProductCharacteristicCode": "08",
                    "ProductDescription": "Cleveland Browns 8x32 Heritage Banner"
                  }
                ]
              },
              {
                "OrderLine": {
                  "LineSequenceNumber": "2",
                  "BuyerPartNumber": "16660236-NA",
                  "VendorPartNumber": "49167",
                  "ConsumerPackageCode": "674088491670",
                  "OrderQty": 3,
                  "OrderQtyUOM": "EA",
                  "PurchasePrice": 15,
                  "ProductColorDescription": "Brown"
                },
                "PriceInformation": [
                  {
                    "PriceTypeIDCode": "RTL",
                    "UnitPrice": 36.99
                  }
                ],
                "ProductOrItemDescription": [
                  {
                    "ProductCharacteristicCode": "08",
                    "ProductDescription": "Cleveland Browns Man Cave Banner"
                  }
                ]
              },
              {
                "OrderLine": {
                  "LineSequenceNumber": "3",
                  "BuyerPartNumber": "16660237-NA",
                  "VendorPartNumber": "80510",
                  "ConsumerPackageCode": "674088805101",
                  "OrderQty": 3,
                  "OrderQtyUOM": "EA",
                  "PurchasePrice": 17,
                  "ProductColorDescription": "Brown"
                },
                "PriceInformation": [
                  {
                    "PriceTypeIDCode": "RTL",
                    "UnitPrice": 32.99
                  }
                ],
                "ProductOrItemDescription": [
                  {
                    "ProductCharacteristicCode": "08",
                    "ProductDescription": "Cleveland Municipal Stadium Browns Banner"
                  }
                ]
              }
            ],
            "Summary": {
              "TotalLineItemNumber": 3
            }
          }';


        $return_response = true;
        try {

            $product_identity_obj_id = $this->helper->getObjectId('product_identity');

            $CustomDataTradingPartner = $this->map->getMappedDataByName($user_integration_id, null, "trading_partner_id", ['custom_data'], "default");
            $trading_partner_id = @$CustomDataTradingPartner->custom_data ? $CustomDataTradingPartner->custom_data : null;


            $maping_data = $this->map->getMappedField($user_integration_id, null, $product_identity_obj_id);
            $source_row_data = $destination_row_data = '';
            if ($maping_data) {
                if ($maping_data['destination_platform_id'] == 'spscommerce') {
                    $destination_row_data = $maping_data['source_row_data'];
                    $source_row_data = $maping_data['destination_row_data'];
                } else {
                    $destination_row_data = $maping_data['destination_row_data'];
                    $source_row_data = $maping_data['source_row_data'];
                }
            }



            $podetail = json_decode($po, true);
            //echo "<pre>";
            //print_r($podetail);
            if (isset($podetail['Header']['OrderHeader']['TradingPartnerId'])) {

                $TradingPartnerId = @$podetail['Header']['OrderHeader']['TradingPartnerId'];

                if ($trading_partner_id == $TradingPartnerId) {

                    // Getting User Integration ID for trading partner because in a SPS API we get all trafing partner order data which we have to identify for which order for which integration
                    /*if($TradingPartnerId!=''){

                        $user_integration = DB::table('platform_data_mapping as pdm')
                    ->join("platform_objects as po",function($join){
                    $join->on("pdm.platform_object_id","=","po.id")
                        ->on("pdm.status","=","po.status");
                    })->where(['pdm.mapping_type' => 'default','po.name' => 'trading_partner_id','pdm.data_map_type' => 'custom','pdm.custom_data' => $TradingPartnerId])->select(['user_integration_id'])->first();

                    $user_integration_id = @$user_integration->user_integration_id ? $user_integration->user_integration_id : $user_integration_id;


                    }

                    $maping_data = $this->map->getMappedField($user_integration_id, null, $product_identity_obj_id);
                    $source_row_data = $destination_row_data = '';
                    if ($maping_data) {
                        if ($maping_data['destination_platform_id'] == 'spscommerce') {
                            $destination_row_data = $maping_data['source_row_data'];
                            $source_row_data = $maping_data['destination_row_data'];
                        } else {
                            $destination_row_data = $maping_data['destination_row_data'];
                            $source_row_data = $maping_data['source_row_data'];
                        }
                    }*/



                    $PurchaseOrderNumber = @$podetail['Header']['OrderHeader']['PurchaseOrderNumber'];

                    if ($PurchaseOrderNumber == $last_order_number) {
                        $return_response = 'break'; //break
                    } else {

                        //storing entries into files
                        $path = public_path() . '/esb_asset/spscommerce/' . $user_integration_id;
                        if (!file_exists($path)) {
                            mkdir($path, 0777, true);
                        }

                        $file_name = "PO_" . $PurchaseOrderNumber . "_" . $user_integration_id . '.txt';
                        file_put_contents($path . '/' . $file_name, $po);


                        $PrimaryEmail = @$podetail['Header']['Contacts'][0]['PrimaryEmail'];
                        $ContactName = @$podetail['Header']['Contacts'][0]['ContactName'];
                        $platform_customer_id = 0;
                        if (trim($PrimaryEmail) != '' || trim($ContactName) != '') {

                            $arr_customer = array();
                            $arr_customer['user_id'] = $user_id;
                            $arr_customer['platform_id'] = $this->my_platform_id;
                            $arr_customer['user_integration_id'] = $user_integration_id;
                            $arr_customer['customer_name'] = @$podetail['Header']['Contacts'][0]['ContactName'];
                            $arr_customer['phone'] = @$podetail['Header']['Contacts'][0]['PrimaryPhone'];
                            $arr_customer['fax'] = @$podetail['Header']['Contacts'][0]['PrimaryFax'];
                            $arr_customer['email'] = @$podetail['Header']['Contacts'][0]['PrimaryEmail'];


                            $customer_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => @$podetail['Header']['Contacts'][0]['PrimaryEmail']], ['id']);


                            if ($customer_details) {
                                $platform_customer_id = $customer_details->id;
                                $this->mobj->makeUpdate('platform_customer', $arr_customer, ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => @$podetail['Header']['Contacts'][0]['PrimaryEmail']]);
                            } else {
                                $arr_customer['sync_status'] = 'Ready';
                                $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                            }
                        }



                        $total_discount = 0;
                        if (isset($podetail['Header']['PaymentTerms'])) {
                            foreach ($podetail['Header']['PaymentTerms'] as $rowdis) {
                                $TermsDiscountAmount = @$rowdis['TermsDiscountAmount'] ? @$rowdis['TermsDiscountAmount'] : 0;
                                $total_discount += floatval($TermsDiscountAmount);
                            }
                        }

                        $delivery_date = "";
                        if (isset($podetail['Header']['Dates'])) {
                            foreach ($podetail['Header']['Dates'] as $rowdate) {
                                if (isset($rowdate['DateTimeQualifier']) && ($rowdate['DateTimeQualifier'] == '10' || $rowdate['DateTimeQualifier'] == '010' || $rowdate['DateTimeQualifier'] == '002' || $rowdate['DateTimeQualifier'] == '02')) {
                                    $delivery_date = $rowdate['Date'];
                                }
                            }
                        }


                        $notes = array();
                        if (isset($podetail['Header']['Notes'])) {
                            foreach ($podetail['Header']['Notes'] as $rownote) {
                                $notes[] = $rownote['Note'];
                            }
                        }


                        $arr_order = array();
                        $arr_order['user_id'] = $user_id;
                        $arr_order['platform_id'] = $this->my_platform_id;
                        $arr_order['platform_customer_id'] = $platform_customer_id;
                        $arr_order['user_integration_id'] = $user_integration_id;
                        $arr_order['order_type'] = "PO";
                        $arr_order['customer_email'] = @$podetail['Header']['Contacts'][0]['PrimaryEmail'];
                        $arr_order['trading_partner_id'] = @$podetail['Header']['OrderHeader']['TradingPartnerId'];
                        $arr_order['api_order_id'] = $PurchaseOrderNumber;
                        $arr_order['api_order_reference'] = $PurchaseOrderNumber;
                        $arr_order['order_number'] = $PurchaseOrderNumber;
                        $arr_order['order_date'] = @$podetail['Header']['OrderHeader']['PurchaseOrderDate'];
                        $arr_order['department'] = @$podetail['Header']['OrderHeader']['Department'];
                        $arr_order['vendor'] = @$podetail['Header']['OrderHeader']['Vendor'];
                        $arr_order['total_discount'] = $total_discount;
                        $arr_order['total_tax'] = 0;
                        $arr_order['total_amount'] = @$podetail['Summary']['TotalAmount'] ? @$podetail['Summary']['TotalAmount'] : 0;

                        $arr_order['delivery_date'] = @$delivery_date;
                        $arr_order['notes'] = implode(' | ', $notes);
                        $arr_order['due_days'] = @$podetail['Header']['PaymentTerms'][0]['TermsDueDay'] ? @$podetail['Header']['PaymentTerms'][0]['TermsDueDay'] : 0;
                        $arr_order['file_name'] =  $file_name;



                        $whereorder = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->OrderAdditinalWhereConditions(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_order_id' => $PurchaseOrderNumber, 'order_date' => @$podetail['Header']['OrderHeader']['PurchaseOrderDate']]);


                        $order_details = $this->mobj->getFirstResultByConditions('platform_order', $whereorder, ['id']);

                        if ($order_details) {
                            $platform_order_id = $order_details->id;
                            $this->mobj->makeUpdate('platform_order', $arr_order, $whereorder);
                        } else {
                            $arr_order['sync_status'] = 'Ready';
                            $arr_order['order_updated_at'] = date('Y-m-d H:i:s');
                            $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                        }




                        $arr_order_address_billing = array();
                        $arr_order_address_shipping = array();
                        foreach (@$podetail['Header']['Address'] as $address) {

                            if ($address['AddressTypeCode'] == 'BT') {
                                $arr_order_address_billing['address_type'] = "billing";
                                $arr_order_address_billing['platform_order_id'] = $platform_order_id;
                                $arr_order_address_billing['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address_billing['address_name'] = @$address['AddressName'];
                                $arr_order_address_billing['address1'] = @$address['Address1'];
                                $arr_order_address_billing['address2'] = @$address['Address2'];
                                $arr_order_address_billing['address3'] = @$address['Address3'];
                                $arr_order_address_billing['address4'] = @$address['Address4'];
                                $arr_order_address_billing['city'] = @$address['City'];
                                $arr_order_address_billing['state'] = @$address['State'];
                                $arr_order_address_billing['postal_code'] = @$address['PostalCode'];
                                $arr_order_address_billing['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address_billing, ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address_billing);
                                }
                            } else if ($address['AddressTypeCode'] == 'ST' || $address['AddressTypeCode'] == 'BY') {
                                $arr_order_address_shipping['address_type'] = "shipping";
                                $arr_order_address_shipping['platform_order_id'] = $platform_order_id;
                                $arr_order_address_shipping['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address_shipping['address_name'] = @$address['AddressName'];
                                $arr_order_address_shipping['address1'] = @$address['Address1'];
                                $arr_order_address_shipping['address2'] = @$address['Address2'];
                                $arr_order_address_shipping['address3'] = @$address['Address3'];
                                $arr_order_address_shipping['address4'] = @$address['Address4'];
                                $arr_order_address_shipping['city'] = @$address['City'];
                                $arr_order_address_shipping['state'] = @$address['State'];
                                $arr_order_address_shipping['postal_code'] = @$address['PostalCode'];
                                $arr_order_address_shipping['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address_shipping, ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address_shipping);
                                }
                            } else if ($address['AddressTypeCode'] == 'VN') {
                                $arr_order_address_vendor = array();
                                $arr_order_address_vendor['address_type'] = "vendor";
                                $arr_order_address_vendor['platform_order_id'] = $platform_order_id;
                                $arr_order_address_vendor['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address_vendor['address_name'] = @$address['AddressName'];
                                $arr_order_address_vendor['address1'] = @$address['Address1'];
                                $arr_order_address_vendor['address2'] = @$address['Address2'];
                                $arr_order_address_vendor['address3'] = @$address['Address3'];
                                $arr_order_address_vendor['address4'] = @$address['Address4'];
                                $arr_order_address_vendor['city'] = @$address['City'];
                                $arr_order_address_vendor['state'] = @$address['State'];
                                $arr_order_address_vendor['postal_code'] = @$address['PostalCode'];
                                $arr_order_address_vendor['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "vendor"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address_vendor, ['platform_order_id' => $platform_order_id, 'address_type' => "vendor"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address_vendor);
                                }
                            } else {

                                $arr_order_address = array();
                                $arr_order_address['address_type'] = "other";
                                $arr_order_address['platform_order_id'] = $platform_order_id;
                                $arr_order_address['address_id'] = @$address['AddressLocationNumber'];
                                $arr_order_address['address_name'] = @$address['AddressName'];
                                $arr_order_address['address1'] = @$address['Address1'];
                                $arr_order_address['address2'] = @$address['Address2'];
                                $arr_order_address['address3'] = @$address['Address3'];
                                $arr_order_address['address4'] = @$address['Address4'];
                                $arr_order_address['city'] = @$address['City'];
                                $arr_order_address['state'] = @$address['State'];
                                $arr_order_address['postal_code'] = @$address['PostalCode'];
                                $arr_order_address['country'] = @$address['Country'];

                                $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "other"]);

                                if ($ct_address > 0) {
                                    $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => "other"]);
                                } else {
                                    $this->mobj->makeInsert('platform_order_address', $arr_order_address);
                                }
                            }
                        }

                        if (count($arr_order_address_billing) > 0 && count($arr_order_address_shipping) == 0) {
                            $arr_order_address_billing['address_type'] = "shipping";

                            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);

                            if ($ct_address > 0) {
                                $this->mobj->makeUpdate('platform_order_address', $arr_order_address_billing, ['platform_order_id' => $platform_order_id, 'address_type' => "shipping"]);
                            } else {
                                $this->mobj->makeInsert('platform_order_address', $arr_order_address_billing);
                            }
                        } else if (count($arr_order_address_billing) == 0 && count($arr_order_address_shipping) > 0) {
                            $arr_order_address_shipping['address_type'] = "billing";

                            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);

                            if ($ct_address > 0) {
                                $this->mobj->makeUpdate('platform_order_address', $arr_order_address_shipping, ['platform_order_id' => $platform_order_id, 'address_type' => "billing"]);
                            } else {
                                $this->mobj->makeInsert('platform_order_address', $arr_order_address_shipping);
                            }
                        }

                        foreach (@$podetail['LineItem'] as $lineitem) {

                            $api_product_id = '';
                            if ($source_row_data == 'sku') {
                                $api_product_id = @$lineitem['OrderLine']['BuyerPartNumber'];
                            } else if ($source_row_data == 'ean') {
                                $api_product_id = @$lineitem['OrderLine']['EAN'];
                            } else if ($source_row_data == 'gtin') {
                                $api_product_id = @$lineitem['OrderLine']['GTIN'];
                            } else if ($source_row_data == 'upc') {
                                $api_product_id = @$lineitem['OrderLine']['UPCCaseCode'] ? @$lineitem['OrderLine']['UPCCaseCode'] : @$lineitem['OrderLine']['ConsumerPackageCode'];
                            } else if ($source_row_data == 'mpn') {
                                $api_product_id = @$lineitem['OrderLine']['VendorPartNumber'];
                            }

                            //echo "source_row_data-->".$source_row_data."-->destination_row_data-->".$destination_row_data."<br/>";
                            //echo "api_product_id-->".$api_product_id."-->platform_order_id-->".$platform_order_id."<br/>";


                            $arr_order_line = array();
                            $arr_order_line['platform_order_id'] = $platform_order_id;
                            $arr_order_line['item_row_sequence'] = @$lineitem['OrderLine']['LineSequenceNumber'] ? $lineitem['OrderLine']['LineSequenceNumber'] : 0;
                            $arr_order_line['api_product_id'] = $api_product_id; //@$lineitem['OrderLine']['ProductID'][0]['PartNumber'];
                            $arr_order_line['sku'] = @$lineitem['OrderLine']['BuyerPartNumber']; //@$lineitem['OrderLine']['ProductID'][0]['PartNumber'];
                            $arr_order_line['ean'] = @$lineitem['OrderLine']['EAN'];
                            $arr_order_line['gtin'] = @$lineitem['OrderLine']['GTIN'];
                            $arr_order_line['upc'] = @$lineitem['OrderLine']['UPCCaseCode'] ? @$lineitem['OrderLine']['UPCCaseCode'] : @$lineitem['OrderLine']['ConsumerPackageCode'];
                            $arr_order_line['mpn'] = @$lineitem['OrderLine']['VendorPartNumber'];
                            $arr_order_line['qty'] = @$lineitem['OrderLine']['OrderQty'] ? @$lineitem['OrderLine']['OrderQty'] : 0;
                            $arr_order_line['uom'] = @$lineitem['OrderLine']['OrderQtyUOM'] ? @$lineitem['OrderLine']['OrderQtyUOM'] : null;
                            $arr_order_line['price'] = @$lineitem['OrderLine']['PurchasePrice'] ? @$lineitem['OrderLine']['PurchasePrice'] : 0;
                            $arr_order_line['unit_price'] = @$lineitem['PriceInformation'][0]['UnitPrice'] ? @$lineitem['PriceInformation'][0]['UnitPrice'] : 0;
                            $arr_order_line['description'] = @$lineitem['ProductOrItemDescription'][0]['ProductDescription'];
                            $arr_order_line['notes'] = @$lineitem['Notes'][0]['Note'];
                            $arr_order_line['subtotal'] = (isset($lineitem['OrderLine']['PurchasePrice']) && isset($lineitem['OrderLine']['OrderQty'])) ? round(floatval($lineitem['OrderLine']['PurchasePrice']) * floatval($lineitem['OrderLine']['OrderQty']), 2) : 0;
							$arr_order_line['taxes'] = @json_encode($lineitem['ChargesAllowances']);



                            $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'api_product_id' => $api_product_id]);

                            if ($ct_order_line > 0) {
                                $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'api_product_id' => $api_product_id]);
                            } else {
                                $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                            }
                        }

                        //Delete SPS File After Use
                        //if ($user_integration_id == 163 || $user_integration_id == 174 || $user_integration_id == 175 || $user_integration_id == 176 || $user_integration_id == 177 || $user_integration_id == 178) {
                        //     $delete_response = $this->spsapi->DeleteTransactions($sps_account, $user_id, $po_id);
                        //}

                        $return_response = true;
                    }
                }
            } else {

                $return_response = 'Trading Partner ID Not Found';
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--GetTestManualOrderUsingJson-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }


        return $return_response;
    }

    public function test_sps()
    {


        die;
        $user_id = 187;
        $user_integration_id = 174;
        $platform_workflow_rule_id = 84;

        $source_platform_id = 'intacct';
        $user_workflow_rule_id = 370;
        $this->SpsCreateUpdateInvoice($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', '383711');




        die;

        $user_id = 187;
        $user_integration_id = 163;
        $platform_workflow_rule_id = 84;

        $source_platform_id = 'intacct';
        $user_workflow_rule_id = 358;
        $this->SpsCreateUpdateInvoice($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', '363308');


        die;



        $user_id = 209;
        $user_integration_id = 510;
        $source_platform = 'skuvault';
        $user_workflow_rule_id = 995;
        $platform_workflow_rule_id = 152;
        $sync_status = 'Ready';
        $order_type = 'SO';

        $this->SpsCreateUpdateShipment($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status,550607);


        die;

        die;



        $user_id = 168;
        $user_integration_id = 107;
        $platform_workflow_rule_id = 53;

        //$response = $this->SpsGetAllAcknowledgement($user_id,$user_integration_id,$platform_workflow_rule_id);
        //$response = $this->SpsGetAllInvoices($user_id,$user_integration_id,$platform_workflow_rule_id);
        $response = $this->SpsGetAllShipment($user_id, $user_integration_id, $platform_workflow_rule_id, 'brightpearl');
        //$response = $this->SpsGetAllInventory($user_id,$user_integration_id,$platform_workflow_rule_id);

        die;


        $user_id = 150;
        $user_integration_id = 398;
        app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->GetProducts($user_id, $user_integration_id, 2, 1);

        die;





        // \Storage::disk('local')->append('Bhoopendra_Kefron.txt', 'Contenthghgghghs');
        die;

        $user_id = 185;
        $user_integration_id = 187;
        $platform_workflow_rule_id = 72;


        $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

        if ($sps_account) {


            //PAGINATION TIME DATE Filter
            $result = $this->spsapi->GetAllPO($sps_account, $user_id, $user_integration_id);
            $listpo = json_decode($result, true);
            echo "<pre>";
            print_r($listpo);

            //$orderdetail = DB::table('platform_order')->select('order_number')->where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])->orderByRaw("DATE_FORMAT(updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
            //$last_order_number = @$orderdetail->order_number;
            $last_order_number = null;

            if (is_array($listpo)) {

                if (count($listpo) > 0) {
                    $ct = 0;
                    foreach ($listpo as $row) {
                        if (isset($row['key'])) {

                            $po = $this->spsapi->GetPOById($sps_account, $user_id, $row['key']);

                            $podetail = json_decode($po, true);
                            echo "<pre>";
                            print_r($podetail);

                            /*$delete_response = $this->spsapi->DeleteTransactions($sps_account,$user_id,$row['key']);
                                echo "<br/>****** Delete ***********<br/>";
                                echo "<pre>";
                                print_r($delete_response);
                                if($ct==5){
                                    break;
                                }
                                $ct++;
                                die;*/
                        }
                    }
                }
            }
        }

        die;











        $user_id = 185;
        $user_integration_id = 186;
        $platform_workflow_rule_id = 76;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 463;
        $this->SPSUpdateInventory($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'Ready', $record_id = '');
        die;


        $user_id = 185;
        $user_integration_id = 186;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 462;
        $platform_workflow_rule_id = 75;
        $sync_status = 'Ready';
        $order_type = 'SO';

        $this->SpsCreateUpdateShipment($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, 512082);


        $user_id = 168;
        $user_integration_id = 107;
        $platform_workflow_rule_id = 39;
        $this->SpsGetAllPO($user_id, $user_integration_id, $platform_workflow_rule_id);

        die;

        $user_id = 168;
        $user_integration_id = 107;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 341;
        $platform_workflow_rule_id = 39;
        $sync_status = 'Ready';
        $order_type = 'PO';

        $this->SpsCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, 209350);
        die;










        echo "<br/>Token->" . $this->mobj->encrypt_decrypt('Tzc4b056T3pXb2dSYTdIYmg2TEk3Z0xqVk1VSVgwRjVhWlpWeVFzcjdXektIMVNPOTBXMHNpUVpibzA3VndlODBUVUhLSVYyTzRDRXlCdForZFFOdlR2VWpncnZidjNVeWNmUWxhNWNzOXlROFh1SGVuUi96SWFwc014NnFpN1FMQmJaYVBwTmRLMGdYQytHam8wWWhsWC9IS3ozS2VvbzVFYkRRUjJ1NVROZENTRDJrY0xWNDd2UGRid2hQZkhYN0xVWUhmdnhwWnNrNldwanNGb2gwS1ZxVW1YUzZCNEVad1NJcmZ3QUFISVRPYkdxMlA3RmU2cElpbXlZaFA3NjhkZzdTQ3ljQ3E0eXlsTThJUFdXczJJWDU4R3N2MXc3V3llbUFrLzhxaWpmZUs4RWs4N0JOeEJiWDN2Z05qZTViTHJCRFNYYWduZ1YxeHovbE0xOVo5Z0gvU2g5QWR1dGRUSG8rS1FqQW81c2VEWTNCT1dRdGZBcmVQVW1GN2VHOVRuU0Ywek5UUDNteEY0dU1JaTNLZUd3NkllcGdHZzRiYXVDMUhvdXpYWkQzWkVYYUplRTE5VDV3NlNhV3FDaU9wUzBUK1RzMDl0cUozQUxabTlvMWg0cm9KSjRoeFZJY1hBSFpHNlhxR2oySzFaYVNIQXg5Rm5ST2xtM1NqVTBIaVZnMVpuL1ZwemR4M2htcXRvcW44R1BqeGg2dHJOTkxFUFhodkVrdDhySkJUTDBvTWpob2NhOW1BU1ZSRE5tUFBRTzVyZDVnbXBYdXJPbld1aUd5QzNjOVJXR25XY3k0QlNUZUsrdHV6OUNjSThxbm1GWkUwUW5LbTgyQ3MwcFpUTjg0aVpyK3dVZWNVSnFDSzhiSEM2SUlKWEFVc1pNTyttWlJYM0cxdjlVdk1SZUp4R256MTFRVjBTYVYxNGVEdThOR0hCT3B4Q3Zod1g5RXk1TTBnSmJXNVpvdGlDM0xkS0NYV0JDWGJ2eElJbWlpQXpqZExrN0c0aThBYi83SlBidFJJVmY3dnVPeHFuc2ttQUtnOXZVNllxcHFmUWZ4QXZMRVhSU080R0pPc2F4Wm1CMmJMdHo0dzFZY3lBWFBTVWt4WkUzMEwrekI1Ymg4c1VBVDV6RVhLVHNmelp0Y1J3YzIzUUtNeSs2NXVSTnhIK2tnbThrcW1aclBITlhSSkpaNW1iSlpETG9IdzdlbEFjQ2QzMjhCTzhkVUFIUTdhOE01a0NQZjlPbXRrS3ZDOXRrMzFhYkl4MUZLbi9NMDhYQ2ZFNk9DQTRqNHRUOWRDTjZlUHB1WVg4cnRDOE51WVk0eDZsaThTMnJSSDkwUWhaVFNYdlJDWEt4UVY2SFloZUpUU1dkWkNqV2lmOUZUZXFNYVpmZnB2Y0lyYWhKOVlmS1FPRTh0dzU4MStiRVZZSGkzOENVcDFoV3J4bHppNSt5VmtWanV6RGlMSXE3UUdQakQ1SXdkMm9lMDB6RDFmTWpQbXZHdGk5Z2Nta3ozeDNKWEpkUFFCRVF4cUJJaTNHL1hlb0JyNGtUT0NGYWQ4ZTlXRVJwV2YzWDNBQ2JWZ0x6a1F4Q0pJNUt5dlZURnVBbFpBTFBKc2hOdDV0VWpKSE1JdXBRVmFDZy9Temlod1RiM09ZUXdwRzhKeFR6UHAyWDc2cXJuTGNCdFpoNjFmYWh4YmJ0SWIrdEVQTmNoKy93d29lZDNON3RoNWJaV1k4MERrNzZoWm0wbXpFTUloWktRWmJtejBzNTkzR2IrMmtQMHV0UUovUExFaTdpelByRmlybXVaNnU3Wjl3bUx1RDRMQnl6RHg2SUFObEdIeWRISW1BVFFiY096NjBOaGF0b3BCK2pxaEZTVzJuemhSZzJqVStNa2pxQUpMbDNwWDRReVI1Y1I4RFZINVNtOHVLcFR1NkVQS24rUjFnTTZuYUdOeVYzTjM5MGVZYk11K2x6RFZleGpvS1ZxOTM2TExTbjc0S0pDUHUvcG53NnhORUVDWlFSN2FiS0pMK01qZVZmMmVQaHdOY3RnN3FNMmY3T0RnS3BiN1BzYTBMNFVSWFhxM2RkNFpKazJlL2ZGZmh2eGFia0hQMC9XL1Vrajlta0hVSW5xa211RCtMdw==', 'decrypt');

        die;

        $user_id = 187;
        $user_integration_id = 174;
        $platform_workflow_rule_id = 84;

        $source_platform_id = 'intacct';
        $user_workflow_rule_id = 370;
        $this->SpsCreateUpdateInvoice($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready');

        die;


        $user_id = 185;
        $user_integration_id = 186;
        $platform_workflow_rule_id = 73;

        $source_platform_id = 'brightpearl';
        $user_workflow_rule_id = 460;
        $this->SpsCreateUpdateAcknowledgement($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id = '');

        die;




        echo "<br/>Refresh->" . $this->mobj->encrypt_decrypt('bm1ScWo2am13K0FkV0NIRFVUZlUrc3RLMENjVGEzczRBTWYzOUZtVTlMWG1iN1JkZm9Na1U0VW9DcDVFWTl5UQ==', 'decrypt');





        $user_id = 187;
        $id = 339;
        $this->GetAccessTokenUsingRefreshToken($user_id, $id, '');
        die;

        $user_id = 187;
        $user_integration_id = 174;
        $platform_workflow_rule_id = 84;

        $source_platform_id = 'intacct';
        $user_workflow_rule_id = 370;
        $this->SpsCreateUpdateInvoice($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', '44601');

        die;



















        echo "<br/>Appid->" . $this->mobj->encrypt_decrypt('Q2VvKzNOVEIxSzhMS1JWZ2hEdkxleUNocjJETndxYld2RHEzc250K0N3V21FVGJCMEo5VG54OHhFUWdGeGhQNQ==', 'decrypt');


        die;


        $user_id = 187;
        $user_integration_id = 163;
        $platform_workflow_rule_id = 83;
        $this->SpsGetAllPO($user_id, $user_integration_id, $platform_workflow_rule_id);
        die;
        $user_integration_id = 312;
        $platform_workflow_rule_id = 112;
        $shipping_method = 4;


        $source_row_data = DB::table('platform_object_data')->whereIn('user_integration_id', [$user_integration_id, 0])->where(['api_id' => $shipping_method, 'platform_object_id' => 18, 'platform_id' => 1])->select('id')->first();
        if ($source_row_data) {  // if $Source_row_data is set

            $spm = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "shipping_method_with_custom", [], 'regular', null, 'single', 'source', [], $source_row_data->id);
            dd($spm);
        }





        die;
        $shipping_method = [];
        $shipping_method['6'] = 'Military Official Mail';
        $shipping_method['7'] = 'Mail';
        $shipping_method['A'] = 'Air';
        $shipping_method['AR'] = 'Armed Forces Courier Service';
        $shipping_method['B'] = 'Barge';
        $shipping_method['BP'] = 'Book Postal';
        $shipping_method['BU'] = 'Bus';
        $shipping_method['C'] = 'Consolidation';
        $shipping_method['DW'] = 'Driveaway';
        $shipping_method['E'] = 'Expedited Truck';
        $shipping_method['F'] = 'Flyaway';
        $shipping_method['GG'] = 'Geographic Receiving/Shipping';
        $shipping_method['H'] = 'Customer Pickup';
        $shipping_method['HH'] = 'Household Goods Truck';
        $shipping_method['I'] = 'Common Irregular Carrier';
        $shipping_method['K'] = 'Backhaul';
        $shipping_method['L'] = 'Contract Carrier';
        $shipping_method['LA'] = 'Military Air';
        $shipping_method['LD'] = 'Local Delivery';
        $shipping_method['LT'] = 'Less Than Trailer Load[LTL]';
        $shipping_method['M'] = 'Motor[Common Carrier]';
        $shipping_method['N'] = 'Private Vessel';
        $shipping_method['O'] = 'Containerized Ocean';
        $shipping_method['P'] = 'Private Carrier';
        $shipping_method['R'] = 'Rail';
        $shipping_method['RC'] = 'Rail Less Than Carload';
        $shipping_method['SB'] = 'Shipper Agent';
        $shipping_method['SD'] = 'Shipper Association';
        $shipping_method['SE'] = 'Sea/Air';
        $shipping_method['SF'] = 'Surface Freight Forwarder';
        $shipping_method['SR'] = 'Supplier Truck';
        $shipping_method['SS'] = 'Steamship';
        $shipping_method['ST'] = 'Stack Train';
        $shipping_method['T'] = 'Best Way[Shippers Option]';
        $shipping_method['TA'] = 'Towaway Service';
        $shipping_method['TC'] = 'Cab/Taxi';
        $shipping_method['TL'] = 'Truck Load [TL]';
        $shipping_method['TT'] = 'Tank Truck';
        $shipping_method['VE'] = 'Ocean Vessel';
        $shipping_method['VL'] = 'Lake Vessel';
        $shipping_method['W'] = 'Inland Waterway';
        $shipping_method['WP'] = 'Water or Pipeline Intermodal Movement';
        $shipping_method['X'] = 'Intermodal[Piggyback]';

        $sync_object_id = $this->helper->getObjectId('shipping_method');


        foreach ($shipping_method as $code => $name) {
            echo $code . "-->" . $name . "<br/>";
            //DB::table('platform_object_data')->insert(['platform_id'=>2,'platform_object_id'=>$sync_object_id,'api_id'=>$code,'name'=>$name,'api_code'=>$code]);
        }

        die;






        $user_id = 149;
        $user_integration_id = 259;
        $platform_workflow_rule_id = 42;
        $source_platform = 'intacct';
        $user_workflow_rule_id = 414;
        $this->SpsCreateUpdateInvoice($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id = '');
        die;

        $user_id = 150;
        $user_integration_id = 398;
        $platform_workflow_rule_id = 111;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 673;
        $this->SpsCreateUpdateInvoice($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id = '');
        die;

        $user_id = 150;
        $user_integration_id = 398;
        $platform_workflow_rule_id = 118;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 722;
        $this->SPSUpdateInventory($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'Ready', $record_id = '');
        die;

        $user_id = 150;
        $user_integration_id = 398;
        $platform_workflow_rule_id = 110;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 972;
        $this->SpsCreateUpdateAcknowledgement($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id = '');
        die;


        $user_id = 150;
        $user_integration_id = 398;
        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetProducts($user_id, $user_integration_id, 2, 1);

        die;

        $user_id = 150;
        $user_integration_id = 398;
        //dd(app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetWebhookList($user_integration_id));
        dd(app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetPendingInvoice($user_id, $user_integration_id, 0));
        die;


        $user_id = 147;
        $user_integration_id = 312;
        $platform_workflow_rule_id = 112;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 650;
        $this->SpsCreateUpdateShipment($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'SO', 'Ready', $record_id = '');
        die;

        /*
        $user_id = 147;
        $user_integration_id = 312;
        $platform_workflow_rule_id = 110;
        $destination_platform_id = 'spscommerce';
        $user_workflow_rule_id = 648;
        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetOrdersByType($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $destination_platform_id, 0, 'sales_orders', 1, 1);
        die;
        */







        $user_id = 147;
        $user_integration_id = 312;
        $platform_workflow_rule_id = 84;
        $source_platform = 'spscommerce';
        $user_workflow_rule_id = 495;
        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->SyncOrderInBP($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform, $sync_status = "Ready", $RecordID = NULL);
        die;

        $user_id = 147;
        $user_integration_id = 312;
        $platform_workflow_rule_id = 84;
        $this->SpsGetAllPO($user_id, $user_integration_id, $platform_workflow_rule_id);
        die;


        $user_id = 190;
        $user_integration_id = 351;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 573;
        $platform_workflow_rule_id = 34;
        $sync_status = 'Ready';
        $order_type = 'PO';

        $this->SpsCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '');
        die;

        $user_id = 150;
        $user_integration_id = 299;
        $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

        if ($sps_account) {


            //PAGINATION TIME DATE Filter
            $result = $this->spsapi->GetAllPO($sps_account, $user_id, $user_integration_id);
            $listpo = json_decode($result, true);

            echo "<pre>";
            print_r($listpo);
        }

        die;

        $user_id = 150;
        $user_integration_id = 299;
        $po_id = "850PO113564";
        $postdata = '{
            "Header":{
               "OrderHeader":{
                  "PurchaseOrderNumber":"113564",
                  "PrimaryPOTypeCode":"SA",
                  "PurchaseOrderDate":"2021-12-17",
                  "Vendor":"2005"
               },
               "Notes":[
                  {
                     "NoteCode":"GEN",
                     "Note":"Final testing"
                  }
               ],
               "PaymentTerms":[
                  {
                     "TermsDescription":"Final test"
                  }
               ],
               "Dates":[
                  {
                     "DateTimeQualifier":"002",
                     "Date":"2021-12-20"
                  }
               ],
               "Contacts":[
                  {
                     "ContactTypeCode":"BD",
                     "ContactName":"Carthryn Company LLC",
                     "PrimaryPhone":"920-783-8100",
                     "PrimaryEmail":"orders@carthryn.com"
                  }
               ],
               "Address":[
                  {
                     "AddressTypeCode":"ST",
                     "LocationCodeQualifier":"92",
                     "AddressLocationNumber":"01",
                     "AddressName":"Carthryn Company LLC",
                     "Address1":"801 N 8th St",
                     "Address2":"Central Avenue",
                     "Address3":null,
                     "Address4":null,
                     "City":"Sheboygan",
                     "State":"WI",
                     "PostalCode":"53081",
                     "Country":"US"
                  }
               ],
               "CarrierInformation":[
                  {
                     "CarrierRouting":"Three Day Service",
                     "ServiceLevelCode":"3D"
                  }
               ],
               "References":[
                  {
                     "ReferenceQual":"GK",
                     "ReferenceID":"123456"
                  }
               ]
            },
            "LineItem":[
               {
                  "OrderLine":{
                     "LineSequenceNumber":1,
                     "BuyerPartNumber":"11570-041-20A",
                     "VendorPartNumber":"11570-041-20A",
                     "ConsumerPackageCode":"32112332112",
                     "OrderQty":1,
                     "OrderQtyUOM":"EA",
                     "PurchasePrice":32.47
                  },
                  "ProductOrItemDescription":[
                     {
                        "ProductCharacteristicCode":"08",
                        "ProductDescription":"A durable, versatile long-sleeved shirt."
                     }
                  ]
               }
            ]
         }';


        $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
        $result_sps_order = $this->spsapi->CreatePO($sps_account, $user_id, $po_id, $postdata);
        $result = json_decode($result_sps_order, true);
        echo "<pre>";
        print_r($result);
        die;

        $user_id = 147;
        $user_integration_id = 91;
        $platform_workflow_rule_id = 92;
        $user_workflow_rule_id = 536;
        $SorucePlatformName = 'spscommerce';
        //$this->SpsGetAllInvoices($user_id,$user_integration_id,$platform_workflow_rule_id);

        app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->CreatePOGoodInNote($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $SorucePlatformName, $sync_status = "Ready", NULL);

        die;
        $user_id = 147;
        $user_integration_id = 91;
        $platform_workflow_rule_id = 65;
        $user_workflow_rule_id = 536;
        $SorucePlatformName = 'spscommerce';
        //$this->SpsGetAllInvoices($user_id,$user_integration_id,$platform_workflow_rule_id);

        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreatePOGoodInNote($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $SorucePlatformName, $sync_status = "Ready", NULL);

        die;

        $user_id = 147;
        $user_integration_id = 312;

        $OrderStatus = $this->map->getMappedDataByName(273, null, "get_order_status", ['name'], "regular", null, "multiple", "source", ['api_id', 'name']);
        echo "<pre>";


        print_r($OrderStatus);


        die;

        $user_id = 147;
        $user_integration_id = 312;
        $sps_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['access_token', 'env_type', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);


        $headers = ['Authorization: Bearer ' . $this->mobj->encrypt_decrypt($sps_account->access_token, 'decrypt')];
        $response = $this->mobj->makeCurlRequest('GET', 'https://api.spscommerce.com/transactions/v5/data/testout/*', [], $headers);
        $res = json_decode($response, true);
        echo "<pre>";
        print_r($res);

        die;



        $user_id = 147;
        $user_integration_id = 312;
        $platform_workflow_rule_id = 84;
        $this->SpsGetAllPO($user_id, $user_integration_id, $platform_workflow_rule_id);
        die;


        $user_id = 150;
        $user_integration_id = 299;
        $source_platform_id = 'brightpearl';
        $user_workflow_rule_id = 466;
        $platform_workflow_rule_id = 34;
        $sync_status = 'Ready';
        $order_type = 'PO';

        $this->SpsCreateUpdatePO($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, null);
        die;

        $user_id = 149;
        $user_integration_id = 130;
        $source_platform = 'intacct';
        $user_workflow_rule_id = 191;
        $platform_workflow_rule_id = 42;
        $sync_status = 'Ready';

        $this->SpsCreateInvoice($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id = '');
        die;

        $user_id = 147;
        $user_integration_id = 91;
        $platform_workflow_rule_id = 65;
        $this->SpsGetAllInventory($user_id, $user_integration_id, $platform_workflow_rule_id);

        die;

        $user_id = 147;
        $user_integration_id = 73;
        $source_platform = 'kefron';
        $user_workflow_rule_id = 345;
        $WorkFlowID = 62;
        $sync_status = 'Ready';

        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreatePurchaseOrderInvoice($user_id, $user_integration_id, $WorkFlowID, $user_workflow_rule_id, $source_platform, $sync_status, $RecordID = NULL);


        die;


        $user_id = 147;
        $user_integration_id = 91;
        $source_platform = 'spscommerce';
        $user_workflow_rule_id = 152;
        $WorkFlowID = 35;
        $platformObjectId = 10;
        $order_type = 'PO';
        $sync_status = 'Ready';
        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatusAndNotes($user_id, $user_integration_id, $WorkFlowID, $user_workflow_rule_id, $source_platform, $order_type, $sync_status, $RecordID = NULL);



        die;




        $user_id = 147;


        dd($this->spsapi->GetAllTransactions($user_id));

        die;
        $user_integration_id = 91;
        $source_platform = 'brightpearl';
        $user_workflow_rule_id = 147;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $this->SpsCreateUpdatePO($user_id, $source_platform, $user_integration_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '');
        /*  echo "<pre>";
$a = array('hello');
$b = array('byy');

$c = $a + $b;
print_r($a);
print_r($b);
print_r($c);
die;*/

        die;
        $ct_qualifiers = array();
        $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] = 3;
        dd($ct_qualifiers);
        echo "hii";
        die;
        /*
        $user_id = 97;
        $user_integration_id = 12;
        $file_name = 'PO11111_1627894346.txt';
        $data = file_get_contents(public_path().'/esb_asset/spscommerce/'.$user_id.'_'.$user_integration_id.'/'.$file_name);
        $res = json_decode($data,true);
        echo "<pre>";
        print_r($res);

        die;
        */


        $user_id = 97;
        $user_integration_id = 12;
        $source_platform = 'intacct';
        $user_workflow_rule_id = 2;

        $this->CreateInvoiceToSPS($user_id, $source_platform, $user_integration_id, $user_workflow_rule_id);

        //$postdata = json_encode($res,true);
        //echo "<pre>";
        //print_r($res);

        die;
    }
}
