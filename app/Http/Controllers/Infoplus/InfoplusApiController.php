<?php

namespace App\Http\Controllers\Infoplus;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\Api\InfoplusApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use App\Models\PlatformAccount;
use Validator;
use App\Http\Controllers\Infoplus\InfoplusServiceController;
use App\Models\Enum\PlatformRecordType;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use Carbon\Carbon;
use Lang;

class InfoplusApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $helper, $map, $platformId, $log, $service, $infoplus;
    public static $myPlatform = 'infoplus';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->infoplus = new InfoplusApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->service = new InfoplusServiceController;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }
    /* Display infoplus form for credentials */
    public function InitiateInfoplusAuth(Request $request)
    {
        if ($request->isMethod('get')) {
            $platform = self::$myPlatform;
            return view("pages.apiauth.auth_infoplus", compact('platform'));
        }
    }
    /* Save Infoplus credentials */
    public function ConnectInfoplusAuth(Request $request)
    {
        if ($request->isMethod('post')) {

            if ($this->mobj->checkHtmlTags($request->all())) {
                $data['status_code'] = 0;
                $data['status_text'] = Lang::get('tags.validate');
                return json_encode($data);
            }

            $flag = true;
            $regex = '/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/';
            $validator = Validator::make($request->all(), [
                'access_key' => 'required',
                'api_domain' => 'required|regex:' . $regex,
            ]);

            if ($validator->fails()) {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = $validator->getMessageBag()->toArray();
            } else {
                if (filter_var($request->api_domain, FILTER_VALIDATE_URL) === FALSE) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'This is not a valid URL.';
                } else {
                    $access_key = $this->mobj->encrypt_decrypt($request->access_key);
                    $domain = parse_url($request->api_domain, PHP_URL_HOST);
                    // To check whether given account is already in use or not.
                    $checkExistingAc = $this->service->CheckExistingConnectedAccount($domain,  $access_key);
                    if ($checkExistingAc) {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'This account detail is already exist, Try with another account.';
                    } else {
                        $access_key = $this->mobj->encrypt_decrypt($request->access_key);
                        $domain = parse_url($request->api_domain, PHP_URL_HOST);
                        $checkCredentials = $this->service->CheckAPICredentials($access_key, $domain);
                        if (!$checkCredentials || !isset($this->platformId)) {

                            $flag = false;
                            $data['status_code'] = 0;
                            $data['status_text'] = 'Invalid credentials!';
                        } else {
                            $user_id = Auth::user()->id;
                            $platform_accounts =  new PlatformAccount();
                            $count = $platform_accounts->where('platform_id', $this->platformId)->count();
                            $increment = $count > 0 ?  '_' . $domain . "_" . $count . "_" . date('m-d-Y') : '_' . $domain . "_" . date('m-d-Y');
                            $platform_accounts->create([
                                'account_name' => self::$myPlatform . $increment,
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'access_key' => $access_key,
                                'api_domain' =>  $domain,
                                'allow_refresh' =>  0,
                            ]);
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
    /* Get Carriers */
    public function GetCarriers($userId = NULL, $userIntegrationId = NULL, $attempt = 1, $is_initial_syn = 0, $account = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                $x = true;
                $page = 1;
                $pageLimit = 250;
                $objectId = $this->helper->getObjectId('shipping_method');
                if ($attempt == 1 && $is_initial_syn == 1) {
                    /* Set status=0 */
                    $this->service->SetStatus($userId, $userIntegrationId, $this->platformId, $objectId);
                    while ($x) {
                        $arguments = [
                            "limit" => $pageLimit,
                            "page" => $page
                        ];
                        $apicall = $this->infoplus->_API_CALL($account, "GET", "carrier/search",  $arguments);

                        $carriers = $apicall['body'];

                        if (!empty($carriers) && is_array($carriers)) {
                            if (!isset($carriers['errors'])) {
                                foreach ($carriers as $key => $value) {


                                    $List = array(
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $this->platformId,
                                        'api_id' => $value['carrier'],
                                        'name' => $value['label'],
                                        'status' => 1,
                                        'platform_object_id' => $objectId

                                    );

                                    PlatformObjectData::updateOrCreate([
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' =>  $this->platformId,
                                        'api_id' => $value['carrier'],
                                        'platform_object_id' => $objectId
                                    ], $List);
                                }
                                $page++;
                                $x = true;
                            } else {
                                continue;
                            }

                            if ($page % 2 == 0) {
                                sleep(1);
                            }
                        } else {

                            $x = false;
                        }
                    }
                    $return_response =  true;
                } else if ($attempt == 1 && $is_initial_syn == 0) {
                    $arguments = [
                        "limit" => 250,
                        "page" => 1
                    ];
                    $apicall = $this->infoplus->_API_CALL($account, "GET", "carrier/search",  $arguments);

                    $carriers = $apicall['body'];

                    if (!empty($carriers) && is_array($carriers)) {
                        if (!isset($carriers['errors'])) {
                            foreach ($carriers as $key => $value) {

                                $List = array(
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'api_id' => $value['carrier'],
                                    'name' => $this->service->getSubstringBeforeLastBracket($value['label']),
                                    'status' => 1,
                                    'platform_object_id' => $objectId

                                );
                                PlatformObjectData::updateOrCreate([
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' =>  $this->platformId,
                                    'api_id' => $value['carrier'],
                                    'platform_object_id' => $objectId
                                ], $List);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Carriers */
    public function GetCommodityCode($userId = NULL, $userIntegrationId = NULL, $attempt = 1,  $is_initial_syn = 0, $account = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                $x = true;
                $page = 1;
                $pageLimit = 250;
                $objectId = $this->helper->getObjectId('commodity_code');
                if ($attempt == 1 && $is_initial_syn == 1) {

                    /* Set status=0 */
                    $this->service->SetStatus($userId, $userIntegrationId, $this->platformId, $objectId);
                    while ($x) {
                        $arguments = [
                            "limit" => $pageLimit,
                            "page" => $page
                        ];
                        $apicall = $this->infoplus->_API_CALL($account, "GET", "commodityCode/search",  $arguments, [], "v3.0");

                        $commodity_code = $apicall['body'];


                        if (!empty($commodity_code) && is_array($commodity_code)) {
                            if (!isset($commodity_code['errors'])) {
                                foreach ($commodity_code as $key => $value) {
                                    $List = array(
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $this->platformId,
                                        'api_id' => $value['id'],
                                        'api_code' => $value['code'],
                                        'status' => 1,
                                        'platform_object_id' => $objectId

                                    );

                                    PlatformObjectData::updateOrCreate([
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' =>  $this->platformId,
                                        'api_id' => $value['id'],
                                        'platform_object_id' => $objectId
                                    ], $List);
                                }
                                $page++;
                                $x = true;
                            } else {
                                continue;
                            }

                            if ($page % 2 == 0) {
                                sleep(1);
                            }
                        } else {

                            $x = false;
                        }
                    }
                    $return_response =  true;
                } else if ($attempt == 1 && $is_initial_syn == 0) {
                    $arguments = [
                        "limit" => 250,
                        "page" => 1
                    ];
                    $apicall = $this->infoplus->_API_CALL($account, "GET", "commodityCode/search",  $arguments, [], "v3.0");

                    $commodity_code = $apicall['body'];

                    if (!empty($commodity_code) && is_array($commodity_code)) {
                        if (!isset($commodity_code['errors'])) {
                            foreach ($commodity_code as $key => $value) {
                                $List = array(
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'api_id' => $value['id'],
                                    'api_code' => $value['code'],
                                    'status' => 1,
                                    'platform_object_id' => $objectId

                                );

                                PlatformObjectData::updateOrCreate([
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' =>  $this->platformId,
                                    'api_id' => $value['id'],
                                    'platform_object_id' => $objectId
                                ], $List);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /**
     * Function is used to get products last update (api_updated_at) datetime
     *

     * @param $userIntegrationId, the user_integration id

     * @return array value
     */
    public function GetProductLastUpdateDate($userIntegrationId = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute

        try {
            $account = $this->getPrimaryAccount($userIntegrationId);

            if ($account) {
                //if intial sync is set =0
                $lastDate = PlatformProduct::select('api_updated_at')->where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId,
                ])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                if (isset($lastDate->api_updated_at)) {
                    $date = $lastDate->api_updated_at;
                    $msg = "Last product date found";
                } else {
                    $date = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    $msg = "Current date with 60 minute minus";
                }

                $return_response = ['code' => 200, 'date' => $date, 'message' => $msg];
            } else {
                $return_response = ['code' => 204, 'date' => NULL, 'message' => "User integration not found"];
            }
        } catch (\Exception $e) {
            $return_response = ['code' => 204, 'date' => NULL, 'message' => $e->getMessage()];
        }
        return json_encode($return_response);
    }
    public function ReceiveProductWebhook(Request $request, $user_integration_id)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $response = true;
        try {
            if ($request->isMethod('post')) {
                // \Log::channel('webhook')->info("Integration " . $user_integration_id . " Product_infoplus_hook -ProductPrimaryId=" . $request['id'] .  " Created Date : " . date('Y-m-d H:i:s'));
                $integration = $this->map->getUserIntegrationDetailsById($user_integration_id, self::$myPlatform);
                if ($integration) {
                    if (!empty($request['sku'])) {
                        if (!$this->map->getIntegProductById($user_integration_id, $request['id'], 'ReceiveProductWebhook', self::$myPlatform)) { //Skip same prodoct webhook under 6 seconds
                            $this->service->PrepareModalData($request, $integration->user_id, $user_integration_id);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
        }
        return $response;
    }
    /**
     * Function is used to get products at intial sync and after any new or updation of product in infoplus
     *
     * @param $userId, the user's id with the current integration
     * @param $userIntegrationId, the user_integration id
     * @param $is_initial_sync, for set intial value or not
     * @param $account, the platform_account credentials
     * @return bool value
     */
    public function GetProducts($userId = NULL, $userIntegrationId = NULL, $is_initial_syn = 0, $account = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account && $this->platformId) {
                if ($is_initial_syn) { // get prducts by chunks in loop when intial sync=1
                    $x = 1;
                    while ($x <= 1) {
                        $loopBreaker = true;
                        $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', 'products']])->first();
                        if (isset($pageNo->url)) {
                            if ($pageNo->url == 0 && $pageNo->status == 1) {

                                $loopBreaker = false;
                            } else {
                                $page = $pageNo->url + 1;
                            }
                        } else {
                            $page = 1;
                        }
                        if ($loopBreaker) {
                            $pageCounter = $page;
                            $pageLimit = 250;
                            $breakCounter = 0;
                            $arguments = [
                                "limit" => $pageLimit,
                                "page" => $page
                            ];
                            $apicall = $this->infoplus->_API_CALL($account, "GET", "item/search", $arguments, [], "v3.0");
                            $product = $apicall['body'];


                            if (!empty($product) || count($product) > 0) {
                                if (!isset($product['errors'])) {
                                    foreach ($product as $key => $value) {
                                        if (!empty($value['sku'])) {
                                            $this->service->PrepareModalData($value, $userId, $userIntegrationId);
                                        }
                                    }
                                    if ($breakCounter == 0) {

                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $page;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                                'url' => $page + 1,
                                                'url_name' => 'products',
                                                'status' => 0
                                            ]);
                                        }
                                        $return_response = "Page-{$pageCounter} data processed";
                                    } else {
                                        $return_response = "API Error to get products from " . self::$myPlatform;
                                    }
                                } else {
                                    continue;
                                }
                            } else {
                                if (isset($pageNo->url)) {
                                    $pageNo->url = 0;
                                    $pageNo->status = 1;
                                    $pageNo->save();
                                }
                                $return_response = true;
                            }
                        } else {
                            $return_response = true;
                        }
                        $x++;
                    }
                } else {
                    //if intial sync is set =0
                    $lastDate = PlatformProduct::select('api_updated_at')->where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                    ])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                    if (isset($lastDate->api_updated_at)) {
                        $date = $lastDate->api_updated_at;
                    } else {
                        $date = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    }
                    $page = 1;
                    $pageLimit = 250;
                    $arguments = [
                        "limit" => $pageLimit,
                        "page" => $page,
                        "filter" => "modifyDate gte '" . $date . "'",
                        "sort" =>  "!modifyDate"
                    ];


                    $apicall = $this->infoplus->_API_CALL($account, "GET", "item/search", $arguments, [], "v3.0");
                    $product = $apicall['body'];
                    if (!empty($product) || count($product) > 0) {
                        if (!isset($product['errors'])) {
                            foreach ($product as $key => $value) {
                                if (!empty($value['sku'])) {
                                    $this->service->PrepareModalData($value, $userId, $userIntegrationId);
                                }
                            }
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
    /* Get Source Account Extra Information */
    public  function getAccountExtraInformation($user_integration_id, $platformID)
    {
        $account = $this->getPrimaryAccount($user_integration_id);
        return $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['user_integration_id' => $user_integration_id, 'account_id' => $account->id]);
    }
    /**
     * Function is used to sync products in infoplus
     *
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $sync_status, the platform_workflow_rule id
     * @param $record_id, for resyncing the failed data
     * @param $account, platform account details
     */
    public function SyncProducts($user_id = NULL, $user_integration_id = NULL, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_name, $sync_status, $record_id = null, $account = NULL)

    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $returnstatus = true;
        try {

            if (!isset($account)) {
                $account = $this->getPrimaryAccount($user_integration_id);
            }
            if ($account) {
                $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
                $sourceAccountExtraInformation = $this->getAccountExtraInformation($user_integration_id, $source_platform_id);
                if ($source_platform_id) {
                    /* Check bundle_kit_child_product_quantity mapping */
                    $bundleKitChildProductQuantity = $this->map->getMappedDataByName($user_integration_id, null, "bundle_kit_child_product_quantity", ['custom_data'], "default");
                    $syncBundleProduct = isset($bundleKitChildProductQuantity->custom_data) ? $bundleKitChildProductQuantity->custom_data : "No";
                    /* Default LOB */
                    $default_lob =  $this->map->getMappedDataByName($user_integration_id, NULL, "default_order_line_of_business",  ['api_id'], "default");

                    if ($default_lob) {

                        $default_order_lob = $default_lob->api_id;
                    } else {
                        $default_order_lob = 0;
                    }
                    /* Identify Product Uniqueness */
                    $identity = $this->service->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                    $source_row_data = $identity['source_identity']; //Source Identity
                    $destination_row_data = $identity['destination_identity']; //Destination Identity
                    if ($source_row_data && $destination_row_data) {
                        $object_id = $this->helper->getObjectId('product');
                        $commodityObjectId = $this->helper->getObjectId('commodity_code');
                        $process_limit = 15;
                        $q = DB::table('platform_product as s')
                            ->Leftjoin('platform_product_detail_attributes as at', 'at.platform_product_id', '=', 's.id');

                        if ($record_id && $record_id !== 0) {
                            $q->where('s.id', $record_id);
                        } else {
                            $condition = [
                                ['s.user_id', '=', $user_id],
                                ['s.user_integration_id', '=', $user_integration_id],
                                ['s.platform_id', '=', $source_platform_id],
                                ['s.product_sync_status', '=', $sync_status],
                                ['s.is_deleted', '=', 0],
                                ['s.bundle', '=', 0],  /* Only non bundle products can sync in infoplus */
                                // ['at.lob', '=', $default_order_lob]

                            ];
                            if (strtolower($syncBundleProduct) == "yes") {
                                /* if sync bundle product mapping  found=Yes
                                */
                                unset($condition[5]); //unset(['s.bundle', '=', 0])
                            }
                            $q->where($condition);
                        }
                        $product_arr = $q->select('s.id', 's.user_id', 's.user_integration_id', 's.platform_id', 's.api_product_id', 's.product_name', 's.linked_id', 's.sku', 's.upc', 's.weight', 's.category_id', 's.stock_track', 's.description', 'at.shortdescription', 'at.lenght', 'at.height', 'at.width', 'at.volume', 'at.taxcode_ids', 'at.product_type_ids', 'at.forward_lot_mixing_rule', 'at.storage_lot_mixing_rule', 'at.forward_item_mixing_rule', 'at.storage_item_mixing_rule', 'at.allocation_rule', 's.has_variations', 's.parent_product_id', 's.bundle', 's.api_group_id', 'at.lob')->limit($process_limit)->get();

                        if (count($product_arr) > 0) {

                            $product_price_list_object_id = $this->helper->getObjectId('product_pricelist');

                            $collectCommodity = []; //commodity code memo
                            /* Product Major Category ID Mapping */
                            $majorCategoryMapping = $this->map->getMappedDataByName($user_integration_id, null, "product_category", ['custom_data'], "default");
                            $majorCategoryText = isset($majorCategoryMapping->custom_data) ? $majorCategoryMapping->custom_data : null;
                            /* Product Sub CategoryID Mapping */
                            $subCategoryMapping = $this->map->getMappedDataByName($user_integration_id, null, "product_sub_category", ['custom_data'], "default");
                            $subCategoryText = isset($subCategoryMapping->custom_data) ? $subCategoryMapping->custom_data : null;
                            $subGroupId = $majorGroupId = null;
                            /* Find Category ID (majorGroupId)*/
                            //$majorGroupIdArr=$subGroupIdArr=[];
                            if ($majorCategoryText) {
                                $majorGroupId = $this->service->findAndCreateCategory($user_id, $user_integration_id, $majorCategoryText, $default_order_lob, $account, 'category');
                                //$majorGroupIdArr[$default_order_lob]=$majorGroupId;
                            }
                            sleep(1);
                            /* Find  Sub Category ID (subGroupId) */
                            if ($subCategoryText) {
                                $subGroupId = $this->service->findAndCreateCategory($user_id, $user_integration_id, $subCategoryText, $default_order_lob, $account, 'sub_category');
                                //$subGroupIdArr[$default_order_lob]= $subGroupId;
                            }
                            foreach ($product_arr as $product) {
                                /* assing sub group id & major group id */
                                $product->subGroupId = $subGroupId;
                                $product->majorGroupId = $majorGroupId;

                                $findProduct = null;
                                if (isset($product->linked_id)) {
                                    $condition = [
                                        ['s.id', '=', $product->linked_id],
                                        ['s.is_deleted', '=', 0],
                                        ['s.bundle', '=', 0],  /* Only non bundle products can sync in infoplus */
                                        ['at.lob', '=', $default_order_lob]
                                    ];
                                    if (strtolower($syncBundleProduct) == "yes") {
                                        unset($condition[2]); //unset(['s.bundle', '=', 0])
                                    }
                                    $findProduct = DB::table('platform_product as s')
                                        ->Leftjoin('platform_product_detail_attributes as at', 'at.platform_product_id', '=', 's.id')->where($condition)->select('s.id', 's.api_product_id', 's.user_id', 's.user_integration_id', 's.platform_id', 's.product_name', 's.linked_id', 's.sku', 's.upc', 's.weight', 's.category_id', 's.stock_track', 's.description', 'at.shortdescription', 'at.lenght', 'at.height', 'at.width', 'at.volume', 'at.taxcode_ids', 's.bundle', 'at.product_type_ids', 's.has_variations', 's.parent_product_id', 's.api_group_id', 'at.forward_lot_mixing_rule', 'at.storage_lot_mixing_rule', 'at.forward_item_mixing_rule', 'at.storage_item_mixing_rule', 'at.allocation_rule', 'at.lob')->first();
                                }
                                $prod = (array) $product;
                                if (!$findProduct) {
                                    $condition = [
                                        ['s.user_id', '=', $user_id],
                                        ['s.user_integration_id', '=', $user_integration_id],
                                        ['s.platform_id', '=', $this->platformId],
                                        ['s.is_deleted', '=', 0],
                                        ['s.bundle', '=', 0],  /* Only non bundle products can sync in infoplus */
                                        ['at.lob', '=', $default_order_lob],
                                        ['s.' . $destination_row_data, '=', $prod[$source_row_data]]

                                    ];
                                    if (strtolower($syncBundleProduct) == "yes") {
                                        unset($condition[4]); //unset(['s.bundle', '=', 0])
                                    }
                                    $findProduct = DB::table('platform_product as s')
                                        ->Leftjoin('platform_product_detail_attributes as at', 'at.platform_product_id', '=', 's.id')
                                        ->where($condition)
                                        ->select('s.id', 's.user_id', 's.user_integration_id', 's.platform_id', 's.api_product_id', 's.product_name', 's.linked_id', 's.sku', 's.upc', 's.weight', 's.category_id', 's.stock_track', 's.description', 'at.shortdescription', 'at.lenght', 'at.height', 'at.width', 'at.volume', 'at.taxcode_ids', 'at.product_type_ids', 's.has_variations', 's.parent_product_id', 's.bundle', 's.api_group_id', 'at.forward_lot_mixing_rule', 'at.storage_lot_mixing_rule', 'at.forward_item_mixing_rule', 'at.storage_item_mixing_rule', 'at.allocation_rule', 'at.lob')->first();
                                }

                                if ($findProduct) {
                                    // $product_default_order_lob=$findProduct->lob;
                                    // if ($majorCategoryText && !isset($majorGroupIdArr[$product_default_order_lob])) {
                                    //     $majorGroupId =$this->service->findAndCreateCategory($user_id, $user_integration_id, $majorCategoryText, $product_default_order_lob,$account,'category');
                                    //     $majorGroupIdArr[$product_default_order_lob]=$majorGroupId;

                                    //     $product->majorGroupId = $majorGroupId;
                                    //     $default_order_lob=$product_default_order_lob;
                                    // }
                                    // sleep(1);
                                    // /* Find  Sub Category ID (subGroupId) */
                                    // if ($subCategoryText && !isset($subGroupIdArr[$product_default_order_lob])) {
                                    //     $subGroupId =$this->service->findAndCreateCategory($user_id, $user_integration_id, $subCategoryText, $product_default_order_lob,$account,'sub_category');
                                    //     $subGroupIdArr[$product_default_order_lob]= $subGroupId;
                                    //     $product->subGroupId = $subGroupId;
                                    //     $default_order_lob=$product_default_order_lob;
                                    // }

                                    $price = $this->service->FindPriceList($product->id, $user_integration_id, $platform_workflow_rule_id, $product_price_list_object_id);
                                    $product->infoplus_product_id = $findProduct->api_product_id; //Assing infoplus api product id as alias infoplus_product_id in product object
                                    $product->forward_lot_mixing_rule = $findProduct->forward_lot_mixing_rule;
                                    $product->storage_lot_mixing_rule = $findProduct->storage_lot_mixing_rule;
                                    $product->forward_item_mixing_rule = $findProduct->forward_item_mixing_rule;
                                    $product->storage_item_mixing_rule = $findProduct->storage_item_mixing_rule;
                                    $product->allocation_rule = $findProduct->allocation_rule;
                                    $commodityCode = $this->service->getCustomFieldMapping($product->id, $source_platform_id, $object_id, $user_integration_id);
                                    if (isset($collectCommodity[$commodityCode])) {
                                        $product->commodityCodeId = $collectCommodity[$commodityCode];
                                    } else {
                                        $product->commodityCodeId = $this->service->findCommodityCode($user_id, $user_integration_id, $commodityObjectId, $commodityCode);
                                        if (!$product->commodityCodeId) {
                                            $product->commodityCodeId = $this->service->searchCommodityCode($account, $commodityCode); //search commodity code
                                        }

                                        $collectCommodity[$product->commodityCodeId] = $product->commodityCodeId;
                                    }

                                    $apicall = $this->service->createAndUpdateProduct($account, $product, $price, $default_order_lob, "PUT", $sourceAccountExtraInformation);

                                    $response = $apicall['body'];

                                    if (!isset($response['errors']) && $apicall['status_code'] == 200) {

                                        $response['linked_id'] = $product->id; //assing linked_id

                                        $ProductPrimaryID =  $this->service->PrepareModalData($response, $user_id, $user_integration_id);/* Handle created product response and save in DB */

                                        if ($ProductPrimaryID) {
                                            if (strtolower($syncBundleProduct) == "yes") {
                                                if ($findProduct->bundle && $findProduct->api_group_id > 0) {
                                                    $this->service->UpdateKitProduct($account, $findProduct, $product, $default_order_lob, $identity, $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $object_id);
                                                } else if ($product->bundle) {
                                                    //If bundle=0 && api_group_id not set
                                                    $apicall  = $this->service->searchKitSKU($account, $findProduct->sku); //Get Kit By SKU and update kit id in api_group_id
                                                    $response = $apicall['body'];
                                                    if (!isset($response['errors']) && is_array($response) && !empty($response)) {
                                                        $this->mobj->makeUpdate('platform_product', ['bundle' => 1, 'api_group_id' => $response[0]['id']], ['id' => $findProduct->id]);
                                                        $findProduct->api_group_id = $response[0]['id']; //assign infplus kit id
                                                        $this->service->UpdateKitProduct($account, $findProduct, $product, $default_order_lob, $identity, $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $object_id);
                                                    } else {
                                                        $this->service->CreateKitProduct($account, $findProduct, $product, $default_order_lob, $identity, $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $object_id);
                                                    }
                                                } else {
                                                    /* Update BP product status */
                                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'success', $product->id, NULL);
                                                }
                                            } else {
                                                /* Update BP product status */
                                                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'success', $product->id, NULL);
                                            }
                                        }
                                    } else {

                                        $error = $this->service->handleErrorResponse($apicall);
                                        if (!$this->service->handleCustomError($error)) { //if we found this two types of errors, skip this product to failed
                                            continue;
                                        }
                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $error);
                                        $returnstatus = $error;
                                    }
                                } else {

                                    $exist = $this->service->SearchProductBySKU($product->sku, $account, $user_id, $user_integration_id, $default_order_lob); //Before Create find product by sku

                                    if (!$exist) {
                                        $price = $this->service->FindPriceList($product->id, $user_integration_id, $platform_workflow_rule_id, $product_price_list_object_id);
                                        $commodityCode = $this->service->getCustomFieldMapping($product->id, $source_platform_id, $object_id, $user_integration_id);
                                        if (isset($collectCommodity[$commodityCode])) {
                                            $product->commodityCodeId = $collectCommodity[$commodityCode];
                                        } else {
                                            $product->commodityCodeId = $this->service->findCommodityCode($user_id, $user_integration_id, $commodityObjectId, $commodityCode);
                                            if (!$product->commodityCodeId) {
                                                $product->commodityCodeId = $this->service->searchCommodityCode($account, $commodityCode); //search commodity code
                                            }
                                            $collectCommodity[$product->commodityCodeId] = $product->commodityCodeId;
                                        }


                                        $apicall = $this->service->createAndUpdateProduct($account, $product, $price, $default_order_lob, "POST", $sourceAccountExtraInformation);
                                        $response = $apicall['body'];
                                        if (!isset($response['errors'])) {
                                            $response['linked_id'] = $product->id; //assing linked_id
                                            $ProductPrimaryID =  $this->service->PrepareModalData($response, $user_id, $user_integration_id);/* Handle created product response and save in DB */

                                            if ($ProductPrimaryID) {
                                                if (strtolower($syncBundleProduct) == "yes") {
                                                    if ($product->bundle) {

                                                        $infoplusProduct = (object) [
                                                            'id' => $ProductPrimaryID,
                                                            'sku' => $product->sku,
                                                        ];
                                                        $this->service->CreateKitProduct($account, $infoplusProduct, $product, $default_order_lob, $identity, $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $object_id);
                                                    } else {
                                                        if ($product->parent_product_id != null && $product->parent_product_id != 0 && !empty($product->parent_product_id)) {
                                                            //IF parent product id found
                                                            $this->service->KitProduct($product, $default_order_lob, $account, $identity, $user_workflow_rule_id, $object_id);
                                                        }
                                                        /* Update BP product status */
                                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'success', $product->id, NULL);
                                                    }
                                                } else {
                                                    /* Update BP product status */
                                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'success', $product->id, NULL);
                                                }
                                            }
                                        } else {
                                            $error = $this->service->handleErrorResponse($apicall);
                                            if (!$this->service->handleCustomError($error)) { //if we found this two types of errors, skip this product to failed
                                                continue;
                                            }

                                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $error);
                                            $returnstatus = $error;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $returnstatus = 'Account error occured.';
                }
            } else {
                $returnstatus = 'No account found for integration.';
            }
        } catch (\Exception $e) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }
    /* Get Warehouses */
    public function GetWarehouse($userId = NULL, $userIntegrationId = NULL, $attempt = 1, $is_initial_syn = 0, $account = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                $x = true;
                $page = 1;
                $pageLimit = 250;
                $objectId = $this->helper->getObjectId("warehouse");
                if ($attempt == 1 && $is_initial_syn == 1) {
                    /* Set status=0 */
                    $this->service->SetStatus($userId, $userIntegrationId, $this->platformId, $objectId);
                    while ($x) {
                        $arguments = [
                            "limit" => $pageLimit,
                            "page" => $page
                        ];
                        $apicall = $this->infoplus->_API_CALL($account, "GET", "warehouse/search", $arguments, [], "v3.0");
                        $warehouse = $apicall['body'];

                        if (!empty($warehouse) && is_array($warehouse)) {

                            if (!isset($warehouse['errors'])) {
                                foreach ($warehouse as $key => $value) {
                                    $List = array(
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $this->platformId,
                                        'api_id' => $value['id'],
                                        'name' => $value['name'],
                                        'status' => 1,
                                        'platform_object_id' => $objectId
                                    );

                                    $warehouse = PlatformObjectData::updateOrCreate([
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' =>  $this->platformId,
                                        'api_id' => $value['id'],
                                        'platform_object_id' => $objectId
                                    ], $List);
                                    // if (isset($warehouse->id)) {
                                    //     $this->GetWarehouseLocation($userId, $userIntegrationId, $value['id'], $warehouse->id, $account);
                                    // }
                                }
                                $page++;
                                $x = true;
                            } else {
                                continue;
                            }

                            if ($page % 2 == 0) {
                                sleep(1);
                            }
                        } else {

                            $x = false;
                        }
                    }
                    $return_response =  true;
                } else if ($attempt == 1 && $is_initial_syn == 0) {
                    $arguments = [
                        "limit" => 250,
                        "page" => 1
                    ];
                    $apicall = $this->infoplus->_API_CALL($account, "GET", "warehouse/search",  $arguments, [], "v3.0");

                    $carriers = $apicall['body'];

                    if (!empty($carriers) && is_array($carriers)) {
                        if (!isset($carriers['errors'])) {
                            foreach ($carriers as $key => $value) {


                                $List = array(
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'api_id' => $value['id'],
                                    'name' => $value['name'],
                                    'status' => 1,
                                    'platform_object_id' => $objectId

                                );

                                $warehouse = PlatformObjectData::updateOrCreate([
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' =>  $this->platformId,
                                    'api_id' => $value['id'],
                                    'platform_object_id' => $objectId
                                ], $List);
                                // if (isset($warehouse->id)) {
                                //     $this->GetWarehouseLocation($userId, $userIntegrationId, $value['id'], $warehouse->id, $account);
                                // }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Warehouse Locations */
    public function GetWarehouseLocation($userId, $userIntegrationId, $warehouseId, $warehousePrimaryID, $account = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                $x = true;
                $page = 1;
                $pageLimit = 250;
                $objectId = $this->helper->getObjectId("location");
                /* Set status=0 */
                $this->service->SetStatus($userId, $userIntegrationId, $this->platformId, $objectId, $warehousePrimaryID);
                while ($x) {
                    $arguments = [
                        "limit" => $pageLimit,
                        "page" => $page,
                        "filter" => "warehouseId eq {$warehouseId}"
                    ];
                    $apicall = $this->infoplus->_API_CALL($account, "GET", "location/search", $arguments);

                    $warehouse = $apicall['body'];

                    if (!empty($warehouse) && is_array($warehouse)) {

                        if (!isset($warehouse['errors'])) {
                            foreach ($warehouse as $key => $value) {
                                $List = array(
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'api_id' => $value['id'],
                                    'name' => isset($value['address']) ? $value['address'] : $value['behaviorType'],
                                    'status' => 1,
                                    'platform_object_id' => $objectId,
                                    'parent_id' => $warehousePrimaryID

                                );

                                PlatformObjectData::updateOrCreate([
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' =>  $this->platformId,
                                    'api_id' => $value['id'],
                                    'platform_object_id' => $objectId,
                                    'parent_id' => $warehousePrimaryID
                                ], $List);
                            }
                            $page++;
                            $x = true;
                        } else {
                            continue;
                        }

                        if ($page % 2 == 0) {
                            sleep(1);
                        }
                    } else {

                        $x = false;
                    }
                }
                $return_response =  true;
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get LOB */
    public function GetLOB($userId = NULL, $userIntegrationId = NULL, $account = NULL)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                $x = true;
                $page = 1;
                $pageLimit = 250;
                $objectId = $this->helper->getObjectId("line_of_business");
                /* Set status=0 */
                $this->service->SetStatus($userId, $userIntegrationId, $this->platformId, $objectId);
                while ($x) {
                    $arguments = [
                        "limit" => $pageLimit,
                        "page" => $page
                    ];
                    $apicall = $this->infoplus->_API_CALL($account, "GET", "lineOfBusiness/search", $arguments);

                    $lob =  $apicall['body'];

                    if (!empty($lob) && is_array($lob)) {

                        if (!isset($lob['errors'])) {
                            foreach ($lob as $key => $value) {
                                $List = array(
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'api_id' => $value['id'],
                                    'name' => $value['label'],
                                    'status' => 1,
                                    'platform_object_id' => $objectId
                                );
                                PlatformObjectData::updateOrCreate([
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' =>  $this->platformId,
                                    'api_id' => $value['id'],
                                    'platform_object_id' => $objectId
                                ], $List);
                            }
                            $page++;
                            $x = true;
                        } else {
                            if (isset($apicall['body']['errors'])) {
                                $x = false;
                                $err_msg = 'Infoplus: Invalid API';

                                if (isset($apicall['body']['errors'][0])) {
                                    $err_msg = 'Infoplus: ' . $apicall['body']['errors'][0];
                                }
                                return $err_msg;
                            }
                            continue;
                        }

                        if ($page % 2 == 0) {
                            sleep(1);
                        }
                    } else {

                        $x = false;
                    }
                }
                $return_response =  true;
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Create Order In Infoplus */
    public function SyncOrder($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = NULL, $UserWorkFlowRuleID = NULL, $SorucePlatformName = NULL, $order_type = "SO",  $sync_status = "Ready",  $RecordID = NULL, $account = NULL)
    {
        $return_response = true;

        try {
            $recordExist = 0;
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
            $sourceAccount = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);

            if ($account && $sourceAccount) {
                $object_id = $field_mapping_object_id = $this->helper->getObjectId('sales_order');
                $query = PlatformOrder::with(['platformOrderAddress', 'getShipmentReadyAndFailed'])->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id',  'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status',  'is_voided',  'is_deleted', 'linked_id',  'order_updated_at', 'updated_at', 'warehouse_id', 'order_date', 'api_order_reference', 'allow_check', 'linked_api_order_id', 'shipping_method');
                if ($RecordID && $RecordID !== 0) {
                    $query->where('id', $RecordID);
                } else {
                    $query->where([
                        'platform_id' => $SourcePlatformId,
                        'user_integration_id' => $userIntegrationId,
                        'sync_status' => $sync_status,
                        'order_type' => $order_type
                    ]);
                }
                $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    $orderIds = $query->pluck('sync_status','id')->toArray(); //before sync get only order ids to set sync_status as Processing
                    if (count($orderIds)) {
                        $orderPrimaryIds=array_keys($orderIds);
                        PlatformOrder::whereIn('id', $orderPrimaryIds)->update(['sync_status' => PlatformStatus::PROCESSING,'allow_check' => 1]);
                    }

                    $recordExist = 1;
                    /* Default LOB */
                    $default_lob =  $this->map->getMappedDataByName($userIntegrationId, NULL, "default_order_line_of_business",  ['api_id'], "default");
                    if ($default_lob) {
                        $default_order_lob = $default_lob->api_id;
                    } else {
                        $default_order_lob = 0;
                    }
                    $CustomEmail = NULL;
                    $CustomE = $this->map->getMappedDataByName($userIntegrationId, NULL, "default_customer_email",  ['custom_data'], "default");
                    if ($CustomE) {
                        $CustomEmail = $CustomE->custom_data;
                    }
                    /* ---------- */
                    /* Default Warehouse */
                    // $default_WarehouseId = NULL;
                    // $default_WarehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], "default");

                    // if ($default_WarehouseId) {
                    //     $default_order_warehouse_id = $default_WarehouseId->api_id;
                    // }
                    /* --------------- */
                    $source_identity = $this->service->ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID);

                    /* Find Address Type: billing or shipping */
                    $addressType = $this->map->getMappedDataByName($userIntegrationId, NULL, "sorder_shipping_address", ['api_id']);
                    $addressType = isset($addressType->api_id) ? $addressType->api_id : "billing";
                    $customerNo = false;
                    $DefaultShippingMethodId = NULL;
                    $default_sales_order_shipping_method = $this->map->getMappedDataByName($userIntegrationId, NULL, "sorder_shipping_method", ['api_id']);
                    if ($default_sales_order_shipping_method) {
                        $DefaultShippingMethodId = $default_sales_order_shipping_method->api_id;
                    }
                    $shipping_method_object_id = $this->helper->getObjectId('shipping_method');
                    $channelListMemo = []; //channel list memo
                    foreach ($list as $value) {
                        /* Find Order Primary Key */
                        $order_primary_id = isset($value->id) ? $value->id : NULL;

                        $shipment = isset($value->getShipmentReadyAndFailed) ? $value->getShipmentReadyAndFailed : null;
                        if ($shipment) {
                            /* find or create order source in infoplus */

                            $shipmentId = $shipment->id;
                            $warehouse_id = $shipment->warehouse_id;
                            if (isset($value->is_deleted) && $value->is_deleted == 0 && $value->linked_id == 0) {
                                /*  Proceed to create order when is_deleted=0 */
                                $warehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], 'regular', $warehouse_id);

                                if (isset($warehouseId->api_id)) {
                                    $OrderWarehouseId = $warehouseId->api_id;

                                    /*----------------Start to find order shipping method----------------*/
                                    $sales_order_shipping_method = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $shipping_method_object_id, "api_id",  $value->shipping_method, ["name"]);

                                    if ($sales_order_shipping_method) {
                                        $sourceMethod = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $this->platformId, $shipping_method_object_id, "name",  $sales_order_shipping_method->name, ["api_id"]);
                                        if (isset($sourceMethod)) {
                                            $shippingMethod = $sourceMethod->api_id;
                                        } else {
                                            $shippingMethod = $DefaultShippingMethodId;
                                        }
                                    } else {
                                        $shippingMethod = $DefaultShippingMethodId;
                                    }

                                    /*----------------End to find order shipping method----------------*/
                                    /* Find Address */
                                    //$address = isset($value->platformOrderAddress) ? $value->platformOrderAddress : NULL;

                                    // if ($address) {

                                    /* Find Customer Address */
                                    // $details = $this->service->GetCustomerAddress($addressType,  $order_primary_id, "Customer");

                                    // if (is_array($details) && !empty($details)) {
                                    $customerNo = $this->service->FindCustomerOrVentorByEmail("Customer", $value->platform_customer_id, $userId, $userIntegrationId, $CustomEmail, $account);
                                    //}
                                    // }

                                    if (is_array($customerNo)) { //if customer creation failed
                                        $return_response = $customerNo == false || !isset($customerNo[0]) ? "Can not create customer" : $customerNo[0];
                                        if ($this->service->handleCustomError($return_response)) {
                                            $this->updatePlatformOrder(['id'=>$value->id],[
                                                'sync_status'=>PlatformStatus::FAILED,
                                                'order_updated_at' => date("Y-m-d H:i:s")
                                            ]);
                                            /* Shipment Table sync status updated */
                                            PlatformOrderShipment::where('platform_order_id',  $order_primary_id)->update(['sync_status' => "Failed"]);

                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else if ($order_primary_id) {
                                        $address = isset($value->platformOrderAddress) ? $value->platformOrderAddress : NULL;
                                        if ($address) {

                                            $orderLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $shipmentId)->groupBy('product_id')
                                                ->selectRaw('*, sum(quantity) as sum')->get(); //isset($value->platformShippingLines) ? $value->platformShippingLines : NULL;
                                            $address = $this->service->GetAddress($address);
                                            $cutomerName = $this->service->findCustomerName($value->platform_customer_id);
                                            if ($cutomerName) {
                                                $address['shipTo']['shipToAttention'] = $cutomerName;
                                                $address['billTo']['billToAttention'] = $cutomerName;
                                            }
                                            if ($orderLines) {
                                                $orderLines = $this->service->PrepareOrderLine("SO", $orderLines, $userId, $userIntegrationId,  $SourcePlatformId, $source_identity['source_identity'], $default_order_lob);

                                                if (count($orderLines['items']) > 0) {
                                                    if ($orderLines['productNotFound']) {
                                                        /* Source Order sync status updated with linked id  */
                                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                                            'sync_status'=>PlatformStatus::FAILED,
                                                            'order_updated_at' => date("Y-m-d H:i:s")
                                                        ]);
                                                        /* Shipment Table sync status updated */
                                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => PlatformStatus::FAILED, 'updated_at' => date('Y-m-d H:i:s')]);
                                                        $return_response = "One or more line item(s) are not matched with infoplus product";
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                    } else {
                                                        $orderSource = $this->service->findOrCreateOrderSource($channelListMemo, $value, $account, $default_order_lob);
                                                        $post = [
                                                            "lobId" => $default_order_lob,
                                                            "warehouseId" => $OrderWarehouseId,
                                                            "orderDate" => date('c', strtotime($value->order_date)),
                                                            "customerPONo" => $value->api_order_reference,
                                                            "customerOrderNo" => $value->api_order_id . "-" . $shipment->shipment_sequence_number,
                                                            "mediaCode" => "Electronic",
                                                            "legacyRestrictionType" => "Interim",
                                                            "shipCode" => "Ready to ship",
                                                            "shippingCharge" => 0,
                                                            "totalDiscount" => 0,
                                                            "lineItems" => $orderLines['items']
                                                        ];
                                                        if ($shippingMethod) {
                                                            $post["carrierId"] = $shippingMethod;
                                                        }
                                                        if (!is_bool($customerNo)) { //if customerNo is not a bool value
                                                            $post["customerNo"] = $customerNo;
                                                        }
                                                        /* if order source id is numeric assing in payload otherwise it will absolutely error from api */
                                                        $sendPaylod = true;
                                                        if (is_numeric($orderSource) || is_null($orderSource)) {
                                                            $post["orderSourceId"] = $orderSource;
                                                        } else {
                                                            $sendPaylod = false;
                                                        }
                                                        if ($sendPaylod) {
                                                            /* Field Mapping */
                                                            $field_mapping = $this->map->GetMappedFieldRecord($field_mapping_object_id, $userIntegrationId, NULL, "source_row_id", NULL, $value->id);
                                                            if ($field_mapping) {
                                                                foreach ($field_mapping as $mapping) {
                                                                    $casting = $value->toArray();
                                                                    if (isset($mapping['destination_field_name'])) { //This will add all mapping values to infoplus api
                                                                        $post[$mapping['destination_field_name']] = $mapping['source_custom_field_value']; // Here added orderMessage && giftMessage field when we have mappings
                                                                    }
                                                                    if (isset($casting[$mapping['source_db_field_name']])) {
                                                                        $post[$mapping['destination_field_name']] = $casting[$mapping['source_db_field_name']];
                                                                    }
                                                                }
                                                            }
                                                            $payload = array_merge($post, $address['billTo'], $address['shipTo']);
                                                            $createOrder = true;
                                                            $allowCheckOrderNumber=false;
                                                            if ($value->allow_check) { //when deadlock found set allow_check=1 to resync order
                                                                $order_response = $apicall = [];
                                                                if ($value->linked_api_order_id) {   //when already order created
                                                                    $createOrder = false;
                                                                    $order_response['orderNo'] = (int) $value->linked_api_order_id;
                                                                    $allowCheckOrderNumber=true;
                                                                } else {
                                                                    //search order by customerOrderNo  in infoplus
                                                                    $searchResult = $this->service->SearchOrderByCustomerOrderNo($value->api_order_id . "-" . $shipment->shipment_sequence_number, $userIntegrationId, $account);
                                                                    if (isset($searchResult['api_error']) && $searchResult['api_error']) {
                                                                        $createOrder = false;
                                                                        $apicall['body']['errors'] = [$searchResult['error']];
                                                                    } else if (isset($searchResult['custom_error']) && $searchResult['custom_error']) {
                                                                        $createOrder = true;
                                                                        $apicall['body']['errors'] = [$searchResult['error']];
                                                                    } else if (isset($searchResult['exception_error']) && $searchResult['exception_error']) {
                                                                        $createOrder = false;
                                                                        $apicall['body']['errors'] = [$searchResult['error']];
                                                                    } else if (isset($searchResult['order_id']) && is_int($searchResult['order_id'])) {
                                                                        $createOrder = false;
                                                                        $allowCheckOrderNumber=true;
                                                                        $order_response['orderNo'] = $searchResult['order_id'];
                                                                    }

                                                                }
                                                                /* Add below createDate every time */
                                                                $order_response['createDate'] = date("Y-m-d H:i:s", strtotime($value->order_date));
                                                            }


                                                            if ($createOrder) {
                                                                $apicall = $this->infoplus->_API_CALL($account, "POST", "order", [], $payload, "v3.0");
                                                                $order_response = $apicall['body'];
                                                            }

                                                            if (!empty($order_response) && is_array($order_response)) {
                                                                if (isset($order_response['orderNo'])) {
                                                                    if (!isset($post['customerNo']) && $createOrder) {
                                                                        /* Id payload don't have customerNo then store customer detail in DB*/
                                                                        $this->service->saveCustomerDetails($userId, $userIntegrationId, $this->platformId, $order_response, "Customer");
                                                                    }
                                                                    /* Insert infoplus order details  */
                                                                    if($allowCheckOrderNumber){
                                                                        $OrderLinked=PlatformOrder::where([ 'user_id' => $userId,
                                                                        'platform_id' => $this->platformId,
                                                                        'user_integration_id' => $userIntegrationId,
                                                                        'order_type' => "SO",
                                                                        'api_order_id' => (string)$order_response['orderNo']])->first();
                                                                        if(!$OrderLinked){
                                                                            $OrderLinked = $this->service->SaveOrderDetails([
                                                                                'user_id' => $userId,
                                                                                'platform_id' => $this->platformId,
                                                                                'user_integration_id' => $userIntegrationId,
                                                                                'order_type' => "SO",
                                                                                'api_order_id' => $order_response['orderNo'],
                                                                                'order_date' => date("Y-m-d H:i:s", strtotime($order_response['createDate'])),
                                                                                'order_number' => $order_response['orderNo'],
                                                                                'sync_status' => 'Pending',
                                                                                'linked_id' =>  $order_primary_id,
                                                                                'shipment_status' => "Pending",
                                                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                                                            ]);
                                                                        }
                                                                    }else{

                                                                        $OrderLinked = $this->service->SaveOrderDetails([
                                                                            'user_id' => $userId,
                                                                            'platform_id' => $this->platformId,
                                                                            'user_integration_id' => $userIntegrationId,
                                                                            'order_type' => "SO",
                                                                            'api_order_id' => $order_response['orderNo'],
                                                                            'order_date' => date("Y-m-d H:i:s", strtotime($order_response['createDate'])),
                                                                            'order_number' => $order_response['orderNo'],
                                                                            'sync_status' => 'Pending',
                                                                            'linked_id' =>  $order_primary_id,
                                                                            'shipment_status' => "Pending",
                                                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                                                        ]);

                                                                    }
                                                                    $syncLog = 'success';
                                                                    $error = null;
                                                                    if ($OrderLinked) {
                                                                        /* Source Order sync status updated with linked id  */
                                                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                                                            'sync_status'=>PlatformStatus::SYNCED,
                                                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                                                            'allow_check' => 1,
                                                                            'linked_api_order_id' => $order_response['orderNo'],
                                                                            'linked_id' => $OrderLinked,
                                                                        ]);
                                                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Synced"]);
                                                                    } else {
                                                                        /* Source Order sync status updated with linked id  */

                                                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                                                            'sync_status'=>PlatformStatus::FAILED,
                                                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                                                            'allow_check' => 1,
                                                                            'linked_api_order_id' => $order_response['orderNo'],
                                                                            'linked_id' => $OrderLinked,
                                                                        ]);
                                                                        $error = $return_response = "Order failed due to concurrent sync, please retry.";
                                                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                                        $syncLog = 'failed';
                                                                    }
                                                                    /* Shipment Table sync status updated */
                                                                    if ($createOrder) {
                                                                        $updateResponse = $this->service->updateOrder($account, $order_response, $address['shipTo']);
                                                                        if (!is_bool($updateResponse)) {

                                                                            $customError = $this->service->handleCustomError($updateResponse, "custom", "REQ MODIFY REQUEST ON PROCESSED ORDER");
                                                                            if (!$customError) {
                                                                                $sync_status = PlatformStatus::SYNCED;

                                                                                $syncLog = 'success';
                                                                            } else {
                                                                                $sync_status = PlatformStatus::FAILED;

                                                                                $syncLog = 'failed';
                                                                            }
                                                                            $this->updatePlatformOrder(['id'=>$value->id],[
                                                                                'sync_status'=>$sync_status,
                                                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                                                            ]);
                                                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                                                            if (empty($updateResponse)) {
                                                                                $updateResponse = "Failed to update shipTo Address";
                                                                            }
                                                                            $return_response = $error = $updateResponse;
                                                                        } else {
                                                                            if ($syncLog != 'failed') {
                                                                                $error = null;
                                                                            }
                                                                        }
                                                                    }

                                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, $syncLog, $order_primary_id, $error);
                                                                } else {
                                                                    /* Source Order sync status updated with linked id  */
                                                                    $return_response = $this->service->handleErrorResponse($apicall);
                                                                    if ($this->service->handleCustomError($return_response)) {
                                                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                                                            'sync_status'=>PlatformStatus::FAILED,
                                                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                                                        ]);
                                                                        /* Shipment Table sync status updated */
                                                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                                        $return_response = $this->service->handleErrorResponse($apicall);
                                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id,  $return_response);
                                                                    }
                                                                }
                                                            } else {
                                                                /* Source Order sync status updated with linked id  */
                                                                $return_response = $this->service->handleErrorResponse($apicall);
                                                                if ($this->service->handleCustomError($return_response)) {
                                                                    if(isset($orderIds[$value->id])){
                                                                        $sync_status=$orderIds[$value->id];
                                                                    }else{
                                                                        $sync_status=PlatformStatus::FAILED;
                                                                    }
                                                                    $this->updatePlatformOrder(['id'=>$value->id],[
                                                                        'sync_status'=>$sync_status,
                                                                        'order_updated_at' => date("Y-m-d H:i:s"),
                                                                    ]);
                                                                    /* Shipment Table sync status updated */
                                                                    PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                                                    if($sync_status==PlatformStatus::FAILED){
                                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                                    }

                                                                }else{
                                                                    if(isset($orderIds[$value->id])){
                                                                        $sync_status=$orderIds[$value->id];
                                                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                                                            'sync_status'=>$sync_status,
                                                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                                                        ]);
                                                                        /* Shipment Table sync status updated */
                                                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                                                        if($sync_status==PlatformStatus::FAILED){
                                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                                        }
                                                                    }

                                                                }
                                                                $return_response = isset($return_response) ? $return_response : "API Error";

                                                            }
                                                        } else {
                                                            /* Source Order sync status updated with linked id  */
                                                            $return_response = "No order source id found for Infoplus";
                                                            $this->updatePlatformOrder(['id'=>$value->id],[
                                                                'sync_status'=>PlatformStatus::FAILED,
                                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                                            ]);
                                                            /* Shipment Table sync status updated */
                                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                            $return_response = isset($return_response) ? $return_response : "API Error:Invalid Json";
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                        }
                                                    }
                                                } else {
                                                    /* Source Order sync status updated with linked id  */
                                                    $this->updatePlatformOrder(['id'=>$value->id],[
                                                        'sync_status'=>PlatformStatus::FAILED,
                                                        'order_updated_at' => date("Y-m-d H:i:s"),
                                                    ]);
                                                    /* Shipment Table sync status updated */
                                                    PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                    $return_response = "Products are not found";
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                }
                                            } else {
                                                /* If no shipment lines found  */
                                                $this->updatePlatformOrder(['id'=>$value->id],[
                                                    'sync_status'=>PlatformStatus::FAILED,
                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                ]);
                                                /* Shipment Table sync status updated */
                                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                                $return_response = "Products are not found";
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                            }
                                        } else {
                                            /* If no shipment address found  */
                                            $this->updatePlatformOrder(['id'=>$value->id],[
                                                'sync_status'=>PlatformStatus::FAILED,
                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                            ]);
                                            /* Shipment Table sync status updated */
                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                            $return_response = "Customer addresses are invalid or not in a proper way";
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else if ($order_primary_id && $value->sync_status == "Synced") {
                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Inactive"]);
                                    } else {

                                        $return_response = "Order detail has been not found";
                                    }
                                } else {
                                    /* when warehouse mapping not found */
                                    $this->updatePlatformOrder(['id'=>$value->id],[
                                        'sync_status'=>PlatformStatus::IGNORE,
                                        'order_updated_at' => date("Y-m-d H:i:s"),
                                    ]);
                                    /* Shipment Table sync status updated */
                                    PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Ignore"]);
                                    $return_response = "No warehouse mapping found";
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                }
                            } else if (isset($value->is_deleted) && $value->is_deleted == 1 && $value->linked_id) {

                                /* Proceed to delete in infoplus */
                                $destination_order_id = PlatformOrder::select('id', 'api_order_id', 'is_voided', 'order_updated_at')->find($value->linked_id);
                                if ($destination_order_id) {
                                    $apicall = $this->infoplus->_API_CALL($account, "DELETE", "order/{$destination_order_id->api_order_id}", [], [], "v3.0");

                                    $order_response = $apicall['status_code'];

                                    if (!empty($apicall) && is_array($apicall)) {
                                        if (isset($apicall['status_code']) && $apicall['status_code'] == 204) {
                                            /* Destination Order sync status updated  */
                                            $this->updatePlatformOrder(['id'=>$destination_order_id->id],[
                                                'is_voided'=>1,
                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                            ]);
                                            /* Source Order sync status updated with linked id  */
                                            $this->updatePlatformOrder(['id'=>$value->id],[
                                                'sync_status'=>PlatformStatus::SYNCED,
                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                            ]);
                                            /* Shipment Table sync status updated */
                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Synced"]);
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                        } else {
                                            $return_response = $this->service->handleErrorResponse($apicall);
                                            if ($this->service->handleCustomError($return_response)) {
                                                /* Source Order sync status updated with linked id  */
                                                if(isset($orderIds[$value->id])){
                                                    $sync_status=$orderIds[$value->id];
                                                }else{
                                                    $sync_status=PlatformStatus::FAILED;
                                                }
                                                $this->updatePlatformOrder(['id'=>$value->id],[
                                                    'sync_status'=>$sync_status,
                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                ]);
                                                /* Shipment Table sync status updated */
                                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                                if($sync_status==PlatformStatus::FAILED){
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                }

                                            }else{
                                                if(isset($orderIds[$value->id])){
                                                    $sync_status=$orderIds[$value->id];
                                                    $this->updatePlatformOrder(['id'=>$value->id],[
                                                        'sync_status'=>$sync_status,
                                                        'order_updated_at' => date("Y-m-d H:i:s"),
                                                    ]);
                                                    /* Shipment Table sync status updated */
                                                    PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                                    if($sync_status==PlatformStatus::FAILED){
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                    }
                                                }
                                            }

                                            $return_response = isset($return_response) ? $return_response : "API Error";



                                        }
                                    } else {
                                        $return_response = $this->service->handleErrorResponse($apicall);
                                        if ($this->service->handleCustomError($return_response)) {
                                            /* Source Order sync status updated with linked id  */
                                            if(isset($orderIds[$value->id])){
                                                $sync_status=$orderIds[$value->id];
                                            }else{
                                                $sync_status=PlatformStatus::FAILED;
                                            }
                                            $this->updatePlatformOrder(['id'=>$value->id],[
                                                'sync_status'=>$sync_status,
                                                'order_updated_at' => date("Y-m-d H:i:s"),
                                            ]);
                                            /* Shipment Table sync status updated */
                                            PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                            if($sync_status==PlatformStatus::FAILED){
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                            }
                                        }else{
                                            if(isset($orderIds[$value->id])){
                                                $sync_status=$orderIds[$value->id];
                                                $this->updatePlatformOrder(['id'=>$value->id],[
                                                    'sync_status'=>$sync_status,
                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                ]);
                                                /* Shipment Table sync status updated */
                                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                                if($sync_status==PlatformStatus::FAILED){
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                }
                                            }
                                        }
                                        $return_response = isset($return_response) ? $return_response : "API Error";

                                    }
                                }
                            } else if (isset($value->is_deleted) && $value->is_deleted == 1 && $value->linked_id == 0) {
                                /*  Proceed to failed order when is_deleted=1 and linked_id=0 */
                                $this->updatePlatformOrder(['id'=>$value->id],[
                                    'sync_status'=>PlatformStatus::FAILED,
                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                ]);
                                $return_response = "Order related data deleted in source platform.";
                                /* Shipment Table sync status updated */
                                PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Failed"]);
                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                            } else if (isset($value->is_deleted) && $value->is_deleted == 0 && $value->linked_id) {
                                $orderDetail = PlatformOrder::find($value->linked_id);
                                if ($orderDetail) {
                                    $address = isset($value->platformOrderAddress) ? $value->platformOrderAddress : NULL;
                                    $address = $this->service->GetAddress($address);
                                    $sales_order_shipping_method = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $shipping_method_object_id, "api_id",  $value->shipping_method, ["name"]); //Start to find order shipping method

                                    if ($sales_order_shipping_method) {
                                        $sourceMethod = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $this->platformId, $shipping_method_object_id, "name",  $sales_order_shipping_method->name, ["api_id"]);
                                        if (isset($sourceMethod)) {
                                            $shippingMethod = $sourceMethod->api_id;
                                        } else {
                                            $shippingMethod = $DefaultShippingMethodId;
                                        }
                                    } else {
                                        $shippingMethod = $DefaultShippingMethodId;
                                    }
                                    $syncLog = 'success';
                                    $order_response = [];
                                    $order_response['orderNo'] = $orderDetail->api_order_id;
                                    $order_response['carrierId'] =  $shippingMethod;
                                    $updateResponse = $this->service->updateOrder($account, $order_response, $address['shipTo']);
                                    if (!is_bool($updateResponse)) {
                                        $customError = $this->service->handleCustomError($updateResponse, "custom", "REQ MODIFY REQUEST ON PROCESSED ORDER");
                                        if (!$customError) {
                                            $sync_status = PlatformStatus::SYNCED;

                                            $syncLog = 'synced';
                                        } else {
                                            $sync_status = PlatformStatus::FAILED;

                                            $syncLog = 'failed';
                                        }
                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                            'sync_status'=>$sync_status,
                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                        ]);
                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => $sync_status]);
                                        if (empty($updateResponse)) {
                                            $updateResponse = "Failed to update shipTo Address";
                                        }
                                        $return_response = $updateResponse;
                                    } else {
                                        PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => "Synced"]);
                                        $this->updatePlatformOrder(['id'=>$value->id],[
                                            'sync_status'=>PlatformStatus::SYNCED,
                                            'order_updated_at' => date("Y-m-d H:i:s"),
                                        ]);
                                        $updateResponse = null;
                                    }

                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, $syncLog, $order_primary_id, $updateResponse);
                                }
                            }
                        } else {
                            $this->updatePlatformOrder(['id'=>$value->id],[
                                'sync_status'=>PlatformStatus::FAILED,
                                'order_updated_at' => date("Y-m-d H:i:s"),
                            ]);
                            $return_response = "No shipment details found for order";
                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id,  $return_response);
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
    public function updatePlatformOrder($where,$update){
        PlatformOrder::where($where)->update($update);
    }
    /* Get Transfer Order Item Receipt | Once all items are received set DB sync status ready */
    public function GetTransferOrderItemReceived($userId = NULL, $userIntegrationId = NULL, $UserWorkFlowRuleID = NULL, $order_type = "Transfer", $sync_status = "Pending",  $RecordID = NULL, $account = NULL)
    {
        $return_response = true;

        try {

            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }

            if ($account) {
                $object_id = $this->helper->getObjectId('transfer_order');

                $query = PlatformOrderShipment::select('linked_id', 'id', 'shipment_id', 'sync_status', 'updated_at');
                if ($RecordID && $RecordID !== 0) {

                    $query->where('id', $RecordID);
                } else {

                    $query->where([
                        [
                            'platform_id', '=', $this->platformId
                        ],
                        [
                            'user_integration_id', '=', $userIntegrationId
                        ],
                        [
                            'sync_status', '=', $sync_status
                        ],
                        [
                            'type', '=', $order_type
                        ]

                    ]);
                }

                $order_list = $query->orderBy('updated_at', 'ASC')->orderBy('created_at', 'DESC')->take($limit)->get();

                if (!empty($order_list) && count($order_list) > 0) {
                    foreach ($order_list as $order) {

                        /* Check sync_status is pending */
                        if (isset($order->linked_id)) {
                            /* Here we actually checking the ASN no againts the Transfer Order */
                            $asNo = $order->shipment_id . '-T';
                            $arguments = [
                                "limit" => 1,
                                "page" => 1,
                                "filter" => "poNo eq '{$asNo}'"
                            ];
                            $apicall = $this->infoplus->_API_CALL($account, "GET", "asn/search", $arguments);
                            $order_response = $apicall['body'];

                            if (!empty($order_response) && is_array($order_response)) {

                                if (isset($order_response[0])) {
                                    $order_response = $order_response[0];
                                    if ($order_response['status'] == "Cancelled" && isset($order_response['status'])) {
                                        /* If order cancel | Set Failed */
                                        $order->sync_status = 'Failed';
                                        $order->save();
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $this->platformId, $this->platformId, $object_id, 'failed', $order->id, "Order has been cancelled from infoplus");
                                        continue;
                                    } else if ($order_response['status'] == "Received" && isset($order_response['status'])) {

                                        $order->sync_status = "Ready";
                                        $order->save();
                                    }
                                } else {
                                    //If order has not found
                                    $order->sync_status = "Failed";
                                    $return_response = $this->service->handleErrorResponse($apicall);
                                }
                            }
                        }

                        $order->updated_at = date('Y-m-d H:i:s');
                        $order->save();
                    }
                }
                $return_response = true;
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* This method is basically used to check sales order status if all the items were synced, capture the status and ready to send for next platform */
    public function CheckOrderStatus($userId = NULL, $userIntegrationId = NULL, $UserWorkFlowRuleID = NULL, $destinationPlaformName = null, $is_initial_sync)
    {

        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = true;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account && $this->platformId) {
                if (!$is_initial_sync) {
                    $destinationPlaformId = $this->helper->getPlatformIdByName($destinationPlaformName);
                    $object_id = $this->helper->getObjectId('sales_order');
                    $orderUrlName = 'order_status_last_modified';
                    $orderUrl = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', $orderUrlName]])->first();
                    $startingDate =   null;
                    $endDate = Carbon::now(); 
                    $page = 1;
                    if (isset($orderUrl->url)) {
                         $startingDate = Carbon::parse($orderUrl->url); 
                         /* If Order last time found */
                         $dates = $this->service->UrlDate(trim($orderUrl->url), "|");
                         if (is_array($dates)) {
                             //Making Date Range
                            $startingDate = Carbon::parse($dates[0]);
                            $endDate = Carbon::parse($dates[1]);
                            $page = isset($dates[2])?$dates[2]:1;
                           
                         } else {
                            if (is_null($dates) && !empty($dates) && Carbon::now()->format('Y-m-d') > Carbon::parse($dates)->format('Y-m-d')) { //if current time is greater than equal to old datetime
                                $startingDate = Carbon::parse($dates);
                            }
                         }
                    } else {
                        $query = PlatformOrder::select('order_date')->where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $userIntegrationId,
                            'shipment_status' => "Pending",
                            'order_type' => "SO",
                            'is_deleted' => 0
                        ])->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') DESC")->first();
                        if ($query) {
                            $startingDate =   Carbon::parse($query->order_date);
                        }
                    }


                    if ($startingDate) {
                        $diffInDays = $startingDate->diffInDays($endDate);
                        $startDate =   $startingDate->format('Y-m-d\TH:i:s\Z');
                        if ($diffInDays > 3) { // if date range difference is greater than 3 days add 3 days in ending date to avoid timeout in api.
                            $endDate =   $startingDate->addDays(3)->format('Y-m-d\TH:i:s\Z');
                        } else {
                            $endDate =   $endDate->format('Y-m-d\TH:i:s\Z');
                        }
                        
                        $urlValue = $startDate . "|" . $endDate ."|". $page;
                        $pageLimit = 25;
                        $arguments = [
                            "limit" => $pageLimit,
                            "page" => $page,
                            "filter" => 'status in ("Cancelled","Shipped") AND modifyDate gte "' . $startDate . '" AND modifyDate lte "' . $endDate . '"',
                            "sort" =>  "modifyDate"
                        ];
                        $apicall = $this->infoplus->_API_CALL($account, "GET", "order/search", $arguments, [], "v3.0");
                        \Storage::append('Infoplus/' . $userIntegrationId . '/CheckOrderStatus/' . date('d-m-Y') . '.txt', " Date: " . date('d-m-Y H:i:s') . " StartDate: " . $startDate . " - EndDate: " . $endDate);


                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $order_responses = $apicall['body'];
                           
                            if (!empty($order_responses) || count($order_responses) > 0) {
                                if (!isset($order_responses['errors'])) {
                                    $page++;
                                    foreach ($order_responses as $key => $order_response) {
                                        $startDate = $order_response['modifyDate'];
                                        $urlValue = $startDate . "|" . $endDate ."|". $page;
                                        $orderNo=(string)((int)$order_response['orderNo']);
                                        $order = PlatformOrder::where([
                                            'platform_id' => $this->platformId,
                                            'user_integration_id' => $userIntegrationId,
                                            'shipment_status' => PlatformStatus::PENDING,
                                            'order_type' => "SO",
                                            'api_order_id' => (string)$orderNo
                                        ])->first();
                                        if($order){
                                            if (isset($order->linked_id) && $order->linked_id) {
                                                $shipment = PlatformOrderShipment::select('linked_id', 'id')->where('platform_order_id', $order->linked_id)->where('sync_status', 'Synced')->orderBy('shipment_id', 'asc')->first();

                                                if ($shipment) {
                                                    if (isset($order_response['orderNo'])) {
                                                        if ($order_response['status'] == "Cancelled" && isset($order_response['status'])) {
                                                            /* If order cancel | Set Failed */
                                                            $order->shipment_status = PlatformStatus::IGNORE;
                                                            $order->save();
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $this->platformId, $destinationPlaformId, $object_id, 'failed', $order->id, "Order has been cancelled from infoplus");
                                                        } else if ($order_response['status'] == "Shipped" && isset($order_response['status'])) {
                                                            /* Get Tracking Info */
                                                            $trackinInfo = $shippingMethod = $shipDate = NULL;
                                                            $arguments = [
                                                                "limit" => 1,
                                                                "filter" =>  "orderNo eq '" . $order->api_order_id . "'"
                                                            ];
                                                            $apicall_for_tracking = $this->infoplus->_API_CALL($account, "GET", "shipment/search/", $arguments, [], "v3.0");
                                                            $response_for_tracking = $apicall_for_tracking['body'];

                                                            if (!empty($response_for_tracking) && is_array($response_for_tracking)) {
                                                                if (!isset($response_for_tracking['errors'])) {
                                                                    $trackinInfo = isset($response_for_tracking[0]['trackingNo']) ? $response_for_tracking[0]['trackingNo'] : NULL;
                                                                    $shipDate = isset($response_for_tracking[0]['shipDate']) ? $response_for_tracking[0]['shipDate'] : NULL;
                                                                    $shippingMethod = isset($response_for_tracking[0]['carrierCompany']) ? $response_for_tracking[0]['carrierCompany'] : NULL;

                                                                    if (isset($shipment->linked_id) && $shipment->linked_id > 0) {
                                                                        $newshipment = PlatformOrderShipment::find($shipment->linked_id);
                                                                        $newshipment->sync_status = PlatformStatus::READY;
                                                                        $newshipment->shipping_method = $shippingMethod;
                                                                        $newshipment->tracking_info = $trackinInfo;
                                                                        $newshipment->realease_date = $shipDate;
                                                                        $newshipment->save();
                                                                        // ->update([
                                                                        //     'sync_status' => "Ready",
                                                                        //     'shipping_method' => $shippingMethod,
                                                                        //     'tracking_info' => $trackinInfo,
                                                                        //     'realease_date' => $shipDate,
                                                                        // ]);
                                                                    } else {
                                                                        $newshipment = PlatformOrderShipment::create([
                                                                            'user_id' => $userId,
                                                                            'platform_id' => $this->platformId,
                                                                            'user_integration_id' =>  $userIntegrationId,
                                                                            'sync_status' => PlatformStatus::READY,
                                                                            'shipping_method' => $shippingMethod,
                                                                            'tracking_info' => $trackinInfo,
                                                                            'realease_date' => $shipDate,
                                                                            'platform_order_id' => $order->id,
                                                                            'order_id' => $order->api_order_id,
                                                                            'linked_id' => $shipment->id
                                                                        ]);
                                                                        $shipment->linked_id = $newshipment->id;
                                                                        $shipment->save();
                                                                        $order->shipment_status = PlatformStatus::READY;
                                                                        $order->save();
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        //If order has not found

                                                        $return_response = $this->service->handleErrorResponse($apicall);

                                                        $mystring = "No order found with orderNo";
                                                        // Test if string contains the word
                                                        if (strpos($mystring, $return_response) !== false) {
                                                            $order->shipment_status = PlatformStatus::IGNORE;
                                                        } else {
                                                            $order->shipment_status = PlatformStatus::FAILED;
                                                        }
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $this->platformId, $destinationPlaformId, $object_id, 'failed', $order->id, $return_response);
                                                    }
                                                }
                                            } else {
                                                $order->shipment_status = PlatformStatus::IGNORE;
                                                $order->save();
                                            }
                                        }

                                    }
                                    if (isset($orderUrl)) {
                                        if ($orderUrl->url != $urlValue) {
                                            $orderUrl->url = $urlValue;
                                            $orderUrl->save();
                                        }
                                       
                                    } else {
                                        PlatformUrl::insert([
                                            'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                            'url' => $urlValue,
                                            'url_name' => $orderUrlName,
                                            'status' => 0
                                        ]);
                                    }
                                }
                            } else {
                                if (isset($orderUrl)) {
                                    if ($orderUrl->url != $urlValue) {
                                        $orderUrl->url = $urlValue;
                                        $orderUrl->save();
                                    }
                                } else {
                                    PlatformUrl::insert([
                                        'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                        'url' => $urlValue,
                                        'url_name' => $orderUrlName,
                                        'status' => 0
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    public function CheckOrderStatusBackup($userId = NULL, $userIntegrationId = NULL, $UserWorkFlowRuleID = NULL, $destinationPlaformName = null, $is_initial_sync)
    {

        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = true;
        try {
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account && $this->platformId) {
                if (!$is_initial_sync) {
                    $destinationPlaformId = $this->helper->getPlatformIdByName($destinationPlaformName);
                    $object_id = $this->helper->getObjectId('sales_order');
                    $orderUrlName = 'order_status_last_modified';
                    $orderUrl = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', $orderUrlName]])->first();
                    $startingDate =   null;
                    if (isset($orderUrl->url)) {
                        $startingDate = Carbon::parse($orderUrl->url); //->subSeconds(2)->format('Y-m-d\TH:i:s\Z');
                    } else {
                        $query = PlatformOrder::select('order_date')->where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $userIntegrationId,
                            'shipment_status' => "Pending",
                            'order_type' => "SO",
                            'is_deleted' => 0
                        ])->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') DESC")->first();
                        if ($query) {
                            $startingDate =   Carbon::parse($query->order_date); //->subSecond()->format('Y-m-d\TH:i:s\Z');
                        }
                    }


                    if ($startingDate) {
                        $endDate = Carbon::now(); //->format('Y-m-d\TH:i:s\Z');
                        $diffInDays = $startingDate->diffInDays($endDate);
                        $startDate =   $startingDate->format('Y-m-d\TH:i:s\Z'); //->subSeconds(2)
                        if ($diffInDays > 3) { // if date range difference is greater than 3 days add 3 days in ending date to avoid timeout in api.
                            $endDate =   $startingDate->addDays(3)->format('Y-m-d\TH:i:s\Z');
                        } else {
                            $endDate =   $endDate->format('Y-m-d\TH:i:s\Z');
                        }

                        $page = 1;
                        $pageLimit = 25;
                        $arguments = [
                            "limit" => $pageLimit,
                            "page" => $page,
                            "filter" => 'status in ("Cancelled","Shipped") AND modifyDate gte "' . $startDate . '" AND modifyDate lte "' . $endDate . '"',
                            "sort" =>  "modifyDate"
                        ];
                        $apicall = $this->infoplus->_API_CALL($account, "GET", "order/search", $arguments, [], "v3.0");


                        \Storage::append('Infoplus/' . $userIntegrationId . '/CheckOrderStatus/' . date('d-m-Y') . '.txt', " Date: " . date('d-m-Y H:i:s') . " StartDate: " . $startDate . " - EndDate: " . $endDate);


                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $order_responses = $apicall['body'];
                            if (!empty($order_responses) || count($order_responses) > 0) {
                                if (!isset($order_responses['errors'])) {
                                    foreach ($order_responses as $key => $order_response) {
                                        $startDate = $order_response['modifyDate'];
                                        $orderNo=(string)((int)$order_response['orderNo']);
                                        $order = PlatformOrder::where([
                                            'platform_id' => $this->platformId,
                                            'user_integration_id' => $userIntegrationId,
                                            'shipment_status' => PlatformStatus::PENDING,
                                            'order_type' => "SO",
                                            'api_order_id' => (string)$orderNo
                                        ])->first();
                                        if($order){
                                            if (isset($order->linked_id) && $order->linked_id) {
                                                $shipment = PlatformOrderShipment::select('linked_id', 'id')->where('platform_order_id', $order->linked_id)->where('sync_status', 'Synced')->orderBy('shipment_id', 'asc')->first();

                                                if ($shipment) {
                                                    if (isset($order_response['orderNo'])) {
                                                        if ($order_response['status'] == "Cancelled" && isset($order_response['status'])) {
                                                            /* If order cancel | Set Failed */
                                                            $order->shipment_status = PlatformStatus::IGNORE;
                                                            $order->save();
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $this->platformId, $destinationPlaformId, $object_id, 'failed', $order->id, "Order has been cancelled from infoplus");
                                                        } else if ($order_response['status'] == "Shipped" && isset($order_response['status'])) {
                                                            /* Get Tracking Info */
                                                            $trackinInfo = $shippingMethod = $shipDate = NULL;
                                                            $arguments = [
                                                                "limit" => 1,
                                                                "filter" =>  "orderNo eq '" . $order->api_order_id . "'"
                                                            ];
                                                            $apicall_for_tracking = $this->infoplus->_API_CALL($account, "GET", "shipment/search/", $arguments, [], "v3.0");
                                                            $response_for_tracking = $apicall_for_tracking['body'];

                                                            if (!empty($response_for_tracking) && is_array($response_for_tracking)) {
                                                                if (!isset($response_for_tracking['errors'])) {
                                                                    $trackinInfo = isset($response_for_tracking[0]['trackingNo']) ? $response_for_tracking[0]['trackingNo'] : NULL;
                                                                    $shipDate = isset($response_for_tracking[0]['shipDate']) ? $response_for_tracking[0]['shipDate'] : NULL;
                                                                    $shippingMethod = isset($response_for_tracking[0]['carrierCompany']) ? $response_for_tracking[0]['carrierCompany'] : NULL;

                                                                    if (isset($shipment->linked_id) && $shipment->linked_id > 0) {
                                                                        $newshipment = PlatformOrderShipment::find($shipment->linked_id);
                                                                        $newshipment->sync_status = PlatformStatus::READY;
                                                                        $newshipment->shipping_method = $shippingMethod;
                                                                        $newshipment->tracking_info = $trackinInfo;
                                                                        $newshipment->realease_date = $shipDate;
                                                                        $newshipment->save();
                                                                        // ->update([
                                                                        //     'sync_status' => "Ready",
                                                                        //     'shipping_method' => $shippingMethod,
                                                                        //     'tracking_info' => $trackinInfo,
                                                                        //     'realease_date' => $shipDate,
                                                                        // ]);
                                                                    } else {
                                                                        $newshipment = PlatformOrderShipment::create([
                                                                            'user_id' => $userId,
                                                                            'platform_id' => $this->platformId,
                                                                            'user_integration_id' =>  $userIntegrationId,
                                                                            'sync_status' => PlatformStatus::READY,
                                                                            'shipping_method' => $shippingMethod,
                                                                            'tracking_info' => $trackinInfo,
                                                                            'realease_date' => $shipDate,
                                                                            'platform_order_id' => $order->id,
                                                                            'order_id' => $order->api_order_id,
                                                                            'linked_id' => $shipment->id
                                                                        ]);
                                                                        $shipment->linked_id = $newshipment->id;
                                                                        $shipment->save();
                                                                        $order->shipment_status = PlatformStatus::READY;
                                                                        $order->save();
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        //If order has not found

                                                        $return_response = $this->service->handleErrorResponse($apicall);

                                                        $mystring = "No order found with orderNo";
                                                        // Test if string contains the word
                                                        if (strpos($mystring, $return_response) !== false) {
                                                            $order->shipment_status = PlatformStatus::IGNORE;
                                                        } else {
                                                            $order->shipment_status = PlatformStatus::FAILED;
                                                        }
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $this->platformId, $destinationPlaformId, $object_id, 'failed', $order->id, $return_response);
                                                    }
                                                }
                                            } else {
                                                $order->shipment_status = PlatformStatus::IGNORE;
                                                $order->save();
                                            }
                                        }

                                    }

                                    if (isset($orderUrl)) {
                                        if ($orderUrl->url != $startDate) {
                                            $orderUrl->url = $startDate;
                                        }else{                                           
                                            $orderUrl->url =  Carbon::parse($startDate)->addSecond()->format('Y-m-d\TH:i:s\Z');                                          
                                        }
                                        $orderUrl->save();
                                    } else {
                                        PlatformUrl::insert([
                                            'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                            'url' => $startDate,
                                            'url_name' => $orderUrlName,
                                            'status' => 0
                                        ]);
                                    }
                                }
                            } else {
                                if (isset($orderUrl)) {
                                    if ($orderUrl->url != $endDate) {
                                        $orderUrl->url = $endDate;
                                        $orderUrl->save();
                                    }
                                } else {
                                    PlatformUrl::insert([
                                        'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                        'url' => $endDate,
                                        'url_name' => $orderUrlName,
                                        'status' => 0
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Create Transfer Order In Infoplus */
    public function SyncTransferOrder($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = NULL, $UserWorkFlowRuleID = NULL, $SorucePlatformName = NULL, $order_type = "Transfer",  $sync_status = "Ready", $RecordID = NULL, $account = NULL)
    {
        $return_response = true;

        try {
            $recordExist = 0;
            $limit = 20;

            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }

            $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
            $sourceAccount = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);

            if ($account  && $sourceAccount) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                    $object_id = $this->helper->getObjectId('transfer_order');

                    $query = PlatformOrderShipment::select('id', 'user_id', 'platform_id', 'user_integration_id', 'shipment_id', 'sync_status', 'platform_order_id', 'order_id', 'shipping_method', 'shipment_sequence_number', 'warehouse_id', 'to_warehouse_id', 'linked_id', 'stock_transfer_id');
                    if ($RecordID && $RecordID !== 0) {

                        $query->where('id', $RecordID);
                    } else {
                        $query->where([
                            [
                                'platform_id', '=', $SourcePlatformId
                            ], [
                                'user_integration_id', '=', $userIntegrationId
                            ], [
                                'sync_status', '=', $sync_status
                            ],
                            [
                                'type', '=', $order_type
                            ]
                        ]);
                    }

                    $list = $query->groupBy()->orderBy('updated_at', 'ASC')->orderBy('shipment_id', 'DESC')->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {
                        $recordExist = 1;
                        /* Default LOB */
                        $default_lob =  $this->map->getMappedDataByName($userIntegrationId, NULL, "default_order_line_of_business",  ['api_id'], "default");

                        if ($default_lob) {
                            $default_order_lob = $default_lob->api_id;
                        } else {
                            $default_order_lob = 0;
                        }
                        /* Find default customer */
                        // $CustomEmail = NULL;
                        // $CustomE = $this->map->getMappedDataByName($userIntegrationId, NULL, "default_customer_email",  ['custom_data'], "default");
                        // if ($CustomE) {
                        //     $CustomEmail = $CustomE->custom_data;
                        // }
                        /* ---------- */
                        /* Default Warehouse */
                        // $default_WarehouseId = NULL;
                        // $default_WarehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], "default");

                        // if ($default_WarehouseId) {
                        //     $default_order_warehouse_id = $default_WarehouseId->api_id;
                        // }
                        /* --------------- */

                        /* Find product identity */
                        $source_identity = $this->service->ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID);
                        /* --------------------- */
                        $DefaultShippingMethodId = NULL;
                        $default_sales_order_shipping_method = $this->map->getMappedDataByName($userIntegrationId, NULL, "sorder_shipping_method", ['api_id']);
                        if ($default_sales_order_shipping_method) {
                            $DefaultShippingMethodId = $default_sales_order_shipping_method->api_id;
                        }
                        $customerNo = false;
                        $shipping_method_object_id = $this->helper->getObjectId('shipping_method');
                        $warehouse_object_id = $this->helper->getObjectId('warehouse');
                        foreach ($list as $value) {
                            $bp_order_status = isset($value->sync_status) ? $value->sync_status : NULL;
                            if (in_array($value->sync_status, [$sync_status, 'Failed'])) {
                                /* Find Order Primary Key */
                                $order_primary_id = isset($value->id) ? $value->id : NULL;

                                /* Find one to one warehouse mapping if not found assing default warehouse */
                                $warehouseId = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "transfer_order_warehouse", ['api_id'], 'regular', $value->warehouse_id);
                                if (isset($warehouseId->api_id)) {
                                    $OrderWarehouseId = $warehouseId->api_id;
                                    $checkCustomerMap = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "order_warehouse_for_customer_email", ['api_id'], 'cross', $value->warehouse_id);
                                    if ($checkCustomerMap) {
                                        $CustomEmail = $checkCustomerMap->custom_data;
                                        $toWarehouse = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $warehouse_object_id, "api_id",  $value->to_warehouse_id, ["id", "name"]);

                                        if ($toWarehouse) {
                                            $toWarehouseAddress = $this->service->getWarehouseAddress($toWarehouse->id, $toWarehouse->name);
                                        } else {
                                            $toWarehouseAddress = [];
                                        }

                                        if (!empty($toWarehouseAddress)) {
                                            if ($value->linked_id == 0) {
                                                /*----------------Start to find order shipping method----------------*/
                                                $sales_order_shipping_method = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $shipping_method_object_id, "api_id",  $value->shipping_method, ["name"]);

                                                if ($sales_order_shipping_method) {
                                                    $sourceMethod = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $this->platformId, $shipping_method_object_id, "name",  $sales_order_shipping_method->name, ["api_id"]);
                                                    if (isset($sourceMethod)) {
                                                        $shippingMethod = $sourceMethod->api_id;
                                                    } else {
                                                        $shippingMethod = $DefaultShippingMethodId;
                                                    }
                                                } else {
                                                    $shippingMethod = $DefaultShippingMethodId;
                                                }
                                                /*----------------End to find order shipping method----------------*/
                                                /* Find customer by default email address */

                                                $customerNo = $this->service->FindCustomerOrVentorByEmail("Customer", NULL, $userId, $userIntegrationId, $CustomEmail, $account);
                                                /* --------------------- */

                                                if (is_array($customerNo)) { //if customer creation failed
                                                    $return_response = $customerNo == false || !isset($customerNo[0]) ? "Customer is not found in Infoplus account!" : $customerNo[0];
                                                    if ($this->service->handleCustomError($return_response)) {
                                                        /* Shipment Table sync status updated */
                                                        $value->sync_status = "Failed";
                                                        $value->save();
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                    }
                                                } else if ($order_primary_id) {


                                                    $orderLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $value->id)->groupBy('product_id')
                                                        ->selectRaw('*, sum(quantity) as sum')->get(); //isset($value->platformShippingLines) ? $value->platformShippingLines : NULL;

                                                    if ($orderLines) {
                                                        $orderLines = $this->service->PrepareOrderLine("SO", $orderLines, $userId, $userIntegrationId,  $SourcePlatformId, $source_identity['source_identity'], $default_order_lob);

                                                        if (count($orderLines['items']) > 0) {
                                                            if ($orderLines['productNotFound']) {
                                                                /* Shipment Table sync status updated */
                                                                // $value->sync_status = "Ready";
                                                                // $value->updated_at = date('Y-m-d H:i:s');
                                                                // $value->save();
                                                                $value->sync_status = 'Failed';
                                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                                $value->save();
                                                                $return_response = "One or more line item(s) are not matched with infoplus product";
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                            } else {
                                                                $post = [
                                                                    "lobId" => $default_order_lob,
                                                                    "warehouseId" => $OrderWarehouseId,
                                                                    "customerOrderNo" => $value->stock_transfer_id,
                                                                    "orderDate" => Carbon::parse($value->created_on)->format('Y-m-d\TH:i:s\Z'),
                                                                    "mediaCode" => "Electronic",
                                                                    "legacyRestrictionType" => "Interim",
                                                                    "shipCode" => "Ready to ship",
                                                                    "serviceTypeId" => "T",
                                                                    "carrierId" => $shippingMethod,
                                                                    "lineItems" => $orderLines['items'],
                                                                ];
                                                                if (!is_bool($customerNo)) { //if customerNo is not a bool value
                                                                    $post["customerNo"] = $customerNo;
                                                                }
                                                                $customerName = $this->service->findCustomerNameByEmail($CustomEmail, $userId, $userIntegrationId, $SourcePlatformId);
                                                                if ($customerName) {
                                                                    $toWarehouseAddress['shipToAttention'] = $customerName;
                                                                }
                                                                $payload = array_merge($post, $toWarehouseAddress);
                                                                $apicall = $this->infoplus->_API_CALL($account, "POST", "order", [], $payload);
                                                                $order_response = $apicall['body'];
                                                                if (!empty($order_response) && is_array($order_response)) {
                                                                    if (isset($order_response['orderNo'])) {
                                                                        if (!isset($post['customerNo'])) {
                                                                            /* Id payload don't have customerNo then store customer detail in DB*/
                                                                            $this->service->saveCustomerDetails($userId, $userIntegrationId, $this->platformId, $order_response, "Customer");
                                                                        }
                                                                        /* Insert infoplus order details  */
                                                                        $OrderLinked = $this->mobj->makeInsertGetId('platform_order_shipments', [
                                                                            'user_id' => $userId,
                                                                            'platform_id' => $this->platformId,
                                                                            'user_integration_id' => $userIntegrationId,
                                                                            'shipment_id' => $order_response['orderNo'],
                                                                            'shipment_sequence_number' => 0,
                                                                            'warehouse_id' =>  $order_response['warehouseId'],
                                                                            'created_on' => date("Y-m-d H:i:s", strtotime($order_response['orderDate'])),
                                                                            'order_id' => $order_response['orderNo'],
                                                                            'type' => PlatformRecordType::TRANSFER,
                                                                            'sync_status' => 'Pending',
                                                                            'linked_id' =>  $value->id,
                                                                        ]);

                                                                        /* Shipment Table sync status updated */
                                                                        $value->sync_status = "Synced";
                                                                        $value->linked_id = $OrderLinked;
                                                                        $value->save();
                                                                        $syncLog = 'success';
                                                                        $updateResponse = $this->service->updateOrder($account, $order_response, $toWarehouseAddress);
                                                                        if (!is_bool($updateResponse)) {
                                                                            $value->sync_status = 'Failed';
                                                                            $value->save();
                                                                            $syncLog = 'failed';
                                                                            $return_response = $updateResponse;
                                                                        } else {
                                                                            $updateResponse = null;
                                                                        }

                                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, $syncLog, $order_primary_id, $updateResponse);
                                                                    } else {
                                                                        $return_response = $this->service->handleErrorResponse($apicall);
                                                                        if ($this->service->handleCustomError($return_response)) {
                                                                            /* Shipment Table sync status updated */
                                                                            $value->sync_status = "Failed";
                                                                            $value->save();
                                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                                        }
                                                                    }
                                                                } else {
                                                                    $return_response = $this->service->handleErrorResponse($apicall);
                                                                    if ($this->service->handleCustomError($return_response)) {
                                                                        /* Shipment Table sync status updated */
                                                                        $value->sync_status = "Failed";
                                                                        $value->save();
                                                                        $return_response = isset($return_response) ? $return_response : "API Error:Invalid Json";
                                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            /* Shipment Table sync status updated */
                                                            $value->sync_status = "Failed";
                                                            $value->save();
                                                            $return_response = "Products are not found";
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                        }
                                                    } else {
                                                        /* If no shipment lines found  */
                                                        $value->sync_status = "Failed";
                                                        $value->save();
                                                        $return_response = "Products are not found";
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                                    }
                                                } else if ($order_primary_id && $bp_order_status == "Synced") {
                                                    $value->sync_status = "Inactive";
                                                    $value->save();
                                                } else {

                                                    $return_response = "Order detail has been not found";
                                                }
                                            } else if ($value->linked_id > 0) {
                                                $shipmentDetail = PlatformOrderShipment::find($value->linked_id);
                                                if ($shipmentDetail) {
                                                    $order_response = [];
                                                    $order_response['orderNo'] = $shipmentDetail->shipment_id;
                                                    $syncLog = 'success';
                                                    $updateResponse = $this->service->updateOrder($account, $order_response, $toWarehouseAddress);
                                                    if (!is_bool($updateResponse)) {
                                                        $value->sync_status = 'Failed';
                                                        $value->save();
                                                        $syncLog = 'failed';
                                                        $return_response = $updateResponse;
                                                    } else {
                                                        $value->sync_status = 'Synced';
                                                        $value->save();
                                                        $updateResponse = null;
                                                    }

                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, $syncLog, $order_primary_id, $updateResponse);
                                                }
                                            }
                                        } else {
                                            /* Shipment Table sync status updated */
                                            $value->sync_status = "Failed";
                                            $value->save();
                                            $return_response = "To Shipment address is missing.";
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id,  $return_response);
                                        }
                                    } else {
                                        /* Shipment Table sync status updated */
                                        $value->sync_status = "Failed";
                                        $value->save();
                                        $return_response = "No customer email mapping for warehouse";
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id,  $return_response);
                                    }
                                } else {
                                    /* Shipment Table sync status updated */
                                    $value->sync_status = "Ignore";
                                    $value->save();
                                    $error = "No warehouse mapping found";
                                    $return_response = $error;

                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                                }
                            }
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

    /* Create ASN/Purchase Order In Infoplus */
    public function SyncASN($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = NULL, $UserWorkFlowRuleID = NULL, $SorucePlatformName = NULL, $order_type = "PO", $sync_status = "Ready", $RecordID = NULL, $account = NULL)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 20;

            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
            $sourceAccount = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);

            if ($account && $sourceAccount) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                    $purchase_order_object_id = $this->helper->getObjectId('purchase_order');
                    $query = PlatformOrder::with('platformOrderAddress', 'platformCustomer', 'platformOrderLine');
                    if ($RecordID && $RecordID !== 0) {
                        $query->where('id', $RecordID);
                    } else {
                        $query->where([
                            [
                                'platform_id', '=', $SourcePlatformId
                            ], [
                                'user_integration_id', '=', $userIntegrationId
                            ], [
                                'sync_status', '=', $sync_status
                            ],
                            [
                                'order_type', '=', $order_type
                            ]
                        ]);
                    }

                    $list = $query->orderBy('id', 'ASC')->orderBy('updated_at', 'asc')->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {
                        $recordExist = 1;
                        $vendorID = false;
                        /* Default LOB */
                        $default_lob =  $this->map->getMappedDataByName($userIntegrationId, NULL, "default_order_line_of_business",  ['api_id'], "default");
                        if ($default_lob) {
                            $default_order_lob = $default_lob->api_id;
                        } else {
                            $default_order_lob = 0;
                        }
                        $CustomEmail = NULL;
                        $CustomE = $this->map->getMappedDataByName($userIntegrationId, NULL, "default_customer_email",  ['custom_data'], "default");
                        if ($CustomE) {
                            $CustomEmail = $CustomE->custom_data;
                        }

                        /* ---------- */
                        /* Default Warehouse */
                        // $default_order_warehouse_id = NULL;
                        // $default_WarehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], "default");


                        // if ($default_WarehouseId) {
                        //     $default_order_warehouse_id = $default_WarehouseId->api_id;
                        // }

                        /* --------------- */
                        $source_identity = $this->service->ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID);
                        /* Find Address Type: billing or shipping */
                        $addressType = $this->map->getMappedDataByName($userIntegrationId, NULL, "sorder_shipping_address", ['api_id']);

                        $addressType = isset($addressType->api_id) ? $addressType->api_id : "billing";

                        foreach ($list as $value) {

                            $bp_order_status = isset($value->sync_status) ? $value->sync_status : NULL;
                            if (in_array($bp_order_status, [$sync_status, 'Failed'])) {
                                $warehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], 'regular', $value->warehouse_id);
                                $order_primary_id = isset($value->id) ? $value->id : NULL;
                                if (isset($warehouseId->api_id)) {
                                    $OrderWarehouseId = $warehouseId->api_id;
                                    /* Find Address */
                                    $address = isset($value->platformOrderAddress) ? $value->platformOrderAddress : NULL;

                                    if ($address) {

                                        /* Find Customer Address */
                                        $details = $this->service->GetCustomerAddress($addressType, $value->id, "Vendor");

                                        if (is_array($details) && !empty($details)) {
                                            $vendorID = $this->service->FindVentorByEmail("Vendor", $default_order_lob, $details, $value->platform_customer_id, $userId, $userIntegrationId, $CustomEmail, $account);
                                        }
                                    }


                                    if (is_numeric($vendorID)) {
                                        /* Find Order Primary Key */
                                        if ($order_primary_id) {

                                            if ($address) {
                                                $orderLines = isset($value->platformOrderLine) ? $value->platformOrderLine : NULL;
                                                $address = $this->service->GetAddress($address, "ASN");

                                                if ($orderLines) {

                                                    $orderLines = $this->service->PrepareOrderLine("ASN", $orderLines, $userId, $userIntegrationId, $SourcePlatformId, $source_identity['source_identity'], $default_order_lob, $OrderWarehouseId, $vendorID, $value->delivery_date);
                                                    if (count($orderLines['items']) > 0) {
                                                        if ($orderLines['productNotFound']) {

                                                            /* Source Order sync status updated with linked id  */
                                                            // $value->sync_status = 'Ready';
                                                            // $value->order_updated_at = date("Y-m-d H:i:s");
                                                            // $value->save();
                                                            $value->sync_status = 'Failed';
                                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                                            $value->save();
                                                            $return_response = "One or more line item(s) are not matched with infoplus product";
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                                        } else {

                                                            $post = [
                                                                "lobId" => $default_order_lob,
                                                                "poNo" =>  $value->api_order_id,
                                                                "warehouseId" => $OrderWarehouseId,
                                                                'type' => "Normal",
                                                                "orderDate" => Carbon::parse($value->order_date)->format('Y-m-d\TH:i:s\Z'),
                                                                "vendorId" => $vendorID,
                                                                //"status" => "Open",
                                                                "lineItems" => $orderLines['items']
                                                            ];

                                                            $payload = array_merge($post, $address['billTo'], $address['shipTo']);

                                                            $apicall = $this->infoplus->_API_CALL($account, "POST", "asn", [], $payload);

                                                            $order_response = $apicall['body'];

                                                            if (!empty($order_response) && is_array($order_response)) {
                                                                if (isset($order_response['id'])) {
                                                                    /* Insert infoplus order details  */
                                                                    $OrderLinked = $this->mobj->makeInsertGetId('platform_order', [
                                                                        'user_id' => $userId,
                                                                        'platform_id' => $this->platformId,
                                                                        'user_integration_id' => $userIntegrationId,
                                                                        'order_type' => $order_type,
                                                                        'api_order_id' => $order_response['id'],
                                                                        'order_date' => date("Y-m-d H:i:s", strtotime($order_response['createDate'])),
                                                                        'order_number' => $order_response['poNo'],
                                                                        'sync_status' => 'Pending',
                                                                        'linked_id' =>  $order_primary_id,
                                                                        'shipment_status' => "Pending",
                                                                        'refund_sync_status' => "Pending",
                                                                        'order_updated_at' => date("Y-m-d H:i:s"),
                                                                        'api_updated_at' => $order_response['modifyDate'],
                                                                    ]);

                                                                    /* Source Order sync status updated with linked id  */
                                                                    $value->linked_id = $OrderLinked;
                                                                    $value->sync_status = 'Synced';
                                                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                                                    $value->save();
                                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'success', $order_primary_id, NULL);
                                                                } else {
                                                                    /* Source Order sync status updated with linked id  */
                                                                    $return_response = $this->service->handleErrorResponse($apicall);
                                                                    if ($this->service->handleCustomError($return_response)) {
                                                                        $value->sync_status = 'Failed';
                                                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                                                        $value->save();
                                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                                                    }
                                                                }
                                                            } else {
                                                                /* Source Order sync status updated with linked id  */
                                                                $return_response = $this->service->handleErrorResponse($apicall);
                                                                if ($this->service->handleCustomError($return_response)) {
                                                                    $value->sync_status = 'Failed';
                                                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                                                    $value->save();
                                                                    $return_response = isset($return_response) ? $return_response : "API Error:Invalid Json";
                                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        /* Source Order sync status updated with linked id  */
                                                        $value->sync_status = 'Failed';
                                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                                        $value->save();
                                                        $return_response = "Products are not found";
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                                    }
                                                } else {
                                                    /* If no shipment lines found  */
                                                    $value->sync_status = 'Failed';
                                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                                    $value->save();
                                                    $return_response = "No Line items are not found";
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                                }
                                            } else {
                                                /* If no shipment address found  */
                                                $value->sync_status = 'Failed';
                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                $value->save();
                                                $return_response = "Customer addresses are invalid or not in a proper way";
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                            }
                                        } else {

                                            $return_response = "Order detail has been not found";
                                        }
                                    } else {
                                        $return_response = $vendorID == false || !isset($vendorID[0]) ? "Vendor not found" : $vendorID[0];
                                        if ($this->service->handleCustomError($return_response)) {
                                            /* If no shipment address found  */
                                            $value->sync_status = 'Failed';
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();

                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    }
                                } else {
                                    /* If warehouse mapping found  */
                                    $value->sync_status = 'Ignore';
                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                    $value->save();
                                    $return_response = "No warehouse mapping found";
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $purchase_order_object_id, 'failed', $order_primary_id, $return_response);
                                }
                            }
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

    /* Get ASN Items Receipt */
    public function GetItemReceipts($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowRuelID, $UserWorkFlowRuleID, $DestinationPlatformName, $order_type = "PO", $sync_status = "Pending", $type = "ASN", $RecordID = NULL, $account = NULL)
    {

        $return_response = false;
        try {
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {

                    $query = PlatformOrder::with('linkedOrder')->select('id', 'api_order_id',  'updated_at', 'currency', 'linked_id', 'refund_sync_status', 'shipment_status', 'api_updated_at', 'is_fully_synced');
                    if ($RecordID) {
                        $query->where('id', $RecordID);
                    } else {
                        if ($order_type == "SC") {
                            $query->where([
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $userIntegrationId,
                                'order_type' => $order_type,
                                'refund_sync_status' => $sync_status
                            ]);
                        } else {
                            $query->where([
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $userIntegrationId,
                                'order_type' => $order_type,
                                'is_fully_synced' => 0
                            ]);
                        }
                    }

                    // $dateUpdate = $query->orderBy('api_updated_at', 'desc')->first(); //first last updated date
                    $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {
                        $identity = $this->service->ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuelID);
                        $source_identity = $identity['source_identity'];
                        $destination_identity = $identity['destination_identity'];

                        if ($source_identity && $destination_identity) {
                            $checkingForASN = false;
                            //$object_id =  $this->helper->getObjectId('purchase_order');
                            if ($type == "ASN") { //This part is userd when we have type ASN(PO)
                                $destination_platform_id = $this->helper->getPlatformIdByName($DestinationPlatformName);
                                $checkingForASN = true;
                            }

                            foreach ($list as $order) {

                                if (isset($order->linked_id) && $order->linked_id) {

                                    // if (isset($dateUpdate->api_updated_at)) {
                                    //     $date = $dateUpdate->api_updated_at; //if new date found assgin
                                    // } else {
                                    //     $date = Carbon::now()->subMinutes(30)->format('Y-m-d\TH:i:s\Z'); //minus 30 from current time to get latest data
                                    // }

                                    $arguments = [
                                        "limit" => 250,
                                        "filter" =>  "poNoId eq '" . $order->api_order_id . "'"
                                    ];
                                    $apicall = $this->infoplus->_API_CALL($account, "GET", "itemReceipt/search", $arguments, [], "v3.0");

                                    if ($result = $apicall['body']) {

                                        if (isset($result) && !empty($result) && is_array($result)) {
                                            $platform_order_id = $order->id;
                                            if ($checkingForASN) {
                                                $currency = $order->linkedOrder->currency;
                                                $order_id = $order->linkedOrder->api_order_id;
                                                $linked_id = $order->linked_id;
                                            }
                                            $Items = [];

                                            foreach ($result as $key => $value) {
                                                if (isset($value['receivedQuantity']) && $value['receivedQuantity'] > 0) {
                                                    if ($checkingForASN) { //When ASN set=true
                                                        $find = PlatformOrderShipment::where([
                                                            'platform_id' => $this->platformId,
                                                            'user_integration_id' => $userIntegrationId,
                                                            'realease_date' => $value['receivedDate'],
                                                            'shipment_id' => (string)$value['id'],
                                                            'type' => PlatformRecordType::POSHIPMENT
                                                        ])->first();
                                                        if (!$find) {
                                                            $shipmentId = PlatformOrderShipment::create([
                                                                'user_id' => $userId,
                                                                'platform_id' => $this->platformId,
                                                                'user_integration_id' => $userIntegrationId,
                                                                'sync_status' => "Ready",
                                                                'type' => PlatformRecordType::POSHIPMENT,
                                                                'shipment_id' => $value['id'],
                                                                'order_id' =>  $order_id,
                                                                'warehouse_id' => isset($value['warehouseId']) ? $value['warehouseId'] : NULL,
                                                                'realease_date' => $value['receivedDate'],
                                                                'created_on' => $value['receivedDate'],
                                                                'shipment_sequence_number' => 0,
                                                                'platform_order_id' =>  $platform_order_id,

                                                            ]);
                                                            $where = [];
                                                            if ($source_identity) {
                                                                $product_sku = $value['sku'];
                                                                $where['pp.' . $destination_identity] = $product_sku;
                                                            }
                                                            $where['pol.platform_order_id'] = $linked_id;
                                                            $where['pp.platform_id'] = $destination_platform_id;
                                                            $platform_product = $this->service->PrepareItems($where);
                                                            $api_product_id = @$platform_product->api_product_id;
                                                            $api_order_line_id = @$platform_product->api_order_line_id;

                                                            array_push($Items, [
                                                                'platform_order_shipment_id' => $shipmentId->id,
                                                                'row_id' => $api_order_line_id,
                                                                'product_id' => $api_product_id,
                                                                'quantity' => $value['receivedQuantity'],
                                                                'currency' => $currency,
                                                                'price' => isset($value['sell']) ? $this->helper->getNumberFormat($value['sell'], 4) : $this->helper->getNumberFormat(0, 4),
                                                            ]);
                                                        }
                                                    } else {
                                                        /* To manage Sales Return for Sales Credit in BP (Inventory Management) || This below code is commented due to logic changed as we can directly receive inventory via sales credit */

                                                        /*   if (isset($value['receivedQuantity']) && $value['receivedQuantity'] > 0) {
                                                            $refund_order_number = $order->api_order_id . "-" . $value['id'];
                                                            $this->service->ItemReceiptReturn($UserWorkFlowRuleID, $platform_order_id, $refund_order_number, $value, $userId, $userIntegrationId);
                                                        } */
                                                    }
                                                }
                                            }

                                            if (!empty($Items) && $checkingForASN) {

                                                $this->mobj->makeInsert(
                                                    "platform_order_shipment_lines",
                                                    $Items
                                                );
                                            }
                                        } else {
                                            $return_response = $this->service->handleErrorResponse($apicall);
                                        }
                                    } else {

                                        $return_response = $this->service->handleErrorResponse($apicall);
                                    }

                                    /* Get PO/SC Order Status */
                                    $apicall = $this->infoplus->_API_CALL($account, "GET", "asn/{$order->api_order_id}", [], [], "v3.0");
                                    $getStatus = $apicall['body'];
                                    if (!isset($getStatus['errors']) && is_array($getStatus) && !empty($getStatus)) {
                                        if ($getStatus['status'] == "Received") { //if found set order status as synced so next time it will not pick in loop
                                            if ($checkingForASN) { //When ASN set=true
                                                $order->shipment_status = 'Ready';
                                                /* When all item receipt received */
                                                $order->is_fully_synced = 1;
                                                /* Insert only one entry to identify all item receipt received */
                                                $find = PlatformOrderShipment::where([
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $userIntegrationId,
                                                    'platform_order_id' =>  $platform_order_id,
                                                    'shipment_id' => "final_shipment",
                                                    'type' => PlatformRecordType::POSHIPMENT
                                                ])->first();
                                                if (!$find) {
                                                    PlatformOrderShipment::create([
                                                        'user_id' => $userId,
                                                        'platform_id' => $this->platformId,
                                                        'user_integration_id' => $userIntegrationId,
                                                        'sync_status' => "Ready",
                                                        'shipment_id' => "final_shipment",
                                                        'order_id' => $order_id,
                                                        'warehouse_id' =>  NULL,
                                                        'type' => PlatformRecordType::POSHIPMENT,
                                                        'release_date' => date('Y-m-d H:i:s'),
                                                        'created_on' => date('Y-m-d H:i:s'),
                                                        'shipment_sequence_number' => 0,
                                                        'platform_order_id' =>  $platform_order_id,

                                                    ]);
                                                }
                                            } else {
                                                $order->refund_sync_status = 'Ready';
                                            }
                                        }
                                        $order->api_updated_at = $getStatus['modifyDate'];
                                        $order->save();
                                    } else {
                                        /* When ASN not exist handle error and set order as ignore */
                                        $return_response = $this->service->handleErrorResponse($apicall);
                                        if (strpos($return_response, $order->api_order_id) !== false) {
                                            if ($checkingForASN) { //When ASN set=true
                                                $order->shipment_status = 'Ignore';
                                                $order->is_fully_synced = 1;
                                            } else {
                                                $order->refund_sync_status = 'Ignore';
                                            }
                                        }

                                        // $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $this->platformId,$destination_platform_id, $object_id, 'failed', $order->id, $return_response);
                                    }
                                }
                                $order->updated_at = date('Y-m-d H:i:s');
                                $order->save();
                            }
                        }
                    }
                }
                $return_response = true;
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Inventory From Infoplus */
    public function GetInventory($userId = NULL, $userIntegrationId = NULL, $is_initial_syn = 0, $account = NULL)
    {

        $return_response = false;
        try {

            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account) {
                $date = Carbon::now()->subMinutes(30)->format('Y-m-d\TH:i:s\Z');
                $arguments = [];
                if ($is_initial_syn == 0) {
                    //if intial sync is set =0
                    $lastDate = PlatformProduct::select('api_inventory_lastmodified_time')->where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                    ])->orderByRaw("DATE_FORMAT(api_inventory_lastmodified_time, '%Y-%m-%d %H-%i-%s') DESC")->first();
                    if (isset($lastDate->api_inventory_lastmodified_time)) {
                        $date = $lastDate->api_inventory_lastmodified_time;
                    } else {
                        $date = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    }

                    $arguments["filter"] =  "inventoryUpdateTimestamp gte '" . $date . "'";
                    $arguments["sort"] =  "!modifyDate";
                    $url_name = "inventory_update";
                    $pageNumberIncrease = 1;
                    $SetloopBreaker = true;
                    $pageBreakerCounter = 5;
                } else {

                    //if intial sync is set =1
                    $url_name = "inventory";
                    $pageNumberIncrease = 0;
                    $SetloopBreaker = false;
                    $pageBreakerCounter = 10;
                }
                $x = 1;
                $pageLimit = 200;
                while ($x <= $pageBreakerCounter) {
                    $loopBreaker = true;
                    $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', $url_name]])->first();

                    if (isset($pageNo->url)) {

                        if ($pageNo->url == 0 && $pageNo->status == 1) {
                            $page = $pageNumberIncrease;

                            $loopBreaker = $SetloopBreaker;
                        } else {
                            $page = $pageNo->url + 1;
                        }
                    } else {
                        $page = 1;
                    }
                    if ($loopBreaker) {
                        $pageCounter = $page;
                        $breakCounter = 0;
                        $arguments["limit"] = $pageLimit;
                        $arguments["page"] = $page;

                        $apicall = $this->infoplus->_API_CALL($account, "GET", "item/search", $arguments, [], "v3.0");
                        $data = $apicall['body'];

                        if (!empty($data) && is_array($data)) {

                            if (!isset($data['errors'])) {
                                $skuList = [];
                                $ProductSkuID = [];
                                foreach ($data as $key => $value) {
                                    if (isset($skuList[$value['sku']])) {

                                        if (isset($ProductSkuID[$value['sku']])) {
                                            /* if SKU set find primary product tbl id */
                                            $productPrimaryID = $ProductSkuID[$value['sku']];
                                        }

                                        if ($productPrimaryID) {
                                            $value['platform_product_id'] = $productPrimaryID;
                                            $this->service->UpdateOrCreateProductInventory($userId, $userIntegrationId, $value);
                                        }
                                    } else {
                                        if (!empty($value['sku']) && isset($value['sku'])) {
                                            $skuList[$value['sku']] = $value['sku'];
                                            /* First Add Products in platform_products table */
                                            // $productPrimaryID = $this->service->UpdateOrCreateProduct($userId, $userIntegrationId, $value);
                                            $productPrimaryID = $this->service->PrepareModalData($value, $userId, $userIntegrationId, "inventory");
                                            /* ------------------------ */
                                            if ($productPrimaryID) {
                                                $ProductSkuID[$value['sku']] = $productPrimaryID;
                                                $value['platform_product_id'] = $productPrimaryID;
                                                $this->service->UpdateOrCreateProductInventory($userId, $userIntegrationId, $value);
                                            }
                                        }
                                    }
                                }

                                if ($breakCounter == 0) {

                                    if (isset($pageNo->url)) {
                                        $pageNo->url = $page;
                                        $pageNo->status = 0;
                                        $pageNo->save();
                                    } else {
                                        PlatformUrl::insert([
                                            'user_id' => $userId,
                                            'user_integration_id' => $userIntegrationId,
                                            'platform_id' => $this->platformId,
                                            'url' => $page + 1,
                                            'url_name' => $url_name,
                                            'status' => 0
                                        ]);
                                    }
                                    $return_response = "Page-{$pageCounter} data processed";
                                } else {
                                    $return_response = "API Error to get inventory from Infoplus";
                                }
                            } else {
                                $breakCounter = 1;
                                break;
                            }
                        } else {
                            if (isset($pageNo->url)) {
                                $pageNo->url = 0;
                                $pageNo->status = 1;
                                $pageNo->save();
                            } else {
                                PlatformUrl::insert([
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'url' => $page + 1,
                                    'url_name' => $url_name,
                                    'status' => 0
                                ]);
                            }
                            $return_response = true;
                        }
                    } else {
                        $return_response = true;
                    }
                    $x++;
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get Account Details */
    private function getPrimaryAccount($user_integration_id, $platformName = null, $platformId = null)
    {
        if (!$platformName) {
            $platformName = self::$myPlatform;
        }
        if (!$platformId) {
            $platformId = $this->platformId;
        }
        // $account = $this->mobj->get_or_set('account_info_' . $platformId . "_" . $user_integration_id);
        // if (!$account) {
        $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platformId);
        // $this->mobj->get_or_set('account_info_' . $platformId . "_" . $user_integration_id, $account, 3600);
        //  }
        return $account;
    }

    /* Execute Infoplus Method */
    public function ExecuteInfoplusEvents($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
    {
        $response = true;
        if ($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'COMMODITYCODE') {
            $response = $this->GetCommodityCode($user_id, $user_integration_id, 1, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'SHIPPINGMETHOD') {
            $response = $this->GetCarriers($user_id, $user_integration_id, 1, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'WAREHOUSE') {
            $response = $this->GetWarehouse($user_id, $user_integration_id, 1, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'LINEOFBUSINESS') {
            $response = $this->GetLOB($user_id, $user_integration_id);
        } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
            $sync_status = 'Ready';
            $order_type = "SO";
            $response = $this->SyncOrder($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $order_type, $sync_status, $record_id);
        } else if ($method == 'MUTATE' && $event == 'TRANSFERORDER') {
            $sync_status = 'Ready';
            $order_type = "Transfer";
            $response = $this->SyncTransferOrder($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $order_type, $sync_status, $record_id);
        } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $sync_status = 'Ready';
            $order_type = "PO";
            $response = $this->SyncASN($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $order_type, $sync_status, $record_id);
        } else if ($method == 'GET' && $event == 'POITEMRECEIPT') {
            $sync_status = 'Ready';
            $response = $this->GetItemReceipts($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,  $destination_platform_id, "PO", $sync_status);
        } else if ($method == 'GET' && $event == 'INVENTORY') {
            $response = $this->GetInventory($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'MUTATE' && $event == 'SALESCREDIT') {
            $sync_status = 'Ready';
            $order_type = "SC";
            $response = $this->SyncASN($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $order_type, $sync_status, $record_id);
        } else if ($method == 'GET' && $event == 'ITEMSHIPPED') {
            $sync_status = "Pending";
            $order_type = "SO";
            $response = $this->CheckOrderStatus($user_id, $user_integration_id,  $user_workflow_rule_id, $destination_platform_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'ALLTRANSFERITEMRECEIVED') {
            $sync_status = "Pending";
            $order_type = "Transfer";
            $response = $this->GetTransferOrderItemReceived($user_id, $user_integration_id,  $user_workflow_rule_id, $order_type, $sync_status, $record_id);
        } else if ($method == 'GET' && $event == 'ITEMRECEIPTRETURN') {
            $sync_status = 'Pending';
            $order_type = "SC";
            $type = NULL;
            $response = $this->GetItemReceipts($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,  $destination_platform_id, $order_type, $sync_status, $type, $record_id);
        } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
            $sync_status = 'Ready';
            //  if (env('APP_ENV') == "stag") {
            $response = $this->SyncProducts($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,  $source_platform_id,  $sync_status, $record_id);
            // } else {
            //     $response = $this->SyncProductsOriginal($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,  $source_platform_id,  $sync_status, $record_id);
            // }
        }
        return  $response;
    }

    //test function  //test_infoplus
    public function test()
    {
        $user_id = 205;
        $user_integration_id = 501;
        $platform_workflow_rule = 135;
        $user_workflow_rule = 932;

        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->SyncInventoryBulk($user_id, $user_integration_id, 'infoplus', $platform_workflow_rule, $user_workflow_rule, 'Ready', NULL);

        // app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->updateInventorySyncStatus($user_id, $user_integration_id, 'infoplus', "Ready");
        // die();

    }


}
