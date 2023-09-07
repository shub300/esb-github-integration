<?php

namespace App\Http\Controllers\Infoplus;

use App\Http\Controllers\Controller;
use App\Helper\Api\InfoplusApi;
use App\Helper\ConnectionHelper;
use App\Helper\Conversion\Facades\Conversion;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use DB;
use App\Models\PlatformAccount;
use App\Models\PlatformCountry;
use App\Models\PlatformCustomer;
use App\Models\PlatformField;
use App\Models\PlatformObject;
use App\Models\PlatformObjectData;
use App\Models\PlatformObjectDataAdditionalInformation;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderRefund;
use App\Models\PlatformOrderRefundLine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductInventoryCredit;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformStates;
use Carbon\Carbon;


class InfoplusServiceController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $wc, $helper, $map, $platformId, $log, $infoplus;
    public static $myPlatform = 'infoplus';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->infoplus = new InfoplusApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }
    public function UrlDate($dateTime, $sign = "|")
    {
        $date_slice = explode($sign, $dateTime);
        if (isset($date_slice[1]) && !empty($date_slice[1])) {
            $page = null;
            if (isset($date_slice[2]) && isset($date_slice[2])) {
                $page = trim($date_slice[1]);
            }
            return [
                trim($date_slice[0]),
                trim($date_slice[1]),
                $page
            ];
        } else {
            return trim($date_slice[0]);
        }
    }
    /* Handle Erros */
    public function handleErrorResponse($response)
    {

        $errors_list = null;

        if (isset($response['body']['errors'])) {
            $errors_list = implode(", ", $response['body']['errors']);
        } else if (isset($response['reason'])) {
            $errors_list =  $response['reason'];
        } else {
            $errors_list = "Internal API Error";
        }
        return $errors_list;
    }
    public function handleCustomError($error, $type = null, $subString = null)
    {
        $return = true;
        if (is_null($type)) {
            if (in_array($error, ['Too Many Requests', 'Bad Gateway'])) {
                $return = false;
            }
        } else {
            if (strpos($error, $subString) !== false) {
                $return = false;
            }
        }

        return $return;
    }

    /* Check existing connected account */
    public function CheckExistingConnectedAccount($api_domain, $access_key)
    {

        $exist = PlatformAccount::where(['platform_id' => $this->platformId, 'api_domain' => $api_domain, 'access_key' => $access_key])->count();
        if ($exist > 0) {
            return true;
        } else {
            return false;
        }
    }
    /* Check Credentials */
    public function CheckAPICredentials($access_key, $api_domain)
    {
        try {
            return $this->infoplus->CheckCredentials($access_key, $api_domain);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
    /* Ser status=0 for platform_object_data table */
    public function SetStatus($user_id, $user_integration_id, $platform_id, $object_id, $parent_id = NULL)
    {
        PlatformObjectData::where([
            'user_integration_id' => $user_integration_id,
            'platform_id' =>  $platform_id,
            'platform_object_id' => $object_id,
            'parent_id' => $parent_id
        ])->update([
            'status' => 0
        ]);
    }
    /* Search Product by SKU */
    public function SearchProductBySKU($findbySKU, $account, $user_id, $user_integration_id, $default_order_lob = null)
    {
        $return = false;
        if (isset($findbySKU)) {
            $page = 1;
            $pageLimit = 1;
            $value = $this->infoplus->checkStringQuotes($findbySKU);
            if ($value == "single") {
                $filter = 'sku eq "' . $findbySKU . '"';
            } else if ($value == "double" || is_null($value)) {
                $filter = "sku eq '" . $findbySKU . "'";
            }
            $arguments = [
                "limit" => $pageLimit,
                "page" => $page,
                "filter" => $filter
            ];

            $apicall = $this->infoplus->_API_CALL($account, "GET", "item/search", $arguments, [], "v3.0");
            $product = $apicall['body'];
            if (!empty($product) || count($product) > 0) {
                if (!isset($product['errors'])) {
                    foreach ($product as $key => $value) {
                        if (!empty($value['sku'])) {
                            $returnProduct = $this->PrepareModalData($value, $user_id, $user_integration_id);
                            if (isset($returnProduct)) {
                                if ($default_order_lob) {
                                    if (trim($default_order_lob) == trim($value['lobId'])) {
                                        $return = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $return;
    }
    /* Find Price List By Product ID */
    public function FindPriceList($productID, $userIntegrationId, $PlatformWorkFlowRuleID)
    {
        $vendor_price = $sale_price =  "";
        $priceListArray = DB::table('platform_porduct_price_list as pp')
            ->join('platform_object_data as data', 'pp.platform_object_data_id', '=', 'data.id')
            ->where('pp.platform_product_id', $productID)
            ->select('pp.platform_product_id', 'pp.price', 'pp.api_currency_code', 'pp.platform_object_data_id')->get();

        if (!empty($priceListArray)) {
            foreach ($priceListArray as $key => $value) {
                $priceName = $this->map->getObjectDataByID($value->platform_object_data_id, ['api_id']);

                if (isset($priceName->api_id)) {
                    $res = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "product_pricelist", ['api_id'],  "regular", $priceName->api_id, "single");
                    if ($res) {
                        if ($res->api_id == "vendor_price") {
                            $vendor_price = (string) $value->price;
                        }
                        if ($res->api_id == "sale_price") {
                            $sale_price = (string) $value->price;
                        }
                    }
                }
            }
        }

        return ['sale_price' => $sale_price, 'vendor_price' => $vendor_price];
    }
    /* Find Order ID in order table */
    public function FindOrderID($OrderID)
    {
        return PlatformOrder::find($OrderID);
    }
    /* Prepare Modal Data */
    public function PrepareModalData($product, $user_id, $user_integration_id, $type = "product")
    {
        $ProductPrimaryID = NULL;
        $findProduct = PlatformProduct::where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $product['id'],
        ])->first();
        if ($findProduct) {

            $ProductPrimaryID = $findProduct->id;
            $update = false;
            if ($type == "inventory") {
                if ($findProduct->api_inventory_lastmodified_time != $product['inventoryUpdateTimestamp']) {
                    $update = true;
                }
            } else {

                if ($findProduct->api_updated_at != $product['modifyDate']) {
                    $update = true;
                }
            }

            //temporary added will be give mapping for this latter
            // if($user_integration_id==422 && $findProduct->inventory_sync_status == 'Ignore'){
            //     $update=false;
            // }

            $allow_recheck_ignored = $this->map->getMappedDataByName($user_integration_id, NULL, "recheck_ignored_inventory", ['api_id']);
            if( $findProduct->inventory_sync_status == 'Ignore' && $allow_recheck_ignored && $allow_recheck_ignored->api_id =="No") {
                $update=false;

                //update product for handle last modification
            }

            
            if ($update) {
                $findProduct->api_product_code = $product['lobId'];
                $findProduct->sku = $product['sku'];
                $findProduct->bundle = 0;
                $findProduct->upc = $product['upc'];
                $findProduct->manufacturer_sku = $product['vendorSKU'];
                $findProduct->product_name =  $product['itemDescription'];
                $findProduct->description = $product['itemDescription'];
                $findProduct->product_status = $product['status'];
                $findProduct->weight = $product['weightPerWrap'];
                $findProduct->api_updated_at = $product['modifyDate'];
                $findProduct->api_inventory_lastmodified_time = $product['inventoryUpdateTimestamp'];
                $findProduct->is_deleted = 0;
                if (isset($product['linked_id'])) {
                    $findProduct->linked_id = $product['linked_id'];
                }
                if ($type == "inventory") {
                    $findProduct->inventory_sync_status = "Ready";
                } else {
                    $findProduct->product_sync_status = "Ready";
                }

                $findProduct->save();
            }
        } else {
            $productCreate = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_product_id' => $product['id'],
                'api_product_code' => $product['lobId'],
                'sku' => $product['sku'],
                'bundle' => 0,
                'upc' => $product['upc'],
                'manufacturer_sku' => $product['vendorSKU'],
                'product_name' =>  $product['itemDescription'],
                'description' => $product['itemDescription'],
                'product_status' => $product['status'],
                'weight' => $product['weightPerWrap'],
                'api_updated_at' => $product['modifyDate'],
                'api_inventory_lastmodified_time' => $product['inventoryUpdateTimestamp'],
                'is_deleted' => 0,

            ];
            if ($type == "inventory") {
                $productCreate['inventory_sync_status'] = "Ready";
            } else {
                $productCreate['product_sync_status'] = "Ready";
            }
            if (isset($product['linked_id'])) {
                $productCreate['linked_id'] = $product['linked_id'];
            }
            $findProduct = PlatformProduct::create($productCreate);
            $ProductPrimaryID = $findProduct->id;
        }

        if ($ProductPrimaryID) {

            $AttributeData = [
                'lenght' => isset($product['length']) ? $product['length'] : NULL,
                'height' => isset($product['height']) ? $product['height'] : NULL,
                'width' => isset($product['width']) ? $product['width'] : NULL,
                'shortdescription' => isset($product['additionalDescription']) ? $product['additionalDescription'] : NULL,
                'forward_lot_mixing_rule' => isset($product['forwardLotMixingRule']) ? $product['forwardLotMixingRule'] : NULL,
                'storage_lot_mixing_rule' => isset($product['storageLotMixingRule']) ? $product['storageLotMixingRule'] : NULL,
                'forward_item_mixing_rule' => isset($product['forwardItemMixingRule']) ? $product['forwardItemMixingRule'] : NULL,
                'storage_item_mixing_rule' => isset($product['storageItemMixingRule']) ? $product['storageItemMixingRule'] : NULL,
                'allocation_rule' => isset($product['allocationRule']) ? $product['allocationRule'] : NULL,
                'lob' => isset($product['lobId']) ? $product['lobId'] : NULL,
            ];

            $AttributeData['platform_product_id'] = $ProductPrimaryID;
            $this->CreateOrUpdateProductAttributes($ProductPrimaryID, $AttributeData);
            $this->CreatePriceList($ProductPrimaryID, "pricelist", $product['sellPrice']);
        }


        return  $ProductPrimaryID;
    }
    /* Insert Update Product Attributes */
    public function CreateOrUpdateProductAttributes($ProductID = NULL, $PostData = [])
    {

        if ($ProductID && !empty($PostData)) {

            PlatformProductDetailAttribute::updateOrCreate(['platform_product_id' => $ProductID], $PostData);
        }
    }
    /* Insert / Update Product Price */
    public function CreatePriceList($ProductPrimaryID, $ObjectName, $SalePrice = 0)
    {

        if ($ProductPrimaryID) {
            $ObjectId = $this->helper->getObjectId($ObjectName);
            if ($ObjectId) {
                $find = PlatformObjectData::select('id', 'api_id')->where([
                    'user_id' => 0,
                    'user_integration_id' => 0,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $ObjectId,
                ])->first();
                if ($find) {
                    $SalePrice = isset($SalePrice) ? $SalePrice : 0;
                    if ($find->api_id == "sale_price") { //In infoplus only have sale price (list_price in api)
                        PlatformProductPriceList::updateOrCreate(['platform_product_id' => $ProductPrimaryID], ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $find->id, 'price' => $SalePrice]);
                    }
                }
            }
        }
    }
    /* Bill To | Sold To | ShipTo | Ship Date */
    public function GetAddress($address, $type = "SO")
    {
        $billTo = $shipTo = $szip = NULL;
        if ($address) {
            foreach ($address as $key => $value) {
                if ($value->address_type == "shipping" || $value->address_type == "billing") {
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
                    $countryName = isset($value->country) ? $value->country : null;
                    if ($countryName) {
                        $FindCountry = PlatformCountry::select('name')->where('iso', '=', $countryName)->first();
                        $country = isset($FindCountry->name) ? $FindCountry->name :  $countryName;
                    } else {
                        $country = $countryName;
                    }


                    if ($value->address_type == "shipping") {
                        /* shipping address */
                        $address_name = isset($value->address_name) ? $value->address_name : '';
                        $company_name = isset($value->company) && !empty($value->company) ? $value->company :  $address_name;
                        $address1 = isset($value->address1) ? $value->address1 : '';
                        $address2 = isset($value->address2) ? $value->address2 : '';
                        $city = isset($value->address3) ? $value->address3 : '';
                        $zip = isset($value->postal_code) ? $value->postal_code : null;

                        $telephone = isset($value->phone_number) ? $value->phone_number : '';
                        $email = isset($value->email) ? $value->email : '';
                        $shipTo = [
                            "shipToCompany" =>  $company_name,
                            "shipToStreet" => $address1,
                            "shipToStreet2" => $address2,
                            "shipToCity" =>  $city,
                            "shipToState" => $state,
                            "shipToZip" => $zip,
                            "shipToPhone" => $telephone,
                            "shipToEmail" => $email,
                            "shipToCountry" => $country
                        ];

                        if ($type == "ASN") {
                            $shipTo = [

                                "shipToName" =>  $address_name,
                                "shipToStreet1" => $address1,
                                "shipToStreet2" => $address2,
                                "shipToCity" =>  $city,
                                "shipToState" => $state,
                                "shipToZipCode" => $zip,
                                "shipToPhone" => $telephone,


                            ];
                        }
                        if ($type == "SO") {

                            if (!in_array(strtoupper($countryName), ['US', 'USA'])) {
                                $shipTo['shipToStreet3'] = $state;
                                unset($shipTo["shipToState"]);
                            }
                        }
                    }
                    if ($value->address_type == "billing") {
                        /* billing address */
                        $address_name = isset($value->address_name) ? $value->address_name : '';
                        $company_name = isset($value->company) && !empty($value->company) ? $value->company :  $address_name;
                        $address1 = isset($value->address1) ? $value->address1 : '';
                        $address2 = isset($value->address2) ? $value->address2 : '';
                        $city = isset($value->address3) ? $value->address3 : '';

                        $zip = isset($value->postal_code) ? $value->postal_code : '';

                        $telephone = isset($value->phone_number) ? $value->phone_number : '';
                        $email = isset($value->email) ? $value->email : '';

                        $billTo = [
                            "billToCompany" =>  $company_name,
                            "billToStreet" => $address1,
                            "billToStreet2" => $address2,
                            "billToCity" =>  $city,
                            "billToState" => $state,
                            "billToZip" => $zip,
                            "billToPhone" =>  $telephone,
                            "billToEmail" => $email,
                            "billToCountry" => $country
                        ];
                        if ($type == "ASN") {
                            $billTo = [

                                "billingName" =>  $address_name,
                                "billingStreet1" => $address1,
                                "billingStreet2" => $address2,
                                "billingCity" =>  $city,
                                "billingState" => $state,
                                "billingZipCode" => $zip,
                                "billingPhone" => $telephone,


                            ];
                        }
                        if ($type == "SO") {
                            if (!in_array(strtoupper($countryName), ['US', 'USA'])) {
                                $billTo['billToStreet3'] = $state;
                                unset($billTo["billToState"]);
                            }
                        }
                    }
                }
            }
        }

        return ['billTo' => $billTo, 'shipTo' => $shipTo];
    }

    /* Product Identity Mapping */
    public function ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuelID)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $maping_data =  $this->map->getMappedField($userIntegrationId, $PlatformWorkFlowRuelID, $product_identity_obj_id);

        $source_row_data = $destination_row_data = 'sku';
        if ($maping_data) {

            if ($maping_data['destination_platform_id'] == self::$myPlatform) {
                $destination_row_data = $maping_data['destination_row_data'];
                $source_row_data = $maping_data['source_row_data'];
            } else {
                $destination_row_data = $maping_data['source_row_data'];
                $source_row_data = $maping_data['destination_row_data'];
            }
        }
        return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
    }
    /* Prepare Order Lines */
    public function PrepareOrderLine($type = "SO", $orderLines, $userID, $userIntegrationId, $SourcePlatformId, $source_identity, $LOB = 0, $warehouseId = NULL, $vendorId = NULL, $date = NULL)
    {
        $items = [];
        $productNotFound = false;
        if ($orderLines) {
            $qty = 0;
            foreach ($orderLines as $key => $val) {
                $q = PlatformProduct::select($source_identity);
                if ($type == "SO") {
                    $q->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $SourcePlatformId], ['api_product_id', '=', $val->product_id], ['is_deleted', '=', 0]]);
                    $qty = (int) $val->sum;
                    $bp_product = $q->pluck($source_identity)->first(); //Find Source Product by product_id
                    if ($bp_product) {
                        array_push($items, [
                            "lobId" => $LOB,
                            "sku" => $bp_product,
                            "orderedQty" =>  $qty
                        ]);
                    } else {
                        $productNotFound = true;
                    }
                } else if ($type == "ASN") {
                    if (!in_array($val->api_product_id, [1000, 1001])) { //Skip non-track products from PO order & Transfer Order                       
                        $q->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $SourcePlatformId], [$source_identity, '=', $val->sku], ['is_deleted', '=', 0]]);
                        $qty = (int) $val->qty;
                        $bp_product = $q->pluck($source_identity)->first(); //Find Source Product by product_id
                        if ($bp_product) {
                            array_push($items, [
                                "lobId" => $LOB,
                                "sku" => $bp_product,
                                "orderQuantity" =>  $qty,
                                "warehouseId" => $warehouseId,
                                "requestedDeliveryDate" => isset($date) ? Carbon::parse($date)->format('Y-m-d\TH:i:s\Z') : Carbon::now()->format('Y-m-d\TH:i:s\Z'),
                                "unitCode" => "BOOK",
                                "wrapCode" => "PAIR",
                                "unitsPerWrap" => 1,
                                "maxOvers" => 1,
                                "maxUnders" => 1,
                                "vendorId" => $vendorId,
                            ]);
                        } else {
                            $productNotFound = true;
                        }
                    }
                }
            }
        }

        return ['items' => $items, 'productNotFound' => $productNotFound];
    }
    /* UpdateOrder */
    public function updateOrder($account, $responsePayload, $shipTo)
    {


        $updatePayload = [
            "orderNo" => $responsePayload['orderNo'],
            "carrierId" =>  $responsePayload['carrierId']
        ];
        /* Id payload don't have customerNo then store customer detail in DB*/
        $updatePayload = array_merge($updatePayload, $shipTo);
        $apicall = $this->infoplus->_API_CALL($account, "PUT", "order", [], $updatePayload);
        $order_response = $apicall['body'];
        if (!empty($order_response) && is_array($order_response)) {
            if (isset($order_response['orderNo'])) {
                return true;
            } else {
                return $this->handleErrorResponse($apicall);
            }
        }
    }
    /* Create Cutomer by Default email address */
    private function CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account)
    {
        $return = null;
        if ($CustomEmail) {
            if ($type == "Customer") {
                $url = "customer/search";
                $filterColumn = "email";
            } else if ($type == "Vendor") {
                $url = "vendor/search";
                $filterColumn = "podEmail";
            }
            $value = $this->infoplus->checkStringQuotes($CustomEmail);
            if ($value == "single") {
                $filter = $filterColumn . ' eq "' . $CustomEmail . '"';
            } else if ($value == "double" || is_null($value)) {
                $filter = $filterColumn . " eq '" . $CustomEmail . "'";
            }
            $arguments = [
                "limit" => 1,
                "filter" => $filter
            ];
            $apicall = $this->infoplus->_API_CALL($account, "GET", $url, $arguments, [], "v3.0");
            $response = $apicall['body'];

            if (!isset($response['errors'])) {
                if (is_array($response) && !empty($response)) {
                    $arr = $response[0];
                    if ($type == "Customer") {
                        $api_return_id = $arr['customerNo'];
                        $_code = $arr['customerNo'];
                        $email = $arr[$filterColumn];

                        $where = [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_customer_code' => $arr['customerNo'],
                            'type' => $type
                        ];
                    } else if ($type == "Vendor") {
                        $api_return_id = $arr['id'];
                        $_code = $arr['vendorNo'];
                        $email = $arr[$filterColumn]; //need to change
                        $where = [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_customer_id' => $arr['id'],
                            'type' => $type
                        ];
                    }
                    PlatformCustomer::updateOrCreate($where, [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $arr['id'],
                        'api_customer_code' =>  $_code,
                        'email' => $email,
                        'customer_name' => $arr['name'],
                        'type' => $type
                    ]);
                    $return = $api_return_id;
                } else {
                    if ($type == "Customer") {
                        $return = ["Customer ({$CustomEmail}) email address not available in Infoplus System."];
                    } else if ($type == "Vendor") {
                        $return = ["Vendor ({$CustomEmail}) email address not available in Infoplus System."];
                    } else {
                        $return = ["Default email address not found in Infoplus System"];
                    }
                }
            } else {
                $error = $this->handleErrorResponse($apicall);
                $return = [$error];
            }
        }
        return $return;
    }
    /* Save Customer Details */
    public function saveCustomerDetails($user_id, $user_integration_id, $platform_id, $payload = [], $type = "Custtomer")
    {
        if ($payload) {
            if ($type == "Customer") {
                $where = [
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $platform_id,
                    'api_customer_code' => $payload['customerNo'],
                    'type' => $type
                ];
                $store = [
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $platform_id,
                    'api_customer_code' => $payload['customerNo'],
                    'email' => isset($payload['billToEmail']) ? $payload['billToEmail'] : null,
                    'customer_name' => isset($payload['billingName']) ? $payload['billingName'] : null,
                    'type' => $type
                ];
            } else {
                $where = [
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $platform_id,
                    'api_customer_id' => $payload['vendorId'],
                    'type' => $type
                ];
                $store = [
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $platform_id,
                    'api_customer_id' => $payload['vendorId'],
                    'email' => isset($payload['billToEmail']) ? $payload['billToEmail'] : null,
                    'customer_name' => isset($payload['billingName']) ? $payload['billingName'] : null,
                    'type' => $type
                ];
            }
            PlatformCustomer::updateOrCreate($where, $store);
        }
    }
    /* find customer name */
    public function findCustomerName($customerPrimaryID)
    {
        $customerName = null;
        /* First search customer by platform customer id */
        $find = PlatformCustomer::find($customerPrimaryID);
        if ($find) {
            $customerName = $find->customer_name;
        }
        return $customerName;
    }
    /* find customer name */
    public function findCustomerNameByEmail($email, $user_id, $user_integration_id, $source_platform_id, $type = "Customer")
    {
        $customerName = null;
        /* First search customer by platform customer id */
        $find = PlatformCustomer::select('customer_name')->where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $source_platform_id,
            'email' => $email,
            'type' => $type,
            'is_deleted' => 0
        ])->first();

        if ($find) {
            $customerName = $find->customer_name;
        }
        return $customerName;
    }
    /* Find customer by email address and save customer details */
    public function FindCustomerOrVentorByEmail($type = "Customer", $CustomerPrimaryID, $user_id, $user_integration_id, $CustomEmail, $account = NULL)
    {
        $return = false;
        if (!isset($account)) {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_key',  'platform_id', 'id', 'user_id', 'api_domain']);
        }
        $obj = new PlatformCustomer;
        /* First search customer by platform customer id */
        $find = $obj->find($CustomerPrimaryID);
        $is_customer = false;
        if ($type == "Customer") {
            /* For customer search only */
            $url = "customer/search";
            $column = "api_customer_code"; //return column name
            $filterColumn = "email";
            $is_customer = true;
        } else if ($type == "Vendor") {
            /* For vendor search only */
            $url = "vendor/search";
            $column = "api_customer_id"; //return column name
            $filterColumn = "podEmail";
        }

        if (!empty($find->email) && isset($find->email)) {

            $email = $find->email;
            $value = $this->infoplus->checkStringQuotes($email);
            if ($value == "single") {
                $filter = $filterColumn . ' eq "' . $email . '"';
            } else if ($value == "double" || is_null($value)) {
                $filter = $filterColumn . " eq '" . $email . "'";
            }

            $findRecord = $obj->select($column)->where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'email' => $email,
                'type' => $type,
                'is_deleted' => 0
            ])->first();

            if ($findRecord) {
                /* If customer/vendor email found in DB, select the customer no/vendor id from there. */
                $arr = $findRecord->toArray();
                $return = $arr[$column];
            } else {
                /* Search customer no or vendor id in api via using email */
                $arguments = [
                    "limit" => 1,
                    "filter" => $filter
                ];
                $apicall = $this->infoplus->_API_CALL($account, "GET", $url, $arguments, [], "v3.0");
                $response = $apicall['body'];

                if (!isset($response['errors'])) {
                    if (is_array($response) && !empty($response)) {
                        /* Customer no or vendor id is found via api search using email */
                        $arr = $response[0];

                        if ($type == "Customer") {
                            $api_return_id = $arr['customerNo'];
                            $_code = $arr['customerNo'];
                            $email = $arr['email'];
                        } else if ($type == "Vendor") {
                            $api_return_id = $arr['id'];
                            $_code = $arr['vendorNo'];
                            $email = $arr['arEmail']; //need to change
                        }
                        PlatformCustomer::updateOrCreate([
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_customer_id' => $arr['id'],
                            'type' => $type
                        ], [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_customer_id' => $arr['id'],
                            'api_customer_code' => $_code,
                            'email' => $email,
                            'customer_name' => $arr['name'],
                            'type' => $type
                        ]);
                        $return = $api_return_id;
                    } else {
                        // /* Customer no or vendor id is NOT found via api search using email || Now search using default customer email address*/
                        // if ($CustomEmail) { //If default customer email customer mapping found
                        //     $findRecord = $obj->select($column)->where([
                        //         'user_id' => $user_id,
                        //         'user_integration_id' => $user_integration_id,
                        //         'platform_id' => $this->platformId,
                        //         'email' => $CustomEmail,
                        //         'type' => $type,
                        //         'is_deleted' => 0
                        //     ])->first();

                        //     if ($findRecord) {
                        //         /* If customer/vendor email found in DB, select the customer no/vendor id from there. */
                        //         $arr = $findRecord->toArray();
                        //         $return= $arr[$column];
                        //     } else {
                        //         $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);
                        //         if (is_array($customerVendorId)) {
                        //             $return= $customerVendorId;
                        //         } else if (is_null($customerVendorId)) {
                        //             $return= false;
                        //         } else {
                        //             $return=  $customerVendorId;
                        //         }
                        //     }
                        // } else {
                        //     $return=  ["Default customer email mapping is not found."];
                        // }
                        $return = true;
                    }
                } else {
                    $return =  $this->handleErrorResponse($apicall);
                    if (empty($return)) {
                        $return = true; //It means customer/vendor created when order is post with billTo Address
                    } else {
                        $return = [$return];
                    }
                }
            }
        } else {
            /* Customer no or vendor id is NOT found via api search using email || Now search using default customer email address*/
            if ($CustomEmail) { //If default customer email customer mapping found
                $findRecord = $obj->select($column)->where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'email' => $CustomEmail,
                    'type' => $type,
                    'is_deleted' => 0
                ])->first();

                if ($findRecord) {
                    /* If customer/vendor email found in DB, select the customer no/vendor id from there. */
                    $arr = $findRecord->toArray();
                    $return = $arr[$column];
                } else {
                    $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);
                    if (is_array($customerVendorId)) {
                        $return = $customerVendorId;
                    } else if (is_null($customerVendorId)) {
                        $return = false;
                    } else {
                        $return =  $customerVendorId;
                    }
                }
            } else {
                $return =  ["Default customer email mapping is not found."];
            }
        }
        return $return;
    }
    public function FindCustomerOrVentorByEmailBK($type = "Customer", $lob = NULL, $payload = [], $CustomerPrimaryID, $user_id, $user_integration_id, $CustomEmail, $account = NULL)
    {
        if (!isset($account)) {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_key',  'platform_id', 'id', 'user_id', 'api_domain']);
        }
        $notCustomerVendorCreate = true;
        $obj = new PlatformCustomer;
        $find = $obj->find($CustomerPrimaryID);
        $is_customer = false;
        if ($type == "Customer") {
            $url = "customer/search";
            $column = "api_customer_code"; //return column name
            $filterColumn = "email";
            $is_customer = true;
        } else if ($type == "Vendor") {
            $url = "vendor/search";
            $column = "api_customer_id"; //return column name
            $filterColumn = "podEmail";
        }

        if (!empty($find->email) && isset($find->email)) {

            $email = $find->email;
            $value = $this->infoplus->checkStringQuotes($email);
            if ($value == "single") {
                $filter = $filterColumn . ' eq "' . $email . '"';
            } else if ($value == "double" || is_null($value)) {
                $filter = $filterColumn . " eq '" . $email . "'";
            }

            $findRecord = $obj->select($column)->where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'email' => $email,
                'type' => $type,
                'is_deleted' => 0
            ])->first();

            if ($findRecord) {
                $arr = $findRecord->toArray();
                return $arr[$column];
            } else {
                $arguments = [
                    "limit" => 1,
                    "filter" => $filter
                ];
                $apicall = $this->infoplus->_API_CALL($account, "GET", $url, $arguments, [], "v3.0");
                $response = $apicall['body'];

                if (!isset($response['errors']) && is_array($response) && !empty($response)) {
                    $arr = $response[0];

                    if ($type == "Customer") {
                        $api_return_id = $arr['customerNo'];
                        $_code = $arr['customerNo'];
                        $email = $arr['email'];
                    } else if ($type == "Vendor") {
                        $api_return_id = $arr['id'];
                        $_code = $arr['vendorNo'];
                        $email = $arr['arEmail']; //need to change
                    }
                    PlatformCustomer::updateOrCreate([
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $arr['id'],
                        'type' => $type
                    ], [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $arr['id'],
                        'api_customer_code' => $_code,
                        'email' => $email,
                        'customer_name' => $arr['name'],
                        'type' => $type
                    ]);
                    return $api_return_id;
                } else {

                    $NO = $this->GenerateNumber();
                    if ($is_customer) {
                        //Create New Customer
                        $customerNo = "CUST" . $NO;

                        $payloadPost = [
                            "lobId" => $lob,
                            "customerNo" => $customerNo,
                        ];
                        if (!empty($find->customer_name)) {
                            unset($payload['name']);
                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                            } else {
                                $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                                $payloadPost['attention'] = $payloadPost['name'];
                            }
                            unset($payload['company_name']);
                        } else {
                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . $customerNo;
                            } else {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : $customerNo;
                            }
                            unset($payload['company_name']);
                        }
                        if (!empty($find->email)) {
                            unset($payload['email']);
                            $payloadPost['email'] = $find->email;
                        } else {
                            if (!empty($payload['email']) && isset($payload['email'])) {
                                $payloadPost['email'] = $payload['email'];
                                unset($payload['email']);
                            } else {
                                if ($CustomEmail) {
                                    $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);
                                    if ($customerVendorId) {
                                        $notCustomerVendorCreate = false;
                                        $return = $customerVendorId;
                                    } else if (is_null($customerVendorId)) {
                                        $return =  $notCustomerVendorCreate = false;
                                    } else {
                                        $notCustomerVendorCreate = true;
                                        $payloadPost['email'] = $CustomEmail;
                                    }
                                } else {
                                    $notCustomerVendorCreate = false;
                                }
                            };
                        }

                        $payloadPost = array_merge($payloadPost, $payload);
                    } else {
                        //Create New Vendor
                        $payloadPost = [
                            "lobId" => $lob,
                            "vendorNo" => $NO,
                        ];
                        if (!empty($find->customer_name)) {
                            unset($payload['name']);

                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                            } else {
                                $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                            }
                            unset($payload['company_name']);
                        } else {
                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . "Vendor-" . $NO;
                            } else {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : "Vendor-" . $NO;
                            }
                            unset($payload['company_name']);
                        }
                        if (!empty($find->email)) {
                            $payloadPost['arEmail'] = $find->email;
                            $payloadPost['podEmail'] = $find->email;
                            $payloadPost['orderEmail'] = $find->email;
                        } else {
                            if ($CustomEmail) {
                                $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);
                                if ($customerVendorId) {
                                    $notCustomerVendorCreate = false;
                                    $return = $customerVendorId;
                                } else if (is_null($customerVendorId)) {
                                    $return =  $notCustomerVendorCreate = false;
                                } else {
                                    $notCustomerVendorCreate = true;
                                    $payload['arEmail'] = $CustomEmail;
                                    $payload['podEmail'] = $CustomEmail;
                                    $payload['orderEmail'] = $CustomEmail;
                                }
                            } else {
                                $notCustomerVendorCreate = false;
                            }
                        }
                        $payloadPost = array_merge($payloadPost, $payload);
                    }

                    if ($notCustomerVendorCreate) {

                        $return = $this->CreateCustomerOrVendor($type, $payloadPost, $user_id, $user_integration_id, $account);
                    } else {
                        $return = false;
                    }


                    return $return;
                }
            }
        } else {

            $NO = $this->GenerateNumber();
            if ($is_customer) {
                //Create New Customer
                $customerNo = "CUST" . $NO;
                $payloadPost = [
                    "lobId" => $lob,
                    "customerNo" => $customerNo,
                ];
                if (!empty($find->customer_name) && isset($find->customer_name)) {
                    unset($payload['name']);
                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                        $payloadPost['name'] =  $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                        $payloadPost['attention'] = $this->removeNonASCII("’", "'", $find->customer_name);
                    } else {
                        $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                        $payloadPost['attention'] = $payloadPost['name'];
                    }
                    unset($payload['company_name']);
                } else {
                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {

                        $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . $customerNo;
                        $payloadPost['attention'] = isset($payload['name']) ? $payload['name'] : $customerNo;
                    } else {
                        $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : $customerNo;
                        $payloadPost['attention'] = $payloadPost['name'];
                    }
                    unset($payload['company_name']);
                }
                if (!empty($find->email) && isset($find->email)) {
                    unset($payload['email']);
                    $payloadPost['email'] = $find->email;
                } else {
                    if (!empty($payload['email']) && isset($payload['email'])) {
                        $payloadPost['email'] = $payload['email'];
                        unset($payload['email']);
                    } else {

                        if ($CustomEmail) {
                            $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);

                            if ($customerVendorId) {
                                $notCustomerVendorCreate = false;
                                $return = $customerVendorId;
                            } else if (is_null($customerVendorId)) {
                                $return =  $notCustomerVendorCreate = false;
                            } else {
                                $notCustomerVendorCreate = true;
                                $payloadPost['email'] = $CustomEmail;
                            }
                        } else {
                            $notCustomerVendorCreate = false;
                        }
                    }
                }

                $payloadPost = array_merge($payloadPost, $payload);
            } else {

                //Create New Vendor
                $payloadPost = [
                    "lobId" => $lob,
                    "vendorNo" => $NO,
                ];
                if (!empty($find->customer_name) && isset($find->customer_name)) {
                    unset($payload['name']);

                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                        $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name) : $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                    } else {
                        $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                    }
                    unset($payload['company_name']);
                } else {
                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {

                        $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . "Vendor-" . $NO;
                    } else {
                        $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : "Vendor-" . $NO;
                    }
                    unset($payload['company_name']);
                }

                if (!empty($find->email) && isset($find->email)) {
                    $payloadPost['arEmail'] = $find->email;
                    $payloadPost['podEmail'] = $find->email;
                    $payloadPost['orderEmail'] = $find->email;
                } else {

                    if ($CustomEmail) {
                        $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);

                        if ($customerVendorId) {
                            $notCustomerVendorCreate = false;
                            $return = $customerVendorId;
                        } else if (is_null($customerVendorId)) {
                            $return =  $notCustomerVendorCreate = false;
                        } else {
                            $notCustomerVendorCreate = true;
                            $payload['arEmail'] = $CustomEmail;
                            $payload['podEmail'] = $CustomEmail;
                            $payload['orderEmail'] = $CustomEmail;
                        }
                    } else {
                        $notCustomerVendorCreate = false;
                    }
                }

                $payloadPost = array_merge($payloadPost, $payload);
            }
            if ($notCustomerVendorCreate) {

                $return = $this->CreateCustomerOrVendor($type, $payloadPost, $user_id, $user_integration_id, $account);
            } else {
                $return = $return;
            }


            return $return;
        }
    }
    /* Find and create vendor */
    public function FindVentorByEmail($type = "Vendor", $lob = NULL, $payload = [], $CustomerPrimaryID, $user_id, $user_integration_id, $CustomEmail, $account = NULL)
    {
        if (!isset($account)) {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_key',  'platform_id', 'id', 'user_id', 'api_domain']);
        }
        $notCustomerVendorCreate = true;
        $obj = new PlatformCustomer;
        $find = $obj->find($CustomerPrimaryID);
        $is_customer = false;
        if ($type == "Customer") {
            $url = "customer/search";
            $column = "api_customer_code"; //return column name
            $filterColumn = "email";
            $is_customer = true;
        } else if ($type == "Vendor") {
            $url = "vendor/search";
            $column = "api_customer_id"; //return column name
            $filterColumn = "podEmail";
        }

        if (!empty($find->email) && isset($find->email)) {
            $email = $find->email;
            $value = $this->infoplus->checkStringQuotes($email);
            if ($value == "single") {
                $filter = $filterColumn . ' eq "' . $email . '"';
            } else if ($value == "double" || is_null($value)) {
                $filter = $filterColumn . " eq '" . $email . "'";
            }

            $findRecord = $obj->select($column)->where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'email' => $email,
                'type' => $type,
                'is_deleted' => 0
            ])->first();

            if ($findRecord) {
                $arr = $findRecord->toArray();
                return $arr[$column];
            } else {
                $arguments = [
                    "limit" => 1,
                    "filter" => $filter
                ];
                $apicall = $this->infoplus->_API_CALL($account, "GET", $url, $arguments, [], "v3.0");
                $response = $apicall['body'];

                if (!isset($response['errors']) && is_array($response) && !empty($response)) {
                    $arr = $response[0];

                    if ($type == "Customer") {
                        $api_return_id = $arr['customerNo'];
                        $_code = $arr['customerNo'];
                        $email = $arr['email'];
                    } else if ($type == "Vendor") {
                        $api_return_id = $arr['id'];
                        $_code = $arr['vendorNo'];
                        $email = $arr['arEmail']; //need to change
                    }
                    PlatformCustomer::updateOrCreate([
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $arr['id'],
                        'type' => $type
                    ], [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $arr['id'],
                        'api_customer_code' => $_code,
                        'email' => $email,
                        'customer_name' => $arr['name'],
                        'type' => $type
                    ]);
                    return $api_return_id;
                } else {

                    $NO = $this->GenerateNumber();
                    if ($is_customer) {
                        //Create New Customer
                        $customerNo = "CUST" . $NO;

                        $payloadPost = [
                            "lobId" => $lob,
                            "customerNo" => $customerNo,
                        ];
                        if (!empty($find->customer_name)) {
                            unset($payload['name']);
                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                            } else {
                                $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                            }
                            unset($payload['company_name']);
                        } else {
                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . $customerNo;
                            } else {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : $customerNo;
                            }
                            unset($payload['company_name']);
                        }
                        if (!empty($find->email)) {
                            unset($payload['email']);
                            $payloadPost['email'] = $find->email;
                        } else {
                            if (!empty($payload['email']) && isset($payload['email'])) {
                                $payloadPost['email'] = $payload['email'];
                                unset($payload['email']);
                            } else {
                                if ($CustomEmail) {
                                    $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);
                                    if (!is_array($customerVendorId) && !is_null($customerVendorId)) {
                                        $notCustomerVendorCreate = false;
                                        $return = $customerVendorId;
                                    } else if (is_null($customerVendorId)) {
                                        $return =  $notCustomerVendorCreate = false;
                                    } else {
                                        $notCustomerVendorCreate = true;
                                        $payloadPost['email'] = $CustomEmail;
                                    }
                                } else {
                                    $notCustomerVendorCreate = false;
                                }
                            };
                        }

                        $payloadPost = array_merge($payloadPost, $payload);
                    } else {
                        //Create New Vendor
                        $payloadPost = [
                            "lobId" => $lob,
                            "vendorNo" => $NO,
                        ];
                        if (!empty($find->customer_name)) {
                            unset($payload['name']);

                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                            } else {
                                $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                            }
                            unset($payload['company_name']);
                        } else {
                            if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . "Vendor-" . $NO;
                            } else {
                                $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : "Vendor-" . $NO;
                            }
                            unset($payload['company_name']);
                        }
                        if (!empty($find->email)) {
                            //create from po vendor email 
                            $payloadPost['arEmail'] = $find->email;
                            $payloadPost['podEmail'] = $find->email;
                            $payloadPost['orderEmail'] = $find->email;

                            //added addition unset
                            unset($payload['arEmail']);
                            unset($payload['podEmail']);
                            unset($payload['orderEmail']);

                        }  else if ( !$payload['arEmail']) {
                            
                            //when shipping billing email are not exist then create by default email from mapping..
                            if ($CustomEmail) {
                                $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);
                                if (!is_array($customerVendorId) && !is_null($customerVendorId)) {
                                    $notCustomerVendorCreate = false;
                                    $return = $customerVendorId;
                                } else if (is_null($customerVendorId)) {
                                    $return =  $notCustomerVendorCreate = false;
                                } else {
                                    $notCustomerVendorCreate = true;
                                    $payload['arEmail'] = $CustomEmail;
                                    $payload['podEmail'] = $CustomEmail;
                                    $payload['orderEmail'] = $CustomEmail;
                                }
                            } else {
                                $notCustomerVendorCreate = false;
                            }
                        }
                        $payloadPost = array_merge($payloadPost, $payload);
                    }

                    if ($notCustomerVendorCreate) {

                        $return = $this->CreateCustomerOrVendor($type, $payloadPost, $user_id, $user_integration_id, $account);
                    } else {
                        $return = false;
                    }


                    return $return;
                }
            }
        } else {

            $NO = $this->GenerateNumber();
            if ($is_customer) {
                //Create New Customer

                $customerNo = "CUST" . $NO;
                $payloadPost = [
                    "lobId" => $lob,
                    "customerNo" => $customerNo,
                ];
                if (!empty($find->customer_name) && isset($find->customer_name)) {
                    unset($payload['name']);
                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                        $payloadPost['name'] =  $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                    } else {
                        $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                    }
                    unset($payload['company_name']);
                } else {
                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {

                        $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . $customerNo;
                    } else {
                        $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : $customerNo;
                    }
                    unset($payload['company_name']);
                }
                if (!empty($find->email) && isset($find->email)) {
                    unset($payload['email']);
                    $payloadPost['email'] = $find->email;
                } else {
                    if (!empty($payload['email']) && isset($payload['email'])) {
                        $payloadPost['email'] = $payload['email'];
                        unset($payload['email']);
                    } else {

                        if ($CustomEmail) {
                            $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);

                            if (!is_array($customerVendorId) && !is_null($customerVendorId)) {
                                $notCustomerVendorCreate = false;
                                $return = $customerVendorId;
                            } else if (is_null($customerVendorId)) {
                                $return =  $notCustomerVendorCreate = false;
                            } else {
                                $notCustomerVendorCreate = true;
                                $payloadPost['email'] = $CustomEmail;
                            }
                        } else {
                            $notCustomerVendorCreate = false;
                        }
                    }
                }

                $payloadPost = array_merge($payloadPost, $payload);
            } else {

                //Create New Vendor
                $payloadPost = [
                    "lobId" => $lob,
                    "vendorNo" => $NO,
                ];
                if (!empty($find->customer_name) && isset($find->customer_name)) {
                    unset($payload['name']);

                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {
                        $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name) : $payload['company_name'] . "-" . $this->removeNonASCII("’", "'", $find->customer_name);
                    } else {
                        $payloadPost['name'] = $this->removeNonASCII("’", "'", $find->customer_name);
                    }
                    unset($payload['company_name']);
                } else {
                    if (isset($payload['company_name']) && !empty($payload['company_name'])) {

                        $payloadPost['name'] = isset($payload['name']) ? $payload['company_name'] . "-" . $payload['name'] : $payload['company_name'] . "-" . "Vendor-" . $NO;
                    } else {
                        $payloadPost['name'] = isset($payload['name']) ? $payload['name'] : "Vendor-" . $NO;
                    }
                    unset($payload['company_name']);
                }

                if (!empty($find->email) && isset($find->email)) {
                    $payloadPost['arEmail'] = $find->email;
                    $payloadPost['podEmail'] = $find->email;
                    $payloadPost['orderEmail'] = $find->email;
                } else if ( !$payload['arEmail']) {

                    if ($CustomEmail) {
                        $customerVendorId = $this->CreateCustomerOrVendorByDefaultEmail($type, $CustomEmail,  $user_id, $user_integration_id, $account);

                        if (!is_array($customerVendorId) && !is_null($customerVendorId)) {
                            $notCustomerVendorCreate = false;
                            $return = $customerVendorId;
                        } else if (is_null($customerVendorId)) {
                            $return =  $notCustomerVendorCreate = false;
                        } else {
                            $notCustomerVendorCreate = true;
                            $payload['arEmail'] = $CustomEmail;
                            $payload['podEmail'] = $CustomEmail;
                            $payload['orderEmail'] = $CustomEmail;
                        }
                    } else {
                        $notCustomerVendorCreate = false;
                    }
                }

                $payloadPost = array_merge($payloadPost, $payload);
            }
            if ($notCustomerVendorCreate) {

                $return = $this->CreateCustomerOrVendor($type, $payloadPost, $user_id, $user_integration_id, $account);
            } else {
                $return = $return;
            }


            return $return;
        }
    }
    private function removeNonASCII($remove, $replace, $string)
    {
        return str_replace($remove, $replace, $string);
    }

    /* Get Customer for address from address table*/
    public function GetCustomerAddress($addressType, $orderNo, $type = NULL)
    {
        /* Find Address */
        $find = PlatformOrderAddress::where([
            'platform_order_id' => $orderNo, 'address_type' => $addressType
        ])->select('address_name', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'phone_number', 'firstname', 'lastname', 'ship_speed', 'email', 'company')->first();
        if ($find) {
            $stateName = isset($find->address4) ? $find->address4 : '';
            $countryName = isset($find->country) ? $find->country : '';
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
            if ($countryName) {
                $FindCountry = PlatformCountry::select('iso')->where(function ($query) use ($countryName) {
                    $query->where(
                        'name',
                        '=',
                        $countryName
                    )
                        ->orWhere('iso', '=', $countryName);
                })->first();
                $country = isset($FindCountry->iso) ? $FindCountry->iso :  $countryName;
            } else {
                $country = $countryName;
            }
            $address_name = isset($find->address_name) ? $find->address_name : '';
            $company_name = isset($find->company) && !empty($find->company) ? $find->company :  $address_name;
            $address1 = isset($find->address1) ? $find->address1 : '';
            $address2 = isset($find->address2) ? $find->address2 : '';
            $city = isset($find->address3) ? $find->address3 : '';

            $zip = isset($find->postal_code) ? $find->postal_code : '';

            $telephone = isset($find->phone_number) ? $find->phone_number : '';
            $email = isset($find->email) ? $find->email : '';

            if ($type == "Customer") {
                return [
                    "name" => $this->removeNonASCII("’", "'", $address_name),
                    "company_name" => $this->removeNonASCII("’", "'", $company_name),
                    "street" => $this->removeNonASCII("’", "'", $address1 . " " . $address2),
                    "city" => $this->removeNonASCII("’", "'", $city),
                    "state" => $state,
                    "zipCode" => $zip,
                    "country" => $country,
                    "phone" =>  $this->removeNonASCII("\u202c", "", $telephone),
                    "email" =>  $email,
                    "packageCarrierId" => 0,
                    "truckCarrierId" => 0,
                    "weightBreak" => 0,
                    "residential" =>  "Yes",
                ];
            } else if ($type == "Vendor") {
                return [
                    "name" => $this->removeNonASCII("’", "'", $address_name),
                    "company_name" => $this->removeNonASCII("’", "'", $company_name),
                    "street" => $this->removeNonASCII("’", "'", $address1),
                    "street2" =>  $this->removeNonASCII("’", "'", $address2),
                    "city" => $this->removeNonASCII("’", "'", $city),
                    "state" => $state,
                    "zipCode" => $zip,
                    "country" => $country,
                    "phone" =>  $this->removeNonASCII("\u202c", "", $telephone),
                    "arEmail" =>  $email,
                    "orderEmail" =>  $email,
                    "podEmail" =>  $email,
                    "inactive" => 'No'
                ];
            }
            return $find;
        }
        return false;
    }
    /* find Warehouse Address */
    public function getWarehouseAddress($platformObjectDataId,$warehouseName=null)
    {
        $address = [];
        $wh = PlatformObjectDataAdditionalInformation::where('platform_object_data_id', $platformObjectDataId)->first();
        if ($wh) {
            $stateName = isset($wh->state) ? $wh->state : null;
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
            $countryName = isset($wh->country) ? $wh->country : null;
            if ($countryName) {
                $FindCountry = PlatformCountry::select('name')->where(function ($query) use ($countryName) {
                    $query->where(
                        'name',
                        '=',
                        $countryName
                    )
                        ->orWhere('iso', '=', $countryName);
                })->first();
                $country = isset($FindCountry->name) ? $FindCountry->name :  $countryName;
            } else {
                $country = $countryName;
            }
            $address = [
                "shipToCompany" =>  $warehouseName,
                "shipToStreet" => isset($wh->address1) ? $wh->address1 : null,
                "shipToCity" =>  isset($wh->city) ? $wh->city : null,
                "shipToState" => $state,
                "shipToZip" => isset($wh->postal_code) ? $wh->postal_code : null,
                "shipToCountry" => $country
            ];
            if (!in_array(strtoupper($countryName), ['US', 'USA'])) {
                $shipTo['shipToStreet3'] = $state;
                unset($shipTo["shipToState"]);
            }
        }
        return $address;
    }
    /* Prepare Items */
    public function PrepareItems($where)
    {
        return  DB::table('platform_order_line as pol')->leftJoin('platform_product as pp', 'pol.api_product_id', '=', 'pp.api_product_id')->select('pol.api_product_id', 'pol.api_order_line_id')->where($where)->first();
    }
    private function GenerateNumber($digits = 7)
    {
        return str_pad(rand(0, pow(10, $digits) - 1), $digits, '0', STR_PAD_LEFT);
    }
    /* Create Customer/Vendor */
    private function CreateCustomerOrVendor($type = "Customer", $payload, $user_id, $user_integration_id, $account = NULL)
    {

        if (!isset($account)) {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_key',  'platform_id', 'id', 'user_id', 'api_domain']);
        }
        if ($type == "Customer") {

            $url = "customer";
        } else if ($type == "Vendor") {

            $url = "vendor";
        }

        $apicall = $this->infoplus->_API_CALL($account, "POST", $url, [], $payload);
        $response = $apicall['body'];

        if (is_array($response) && isset($response['id'])) {

            $details = [
                "user_id" => $user_id,
                "user_integration_id" => $user_integration_id,
                "platform_id" => $this->platformId,
                "api_customer_id" => $response['id'],
                "api_customer_code" => ($type == "Customer") ? $response['customerNo'] : $response['vendorNo'],
                "customer_name" => $response['name'],
                "email" => ($type == "Customer") ? $response['email'] : $response['arEmail'],
                "type" => $type,
            ];

            PlatformCustomer::insert($details);
            return ($type == "Customer") ? $response['customerNo'] : $response['id'];
        } else {
            return [$this->handleErrorResponse($apicall)];
        }
        return false;
    }
    /* Search Category & Sub Category And Create */
    public function searchCategoryAndCreate($type, $categoryText, $default_order_lob, $user_id, $user_integration_id, $platform_object_id = null, $account = NULL)
    {
        $categoryId = false;
        if (!isset($account)) {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_key',  'platform_id', 'id', 'user_id', 'api_domain']);
        }

        if ($type == "category") {
            if ($platform_object_id) {
                $platform_object_id = $this->helper->getObjectId('category');
            }
            $searchUrl = "itemCategory/search";
            $createUrl = "itemCategory";
        } else {
            if ($platform_object_id) {
                $platform_object_id = $this->helper->getObjectId('sub_category');
            }
            $searchUrl = "itemSubCategory/search";
            $createUrl = "itemSubCategory";
        }
        $value = $this->infoplus->checkStringQuotes($categoryText);
        if ($value == "single") {
            $filter = 'name eq "' . $categoryText . '"';
        } else if ($value == "double" || is_null($value)) {
            $filter = "name eq '" . $categoryText . "'";
        }
        $apicall = $this->infoplus->_API_CALL($account, "GET", $searchUrl, ["filter" => $filter], [], 'v3.0');
        $response = $apicall['body'];

        if (!isset($response['errors']) && $apicall['status_code'] == 200) {
            if (is_array($response) && !empty($response)) {
                //If category array is not empty
                foreach ($response as $category) {
                    $update = PlatformObjectData::updateOrCreate([
                        "user_id" => $user_id,
                        "user_integration_id" => $user_integration_id,
                        "platform_id" => $this->platformId,
                        "platform_object_id" => $platform_object_id,
                        "api_id" => $category['internalId']
                    ], [
                        "user_id" => $user_id,
                        "user_integration_id" => $user_integration_id,
                        "platform_id" => $this->platformId,
                        "platform_object_id" => $platform_object_id,
                        "api_id" => $category['internalId'],
                        "name" => $category['name'],
                        "status" => 1,
                    ]);
                    if ($update) {
                        $find = PlatformObjectDataAdditionalInformation::where(['platform_object_data_id' => $update->id, 'lob' => $category['lobId']])->first();
                        if (!$find) {
                            PlatformObjectDataAdditionalInformation::create(['platform_object_data_id' => $update->id, 'user_integration_id' => $user_integration_id, 'lob' => $category['lobId']]);
                        }
                    }
                    if ($default_order_lob == $category['lobId']) {
                        $categoryId = $category['internalId'];
                    }
                    break;
                }
            } else {
                //create new category
                $categoryId = $this->GenerateNumber(4); // maximum allowed which is 32,767."
                $payload = [
                    "lobId" => $default_order_lob,
                    "id" => $categoryId,
                    "name" => $categoryText
                ];

                $apicall = $this->infoplus->_API_CALL($account, "POST", $createUrl, [], $payload, 'v3.0');
                $response = $apicall['body'];

                if (!isset($response['errors']) && $apicall['status_code'] == 200) {
                    $update = PlatformObjectData::updateOrCreate([
                        "user_id" => $user_id,
                        "user_integration_id" => $user_integration_id,
                        "platform_id" => $this->platformId,
                        "platform_object_id" => $platform_object_id,
                        "api_id" => $response['internalId']
                    ], [
                        "user_id" => $user_id,
                        "user_integration_id" => $user_integration_id,
                        "platform_id" => $this->platformId,
                        "platform_object_id" => $platform_object_id,
                        "api_id" => $response['internalId'],
                        "name" => $response['name'],
                        "status" => 1,
                    ]);
                    if ($update) {
                        $find = PlatformObjectDataAdditionalInformation::where(['platform_object_data_id' => $update->id, 'lob' => $default_order_lob])->first();
                        if (!$find) {
                            PlatformObjectDataAdditionalInformation::create(['platform_object_data_id' => $update->id, 'user_integration_id' => $user_integration_id, 'lob' => $default_order_lob]);
                        }
                        // PlatformObjectDataAdditionalInformation::updateOrCreate(['platform_object_data_id'=>$update->id,'lob'=>$default_order_lob],['platform_object_data_id'=>$update->id,'user_integration_id'=>$user_integration_id,'lob'=>$default_order_lob]);

                    }
                    $categoryId = $response['internalId'];
                } else {
                    return $this->handleErrorResponse($apicall);
                }
            }
        } else {
            return $this->handleErrorResponse($apicall);
        }
        return $categoryId;
    }

    /* Insert / Update Product */
    public function UpdateOrCreateProduct($user_id, $user_integration_id, $product)
    {
        $productList = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $product['id'],
            'api_product_code' => isset($product['lobId']) ? $product['lobId'] : null,
            'sku' => $product['sku'],
            'inventory_sync_status' => "Ready",
            'api_inventory_lastmodified_time' => $product['inventoryUpdateTimestamp'],
        ];
        $where = [
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $product['id']
        ];
        $find = PlatformProduct::select('id', 'sku', 'inventory_sync_status', 'api_inventory_lastmodified_time')->where($where)->first();
        if ($find) {
            if ($find->api_inventory_lastmodified_time != $product['inventoryUpdateTimestamp']) {
                $find->api_inventory_lastmodified_time = $product['inventoryUpdateTimestamp'];
                $find->sku = $product['sku'];
                $find->inventory_sync_status = "Ready";
                $find->save();
            }
            $productId = $find->id;
        } else {
            $productSave = PlatformProduct::create($productList);
            $productId = isset($productSave->id) ? $productSave->id : 0;
        }
        //$product = PlatformProduct::updateOrCreate($where, $productList);
        return $productId;
    }
    /* Insert / Update Product Inventory */
    public function UpdateOrCreateProductInventory($user_id, $user_integration_id, $product)
    {

        $key = 1;
        while (10 >= $key) {
            /* Warehouse */
            $attWKey = "warehouse{$key}Id";
            if (isset($product[$attWKey])) {
                $warehouseId = $product[$attWKey];
                /* Quantity */
                $orderableQKey = "w{$key}OrderableQuantity";
                $orderableQuantity =  isset($product[$orderableQKey]) ? $product[$orderableQKey] : 0;
                // $attQKey = "w{$key}OnHandQuantity";
                // $fulfillQKey = "w{$key}InFulfillmentProcessQuantity";
                // $onhandQuantity =  isset($product[$attQKey]) ? $product[$attQKey] : 0;
                // $inFulfillmentProcessQuantity = isset($product[$fulfillQKey]) ? $product[$fulfillQKey] : 0;
                // $overAllQty = $onhandQuantity + $inFulfillmentProcessQuantity;
                $productInventoryList = [
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_product_id' => $product['platform_product_id'],
                    'sku' => $product['sku'],
                    'api_warehouse_id' => $warehouseId,
                    'quantity' => $orderableQuantity,
                    'sync_status' => "Ready",
                    'api_updated_at' => $product['inventoryUpdateTimestamp'],
                ];
                PlatformProductInventory::updateOrCreate([
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_product_id' => $product['platform_product_id'],
                    'api_warehouse_id' => $warehouseId,
                ], $productInventoryList);
                // $find = PlatformProductInventory::select('id', 'quantity', 'sync_status')->where([
                //     'user_id' => $user_id,
                //     'user_integration_id' => $user_integration_id,
                //     'platform_id' => $this->platformId,
                //     'platform_product_id' => $product['platform_product_id'],
                //     'api_warehouse_id' => $warehouseId,
                // ])->first();
                // if ($find) {
                //     if ($find->quantity != $orderableQuantity) { //if qty changes in warehouse wise qty, set sync status=Ready
                //         $find->quantity = $orderableQuantity;
                //         $find->sync_status = "Ready";
                //         $find->save();
                //     }
                // } else {
                //     PlatformProductInventory::create($productInventoryList);
                // }
            }
            $key++;
        }
    }
    /* For Item Receipt for sales item receipt return*/
    public function ItemReceiptReturn($user_workflow_rule_id, $platform_order_id, $refund_order_number, $product, $user_id = NULL, $user_integration_id = NULL)
    {

        $product_parimary_id = $this->CreateOrUpdateProduct($user_id, $user_integration_id, $product);
        $platform_refund_id = NULL;
        $find = PlatformOrderRefund::select('id')->where([
            'platform_order_id' => $platform_order_id,
            'api_id' => $product['id']
        ])->first();
        if (!$find) {
            $insert = PlatformOrderRefund::create([
                'platform_order_id' => $platform_order_id,
                'refund_order_number' => $refund_order_number,
                'api_id' => $product['id'],
                'user_workflow_rule_id' => $user_workflow_rule_id,
                'date_created' => date('Y-m-d H:i:s', strtotime($product['receivedDate'])),
            ]);
            if (isset($insert->id)) {
                $platform_refund_id = $insert->id;
                PlatformOrderRefundLine::updateOrCreate(['platform_order_refund_id' => $platform_refund_id], [
                    'platform_order_refund_id' => $platform_refund_id,
                    'api_order_line_id' => $product['id'],
                    'qty' => $product['receivedQuantity'] ? $product['receivedQuantity'] : 0,
                    'sku' => $product['sku'],
                    'price' => isset($product['cost']) ? $product['cost'] : 0,
                    'api_warehouse_id' => $product['warehouseId'],
                    'api_release_date' => date('Y-m-d H:i:s', strtotime($product['receivedDate'])),
                    'row_type' => "ITEM"
                ]);
            }
        } else {
            $platform_refund_id = $find->id;
            PlatformOrderRefundLine::updateOrCreate(['platform_order_refund_id' => $platform_refund_id], [
                'platform_order_refund_id' => $platform_refund_id,
                'api_order_line_id' => $product['id'],
                'qty' => $product['receivedQuantity'] ? $product['receivedQuantity'] : 0,
                'sku' => $product['sku'],
                'price' => isset($product['cost']) ? $product['cost'] : 0,
                'api_warehouse_id' => $product['warehouseId'],
                'api_release_date' => date('Y-m-d H:i:s', strtotime($product['receivedDate'])),
                'row_type' => "ITEM"
            ]);
        }

        if ($platform_refund_id) {
            /* Inventory Credit */
            $quantity = $product['receivedQuantity'] ? $product['receivedQuantity'] : 0;
            $sku = $product['sku'];
            $api_warehouse_id = $product['warehouseId'];

            $this->CreateOrUpdateProductInventory($user_id, $user_integration_id, $user_workflow_rule_id, $product_parimary_id, $platform_refund_id, $sku, $api_warehouse_id, $quantity);
        }
    }
    /* Inventory Update in inventory table and inventory credits */
    private function CreateOrUpdateProductInventory($user_id, $user_integration_id, $user_workflow_rule_id, $product_parimary_id, $platform_refund_id, $sku, $warehouse_id, $quantity)
    {
        $inventoryId = NULL;
        $where = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'platform_product_id' => $product_parimary_id,
            'api_warehouse_id' => $warehouse_id,
        ];
        $find = PlatformProductInventory::where($where)->first();
        if ($find) {
            $inventoryId = $find->id;
        } else {
            $where['sku'] = $sku;
            $inventory = PlatformProductInventory::create($where);
            $inventoryId = $inventory->id;
        }
        if ($inventoryId) {
            /* Add inventory in credits */
            $find = PlatformProductInventoryCredit::where([
                'platform_inventory_id' => $inventoryId,
                'platform_refund_order_id' => $platform_refund_id
            ])->first();
            if (!$find) {
                PlatformProductInventoryCredit::create([
                    'platform_inventory_id' => $inventoryId,
                    'platform_refund_order_id' => $platform_refund_id,
                    'user_workflow_rule_id' => $user_workflow_rule_id,
                    'quantity' => $quantity,
                    'sync_status' => "Ready"
                ]);
            }
        }
    }
    /* Insert/Update Product */
    public function CreateOrUpdateProduct($user_id, $user_integration_id, $product)
    {
        $product_id = NULL;
        $Where = [
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'sku' => $product['sku']
        ];
        $create = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'sku' => $product['sku'],
            'inventory_sync_status' => "Ready"
        ];
        $find = PlatformProduct::select('id', 'inventory_sync_status')->where($Where)->first();
        if ($find) {
            $product_id = $find->id;
            $find->inventory_sync_status = "Ready";
            $find->save();
        } else {
            $product_response = PlatformProduct::create($create);
            $product_id = $product_response->id;
        }
        return $product_id;
    }
    /* Find SKUs and quantity from source platform products */
    public function kitComponentSKU($productIdentity, $product_id, $user_id, $user_integration_id, $source_platform_id)
    {
        $findChilds = PlatformProduct::with('kitQuantity:platform_product_id,quantity')->select('id', 'sku')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id])->whereRaw("find_in_set('" . $product_id . "',parent_product_id)")->get();;
        $componentSku = [];
        $source_row_data = $productIdentity['source_identity']; //Source Identity
        $destination_row_data = $productIdentity['destination_identity']; //Destination Identity
        if (count($findChilds) > 0) {

            foreach ($findChilds as $child_product) {
                $product = $child_product->toArray();

                $count = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $source_row_data =>  $product[$destination_row_data]])->count(); //Find destination product by product identity mapping 

                if ($count && isset($child_product->kitQuantity) && $child_product->kitQuantity->quantity) {

                    $componentSku[] = [
                        'sku' => $child_product->sku,
                        'quantity' => $child_product->kitQuantity->quantity
                    ];
                }
            }
        }

        return $componentSku;
    }
    /* Update Or Create Product Kit */
    public function UpdateOrCreateKitProduct($account, $product, $default_order_lob, $componentSku, $method = "POST")
    {

        if ($method == "POST") {
            $createUpdateKit = [
                "lobId" => $default_order_lob,
                "kitSKU" => $product->sku,
                "touches" => 0,
                "isKOD" => "Yes",
                "kitComponentList" => $componentSku
            ];
        } else {
            $createUpdateKit = [
                "id" => $product->api_group_id,
                "lobId" => $default_order_lob,
                "kitSKU" => $product->sku,
                "touches" => 0,
                "isKOD" => "Yes",
                "kitComponentList" => $componentSku
            ];
        }

        return $this->infoplus->_API_CALL($account, $method, "kit", [], $createUpdateKit, "v3.0");
    }
    /* Search kitSKU */
    public function searchKitSKU($account, $SKU)
    {
        $value = $this->infoplus->checkStringQuotes($SKU);
        if ($value == "single") {
            $filter = 'kitSKU eq "' . $SKU . '"';
        } else if ($value == "double" || is_null($value)) {
            $filter = "kitSKU eq '" . $SKU . "'";
        }
        $arguments = [
            "limit" => 1,
            "page" => 1,
            "filter" =>  $filter,
            "sort" =>  "!kitSKU"
        ];

        return $this->infoplus->_API_CALL($account, "GET", "kit/search", $arguments, [], "v3.0");
    }
    /* Update Kit Product */
    public function UpdateKitProduct($account, $infoplusProduct, $sourceProduct, $default_order_lob, $identity, $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $object_id)
    {
        $componentSku = $this->kitComponentSKU($identity, $sourceProduct->id, $user_id, $user_integration_id, $source_platform_id); //find component products from source platform products

        if (!empty($componentSku)) {
            $apicall = $this->UpdateOrCreateKitProduct($account, $infoplusProduct, $default_order_lob, $componentSku, "PUT");
            $response = $apicall['body'];
            if (!isset($response['errors'])) {
                /* Update Source product status */
                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $infoplusProduct->id], ['id' => $sourceProduct->id]);
                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'success', $sourceProduct->id, NULL);
            } else {
                $error = $this->handleErrorResponse($apicall);
                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $sourceProduct->id]);
                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'failed', $sourceProduct->id, $error);
            }
        } else {
            //component error
            $error = "No component sku found to create kit product!";
            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $sourceProduct->id]);
            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'failed', $sourceProduct->id, $error);
        }
    }
    /* Create Kit Product */
    public function CreateKitProduct($account, $infoplusProduct, $sourceProduct, $default_order_lob, $identity, $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $object_id)
    {
        $componentSku = $this->kitComponentSKU($identity, $sourceProduct->id, $user_id, $user_integration_id, $source_platform_id); //find component products from source platform products
        if (!empty($componentSku)) {

            $apicall = $this->UpdateOrCreateKitProduct($account, $infoplusProduct, $default_order_lob, $componentSku, "POST");
            $response = $apicall['body'];
            if (!isset($response['errors'])) {
                /* Update Infoplus Product Detail */
                $this->mobj->makeUpdate('platform_product', ['bundle' => 1, 'api_group_id' => $response['id']], ['id' => $infoplusProduct->id]);
                /* Update Source product status */
                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $infoplusProduct->id], ['id' => $sourceProduct->id]);
                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'success', $sourceProduct->id, NULL);
            } else {
                $error = $this->handleErrorResponse($apicall);
                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $sourceProduct->id]);
                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'failed', $sourceProduct->id, $error);
            }
        } else {
            //component error
            $error = "No component sku found to create kit product!";
            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $sourceProduct->id]);
            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, 'failed', $sourceProduct->id, $error);
        }
    }
    /* Search Commodity Code */
    public function searchCommodityCode($account, $code)
    {
        $return = null;
        $value = $this->infoplus->checkStringQuotes($code);
        if ($value == "single") {
            $filter = 'code eq "' . $code . '"';
        } else if ($value == "double" || is_null($value)) {
            $filter = "code eq '" . $code . "'";
        }
        $arguments = [
            "limit" => 1,
            "page" => 1,
            "filter" =>  $filter,
            "sort" =>  "!code"
        ];
        $apicall = $this->infoplus->_API_CALL($account, "GET", "commodityCode/search", $arguments, [], "v3.0");
        $response = $apicall['body'];
        if (!isset($response['errors']) && is_array($response) && !empty($response)) {
            $return = isset($response[0]['id']) ? $response[0]['id'] : null;
        }
        return $return;
    }
    /* Prepare Payload to create or update product */
    public function createAndUpdateProduct($account, $product, $price, $default_order_lob, $method, $sourceAccountExtraInformation = null)
    {

        $createOrUpdate = [
            "id" => isset($product->infoplus_product_id) ? $product->infoplus_product_id : null,
            "lobId" => $default_order_lob, //required
            "sku" => $product->sku, //required
            "upc" => $product->upc,
            "itemDescription" => $product->product_name,
            "additionalDescription" => strip_tags($product->description),
            "sellPrice" => $price['sale_price'],
            "vendorPrice" => $price['vendor_price'],
            "majorGroupId" => isset($product->majorGroupId) ? $product->majorGroupId : null,
            "subGroupId" => isset($product->subGroupId) ? $product->subGroupId : null,
            "backorder" => "Yes",
            "chargeCode" => "Not Chargeable",
            "criticalAmount" => 0,
            "maxCycle" => 99999,
            "maxInterim" => 99999,
            "seasonalItem" => "No",
            "secure" => "No",
            "unitsPerWrap" => 1,
            "wrapCode" => "EACH", //required
            "unitCode" => "EACH", //required
            "forwardLotMixingRule" =>  isset($product->forward_lot_mixing_rule) ? $product->forward_lot_mixing_rule : "Inventory Properties", //required                                            
            "allocationRule" => isset($product->allocation_rule) ? $product->allocation_rule : "Labor Optimized",
            "storageLotMixingRule" => isset($product->storage_lot_mixing_rule) ? $product->storage_lot_mixing_rule : "Item Receipt",
            "forwardItemMixingRule" => isset($product->forward_item_mixing_rule) ? $product->forward_item_mixing_rule : "Multi",
            "storageItemMixingRule" => isset($product->storage_item_mixing_rule) ? $product->storage_item_mixing_rule : "Single",
            "receivingCriteriaSchemeId" => 1, //required
            "hazmat" => "No",
            "status" => "Active", //required

        ];
        if (isset($product->commodityCodeId)) {
            //greater than zero
            $createOrUpdate['commodityCodeId'] = $product->commodityCodeId;
        }
        if ($product->weight) {
            //greater than zero
            $weightUnit = null;
            $unit = isset($sourceAccountExtraInformation->account_product_weight_unit) ? strtolower($sourceAccountExtraInformation->account_product_weight_unit) : null;
            if ($unit == strtolower("POUND") || $unit == "lb" || $unit == "lbs") {
                $weightUnit = "POUND";
            } else if ($unit == strtolower("GRAM") || $unit == "g") {
                $weightUnit = "GRAM";
            } else if ($unit == strtolower("KILOGRAM") || $unit == "kg") {
                $weightUnit = "KILOGRAM";
            } else if ($unit == strtolower("MILLIGRAM") || $unit == "mgm") {
                $weightUnit = "MILLIGRAM";
            } else if ($unit == strtolower("MICROGRAM") || $unit == "mc") {
                $weightUnit = "MICROGRAM";
            } else if ($unit == strtolower("OUNCE") || $unit == "oz") {
                $weightUnit = "OUNCE";
            }

            if ($weightUnit) {
                $createOrUpdate['weightPerWrap'] = Conversion::convert($product->weight, $weightUnit)->to('POUND')->format();
            } else {
                $createOrUpdate['weightPerWrap'] = $product->weight;
            }
        }
        if ($product->lenght) {
            //greater than zero
            $lenghtUnit = null;
            $unit = isset($sourceAccountExtraInformation->account_product_lenght_unit) ? strtolower($sourceAccountExtraInformation->account_product_lenght_unit) : null;
            if ($unit == strtolower("INCH") || $unit == "inc" || $unit == "in" || $unit == "inches") {
                $lenghtUnit = "INCH";
            } else  if ($unit == "mm" || $unit == strtolower("MILLIMETRE")) {
                $lenghtUnit = "MILLIMETRE";
            } else  if ($unit == strtolower("KILOMETRE") || $unit == "km") {
                $lenghtUnit = "KILOMETRE";
            } else  if ($unit == strtolower("METRE") || $unit == "m") {
                $lenghtUnit = "METRE";
            } else  if ($unit == strtolower("CENTIMETRE") || $unit == "cm") {
                $lenghtUnit = "CENTIMETRE";
            } else  if ($unit == strtolower("FOOT") || $unit == "ft") {
                $lenghtUnit = "FOOT";
            }

            if ($lenghtUnit) {
                $createOrUpdate['length'] = Conversion::convert($product->lenght, $lenghtUnit)->to('INCH')->format();
            } else {
                $createOrUpdate['length'] = $product->lenght;
            }
        }
        if ($product->width) {
            //greater than zero
            $widthUnit = null;
            $unit = isset($sourceAccountExtraInformation->account_product_lenght_unit) ? strtolower($sourceAccountExtraInformation->account_product_lenght_unit) : null;
            if ($unit == strtolower("INCH") || $unit == "inc" || $unit == "in" || $unit == "inches") {
                $widthUnit = "INCH";
            } else  if ($unit == "mm" || $unit == strtolower("MILLIMETRE")) {
                $widthUnit = "MILLIMETRE";
            } else  if ($unit == strtolower("KILOMETRE") || $unit == "km") {
                $widthUnit = "KILOMETRE";
            } else  if ($unit == strtolower("METRE") || $unit == "m") {
                $widthUnit = "METRE";
            } else  if ($unit == strtolower("CENTIMETRE") || $unit == "cm") {
                $widthUnit = "CENTIMETRE";
            } else  if ($unit == strtolower("FOOT") || $unit == "ft") {
                $widthUnit = "FOOT";
            }
            if ($widthUnit) {
                $createOrUpdate['width'] = Conversion::convert($product->width, $widthUnit)->to('INCH')->format();
            } else {
                $createOrUpdate['width'] = $product->width;
            }
        }
        if ($product->height) {
            //greater than zero
            $heightUnit = null;
            $unit = isset($sourceAccountExtraInformation->account_product_lenght_unit) ? strtolower($sourceAccountExtraInformation->account_product_lenght_unit) : null;
            if ($unit == strtolower("INCH") || $unit == "inc" || $unit == "in" || $unit == "inches") {
                $heightUnit = "INCH";
            } else  if ($unit == "mm" || $unit == strtolower("MILLIMETRE")) {
                $heightUnit = "MILLIMETRE";
            } else  if ($unit == strtolower("KILOMETRE") || $unit == "km") {
                $heightUnit = "KILOMETRE";
            } else  if ($unit == strtolower("METRE") || $unit == "m") {
                $heightUnit = "METRE";
            } else  if ($unit == strtolower("CENTIMETRE") || $unit == "cm") {
                $heightUnit = "CENTIMETRE";
            } else  if ($unit == strtolower("FOOT") || $unit == "ft") {
                $heightUnit = "FOOT";
            }
            if ($heightUnit) {
                $createOrUpdate['height'] = Conversion::convert($product->height, 'INCH')->to('INCH')->format();
            } else {
                $createOrUpdate['height'] = $product->height;
            }
        }
        if ($method == "POST") {
            //If we are going to create product, unset infoplus product api_id
            unset($createOrUpdate['id']);
        } else {
            //If we are going to update product, unset infoplus sku
            unset($createOrUpdate['sku']);
        }

        return  $this->infoplus->_API_CALL($account, $method, "item", [], $createOrUpdate, "v3.0");
    }
    /* Update Or Create Kit Product By Source Normal Product */
    public function KitProduct($product, $default_order_lob, $account, $productIdentity, $user_workflow_rule_id, $object_id)
    {
        $parentProductIds = explode(",", $product->parent_product_id);

        if (count($parentProductIds) > 0) {
            $user_id = $product->user_id;
            $user_integration_id = $product->user_integration_id;
            $platform_id = $product->platform_id;
            $source_row_data = $productIdentity['source_identity']; //Source Identity
            $destination_row_data = $productIdentity['destination_identity']; //Destination Identity

            foreach ($parentProductIds as $key => $bundleProduct) {
                if (!empty($bundleProduct) && !is_null($bundleProduct)) {

                    $findSourceBundleProduct =  DB::table('platform_product as f')->select('f.id', 'f.user_id', 'f.user_integration_id', 'f.platform_id', 'f.sku', 's.sku as destination_sku', 's.linked_id', 'f.parent_product_id', 'f.bundle', 's.api_group_id', 's.bundle as destination_bundle', 's.id as destination_prodcut_id')
                        ->join('platform_product as s', 'f.' . $destination_row_data, '=', 's.' . $source_row_data)->where(['f.id' => $bundleProduct, 'f.is_deleted' => 0])->first();

                    if ($findSourceBundleProduct) {
                        if ($findSourceBundleProduct->destination_bundle && $findSourceBundleProduct->api_group_id > 0) { //If bundle=1 and api_group_id found || Go for KIT Update
                            $infoplusProduct = (object)[
                                'id' => $findSourceBundleProduct->destination_prodcut_id,
                                'sku' => $findSourceBundleProduct->destination_sku,
                                'api_group_id' => $findSourceBundleProduct->api_group_id
                            ];
                            $sourceProduct = (object)[
                                'id' => $findSourceBundleProduct->id,
                                'sku' => $findSourceBundleProduct->sku,

                            ];
                            $this->UpdateKitProduct($account, $infoplusProduct, $sourceProduct, $default_order_lob, $productIdentity, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_id, $object_id);
                        } else {
                            //If bundle=0 && api_group_id not set
                            $apicall = $this->searchKitSKU($account, $findSourceBundleProduct->sku); //Get Kit By SKU and update kit id in api_group_id
                            $response = $apicall['body'];
                            if (!isset($response['errors']) && is_array($response) && !empty($response)) {
                                //IF kit sku found as kit using api, get update those kit detail
                                $this->mobj->makeUpdate('platform_product', ['bundle' => 1, 'api_group_id' => $response[0]['id']], ['id' => $findSourceBundleProduct->destination_prodcut_id]);
                                $infoplusProduct = (object)[
                                    'id' => $findSourceBundleProduct->destination_prodcut_id,
                                    'sku' => $findSourceBundleProduct->destination_sku,
                                    'api_group_id' => $findSourceBundleProduct->api_group_id
                                ];
                                $sourceProduct = (object)[
                                    'id' => $findSourceBundleProduct->id,
                                    'sku' => $findSourceBundleProduct->sku,

                                ];
                                $this->UpdateKitProduct($account, $infoplusProduct, $sourceProduct, $default_order_lob, $productIdentity, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_id, $object_id);
                            } else {
                                /* Create new Kit */
                                $infoplusProduct = (object)[
                                    'id' => $findSourceBundleProduct->destination_prodcut_id,
                                    'sku' => $findSourceBundleProduct->destination_sku,
                                    'api_group_id' => $findSourceBundleProduct->api_group_id
                                ];
                                $sourceProduct = (object)[
                                    'id' => $findSourceBundleProduct->id,
                                    'sku' => $findSourceBundleProduct->sku,
                                ];
                                $this->CreateKitProduct($account, $infoplusProduct, $sourceProduct, $default_order_lob, $productIdentity, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_id, $object_id);
                            }
                        }
                    }
                }
            }
        }
    }
    /* Find Product Field Mapping Value */
    public function getCustomFieldMapping($product_id, $source_platform_id, $object_id, $user_integration_id)
    {
        $cus_values = $this->mobj->getResultByConditions('platform_custom_field_values', [
            'record_id' => $product_id,
            'platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id
        ], ['field_value', 'platform_field_id']);
        foreach ($cus_values as $cus_value) {
            $getMappedField = $this->map->getMappedField($user_integration_id, null, $object_id, [], $cus_value->platform_field_id);
            if (!empty($getMappedField['destination_field_name'])) {
                $destinationField = PlatformField::where([
                    'platform_id' => $this->platformId,
                    'custom_field_id' => $getMappedField['destination_custom_field_id'], 'platform_object_id' => $object_id
                ])->first();
                if ($destinationField) {
                    return $cus_value->field_value;
                }
            }
        }
    }
    /* Find Commodity Code */
    public function findCommodityCode($user_id, $user_integration_id, $commodityObjectId, $commodityCode)
    {
        $return = null;
        $find = PlatformObjectData::select('id', 'api_id')->where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'platform_object_id' => $commodityObjectId,
            'api_code' => $commodityCode,
        ])->first();
        if ($find) {
            $return = $find->api_id;
        }
        return $return;
    }
    /* Find & Create Category & Sub Category */
    public function findAndCreateCategory($user_id, $user_integration_id, $categoryText, $default_order_lob, $account, $type)
    {
        $categoryObjectId = $this->helper->getObjectId($type);
        $categoryId = $this->findCategoryId($user_id, $user_integration_id, $categoryObjectId, $categoryText, $default_order_lob);

        if (!$categoryId) {
            $categoryId = $this->searchCategoryAndCreate($type, $categoryText, $default_order_lob, $user_id, $user_integration_id, $categoryObjectId, $account); //search majorGroupID
        }
        return $categoryId;
    }
    public function findCategoryId($user_id, $user_integration_id, $objectId, $categoryText, $default_order_lob = null)
    {
        $return = null;
        $find = DB::table('platform_object_data')->join('platform_object_data_additional_information', 'platform_object_data.id', '=', 'platform_object_data_additional_information.platform_object_data_id')->where([
            'platform_object_data.user_id' => $user_id,
            'platform_object_data.user_integration_id' => $user_integration_id,
            'platform_object_data.platform_id' => $this->platformId,
            'platform_object_data.platform_object_id' => $objectId,
            'platform_object_data.name' =>  $categoryText,
            'platform_object_data_additional_information.lob' => $default_order_lob,
        ])->select('platform_object_data.id', 'platform_object_data.api_id')->first();
        // $find = PlatformObjectData::select('id', 'api_id')->where([
        //     'user_id' => $user_id,
        //     'user_integration_id' => $user_integration_id,
        //     'platform_id' => $this->platformId,
        //     'platform_object_id' => $objectId,
        //     'name' => $categoryText,
        // ])->first();
        if ($find) {
            $return = $find->api_id;
        }
        return $return;
    }
    /* ---Insert Order Details--- */
    public function SaveOrderDetails($payload)
    {

        $orderID = false;
        if (!empty($payload)) {

            DB::beginTransaction();
            try {
                $order = new PlatformOrder();
                $order->user_id = $payload['user_id'];
                $order->platform_id = $payload['platform_id'];
                $order->user_integration_id = $payload['user_integration_id'];
                $order->order_type =  $payload['order_type'];
                $order->api_order_id = $payload['api_order_id'];
                $order->order_date = $payload['order_date'];
                $order->order_number = $payload['order_number'];
                $order->sync_status = $payload['sync_status'];
                $order->linked_id =  $payload['linked_id'];
                $order->shipment_status =  $payload['shipment_status'];
                $order->order_updated_at = $payload['order_updated_at'];
                if ($order->save()) {
                    $orderID = $order->id;
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error($payload['user_integration_id'] . ' -> InfoplusServiceController -> SaveOrderDetails -> ' . $payload['api_order_id'] . " -> " . $e->getMessage());
            }
        }

        return $orderID;
    }
    public function SearchOrderByCustomerOrderNo($reference, $userIntegrationId = NULL, $account = NULL)
    {
        $api_error = $custom_error = $exception_error = false;
        $error = $order_id = null;
        try {
            if (!isset($account)) {
                $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_key',  'platform_id', 'id', 'user_id', 'api_domain']);
            }
            if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                //for only BP integration
                $value = $this->infoplus->checkStringQuotes($reference);
                if ($value == "single") {
                    $filter = 'customerOrderNo eq "' . $reference . '"';
                } else if ($value == "double" || is_null($value)) {
                    $filter = "customerOrderNo eq '" . $reference . "'";
                }
                $arguments = [
                    "limit" => 1,
                    "page" => 1,
                    "filter" => $filter
                ];
                $apicall = $this->infoplus->_API_CALL($account, "GET", "order/search", $arguments, [], "v3.0");
                $order_response = $apicall['body'];
                if (is_array($order_response)) {
                    if (isset($order_response[0])) {
                        $order_id = (int) $order_response[0]['orderNo'];
                    } else if (empty($order_response)) {
                        $custom_error = true;
                        $error =  "Order has been not found in Infoplus";
                    } else {
                        $error = $this->handleErrorResponse($apicall);
                        $api_error = true;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($userIntegrationId . ' -> InfoplusServiceController -> SearchOrderByCustomerOrderNo -> ' . $e->getLine() . " -> " . $e->getMessage());
            $error = "Unexpected, Brightpearl internal error";
            $exception_error = true;
        }
        return ['order_id' => $order_id, 'api_error' => $api_error, 'custom_error' => $custom_error, 'exception_error' => $exception_error, 'error' => $error];
    }

    /* find order source id or create new one */
    public function findOrCreateOrderSource($channelListMemo, $order, $account, $lobId)
    {
        $orderSourceId = null;
        try {
            if (isset($order)) {

                /* find source platform channel */
                $findChannel = PlatformOrderAdditionalInformation::select('api_channel_id')->where('platform_order_id', $order->id)->first();

                if ($findChannel) {
                    if (isset($channelListMemo[$findChannel->api_channel_id])) {

                        $orderSourceId = $channelListMemo[$findChannel->api_channel_id];
                    } else {

                        $channelObjectId = $this->helper->getObjectId('channel');

                        $channel = PlatformObjectData::where([
                            'user_integration_id' => $order->user_integration_id,
                            'platform_id' => $order->platform_id, //source platform id
                            'platform_object_id' => $channelObjectId,
                            'api_id' => $findChannel->api_channel_id,
                        ])->select('id', 'api_id', 'name')->first();

                        if ($channel) {
                            /* find order source in table */
                            $orderSourceObjectId = $this->helper->getObjectId('order_source');
                            $orderSource = DB::table('platform_object_data')->where([
                                'platform_object_data.user_id' => $order->user_id,
                                'platform_object_data.user_integration_id' => $order->user_integration_id,
                                'platform_object_data.platform_id' => $this->platformId, //destination platform id
                                'platform_object_data.platform_object_id' => $orderSourceObjectId,
                                'platform_object_data.name' =>  $channel->name,
                            ])->join('platform_object_data_additional_information', function ($join) use ($lobId) {
                                $join->on('platform_object_data_additional_information.platform_object_data_id', '=', 'platform_object_data.id')->where('lob', $lobId);
                            })->select('platform_object_data.id', 'platform_object_data.api_id', 'platform_object_data.name')->first();

                            if ($orderSource) {
                                // if order source id found in table
                                $orderSourceId = $orderSource->api_id;
                                $channelListMemo[$findChannel->api_channel_id] = $orderSourceId; //cache memo
                            } else {
                                // call api to find order source name in infoplus api
                                $value = $this->infoplus->checkStringQuotes($channel->name);
                                if ($value == "single") {
                                    $filter = 'name eq "' . $channel->name . '" and lobId eq ' . $lobId;
                                } else if ($value == "double" || is_null($value)) {
                                    $filter = "name eq '" . $channel->name . "' and lobId eq {$lobId}";
                                }
                                $arguments = [
                                    "limit" => 1,
                                    "page" => 1,
                                    "filter" => $filter
                                ];
                                $apicall = $this->infoplus->_API_CALL($account, "GET", "orderSource/search", $arguments, [], "v3.0");

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $body = $apicall['body'];
                                    if (is_array($body) && isset($body[0])) {

                                        $api_id =  $body[0]['id'];
                                        $api_name =  $body[0]['name'];
                                        $orderSource = PlatformObjectData::create([
                                            'user_id' => $order->user_id,
                                            'user_integration_id' => $order->user_integration_id,
                                            'platform_id' => $this->platformId, //destination platform id
                                            'platform_object_id' => $orderSourceObjectId,
                                            'name' => $api_name,
                                            'api_id' => $api_id,
                                        ]);
                                        if ($orderSource) {
                                            PlatformObjectDataAdditionalInformation::create([
                                                'user_integration_id' => $order->user_integration_id,
                                                'platform_object_data_id' => $orderSource->id,
                                                'lob' => $lobId
                                            ]);
                                        }
                                        $orderSourceId = $api_id;
                                        $channelListMemo[$findChannel->api_channel_id] = $orderSourceId; //cache memo

                                    } else {
                                        /* call api to create order source in infoplus */
                                        $payload = [
                                            "lobId" => $lobId,
                                            "name" => $channel->name,
                                        ];
                                        $apicall = $this->infoplus->_API_CALL($account, "POST", "orderSource", $arguments, $payload, "v3.0");
                                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                            $body = $apicall['body'];
                                            $api_id =  $body['id'];
                                            $api_name =  $body['name'];
                                            $orderSource = PlatformObjectData::create([
                                                'user_id' => $order->user_id,
                                                'user_integration_id' => $order->user_integration_id,
                                                'platform_id' => $this->platformId, //destination platform id
                                                'platform_object_id' => $orderSourceObjectId,
                                                'name' => $api_name,
                                                'api_id' => $api_id,
                                            ]);
                                            if ($orderSource) {
                                                PlatformObjectDataAdditionalInformation::create([
                                                    'user_integration_id' => $order->user_integration_id,
                                                    'platform_object_data_id' => $orderSource->id,
                                                    'lob' => $lobId
                                                ]);
                                            }
                                            $orderSourceId = $api_id;
                                            $channelListMemo[$findChannel->api_channel_id] = $orderSourceId; //cache memo
                                        } else {
                                            $orderSourceId = $this->handleErrorResponse($apicall);
                                        }
                                    }
                                } else {
                                    $orderSourceId = $this->handleErrorResponse($apicall);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($order->user_integration_id . ' -> InfoplusServiceController -> findOrCreateOrderSource -> ' . $e->getLine() . " -> " . $e->getMessage());
        }
        return $orderSourceId;
    }
    /* get before last bracket value */
    public function getSubstringBeforeLastBracket($string)
    {
        $lastBracketPos = strrpos($string, '(');
        if ($lastBracketPos !== false) {
            $substringBeforeLastBracket = substr($string, 0, $lastBracketPos);
            return trim($substringBeforeLastBracket);
        }
        return null;
    }
}
