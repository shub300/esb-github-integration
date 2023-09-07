<?php

namespace App\Http\Controllers\Peoplevox;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Helper\MainModel;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use Illuminate\Support\Facades\Log;
use App\Helper\ConnectionHelper;
use App\Http\Controllers\Peoplevox\Api\PeoplevoxApi;
use App\Models\PlatformAccount;
use App\Models\PlatformUrl;
use App\Models\PlatformCustomer;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformProductInventory;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformObjectData;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformPreProcessData;
use App\Models\UserWorkflowRule;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

class PeoplevoxController extends PeoplevoxApi
{
    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'peoplevox';

    public $connectionHelper, $mainModel, $logger, $fieldMapHelper, $platformId;
    public function __construct()
    {
        $this->connectionHelper = new ConnectionHelper();
        $this->mainModel = new MainModel();
        $this->logger = new Logger();
        $this->fieldMapHelper = new FieldMappingHelper();
        // Set the platform ID
        $this->platformId = $this->connectionHelper->getPlatformIdByName(self::PLATFORMNAME);
    }

    /** ##### Account connection [start] ##### */

    /**
     * Auth function return the view page of authentication
     */
    public function InitiatePeoplevoxAuth(Request $request)
    {
        $platform = self::PLATFORMNAME;
        return view("pages.apiauth.auth_peoplevox", compact('platform'));
    }

    /**
     * Auth function to connect to the platform with response to the front
     */
    public function ConnectPeoplevox(Request $request)
    {
        $response = ['status_code' => 0]; // array for return response with status_code default to 0 (false)

        if ($this->mainModel->checkHtmlTags($request->all())) {
            $response['status_text'] = Lang::get('tags.validate');
            return $response;
        }

        try {
            $validator = Validator::make($request->all(), [
                'peoplevoxClientId' => 'required',
                'peoplevoxUsername' => 'required',
                'peoplevoxPassword' => 'required'
            ], [
                'peoplevoxClientId.required' => 'Client id is required.',
                'peoplevoxUsername.required' => 'Username is required.',
                'peoplevoxPassword.required' => 'Password is required.'
            ]);
            if ($validator->fails()) {
                $statustext = array_values( json_decode( $validator->messages()->toJson(), true ) )[0][0];
            } else {
                $validated = array_map(function ($val) {
                    return htmlspecialchars($val);
                }, $validator->validated());
                $validated = (object) $validated;

                // Set and Decrypt the values for security measures
                $client_id = $validated->peoplevoxClientId; // $this->mainModel->encrypt_decrypt( $validated->peoplevoxClientId );
                $username = $this->mainModel->encrypt_decrypt($validated->peoplevoxUsername);
                $password = $this->mainModel->encrypt_decrypt($validated->peoplevoxPassword);

                // Get Current User Id
                $user_data =  Session::get('user_data');
                $userId =  $user_data['id'];

                // Check for the account
                $account = PlatformAccount::select('id')->where([
                    'user_id' => $userId,
                    'platform_id' => $this->platformId,
                    'account_name' => $client_id
                ])->count();
                if ($account === 0) {
                    $conncetion = static::checkAuthCredential($validated, 'auth');
                    if (isset($conncetion['status_code']) && $conncetion['status_code'] == 1) {
                        // Add the given data
                        $newAccount = PlatformAccount::create([
                            'user_id' => $userId,
                            'platform_id' => $this->platformId,
                            'account_name' => $client_id,
                            'app_id' => $username,
                            'app_secret' => $password,
                            'access_token' => $this->mainModel->encrypt_decrypt($conncetion['status_data'])
                        ]);

                        if ($newAccount->id) {
                            $response['status_code'] = true;
                            $statustext = 'Account Connected.';
                        } else {
                            $statustext = 'Account not created! Please try again.';
                        }
                    } else {
                        $statustext = 'Please check for the given credential.';
                        if (isset($conncetion['status_data'])) {
                            $statustext = $conncetion['status_data'];
                        }
                    }
                } else {
                    $statustext = "Account already connected.";
                }
            }
            $response['status_text'] = $statustext;
        } catch ( Exception $e) {
            $response['status_text'] = $e->getMessage();
        }
        return $response;
    }

    /** ##### Account connection [end] ##### */

    /** ##### GET::Warehouse [start] ##### */
    public function storeWarehouseData( $userId, $userIntegrationId )
    {
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $objectId = $this->connectionHelper->getObjectId('warehouse');
                $destPlatformId = $this->connectionHelper->getPlatformIdByName("snowflake");
                $postDataReqFields = [
                    'method' => 'GetData',
                    'templateName' => 'Sites',
                    'limit' => 30
                ];

                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                $response = static::makeAPICall($accountInfo, 'GetData', $postData);
                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $sites = $response['status_data'];
                    if (count($sites)) {
                        PlatformObjectData::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id'=>$objectId])->update(['status' => 0]);
                        foreach ($sites as $key => $site) {

                            if( isset($site['Reference']) && trim($site['Reference']) == 'SystemSite' ){
                                continue; // This is an internal warehouse, it does not use to create orders
                            }

                            $siteData = [
                                "name" => (isset($site['Name']) && $site['Name']) ? $site['Name'] : NULL,
                                "api_id" => (isset($site['Reference']) && $site['Reference']) ? $site['Reference'] : NULL,
                                "status" => 1
                            ];

                            // Store warehouse information for Source (Peopvevox) side
                            $existing = PlatformObjectData::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_id' => $site['Reference']])->select('id')->first();
                            if ($existing) {
                                $existing->update($siteData);
                            } else {
                                $siteData['user_id'] = $userId;
                                $siteData['user_integration_id'] = $userIntegrationId;
                                $siteData['platform_id'] = $this->platformId;
                                $siteData['platform_object_id'] = $objectId;
                                PlatformObjectData::create($siteData);
                            }

                            // Store warehouse information for Destination (Snowflake) side
                            $existing = PlatformObjectData::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $destPlatformId, 'api_id' => $site['Reference']])->select('id')->first();
                            if ($existing) {
                                $siteData['platform_id'] = $destPlatformId;
                                $existing->update( $siteData );
                            } else {
                                $siteData['user_id'] = $userId;
                                $siteData['user_integration_id'] = $userIntegrationId;
                                $siteData['platform_id'] = $destPlatformId;
                                $siteData['platform_object_id'] = $objectId;
                                PlatformObjectData::create( $siteData );
                            }
                        }
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> storeWarehouseData -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }
    /** ##### GET::Warehouse [end] ##### */


    /** ##### GET::Customer and Product [start] ##### */

    /**
     * Function to get customer information from Peoplevox platform using API call
     */
    public function getSuppliers($userId, $userIntegrationId)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/getSuppliers/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $pageNo = 1;
                $limit = 100;
                $objectId = $this->connectionHelper->getObjectId('default_vendor');

                $postDataReqFields = [
                    'method' => 'GetData',
                    'templateName' => 'Suppliers',
                    'pageNo' => $pageNo,
                    'limit' => $limit
                ];
                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSuppliers/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postdata: " . print_r($postData, true));
                $response = static::makeAPICall($accountInfo, 'GetData', $postData);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSuppliers/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));
                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $Suppliers = $response['status_data'];
                    if (count($Suppliers)) {
                        PlatformObjectData::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id'=>$objectId])->update(['status' => 0]);
                        foreach ($Suppliers as $key => $supplier) {

                            if( !isset($supplier['Reference']) || !trim($supplier['Reference']) ){
                                continue; // Insert supplier only if its Reference value is set
                            }

                            if (strpos($supplier['Reference'], "~D~") !== false) {
                                continue; // Skip supplier with "~D~", these are Deactivated suppliers
                            }

                            $supplierData = [
                                "name" => (isset($supplier['Name']) && $supplier['Name']) ? $supplier['Name'] : NULL,
                                "api_id" => (isset($supplier['Reference']) && $supplier['Reference']) ? $supplier['Reference'] : NULL,
                                "status" => 1
                            ];

                            // Store warehouse information for Source (Peopvevox) side
                            $existing = PlatformObjectData::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_id' => $supplier['Reference']])->select('id')->first();
                            if ($existing) {
                                $existing->update( $supplierData );
                            } else {
                                $supplierData['user_id'] = $userId;
                                $supplierData['user_integration_id'] = $userIntegrationId;
                                $supplierData['platform_id'] = $this->platformId;
                                $supplierData['platform_object_id'] = $objectId;
                                PlatformObjectData::create( $supplierData );
                            }
                        }
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getSuppliers -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Function to get item information from Peoplevox platform using API call
     */
    public function getProducts($userId, $userIntegrationId, $reqItemCode = null)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $pageNo = 1;
                $limit = 50;

                if( !$reqItemCode ){
                    $pf_url = PlatformUrl::where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId, 'url_name' => 'item_pageNo', 'status' => 1
                    ])->select('id', 'url', 'status')->first();

                    if ($pf_url && $pf_url->status == 1) {
                        $pageNo = $pf_url->url;
                    } else {
                        $pf_url = new PlatformUrl();
                        $pf_url->user_id = $userId;
                        $pf_url->user_integration_id = $userIntegrationId;
                        $pf_url->platform_id = $this->platformId;
                        $pf_url->url_name = 'item_pageNo';
                        $pf_url->url = $pageNo;
                        $pf_url->status = 1;
                        $pf_url->save();
                    }
                }

                $statusAttrField = null;
                $attrFieldMap = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, null, "product_status_field",  ['api_id']);
                if ($attrFieldMap) {
                    $statusAttrField = $attrFieldMap->api_id;
                }

                $postDataReqFields = [
                    'method' => 'GetData',
                    'templateName' => 'Item types',
                    'pageNo' => $pageNo,
                    'limit' => $limit
                ];

                if( $reqItemCode ){
                    $postDataReqFields['searchField'] = 'ItemCode';
                    $postDataReqFields['searchTerm'] = $reqItemCode;
                }

                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
                $response = static::makeAPICall($accountInfo, 'GetData', $postData);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));
                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $items = $response['status_data'];
                    if (count($items)) {
                        $bundleItemData = PlatformPreProcessData::where([
                            'user_integration_id' => $userIntegrationId,
                            'platform_id' => $this->platformId,
                            'status' => 1,
                            'module' => 'PRODUCT'
                        ])->pluck('api_id')->toArray();

                        foreach ($items as $key => $item) {
                            $itemCode = (isset($item['ItemCode']) && $item['ItemCode']) ? trim($item['ItemCode']) : NULL;

                            if ( !$itemCode || strpos($itemCode, "~D~") !== false) {
                                continue; // Skip item codes with "~D~", these are Deactivated items
                            }

                            if( isset($item["$statusAttrField"]) && $item["$statusAttrField"] == 'inactive'){
                                continue;
                            }

                            $itemData = [
                                "product_name" => (isset($item['Name']) && $item['Name']) ? $item['Name'] : $itemCode,
                                "barcode" => (isset($item['Barcode']) && $item['Barcode']) ? $item['Barcode'] : NULL,
                                "uom" => (isset($item['UnitOfMeasure']) && $item['UnitOfMeasure']) ? $item['UnitOfMeasure'] : NULL,
                                "weight" => (isset($item['Weight']) && $item['Weight']) ? $item['Weight'] : NULL,
                                "weight_unit" => (isset($item['WeightMeasure']) && $item['WeightMeasure']) ? $item['WeightMeasure'] : NULL,
                                'product_status' => 1, // to make published in Snowflake
                                'price' => (isset($item['RetailPrice']) && $item['RetailPrice']) ? $item['RetailPrice'] : 0,
                                "api_updated_at" => now()
                            ];
                            Storage::append('Peoplevox/' . $userIntegrationId . '/storeItemInformation/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: itemData" . print_r($itemData, true));
                            if ( count($bundleItemData) && in_array($itemCode, $bundleItemData)){
                                $itemData['bundle'] = 1;
                            }

                            $existing = PlatformProduct::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_product_id' => trim($item['ItemCode'])])->select('id', 'product_sync_status')->first();
                            if ($existing) {
                                $pvxItemId = $existing->id;
                                /*if ($existing->product_sync_status != 'Synced') {
                                    $itemData['product_sync_status'] = 'Ready';
                                }*/
                                //$existing->update($itemData); // Item update is not in our project doc. Only one time item creation will be handlled.
                            } else {
                                $itemData['user_id'] = $userId;
                                $itemData['user_integration_id'] = $userIntegrationId;
                                $itemData['platform_id'] = $this->platformId;
                                $itemData['product_sync_status'] = 'Ready';
                                $itemData['api_product_id'] = $itemCode;
                                $itemData['api_variant_id'] = $itemCode;
                                $itemData['sku'] = $itemCode;
                                $pvxItemId = PlatformProduct::create($itemData)->id;
                            }

                            if ($pvxItemId) {
                                /** Product attribute data [start] */
                                $itemAttrData = [
                                    "fulldescription" => (isset($item['Description']) && $item['Description']) ? $item['Description'] : NULL,
                                    "height" => (isset($item['Height']) && $item['Height']) ? $item['Height'] : NULL,
                                    "width" => (isset($item['Width']) && $item['Width']) ? $item['Width'] : NULL,
                                    "length" => (isset($item['Depth']) && $item['Depth']) ? $item['Depth'] : NULL
                                ];

                                $existingAttr = PlatformProductDetailAttribute::where(['platform_product_id' => $pvxItemId])->select('id')->first();
                                if ($existingAttr) {
                                    $existingAttr->update($itemAttrData);
                                } else {
                                    $itemAttrData['platform_product_id'] = $pvxItemId;
                                    PlatformProductDetailAttribute::create($itemAttrData);
                                }
                                /** Product attribute data [end] */

                                /** Product inventory [start]  */
                                $stockOnHand = (isset($item['OnHand']) && $item['OnHand']) ? $item['OnHand'] : 0;
                                $itemInventoryData = [
                                    "api_product_id" => (isset($item['ItemCode']) && $item['ItemCode']) ? $item['ItemCode'] : NULL,
                                    "quantity" => $stockOnHand,
                                ];

                                $existingInv = PlatformProductInventory::where(['platform_product_id' => $pvxItemId])->select('id', 'quantity')->first();
                                if ($existingInv) {
                                    if ((float) $existingInv->quantity != (float) $stockOnHand) {
                                        $existingInv->sync_status = 'Ready';
                                        PlatformProduct::where('id', $pvxItemId)->update(["inventory_sync_status" => 'Ready']);
                                    }
                                    $existingInv->update($itemInventoryData);
                                } else {
                                    $itemInventoryData['user_id'] = $userId;
                                    $itemInventoryData['user_integration_id'] = $userIntegrationId;
                                    $itemInventoryData['platform_id'] = $this->platformId;
                                    $itemInventoryData['platform_product_id'] = $pvxItemId;
                                    $itemInventoryData['sync_status'] = 'Ready';
                                    PlatformProductInventory::create($itemInventoryData);

                                    PlatformProduct::where('id', $pvxItemId)->update(["inventory_sync_status" => 'Ready']);
                                }
                                /** Product inventory [end]  */

                                /** Product price [start] */
                                $apiCurrencyCode = null;
                                $default_product_currency = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, null, "default_product_currency", ['api_code'], "default");
                                if (isset($default_product_currency->api_code)){
                                    $apiCurrencyCode = $default_product_currency->api_code;
                                }
                                $itemPriceData = [ "api_currency_code" => $apiCurrencyCode ];
                                $objectID = $this->connectionHelper->getObjectId("pricelist");
                                Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: pricelist objectID: " . print_r( $objectID, true ));
                                if ($objectID) {
                                    $priceListInfo = PlatformObjectData::where([
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $this->platformId,
                                        'platform_object_id' => $objectID
                                    ])->select('id', 'api_id')->get();

                                    if( $priceListInfo->isNotEmpty() ){
                                        foreach ($priceListInfo as $objData) {
                                            if($objData->api_id == 'cost_price'){
                                                $itemPriceData['price'] = (isset($item['BuyPrice']) && $item['BuyPrice']) ? $item['BuyPrice'] : 0;
                                                $itemPriceData['platform_object_data_id'] = $objData->id;
                                                Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: cost_price block: " . print_r( $objData->id, true ));
                                            }
                                            if($objData->api_id == 'sale_price'){
                                                $itemPriceData['price'] = (isset($item['RetailPrice']) && $item['RetailPrice']) ? $item['RetailPrice'] : 0;
                                                $itemPriceData['platform_object_data_id'] = $objData->id;
                                                Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: sale_price block: " . print_r( $objData->id, true ));
                                            }

                                            Storage::append('Peoplevox/' . $userIntegrationId . '/getProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: itemPriceData: " . print_r( $itemPriceData, true ));
                                            $existingPrice = PlatformProductPriceList::where(['platform_product_id' => $pvxItemId, 'platform_object_data_id'=>$objData->id ])->select('id')->first();
                                            if ($existingPrice) {
                                                $existingPrice->update($itemPriceData);
                                            } else {
                                                $itemPriceData['platform_product_id'] = $pvxItemId;
                                                PlatformProductPriceList::create($itemPriceData);
                                            }
                                        }
                                    }
                                }
                                /** Product price [end] */
                            }
                        }
                    }

                    if( !$reqItemCode ){
                        if (count($items) == $limit && $pf_url) { // because 100 is the default page limit
                            $pf_url->update(['url' => $pageNo + 1]);
                        } else if (count($items) < $limit && $pf_url) { // if returned less than 100 record the set 1 in page counter to reverse the same flow from the begining
                            $pf_url->update(['url' => 1]);
                        }
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getProducts -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Function to get item information from Peoplevox platform using API call
     */
    public function getBundleProducts($userId, $userIntegrationId, $isInitialSync = 0)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/getBundleProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $pageNo = 1;
                $limit = 90;

                $pf_url = PlatformUrl::where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId, 'url_name' => 'bundleItem_pageNo', 'status' => 1
                ])->select('id', 'url', 'status')->first();

                if ($pf_url && $pf_url->status == 1) {
                    $pageNo = $pf_url->url;
                } else {
                    $pf_url = new PlatformUrl();
                    $pf_url->user_id = $userId;
                    $pf_url->user_integration_id = $userIntegrationId;
                    $pf_url->platform_id = $this->platformId;
                    $pf_url->url_name = 'bundleItem_pageNo';
                    $pf_url->url = $pageNo;
                    $pf_url->status = 1;
                    $pf_url->save();
                }

                $postDataReqFields = [
                    'method' => 'GetData',
                    'templateName' => 'Item type kittings',
                    'pageNo' => $pageNo,
                    'limit' => $limit
                ];

                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getBundleProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
                $response = static::makeAPICall($accountInfo, 'GetData', $postData);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getBundleProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));
                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $bundleItems = $response['status_data'];
                    if (count($bundleItems)) {
                        foreach ($bundleItems as $key => $bundleItem) {
                            $parentItemCode = (isset($bundleItem['ParentItemCode']) && $bundleItem['ParentItemCode']) ? trim($bundleItem['ParentItemCode']) : NULL;
                            $childItemCode = (isset($bundleItem['ChildItemCode']) && $bundleItem['ChildItemCode']) ? trim($bundleItem['ChildItemCode']) : NULL;
                            $quantity = (isset($bundleItem['Quantity']) && $bundleItem['Quantity']) ? trim($bundleItem['Quantity']) : 0;
                            if (strpos($parentItemCode, "~D~") !== false) {
                                Storage::append('Peoplevox/' . $userIntegrationId . '/getBundleProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: skipped from ~D~ check");
                                continue; // Skip item codes with "~D~", these are Deactivated items
                            }
                            PlatformPreProcessData::where([
                                'user_integration_id' => $userIntegrationId,
                                'platform_id' => $this->platformId,
                                'api_id' => $parentItemCode,
                                'sub_api_id' => $childItemCode,
                                'module' => 'PRODUCT',
                            ])->update([ 'status' => 0 ]); // Make status 0 for existing, if item found again then it will be update to 1

                            $bundleItem = PlatformPreProcessData::where([
                                'user_integration_id' => $userIntegrationId,
                                'platform_id' => $this->platformId,
                                'api_id' => $parentItemCode,
                                'sub_api_id' => $childItemCode,
                                'module' => 'PRODUCT',
                            ])->select('id', 'status')->first();
                            if( !$bundleItem ){
                                $bundleItem = new PlatformPreProcessData();
                                $bundleItem->user_id = $userId;
                                $bundleItem->user_integration_id = $userIntegrationId;
                                $bundleItem->platform_id = $this->platformId;
                                $bundleItem->api_id = $parentItemCode;
                                $bundleItem->sub_api_id = $childItemCode;
                                $bundleItem->module = 'PRODUCT';
                            }
                            $bundleItem->description = $quantity;
                            $bundleItem->status = 1;
                            $bundleItem->save();
                            Storage::append('Peoplevox/' . $userIntegrationId . '/getBundleProducts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: saved: " . print_r( $bundleItem->id, true ));
                        }
                    }

                    if (count($bundleItems) == $limit && $pf_url) { // because 100 is the default page limit
                        $pf_url->update(['url' => $pageNo + 1]);
                        if( $isInitialSync ){
                            $returnstatus = "processed page number $pageNo";
                        }
                    } else if (count($bundleItems) < $limit && $pf_url) { // if returned less than 100 record the set 1 in page counter to reverse the same flow from the begining
                        $pf_url->update(['url' => 1]);
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getProducts -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /** Function to instant pull those items which are coming through Sales Order but its Line-item is not existed in Database
     * Then this function is called to instantly pull single or multiple item/s so that SO can be create in Snowflake without any error.
     */
    public function pullUnavailableItems($arrItemCode, $pvxSoId, $userIntegrationId){
        $returnstatus = true;
        try{
            $basicInfo = PlatformOrder::where('id', $pvxSoId)->select('user_id', 'user_integration_id', 'platform_id')->first();
            if($basicInfo){
                $arrItemCode = array_unique($arrItemCode);
                $availableItemCodes = PlatformProduct::where([
                    'user_integration_id'=>$basicInfo->user_integration_id,
                    'platform_id'=>$basicInfo->platform_id
                ])
                ->whereIn('api_product_id', $arrItemCode)
                ->pluck('api_product_id')->toArray();

                $missingItemCodes = array_diff($arrItemCode, $availableItemCodes);

                if( count($missingItemCodes) ){
                    foreach ($missingItemCodes as $key => $itemCode) {
                        $returnstatus = $this->getProducts($basicInfo->user_id, $basicInfo->user_integration_id, $itemCode);
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> pullUnavailableItems -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }
    /** ##### GET::Customer and Product [end] ##### */

    /** ##### GET::Sales order [start] ##### */

    /**
     * Function to get sales order information from Peoplevox platform using API call
     */
    public function getSalesOrders($userId, $userIntegrationId, $userWorkFlowRuleId)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $pageNo = 1;
                $limit = 10;
                $pf_url = PlatformUrl::where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId, 'url_name' => 'so_pageNo', 'status' => 1
                ])->select('id', 'url', 'status')->first();

                if ($pf_url && $pf_url->status == 1) {
                    $pageNo = $pf_url->url;
                } else {
                    $pf_url = new PlatformUrl();
                    $pf_url->user_id = $userId;
                    $pf_url->user_integration_id = $userIntegrationId;
                    $pf_url->platform_id = $this->platformId;
                    $pf_url->url_name = 'so_pageNo';
                    $pf_url->url = $pageNo;
                    $pf_url->status = 1;
                    $pf_url->save();
                }

                $postDataReqFields = [
                    'method' => 'GetReportData',
                    'templateName' => 'Sales orders by status',
                    'orderBy' => 'Requested delivery date',
                    'orderType' => 'asc',
                    'pageNo' => $pageNo,
                    'limit' => $limit
                ];
                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
                $response = static::makeAPICall($accountInfo, 'GetReportData', $postData);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));

                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $salesOrders = $response['status_data'];
                    if (count($salesOrders)) {
                        $storeRes = $this->storeSalesOrderDetails($accountInfo, $userId, $userIntegrationId, $userWorkFlowRuleId, $salesOrders);
                        if($storeRes !== true){
                            $returnstatus = $storeRes;
                        }
                    }

                    if (count($salesOrders) == $limit && $pf_url) { // because 100 is the default page limit
                        $pf_url->update(['url' => $pageNo + 1]);
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getSalesOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Backup call to get missing sales order information from Peoplevox platform using API call
     * Due to depending on API field "RequestedDeliveryDate" some SO got missed because it is a future date.
     * So in some case SO got missed in our regular SO GET call. So this call repull those missed SO details.
     */
    public function getSalesOrdersBackupCall($userId, $userIntegrationId, $userWorkFlowRuleId){
        $returnstatus = true;
        try {
            // Some predefined values
            $startTime = 1; // 01:00AM From this time sync has to start
            $endTime = 10;  // 04:00AM After this time sync has to stop

            // Get the current date and time
            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d');

            $allowHours = range($startTime, $endTime);
            $allowSync = false;
            if( in_array(date('H'), $allowHours) ){
                $syncDateInfo = PlatformUrl::where([ 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$this->platformId, 'url_name'=>'soBackup_timer' ])
                ->select('id', 'url', 'status')->first();
                if( $syncDateInfo ){
                    $lastSyncedDate = date('Y-m-d', strtotime($syncDateInfo->url));
                    /** If last synced and current date is the same and sync status is zero then we consider today's sync has been completed.
                     *  So we can skip the flow. Here status 1 indicates sync process is in progress. */
                    if ($currentDate == $lastSyncedDate && $syncDateInfo->status === 0 ) {
                        return true;
                    }

                    // If current date is greater than lastSyncdDate then we allow to sync
                    if ($currentDate != $lastSyncedDate || $syncDateInfo->status === 1) {
                        $allowSync = true;

                        if ($currentDate != $lastSyncedDate || $syncDateInfo->status === 0) {
                            $syncDateInfo->status = 1;
                            $syncDateInfo->url = $currentDateTime;
                            $syncDateInfo->save();
                        }
                    }
                } else{
                    // Check if the current time is between start time and end time
                    $allowSync = true;

                    $syncDateInfo = new PlatformUrl();
                    $syncDateInfo->user_id = $userId;
                    $syncDateInfo->user_integration_id = $userIntegrationId;
                    $syncDateInfo->platform_id = $this->platformId;
                    $syncDateInfo->url_name = 'soBackup_timer';
                    $syncDateInfo->status = 1;
                    $syncDateInfo->url = $currentDateTime;
                    $syncDateInfo->save();
                }
            }

            // Check if sync is allowed or not, if not then exit from the process
            if( !$allowSync ){
                return true;
            }

            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                // Check Sales order's current pageination number, so that backup call an start back from this point
                $pageNo = 1;
                $limit = 10;
                $pf_url = PlatformUrl::where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId, 'url_name' => 'soBackup_pageNo', 'status' => 1
                ])->select('id', 'url', 'status', 'url_filter')->first();

                if ($pf_url) {
                    $pageNo = $pf_url->url;
                } else {
                    $SoPagination = PlatformUrl::where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId, 'url_name' => 'so_pageNo', 'status' => 1
                    ])->select('url')->first();
                    if($SoPagination){
                        $pageNo = (int)$SoPagination->url - 100;
                    }
                    if(!$pf_url){
                        $pf_url = new PlatformUrl();
                        $pf_url->user_id = $userId;
                        $pf_url->user_integration_id = $userIntegrationId;
                        $pf_url->platform_id = $this->platformId;
                        $pf_url->url_name = 'soBackup_pageNo';
                    }
                    if($pf_url->url_filter){
                        $date_filter = new \DateTime($pf_url->url_filter);
                        $today = new \DateTime(); // This will get the current date and time

                        if ($date_filter->format('Y-m-d') == $today->format('Y-m-d')) {
                            return true; // SO backup sync has been done already. No need to restart again at the same date.
                        }
                    }

                    $pf_url->url = $pageNo;
                    $pf_url->status = 1;
                    $pf_url->save();
                }

                $postDataReqFields = [
                    'method' => 'GetReportData',
                    'templateName' => 'Sales orders by status',
                    'orderBy' => 'Requested delivery date',
                    'orderType' => 'asc',
                    'pageNo' => $pageNo,
                    'limit' => $limit
                ];
                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrdersBackupCall/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
                $response = static::makeAPICall($accountInfo, 'GetReportData', $postData);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrdersBackupCall/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));

                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $salesOrders = $response['status_data'];
                    if (count($salesOrders)) {
                        $storeRes = $this->storeSalesOrderDetails($accountInfo, $userId, $userIntegrationId, $userWorkFlowRuleId, $salesOrders);
                        if($storeRes !== true){
                            $returnstatus = $storeRes;
                        }
                    }

                    if (count($salesOrders) == $limit && $pf_url) { // because 100 is the default page limit
                        $pf_url->update(['url' => $pageNo + 1]);
                    } else if (count($salesOrders) < $limit && $pf_url) { // if returned less than 100 record the set 1 in page counter to reverse the same flow from the begining
                        $pf_url->update(['url' => 1, 'status'=>0, 'url_filter'=>date('Y-m-d')]);
                        if($syncDateInfo){
                            $syncDateInfo->status = 0;
                            $syncDateInfo->save();
                        }
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getSalesOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Common function to store update Sales order information. This function is being called from two positions
     * 1st: Regural SO GET call "getSalesOrders"
     * 2nd: Backup SO GET call "getSalesOrdersBackupCall"
     */
    public function storeSalesOrderDetails($accountInfo, $userId, $userIntegrationId, $userWorkFlowRuleId, $salesOrders){
        $returnstatus = true;
        try {
            foreach ($salesOrders as $key => $so) {
                $api_order_id = (isset($so['Sales order no.']) && $so['Sales order no.']) ? $so['Sales order no.'] : NULL;
                if(!$api_order_id){
                    continue;
                }

                $dateTime = (isset($so['Requested delivery date']) && !empty($so['Requested delivery date'])) ? $so['Requested delivery date'] : null;
                $api_order_date = null;
                if ($dateTime) {
                    $currentYear = date("Y");

                    // Replace "YYYY" with current year
                    $api_order_date = str_replace("YYYY", $currentYear, $dateTime);

                    // Remove the single quotes surrounding the date string
                    $api_order_date = trim($api_order_date, "'");

                    // Create a DateTime object from the date string
                    $dateTime = DateTime::createFromFormat('d/m/Y H:i', $api_order_date);

                    if ($dateTime !== false) {
                        // Format the date to the desired format
                        $api_order_date = $dateTime->format('Y-m-d H:i:s');
                    }
                } else{
                    Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: order skipped - No Date value found: " . $api_order_id );
                    continue; // skip older order
                }

                $get_workflow_events = UserWorkflowRule::where('id', $userWorkFlowRuleId)->select('sync_start_date')->first();
                if ( isset( $get_workflow_events->sync_start_date ) ) {
                    $syncStartDate = date('Y-m-d H:i:s', strtotime($get_workflow_events->sync_start_date));

                    $dateTimeApiOrder = new \DateTime($api_order_date);
                    $dateTimeSyncStart = new \DateTime($syncStartDate);

                    if ($dateTimeApiOrder < $dateTimeSyncStart) {
                        Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: order skipped - Earlier order: " . $api_order_id );
                        continue; // skip older order
                    }
                }

                $soStatus = (isset($so['Status']) && $so['Status']) ? $so['Status'] : NULL;
                if($soStatus == "Cancelled"){
                    continue;
                }
                // date_default_timezone_set('UTC'); // currently not required for order_date
                $soData = [
                    "api_order_id" => $api_order_id,
                    "order_number" => $api_order_id,
                    "order_date" => date('Y-m-d H:i:s'),
                    "delivery_date" => $api_order_date,
                    "order_status" => $soStatus,
                ];

                $existing = PlatformOrder::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_order_id' => $api_order_id])->select('id', 'sync_status')->first();
                $pullLineItem = false;
                if ($existing) {
                    $pvxSoId = $existing->id;
                    $notToReUpdate = ['Synced','Inactive','Processing','Ignore']; // 'Failed',
                    if ( !in_array($existing->sync_status, $notToReUpdate) ) {
                        $soData['sync_status'] = 'Ready';
                        $existing->update($soData);
                        $pullLineItem = true;
                    }
                } else {
                    $soData['user_id'] = $userId;
                    $soData['user_integration_id'] = $userIntegrationId;
                    $soData['platform_id'] = $this->platformId;
                    $soData['order_type'] = 'SO';
                    $soData['sync_status'] = 'Ready';
                    $pvxSoId = PlatformOrder::create($soData)->id;
                    $pullLineItem = true;
                }

                if ($pvxSoId && $pullLineItem) {
                    /** Peoplevox orderline [start] */
                    $this->getSalesOrderLines($accountInfo, $api_order_id, $pvxSoId, $userIntegrationId);
                    /** Peoplevox orderline [end] */
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getSalesOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Function to get cancelled sales order information from Peoplevox platform using API call
     * The main purpose is to pull all cancelled order and cross check with the already existed salesOrder, if existed order ids not cancelled type then
     * make it to "Cancelled", so that it can update further the synced SalseOrder, so that the total SO count can be reduce from IP.
     *
     * This function GET call triggers in a loop, means if one circle of pagination is completed then it restart the GET api call again from the specified page number.
     * There is another logic in this function to manage a date pointer so that we don't read the whole page because in starting page numbers can have old SoalesOrder data.
     * So a number of old data are not for our use, thats why we use a that page number from where our required/filtered data started comming to our Database. So if page read
     * completed the our connector will start from that specific page number insteed starting from the scratch.
     */
    public function checkCancelledSalesOrders($userId, $userIntegrationId, $userWorkFlowRuleId){
        Storage::append('Peoplevox/' . $userIntegrationId . '/checkCancelledSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $pageNo = 1;
                $limit = 10;
                $pf_url = PlatformUrl::where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId, 'url_name' => 'cancelledSo_pageNo', 'status' => 1
                ])->select('id', 'url', 'status')->first();

                if ($pf_url && $pf_url->status == 1) {
                    $pageNo = $pf_url->url;
                } else {
                    $pf_url = new PlatformUrl();
                    $pf_url->user_id = $userId;
                    $pf_url->user_integration_id = $userIntegrationId;
                    $pf_url->platform_id = $this->platformId;
                    $pf_url->url_name = 'cancelledSo_pageNo';
                    $pf_url->url = $pageNo;
                    $pf_url->status = 1;
                    $pf_url->save();
                }

                $postDataReqFields = [
                    'method' => 'GetReportData',
                    'templateName' => 'Sales orders by status',
                    'orderBy' => 'Requested delivery date',
                    'orderType' => 'asc',
                    'searchField' => 'Status',
                    'searchTerm' => 'Cancelled',
                    'pageNo' => $pageNo,
                    'limit' => $limit
                ];

                $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                Storage::append('Peoplevox/' . $userIntegrationId . '/checkCancelledSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
                $response = static::makeAPICall($accountInfo, 'GetReportData', $postData);
                Storage::append('Peoplevox/' . $userIntegrationId . '/checkCancelledSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));

                if (isset($response['status_code']) && $response['status_code'] == 1) {
                    $salesOrders = $response['status_data'];
                    if (count($salesOrders)) {
                        foreach ($salesOrders as $so) {
                            $api_order_id = (isset($so['Sales order no.']) && $so['Sales order no.']) ? $so['Sales order no.'] : NULL;
                            if(!$api_order_id){
                                continue;
                            }

                            $dateTime = (isset($so['Requested delivery date']) && !empty($so['Requested delivery date'])) ? $so['Requested delivery date'] : null;
                            $api_order_date = null;
                            if ($dateTime) {
                                $currentYear = date("Y");

                                // Replace "YYYY" with current year
                                $api_order_date = str_replace("YYYY", $currentYear, $dateTime);

                                // Remove the single quotes surrounding the date string
                                $api_order_date = trim($api_order_date, "'");

                                // Create a DateTime object from the date string
                                $dateTime = DateTime::createFromFormat('d/m/Y H:i', $api_order_date);

                                if ($dateTime !== false) {
                                    // Format the date to the desired format
                                    $api_order_date = $dateTime->format('Y-m-d H:i:s');
                                }
                            } else{
                                Storage::append('Peoplevox/' . $userIntegrationId . '/checkCancelledSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: order skipped - No Date value found: " . $api_order_id );
                                continue; // skip older order
                            }

                            $get_workflow_events = UserWorkflowRule::where('id', $userWorkFlowRuleId)->select('sync_start_date')->first();
                            if ( isset( $get_workflow_events->sync_start_date ) ) {
                                $syncStartDate = date('Y-m-d H:i:s', strtotime($get_workflow_events->sync_start_date));

                                $dateTimeApiOrder = new \DateTime($api_order_date);
                                $dateTimeSyncStart = new \DateTime($syncStartDate);

                                if ($dateTimeApiOrder < $dateTimeSyncStart) {
                                    Storage::append('Peoplevox/' . $userIntegrationId . '/checkCancelledSalesOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: order skipped - Earlier order: " . $api_order_id );
                                    continue; // skip older order
                                }
                            }

                            $orderObj = PlatformOrder::where([
                                'user_integration_id' => $userIntegrationId,
                                'platform_id' => $this->platformId,
                                'api_order_id' => $api_order_id,
                                'is_voided' => 0
                            ])->where('sync_status', '!=', 'Synced')->select('id', 'is_voided', 'sync_status')->first();
                            if ($orderObj) {
                                $orderObj->is_voided = 1;
                                $orderObj->sync_status = 'Ready';
                                $orderObj->save();
                            }
                        }
                    }

                    if (count($salesOrders) == $limit && $pf_url) { // because 100 is the default page limit
                        $pf_url->url = $pageNo + 1;
                        $pf_url->save();
                    } else if (count($salesOrders) < $limit && $pf_url) { // if returned less than 100 record the set 1 in page counter to reverse the same flow from the begining
                        $pf_url->url = (int)$pf_url->url - 25;
                        $pf_url->save();
                    }
                } else {
                    $returnstatus = $response['status_data'];
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> checkCancelledSalesOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Function to get sales order's line-item information from Peoplevox platform using API call
     */
    public function getSalesOrderLines($accountInfo, $salesOrderNumber, $pvxSoId, $userIntegrationId)
    {
        try {
            $postDataReqFields = [
                'method' => 'GetData',
                'templateName' => 'Sales order items',
                'searchField' => 'SalesOrderNumber',
                'searchTerm' => $salesOrderNumber
            ];
            $soLineItemIds = [];
            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
            Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: so-line postdata: " . print_r($postData, true) );
            $response = static::makeAPICall($accountInfo, 'GetData', $postData);
            Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: so-line response: " . print_r($response, true) );
            if (isset($response['status_code']) && $response['status_code'] == 1) {
                $salesOrdersLines = $response['status_data'];
                if (count($salesOrdersLines)) {
                    foreach ($salesOrdersLines as $key => $line) {
                        $ItemCode = (isset($line['ItemCode']) && $line['ItemCode']) ? $line['ItemCode'] : NULL;

                        $lineData = [
                            "qty" => (isset($line['QuantityOrdered']) && $line['QuantityOrdered']) ? $line['QuantityOrdered'] : 0,
                            "price" => (isset($line['SalePrice']) && $line['SalePrice']) ? $line['SalePrice'] : 0,
                        ];

                        $existing = PlatformOrderLine::where(['platform_order_id' => $pvxSoId, 'api_product_id' => $ItemCode])->select('id')->first();
                        if ($existing) {
                            $existing->update($lineData);
                        } else {
                            $lineData['platform_order_id'] = $pvxSoId;
                            $lineData['api_product_id'] = $ItemCode;
                            PlatformOrderLine::create($lineData);
                        }

                        $soLineItemIds[] = $ItemCode;
                    }
                }
            }

            if( count($soLineItemIds) ){
                $isItemExist = $this->pullUnavailableItems($soLineItemIds, $pvxSoId, $userIntegrationId);
                Storage::append('Peoplevox/' . $userIntegrationId . '/getSalesOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: item pull method response: " . print_r( $isItemExist, true ) );
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getSalesOrderLines -> " . $e->getLine() . " -> " . $e->getMessage());
        }
    }

    /** ##### GET::Sales order [end] ##### */


    /** ##### GET::PO Receipt [start] ##### */

    /**
     * Function to get purchase order information from Peoplevox platform using API call
     */
    public function getPurchaseOrdersReceipts($userId, $userIntegrationId, $orderType)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrdersReceipts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnstatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $limit = 5;
                $pageNo = 1;

                $orderData = PlatformOrder::where([
                    'user_integration_id'=>$userIntegrationId,
                    'platform_id'=>$this->platformId,
                    'order_type'=>$orderType
                ])
                ->whereIn('order_type', ['PO', 'TO'])
                ->whereIn('shipment_status', ['Pending', 'Failed'])
                ->select('id', 'shipment_status', 'api_order_id', 'linked_id', 'updated_at')
                ->orderByRaw("CASE WHEN shipment_status = 'Pending' THEN '1'
                                WHEN shipment_status = 'Failed' THEN '2'
                            END, updated_at ASC") // CASE is using to prioritize PENDING status first
                ->limit($limit)->get();

                if( $orderData->isNotEmpty() ){
                    foreach ($orderData as $key => $order) {

                        $postDataReqFields = [
                            'method' => 'GetData',
                            'templateName' => 'Purchase orders',
                            'searchField' => 'PurchaseOrderNumber',
                            'searchTerm' => $order->api_order_id,
                            'pageNo' => $pageNo,
                            'limit' => 1
                        ];

                        $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                        Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrdersReceipts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r( $postData, true ) );
                        $response = static::makeAPICall($accountInfo, 'GetData', $postData);
                        Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrdersReceipts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r( $response, true ) );

                        if (isset($response['status_code']) && $response['status_code'] == 1) {
                            $purchaseOrders = $response['status_data'];
                            if (count($purchaseOrders)) {
                                foreach ($purchaseOrders as $key => $po) {
                                    // Make sortable based on updated_at date
                                    $order->updated_at = date('Y-m-d H:i:s');
                                    $order->save();

                                    if ( !isset($po['PurchaseOrderNumber']) ) {
                                        continue; // Invalid PO receipt
                                    }

                                    if (!isset($po['Status']) || (isset($po['Status']) && $po['Status'] != "Complete")) {
                                       continue;
                                    }

                                    $PurchaseOrderNumber = trim($po['PurchaseOrderNumber']);
                                    /*$sourceOrder = PlatformOrder::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                    'api_order_id' => $PurchaseOrderNumber, 'order_type' => $orderType])->select('id', 'shipment_status', 'api_order_id', 'linked_id')->first();*/

                                    if ($order && $order->linked_id) {
                                        $platformOrderShipmentId = null;
                                        $ShipmentData = [
                                            "order_id" => $PurchaseOrderNumber,
                                            "shipment_id" => $PurchaseOrderNumber,
                                            "order_status" => (isset($po['Status']) && $po['Status']) ? $po['Status'] : NULL,
                                            "type" => ($orderType == 'TO') ? 'Transfer' : 'Shipment'
                                        ];
                                        Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrdersReceipts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ShipmentData: " . print_r($ShipmentData, true));
                                        $destShipment = PlatformOrderShipment::where('platform_order_id', $order->linked_id)->select('id', 'linked_id')->first();
                                        if ($destShipment && $destShipment->linked_id > 0) {
                                            // Pick shipbob shipment id and sync status if it is linked with a destination shipment
                                            $sourceShipment = PlatformOrderShipment::where('id', $destShipment->linked_id)->select('id', 'sync_status')->first();
                                            if ($sourceShipment) {
                                                if ($sourceShipment->sync_status != 'Synced') {
                                                    $ShipmentData['sync_status'] = 'Ready';
                                                }
                                                Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrdersReceipts/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: update: " . print_r($ShipmentData, true));
                                                $sourceShipment->update($ShipmentData);
                                                $platformOrderShipmentId = $sourceShipment->id;
                                            }
                                        }

                                        if( !$platformOrderShipmentId ){
                                            $linked_id = 0;
                                            if ($destShipment) {
                                                // Set linked id if there is a destination shipment record exists
                                                $linked_id = $destShipment->id;
                                            }

                                            $ShipmentData += [
                                                'user_id' => $userId,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_order_id' => $order->id,
                                                'linked_id' => $linked_id,
                                                'sync_status' => 'Ready'
                                            ];

                                            $existingShipment = PlatformOrderShipment::where('platform_order_id', $order->id)->select('id', 'sync_status')->first();
                                            if( $existingShipment ){
                                                if( $existingShipment->sync_status != 'Synced' ){
                                                    $existingShipment->sync_status = 'Ready';
                                                    $existingShipment->save();
                                                }
                                                $platformOrderShipmentId = $existingShipment->id;
                                            } else{
                                                $sourceShipment = PlatformOrderShipment::create($ShipmentData);
                                                $platformOrderShipmentId = $sourceShipment->id;
                                            }

                                            if ($destShipment) {
                                                $destShipment->linked_id = $platformOrderShipmentId;
                                                $destShipment->save();
                                            }
                                        }

                                        if ($platformOrderShipmentId) {
                                            $order->shipment_status = 'Ready';
                                            $order->save();
                                            self::getPurchaseOrderLines($accountInfo, $po['PurchaseOrderNumber'], $platformOrderShipmentId, $userIntegrationId);
                                        }

                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getPurchaseOrdersReceipts -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Function to get purchase order's line-item information from Peoplevox platform using API call
     */
    private static function getPurchaseOrderLines($accountInfo, $purchaseOrderNumber, $pfOrdShipId, $userIntegrationId)
    {
        try {
            $postDataReqFields = [
                'method' => 'GetData',
                'templateName' => 'Purchase order items',
                'searchField' => 'PurchaseOrderNumber',
                'searchTerm' => $purchaseOrderNumber
            ];
            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
            Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
            $response = static::makeAPICall($accountInfo, 'GetData', $postData);
            Storage::append('Peoplevox/' . $userIntegrationId . '/getPurchaseOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));
            if (isset($response['status_code']) && $response['status_code'] == 1) {
                $salesOrdersLines = $response['status_data'];
                if (count($salesOrdersLines)) {
                    foreach ($salesOrdersLines as $key => $line) {
                        $ItemCode = (isset($line['ItemCode']) && $line['ItemCode']) ? $line['ItemCode'] : NULL;

                        $objShipLine = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $pfOrdShipId, 'product_id' => $ItemCode])->select('id')->first();
                        if ( !$objShipLine ) {
                            $objShipLine = new PlatformOrderShipmentLine();
                            $objShipLine->platform_order_shipment_id = $pfOrdShipId;
                            $objShipLine->product_id = $ItemCode;
                        }
                        $objShipLine->quantity = (isset($line['Quantity']) && $line['Quantity']) ? (int)$line['Quantity'] : 0;
                        $objShipLine->price = (isset($line['CostPrice']) && $line['CostPrice']) ? $line['CostPrice'] : 0;
                        $objShipLine->save();
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> getPurchaseOrderLines -> " . $e->getLine() . " -> " . $e->getMessage());
        }
    }

    /** ##### GET::PO Receipt [end] ##### */


    /** ##### MUTATE::Vendor [start] ##### */

    /**
     * Function to create or update vendor informations from Snowflake to Peoplevox using API call
     */
    public function createUpdateVendors($userId, $userIntegrationId, $accountInfo, $pfCustId)
    {
        $returnStatus = ['statusCode' => 0, 'statusData' => null];
        try {
            if ($accountInfo) {
                $supplier = PlatformCustomer::where('id', $pfCustId)
                    ->select('id', 'customer_name', 'api_customer_id', 'email', 'postal_addresses')->first();

                if ($supplier) {
                    $arrSupp[] = "Name,Reference,Email"; // CSV Header
                    $arrSupp[] = "{$supplier->email},{$supplier->api_customer_id},{$supplier->email}";

                    $csvSupplierData = implode("\n", $arrSupp);

                    if ($csvSupplierData) {
                        $postDataReqFields = [
                            'method' => 'SaveData',
                            'templateName' => 'Suppliers',
                            'csvData' => $csvSupplierData
                        ];

                        $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateVendors/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: post_data: " . print_r( $postData, true ) );
                        $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateVendors/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r( $response, true ) );
                        if (isset($response['status_code']) && $response['status_code'] == 1) {
                            $vendorResponseData = $response['status_data'];
                            if (count($vendorResponseData)) {
                                if (isset($vendorResponseData['Reference'])) { // Supplier successfully created
                                    $errorMsg = null;
                                    $link_response = static::manageVendorLinksInDB($userId, $userIntegrationId, $supplier->id, $vendorResponseData['Reference']);
                                    if ($link_response === true) { // returns the platform_customer_id as respponse
                                        $venAddrRes = static::createUpdateVendorAddress($accountInfo, $supplier->id);
                                        if ($venAddrRes !== true) {
                                            $errorMsg = $link_response;
                                        }
                                    } else {
                                        $errorMsg = $link_response;
                                    }

                                    return ['statusCode' => 1, 'statusData' => $supplier->api_customer_id, 'statusError' => $errorMsg];
                                }
                            }
                        } else {
                            $returnStatus = "Failed to sync, API call error or invalid xml format.";
                            if( $response && isset($response["status_data"]) ){
                                if (is_string($response["status_data"]) && str_contains(strtolower($response["status_data"]), 'session') ) {
                                    return "System : Security - Session Expired"; // In this case return the flow with this error, no need to set records sync status to failed.
                                } else{
                                    $returnStatus = $response['status_data'];
                                }
                            }
                            $returnStatus = ['statusCode' => 0, 'statusData' => $returnStatus];
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            $returnStatus = ['statusCode' => 0, 'statusData' => $e->getMessage()];
        }
        return $returnStatus;
    }

    /**
     * To create or update the supplier's information to handle response (after supplier sync).
     * After create/update this flow also perform linking of the supplier's row with the Snowflake supplier row for further identification.
     */
    private function manageVendorLinksInDB($userId, $userIntegrationId, $platformCustomerId, $reference)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateVendors/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: platformCustomerId: " . print_r( $platformCustomerId, true ) . " | reference: " . print_r( $reference, true ) );
        $returnStatus = true;
        try {
            $sourceVendorObj = PlatformCustomer::find($platformCustomerId);
            if (!$sourceVendorObj) {
                return 'Supplier information not found while linking supplier records.';
            }
            $vendordata = [
                'customer_name' => (isset($sourceVendorObj->customer_name) && $sourceVendorObj->customer_name) ? $sourceVendorObj->customer_name : NULL,
                'email' => (isset($sourceVendorObj->email) && $sourceVendorObj->email) ? $sourceVendorObj->email : NULL,
                'phone' => (isset($sourceVendorObj->phone) && $sourceVendorObj->phone) ? $sourceVendorObj->phone : NULL,
                'postal_addresses' => (isset($sourceVendorObj->postal_addresses) && $sourceVendorObj->postal_addresses) ? $sourceVendorObj->postal_addresses : NULL,
                'sync_status' => 'Synced'
            ];
            if ($sourceVendorObj->linked_id === 0) {
                $vendordata += [
                    'user_id' => $userId,
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId,
                    'type' => 'Vendor',
                    'api_customer_id' => $sourceVendorObj->api_customer_id,
                    'linked_id' => $sourceVendorObj->id,
                ];
                $destVendorObj = PlatformCustomer::create($vendordata);
                $sourceVendorObj->linked_id = $destVendorObj->id;
                Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateVendors/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: New vendor obj created: " . print_r( $destVendorObj->id, true ) );
            } else {
                $destVendorObj = PlatformCustomer::find($sourceVendorObj->linked_id)->update($vendordata);
                Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateVendors/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: vendor updated: " . print_r( $destVendorObj->id, true ) );
            }
            $sourceVendorObj->sync_status = 'Synced';
            $sourceVendorObj->save();
        } catch ( Exception $e ) {
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * To make another api call to sync vendor address on the basis of vendor reference number
     */
    private function createUpdateVendorAddress($accountInfo, $platformCustomerId)
    {
        $returnStatus = true;
        Storage::append('Peoplevox/commonDir/createUpdateVendorAddress/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        try {
            if ($accountInfo) {
                $vendorInfo = PlatformCustomer::find($platformCustomerId);
                if ($vendorInfo) {
                    $arrVendorAddr[] = "SupplierReference,AddressLine1"; // CSV Header
                    $arrVendorAddr[] = "{$vendorInfo->api_customer_id},{$vendorInfo->postal_addresses}";

                    $csvDataVendorAddr = implode("\n", $arrVendorAddr);

                    if ($csvDataVendorAddr) {
                        $postDataReqFields = [
                            'method' => 'SaveData',
                            'templateName' => 'Supplier addresses',
                            'csvData' => $csvDataVendorAddr
                        ];

                        $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                        Storage::append('Peoplevox/commonDir/createUpdateVendorAddress/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r($postData, true));
                        $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                        Storage::append('Peoplevox/commonDir/createUpdateVendorAddress/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r($response, true));
                        if (isset($response['status_code']) && $response['status_code'] == 0) {
                            $returnStatus = $response['status_data'];
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /** ##### MUTATE::Vendor [end] ##### */


    /** ##### MUTATE::Purchase order [start] ##### */

    /**
     * Function to create or update vendor informations from Snowflake to Peoplevox using API call
     */
    public function createUpdatePurchaseOrders($userId, $userIntegrationId, $sourcePlatformName, $userWorkflowRuleId, $platformWorkflowRuleId, $recordId = 0)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ###");
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $sourcePlatformId = $this->connectionHelper->getPlatformIdByName($sourcePlatformName);
                $objectId = $this->connectionHelper->getObjectId('purchase_order');
                if ($sourcePlatformId && $objectId) {
                    $orderType = 'PO';
                    $limit = 5;

                    //$selectFields = ['id', 'warehouse_id', 'api_order_id', 'order_status', 'api_order_reference', 'platform_customer_id'];
                    $queryPOrders = PlatformOrder::where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $sourcePlatformId,
                        'is_deleted' => 0, // This is basically accept only not deleted purchase orders
                        'order_type' => $orderType
                    ]);
                    if ($recordId) {
                        $queryPOrders->where(['id' => $recordId, 'sync_status' => 'Failed']); //
                    } else {
                        $queryPOrders->where('sync_status', 'Ready');
                    }
                    $platformPOrders = $queryPOrders->select('id', 'warehouse_id', 'api_order_id', 'order_status', 'api_order_reference', 'platform_customer_id')->limit($limit)->orderBy('platform_order.updated_at', 'ASC')->get();

                    if ($platformPOrders && count($platformPOrders)) { // check if there are vendor to sync
                        $poBasicInfo = $procesedIds = $POMissingWhMap = $POMissingSupp = [];

                        //$arrPOrders[] = "PurchaseOrderNumber,Status,Reference,Supplier,AddressLine1,Site"; // CSV Header
                        $arrPOrders[] = "PurchaseOrderNumber,Status,Reference,SupplierReference,AddressLine1,Site"; // CSV Header

                        foreach ($platformPOrders as $po) { // loop the purchase orders
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Pf po id processed: " . $po->id . " | order number: " . $po->api_order_id );
                            // Get mapped warehouse id
                            $warehouseObj = PlatformObjectData::where('id', $po->warehouse_id)->select('api_id')->first();
                            $warehouseId = null;
                            if($warehouseObj){
                                $warehouseId = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, 0, "inventory_warehouse", ['api_id'], 'regular', $warehouseObj->api_id);
                            }
                            if ($warehouseId) {
                                $destWarehouseId = $warehouseId->api_id;
                            } else {
                                $POMissingWhMap[] = $po->id; // Ignore the PO if WarehouseMap not found
                                continue;
                            }

                            $supplierReference = null;
                            if ($po->platform_customer_id) {
                                $srcSuppInfo = PlatformCustomer::where('id', $po->platform_customer_id)->select('id', 'linked_id')->first();
                                if ($srcSuppInfo && $srcSuppInfo->linked_id > 0) {
                                    $destSuppInfo = PlatformCustomer::where('id', $srcSuppInfo->linked_id)->select('id', 'api_customer_id')->first();
                                    if ($destSuppInfo) {
                                        $supplierReference = $destSuppInfo->api_customer_id;
                                    }
                                } else if ($srcSuppInfo && $srcSuppInfo->linked_id === 0) {
                                    $vendorResponse = $this->createUpdateVendors($userId, $userIntegrationId, $accountInfo, $srcSuppInfo->id);
                                    Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: supplier create response: " . print_r( $vendorResponse, true ) );
                                    if ($vendorResponse && isset($vendorResponse['statusCode']) && $vendorResponse['statusCode'] == 1) {
                                        $supplierReference = $vendorResponse['statusData'];
                                    } else {
                                        $errorVendorSync = "Vendor sync error, account credentials or something missing in request data.";
                                        if (isset($vendorResponse['statusData'])) {
                                            $errorVendorSync = $vendorResponse['statusData'];
                                        }
                                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Vendor sync error: " . print_r( $errorVendorSync, true ) );
                                        $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $errorVendorSync, [$po->id]);
                                    }
                                }
                            } else {
                                $POMissingSupp[] = $po->id; // Ignore the PO if supplier id not set
                                continue;
                            }

                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: supplierReference: " . print_r( $supplierReference, true ) );
                            if (!$supplierReference) {
                                $POMissingSupp[] = $po->id;
                                continue; // If supplierReference not fount means supplier is not yet synced in PVX
                            }

                            // Get vendor's address
                            $billing_address = static::fetchOrderAddress($po->id, 'billing');
                            $billing_address = str_replace(',', "|", $billing_address);
                            //$arrPOrders[] = "{$po->api_order_id},{$po->order_status},{$po->api_order_reference},{$supplierReference},{$billing_address},{$destWarehouseId}";
                            //$arrPOrders[] = "{$po->api_order_id},{$po->order_status},{$po->api_order_reference},{$supplierReference},{$billing_address}";
                            $arrPOrders[] = "{$po->api_order_reference},{$po->order_status},{$po->api_order_id},{$supplierReference},{$billing_address},{$destWarehouseId}";

                            /**
                             * Array to use just after syncing vendor basic information,
                             * API response returns PurchaseOrderNumber of PO if successful sync,
                             * We need to pass this Reference as a Foreign key to sync address of those purchase orders.
                             * */
                            array_push($poBasicInfo, [
                                "platform_order_id" => $po->id,
                                //"api_order_id" => $po->api_order_id,
                                "api_order_id" => $po->api_order_reference,
                            ]);
                            // Array to track record of process vendor list, in case failure it can be set failed using these id set.
                            $procesedIds[] = $po->id;
                        }

                        $csvDataPO = implode("\n", $arrPOrders);

                        if ($csvDataPO && count($arrPOrders) > 1) {
                            $postDataReqFields = [
                                'method' => 'SaveData',
                                'templateName' => 'Purchase orders',
                                'csvData' => $csvDataPO
                            ];

                            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r( $postData, true ) );
                            $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r( $response, true ) );

                            if (isset($response['status_code']) && $response['status_code'] == 1) {
                                $poResponseData = $response['status_data'];
                                if ($poResponseData && count($poResponseData)) {
                                    $handleResponse = self::postApiResponseHandling($accountInfo, $userId, $userIntegrationId, $orderType, $poBasicInfo, $poResponseData);
                                    Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: handleResponse: " . print_r( $handleResponse, true ) );
                                    if ($handleResponse === true) {
                                        $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, 'success', $procesedIds);
                                    } else{
                                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postApiResponseHandling fail: " . print_r( $handleResponse, true ) );
                                        $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $handleResponse, $procesedIds);
                                    }
                                }
                            } else {
                                if ($response && isset($response["status_data"]) && is_string($response["status_data"]) && str_contains(strtolower($response["status_data"]), 'session') ) {
                                    return "System : Security - Session Expired"; // In this case return the flow with this error, no need to set records sync status to failed.
                                }
                                Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: makeAPICall fail (else part): " . print_r( $handleResponse, true ) );
                                $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $procesedIds);
                            }
                        }

                        if (count($POMissingWhMap)) {
                            $response = "Warehouse mapping not found.";
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $POMissingWhMap);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Warehouse mapping not found: " . print_r( $POMissingWhMap, true ) );
                        }

                        if (count($POMissingSupp)) {
                            $response = "Supplier info not synced in PVX or supplier info not reveived in Purchase order.";
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $POMissingSupp);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePurchaseOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Supplier info not synced: " . print_r( $POMissingSupp, true ) );
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> createUpdatePurchaseOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /** ##### MUTATE::Purchase order [end] ##### */


    /** ##### MUTATE::Transfer order [start] ##### */

    /**
     * Function to create or update transfer order from Snowflake to Peoplevox using API call
     */
    public function createUpdateSOForTransferOrders($userId, $userIntegrationId, $sourcePlatformName, $userWorkflowRuleId, $platformWorkflowRuleId, $recordId = 0)
    {
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $sourcePlatformId = $this->connectionHelper->getPlatformIdByName($sourcePlatformName);
                $objectId = $this->connectionHelper->getObjectId('transfer_order');

                if ($sourcePlatformId && $objectId) {
                    $orderType = 'TO';
                    $limit = 40;
                    $queryTOrders = PlatformOrder::where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $sourcePlatformId,
                        'is_deleted' => 0, // This is basically accept only not deleted purchase orders
                        'order_type' => $orderType
                    ]);
                    if ($recordId) {
                        $queryTOrders->where(['platform_order.id' => $recordId, 'platform_order.sync_status' => 'Failed']);
                    } else {
                        $queryTOrders->where('platform_order.sync_status', 'Ready');
                    }
                    $platformTOrders = $queryTOrders->limit($limit)->orderBy('platform_order.updated_at', 'ASC')->get();

                    if ((is_array($platformTOrders) || is_object($platformTOrders)) && count($platformTOrders)) { // check if there are vendor to sync
                        $soBasicInfo = $procesedIds = $SOMissingWhMap = [];

                        /** To create a Transfer order in Peoplevox a sales order has to be created first to hold an stock from the inventory.
                         * Then a purchase order is created to fill the minus stock in the inventory.
                         * So we are creating SO first. */
                        //$arrPOrders[] = "SalesOrderNumber,CustomerPurchaseOrderReferenceNumber,ShippingAddressLine1,InvoiceAddressLine,CreatedDate,RequestedDeliveryDate,Site"; // CSV Header
                        $arrPOrders[] = "SalesOrderNumber,CustomerPurchaseOrderReferenceNumber,CreatedDate,RequestedDeliveryDate,Site"; // CSV Header
                        foreach ($platformTOrders as $to) { // loop the purchase orders
                            // Get Order shipment information
                            $ordShipment = PlatformOrderShipment::where('platform_order_id', $to->id)->first();
                            if ($ordShipment) {
                                $warehouseObj = PlatformObjectData::where('id', $ordShipment->warehouse_id)->select('api_id')->first();
                                $warehouseId = null;
                                if($warehouseObj){
                                    $warehouseId = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, 0, "inventory_warehouse", ['api_id'], 'regular', $warehouseObj->api_id);
                                }
                                // Get mapped warehouse id
                                $sourceWarehouseId = null;
                                if ($warehouseId) {
                                    $sourceWarehouseId = $warehouseId->api_id;
                                } else {
                                    $SOMissingWhMap[] = $to->id;
                                    continue;
                                }

                                // Get order address
                                //$billing_address = static::fetchOrderAddress($to->id, 'billing');
                                //$shipping_address = static::fetchOrderAddress($to->id, 'shipping');

                                $order_date = date('Y-m-d H:m:s');//date('Y-m-d H:m:s', strtotime($to->order_date));
                                $delivery_date = date('Y-m-d H:m:s');//date('Y-m-d H:m:s', strtotime($to->delivery_date));
                                //$billing_address = str_replace(',',"|",$billing_address);

                                //$arrPOrders[] = "{$to->api_order_id},{$to->api_order_id},{$order_date},{$delivery_date},{$sourceWarehouseId}";
                                $arrPOrders[] = "{$to->api_order_reference},{$to->api_order_id},{$order_date},{$delivery_date},{$sourceWarehouseId}";

                                /**
                                 * Array to use just after syncing transfer order information,
                                 * API response returns SalesOrderNumber of to if successful sync,
                                 * We need to pass this Reference as a Foreign key to sync address of those purchase orders.
                                 * */
                                array_push($soBasicInfo, [
                                    "platform_order_id" => $to->id,
                                    //"api_order_id" => $to->api_order_id,
                                    "api_order_id" => $to->api_order_reference,
                                ]);
                            }

                            // Array to track record of processed TO list, in case failure it can be set failed using these id set.
                            $procesedIds[] = $to->id;
                        }
                        $csvDataSO = implode("\n", $arrPOrders);

                        if ( count($arrPOrders) > 1 ) { // If contains value, not only the headers
                            $postDataReqFields = [
                                'method' => 'SaveData',
                                'templateName' => 'Sales orders',
                                'csvData' => $csvDataSO
                            ];

                            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateSOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: post data: " . print_r( $postData, true ) );
                            $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateSOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r( $response, true ) );
                            if (isset($response['status_code']) && $response['status_code'] == 1) {
                                $soResponseData = $response['status_data'];
                                if ((is_array($soResponseData) || is_object($soResponseData)) && count($soResponseData)) {
                                    $handleResponse = self::postApiResponseHandling($accountInfo, $userId, $userIntegrationId, $orderType, $soBasicInfo, $soResponseData);
                                    if ($handleResponse === true) {
                                        $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, 'success', $procesedIds);
                                    } else{
                                        $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $handleResponse, $procesedIds);
                                    }
                                }
                            } else {
                                if ($response && isset($response["status_data"]) && is_string($response["status_data"]) && str_contains(strtolower($response["status_data"]), 'session') ) {
                                    return "System : Security - Session Expired"; // In this case return the flow with this error, no need to set records sync status to failed.
                                }
                                $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $procesedIds);
                            }
                        } else{
                            $response = "There is no valid data found to create sales order (against Transfer Order)";
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $procesedIds);
                        }

                        if (count($SOMissingWhMap)) {
                            $response = "Warehouse mapping not found or source warehouse not found while creating Sales order (for TO).";
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $SOMissingWhMap);
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> createUpdateSOForTransferOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * Function to create or update transfer order from Snowflake to Peoplevox using API call
     */
    public function createUpdatePOForTransferOrders($userId, $userIntegrationId, $sourcePlatformName, $userWorkflowRuleId, $platformWorkflowRuleId, $recordId = 0)
    {
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $sourcePlatformId = $this->connectionHelper->getPlatformIdByName($sourcePlatformName);
                $objectId = $this->connectionHelper->getObjectId('transfer_order');

                if ($sourcePlatformId && $objectId) {
                    $orderType = 'TO';
                    $limit = 40;
                    $queryPOrders = PlatformOrder::where([
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $sourcePlatformId,
                        'is_deleted' => 0, // This is basically accept only not deleted purchase orders
                        'order_type' => $orderType
                    ]);
                    if ($recordId) {
                        $queryPOrders->where(['id' => $recordId, 'sync_status' => 'Failed']);
                    } else {
                        $queryPOrders->where('sync_status', 'Synced')->where('linked_id', '!=', 0)->whereNull('notes');
                    }
                    $platformPOrders = $queryPOrders->limit($limit)->orderBy('updated_at', 'ASC')->get();

                    if ( $platformPOrders->isNotEmpty() ) { // check if there are vendor to sync
                        $poBasicInfo = $procesedIds = $POMissingSupp = $POMissingWh = [];

                        $defaultSupplierRef = NULL;
                        $defaultSupplierMap = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, null, "default_vendor",  ['api_id']);
                        if ($defaultSupplierMap) {
                            $defaultSupplierRef = $defaultSupplierMap->api_id;
                        }

                        $arrPOrders[] = "PurchaseOrderNumber,Status,Reference,SupplierReference,AddressLine1,Site"; // CSV Header
                        foreach ($platformPOrders as $po) { // loop the purchase orders
                            // Get Order shipment information
                            $ordShipment = PlatformOrderShipment::where('platform_order_id', $po->id)->first();
                            if ($ordShipment) {
                                $warehouseObj = PlatformObjectData::where('id', $ordShipment->to_warehouse_id)->select('api_id')->first();
                                $warehouseId = null;
                                if($warehouseObj){
                                    $warehouseId = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, 0, "inventory_warehouse", ['api_id'], 'regular', $warehouseObj->api_id);
                                }

                                // Get mapped warehouse id
                                $destWarehouseId = null;
                                if ($warehouseId) {
                                    $destWarehouseId = $warehouseId->api_id;
                                } else {
                                    $POMissingWh[] = $po->id; // Ignore the TO if dest. warehouse id not set
                                    continue;
                                }

                                // Get vendor's address
                                $billing_address = static::fetchOrderAddress($po->id, 'billing');
                                $billing_address = str_replace(',', "|", $billing_address);

                                $arrPOrders[] = "{$po->api_order_reference},{$po->order_status},{$po->api_order_id},{$defaultSupplierRef},{$billing_address},{$destWarehouseId}";
                                /**
                                 * Array to use just after syncing vendor basic information,
                                 * API response returns PurchaseOrderNumber of PO if successful sync,
                                 * We need to pass this Reference as a Foreign key to sync address of those purchase orders.
                                 * */
                                array_push($poBasicInfo, [
                                    "platform_order_id" => $po->id,
                                    "platform_order_shipment_id" => $ordShipment->id,
                                    //"api_order_id" => $po->api_order_id,
                                    "api_order_id" => $po->api_order_reference,
                                ]);
                            }
                            // Array to track record of process vendor list, in case failure it can be set failed using these id set.
                            $procesedIds[] = $po->id;
                        }
                        $csvDataPO = implode("\n", $arrPOrders);

                        if ( count($arrPOrders) > 1 ) {
                            $postDataReqFields = [
                                'method' => 'SaveData',
                                'templateName' => 'Purchase orders',
                                'csvData' => $csvDataPO
                            ];

                            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData: " . print_r( $postData, true ) );
                            $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response: " . print_r( $response, true ) );
                            if (isset($response['status_code']) && $response['status_code'] == 1) {
                                $poResponseData = $response['status_data'];
                                if ((is_array($poResponseData) || is_object($poResponseData)) && count($poResponseData)) {
                                    if (isset($poResponseData['Reference'])) { // if single response data
                                        $platformOrderId = $platformOrderShipId = null;
                                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: single response - poBasicInfo: " . print_r($poBasicInfo, true) );
                                        $arrKeys = static::searchForKeys($poBasicInfo, "api_order_id", $poResponseData['Reference']);
                                        if (count($arrKeys)) {
                                            $platformOrderId = $poBasicInfo[$arrKeys[0]]['platform_order_id'];
                                            $platformOrderShipId = $poBasicInfo[$arrKeys[0]]['platform_order_shipment_id'];
                                        }
                                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: arrKeys: " . print_r($arrKeys, true) . " | " . print_r($platformOrderId, true) . " | " . print_r($platformOrderShipId, true));
                                        if ($platformOrderId && $platformOrderShipId) { // returns the platform_order_id as respponse
                                            PlatformOrder::where([ 'id'=>$platformOrderId ])->update([ 'notes'=>'SO and PO created' ]);
                                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: createUpdatePOShipmentLines calling" );
                                            $this->createUpdatePOShipmentLines($accountInfo, $platformOrderShipId, $poResponseData['Reference'], $userIntegrationId);
                                        }
                                    } else {
                                        foreach ($poResponseData as $apiPO) { // if multiple response data
                                            if (!isset($apiPO['Reference'])) {
                                                continue;
                                            }

                                            $platformOrderId = $platformOrderShipId = null;
                                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: multi response - poBasicInfo: " . print_r($poBasicInfo, true) );
                                            $arrKeys = static::searchForKeys($poBasicInfo, "api_order_id", $apiPO['Reference']);
                                            if (count($arrKeys)) {
                                                $platformOrderId = $poBasicInfo[$arrKeys[0]]['platform_order_id'];
                                                $platformOrderShipId = $poBasicInfo[$arrKeys[0]]['platform_order_shipment_id'];
                                            }
                                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: arrKeys: " . print_r($arrKeys, true) . " | " . print_r($platformOrderId, true) . " | " . print_r($platformOrderShipId, true));
                                            if ($platformOrderId && $platformOrderShipId) { // returns the platform_order_id as respponse
                                                PlatformOrder::where([ 'id'=>$platformOrderId ])->update([ 'notes'=>'SO and PO created' ]);
                                                Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: createUpdatePOShipmentLines calling" );
                                                $this->createUpdatePOShipmentLines($accountInfo, $platformOrderShipId, $apiPO['Reference'], $userIntegrationId);
                                            }
                                        }
                                    }
                                    Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: po line sync complete");
                                    $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, 'success', $procesedIds);
                                }
                            } else {
                                if ($response && isset($response["status_data"]) && is_string($response["status_data"]) && str_contains(strtolower($response["status_data"]), 'session') ) {
                                    return "System : Security - Session Expired"; // In this case return the flow with this error, no need to set records sync status to failed.
                                }
                                Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: makeAPICall fail (else part)" . print_r($response, true));
                                $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $procesedIds);
                            }
                        } else{
                            $response = "There is no valid data found to create purchase order (against Transfer Order)";
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOForTransferOrders/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: invalid data" . print_r($response, true));
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $procesedIds);
                        }

                        if (count($POMissingSupp)) {
                            $response = "Supplier info not synced in PVX or supplier info not reveived in Purchase order.";
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $POMissingSupp);
                        }

                        if (count($POMissingWh)) {
                            $response = "Destination warehouse not set or not mapped in warehouse mapping.";
                            $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $POMissingWh);
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> createUpdatePOForTransferOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        Storage::append('zyx_to_po_sync_log.txt', 'returnStatus: ' . print_r( $returnStatus, true ) );
        return $returnStatus;
    }

    /** ##### MUTATE::Transfer order [end] ##### */


    /** ##### Other required methods [start] ##### */

    /** API response handler */
    private function postApiResponseHandling($accountInfo, $userId, $userIntegrationId, $orderType, $ordBasicInfo, $ordResponseData)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/postApiResponseHandling/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: userIntegrationId" . print_r($userIntegrationId, true) . " | ordBasicInfo" . print_r($ordBasicInfo, true) . " | ordResponseData" . print_r($ordResponseData, true));
        $returnStatus = true;
        try {
            if (isset($ordResponseData['Reference'])) { // if single response data
                $link_response = static::manageOrderRowLinking($userId, $userIntegrationId, $ordBasicInfo, $ordResponseData['Reference'], $orderType);
                Storage::append('Peoplevox/' . $userIntegrationId . '/postApiResponseHandling/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: link_response" . print_r($link_response, true) );

                if ($link_response && is_array($link_response)) { // returns the platform_order_id as respponse
                    static::createUpdateOrderLines($accountInfo, $link_response, $ordResponseData['Reference'], $orderType);
                } else {
                    $returnStatus = $link_response;
                }
            } else {
                foreach ($ordResponseData as $apiPO) { // if multiple response data
                    if (!isset($apiPO['Reference'])) {
                        continue;
                    }

                    $link_response = static::manageOrderRowLinking($userId, $userIntegrationId, $ordBasicInfo, $apiPO['Reference'], $orderType);
                    if ($link_response && is_array($link_response)) { // returns the platform_order_id as respponse
                        static::createUpdateOrderLines($accountInfo, $link_response, $apiPO['Reference'], $orderType);
                    } else {
                        $returnStatus = $link_response;
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> postApiResponseHandling -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        Storage::append('Peoplevox/' . $userIntegrationId . '/postApiResponseHandling/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postApiResponseHandling return" . print_r($returnStatus, true) );
        return $returnStatus;
    }

    /**
     * To create or update the Transfer Order's information to handle response (after PO sync [if Transfer order]).
     * After create/update this flow also perform linking of the purchase order row with the Snowflake PO row for further identification.
     */
    private function manageOrderRowLinking($userId, $userIntegrationId, $soBasicInfo, $apiOrderId, $orderType = 'PO')
    {
        $returnStatus = null;
        // In api response vendor name comes in Reference field for identification
        try {
            // In api response vendor name comes in Reference field for identification
            $platformOrderId = null;
            $arrKeys = static::searchForKeys($soBasicInfo, "api_order_id", $apiOrderId);
            if (count($arrKeys)) {
                $platformOrderId = $soBasicInfo[$arrKeys[0]]['platform_order_id'];
            } else {
                return 'Api response user information is not matching with the processed order details.';
            }
            if ($platformOrderId) {
                $sourceOrd = PlatformOrder::find($platformOrderId);
                if (!$sourceOrd) {
                    return 'Order information not found.';
                }

                $orderData = [
                    'order_number' => $apiOrderId,
                    'api_order_reference' => $sourceOrd->api_order_id ?? NULL, //$sourceOrd->api_order_reference ?? NULL,
                    'user_workflow_rule_id' => $sourceOrd->user_workflow_rule_id ?? NULL,
                    'delivery_date' => $sourceOrd->delivery_date ?? NULL,
                    'order_date' => $sourceOrd->order_date ?? NULL,
                ];

                $destPlatformOrderId = null;
                if ($sourceOrd->linked_id === 0) {
                    $orderData += [
                        'user_id' => $userId,
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                        'order_type' => $orderType,
                        'api_order_id' => $apiOrderId,
                        'linked_id' => $sourceOrd->id,
                    ];
                    $destOrd = PlatformOrder::create($orderData);
                    $sourceOrd->linked_id = $destPlatformOrderId = $destOrd->id;
                } else {
                    PlatformOrder::find($sourceOrd->linked_id)->update($orderData);
                    $destPlatformOrderId = $sourceOrd->linked_id;
                }
                $sourceOrd->sync_status = 'Synced';
                $sourceOrd->save();

                // Link order shipment and update sync status
                $destShipmentId = $sourceShipmentId = null;
                if ($orderType == 'TO') {
                    $sourceShip = PlatformOrderShipment::where('platform_order_id', $platformOrderId)->select('id', 'linked_id')->first();
                    if ($sourceShip && $destPlatformOrderId) {
                        if ($sourceShip->linked_id === 0) {
                            $shipData = [
                                'user_id' => $userId,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $userIntegrationId,
                                'order_id' => $apiOrderId,
                                'type' => 'Transfer',
                                'linked_id' => $sourceShip->id,
                                'platform_order_id' => $destPlatformOrderId,
                                // other columns
                                'row_id' => $sourceShip->row_id,
                                'barcode' => $sourceShip->barcode,
                                'warehouse_id' => $sourceShip->warehouse_id,
                                'quantity' => $sourceShip->quantity
                            ];
                            $destOrd = PlatformOrderShipment::create($shipData);
                            $sourceShip->linked_id = $destShipmentId = $destOrd->id;
                        } else {
                            $destShipmentId = $sourceShip->linked_id;
                        }

                        //$sourceShip->sync_status = 'Synced';
                        $sourceShip->save();
                        $sourceShipmentId = $sourceShip->id;
                    }
                }

                $returnStatus = [
                    "user_id" => $userId,
                    "user_integration_id" => $userIntegrationId,
                    "platform_id" => $this->platformId,
                    "sourcePfOrderId" => $platformOrderId,
                    "sourceShipmentId" => $sourceShipmentId,
                    "destShipmentId" => $destShipmentId
                ];
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> manageOrderRowLinking -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * To create or update the Purchase Order line information.
     */
    public function createUpdateOrderLines($accountInfo, $arrPlatformIds, $orderReference, $orderType)
    {
        $returnStatus = true;
        try {
            if ($accountInfo) {
                $srcPfOrderId = $arrPlatformIds['sourcePfOrderId'];
                $srcPfShipmentId = $arrPlatformIds['sourceShipmentId'];
                $destPfShipmentId = $arrPlatformIds['destShipmentId'];
                $userId = $arrPlatformIds['user_id'];
                $userIntegrationId = $arrPlatformIds['user_integration_id'];
                $pvxPlatformId = $arrPlatformIds['platform_id'];

                if ($orderType == 'PO') {
                    $platformLines = PlatformOrderLine::where('platform_order_id', $srcPfOrderId)->get();
                } else {
                    $platformLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $srcPfShipmentId)->get();
                }

                if (count($platformLines)) {
                    $arrProcessedIds = [];

                    if ($orderType == 'PO') {
                        $arrShipLines[] = "PurchaseOrderNumber,ItemCode,Quantity,CostPrice"; // CSV Header
                        foreach ($platformLines as $ordLine) { // loop the orders
                            $arrProcessedIds[] = $ordLine->id;
                            // check if api_product_id is not set or invalid value
                            $itemCode = null;
                            if( !$ordLine->api_product_id || $ordLine->api_product_id == 'non_exiting' ){
                                if( $ordLine->barcode ){
                                    $itemInfo = PlatformProduct::where([ 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$pvxPlatformId, 'barcode'=>$ordLine->barcode ])
                                    ->select('id', 'api_product_id')->first();
                                    if( $itemInfo ){
                                        $itemCode = $itemInfo->api_product_id;
                                    }
                                }
                            } else{
                                $itemCode = $ordLine->api_product_id;
                            }

                            if( !$itemCode ){
                                continue;
                            }
                            $arrShipLines[] = "{$orderReference},{$itemCode},{$ordLine->qty},{$ordLine->price}";
                        }
                    } else {
                        $arrShipLines[] = "SalesOrderNumber,ItemCode,QuantityOrdered,SalePrice"; // CSV Header
                        foreach ($platformLines as $shipLine) { // loop the orders
                            $arrProcessedIds[] = $shipLine->id;
                            // check if product_id is not set or invalid value
                            $itemCode = null;
                            if( !$shipLine->product_id || $shipLine->product_id == 'non_exiting' ){
                                if( $shipLine->barcode ){
                                    $itemInfo = PlatformProduct::where([ 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$pvxPlatformId, 'barcode'=>$shipLine->barcode ])
                                    ->select('id', 'api_product_id')->first();
                                    if( $itemInfo ){
                                        $itemCode = $itemInfo->api_product_id;
                                    }
                                }
                            } else{
                                $itemCode = $shipLine->product_id;
                            }
                            if( !$itemCode ){
                                continue;
                            }
                            $arrShipLines[] = "{$orderReference},{$itemCode},{$shipLine->quantity},{$shipLine->price}";
                        }
                    }

                    if( count($arrShipLines) > 1){
                        $csvDataShipLines = implode("\n", $arrShipLines);

                        if ($csvDataShipLines) {
                            $postDataReqFields = [
                                'method' => "SaveData",
                                'templateName' => "Sales order items",
                                'csvData' => $csvDataShipLines
                            ];

                            if ($orderType == 'PO') {
                                $postDataReqFields['templateName'] = "Purchase order items";
                            }

                            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postdata" . print_r($postData, true) );
                            $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                            Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdateOrderLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response" . print_r($response, true) );
                            if (isset($response['status_code']) && $response['status_code'] == 1) {
                                $ordLineResData = $response['status_data'];
                                if (count($ordLineResData)) {
                                    $synced = false;
                                    if (isset($ordLineResData['Reference'])) { // if single response data
                                        $synced = true;
                                    } else {
                                        foreach ($ordLineResData as $apiPoLine) { // if multiple response data
                                            if (isset($apiPoLine['Reference'])) {
                                                $synced = true;
                                            }
                                        }
                                    }
                                    if ($synced && count($arrProcessedIds) && $orderType == 'TO') {
                                        foreach ($arrProcessedIds as $shipLineId) {
                                            $sourceShipLineObj = PlatformOrderShipmentLine::find($shipLineId);
                                            if ($sourceShipLineObj) {
                                                // Get Peoplevox product detail on the basis of "Barcode"
                                                $product_id = $barcode = null;
                                                if ($sourceShipLineObj->barcode) {
                                                    $destProdInfo = PlatformProduct::where([
                                                        "user_integration_id" => $userIntegrationId,
                                                        "platform_id" => $pvxPlatformId,
                                                        "barcode" => $sourceShipLineObj->barcode
                                                    ])->select('id', 'api_product_id')->first();
                                                    if ($destProdInfo) {
                                                        $product_id = $destProdInfo->api_product_id;
                                                    }
                                                }

                                                $destShipLine = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $destPfShipmentId, 'product_id' => $product_id]);
                                                if (!$destShipLine) {
                                                    $lineData = [
                                                        'platform_order_shipment_id' => $destPfShipmentId,
                                                        'product_id' => $product_id,
                                                        'barcode' => $barcode,
                                                        'quantity' => $sourceShipLineObj->quantity,
                                                        'sent_quantity' => 0,
                                                        'currency' => $sourceShipLineObj->currency,
                                                        'sync_status' => 'Synced'
                                                    ];
                                                    PlatformOrderShipmentLine::create($lineData);
                                                } else {
                                                    $destShipLine->sync_status = 'Synced';
                                                    $destShipLine->save();
                                                }
                                                Storage::append('zyx_trans_ord_sync_log.txt', 'shipment line inserted');
                                            }
                                        }
                                    }
                                }
                            } else {
                                $returnStatus = isset($response['status_data']) ? $response['status_data'] : 'Api call error!';
                            }
                        }
                    } else{
                        $returnStatus = "Order line not set or it is not containing required value to sync the line-item information.";
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> createUpdateOrderLines -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    public function createUpdatePOShipmentLines($accountInfo, $srcPlatformShipId, $orderReference, $userIntegrationId)
    {
        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOShipmentLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ### called ##" );
        $returnStatus = true;
        try {
            if ($accountInfo) {
                $platformShipLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $srcPlatformShipId)->get();

                if (count($platformShipLines)) {
                    $arrProcessedIds = [];
                    $arrShipLines[] = "PurchaseOrderNumber,ItemCode,Quantity,CostPrice"; // CSV Header
                    foreach ($platformShipLines as $shipLine) { // loop the orders
                        $arrProcessedIds[$shipLine->id] = $shipLine->quantity;
                        $arrShipLines[] = "{$orderReference},{$shipLine->product_id},{$shipLine->quantity},{$shipLine->price}";
                    }
                    $csvDataShipLines = implode("\n", $arrShipLines);
                    Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOShipmentLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: csvDataShipLines: " . print_r($csvDataShipLines, true));
                    if ($csvDataShipLines) {
                        $postDataReqFields = [
                            'method' => "SaveData",
                            'templateName' => "Purchase order items",
                            'csvData' => $csvDataShipLines
                        ];

                        $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOShipmentLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: postData" . print_r($postData, true) );
                        $response = static::makeAPICall($accountInfo, 'SaveData', $postData);
                        Storage::append('Peoplevox/' . $userIntegrationId . '/createUpdatePOShipmentLines/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: response" . print_r($response, true) );
                        if (isset($response['status_code']) && $response['status_code'] == 1) {
                            /*$ordLineResData = $response['status_data'];
                            if (count($ordLineResData)) {
                                $synced = false;
                                if (isset($ordLineResData['Reference'])) { // if single response data
                                    $synced = true;
                                } else {
                                    foreach ($ordLineResData as $apiPoLine) { // if multiple response data
                                        if (isset($apiPoLine['Reference'])) {
                                            $synced = true;
                                        }
                                    }
                                }
                            }*/
                        } else {
                            $returnStatus = isset($response['status_data']) ? $response['status_data'] : 'Api call error!';
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($userIntegrationId . " -> PeoplevoxApiController -> createUpdatePOShipmentLines -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * To get the Purchase order billing address.
     */
    private static function fetchOrderAddress($platformOrderId, $addressType)
    {
        $returnValue = null;
        if ($platformOrderId) {
            $billingAddress = PlatformOrderAddress::where(['platform_order_id' => $platformOrderId, 'address_type' => $addressType])->select('address1')->first();
            if ($billingAddress) {
                $returnValue = $billingAddress->address1;
            }
        }
        return $returnValue;
    }

    /**
     * To find key position of given array valuee
     */
    private static function searchForKeys($array, $keyTitle, $string)
    {
        $arrKeys = [];
        foreach ($array as $key => $val) {
            if ($val[$keyTitle] == $string) {
                $arrKeys[] = $key;
            }
        }
        return $arrKeys;
    }

    /** Handle API call error and maintain logs */
    public function handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $procesedIds)
    {
        $returnStatus = "Failed to sync, API call error or invalid xml format.";
        if (isset($response['status_data'])) {
            $returnStatus = $response['status_data'];
        } else if (isset($response)) {
            $returnStatus = $response;
            if ( is_array($response) || is_object($response) ) {
                $returnStatus = json_encode($response, true);
            }
        }

        if (count($procesedIds)) {
            foreach ($procesedIds as $failedId) {
                if($response == 'success'){
                    $sync_status = 'success';
                } else{
                    $objPo = PlatformOrder::find($failedId);
                    $objPo->sync_status = 'Failed';
                    $objPo->updated_at = new \DateTime();
                    $objPo->save();
                    $sync_status = 'failed';
                }

                $this->logger->syncLog(
                    $userId,
                    $userIntegrationId,
                    $userWorkflowRuleId,
                    $sourcePlatformId,
                    $this->platformId,
                    $objectId,
                    $sync_status,
                    $failedId,
                    $returnStatus
                );
            }
        }
    }

    /** ##### Other required methods [end] ##### */

    /**
     * To manage function calling of diffeent module
     */
    public function ExecuteEventPeoplevox($method = '', $event = '', $destination_platform = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform = '', $platform_workflow_rule_id = '', $record_id = 0)
    {
        try {
            $response = true;

            $log = "Method: " . $method . ", event: " . $event . ", source_platform: " . $source_platform . ", destination_platform: ".$destination_platform.", is_initial_sync: " . $is_initial_sync.", user_workflow_rule_id: ".$user_workflow_rule_id.", platform_workflow_rule_id: ".$platform_workflow_rule_id.", record_id: ".$record_id;
            Storage::append('Peoplevox/' . $user_integration_id . '/ExecuteEventPeoplevox/' . date('d-m-Y') . '.txt', "[" . date('h:i:s') . "] " . $log);

            if ($method == 'GET' && $event == 'VENDOR') {
                $response = $this->getSuppliers($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'PRODUCT') {
                if ($is_initial_sync == 0) {
                    $response = $this->getProducts($user_id, $user_integration_id);
                    $this->getBundleProducts($user_id, $user_integration_id);
                }
            } else if ($method == 'GET' && $event == 'BUNDLEPRODUCT') {
                //$response = $this->getBundleProducts($user_id, $user_integration_id);
                Storage::append('Peoplevox/' . $user_integration_id . '/BUNDLEPRODUCT/' . date('d-m-Y') . '.txt', "[" . date('h:i:s') . "] " . $log);
            } else if ($method == 'GET' && $event == 'INVENTORY') {
                $response = $this->getProducts($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'SALESORDER') {
                $response = $this->getSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id);
                // Sub-method to track cancelled sales orders
                $this->checkCancelledSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id);
                $this->getSalesOrdersBackupCall($user_id, $user_integration_id, $user_workflow_rule_id);
            } else if ($method == 'GET' && $event == 'SALESORDERBACKUP') {
                //$response = $this->getSalesOrdersBackupCall($user_id, $user_integration_id, $user_workflow_rule_id);
                Storage::append('Peoplevox/' . $user_integration_id . '/SALESORDERBACKUP/' . date('d-m-Y') . '.txt', "[" . date('h:i:s') . "] " . $log);
            } else if ($method == 'GET' && $event == 'POITEMRECEIPT') {
                $response = $this->getPurchaseOrdersReceipts($user_id, $user_integration_id, 'PO');
            } else if ($method == 'GET' && $event == 'TRANSFERORDER') {
                $response = $this->getPurchaseOrdersReceipts($user_id, $user_integration_id, 'TO');
            } else if ($method == 'MUTATE' && $event == 'VENDOR') {
                if ($is_initial_sync == 0) {
                    $response = $this->createUpdateVendors($user_id, $user_integration_id, $source_platform, $user_workflow_rule_id, $record_id);
                }
            } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
                if ($is_initial_sync == 0) {
                    $response = $this->createUpdatePurchaseOrders($user_id, $user_integration_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
                }
            } else if ($method == 'MUTATE' && $event == 'TRANSFERORDER') {
                if ($is_initial_sync == 0) {
                    $response = $this->createUpdateSOForTransferOrders($user_id, $user_integration_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
                    $response = $this->createUpdatePOForTransferOrders($user_id, $user_integration_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
                }
            } else if ($method == 'GET' && $event == 'WAREHOUSE') {
                $response = $this->storeWarehouseData($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'BUNDLEPRODUCT') {
                $response = $this->getBundleProducts($user_id, $user_integration_id, $is_initial_sync);
            }
            return $response;
        } catch ( Exception $e ) {
            Log::error($user_integration_id . " -> PeoplevoxApiController -> ExecuteEventPeoplevox -> " . $e->getLine() . " -> " . $e->getMessage());
            return $e->getMessage();
        }
    }
}
