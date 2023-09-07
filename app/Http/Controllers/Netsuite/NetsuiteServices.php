<?php

namespace App\Http\Controllers\Netsuite;

use App\Helper\Api\NetsuiteApi;
use App\Helper\Api\NetsuiteRestServices;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use App\Models\Enum\CustomFieldType;
use App\Models\Enum\PlatformName;
use App\Models\Enum\PlatformRecordType;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformCustomer;
use App\Models\PlatformDataMapping;
use App\Models\PlatformFieldOptionData;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use NetSuite\Classes\AccountSearchBasic;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\ClassificationSearchBasic;
use NetSuite\Classes\CustomerDeposit;
use NetSuite\Classes\CustomizationFieldType;
use NetSuite\Classes\CustomizationType;
use NetSuite\Classes\GetCustomizationIdRequest;
use NetSuite\Classes\GetCustomizationType;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\InboundShipment;
use NetSuite\Classes\InboundShipmentItems;
use NetSuite\Classes\InboundShipmentItemsList;
use NetSuite\Classes\InventoryAdjustment;
use NetSuite\Classes\InventoryAdjustmentInventory;
use NetSuite\Classes\InventoryAdjustmentInventoryList;
use NetSuite\Classes\ItemReceipt;
use NetSuite\Classes\ItemReceiptItem;
use NetSuite\Classes\ItemReceiptItemList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesTaxItemSearch;
use NetSuite\Classes\SalesTaxItemSearchBasic;
use NetSuite\Classes\SearchBooleanField;
use NetSuite\Classes\SearchColumnLongField;
use NetSuite\Classes\SearchColumnSelectField;
use NetSuite\Classes\SearchMultiSelectField;
use NetSuite\Classes\SearchMultiSelectFieldOperator;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\SearchStringFieldOperator;
use NetSuite\Classes\TransactionSearch;
use NetSuite\Classes\TransactionSearchAdvanced;
use NetSuite\Classes\TransactionSearchBasic;
use NetSuite\Classes\TransactionSearchRow;
use NetSuite\Classes\TransactionSearchRowBasic;
use NetSuite\Classes\UpdateRequest;
use NetSuite\Classes\UpsertRequest;
use NetSuite\NetSuiteService;
use App\Helper\Logger;
use App\Helper\FieldMappingHelper;
use App\Models\Enum\PlatformCountries;
use App\Models\PlatformCustomerAdditionalInformation;
use App\Models\PlatformCustomFieldValue;
use App\Models\PlatformField;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformProductBundle;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformUrl;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;

class NetsuiteServices
{
    public static $myPlatform = 'netsuite';
    public $helper, $mobj, $netsuiteApi, $CountryCodes, $platformId, $mapping;
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper;
        $this->netsuiteApi = new NetsuiteApi();
        $this->mapping = new FieldMappingHelper();
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    /* Get Search Location wise Onhand Quantity */
    public function GetInventoryQuantityByLocations($service, $search, $field)
    {
        if ($search && $service) {
            $locations = $this->netsuiteApi->GetInventoryByItemExternalID($service, $search, $field);

            if ($locations && isset($locations->locationsList->locations) && is_array($locations->locationsList->locations) && count($locations->locationsList->locations) > 0) {
                $location_array = [];
                foreach ($locations->locationsList->locations as $key => $location) {
                    $location_array[$location->locationId->internalId] = $location->quantityOnHand;
                }
                return $location_array;
            }
        }
        return  is_string($locations) ? $locations : "Netsuite API Internal Error";
    }

    /* Get Search Custom Field */
    public function GetSearchOrderCustomField($order)
    {
        $OrderCustomFields = [];
        if (isset($order->customFieldList->customField) && is_array($order->customFieldList->customField)) {
            foreach ($order->customFieldList->customField as $key => $field) {
                $value = null;
                if (isset($field->value) && is_object($field->value)) {
                    $value = isset($field->value->name) ? $field->value->name : null;
                } else if (isset($field->value) && is_string($field->value)) {
                    $value = $field->value;
                }
                $OrderCustomFields[$field->internalId] = $value;
            }
        }
        return  $OrderCustomFields;
    }

    /* Get Search Custom Field */
    public function GetSearchItemCustomField($item)
    {
        $OrderCustomFields = [];
        if (isset($item->customFieldList->customField) && is_array($item->customFieldList->customField)) {
            foreach ($item->customFieldList->customField as $key => $field) {
                $value = null;
                if (isset($field->value) && is_object($field->value)) {
                    $value = isset($field->value->name) ? $field->value->name : null;
                } else if (isset($field->value) && is_string($field->value)) {
                    $value = $field->value;
                }
                $OrderCustomFields[$field->internalId] = $value;
            }
        }
        return  $OrderCustomFields;
    }

    /* Get Order Location and Update */
    public function GetOrderLocation($order, $user_id, $user_integration_id, $location_object_id = null)
    {
        $return = null;
        if (is_null($location_object_id)) {
            $location_object_id = $this->helper->getObjectId('location');
        }
        if (isset($order->location->internalId)) {
            $ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $location_object_id, 'api_id' => $order->location->internalId], ['id']);
            if ($ord_warehouse) {
                $order_warehouse_id = $ord_warehouse->id;
            } else {

                $order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $order->location->internalId,
                    'name' => $order->location->name,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $location_object_id,

                ]);
            }
            $return = $order_warehouse_id;
        }
        return $return;
    }



    /* Check Sync Start Date And Order date */
    public function isValidOrder($order_sync_start_date, $date_created)
    {
        if (isset($order_sync_start_date) && !empty($order_sync_start_date)) {
            $FromDate = date(DATE_ISO8601, strtotime($order_sync_start_date));
            $ToDate = date(DATE_ISO8601, strtotime($date_created));
            if ($FromDate < $ToDate) {
                $byPass = true;
            } else {
                $byPass = false;
            }
        } else {
            $byPass = true;
        }
        return $byPass;
    }

    public function UrlDate($dateTime, $sign = "|")
    {
        $date_slice = explode($sign, $dateTime);
        if (isset($date_slice[1]) && !empty($date_slice[1])) {
            $searchId = $pageIndex = null;
            if (isset($date_slice[2]) && isset($date_slice[3])) {
                $searchId = trim($date_slice[2]);
                $pageIndex = trim($date_slice[3]);
            }
            return [
                trim($date_slice[0]),
                trim($date_slice[1]), $searchId, $pageIndex
            ];
        } else {
            return trim($date_slice[0]);
        }
    }

    public function updateDateTimeISOFormat($dateTime, $sign = "+")
    {
        $date_slice = explode($sign, $dateTime);
        if (isset($date_slice[0])) {
            return trim($date_slice[0]);
        }
        //return (strstr($dateTime, $sign) ? substr($dateTime, 0, strpos($dateTime, $sign)) : $dateTime);
    }

    public function getLastOrderDateTime($userId, $userIntegrationId, $order_sync_start_date)
    {
        /* If Order last time not found  | set 1 hr minus from current time*/

        $get_order_date = PlatformOrder::select('api_updated_at')
            ->where([
                'user_id' => $userId,
                'platform_id' => $this->platformId,
                'user_integration_id' => $userIntegrationId,
                'order_type' => 'SO',
            ])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")
            ->first();

        if (!empty($get_order_date)) {
            $sync_start_date = \Carbon\Carbon::parse($get_order_date->api_updated_at)->subSecond()->format('c');
            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        } else if ($order_sync_start_date) {
            $sync_start_date = \Carbon\Carbon::parse($order_sync_start_date)->subSecond()->format('c');
            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        } else {
            $sync_start_date = \Carbon\Carbon::parse($order_sync_start_date)->subMinutes(30)->format('c');

            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        }
        return $sync_start_date;
    }
    /* Filter Pattern For Products */
    public function getLastProductDateTime($userId, $userIntegrationId, $column = 'api_updated_at')
    {
        /* If Order last time not found  | set 1 hr minus from current time*/

        $get_product_date = PlatformProduct::select($column)
            ->where([
                'user_id' => $userId,
                'platform_id' => $this->platformId,
                'user_integration_id' => $userIntegrationId
            ])->orderByRaw("DATE_FORMAT({$column}, '%Y-%m-%d %H-%i-%s') DESC")
            ->first();

        if (!empty($get_product_date)) {
            $sync_start_date = \Carbon\Carbon::parse($get_product_date->$column)->subSecond()->format('c');
            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        } else {
            $sync_start_date = null; // \Carbon\Carbon::now()->subDay()->format('c');
            //$sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        }
        return $sync_start_date;
    }
    /* Filter Pattern For Products */
    public function getLastVendorDateTime($userId, $userIntegrationId, $column = 'api_updated_at')
    {
        /* If Order last time not found  | set 1 hr minus from current time*/

        $get_customer_date = PlatformCustomer::select($column)
            ->where([
                'user_id' => $userId,
                'platform_id' => $this->platformId,
                'user_integration_id' => $userIntegrationId
            ])->orderByRaw("DATE_FORMAT({$column}, '%Y-%m-%d %H-%i-%s') DESC")
            ->first();

        if (!empty($get_customer_date)) {
            $sync_start_date = \Carbon\Carbon::parse($get_customer_date->$column)->subSecond()->format('c');
            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        } else {
            $sync_start_date = null; // \Carbon\Carbon::now()->subDay()->format('c');
            // $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        }
        return $sync_start_date;
    }/* Filter Pattern For Products Inventory */
    public function getLastInventoryDateTime($userId, $userIntegrationId)
    {
        /* If Order last time not found  | set 1 hr minus from current time*/

        $get_product_date = PlatformProduct::select('api_inventory_lastmodified_time')
            ->where([
                'user_id' => $userId,
                'platform_id' => $this->platformId,
                'user_integration_id' => $userIntegrationId
            ])->orderByRaw("DATE_FORMAT('api_inventory_lastmodified_time', '%Y-%m-%d %H-%i-%s') DESC")
            ->first();

        if (!empty($get_product_date)) {
            $sync_start_date = \Carbon\Carbon::parse($get_product_date->api_inventory_lastmodified_time)->subSecond()->format('c');
            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        } else {
            $sync_start_date = null; // \Carbon\Carbon::now()->subDay()->format('c');
            // $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        }
        return $sync_start_date;
    }
    /* check order available */
    public function checkPlatformOrderExist($userId, $userIntegrationId, $orderId)
    {
        return PlatformOrder::where(['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_order_id' => (string)$orderId])->first();
    }
    /* check product available */
    public function checkPlatformProductExist($userId, $userIntegrationId, $productId)
    {
        return PlatformProduct::where(['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_product_id' => (string)$productId])->first();
    }
    /* check vendor available */
    public function checkPlatformVendorExist($userId, $userIntegrationId, $vendorId)
    {
        return PlatformCustomer::where(['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_customer_id' => (string)$vendorId, 'type' => "Vendor"])->first();
    }

    public function checkPlatformURLViaURlName($userId, $userIntegrationId, $urlName)
    {
        return PlatformUrl::where(['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'url_name' => $urlName])
            ->select('url', 'id')->first();
    }

    /* Search customer id in platform_customer table */
    public function SearchCustomerByID($CustomerID = null, $userId = null, $userIntegrationId = null, $PlatformId = null, $service)
    {
        $return_response = false;
        $find = PlatformCustomer::select('id', 'email')->where([
            'user_id' => $userId,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $PlatformId,
            'api_customer_id' => (string)$CustomerID,
            'is_deleted' => 0
        ])->first();

        if ($find) {
            $return_response = ['customerId' => $find->id, 'email' => $find->email];
        } else {
            $return_response = $this->GetCustomerById($CustomerID, $userId, $userIntegrationId, $service);
        }
        return $return_response;
    }

    /* Get Customer By ID */
    public function GetCustomerById($CustomerID = null, $userId = null, $userIntegrationId = null, $service)
    {
        $return_response = false;
        try {
            $response = $this->netsuiteApi->SearchNetsuiteCustomerByID($service, $CustomerID);

            if (isset($response->internalId)) {
                $customersList = array(
                    'user_id' => $userId,
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId,
                    'api_customer_id' => $response->internalId,
                    'first_name' => $response->firstName,
                    'last_name' => $response->lastName,
                    'email' => $response->email,
                    'customer_name' => $response->firstName . " " . $response->middleName . " " . $response->lastName,
                );
                $find = $this->mobj->getFirstResultByConditions('platform_customer', [
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId,
                    'api_customer_id' => $response->internalId,
                ], ['id']);
                if ($find) {

                    $this->mobj->makeUpdate(
                        'platform_customer',
                        $customersList,
                        ['id' => $find->id]
                    );
                    $return_response = ['customerId' => $find->id, 'email' => $response->email];
                } else {
                    $lastId = $this->mobj->makeInsertGetId('platform_customer', $customersList);
                    $return_response = ['customerId' => $lastId, 'email' => $response->email];
                }
            } else {
                $return_response = $response;
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function CreateCustomerDeposit($service, $transaction, $orderInternalId, $accountId, $type = 'purchase_orders'): int
    {
        try {
            $deposit = new CustomerDeposit();
            $deposit->salesOrder = new RecordRef();
            $deposit->salesOrder->type = ($type == 'purchase_orders') ? RecordType::purchaseOrder : RecordType::salesOrder;
            $deposit->salesOrder->internalId  = $orderInternalId;
            $deposit->payment = $transaction->transaction_amount;
            $deposit->memo = $transaction->transaction_id;
            $deposit->undepFunds = false;

            $deposit->account = new RecordRef();
            $deposit->account->type = RecordType::account;
            $deposit->account->internalId = $accountId;

            $addRequest = new AddRequest();
            $addRequest->record = $deposit;
            $res = $service->add($addRequest);

            if ($res->writeResponse->status->isSuccess && isset($res->writeResponse->baseRef)) {
                return $res->writeResponse->baseRef->internalId;
            }
        } catch (\Exception $ex) {
            print_r("\n Error exception: " . $ex->getMessage() . " at : " . $ex->getLine());
        }
        return 0;
    }

    /* Store Order */
    public function StoreOrderDetails($order, $user_id, $user_integration_id, $service)
    {
        $order = [
            'user_id' => $user_id,
            'platform_id' => $this->platformId,
            'user_integration_id' => $user_integration_id,
            'platform_customer_id' => $order->platform_customer_id,
            'order_type' => "SO",
            'customer_email' => $order->email,
            'api_order_id' => $order->internalId,
            'api_order_reference' => $order->internalId,
            'order_number' => $order->tranId,
            'order_date' => isset($order->createdDate) ? $order->createdDate : null,
            'total_discount' => isset($order->discountTotal) ? $order->discountTotal : 0,
            'discount_tax' => 0, //discount_tax
            'shipping_total' => isset($order->shippingCost) ? $order->shippingCost : 0, //shipping_total
            'shipping_tax' => isset($order->shippingTax1Rate) ? $order->shippingTax1Rate : 0, //shipping_tax
            'total_tax' => isset($order->taxTotal) ? $order->taxTotal : 0,
            'total_amount' => isset($order->subTotal) ? $order->subTotal : 0,
            'file_name' => $order->status,
            'sync_status' => "Ready",
            'warehouse_id' => @$order->warehouse_id,
            'carrier_code' => isset($order->paymentMethod->name) ? $order->paymentMethod->name : null,
            'payment_date' => isset($order->paymentEventDate) ? $order->paymentEventDate : null,
            'currency' =>  $this->getCurrencyDetailByID($service, $user_id, $user_integration_id, $order->currency->internalId),
            'shipping_method' => isset($order->shipMethod->internalId) ? $order->shipMethod->internalId : null,
            'delivery_date' => isset($order->shipDate) ? $order->shipDate : null,
            'order_updated_at' => date('Y-m-d H:i:s'),
            'api_updated_at' => $order->lastModifiedDate,
        ];

        return $this->mobj->makeInsertGetId('platform_order', $order);
    }

    /* Store Address */
    public function StoreAddress($order, $platform_order_id, $email = null, $type = "insert")
    {
        $returnId = true;
        /* If country and address1 not found ,Copy billing address as shipping address */
        if (isset($order->billingAddress) && !is_null($order->billingAddress)) {
            $billingAddress = [
                'platform_order_id' => $platform_order_id,
                'address_type' => 'billing',
                'firstname' => @$order->billingAddress->attention,
                'lastname' => @$order->billingAddress->addressee,
                'address_name' => @$order->billingAddress->attention . " " . @$order->billingAddress->addressee,
                'address1' => @$order->billingAddress->addr1,
                'address2' => @$order->billingAddress->addr2,
                'city' => @$order->billingAddress->city,
                'state' => @$order->billingAddress->state,
                'postal_code' => @$order->billingAddress->zip,
                'country' =>  PlatformCountries::FindCountryName(@$order->billingAddress->country),
                'phone_number' => @$order->billingAddress->addrPhone,
                'email' => @$email,

            ];
        } else {
            if (isset($order->shippingAddress) && !is_null($order->shippingAddress)) {
                if (!empty($order->shippingAddress->addr1) || !empty($order->shippingAddress->addr1) || !empty($order->shippingAddress->country) || !isset($order->shippingAddress->country)) {
                    $billingAddress = [
                        'platform_order_id' => $platform_order_id,
                        'address_type' => 'shipping',
                        'firstname' => @$order->shippingAddress->attention,
                        'lastname' => @$order->shippingAddress->addressee,
                        'address_name' => @$order->shippingAddress->attention . " " . @$order->shippingAddress->addressee,
                        'address1' => @$order->shippingAddress->addr1,
                        'address2' => @$order->shippingAddress->addr2,
                        'city' => @$order->shippingAddress->city,
                        'state' => @$order->shippingAddress->state,
                        'postal_code' => @$order->shippingAddress->zip,
                        'country' =>  PlatformCountries::FindCountryName($order->shippingAddress->country),
                        'phone_number' => @$order->shippingAddress->addrPhone,
                        'email' => @$email,
                    ];
                }
            }
        }

        if (isset($order->shippingAddress) && !is_null($order->shippingAddress)) {
            if (empty($order->shippingAddress->addr1) || empty($order->shippingAddress->addr1) || !isset($order->shippingAddress->country) || !isset($order->shippingAddress->country)) {
                $shippingAddress = [
                    'platform_order_id' => $platform_order_id,
                    'address_type' => 'shipping',
                    'firstname' => @$order->billingAddress->attention,
                    'lastname' => @$order->billingAddress->addressee,
                    'address_name' => @$order->billingAddress->attention . " " . @$order->billingAddress->addressee,
                    'address1' => @$order->billingAddress->addr1,
                    'address2' => @$order->billingAddress->addr2,
                    'city' => @$order->billingAddress->city,
                    'state' => @$order->billingAddress->state,
                    'postal_code' => @$order->billingAddress->zip,
                    'country' => PlatformCountries::FindCountryName($order->billingAddress->country),
                    'phone_number' => @$order->billingAddress->addrPhone,
                    'email' => @$email,
                ];
            } else {
                $shippingAddress = [
                    'platform_order_id' => $platform_order_id,
                    'address_type' => 'shipping',
                    'firstname' => @$order->shippingAddress->attention,
                    'lastname' => @$order->shippingAddress->addressee,
                    'address_name' => @$order->shippingAddress->attention . " " . @$order->shippingAddress->addressee,
                    'address1' => @$order->shippingAddress->addr1,
                    'address2' => @$order->shippingAddress->addr2,
                    'city' => @$order->shippingAddress->city,
                    'state' => @$order->shippingAddress->state,
                    'postal_code' => @$order->shippingAddress->zip,
                    'country' =>  PlatformCountries::FindCountryName(@$order->shippingAddress->country),
                    'phone_number' => @$order->shippingAddress->addrPhone,
                    'email' => @$email,
                ];
            }
        }

        if ($type == "insert") {
            $save = true;
            $addresses = [];
            if (!empty($billingAddress) && !empty($shippingAddress)) {
                $addresses = [
                    $billingAddress,
                    $shippingAddress,
                ];
            } else if (!empty($billingAddress) && empty($shippingAddress)) {
                $addresses = [
                    $billingAddress
                ];
            } else if (empty($billingAddress) && !empty($shippingAddress)) {
                $addresses = [
                    $shippingAddress,
                ];
            } else if (empty($billingAddress) && empty($shippingAddress)) {
                $save = false;
            }

            if ($save) {
                $update = $this->mobj->makeInsert('platform_order_address', $addresses);
                if (!isset($update)) {
                    $returnId = false;
                }
            }
        } else if ($type == "update") {
            $saveBill = $saveShip = true;
            if (!empty($billingAddress) && empty($shippingAddress)) {
                $saveShip = false;
            } else if (empty($billingAddress) && !empty($shippingAddress)) {
                $saveBill = false;
            } else if (empty($billingAddress) && empty($shippingAddress)) {
                $saveBill = $saveShip = false;
            }
            /* Update Billing Address */
            if ($saveBill) {
                $whereBilling = [
                    'platform_order_id' => $platform_order_id,
                    'address_type' => 'billing',
                ];
                $update = PlatformOrderAddress::updateOrCreate($whereBilling, $billingAddress);
                if (!isset($update->id)) {
                    $returnId = false;
                }
            }

            /* Update Shipping Address */
            if ($saveShip) {
                $whereShipping = [
                    'platform_order_id' => $platform_order_id,
                    'address_type' => 'shipping',
                ];

                $update = PlatformOrderAddress::updateOrCreate($whereShipping, $shippingAddress);
                if (!isset($update->id)) {
                    $returnId = false;
                }
            }
        }
        return $returnId;
    }
    /* Search Custom Field */

    /* Search & Store Product */
    public function SearchAndStoreProduct($service, $user_id, $user_integration_id, $search, $field, $customFieldProductName = null)
    {

        $return = false;
        $findProduct = PlatformProduct::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $search, 'is_deleted' => 0])->first();

        if (!$findProduct) {
            $product = $this->netsuiteApi->GetInventoryByItemExternalID($service, $search, $field);
            if (!is_bool($product) && !is_string($product)) {
                $product_name = null;
                if ($customFieldProductName) {
                    $customFields = $this->GetSearchItemCustomField($product);
                    if (isset($customFields[$customFieldProductName])) {
                        if (!empty($customFields[$customFieldProductName])) {
                            $product_name = $customFields[$customFieldProductName];
                        } else {
                            $product_name = $product->itemId;
                        }
                    } else {
                        $product_name = isset($product->displayName) ? $product->displayName : $product->itemId;
                    }
                } else {
                    $product_name = isset($product->displayName) ? $product->displayName : $product->itemId;
                }
                $product->displayName = $product_name;
                $productResponseId = $this->prepareProductData($product, $user_id, $user_integration_id, $service);
                // $fields = array(
                //     'user_id' => $user_id,
                //     'user_integration_id' => $user_integration_id,
                //     'platform_id' => $this->platformId,
                //     'api_product_id' => $product->internalId,
                //     'product_name' =>  $product_name,
                //     'api_product_code' => isset($product->incomeAccount->internalId) ? $product->incomeAccount->internalId : null,
                //     'upc' => $product->upcCode,
                //     'sku' => $product->itemId,
                //     'sync_status' => "Ready"
                // );

                // $productResponse = PlatformProduct::create($fields);
                if ($productResponseId) {
                    $fields = array(
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_product_id' => $product->internalId,
                        'product_name' =>  $product_name,
                        'api_product_code' => isset($product->incomeAccount->internalId) ? $product->incomeAccount->internalId : null,
                        'upc' => $product->upcCode,
                        'sku' => $product->itemId,
                        'id' => $productResponseId
                    );
                    $return = $fields;
                }
            }
        } else {
            $fields = array(
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_product_id' => $findProduct->api_product_id,
                'product_name' => isset($findProduct->product_name) ? $findProduct->product_name : $findProduct->sku,
                'api_product_code' => $findProduct->api_product_code,
                'upc' => $findProduct->upc,
                'sku' => $findProduct->sku,
                'id' => $findProduct->id,
            );

            $return = $fields;
        }
        return  $return;
    }

    /* Store Line Items */
    public function StoreLineItems($order, $platform_order_id, $type = "insert", $service, $user_id, $user_integration_id, $customFieldProductName)
    {
        $return = null;
        $itemInserted = true;
        if ($type == "insert") {
            /* Main Line Items */
            if (isset($order->itemList->item)) {
                $lineItems = [];
                foreach ($order->itemList->item as $key => $value) {
                    $product = $this->SearchAndStoreProduct($service, $user_id, $user_integration_id, $value->item->internalId, 'internalId', $customFieldProductName);

                    if (is_array($product)) {
                        $lineItems[] = [
                            'platform_order_id' => $platform_order_id,
                            'api_order_line_id' => isset($value->lineUniqueKey) ? $value->lineUniqueKey : 0,
                            'api_product_id' => isset($value->item->internalId) ? $value->item->internalId : null,
                            'product_name' => isset($product['product_name']) ? $product['product_name'] : $product['sku'],
                            'sku' => isset($product['sku']) ? substr($product['sku'], 0, 100) : null,
                            'qty' => isset($value->quantity) ? $value->quantity : 0,
                            'taxes' => isset($value->taxCode->name) ? $value->taxCode->name : null,
                            'total_tax' => 0, //total tax
                            'subtotal' => isset($value->amount) ? $value->amount : 0, //sub total
                            //'subtotal_tax' => isset($value->taxAmount) ? $value->taxAmount : 0, //this field is not in use due to in two NS account we are getting null value
                            'subtotal_tax' => isset($value->tax1Amt) ? $value->tax1Amt : 0, //sub total tax
                            'total' => isset($value->amount) ? $value->amount : 0,
                            'unit_price' =>  isset($value->rate) ? $value->rate : 0,
                            'row_type' => "ITEM",
                            'item_row_sequence' => 1,
                        ];
                    }
                }

                if ($lineItems) {
                    $newlineItems = array_merge($lineItems, [[
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => 0,
                        'api_product_id' => null,
                        'product_name' => "Shipping",
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => 0,
                        'total_tax' => 0,
                        'subtotal_tax' => $order->shippingTax1Rate, //sub total tax
                        'subtotal' => $order->shippingCost, //sub total
                        'total' => $order->shippingCost,
                        'unit_price' => 0,
                        'row_type' => "SHIPPING",
                        'item_row_sequence' => 2,

                    ]]);

                    if (!empty($newlineItems)) {
                        $productId = $this->mobj->makeInsert('platform_order_line', $newlineItems);
                        if (!isset($productId)) {
                            $itemInserted = false;
                        }
                        $newlineItems = null;
                    }
                }
            }
        } else if ($type == "update") {
            /* ----------------Insert Line Items----------- */
            $final_lines = [];
            /* Main Line Items */
            if (isset($order->itemList->item)) {
                foreach ($order->itemList->item as $key => $value) {

                    $line_items[] = isset($value->lineUniqueKey) ? $value->lineUniqueKey : 0;
                    $lineItems = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value->lineUniqueKey) ? $value->lineUniqueKey : 0,
                        'api_product_id' => $value->item->internalId,
                        'product_name' => $value->description,
                        'sku' => $value->item->name,
                        'qty' => $value->quantity,
                        'taxes' => $value->taxCode->name,
                        'total_tax' => 0, //total tax
                        'subtotal' => $value->amount, //sub total
                        'subtotal_tax' => isset($value->tax1Amt) ? $value->tax1Amt : 0, //sub total tax
                        'total' => $value->amount,
                        'unit_price' => $value->rate,
                        'row_type' => "ITEM",
                        'item_row_sequence' => 1,
                    ];
                    $whereItem = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value->lineUniqueKey) ? $value->lineUniqueKey : 0,
                    ];

                    $product = PlatformOrderLine::updateOrCreate($whereItem, $lineItems);
                    if (!isset($product->id)) {
                        $itemInserted = false;
                    }
                }
            }



            /* Set is_deleted=1 if line items not found */
            PlatformOrderLine::where('platform_order_id', $platform_order_id)->whereNotIn('api_order_line_id', $final_lines)->update(['is_deleted' => 1]);
        }
        return ['return' => $return, 'items' => $itemInserted];
    }

    /* Store Payment Details */
    public function StorePaymentDetails($order, $platform_order_id)
    {
        /* -------------Insert Transaction/Payments------------------ */
        $paymentDetails =
            [
                'platform_order_id' => $platform_order_id,
                'transaction_id' => $order->tranId,
                'transaction_datetime' => $order->paymentEventDate,
                // 'transaction_type' => $order->created_via,
                'transaction_method' => $order->paymentMethod,
                'transaction_amount' => $order->total,
                'transaction_reference' => $order->tranId,
                'sync_status' => "Ready",
            ];
        $this->CheckAndSaveTransaction($paymentDetails);
    }

    /* Check if already have payment done for a work | insert and update*/
    public function CheckAndSaveTransaction($post)
    {
        if ($post) {
            $find = PlatformOrderTransaction::select('sync_status', 'transaction_id', 'transaction_datetime', 'transaction_type', 'transaction_method', 'transaction_amount', 'transaction_reference')->where('platform_order_id', $post['platform_order_id'])->first();
            if ($find) {
                if ($find->sync_status != "Synced") {
                    $find->transaction_id = isset($post['transaction_id']) ? $post['transaction_id'] : null;
                    $find->transaction_datetime = isset($post['transaction_datetime']) ? $post['transaction_datetime'] : null;
                    $find->transaction_type = isset($post['transaction_type']) ? $post['transaction_type'] : null;
                    $find->transaction_method = isset($post['transaction_method']) ? $post['transaction_method'] : null;
                    $find->transaction_amount = isset($post['transaction_amount']) ? $post['transaction_amount'] : null;
                    $find->transaction_reference = isset($post['transaction_reference']) ? $post['transaction_reference'] : null;
                    $find->save();
                    return true;
                } else {
                    return false;
                }
            } else {
                PlatformOrderTransaction::insert($post);
                return true;
            }
        }
    }

    //    public function checkAndCreateInboundShipment(PlatformOrder $nsOrder, NetSuiteService $service) {
    //        $existingShipment = PlatformOrderShipment::where('tracking_info',$nsOrder->api_order_reference)
    //            ->where('user_id',$nsOrder->user_id)->where('platform_id',$nsOrder->platform_id)->where('user_integration_id',$nsOrder->user_integration_id)->first();
    //        if($existingShipment) {
    //            $res = $this->updateInboundShipment($nsOrder, $service, $existingShipment);
    //            if($res) {
    //                foreach($nsOrder->platformOrderLine as $lineItem) {
    //                    $existingLine = PlatformOrderShipmentLine::where('platform_order_shipment_id',$existingShipment->id)->where('sku',strval($lineItem->sku))->first();
    //                    if(!$existingLine) {
    //                        $shipLine = new PlatformOrderShipmentLine();
    //                        $shipLine->platform_order_shipment_id = $existingLine->id;
    //                        $shipLine->product_id = $lineItem->api_product_id;
    //                        $shipLine->sku = $lineItem->sku;
    //                        $shipLine->price = $lineItem->total;
    //                        $shipLine->quantity = $lineItem->qty;
    //                        $shipLine->save();
    //                    }
    //                }
    //                return true;
    //            }
    //
    //        } else {
    //            $res = $this->createInboundShipment($nsOrder, $service);
    //            if($res) {
    //                try {
    //                    $shipment = new PlatformOrderShipment();
    //                    $shipment->user_id = $nsOrder->user_id;
    //                    $shipment->user_integration_id = $nsOrder->user_integration_id;
    //                    $shipment->platform_id = $nsOrder->platform_id;
    //                    $shipment->shipment_id = $res->internalId;
    //                    $shipment->sync_status = PlatformStatus::SYNCED;
    //                    $shipment->platform_order_id = $nsOrder->id;
    //                    $shipment->order_id = $nsOrder->api_order_id;
    //                    $shipment->shipment_status = InboundShipmentShipmentStatus::_toBeShipped;
    //                    $shipment->tracking_info = $nsOrder->api_order_reference;
    //                    $shipment->save();
    //                    foreach($nsOrder->platformOrderLine as $lineItem) {
    //                        $shipLine = new PlatformOrderShipmentLine();
    //                        $shipLine->platform_order_shipment_id = $shipment->id;
    //                        $shipLine->product_id = $lineItem->api_product_id;
    //                        $shipLine->sku = $lineItem->sku;
    //                        $shipLine->price = $lineItem->total;
    //                        $shipLine->quantity = $lineItem->qty;
    //                        $shipLine->save();
    //                    }
    //                    return true;
    //                } catch (\Exception $ex) {
    //                    Log::error("Error saving shipment ".$ex->getMessage().' at '.$ex->getLine());
    //                }
    //            }
    //        }
    //        return false;
    //    }

    private function createInboundShipment(PlatformOrder $nsOrder, NetSuiteService $service)
    {

        $inboundShipment = new InboundShipment();

        $inboundShipment->itemsList = new InboundShipmentItemsList();
        //        $inboundShipment->
        $lineUniqueKeys = $this->GetLineUniqueKeyForOrder($service, $nsOrder->api_order_id);
        if ($lineUniqueKeys) {
            $inboundShipmentItems = [];

            foreach ($nsOrder->platformOrderLine as $lineItem) {
                $inboundShipmentItem = new InboundShipmentItems();
                $inboundShipmentItem->purchaseOrder = new RecordRef();
                $inboundShipmentItem->purchaseOrder->internalId = $nsOrder->api_order_id;

                $inboundShipmentItem->shipmentItem = new RecordRef();
                $inboundShipmentItem->shipmentItem->internalId = $lineUniqueKeys[$lineItem->api_product_id];

                $inboundShipmentItem->quantityExpected = $lineItem->quantity;
                $inboundShipmentItem->expectedRate = $lineItem->price;
                array_push($inboundShipmentItems, $inboundShipmentItem);
            }
            $inboundShipment->itemsList->inboundShipmentItems = $inboundShipmentItems;
            $addRequest = new AddRequest();
            $addRequest->record = $inboundShipment;
            $res = $service->add($addRequest);
            if ($res->writeResponse->status->isSuccess) {
                return $res->writeResponse->baseRef;
            }
            return isset($res->writeResponse->status->statusDetail[0]) ? $res->writeResponse->status->statusDetail[0]->message : 'Unexpected error';
        }
        return "Line unique key not found";
    }

    private function updateInboundShipment(PlatformOrder $nsOrder, NetSuiteService $service, PlatformOrderShipment $existingShipment)
    {
        $inboundShipment = $this->getInboundShipmentById($existingShipment->shipment_id, $service);
        if ($inboundShipment) {
            $lineUniqueKeys = $this->GetLineUniqueKeyForOrder($service, $nsOrder->api_order_id);
            $inboundShipment->itemsList->replaceAll = true;
            foreach ($nsOrder->platformOrderLine as $lineItem) {
                $inboundShipmentItem = new InboundShipmentItems();
                $inboundShipmentItem->purchaseOrder = new RecordRef();
                $inboundShipmentItem->purchaseOrder->internalId = $nsOrder->api_order_id;

                $inboundShipmentItem->shipmentItem = new RecordRef();
                $inboundShipmentItem->shipmentItem->internalId = $lineUniqueKeys[$lineItem->api_product_id];

                $inboundShipmentItem->quantityExpected = $lineItem->quantity;
                $inboundShipmentItem->expectedRate = $lineItem->price;

                array_push($inboundShipment->itemsList->inboundShipmentItems, $inboundShipmentItem);
            }
            $arr = [];
            foreach ($inboundShipment->itemsList->inboundShipmentItems as $lineItem) {
                $lineItem->poRate = null;
                $lineItem->unitLandedCost = null;
                $lineItem->totalUnitCost = null;
                array_push($arr, $lineItem);
            }

            $inboundShipment->itemsList->inboundShipmentItems = $arr;
            $updateRequest = new UpdateRequest();

            $updateRequest->record = $inboundShipment;

            return $service->update($updateRequest);
        }
        return "Update Failed!. Inbound shipment not found in Netsuite.";
    }

    public function receiveInboundShipment()
    {

        //        $nsOrder = $shipment->platformOrder->linkedOrder;
        if (1) {
            //            $nsShipments = $nsOrder->shipments;
            $counts = [1];
            $items = [];
            foreach ($counts as $c) {
                //                $item = json_decode('{}');
                //                $item->amount = 10;
                //                $item->id = 9534;
                //                $item->quantityReceived = 1;
                //                array_push($items, $item);
            }
            $receiveItems = json_decode("{}");
            $receiveItems->items = $items;
            $receiveInboundShipment = json_decode("{}");
            $receiveInboundShipment->id = 31;
            $receiveInboundShipment->receiveItems = $receiveItems;
            $data = json_decode('{}');
            $data->receiveInboundShipment = $receiveInboundShipment;

            $nsRest = new NetsuiteRestServices(293);
            $nsRest->receiveInboundShipmentTemp();
        }
    }

    public function createInventoryAdjustmentForProduct($service, $userIntegrationId, PlatformProduct $product, $userWorkflowId, $isRetry = false)
    {
        try {
            $trailLimit = 50;
            $errorFlagFinal = 0;
            $errorMsg = '';
            $goodsMovementIds = [];

            $logger = new Logger();
            $connectionHelper = new ConnectionHelper();
            $mappingHelper = new FieldMappingHelper();
            $subsidiary = $this->getNetsuiteRecordViaMapping(null, $userIntegrationId, null, 'subsidiary');
            $allTrails = $isRetry ? $product->inventoryTrails->whereIn('sync_status', [PlatformStatus::FAILED, PlatformStatus::READY])->count() : $product->inventoryTrails->whereIn('sync_status', [PlatformStatus::FAILED, PlatformStatus::READY])->count();
            foreach (($isRetry ? $product->inventoryTrails->whereIn('sync_status', [PlatformStatus::FAILED, PlatformStatus::READY]) : $product->inventoryTrails->whereIn('sync_status', [PlatformStatus::FAILED, PlatformStatus::READY])->take($trailLimit)) as $trail) {
                $errorFlag = 0;
                if ($trail->platformProduct) {
                    $productIdentityObjId = $connectionHelper->getObjectId('product_identity');
                    $mapData = $mappingHelper->getMappedField($userIntegrationId, null, $productIdentityObjId);

                    if (!empty($mapData)) {
                        $sProdFieldMatchBy =  $mapData['source_row_data'];
                        $dProdFieldMatchBy = $mapData['destination_row_data'];
                    }

                    $nsProduct = app('App\Http\Controllers\Netsuite\NetsuiteApiController')->getProductMapping($service, $trail->platformProduct, $this->platformId, $sProdFieldMatchBy, $dProdFieldMatchBy);
                    if (!$nsProduct) {
                        $errorFlag = 1;
                        //$errorMsg = 'Netsuite product not found';
                        //$trail->sync_status = PlatformStatus::FAILED;
                        $trail->sync_status = PlatformStatus::IGNORE;
                        $trail->sync_error = 'Netsuite product not found.';
                        //$goodsMovementIds[] = $trail->api_id;
                    }

                    $objId = $connectionHelper->getObjectId('warehouse');
                    $sWarehouseObj = PlatformObjectData::where(['user_integration_id' => $trail->user_integration_id, 'platform_id' => $trail->platform_id, 'api_id' => $trail->api_warehouse_id, 'status' => 1, 'platform_object_id' => $objId])->first();
                    if (!$sWarehouseObj) {
                        $errorFlag = 1;
                        //$errorMsg = 'Location not found';
                        //$trail->sync_status = PlatformStatus::FAILED;
                        $trail->sync_status = PlatformStatus::IGNORE;
                        $trail->sync_error = 'Location not found.';
                        //$goodsMovementIds[] = $trail->api_id;
                    }

                    $location = $this->getNetsuiteRecordViaMapping($sWarehouseObj->id, $trail->user_integration_id, 'order_warehouse', 'porder_location', false);
                    if (!$location) {
                        $errorFlag = 1;
                        //$errorMsg = 'Netsuite location not found';
                        //$trail->sync_status = PlatformStatus::FAILED;
                        $trail->sync_status = PlatformStatus::IGNORE;
                        $trail->sync_error = 'Netsuite location not found.';
                        //$goodsMovementIds[] = $trail->api_id;
                    }

                    if (!$errorFlag) {
                        $invAdjustmentInv = new InventoryAdjustmentInventory();
                        $invAdjustmentInv->location = new RecordRef();
                        $invAdjustmentInv->location->internalId = $location->api_id;
                        $invAdjustmentInv->location->type = RecordType::location;
                        $invAdjustmentInv->adjustQtyBy = $trail->api_quantity;
                        $invAdjustmentInv->item = new RecordRef();
                        $invAdjustmentInv->item->internalId = $nsProduct->api_product_id;
                        //$accountId = $account->api_id;
                        $accountId = 292;
                        $res = $this->createInventoryAdjustmentForInventory($service, [$invAdjustmentInv], $location->api_id, $accountId, $subsidiary->api_id, $trail->api_id);
                        if (is_bool($res) && $res === true) {
                            $trail->sync_status = PlatformStatus::SYNCED;
                            $trail->sync_error = NULL;
                        } else {
                            $errorFlagFinal = 1;
                            $errorMsg = 'Adjustment for item id ' . $nsProduct->api_product_id . ' cannot be created: ' . $res;

                            $trail->sync_status = PlatformStatus::FAILED;
                            $trail->sync_error = $errorMsg;

                            $goodsMovementIds[] = $trail->api_id;
                        }

                        Storage::append('NetsuiteInventory/' . date('Y-m-d') . '_inventory_trail.txt', 'Start Request: ' . json_encode([$invAdjustmentInv]) . ', Response: ' . $res . PHP_EOL);
                    }
                } else {
                    //$trail->sync_status = PlatformStatus::FAILED;
                    $trail->sync_status = PlatformStatus::IGNORE;
                }

                $trail->save();
                Storage::append('NetsuiteInventory/' . date('Y-m-d') . '_inventory_trail.txt', 'Stop Request' . PHP_EOL);
            }

            if ($errorFlagFinal) {
                $product->inventory_sync_status = PlatformStatus::FAILED;

                $errorMsg = $errorMsg . ', Goods Movement Ids : ' . implode(', ', $goodsMovementIds);

                $logger->syncLog($product->user_id, $userIntegrationId, $userWorkflowId, $product->platform_id, $this->platformId, null, 'failed', $product->id, $errorMsg);
            } else if ($allTrails > $trailLimit) {
                $product->inventory_sync_status = PlatformStatus::READY;
            } else {
                $product->inventory_sync_status = PlatformStatus::SYNCED;
                $logger->syncLog($product->user_id, $userIntegrationId, $userWorkflowId, $product->platform_id, $this->platformId, null, 'success', $product->id, 'Inventory adjustment synced successfully');
            }

            $product->save();
            return $errorFlagFinal ? $errorMsg : true;
        } catch (\Exception $e) {
            Storage::append('NetsuiteInventory/' . date('Y-m-d') . '_inventory_trail.txt', 'Exception: ' . $e->getLine() . ' --> ' . $e->getMessage() . PHP_EOL);
            \Log::error($userIntegrationId . ' --> NetsuiteServices --> createInventoryAdjustmentForProduct --> ' . $e->getLine() . ' --> ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    //    public function createInventoryAdjustmentFromTrails($service, $userIntegrationId, Collection $adjustments, $userWorkflowId) {
    //        try {
    //            $logger = new Logger();
    //            $connectionHelper = new ConnectionHelper();
    //            $dPlatformId = $connectionHelper->getPlatformIdByName(PlatformName::NETSUITE);
    //            $locationAdjustmentMap = [];
    //            $subsidiary = $this->getNetsuiteRecordViaMapping(null, $userIntegrationId, null, 'subsidiary');
    //            $account = $this->getNetsuiteRecordViaMapping(null, $userIntegrationId, null, 'sorder_payment');
    //            $sourceNSLocationMap = [];
    //            foreach ($adjustments as $adjustment) {
    //                if (!isset($locationAdjustmentMap[$adjustment->api_warehouse_id])) {
    //                    $locationAdjustmentMap[$adjustment->api_warehouse_id] = [];
    //                }
    //                $processedAdjForLocation = $locationAdjustmentMap[$adjustment->api_warehouse_id];
    //                $objId = $connectionHelper->getObjectId('warehouse');
    //                $sWarehouseObj = PlatformObjectData::where(['user_integration_id' => $adjustment->user_integration_id, 'platform_id' => $adjustment->platform_id,
    //                    'api_id' => $adjustment->api_warehouse_id, 'status' => 1, 'platform_object_id' => $objId])->first();
    //                if($sWarehouseObj) {
    //                    $location = $this->getNetsuiteRecordViaMapping($sWarehouseObj->id, $adjustment->user_integration_id, 'order_warehouse', 'porder_location');
    //                    $sProduct = $adjustment->platformProduct;
    //                    $nsProduct = PlatformProduct::where(['user_id' => $adjustment->user_id, 'user_integration_id' => $adjustment->user_integration_id, 'platform_id' => $dPlatformId, 'product_name' => $sProduct->sku])->first();
    //                    $invAdjustmentInventories = [];
    //                    if($nsProduct && $location && $subsidiary && $account) {
    //                        $sourceNSLocationMap[$adjustment->api_warehouse_id] = $location->api_id;
    //                        $invAdjustmentInv = new InventoryAdjustmentInventory();
    //                        $invAdjustmentInv->location = new RecordRef();
    //                        $invAdjustmentInv->location->internalId = $location->api_id;
    //                        $invAdjustmentInv->location->type = RecordType::location;
    //                        $invAdjustmentInv->adjustQtyBy = $adjustment->api_quantity;
    //                        $invAdjustmentInv->item = new RecordRef();
    //                        $invAdjustmentInv->item->internalId = $nsProduct->api_product_id;
    //                        array_push($processedAdjForLocation, $invAdjustmentInv);
    //                        $locationAdjustmentMap[$adjustment->api_warehouse_id] = $processedAdjForLocation;
    //                    }
    //                }
    //            }
    //
    //            $message = '';
    //            $locMessage = [];
    //            $failedLocation = [];
    //
    //            foreach(array_keys($locationAdjustmentMap) as $key) {
    //                    if(isset($locationAdjustmentMap[$key]) && isset($sourceNSLocationMap[$key])) {
    //                    $res = $this->createInventoryAdjustmentForInventory($service, $locationAdjustmentMap[$key], $sourceNSLocationMap[$key], $account->api_id, $subsidiary->api_id);
    //                    if(is_string($res)) {
    //                        $locMessage[$key] = $res;
    //                        $message .= ' '.$res;
    //                        array_push($failedLocation, $key);
    //                    }
    //                } else {
    //                    $locMessage[$key] = 'Netsuite product not found';
    //                    $message .= ' Netsuite product not found';
    //                    array_push($failedLocation, $key);
    //                }
    //            }
    //
    //            foreach ($adjustments as $adjustment) {
    //                $sProduct = $adjustment->platformProduct;
    //                if($sProduct) {
    //                    if(in_array($adjustment->api_warehouse_id, $failedLocation)) {
    //                        $adjustment->sync_status = PlatformStatus::FAILED;
    //                        $sProduct->inventory_sync_status = PlatformStatus::FAILED;
    //                        $logger->syncLog($adjustment->user_id, $userIntegrationId, $userWorkflowId, $adjustment->platform_id, $dPlatformId, null, 'failed', $sProduct->id, $locMessage[$adjustment->api_warehouse_id]);
    //                    } else {
    //                        $adjustment->sync_status = PlatformStatus::SYNCED;
    //                        $sProduct->inventory_sync_status = PlatformStatus::SYNCED;
    //                    }
    //                    $adjustment->save();
    //                    $sProduct->save();
    //                }
    //            }
    //            if($message == '') {
    //                return true;
    //            }
    //            return $message;
    //        } catch (\Exception $ex) {
    //            return $ex->getMessage();
    //        }
    //    }

    public function createInventoryAdjustmentForInventory(NetSuiteService $service, array $inventoryAdjustmentInventory, $locationId, $accountId, $subsidiaryId, $trailApiId)
    {
        try {
            $inventoryAdjustment = new InventoryAdjustment();
            $inventoryAdjustment->memo = $trailApiId;
            $inventoryAdjustment->inventoryList = new InventoryAdjustmentInventoryList();
            $inventoryAdjustment->inventoryList->inventory = $inventoryAdjustmentInventory;
            $inventoryAdjustment->adjLocation = new RecordRef();
            $inventoryAdjustment->adjLocation->internalId = $locationId;
            $inventoryAdjustment->location = new RecordRef();
            $inventoryAdjustment->location->internalId = $locationId;
            $inventoryAdjustment->location->type = RecordType::location;
            $inventoryAdjustment->account = new RecordRef();
            $inventoryAdjustment->account->internalId = $accountId;
            $inventoryAdjustment->account->type = RecordType::account;
            $inventoryAdjustment->subsidiary = new RecordRef();
            $inventoryAdjustment->subsidiary->internalId = $subsidiaryId;
            $inventoryAdjustment->subsidiary->type = RecordType::subsidiary;
            $addReq = new AddRequest();
            $addReq->record = $inventoryAdjustment;

            $res = $service->add($addReq);
            if ($res->writeResponse->status->isSuccess) {
                return true;
            }
            return $res->writeResponse->status->statusDetail[0]->message;
        } catch (\Exception $ex) {
            return $ex->getMessage();
        }
    }

    public function createItemReceipt(PlatformOrderShipment $shipment, NetSuiteService $service)
    {
        $nsOrder = $shipment->platformOrder->linkedOrder;
        if ($nsOrder) {
            $nsOrderLines = $nsOrder->platformOrderLine;
            if ($nsOrderLines) {
                $productIdLineItemMap = $shipment->platformShippingLines->mapWithKeys(function ($line, $key) {
                    return [$line->row_id => $line];
                });
                $record = new ItemReceipt();
                $record->externalId = "IR-" . $shipment->shipment_id;
                $record->customForm = new RecordRef();
                $record->createdFrom = new RecordRef();
                $record->createdFrom->internalId = $nsOrder->api_order_id;
                $record->createdFrom->type = RecordType::purchaseOrder;
                $itemList = [];
                foreach ($nsOrderLines as $lineItem) {
                    $sLineItem = PlatformOrderLine::find($lineItem->linked_id);
                    $isValid = $this->isItemValid($sLineItem);
                    $lineCorrection = 0;
                    if ($sLineItem && $isValid && !$this->isItemReceived($lineItem->id, $nsOrder)) {
                        $item = new ItemReceiptItem();
                        $item->item = new RecordRef();
                        $item->item->internalId = $lineItem->api_product_id;
                        $item->orderLine = $lineItem->api_order_line_id - $lineCorrection;
                        $item->itemReceive = true;
                        if (isset($productIdLineItemMap[$sLineItem->api_order_line_id])) {
                            //Shipment line
                            $sLine = $productIdLineItemMap[$sLineItem->api_order_line_id];
                            $item->quantity = $sLine->quantity;
                        } else {
                            $item->quantity = 0;
                        }
                        array_push($itemList, $item);
                    } else if (!$isValid) {
                        $lineCorrection += 1;
                    }
                }
                $record->itemList = new ItemReceiptItemList();
                $record->itemList->item = $itemList;
                $record->itemList->replaceAll = true;
                $upsert = new UpsertRequest();
                $upsert->record = $record;
                $res = $service->upsert($upsert);
                if ($res->writeResponse->status->isSuccess) {
                    return $res->writeResponse->baseRef;
                }
            }
            return isset($res->writeResponse->status->statusDetail[0]) ? $res->writeResponse->status->statusDetail[0]->message : "Unexpected error";
        }
        return "Netsuite respective order not found";
    }

    public function isItemReceived($lineItemId, PlatformOrder $purchaseOrder): bool
    {
        $shipments = $purchaseOrder->shipments;
        $recQty = 0;
        foreach ($shipments as $shipment) {
            foreach ($shipment->platformShippingLines as $line) {
                if ($line->row_id == $lineItemId) {
                    $recQty += $line->quantity;
                }
            }
        }

        $orderLines = $purchaseOrder->platformOrderLine;
        $productIdOrderLineMap = $orderLines->mapWithKeys(function ($line, $key) {
            return [$line->id => $line];
        });
        if (isset($productIdOrderLineMap[$lineItemId])) {
            return $recQty == $productIdOrderLineMap[$lineItemId]->qty;
        }
        return false;
    }

    private function isItemValid($lineItem): bool
    {
        $platformOrder = $lineItem->platformOrder;
        if ($platformOrder) {
            $sProduct = PlatformProduct::where(['user_integration_id' => $platformOrder->user_integration_id, 'api_product_id' => $lineItem->api_product_id, 'platform_id' => $platformOrder->platform_id, 'is_deleted' => 0])->select('stock_track')->first();
            if ($sProduct) {
                return $sProduct->stock_track == 1;
            }
        }
        return false;
    }

    //    public function isItemReceived($sProductId, PlatformOrder $sPurchaseOrder, $sShipmentId): bool {
    //        $shipments = $sPurchaseOrder->shipments;
    //        $recQty = 0;
    //        foreach ($shipments as $shipment) {
    //            if($shipment->id != $sShipmentId) {
    //                foreach ($shipment->platformShippingLines as $line) {
    //                    if($line->product_id == $sProductId) {
    //                        $recQty += $line->quantity;
    //                    }
    //                }
    //            }
    //        }
    //
    //        $orderLines = $sPurchaseOrder->platformOrderLine;
    //        $productIdOrderLineMap = $orderLines->mapWithKeys(function ($line, $key) {return [$line->api_product_id => $line];});
    //        if(isset($productIdOrderLineMap[$sProductId])) {
    //            return $recQty == $productIdOrderLineMap[$sProductId]->qty;
    //        }
    //        return false;
    //    }

    private function getNetsuiteRecordViaMapping($objectDataId, $integrationId, $mapObjectName, $defaultMapObjName, $getDefault = true): ?PlatformObjectData
    {
        $connectionHelper = new ConnectionHelper();
        if (!$objectDataId) {
            goto defaultMap;
        }
        $objId = $connectionHelper->getObjectId($mapObjectName);
        $map = PlatformDataMapping::where([
            'source_row_id' => $objectDataId, 'user_integration_id' => $integrationId,
            'platform_object_id' => $objId, 'status' => 1
        ])->first();

        if ($map) {
            return PlatformObjectData::find($map->destination_row_id);
        } else {
            if (!$getDefault) {
                return null;
            }
            defaultMap:
            $objId = $connectionHelper->getObjectId($defaultMapObjName);

            $map = PlatformDataMapping::where([
                'user_integration_id' => $integrationId, 'platform_object_id' => $objId,
                'mapping_type' => 'default'
            ])->first();

            if ($map) {
                return PlatformObjectData::find($map->destination_row_id);
            }
        }
        return null;
    }

    public function getTransactionsForOrder($orderId)
    {
        try {
            $mainModel = new MainModel();
            return $mainModel->getResultByConditions('platform_order_transactions', ['platform_order_id' => $orderId]);
        } catch (\Exception $ex) {
            print_r('Error/Exception while getTransactionForOrder(): ' . $ex->getMessage() . ' at ' . $ex->getLine());
        }
        return [];
    }

    private function getInboundShipmentById($internalId, NetSuiteService $nsServices)
    {

        $getRequest = new GetRequest();
        $getRequest->baseRef = new RecordRef();
        $getRequest->baseRef->internalId = $internalId;
        $getRequest->baseRef->type = RecordType::inboundShipment;
        $resp = $nsServices->get($getRequest);
        if ($resp->readResponse->status->isSuccess) {
            return $resp->readResponse->record;
        }
        return null;
    }

    public function getReceiptById($internalId, NetSuiteService $nsServices)
    {
        $getRequest = new GetRequest();
        $getRequest->baseRef = new RecordRef();
        $getRequest->baseRef->type = RecordType::itemReceipt;
        $getRequest->baseRef->internalId = $internalId;

        $resp = $nsServices->get($getRequest);
        return $resp;
    }

    public function getAllClassifications(NetSuiteService $service)
    {
        $search = new ClassificationSearchBasic();
        $search->name = new SearchStringField();
        $search->name->searchValue = '';
        $search->name->operator = SearchStringFieldOperator::contains;

        $searchReq = new SearchRequest();
        $searchReq->searchRecord = $search;
        $res = $service->search($searchReq);
        if ($res->searchResult->status->isSuccess) {
            return $res->searchResult->recordList->record;
        }
        return false;
    }

    public function getAllAccounts(NetSuiteService $service)
    {
        $search = new AccountSearchBasic();
        $search->displayName = new SearchStringField();
        $search->displayName->operator = SearchStringFieldOperator::startsWith;
        $search->displayName->searchValue = '';

        $request = new SearchRequest();
        $request->searchRecord = $search;

        $res = $service->search($request);
        if ($res->searchResult->status->isSuccess) {
            return $res->searchResult->recordList->record;
        }
        return false;
    }

    public function getAllTaxCodes(NetSuiteService $service)
    {
        $sTaxItemSearch = new SalesTaxItemSearch();
        $sTaxItemSearch->basic = new SalesTaxItemSearchBasic();
        $sTaxItemSearch->basic->name = new SearchStringField();
        $sTaxItemSearch->basic->name->searchValue = '';
        $sTaxItemSearch->basic->name->operator = SearchStringFieldOperator::contains;

        $searchReq = new SearchRequest();
        $searchReq->searchRecord = $sTaxItemSearch;
        $res = $service->search($searchReq);
        if ($res->searchResult->status->isSuccess) {
            return $res->searchResult->recordList->record;
        }
        return false;
    }

    public function getCustomFieldTypeForNetsuite($type): string
    {
        if ($type == CustomizationFieldType::_textArea) {
            return CustomFieldType::TEXT_AREA;
        }
        if ($type == CustomizationFieldType::_longText || $type == CustomizationFieldType::_freeFormText) {
            return CustomFieldType::TEXT;
        }
        if ($type == CustomizationFieldType::_multipleSelect) {
            return  CustomFieldType::MULTI_SELECT;
        }
        if ($type == CustomizationFieldType::_listRecord) {
            return CustomFieldType::SELECT;
        }
        if ($type == CustomizationFieldType::_integerNumber || $type == CustomizationFieldType::_decimalNumber) {
            return CustomFieldType::INTEGER;
        }
        if ($type == CustomizationFieldType::_checkBox) {
            return CustomFieldType::BOOLEAN;
        }
        if ($type == CustomizationFieldType::_currency) {
            return CustomFieldType::CURRENCY;
        }
        if ($type == CustomizationFieldType::_date) {
            return CustomFieldType::DATE;
        }
        if ($type == CustomizationFieldType::_datetime) {
            return CustomFieldType::DATE_TIME;
        }
        if ($type == CustomizationFieldType::_timeOfDay) {
            return CustomFieldType::TIME;
        }

        return CustomFieldType::TEXT;
    }

    public function getSelectCustomFieldValueForCustomFields(NetSuiteService $service, Collection $customFields)
    {
        try {
            if ($customFields->count()) {
                $customizationList = $this->getCustomizationList($service);
                if ($customizationList->count()) {
                    foreach ($customFields as $customField) {
                        if ($customField->custom_field_type == CustomFieldType::SELECT && $this->isIdCustomizationId($customizationList, $customField->custom_field_option_group_id)) {
                            $customFieldOptions = $this->getCustomListCustomValue($service, $customField->custom_field_option_group_id);
                            foreach ($customFieldOptions as $customFieldOption) {
                                $option = PlatformFieldOptionData::where(['platform_field_id' => $customField->id, 'field_value_id' => $customFieldOption->valueId])->first();
                                if (!$option) {
                                    $option = new PlatformFieldOptionData();
                                    $option->platform_field_id  = $customField->id;
                                    $option->field_value_id = $customFieldOption->valueId;
                                    $option->status = 1;
                                }
                                $option->field_value = $customFieldOption->value;
                                $option->save();
                            }
                        }
                    }
                }
            }
            return true;
        } catch (\Exception $ex) {
            print_r("Error: " . $ex->getMessage());
            return $ex->getMessage();
        }
    }

    private function getCustomizationList(NetSuiteService $service): Collection
    {
        $getRequest = new GetCustomizationIdRequest();
        $getRequest->customizationType = new CustomizationType();
        $getRequest->customizationType->getCustomizationType = GetCustomizationType::customList;
        $getRequest->includeInactives = false;

        $res = $service->getCustomizationId($getRequest);
        if ($res->getCustomizationIdResult->status->isSuccess) {
            return collect($res->getCustomizationIdResult->customizationRefList->customizationRef);
        }
        return collect([]);
    }

    private function isIdCustomizationId(Collection $customizationList, $internalId): bool
    {
        return $customizationList->contains(function ($customizationRef) use ($internalId) {
            return $customizationRef->internalId == $internalId;
        });
    }

    private function getCustomListCustomValue(NetSuiteService $service, $internalId): Collection
    {
        $getReq = new GetRequest();
        $getReq->baseRef = new RecordRef();
        $getReq->baseRef->internalId = $internalId;
        $getReq->baseRef->type = RecordType::customList;
        $res = $service->get($getReq);
        if ($res->readResponse->status->isSuccess && isset($res->readResponse->record->customValueList)) {
            return collect($res->readResponse->record->customValueList->customValue);
        }
        return collect([]);
    }

    private function GetLineUniqueKeyForOrder(NetSuiteService $service, $apiOrderId)
    {
        try {
            $transactionSearchAdvanced = new TransactionSearchAdvanced();
            $transactionSearchAdvanced->criteria = new TransactionSearch();
            $transactionSearchAdvanced->criteria->basic = new TransactionSearchBasic();
            $transactionSearchAdvanced->criteria->basic->internalId = new SearchMultiSelectField();
            $transactionSearchAdvanced->criteria->basic->internalId->operator = SearchMultiSelectFieldOperator::anyOf;

            $recordRef = new RecordRef();
            $recordRef->internalId = $apiOrderId;

            $transactionSearchAdvanced->criteria->basic->internalId->searchValue = [$recordRef];
            $transactionSearchAdvanced->criteria->basic->mainLine = new SearchBooleanField();
            $transactionSearchAdvanced->criteria->basic->mainLine->searchValue = false;

            $transactionSearchAdvanced->columns = new TransactionSearchRow();
            $transactionSearchAdvanced->columns->basic = new TransactionSearchRowBasic();

            $searchColumnSelectedFields = new SearchColumnSelectField();
            $searchColumnSelectedFields->searchValue = new RecordRef();
            $searchColumnSelectedFields->searchValue->name = '';

            $transactionSearchAdvanced->columns->basic->item = [$searchColumnSelectedFields];

            $searchColumnLongField = new SearchColumnLongField();
            $searchColumnLongField->searchValue = 1;

            $transactionSearchAdvanced->columns->basic->lineUniqueKey = [$searchColumnLongField];

            $search = new SearchRequest();
            $search->searchRecord = $transactionSearchAdvanced;

            $res = $service->search($search);
            if ($res->searchResult->status->isSuccess) {
                return $this->getLineUniqueKeyArrayMapFromSearchRow($res->searchResult->searchRowList->searchRow);
            }
        } catch (\Exception $ex) {
            print_r("Error: " . $ex->getMessage());
            Log::error('Error occurred trying to fetch line unique key for order ' . $apiOrderId . ' ' . $ex->getMessage() . ' at ' . $ex->getLine());
        }
        return 0;
    }

    public function getDefaultCustomerForChannel($service, $userId, $defaultCustomerEmail, $userIntegrationId)
    {
        $connectionHelper = new ConnectionHelper();
        $nsApi = new NetsuiteApi();
        $platformId = $connectionHelper->getPlatformIdByName(PlatformName::NETSUITE);
        $customer = PlatformCustomer::where([
            'user_integration_id' => $userIntegrationId, 'platform_id' => $platformId,
            'email' => $defaultCustomerEmail, 'type' => PlatformRecordType::CUSTOMER
        ])->first();
        $respArr = ['api_customer_id' => 0, 'platform_customer_id' => 0, 'error_msg' => ''];
        if (!$customer) {
            $nsCustomerSearch = $nsApi->SearchNetsuiteCustomer($service, 'email', $defaultCustomerEmail);
            if ($nsCustomerSearch && isset($nsCustomerSearch->record) && count($nsCustomerSearch->record) > 0) {
                foreach ($nsCustomerSearch->record as $nsCustomer) {
                    try {
                        $customer = new PlatformCustomer();
                        $customer->user_id = $userId;
                        $customer->user_integration_id = $userIntegrationId;
                        $customer->platform_id = $platformId;
                        $customer->api_customer_id = $nsCustomer->internalId;
                        $customer->first_name = $nsCustomer->firstName;
                        $customer->last_name = $nsCustomer->lastName;
                        $customer->company_name = $nsCustomer->companyName;
                        $customer->phone = $nsCustomer->phone;
                        $customer->email = $nsCustomer->email;
                        $customer->type = PlatformRecordType::CUSTOMER;

                        $customer->save();
                        $respArr = ['api_customer_id' => $customer->api_customer_id, 'platform_customer_id' => $customer->id, 'error_msg' => ''];
                        break;
                    } catch (\Exception $ex) {
                        $respArr['error_msg'] = 'Error saving ' . PlatformRecordType::CUSTOMER . ': ' . $ex->getMessage();
                    }
                }
            } else {
                $respArr['error_msg'] = PlatformRecordType::CUSTOMER . ' Not Found.';
            }
        } else {
            $respArr = ['api_customer_id' => $customer->api_customer_id, 'platform_customer_id' => $customer->id, 'error_msg' => ''];
        }
        return $respArr;
    }

    public static function GetNetsuiteObjectByName($name, $objectId, $integrationId): ?PlatformObjectData
    {
        $connectionHelper = new ConnectionHelper();
        $platformId = $connectionHelper->getPlatformIdByName(PlatformName::NETSUITE);
        return PlatformObjectData::where([
            'platform_id' => $platformId, 'user_integration_id' => $integrationId,
            'platform_object_id' => $objectId, 'status' => 1
        ])->whereRaw('name LIKE "%' . strtolower($name) . '%"')->first();
    }

    public static function GetNetsuiteCustomFieldOptionByName($name, $customFiledPlatformId): ?PlatformFieldOptionData
    {
        return PlatformFieldOptionData::where(['platform_field_id' => $customFiledPlatformId])
            ->whereRaw('field_value LIKE "%' . strtolower($name) . '%"')->first();
    }

    // $searchRows will be an array of Object "SearchRow" of netsuite
    // Returns Item id -> line unique key "array - key" map
    private function getLineUniqueKeyArrayMapFromSearchRow($searchRows): array
    {
        $allIds =  [];
        foreach ($searchRows as $searchRow) {
            foreach ($searchRow->basic->lineUniqueKey as $key => $lineUniqueKey) {
                $allIds[$searchRow->basic->item[$key]->searchValue->internalId] = $lineUniqueKey->searchValue;
            }
        }
        return $allIds;
    }

    public function GetTestSalesItemData()
    {
        return '{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipItem"}],"count":4,"hasMore":false,"items":[{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3"}],"id":"3"},{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/4"}],"id":"4"},{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/117"}],"id":"117"},{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/2"}],"id":"2"}],"offset":0,"totalResults":4}';
    }

    public function GetTestSalesItemDataById()
    {
        return '{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipItem/3"}],"accChange":false,"account":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/account/233"}],"id":"233","refName":"13020 Inventory : Inventory Product"},"costBasis":{"id":"fr","refName":"Flat Rate"},"countries":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3/countries"}]},"description":"Fedex Next Day","displayName":"FedEx","doifTotal":false,"doifTotalOperator":{"id":"OVER","refName":"Over"},"doifWeight":false,"doifWeightOperator":{"id":"OVER","refName":"Over"},"doifWeightUnit":{"id":"1","refName":"lb"},"edition":"US","excludeCountries":false,"excludeSites":false,"handlingByWeightPerUnit":{"id":"1","refName":"lb"},"handlingCost":{"id":"no_handling","refName":"Handling--No Handling Charge"},"handlingTable":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3/handlingTable"}]},"hasMaximumShippingCost":false,"hasMinimumShippingCost":false,"id":"3","isFreeIfOrderTotalIsOver":false,"isHandlingByWeightBracketed":false,"isInactive":false,"isOnline":true,"isShippingByWeightBracketed":false,"itemId":"FedEx","items":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3/items"}]},"itemType":"ShipItem","needsAllFreeShippingItems":false,"omitPackaging":false,"shipItemCurrency":"USD","shippingByWeightPerUnit":{"id":"1","refName":"lb"},"shippingFlatRateAmount":12,"shippingPerItemAmount":0,"shippingTable":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3/shippingTable"}]},"site":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3/site"}]},"states":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/shipitem/3/states"}]},"subsidiary":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/subsidiary/2"}],"id":"2","refName":"Lovepop, Inc : Lovepop, US"},"tabText":"Select and add items you offer with free shipping.<br> When you add an item that has free shipping to an order, the entire order is shipped free.<br> Check the box below to require that all items be added to an order for free shipping.","taxSchedule":{"links":[],"id":"2","refName":"Non-Taxable"},"taxScheduleHandling":{"links":[],"id":"2","refName":"Non-Taxable"}}';
    }

    public function GetTestDiscountItemData()
    {
        return '{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/discountItem"}],"count":1,"hasMore":false,"items":[{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/discountitem/14"}],"id":"14"}],"offset":0,"totalResults":1}';
    }

    public function GetTestDiscountItemDataById()
    {
        return '{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/discountItem/14"}],"account":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/account/287"}],"id":"287","refName":"41010 Discounts"},"createdDate":"2021-08-11T19:50:00Z","customForm":{"id":"102","refName":"Lovepop - Discount"},"description":"Free Order Discount","id":"14","includeChildren":false,"isInactive":false,"isPreTax":false,"itemId":"Free Order Discount","itemType":{"id":"Discount","refName":"Discount"},"lastModifiedDate":"2021-08-11T19:50:00Z","nonPosting":{"id":false,"refName":"Account"},"rate":100,"subsidiary":{"links":[{"rel":"self","href":"https://4121004.suitetalk.api.netsuite.com/services/rest/record/v1/discountitem/14/subsidiary"}]}}';
    }
    /* New Codes */
    /* Prepare Product Data */
    public function prepareProductData($product, $user_id, $user_integration_id, $platform_name, $service = null)
    {
        $bundle = 0;
        $reflection = new ReflectionClass($product);
        $className = $reflection->getShortName();
        if ($className == "KitItem") {
            $bundle = 1;
        }
        if (@$product->itemId && !empty($product->itemId)) {
            $product_sync_status = PlatformStatus::READY;
        } else {
            $product_sync_status = PlatformStatus::INACTIVE;
        }
        $product_name = isset($product->displayName) ? $product->displayName : $product->itemId;

        $ProductPrimaryID = NULL;
        $pstatus = @$product->isInactive ? false : true;
        $productCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => @$product->internalId,
            'api_product_code' => isset($product->incomeAccount->internalId) ? $product->incomeAccount->internalId : null,
            'sku' => @$product->itemId,
            'upc' => @$product->upcCode,
            'product_name' =>  $product_name,
            'product_status' =>  $pstatus,
            'bundle' =>  $bundle,
            'api_updated_at' => @$product->lastModifiedDate,
            'api_created_at' => @$product->createdDate,
            'is_deleted' => 0,
            'product_sync_status' => $product_sync_status,

        ];
        if (isset($product->linked_id)) {
            $productCreate['linked_id'] = $product->linked_id;
        }


        $findProduct = PlatformProduct::where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => @$product->internalId,
        ])->first();

        if ($findProduct) {
            $ProductPrimaryID = $findProduct->id;
            if ($findProduct->api_updated_at != @$product->lastModifiedDate) {
                $findProduct->product_sync_status = $product_sync_status;
                $findProduct->sku =  @$product->itemId;
                $findProduct->upc = @$product->upcCode;
                $findProduct->product_name =  $product_name;
                $findProduct->product_status = $pstatus;
                $findProduct->api_updated_at = @$product->lastModifiedDate;
                $findProduct->is_deleted = 0;
                $findProduct->bundle =  0;
                if (isset($product->linked_id)) {
                    $findProduct->linked_id = $product->linked_id;
                }

                $findProduct->save();
                if ($ProductPrimaryID) {
                    /* Store prices of product */
                    if (isset($product->pricingMatrix->pricing) && count($product->pricingMatrix->pricing)) {
                        $this->createPriceList($service, $user_id, $user_integration_id, $ProductPrimaryID, $product);
                    }
                    if (in_array($platform_name, ['skubana']) && $bundle) {
                        if (isset($product->memberList->itemMember) && count($product->memberList->itemMember)) {
                            $product->platform_product_id = $ProductPrimaryID;
                            $this->prepareBundleItems($product, $user_id, $user_integration_id);
                        }
                    }

                    $this->getStoreProductCustomFields($ProductPrimaryID, $user_id, $user_integration_id, $product);
                }
            }
        } else {
            $findProduct = PlatformProduct::create($productCreate);
            $ProductPrimaryID = $findProduct->id;
            if ($ProductPrimaryID) {
                /* Store prices of product */
                if (isset($product->pricingMatrix->pricing) && count($product->pricingMatrix->pricing)) {
                    $this->createPriceList($service, $user_id, $user_integration_id, $ProductPrimaryID, $product);
                }
                if (in_array($platform_name, ['skubana']) && $bundle) {
                    if (isset($product->memberList->itemMember) && count($product->memberList->itemMember)) {
                        $product->platform_product_id = $ProductPrimaryID;
                        $this->prepareBundleItems($product, $user_id, $user_integration_id);
                    }
                }
                $this->getStoreProductCustomFields($ProductPrimaryID, $user_id, $user_integration_id, $product);
            }
        }



        return $ProductPrimaryID;
    }
    /* prepare product bundle -> child items */
    public function prepareBundleItems($product, $user_id, $user_integration_id)
    {
        PlatformProductBundle::where('platform_product_id', $product->platform_product_id)->update(['status' => 0]);
        $updateBundleStatus = [];
        foreach ($product->memberList->itemMember as $bundle) {
            $find = PlatformProduct::select('id', 'sku')->where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => @$bundle->item->internalId])->first();
            if ($find) {
                $findBundle = PlatformProductBundle::where(['platform_product_id' => $product->platform_product_id, 'platform_product_bundle_id' => $find->id])->first();
                if ($findBundle) {

                    if ($findBundle->bundle_qty != $bundle->quantity || $findBundle->sku != $find->sku) {
                        $findBundle->bundle_qty = $bundle->quantity;
                        $findBundle->sku = $find->sku;
                        $findBundle->status = 1;
                        $findBundle->save();
                    } else {
                        $updateBundleStatus[] = $findBundle->id;
                    }
                } else {
                    PlatformProductBundle::create([
                        'platform_product_id' => $product->platform_product_id,
                        'platform_product_bundle_id' => @$find->id,
                        'sku' => @$find->sku,
                        'bundle_qty' => @$bundle->quantity,
                        'status' => 1
                    ]);
                }
            } else {
                $create = PlatformProduct::create([
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'api_product_id' => @$bundle->item->internalId,
                    'product_name' => @$bundle->item->name,
                    'bundle' => 0,
                    'product_sync_status' => PlatformStatus::PENDING,
                ]);
                if ($create) {
                    $primaryID = $create->id; // pass last param as 1 for child product
                    PlatformProductBundle::create([
                        'platform_product_id' => $product->platform_product_id,
                        'platform_product_bundle_id' => $primaryID,
                        'bundle_qty' => @$bundle->quantity,
                        'status' => 1
                    ]);
                }
            }
        }
        if ($updateBundleStatus) {
            PlatformProductBundle::whereIn('id', $updateBundleStatus)->update(['status' => 1]);
        }
    }
    /* Get Store Product Custom Fields & Value */
    public function getStoreProductCustomFields($product_primary_id, $user_id, $user_integration_id, $product, $customObjectId = null)
    {
        $customFields = isset($product->customFieldList->customField) ? $product->customFieldList->customField : [];
        if (is_null($customObjectId)) {
            $customObjectId = $this->helper->getObjectId('product');
        }
        $field_mapping = $this->mapping->GetMappedFieldRecord($customObjectId, $user_integration_id, NULL, "source_row_id", NULL, $product_primary_id);
        if ($field_mapping) {
            foreach ($field_mapping as $mapping) {
                foreach ($customFields as $key => $customField) {
                    if ($mapping['source_custom_field_id'] == $customField->internalId) {
                        $findRecord = PlatformField::select('id')->where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'field_type' => 'custom', 'custom_field_id' => $customField->internalId, 'platform_object_id' => $customObjectId, 'status' => 1])->first();

                        if (isset($findRecord)) {
                            $customFieldValue = '';
                            if (is_object(@$customField->value)) {
                                $customFieldValue = @$customField->value->name;
                            } else {
                                $customFieldValue = @$customField->value;
                            }
                            $findCustomFieldValueRecord = PlatformCustomFieldValue::select('id', 'field_value')->where(['record_id' => $product_primary_id, 'platform_field_id' => $findRecord->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
                            if (isset($findCustomFieldValueRecord)) {
                                $findCustomFieldValueRecord->field_value = $customFieldValue;
                                $findCustomFieldValueRecord->save();
                            } else {
                                PlatformCustomFieldValue::insert(['platform_field_id' => $findRecord->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'field_value' => $customFieldValue, 'record_id' => $product_primary_id, 'status' => 1]);
                            }
                        }
                        break; //break the loop if the mapping found
                    }
                }
            }
        }
        // else{
        //     foreach ($customFields as $key => $customField) {
        //         $findRecord = PlatformField::select('id')->where(['platform_id' => $this->platformId,'user_id'=>$user_id, 'user_integration_id' => $user_integration_id, 'field_type' => 'custom', 'custom_field_id' => $customField->internalId, 'platform_object_id' => $customObjectId, 'status' => 1])->first();

        //         if (isset($findRecord)) {
        //             $customFieldValue = '';
        //             if (is_object(@$customField->value)) {
        //                 $customFieldValue = @$customField->value->name;
        //             } else {
        //                 $customFieldValue = @$customField->value;
        //             }
        //             $findCustomFieldValueRecord = PlatformCustomFieldValue::select('id', 'field_value')->where(['record_id' => $product_primary_id, 'platform_field_id' => $findRecord->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
        //             if (isset($findCustomFieldValueRecord)) {
        //                 $findCustomFieldValueRecord->field_value = $customFieldValue;
        //                 $findCustomFieldValueRecord->save();
        //             } else {
        //                 PlatformCustomFieldValue::insert(['platform_field_id' => $findRecord->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'field_value' => $customFieldValue, 'record_id' => $product_primary_id, 'status' => 1]);
        //             }
        //         }
        //     }
        // }




    }
    /* Insert / Update Product Price */
    private function createPriceList($service, $user_id, $user_integration_id, $platform_product_id, $product)
    {
        $ProductPrimaryID = $platform_product_id;
        if ($ProductPrimaryID) {
            $ObjectId = $this->helper->getObjectId('pricelist');
            if ($ObjectId) {
                $objectData = PlatformObjectData::select('id', 'api_id')->where([
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $ObjectId,
                    'status' => 1,
                ])->pluck('id', 'api_id')->toArray();

                if (count($objectData)) {
                    foreach ($product->pricingMatrix->pricing as $val) {
                        if (isset($objectData[$val->priceLevel->internalId])) {
                            $platform_object_data_id = $objectData[$val->priceLevel->internalId];
                            $price = count($val->priceList->price) ? $val->priceList->price[0]->value : 0;
                            $currencyId = $val->currency->internalId;
                            $currency = $this->getCurrencyDetailByID($service, $user_id, $user_integration_id, $currencyId);
                            PlatformProductPriceList::updateOrCreate(['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $platform_object_data_id, 'api_currency_code' => $currency], ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $platform_object_data_id, 'price' => $price, 'api_currency_code' => $currency]);
                        }
                    }
                }
            }
        }
    }

    /* Prepare Vendor Data */
    public function prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync = 0, $service = null)
    {
        $vendorPrimaryID = NULL;
        if($vendor->isPerson){
            if(!empty($vendor->firstName) && !is_null($vendor->firstName)){
                if(!empty($vendor->middleName) && !is_null($vendor->middleName)){
                    if(!empty($vendor->lastName) && !is_null($vendor->lastName)){
                        $fullname = @$vendor->firstName . " " . @$vendor->middleName . " " . @$vendor->lastName;
                    }else{
                        $fullname = @$vendor->firstName . " " . @$vendor->middleName ;
                    }

                }else{
                    $fullname=@$vendor->firstName;
                }
            }
            if (empty($fullname)) {
                $fullname = @$vendor->legalName;
            }
        }else{
            $fullname = @$vendor->companyName;
        }
        // if(empty($fullname) || $fullname==" "){
        //     $fullname = @$vendor->internalId;
        // }
        $vendorCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_customer_id' => isset($vendor->internalId) ? $vendor->internalId : null,
            'api_customer_code' => isset($vendor->internalId) ? $vendor->internalId : null,
            'first_name' => $fullname,
            'last_name' => @$vendor->lastName,
            'email' =>  isset($vendor->email) ? $vendor->email : null,
            'fax' =>  isset($vendor->fax) ? $vendor->fax : null,
            'customer_name' => $fullname,
            'phone' => isset($vendor->phone) ? $vendor->phone : null,
            'company_name' => isset($vendor->companyName) ? $vendor->companyName : null,
            'is_deleted' => 0,
            'api_updated_at' => isset($vendor->lastModifiedDate) ? $vendor->lastModifiedDate : null,
            'api_created_at' => isset($vendor->dateCreated) ? $vendor->dateCreated : null,
            'address1' =>  isset($vendor->defaultAddress) ? $vendor->defaultAddress : null,
            'type' => "Vendor",
            'sync_status' => "Ready"

        ];

        if (isset($vendor->linked_id)) {
            $vendorCreate['linked_id'] = $vendor->linked_id;
        }

        if ($is_initial_sync) { //When is_initial_sync=1
            $findVendor = PlatformCustomer::create($vendorCreate);
            $vendorPrimaryID = isset($findVendor->id) ? $findVendor->id : null;
            if ($vendorPrimaryID) {
                if (isset($vendor->currency->internalId)) {
                    $currency = $this->getCurrencyDetailByID($service, $user_id, $user_integration_id, $vendor->currency->internalId);
                    if (is_string($currency)) {
                        PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'currency' => $currency]);
                    }
                }
            }
        } else {
            //When is_initial_sync=0
            $findVendor = PlatformCustomer::where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_customer_id' =>  isset($vendor->internalId) ? $vendor->internalId : null,
            ])->first();
            if (!$findVendor) {
                $findVendor = PlatformCustomer::create($vendorCreate);
                $vendorPrimaryID = isset($findVendor->id) ? $findVendor->id : null;
                if ($vendorPrimaryID) {
                    if (isset($vendor->currency->internalId)) {
                        $currency = $this->getCurrencyDetailByID($service, $user_id, $user_integration_id, $vendor->currency->internalId);
                        if (is_string($currency)) {
                            PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'currency' => $currency]);
                        }
                    }
                }
            }
        }


        return  $vendorPrimaryID;
    }
    /* get Currency by ID and store in DB */
    public function getCurrencyDetailByID($service, $user_id, $user_integration_id, $currencyId)
    {
        $return_response = false;
        try {
            $platform_object_id = $this->helper->getObjectId("currency");
            $find = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $platform_object_id, 'api_id' => $currencyId, 'status' => 1])->first();
            if ($find) {
                $return_response = $find->api_code;
            } else {
                $currency = $this->netsuiteApi->getCurrencyById($service, $currencyId);
                if (isset($currency->symbol)) {
                    $return_response = $currency->symbol;
                    PlatformObjectData::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $platform_object_id, 'name' => $currency->name, 'api_id' => $currency->internalId, 'api_code' => $currency->symbol, 'status' => 1]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteService - getCurrencyDetailByID - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    public function ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuelID)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $maping_data =  $this->mapping->getMappedField($userIntegrationId, $PlatformWorkFlowRuelID, $product_identity_obj_id);

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
    public function prepareOrderLine($type = "TO", $orderLines, $user_id, $user_integration_id, $destination_identity)
    {
        $items = [];
        $productNotFound = false;
        if ($type == "SO") {
            if ($orderLines) {

                $qty = 0;
                foreach ($orderLines as $key => $val) {
                    $product = PlatformProduct::where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $val->$destination_identity, 'is_deleted' => 0])->select('api_product_id')->first();;


                    $qty = (int) $val->sum;

                    if ($product) {
                        array_push($items, [
                            'quantity' =>  $qty, 'internalId' => $product->api_product_id,
                            'price' => $val->unit_price, 'total' => $val->total + $val->total_tax,
                            'taxCode' => null, 'noTaxTotal' => $val->total
                        ]);
                    } else {
                        $productNotFound = true;
                    }
                }
            }
        } else if ($type == "TO") {
            if ($orderLines) {

                $qty = 0;
                $productIdentityDestination = $destination_identity;
                if ($destination_identity == "api_product_id") {
                    $productIdentityDestination = "product_id"; //if  api_product_id then set product_id because in shipment line no api_product_id column available
                }
                foreach ($orderLines as $key => $val) {
                    $product = PlatformProduct::where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $val->$productIdentityDestination, 'is_deleted' => 0])->select('api_product_id')->first();;

                    $qty = (int) $val->sum;

                    if ($product) {
                        array_push($items, [
                            'quantity' =>  $qty, 'internalId' => $product->api_product_id,
                        ]);
                    } else {
                        $productNotFound = true;
                    }
                }
            }
        }
        return ['items' => $items, 'productNotFound' => $productNotFound];
    }
    /* Prepare Vendor Data */
    public function prepareReceiptData($receipt, $user_id, $user_integration_id, $platformOrderId, $type, $service = null)
    {
        if ($type == "PO") {
            $shipmentType = "Shipment";
        } else if ($type == "TO") {
            $shipmentType = "Transfer";
        }
        $shipmentObj = PlatformOrderShipment::where(['platform_order_id' => $platformOrderId, 'shipment_id' => @$receipt->internalId])->select('id')->first();
        if (!$shipmentObj) {
            $recordNo = @$receipt->createdFrom->internalId;
            $shipmentObj = new PlatformOrderShipment();
            $shipmentObj->user_id = $user_id;
            $shipmentObj->user_integration_id = $user_integration_id;
            $shipmentObj->platform_id = $this->platformId;
            $shipmentObj->platform_order_id = $platformOrderId;
            $shipmentObj->type = $shipmentType;
            $shipmentObj->sync_status = PlatformStatus::READY;
            $shipmentObj->shipment_id = @$receipt->internalId; // REFERENCE
            $shipmentObj->order_id = $recordNo; // ID (order number)
            $shipmentObj->created_on = @$receipt->createdDate; // CREATED_DATE
            $shipmentObj->realease_date =  @$receipt->tranDate;; // EXPECTED_DATE
            $shipmentObj->save();
            if (count(@$receipt->itemList->item)) {
                foreach (@$receipt->itemList->item as $item) {
                    $shipmentLineObj = PlatformOrderShipmentLine::where([
                        'platform_order_shipment_id' => $shipmentObj->id, 'row_id' => @$item->line
                    ])->select('id')
                        ->first();
                    if (!$shipmentLineObj) {
                        $shipmentLineObj = new PlatformOrderShipmentLine();
                        $shipmentLineObj->platform_order_shipment_id = $shipmentObj->id;
                        $shipmentLineObj->row_id = @$item->line;
                        $shipmentLineObj->product_id = @$item->item->internalId;
                        $shipmentLineObj->sku = @$item->item->internalId;
                        $shipmentLineObj->price = @$item->rate && !empty(@$item->rate) ? $item->rate : 0;
                        if (@$item->currency->internalId) {
                            $shipmentLineObj->currency = $this->getCurrencyDetailByID($service, $user_id, $user_integration_id, $item->currency->internalId);
                        }
                        $shipmentLineObj->warehouse_id = $this->GetOrderLocation($item, $user_id, $user_integration_id);
                        $shipmentLineObj->quantity = @$item->quantity;
                        $shipmentLineObj->save();
                    }
                }
            }
            PlatformOrder::where('id', $platformOrderId)->update(['shipment_status' => PlatformStatus::READY]);
        }
    }
    /* Insert / Update Product Inventory */
    public function updateOrCreateProductInventory($user_id, $user_integration_id, $product)
    {
        /* Quantity */
        $warehouseId = @$product->inventoryLocationJoin->internalId[0]->searchValue->internalId;
        $orderableQuantity =  @$product->basic->locationQuantityOnHand[0]->searchValue ? $product->basic->locationQuantityOnHand[0]->searchValue : 0;
        $productInventoryList = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'platform_product_id' => $product->platform_product_id,
            'sku' => @$product->basic->itemId[0]->searchValue,
            'api_warehouse_id' => $warehouseId,
            'quantity' => $orderableQuantity,
            'sync_status' => "Ready",
            'api_updated_at' => @$product->basic->lastQuantityAvailableChange[0]->searchValue,
        ];
        $find = PlatformProductInventory::where([
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'platform_product_id' => $product->platform_product_id,
            'api_warehouse_id' => (string)$warehouseId,
        ])->first();
        if ($find) {
            if ($find->quantity != $orderableQuantity) {
                $find->user_id = $user_id;
                $find->user_integration_id = $user_integration_id;
                $find->platform_id = $this->platformId;
                $find->platform_product_id = $product->platform_product_id;
                $find->sku = @$product->basic->itemId[0]->searchValue;
                $find->api_warehouse_id = $warehouseId;
                $find->quantity = $orderableQuantity;
                $find->sync_status = PlatformStatus::READY;
                $find->api_updated_at = @$product->basic->lastQuantityAvailableChange[0]->searchValue;
                $find->save();
            }
        } else {
            PlatformProductInventory::create($productInventoryList);
        }
    }
}
