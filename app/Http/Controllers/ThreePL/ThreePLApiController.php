<?php

namespace App\Http\Controllers\ThreePL;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\Api\ThreePLApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProduct;
use Validator;
use App\Models\PlatformStates;
use Lang;

class ThreePLApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $tpl, $helper, $map, $platformId, $log;
    public static $myPlatform = '3pl';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->tpl = new ThreePLApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    /* Display 3PL form for credentials */
    public function InitiateThreePLAuth(Request $request)
    {
        if ($request->isMethod('get')) {

            return view("pages.apiauth.auth_3pl", ["platform" => self::$myPlatform]);
        }
    }
    /* Checking Duplicate Account */
    public function CheckExistingConnectedAc($platform_id, $client_id, $client_secret, $user_login_id, $tpl, $default_customer_id, $default_facility_id)
    {
        $client_id = $this->mobj->encrypt_decrypt($client_id);
        $client_secret = $this->mobj->encrypt_decrypt($client_secret);
        $tpl = $this->mobj->encrypt_decrypt($tpl);
        $obj_existing = PlatformAccount::where([['platform_id', '=', $platform_id], ['app_id', '=', $client_id], ['app_secret', '=', $client_secret], ['access_key', '=', $user_login_id], ['refresh_token', '=', $tpl], ['marketplace_id', '=', $default_customer_id], ['secret_key', '=', $default_facility_id]])->count();
        if ($obj_existing > 0) {
            return true;
        } else {
            return false;
        }
    }
    /* Save 3PL credentials */
    public function ConnectThreePLAuth(Request $request)
    {
        if ($request->isMethod('post')) {
            $flag = true;
            $validator = Validator::make($request->all(), [
                'client_id' => 'required',
                'client_secret' => 'required',
                'user_login_id' => 'required|numeric',
                'tpl' => 'required',
                'default_customer_id' => 'required|numeric',
                'default_facility_id' => 'required|numeric',
                'domain' => 'required',
            ]);

            if ($this->mobj->checkHtmlTags($request->all())) {
                $data['status_code'] = 0;
                $data['status_text'] = Lang::get('tags.validate');
                return json_encode($data);
            }

            if ($validator->fails()) {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = $validator->getMessageBag()->toArray();
            } else {
                // To check whether given account is already in use or not.
                $checkExistingAc = $this->CheckExistingConnectedAc($this->platformId, $request->client_id, $request->client_secret, $request->user_login_id, $request->tpl, $request->default_customer_id, $request->default_facility_id);

                if ($checkExistingAc) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'This account detail already exist, Try with another account.';
                } else {
                    if (filter_var($request->domain, FILTER_VALIDATE_URL) === FALSE) {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'This is not a valid URL.';
                    } else {
                        $domain = parse_url($request->domain, PHP_URL_HOST);
                        $checkCredentials = $this->tpl->CheckCredentials($request->client_id, $request->client_secret, $request->tpl, $request->user_login_id, $domain);

                        if (!isset($checkCredentials['access_token']) ||  !isset($this->platformId)) {
                            $flag = false;
                            $data['status_code'] = 0;
                            $data['status_text'] = 'Invalid ' . self::$myPlatform . ' credentials or try with different credentials!!';
                        } else {
                            $user_id =  Auth::user()->id;
                            $client_id = $this->mobj->encrypt_decrypt($request->client_id);
                            $client_secret = $this->mobj->encrypt_decrypt($request->client_secret);
                            $user_login_id = $request->user_login_id;
                            $default_customer_id = $request->default_customer_id;
                            $default_facility_id = $request->default_facility_id;
                            $tpl = $this->mobj->encrypt_decrypt($request->tpl);


                            /* Create Model Instance */
                            $count =  PlatformAccount::where('platform_id', self::$myPlatform)->get()->count();
                            $increment = $count > 0 ?  '_' . $domain . "_" . $count . "_" . date('m-d-Y') : '_' . $domain . "_" . date('m-d-Y');
                            $arr_field = [
                                'account_name' => self::$myPlatform . $increment,
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'app_id' => $client_id,
                                'app_secret' => $client_secret,
                                'access_key' => $user_login_id,
                                'secret_key' => $default_facility_id,
                                'marketplace_id' => $default_customer_id,
                                'refresh_token' => $tpl,
                                'access_token' => $this->mobj->encrypt_decrypt($checkCredentials['access_token']),
                                "token_type" => $checkCredentials['token_type'],
                                "expires_in" => $checkCredentials['expires_in'],
                                "token_refresh_time" => time(),
                                'api_domain' => $domain,
                            ];
                            $this->mobj->makeInsertGetId('platform_accounts', $arr_field);
                        }
                    }
                }
            }
            if ($flag) {
                $data['status_code'] = 1;
                $data['status_text'] = 'Account connected successfully.';
            }
            return response()->json($data);
        }
    }
    /* Refresh token */
    function RefreshTokens($ID)
    {
        $return_response = false;
        date_default_timezone_set('UTC');
        try {

            if ($this->platformId) {
                $accDetail = PlatformAccount::select('id', 'access_key', 'refresh_token', 'app_id', 'app_secret', 'api_domain')->find($ID);
                if ($accDetail) {
                    $client_id = $this->mobj->encrypt_decrypt($accDetail->app_id, 'decrypt');
                    $client_secret = $this->mobj->encrypt_decrypt($accDetail->app_secret, 'decrypt');
                    $tpl = $this->mobj->encrypt_decrypt($accDetail->refresh_token, 'decrypt'); //tpl
                    $user_login_id = $accDetail->access_key; //user_login_id
                    $domain = $accDetail->api_domain; //api_domain name
                    $checkCredentials = $this->tpl->CheckCredentials($client_id, $client_secret, $tpl, $user_login_id, $domain);
                    if (isset($checkCredentials['access_token']) &&  isset($this->platformId)) {
                        $accDetail->access_token = $this->mobj->encrypt_decrypt($checkCredentials['access_token']);
                        $accDetail->expires_in = $checkCredentials['expires_in'];
                        $accDetail->token_refresh_time = time();
                        $accDetail->save();
                        $return_response = true; //$checkCredentials['access_token'];
                    } else {
                        $return_response = isset($checkCredentials['Message']) ? $checkCredentials['Message'] : "API Error";
                    }
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
            //\Storage::disk('local')->append('testCrone.txt', 'ThreePl Refresh Token Resp'.json_encode($return_response));
        }
        return $return_response;
    }
    /* Receive  webhook */
    public function receiveWebhook(Request $request)
    {
    
        try {
        
            if ($request->isMethod('post')) {
                $body = $request->getContent();
                $result_data = json_decode($body, 1);
                \Storage::disk('local')->append(date('d-m-Y').'_3plWebhooks.txt', json_encode($result_data)." Date: ".date('d-m-Y H:i:s'));
            }
        } catch (\Exception $e) {
            Log::error("ThreePLApiController -> receiveWebhook -> " . $e->getLine() . " -> " . $e->getMessage());
        }  
        return true;
    }
    /* Get Products */
    public function GetProducts($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
    {
        $this->mobj->AddMemory();
        $return_response = false;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId,  $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret', 'refresh_token', 'marketplace_id']);

            if ($account && $this->platformId) {

                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {

                    if ($attempt == 1 && $Initial == 1) { // To pull items
                        $x = true;
                        $page = 1;
                        $pageLimit = 100;
                        while ($x) {
                            $response = $this->tpl->CallAPI($account, "GET", "/customers/{$account->marketplace_id}/items?pgsiz={$pageLimit}&pgnum={$page}", [], 'json');

                            if ($items = json_decode($response, true)) {
                                if (!isset($items['ErrorCode'])) {

                                    if (is_array($items['ResourceList']) && isset($items['TotalResults']) && !empty($items['ResourceList'])) {

                                        foreach ($items['ResourceList'] as $key => $value) {
                                            $fields = [
                                                'user_id' => $userId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'api_product_id' => isset($value['ItemId']) ? $value['ItemId'] : NULL,
                                                'product_name' => isset($value['Description']) ? htmlspecialchars($value['Description']) : NULL,
                                                'upc' => isset($value['Upc']) ? $value['Upc'] : NULL,
                                                'sku' => isset($value['Sku']) ? $value['Sku'] : NULL,
                                            ];
                                            $where = [
                                                'user_id' => $userId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'api_product_id' =>  isset($value['ItemId']) ? $value['ItemId'] : NULL,
                                            ];
                                            PlatformProduct::updateOrCreate($where, $fields);
                                        }
                                    } else if (is_array($items['ResourceList']) && isset($items['TotalResults']) && empty($items['ResourceList'])) {
                                        $return_response = $x;
                                        $x = false;
                                        break;
                                    } else {
                                        $return_response = isset($items['ErrorCode']) ? $items['ErrorCode'] : "Internal API Error";
                                        $x = false;
                                        break;
                                    }
                                    $page++;
                                } else {
                                    $return_response = isset($items['Hint']) ? $items['Hint'] : $items['ErrorCode'];
                                    $x = false;
                                    break;
                                }
                            } else {

                                $return_response = "API Error:Unauthorized";

                                $x = false;
                                break;
                            }
                        }
                    } else if ($attempt == 2 && $Initial == 0) {
                        $x = true;
                        $page = 1;
                        $pageLimit = 100;
                        //$date = date('Y-m-d\TH:i:s', strtotime('-60 minutes'));
                        $date = date('Y-m-d', strtotime('-60 minutes'));
                        $response = $this->tpl->CallAPI($account, "GET", "/customers/{$account->marketplace_id}/items?pgsiz={$pageLimit}&pgnum={$page}&rql=ReadOnly.LastModifiedDate=ge={$date}&sort=" . urlencode("-ReadOnly.LastModifiedDate"), [], 'json');

                        if ($items = json_decode($response, true)) {

                            if (!isset($items['ErrorCode'])) {

                                if (is_array($items['ResourceList']) && isset($items['TotalResults']) && !empty($items['ResourceList'])) {

                                    foreach ($items['ResourceList'] as $key => $value) {
                                        $fields = [
                                            'user_id' => $userId,
                                            'user_integration_id' => $userIntegrationId,
                                            'platform_id' => $account->platform_id,
                                            'api_product_id' => isset($value['ItemId']) ? $value['ItemId'] : NULL,
                                            'product_name' => isset($value['Description']) ? htmlspecialchars($value['Description']) : NULL,
                                            'upc' => isset($value['Upc']) ? $value['Upc'] : NULL,
                                            'sku' => isset($value['Sku']) ? $value['Sku'] : NULL,
                                        ];
                                        $where = [
                                            'user_id' => $userId,
                                            'user_integration_id' => $userIntegrationId,
                                            'platform_id' => $account->platform_id,
                                            'api_product_id' =>  isset($value['ItemId']) ? $value['ItemId'] : NULL,
                                        ];
                                        PlatformProduct::updateOrCreate($where, $fields);
                                    }
                                    $return_response = true;
                                }
                            } else {
                                $return_response = isset($items['Hint']) ? $items['Hint'] : $items['ErrorCode'];
                            }
                        } else {

                            $return_response = "API Error:Unauthorized";
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Insert Shipping Method */
    public function SaveShippingMethod($user_id, $user_integration_id, $platform_id, $shipping_method)
    {
        $object_id = $this->helper->getObjectId('shipping_method'); //Get Object ID
        $where = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $platform_id,
            'platform_object_id' => $object_id,
            'api_id' => $shipping_method,
        ];
        $fields = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $platform_id,
            'api_id' => $shipping_method,
            'name' =>  $shipping_method,
            'description' => $shipping_method,
            'platform_object_id' => $object_id,

        ];
        PlatformObjectData::updateOrCreate($where, $fields);
    }
    /* Get Carries | Shipping Methods */
    public function GetShippingMethods($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
    {
        $this->mobj->AddMemory();
        $return_response = false;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId,  $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret', 'refresh_token', 'marketplace_id']);
            if ($account && $this->platformId) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {

                    if ($attempt == 1) { // To pull shipping names
                        $object_id = $this->helper->getObjectId('shipping_method'); //Get Object ID
                        $billingCodeObjectID = $this->helper->getObjectId('billing_code');
                        if ($object_id && $billingCodeObjectID) {
                            $response = $this->tpl->CallAPI($account, "GET", "/properties/carriers", [], 'json');

                            if ($shipping = json_decode($response, true)) {

                                if (!isset($shipping['ErrorCode'])) {
                                    if (isset($shipping['DefaultBillingCodes']) && !empty($shipping['DefaultBillingCodes'])) {

                                        foreach ($shipping['DefaultBillingCodes'] as $key => $value) {
                                            $DefaultBillingCodes = [
                                                'user_id' => $userId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'api_id' => $value['Code'],
                                                'name' =>  isset($value['Code']) ? $value['Code'] : NULL,
                                                'platform_object_id' => $billingCodeObjectID,
                                            ];
                                            $where = [
                                                'user_id' => $userId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'platform_object_id' => $billingCodeObjectID,
                                                'api_id' => $value['Code'],
                                            ];
                                            PlatformObjectData::updateOrCreate($where, $DefaultBillingCodes);
                                        }
                                    }

                                    if (isset($shipping['DefaultShipmentServices']) && !empty($shipping['DefaultShipmentServices'])) {

                                        foreach ($shipping['DefaultShipmentServices'] as $key => $value) {
                                            $fields = [
                                                'user_id' => $userId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'api_id' => $value['Code'],
                                                'name' =>  isset($value['Description']) ? $value['Description'] : NULL,
                                                'description' => isset($value['Description']) ? $value['Description'] : NULL,
                                                'platform_object_id' => $object_id,

                                            ];

                                            $where = [
                                                'user_id' => $userId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'platform_object_id' => $object_id,
                                                'api_id' => $value['Code'],
                                            ];
                                            PlatformObjectData::updateOrCreate($where, $fields);
                                        }
                                    }

                                    if (isset($shipping['ResourceList']) && !empty($shipping['ResourceList'])) {

                                        foreach ($shipping['ResourceList'] as $key => $resourceList) {
                                            $carries_name = $resourceList['Name'];
                                            if (isset($resourceList['ShipmentServices'])) {
                                                foreach ($resourceList['ShipmentServices'] as $service) {
                                                    $fields = [
                                                        'user_id' => $userId,
                                                        'user_integration_id' => $userIntegrationId,
                                                        'platform_id' => $account->platform_id,
                                                        'api_id' => $service['Code'],
                                                        'name' => $carries_name,
                                                        'description' => $service['Description'],
                                                        'platform_object_id' => $object_id,
                                                    ];

                                                    $where = [
                                                        'user_id' => $userId,
                                                        'user_integration_id' => $userIntegrationId,
                                                        'platform_id' => $account->platform_id,
                                                        'platform_object_id' => $object_id,
                                                        'api_id' => $service['Code'],
                                                    ];
                                                    PlatformObjectData::updateOrCreate($where, $fields);
                                                }
                                            }
                                        }
                                    }
                                    $return_response = true;
                                } else {
                                    $return_response = isset($shipping['Hint']) ? $shipping['Hint'] : $shipping['ErrorCode'];
                                }
                            } else {

                                $return_response = "API Error";
                            }
                        } else {
                            $return_response = "Object ID not found";
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Bill To | Sold To | ShipTo | Ship Date */
    public function GetAddress($address, $contactID)
    {
        $billTo = $shipTo = $soldTo = $szip = NULL;
        if ($address) {
            foreach ($address as $key => $value) {
                $stateName = isset($value->address4) ? $value->address4 : '';
                if ($stateName) {
                    $FindState = PlatformStates::select('iso2')->where(function ($query) use ($stateName) {
                        $query->where(
                            'name',
                            '=',
                            $stateName
                        )
                            ->orWhere('iso2', '=', $stateName);
                    })->first();
                    $state = isset($FindState->iso2) ? $FindState->iso2 :  $stateName;
                } else {
                    $state = $stateName;
                }

                if ($value->address_type == "shipping") {
                    /* shipping address */
                    $address_name = isset($value->address_name) ? $value->address_name : '';
                    $company_name = isset($value->company) && !empty($value->company) ? $value->company :  $address_name;
                    $address1 = isset($value->address1) ? $value->address1 : '';
                    $address2 = isset($value->address2) ? $value->address2 : '';
                    $city = isset($value->address3) ? $value->address3 : '';
                    $szip = isset($value->postal_code) ? $value->postal_code : '';
                    $country = isset($value->country) ? $value->country : '';
                    $telephone = isset($value->phone_number) ? $value->phone_number : '';
                    $email = isset($value->email) ? $value->email : '';
                    $shipTo = [
                        //"contactId" => $contactID,
                        "companyName" => $company_name,
                        "name" => $address_name,
                        "address1" => $address1,
                        "address2" => $address2,
                        "city" =>  $city,
                        "state" => $state,
                        "zip" => $szip,
                        "country" => $country,
                        "phoneNumber" => $telephone,
                        "emailAddress" => $email,
                    ];
                }
                if ($value->address_type == "customer") {
                    /* customer address */
                    $address_name = isset($value->address_name) ? $value->address_name : '';
                    $company_name = isset($value->company) && !empty($value->company) ? $value->company :  $address_name;
                    $address1 = isset($value->address1) ? $value->address1 : '';
                    $address2 = isset($value->address2) ? $value->address2 : '';
                    $city = isset($value->address3) ? $value->address3 : '';

                    $zip = isset($value->postal_code) ? $value->postal_code : '';
                    $country = isset($value->country) ? $value->country : '';
                    $telephone = isset($value->phone_number) ? $value->phone_number : '';
                    $email = isset($value->email) ? $value->email : '';
                    $soldTo = [
                        //"contactId" => $contactID,
                        "companyName" => $company_name,
                        "name" => $address_name,
                        "address1" => $address1,
                        "address2" => $address2,
                        "city" =>  $city,
                        "state" => $state,
                        "zip" => $zip,
                        "country" => $country,
                        "phoneNumber" => $telephone,
                        "emailAddress" => $email,
                    ];
                }
                if ($value->address_type == "billing") {
                    /* billing address */
                    $address_name = isset($value->address_name) ? $value->address_name : '';
                    $company_name = isset($value->company) && !empty($value->company) ? $value->company :  $address_name;
                    $address1 = isset($value->address1) ? $value->address1 : '';
                    $address2 = isset($value->address2) ? $value->address2 : '';
                    $city = isset($value->address3) ? $value->address3 : '';

                    $zip = isset($value->postal_code) ? $value->postal_code : '';
                    $country = isset($value->country) ? $value->country : '';
                    $telephone = isset($value->phone_number) ? $value->phone_number : '';
                    $email = isset($value->email) ? $value->email : '';
                    $billTo = [
                        //"contactId" => $contactID,
                        "companyName" => $company_name,
                        "name" => $address_name,
                        "address1" => $address1,
                        "address2" => $address2,
                        "city" =>  $city,
                        "state" => $state,
                        "zip" => $zip,
                        "country" => $country,
                        "phoneNumber" => $telephone,
                        "emailAddress" => $email,
                    ];
                }
            }
        }

        return ['billTo' => $billTo, 'shipTo' => $shipTo, 'soldTo' => $soldTo, 'shipPointZip' => $szip];
    }
    /* Prepare Order Lines */
    public function PrepareOrderLine($orderLines, $userID, $userIntegrationId, $WorkFlowID, $SourcePlatformId)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $maping_data =  $this->map->getMappedField($userIntegrationId, $WorkFlowID, $product_identity_obj_id);
        $notFound = false;
        $items = [];
        if ($maping_data) {
            $source_row_data = $destination_row_data = '';
            if ($maping_data['destination_platform_id'] == '3pl') {
                $destination_row_data = $maping_data['destination_row_data'];
                $source_row_data = $maping_data['source_row_data'];
            } else {
                $destination_row_data = $maping_data['source_row_data'];
                $source_row_data = $maping_data['destination_row_data'];
            }

            if ($orderLines) {

                foreach ($orderLines as $key => $val) {
                    $bp_product = PlatformProduct::select($source_row_data)->where(['user_integration_id'=> $userIntegrationId, 'platform_id'=> $SourcePlatformId, 'api_product_id'=> $val->product_id, 'is_deleted'=> 0])->pluck($source_row_data)->first(); //Find Source Product by product_id

                    if ($bp_product) {
                        $api_product_id = PlatformProduct::select('api_product_id')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], [$destination_row_data, '=', $bp_product], ['is_deleted', '=', 0]])->pluck('api_product_id')->first();
                        if ($api_product_id) {
                            $qty = (int) $val->sum;
                            array_push($items, ['itemIdentifier' => ['sku' => $bp_product, 'id' => $api_product_id], 'qty' => $qty]);
                        } else {
                            $notFound=true;
                        }
                    } else {
                        $notFound=true;
                    }
                }
            }
        }
        return ['items' => $items, 'notfound' => $notFound];
    }

    /* Create Order In 3PL */
    public function SyncOrderIn3PL($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SorucePlatformName = NULL,  $sync_status = "Ready", $RecordID = NULL, $account = NULL)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 25;
            if (!isset($account)) {
                $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'api_domain', 'secret_key', 'marketplace_id']);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
            $SOurceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);
            if ($account && $this->platformId && $SourcePlatformId && $SOurceUfound) {
                    $object_id = $this->helper->getObjectId('sales_order');
                    $query = PlatformOrder::with(['platformOrderAddress', 'getShipmentReadyAndFailed'])->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id',  'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status',  'is_voided',  'is_deleted', 'linked_id',  'order_updated_at', 'updated_at', 'warehouse_id', 'order_date');
                    if ($RecordID && $RecordID !== 0) {
                        $query->where('id', $RecordID);
                    } else {
                        $query->where([
                            'platform_id' => $SourcePlatformId,
                            'user_integration_id' => $userIntegrationId,
                            'sync_status' => $sync_status
                        ]);
                    }
                    $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();
                    if (!empty($list) && count($list) > 0) {
                        $recordExist = 1;
                        $tplAccount =  $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_account_number",  ['custom_data'], "default");

                        $default_carrier_code =  $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_carrier_code",  ['custom_data'], "default");
                        $default_carrier_mode =  $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_carrier_mode",  ['custom_data'], "default");

                        $default_carrier_mode = isset($default_carrier_mode->custom_data) ? $default_carrier_mode->custom_data : NULL;

                        $default_carrier_code = isset($default_carrier_code->custom_data) ? $default_carrier_code->custom_data : NULL;
                        $tplAccount = isset($tplAccount->custom_data) ? $tplAccount->custom_data : NULL;
                        $billingCode = $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_billing_code", ['api_id']);
                        $billingCode = isset($billingCode->api_id) ? $billingCode->api_id : NULL;                      
                        foreach ($list as $value) {
                            /* Find Order Primary Key */
                            $order_primary_id = isset($value->id) ? $value->id : NULL;
                            $shipment = isset($value->getShipmentReadyAndFailed) ? $value->getShipmentReadyAndFailed : null;
                            if ($shipment) {
                                $shipmentId = $shipment->id;
                                $shipment_number = $shipment->shipment_id;
                                if (isset($value->is_deleted) && $value->is_deleted == 0 && empty($value->linked_id) && $value->linked_id == 0) {
                                    if($order_primary_id) {
                                        if ($billingCode) {
                                            /* Find Address */
                                            $address = isset($value->platformOrderAddress) ? $value->platformOrderAddress : NULL;
                                            if ($address) {
                                                $orderLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $shipmentId)->groupBy('product_id')
                                                ->selectRaw('*, sum(quantity) as sum')->get();
                                            
                                                if( $orderLines){
                                                    $orderLines = $this->PrepareOrderLine($orderLines, $userId, $userIntegrationId, $WorkFlowID, $SourcePlatformId);
                                                    $address = $this->GetAddress($address, $account->marketplace_id); //marketplace_id is a 3PL customer id
                                                    if ($orderLines['notfound'] == false && count($orderLines['items']) > 0) {
                                                        $post = [
                                                            "customerIdentifier" => [
                                                                "id" => $account->marketplace_id //customerIdentifier
                                                            ],
                                                            "facilityIdentifier" => [
                                                                "id" => $account->secret_key //facilityIdentifier
                                                            ],
                                                            "referenceNum" => $value->order_number . "-" . date("d-m-Y H:i:s"), 
                                                            "poNum" => $shipment_number,
                                                            "billingCode" => $billingCode,
                                                            "routingInfo" => [
                                                                "carrier" =>  $default_carrier_code,
                                                                "mode" =>  $default_carrier_mode,
                                                                "account" => $tplAccount,
                                                                "shipPointZip" => isset($address['shipPointZip']) ? $address['shipPointZip'] : NULL,
                                                            ],
                                                            "shipTo" => $address['shipTo'],
                                                            "soldTo" => $address['soldTo'],
                                                            "billTo" => $address['billTo'],
                                                            "orderItems" => $orderLines['items']
                                                        ];

                                                        $response = $this->tpl->CallAPI($account, "POST", "/orders", json_encode($post), 'json');

                                                        if ($result = json_decode($response, true)) {
                                                            if (isset($result['ReadOnly'])) {
                                                                /* Insert 3pl order details  */
                                                                $OrderLinked = $this->mobj->makeInsertGetId('platform_order', [
                                                                    'user_id' => $userId,
                                                                    'platform_id' => $this->platformId,
                                                                    'user_integration_id' => $userIntegrationId,
                                                                    'order_type' => "SO",
                                                                    'api_order_id' => $result['ReadOnly']['OrderId'],
                                                                    'order_date' => date("Y-m-d H:i:s", strtotime($result['ReadOnly']['CreationDate'])),
                                                                    'order_number' => $result['ReadOnly']['OrderId'],
                                                                    'sync_status' => 'Ready',
                                                                    'linked_id' =>  $order_primary_id,
                                                                    'shipment_status' => "Pending",
                                                                    //'platform_order_shipment_id' => $value->id,
                                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                                ]);

                                                                /* Source Order sync status updated with linked id  */
                                                                $value->sync_status = "Synced";
                                                                $value->linked_id = $OrderLinked;
                                                                $value->order_updated_at= date("Y-m-d H:i:s");
                                                                $value->save();
                                                                /* Shipment Table sync status updated */                                                                
                                                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Synced"]);
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                                            } else {
                                                                /* Source Order sync status updated with linked id  */
                                                                $value->sync_status = "Failed";                                                              
                                                                $value->order_updated_at= date("Y-m-d H:i:s");
                                                                $value->save();
                                                                /* Shipment Table sync status updated */
                                                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                                $error = isset($result['Hint']) ? $result['Hint'] : $result['ErrorCode'];
                                                                $return_response = $error;
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                                                            }
                                                        } else { 
                                                            /* Invalid call */
                                                            $value->sync_status = 'Failed';
                                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                                            $value->save();
                                                            /* Shipment Table sync status updated */
                                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                            $return_response = "API Error:Invalid Json";
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                        }
                                                    }else{
                                                        /* products not found */
                                                        $value->sync_status = 'Failed';
                                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                                        $value->save();
                                                        /* Shipment Table sync status updated */
                                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                        $return_response = "Products are not found";
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                    }

                                                }else{
                                                    /* products not found */
                                                    $value->sync_status = 'Failed';
                                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                                    $value->save();
                                                    /* Shipment Table sync status updated */
                                                    PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                    $return_response = "Products are not found";
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                }
                                            } else {
                                            /* Address not found */
                                                $value->sync_status = 'Failed';
                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                $value->save();
                                                /* Shipment Table sync status updated */
                                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                $return_response = "Customer addresses are invalid or not in a proper way";
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                            }
                                        } else {
                                        /* Billing code not found */
                                            $value->sync_status = 'Failed';
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();
                                            /* Shipment Table sync status updated */
                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                            $return_response = "Billing code or address not found";
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else if ($order_primary_id && $value->sync_status == "Synced") {
                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Inactive"]);
                                    } else {

                                        $return_response = "Order detail has been not found";
                                    }
                                } else if (isset($value->is_deleted) && $value->is_deleted == 1) {
                                    /*  Proceed to failed order when is_deleted=1 and linked_id=0 */
                                    $value->sync_status = 'Failed';
                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                    $value->save();
                                    $return_response = "Order related data deleted in source platform.";
                                    /* Shipment Table sync status updated */
                                    PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                }
                            }
                        }                      
                    }
                    if ($recordExist == 0) {
                        $return_response = "Record not exist";
                    }
                
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    public function SyncOrderIn3PLBK($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SorucePlatformName = NULL,  $sync_status = "Ready", $RecordID = NULL)
    {
        $return_response = true;

        try {
            $recordExist = 0;
            $limit = 25;
            $object_id = $this->helper->getObjectId('sales_order');
            $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'api_domain', 'secret_key', 'marketplace_id']);
            $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
            $SOurceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);
            if ($account && $this->platformId && $SourcePlatformId && $SOurceUfound) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {

                    $query = PlatformOrderShipment::with(['platformShippingLines', 'platformOrder.platformOrderAddress'])->select('id', 'user_id', 'platform_id', 'user_integration_id', 'shipment_id', 'sync_status', 'platform_order_id', 'order_id', 'shipping_method', 'shipment_sequence_number');
                    if ($RecordID && $RecordID !== 0) {
                        $find = PlatformOrder::with('GetGoodsOutNote')->find($RecordID);
                        if (isset($find->GetGoodsOutNote) && isset($find->GetGoodsOutNote->id)) {
                            $query->where('id', $find->GetGoodsOutNote->id);
                        } else {
                            $list = [];
                        }
                    } else {
                        $query->where([
                            'platform_id' => $SourcePlatformId,
                            'user_integration_id' => $userIntegrationId,
                            'sync_status' => $sync_status
                        ]);
                    }

                    $list = $query->orderBy('updated_at', 'ASC')->orderBy('shipment_id', 'DESC')->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {
                        $recordExist = 1;
                        $tplAccount =  $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_account_number",  ['custom_data'], "default");

                        $default_carrier_code =  $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_carrier_code",  ['custom_data'], "default");
                        $default_carrier_mode =  $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_carrier_mode",  ['custom_data'], "default");

                        $default_carrier_mode = isset($default_carrier_mode->custom_data) ? $default_carrier_mode->custom_data : NULL;

                        $default_carrier_code = isset($default_carrier_code->custom_data) ? $default_carrier_code->custom_data : NULL;
                        $tplAccount = isset($tplAccount->custom_data) ? $tplAccount->custom_data : NULL;
                        $billingCode = $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "default_billing_code", ['api_id']);
                        $billingCode = isset($billingCode->api_id) ? $billingCode->api_id : NULL;

                        $update_timeIds = [];
                        foreach ($list as $value) {
                            $Process = true;
                            if ($RecordID == 0 || $RecordID = NULL) {
                                /* This part is execute when record is null or 0 and set old GON to Inactive */
                                $ValidGON = PlatformOrderShipment::select('id', 'sync_status')->where([
                                    [
                                        'platform_id', '=', $SourcePlatformId
                                    ], [
                                        'user_integration_id', '=', $userIntegrationId
                                    ], [
                                        'sync_status', '=', $sync_status
                                    ], [
                                        'platform_order_id', '=', $value->platform_order_id
                                    ]
                                ])->orderBy('shipment_id', 'DESC')->first();
                                if ($ValidGON) {
                                    if ($ValidGON->id != $value->id) {
                                        $value->sync_status = "Inactive";
                                        $value->save();
                                        $Process = false;
                                    }
                                }
                            }


                            if ($Process) {
                                $bp_order_status = isset($value->platformOrder->sync_status) ? $value->platformOrder->sync_status : NULL;
                                $bp_delete_order_status = isset($value->platformOrder->is_deleted) && $value->platformOrder->is_deleted == 0 ? true : false; //This is basically accept only not deleted orders 
                                if (in_array($bp_order_status, [$sync_status, 'Failed']) && $bp_delete_order_status) {
                                    /* Find Address */
                                    $address = isset($value->platformOrder->platformOrderAddress) ? $value->platformOrder->platformOrderAddress : NULL;
                                    /* Find Order Primary Key */
                                    $order_primary_id = isset($value->platformOrder->id) ? $value->platformOrder->id : NULL;
                                    if ($order_primary_id && $bp_order_status == "Ready") {
                                        if ($billingCode) {
                                            if ($address) {
                                                $orderLines = isset($value->platformShippingLines) ? $value->platformShippingLines : NULL;
                                                $address = $this->GetAddress($address, $account->marketplace_id); //marketplace_id is a 3PL customer id

                                                if ($orderLines) {
                                                    $orderLines = $this->PrepareOrderLine($orderLines, $userId, $userIntegrationId, $WorkFlowID, $SourcePlatformId);

                                                    if ($orderLines['notfound'] == false && count($orderLines['items']) > 0) {


                                                        $post = [
                                                            "customerIdentifier" => [
                                                                "id" => $account->marketplace_id //customerIdentifier
                                                            ],
                                                            "facilityIdentifier" => [
                                                                "id" => $account->secret_key //facilityIdentifier
                                                            ],
                                                            "referenceNum" => $value->platformOrder->order_number . "-" . date("d-m-Y H:i:s"),
                                                            "poNum" => $value->shipment_id,
                                                            "billingCode" => $billingCode,
                                                            "routingInfo" => [
                                                                "carrier" =>  $default_carrier_code,
                                                                "mode" =>  $default_carrier_mode,
                                                                "account" => $tplAccount,
                                                                "shipPointZip" => isset($address['shipPointZip']) ? $address['shipPointZip'] : NULL,
                                                            ],
                                                            "shipTo" => $address['shipTo'],
                                                            "soldTo" => $address['soldTo'],
                                                            "billTo" => $address['billTo'],
                                                            "orderItems" => $orderLines['items']
                                                        ];

                                                        $response = $this->tpl->CallAPI($account, "POST", "/orders", json_encode($post), 'json');

                                                        if ($result = json_decode($response, true)) {
                                                            if (isset($result['ReadOnly'])) {
                                                                /* Insert 3pl order details  */
                                                                $OrderLinked = $this->mobj->makeInsertGetId('platform_order', [
                                                                    'user_id' => $userId,
                                                                    'platform_id' => $this->platformId,
                                                                    'user_integration_id' => $userIntegrationId,
                                                                    'order_type' => "SO",
                                                                    'api_order_id' => $result['ReadOnly']['OrderId'],
                                                                    'order_date' => date("Y-m-d H:i:s", strtotime($result['ReadOnly']['CreationDate'])),
                                                                    'order_number' => $result['ReadOnly']['OrderId'],
                                                                    'sync_status' => 'Ready',
                                                                    'linked_id' =>  $order_primary_id,
                                                                    'shipment_status' => "Pending",
                                                                    //'platform_order_shipment_id' => $value->id,
                                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                                ]);

                                                                /* Source Order sync status updated with linked id  */
                                                                $this->mobj->makeUpdate('platform_order', ['linked_id' => $OrderLinked, 'sync_status' => 'Synced', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                                                /* Shipment Table sync status updated */
                                                                $value->sync_status = "Synced";
                                                                $value->save();
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                                            } else {
                                                                /* Source Order sync status updated with linked id  */
                                                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                                                /* Shipment Table sync status updated */
                                                                $value->sync_status = "Failed";
                                                                $value->save();
                                                                $error = isset($result['Hint']) ? $result['Hint'] : $result['ErrorCode'];
                                                                $return_response = $error;
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                                                            }
                                                        } else {
                                                            /* Source Order sync status updated with linked id  */
                                                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                                            /* Shipment Table sync status updated */
                                                            $value->sync_status = "Failed";
                                                            $value->save();
                                                            $return_response = "API Error:Invalid Json";
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                        }
                                                    } else {
                                                        /* Source Order sync status updated with linked id  */
                                                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                                        /* Shipment Table sync status updated */
                                                        $value->sync_status = "Failed";
                                                        $value->save();
                                                        $return_response = "Products are not found";
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                    }
                                                } else {
                                                    /* If no shipment lines found  */
                                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                                    /* Shipment Table sync status updated */
                                                    $value->sync_status = "Failed";
                                                    $value->save();
                                                    $return_response = "Products are not found";
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                }
                                            } else {
                                                /* If no shipment address found  */
                                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                                /* Shipment Table sync status updated */
                                                $value->sync_status = "Failed";
                                                $value->save();
                                                $return_response = "Customer addresses are invalid or not in a proper way";
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                            }
                                        } else {
                                            /* If no billing code found  */
                                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order_primary_id]);
                                            /* Shipment Table sync status updated */
                                            $value->sync_status = "Failed";
                                            $value->save();
                                            $return_response = "Billing Code not found";
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else if ($order_primary_id && $bp_order_status == "Synced") {
                                        $value->sync_status = "Inactive";
                                        $value->save();
                                    } else {

                                        $return_response = "Order detail has been not found";
                                    }
                                } else {
                                    $update_timeIds[] = $value->id;
                                }
                            }
                        }

                        // Updating Time of ids which has not been ready to pick
                        if (count($update_timeIds)) {
                            DB::table('platform_order_shipments')->whereIn('id', $update_timeIds)->update(['updated_at' => date('Y-m-d H:i:s')]);
                        }
                    }
                    if ($recordExist == 0) {
                        $return_response = "Record not exist";
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get Tracking Info By Order */
    public function GetTrackingOrder($userId = NULL, $userIntegrationId = NULL, $UserWorkFlowID,  $sync_status = "Pending")
    {
        $return_response = false;
        try {
            $limit = 25;
            $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'api_domain', 'secret_key', 'marketplace_id']);
            if ($account && $this->platformId) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                    $object_id = $this->helper->getObjectId('sales_order');
                    $list = PlatformOrder::select('id', 'api_order_id', 'platform_order_shipment_id', 'updated_at', 'linked_id')->where([
                        [
                            'platform_id', '=', $this->platformId
                        ], [
                            'user_integration_id', '=', $userIntegrationId
                        ], [
                            'shipment_status', '=', $sync_status
                        ]
                    ])->orderBy('updated_at', 'ASC')->take($limit)->get();


                    if (!empty($list) && count($list) > 0) {

                        foreach ($list as $order) {

                            if (isset($order->linked_id)) {

                                $shipment = PlatformOrderShipment::select('linked_id', 'id')->where('platform_order_id', $order->linked_id)->where('sync_status', 'Synced')->orderBy('shipment_id', 'asc')->first();
                                if ($shipment) {

                                    $response = $this->tpl->CallAPI($account, "GET", "/orders/{$order->api_order_id}?detail=all&itemdetail=all", [], 'json');
                                    if ($result = json_decode($response, true)) {

                                        if (isset($result['ReadOnly'])) {
                                            if (isset($result['ReadOnly']['Status']) && $result['ReadOnly']['Status'] == 2) {
                                                /* If order cancel | Set Failed */
                                                $order->shipment_status = 'Failed';
                                                $order->save();
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID, self::$myPlatform, $this->platformId, $object_id, 'failed', $order->id, "Order has been cancelled from 3PL");
                                                continue;
                                            }
                                            if (isset($result['RoutingInfo']['TrackingNumber']) && !empty($result['RoutingInfo']['TrackingNumber'])) {
                                                if (isset($result['RoutingInfo']['Carrier'])) {
                                                    //if carrier is not available
                                                    $this->SaveShippingMethod($userId, $userIntegrationId, $this->platformId, $result['RoutingInfo']['Carrier']);
                                                }
                                                if ($shipment->linked_id) {
                                                    $newshipment = PlatformOrderShipment::find($shipment->linked_id)->update([
                                                        'sync_status' => "Ready", 'tracking_info' => isset($result['RoutingInfo']['TrackingNumber']) ? $result['RoutingInfo']['TrackingNumber'] : NULL,
                                                        'shipping_method' => isset($result['RoutingInfo']['Carrier']) ? $result['RoutingInfo']['Carrier'] : NULL,
                                                    ]);
                                                } else {

                                                    $newshipment = PlatformOrderShipment::create([
                                                        'user_id' => $userId,
                                                        'platform_id' => $this->platformId,
                                                        'user_integration_id' =>  $userIntegrationId,
                                                        'sync_status' => "Ready",
                                                        'platform_order_id' => $order->id,
                                                        'order_id' => $order->api_order_id,
                                                        'tracking_info' => isset($result['RoutingInfo']['TrackingNumber']) ? $result['RoutingInfo']['TrackingNumber'] : NULL,
                                                        'shipping_method' => isset($result['RoutingInfo']['Carrier']) ? $result['RoutingInfo']['Carrier'] : NULL,
                                                        'linked_id' => $shipment->id
                                                    ]);
                                                    $shipment->linked_id = $newshipment->id;
                                                    $shipment->save();
                                                    $order->platform_order_shipment_id = $newshipment->id;
                                                    $order->save();
                                                }
                                                $order->shipment_status = 'Ready';
                                                //$order->order_updated_at = date('Y-m-d H:i:s');
                                                $order->save();
                                                //\Log::channel('webhook')->info("GetTrackingOrder_3PL_Log -" . $userId . " Integration " . $userIntegrationId . " Order PID: " . $order->id ." Shipment PID-".$newshipment->id. " Created Date : " . date('Y-m-d H:i:s'));
                                            }
                                        } else {
                                            $return_response = "API Error";
                                            //continue;
                                            //\Log::channel('webhook')->info("GetTrackingOrder_3PL_Log -" . $userId . " Integration " . $userIntegrationId . " Order PID: " . $order->id ." Error- ".$return_response. " Created Date : " . date('Y-m-d H:i:s'));
                                        }
                                    }
                                }
                            }
                            $order->updated_at = date('Y-m-d H:i:s');

                            $order->save();
                        }
                    }
                    $return_response = true;
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Execute 3pl Method */
    public function ExecuteEvent3PL($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
    {
        $response = true;
        if ($method == 'GET' && $event == 'SHIPPINGMETHOD') {

            $response = $this->GetShippingMethods($user_id, $user_integration_id, 1, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'PRODUCT') {
            if ($is_initial_sync) {
                $response = $this->GetProducts($user_id, $user_integration_id, 1, $is_initial_sync);
            } else {
                $response = $this->GetProducts($user_id, $user_integration_id, 2, $is_initial_sync);
            }
        } else if ($method == 'GET' && $event == 'SHIPMENT') {
            if ($is_initial_sync == 0) {
                $sync_status = 'Pending';
                $response = $this->GetTrackingOrder($user_id, $user_integration_id, $user_workflow_rule_id, $sync_status);
                //\Log::channel('webhook')->info("GetTrackingOrder_3PL -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . "Response: " . $response . " Created Date : " . date('Y-m-d H:i:s'));
            }
        } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
            $sync_status = 'Ready';
            $response = $this->SyncOrderIn3PL($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            //\Log::channel('webhook')->info("MUTATE_SALESORDER_3PL -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . "Response: " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'MUTATE' && $event == 'ORDERSTATUS') {
            //This is not run for 3pl mutate
            $response = true;
        }
        return   $response;
    }
}
