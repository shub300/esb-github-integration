<?php

namespace App\Http\Controllers\GunBroker;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;

use App\Models\PlatformCustomer;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use Auth;
use DB;
use App\Http\Controllers\GunBroker\Api\GunBrokerApi;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformProduct;

class GunBrokerServiceController extends GunBrokerApi
{

    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'gunbroker';
    public $helper, $mobj, $log, $platformId, $map;
    public function __construct()
    {

        $this->helper = new ConnectionHelper();
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        // Set the platform ID
        $this->platformId = $this->helper->getPlatformIdByName(self::PLATFORMNAME);
    }
    /**
     * Save order, billing & shipping, order lines details
     * @param $ApiResponse response data

     */
    public function PrepareOrderModal($ApiResponse)
    {
        $orderPrimaryID = self::OrderModal($ApiResponse);
        if ($orderPrimaryID) {
            $ApiResponse['platform_order_id'] = $orderPrimaryID; //assign platform_order_id
            self::LineItemModal($ApiResponse);
            self::AddressModal($ApiResponse);
            self::CheckAndSaveTransaction($ApiResponse);
        }
    }
    /**
     * Save order  details
     * @param $ApiResponse response data

     */
    private function OrderModal($ApiResponse)
    {
        $detail = [
            'user_id' => $ApiResponse['user_id'],
            'platform_id' => $this->platformId,
            'user_integration_id' => $ApiResponse['user_integration_id'],
            'platform_customer_id' => $ApiResponse['platform_customer_id'],
            'order_type' => "SO",
            'api_order_id' => $ApiResponse['orderID'],
            'sync_status' => "Ready",
            'order_number' => $ApiResponse['orderID'],
            'order_date' => date('Y-m-d H:i:s', strtotime($ApiResponse['orderDate'])),
            'total_discount' => isset($ApiResponse['couponValue']) ? $ApiResponse['couponValue'] : NULL,
            'total_tax' => isset($ApiResponse['salesTaxTotal']) ? $ApiResponse['salesTaxTotal'] : NULL,
            'total_amount' => isset($ApiResponse['totalPrice']) ? $ApiResponse['totalPrice'] : NULL,
            'net_amount' => isset($ApiResponse['totalPrice']) ? $ApiResponse['totalPrice'] : NULL,
            'delivery_date' => isset($ApiResponse['shipDateUTC']) ? date('Y-m-d H:i:s', strtotime($ApiResponse['shipDateUTC'])) : NULL,
            'payment_date' => isset($ApiResponse['paymentReceivedDateUTC']) ? date('Y-m-d H:i:s', strtotime($ApiResponse['paymentReceivedDateUTC'])) : NULL,
            'api_updated_at' => isset($ApiResponse['lastModifiedDate']) ? date('Y-m-d H:i:s', strtotime($ApiResponse['lastModifiedDate'])) : NULL,
        ];
        $order = PlatformOrder::create($detail);
        if ($order) {
            return $order->id;
        }
        return false;
    }
    /**
     * Save order billing & shipping details
     * @param $ApiResponse response data

     */
    private function AddressModal($ApiResponse)
    {
        $billingdetail = [
            'address_name' => isset($ApiResponse['billToName']) ? $ApiResponse['billToName'] : NULL,
            'firstname' => isset($ApiResponse['billToName']) ? $ApiResponse['billToName'] : NULL,
            'address1' => isset($ApiResponse['billToAddress1']) ? $ApiResponse['billToAddress1'] : NULL,
            'address2' => isset($ApiResponse['billToAddress2']) ? $ApiResponse['billToAddress2'] : NULL,
            'city' => isset($ApiResponse['billToCity']) ? $ApiResponse['billToCity'] : NULL,
            'state' => isset($ApiResponse['billToState']) ? $ApiResponse['billToState'] : NULL,
            'postal_code' => isset($ApiResponse['billToPostalCode']) ? $ApiResponse['billToPostalCode'] : NULL,
            'country' => isset($ApiResponse['billToCountryCode']) ? $ApiResponse['billToCountryCode'] : NULL,
            'phone_number' => isset($ApiResponse['billToPhone']) ? $ApiResponse['billToPhone'] : NULL,
            'email' => isset($ApiResponse['billToEmail']) ? $ApiResponse['billToEmail'] : NULL,
            'address_type' => "billing",
            'platform_order_id' => $ApiResponse['platform_order_id'],
        ];
        PlatformOrderAddress::updateOrCreate([
            'platform_order_id' => $ApiResponse['platform_order_id'],
            'address_type' => "billing"
        ], $billingdetail);
        $shipingdetail = [

            'address_name' => isset($ApiResponse['shipToName']) ? $ApiResponse['shipToName'] : NULL,
            'firstname' => isset($ApiResponse['shipToName']) ? $ApiResponse['shipToName'] : NULL,
            'address1' => isset($ApiResponse['shipToAddress1']) ? $ApiResponse['shipToAddress1'] : NULL,
            'address2' => isset($ApiResponse['shipToAddress2']) ? $ApiResponse['shipToAddress2'] : NULL,
            'city' => isset($ApiResponse['shipToCity']) ? $ApiResponse['shipToCity'] : NULL,
            'state' => isset($ApiResponse['shipToState']) ? $ApiResponse['shipToState'] : NULL,
            'postal_code' => isset($ApiResponse['shipToPostalCode']) ? $ApiResponse['shipToPostalCode'] : NULL,
            'country' => isset($ApiResponse['shipToCountryCode']) ? $ApiResponse['shipToCountryCode'] : NULL,
            'phone_number' => isset($ApiResponse['shipToPhone']) ? $ApiResponse['shipToPhone'] : NULL,
            'email' => isset($ApiResponse['shipToEmail']) ? $ApiResponse['shipToEmail'] : NULL,
            'address_type' => "shipping",
            'platform_order_id' => $ApiResponse['platform_order_id'],
        ];
        PlatformOrderAddress::updateOrCreate([
            'platform_order_id' => $ApiResponse['platform_order_id'],
            'address_type' => "shipping"
        ], $shipingdetail);
    }
    /**
     * Save order line item details
     * @param $ApiResponse response data

     */
    private function LineItemModal($ApiResponse)
    {
        if (is_array($ApiResponse['orderItemsCollection'])) {
            $totalSalesTax = 0;
            foreach ($ApiResponse['orderItemsCollection'] as $key => $value) {
                $detail = [
                    'platform_order_id' => $ApiResponse['platform_order_id'],
                    'api_product_id' => $value['itemID'],
                    'product_name' => $value['title'],
                    'sku' => $value['sku'],
                    'qty' => $value['quantity'],
                    'unit_price' => $value['itemPrice'],
                    'subtotal' => $value['itemSubTotal'],
                    'subtotal_tax' => $value['salesTax'],
                    'row_type' => "ITEM",
                    'item_row_sequence' => 1,
                ];
                PlatformOrderLine::updateOrCreate([
                    'platform_order_id' => $ApiResponse['platform_order_id'],
                    'api_product_id' => $value['itemID']
                ], $detail);
                $totalSalesTax = $totalSalesTax + $value['salesTax'];//calculate total sale  order tax
            }
            /* complianceFee */
            if (isset($ApiResponse['complianceFee']) && $ApiResponse['complianceFee'] > 0) {
                PlatformOrderLine::create([
                    'platform_order_id' => $ApiResponse['platform_order_id'],
                    'api_product_id' => null,
                    'sku' => null,
                    'product_name' => "Compliance Fee",
                    'qty' => 1,
                    'subtotal' => $ApiResponse['complianceFee'],
                    'subtotal_tax' => 0,
                    'row_type' => "TAX",
                    'item_row_sequence' => 3,
                ]);
            }
            /* Shipping Cost */
            if (isset($ApiResponse['shipCost']) && $ApiResponse['shipCost'] > 0) {
                $complianceFee = isset($ApiResponse['complianceFee']) && $ApiResponse['complianceFee'] > 0 ? $ApiResponse['complianceFee'] : 0;//calculate total sale  order tax
                $salesTaxTotal = isset($ApiResponse['salesTaxTotal']) && $ApiResponse['salesTaxTotal'] > 0 ? $ApiResponse['salesTaxTotal'] : 0; //Total sales tax=Merchandise Total+Shipping+Compliance Fee         
                $salesTax = $salesTaxTotal - $complianceFee;//remove Compliance Fee  from salesTaxTotal
                $shippingTax = $salesTax - $totalSalesTax;//calculate shipping tax
                PlatformOrderLine::create([
                    'platform_order_id' => $ApiResponse['platform_order_id'],
                    'api_product_id' => null,
                    'sku' => null,
                    'product_name' => "Shipping Cost",
                    'qty' => 1,
                    'subtotal' => $ApiResponse['shipCost'],
                    'subtotal_tax' => abs($shippingTax),
                    'row_type' => "SHIPPING",
                    'item_row_sequence' => 2,
                ]);
            }
        }
    }
    /* Check if already have payment done for a work | insert and update*/
    private function CheckAndSaveTransaction($ApiResponse)
    {
        if ($ApiResponse) {
            $paymentMethod=null;
            if(isset($ApiResponse['paymentMethod']) && is_array($ApiResponse['paymentMethod'])){
                $paymentArr=$ApiResponse['paymentMethod'];
                reset($paymentArr);
                $paymentArr=array_values($paymentArr);
                $paymentMethod=isset($paymentArr[0])?$paymentArr[0]:null;
            }            
            $paymentDetails =
            [
            'platform_order_id' => isset($ApiResponse['platform_order_id'])?$ApiResponse['platform_order_id']:null,
            'transaction_id' =>  isset($ApiResponse['paymentLogTransactionID'])?$ApiResponse['paymentLogTransactionID']:null,
            'transaction_datetime' => isset($ApiResponse['paymentLogDateTime'])?$ApiResponse['paymentLogDateTime']:null,
            'transaction_type' => "Payment",
            'transaction_method' => $paymentMethod,
            'transaction_amount' =>isset($ApiResponse['paymentLogAmount'])?$ApiResponse['paymentLogAmount']:0,
            'transaction_reference' => isset($ApiResponse['paymentLogTransactionID'])?$ApiResponse['paymentLogTransactionID']:null,
            'sync_status' => "Ready",
            ];
            $find = PlatformOrderTransaction::select('sync_status', 'transaction_id', 'transaction_datetime', 'transaction_type', 'transaction_method', 'transaction_amount', 'transaction_reference')->where('platform_order_id', $ApiResponse['platform_order_id'])->first();
            if ($find) {
                if ($find->sync_status != "Synced") {
                    $find->transaction_id = isset($ApiResponse['transaction_id']) ? $ApiResponse['transaction_id'] : null;
                    $find->transaction_datetime = isset($ApiResponse['transaction_datetime']) ? $ApiResponse['transaction_datetime'] : null;
                    $find->transaction_type = isset($ApiResponse['transaction_type']) ? $ApiResponse['transaction_type'] : null;
                    $find->transaction_method = isset($ApiResponse['transaction_method']) ? $ApiResponse['transaction_method'] : null;
                    $find->transaction_amount = isset($ApiResponse['transaction_amount']) ? $ApiResponse['transaction_amount'] : null;
                    $find->transaction_reference = isset($ApiResponse['transaction_reference']) ? $ApiResponse['transaction_reference'] : null;
                    $find->save();
                    return true;
                } else {
                    return false;
                }
            } else {
                PlatformOrderTransaction::insert($paymentDetails);
                return true;
            }
        }
    }
    /**
     * Save customer details
     * @param $ApiResponse response data
     * @return primary_id of table
     */
    private function CustomerModal($ApiResponse)
    {
        $detail = [
            'company_name' => isset($ApiResponse['companyName']) ? $ApiResponse['companyName'] : NULL,
            'first_name' => isset($ApiResponse['firstName']) ? $ApiResponse['firstName'] : NULL,
            'last_name' => isset($ApiResponse['lastName']) ? $ApiResponse['lastName'] : NULL,
            'email' => isset($ApiResponse['email']) ? $ApiResponse['email'] : NULL,
            'phone' => isset($ApiResponse['phone']) ? $ApiResponse['phone'] : NULL,
            'fax' => isset($ApiResponse['faxPhone']) ? $ApiResponse['faxPhone'] : NULL,
            'api_customer_id' => isset($ApiResponse['userID']) ? $ApiResponse['userID'] : NULL,
            'api_customer_code' => isset($ApiResponse['userName']) ? $ApiResponse['userName'] : NULL,
            'user_id' => $ApiResponse['user_id'],
            'user_integration_id' => $ApiResponse['user_integration_id'],
            'platform_id' => $this->platformId,
        ];
        $customer = PlatformCustomer::updateOrCreate([
            'user_id' => $ApiResponse['user_id'],
            'user_integration_id' => $ApiResponse['user_integration_id'],
            'platform_id' => $this->platformId,
            'api_customer_id' => $ApiResponse['userID']
        ], $detail);
        return ($customer) ? $customer->id : 0;
    }

    /**
     *  Function used to store customer detail
     * @param $account is platform account detail
     * @return primary_id of table
     */
    public function StoreCustomerDetail($account, $ApiResponse)
    {

        $find = PlatformCustomer::where([
            'user_integration_id' => $ApiResponse['user_integration_id'],
            'platform_id' => $this->platformId,
            'api_customer_id' => $ApiResponse['buyer']['userID']
        ])->first();
        if (!$find) {
            $value = self::GetUserByID($account, $ApiResponse['buyer']['userID']);
            $value['user_id'] = $ApiResponse['user_id'];
            $value['user_integration_id'] = $ApiResponse['user_integration_id'];
            $value['platform_id'] = $this->platformId;
            if (is_array($value)) {
                return self::CustomerModal($value);
            } else if (!is_array($value)) {
                return $value;
            }
        } else {
            return $find->id;
        }
    }
    /**
     *  Function used to store product detail
     * @param $ApiResponse response data
     * @return primary_id of table
     */
    public function PrepareProductModal($ApiResponse)
    {
        $ProductPrimaryID = NULL;
        $productList = array(
            'user_id' => $ApiResponse['user_id'],
            'user_integration_id' => $ApiResponse['user_integration_id'],
            'platform_id' => $this->platformId,
            'api_product_id' => $ApiResponse['itemID'],
            'sku' => $ApiResponse['sku'],
            'product_name' =>  $ApiResponse['title'],
            'price_type' =>  $ApiResponse['fixedPrice'] > 0 ? 'Fixed' : 'Dynamic',
            'is_deleted' => 0,
        );
        $update = PlatformProduct::updateOrCreate([
            'user_id' => $ApiResponse['user_id'],
            'user_integration_id' => $ApiResponse['user_integration_id'],
            'platform_id' => $this->platformId,
            'api_product_id' => $ApiResponse['itemID'],
        ], $productList);
        $ProductPrimaryID = $update->id;
        return  $ProductPrimaryID;
    }
    /**
     *  Function used to get product identity mapping
     * @param $user_integration_id, the user_integration id
     * @param $platform_workflow_rule, the platform_workflow_rule id
     * @return json_encoded data to be return with 2 parameters as `source_identity` and `destination_identity`
     */
    public function ProductIdentityMapping($user_integration_id, $platform_workflow_rule)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $maping_data =  $this->map->getMappedField($user_integration_id, $platform_workflow_rule, $product_identity_obj_id);

        $source_row_data = $destination_row_data = 'sku';
        if ($maping_data) {

            if ($maping_data['destination_platform_id'] == self::PLATFORMNAME) {
                $destination_row_data = $maping_data['destination_row_data'];
                $source_row_data = $maping_data['source_row_data'];
            } else {
                $destination_row_data = $maping_data['source_row_data'];
                $source_row_data = $maping_data['destination_row_data'];
            }
        }
        return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
    }


    /**
     *  Function used to Update Product Attribute Like Inventory and Price or you update any other field Data
     * @param $user_id, the user id
     * @param $user_integration_id, the user_integration id
     * @param $platform_workflow_rule_id, the platform_workflow_rule_id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $source_platform_id, the source platform id eg. 1 or 2
     * @param $Inventory_arr, the inventory array
     * @param $destination_account, the destination_account
     * @param $singleExecute, single record execution
     * @return array data to be return with 2 parameters as `update_inventory_data` and `normal_product`
     */
    public function UpdateProduct($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $Inventory_arr, $destination_account, $singleExecute = false)
    {
        $return = true;
        $object_id = $this->helper->getObjectId('inventory');
        $warehouseArray = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id'], "regular", NULL, "multi", "source");

        $priceList = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_pricelist", ['id'], "default", NULL);
        if ($priceList) {
            $platform_object_data_id = $priceList->id;
        } else {
            $platform_object_data_id = NULL;
        }

        if (is_array($warehouseArray) && !empty($warehouseArray)) {
            foreach ($Inventory_arr as $product) {
                $product_price_arr = false;
                $product_inventory_arr = [];
                if ($singleExecute) {
                    if ($product->inventory_sync_status == "Failed" || $product->inventory_sync_status == "Ready") {
                        //get inventory records
                        $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $user_integration_id, 'api_product_id' => $product->bp_api_product_id], ['id', 'api_warehouse_id', 'quantity']);
                    }
                    if ($product->product_sync_status == "Failed" || $product->product_sync_status == "Ready") {
                        //get price record
                        $product_price_arr = $this->mobj->getFirstResultByConditions('platform_porduct_price_list', ['platform_product_id' => $product->id, 'platform_object_data_id' => $platform_object_data_id], ['price']);
                    }
                } else {
                    if ($product->inventory_sync_status == "Ready") {
                        //get inventory records
                        $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $user_integration_id, 'api_product_id' => $product->bp_api_product_id], ['id', 'api_warehouse_id', 'quantity']);
                    }
                    if ($product->product_sync_status == "Ready") {
                        //get price record
                        $product_price_arr = $this->mobj->getFirstResultByConditions('platform_porduct_price_list', ['platform_product_id' => $product->id, 'platform_object_data_id' => $platform_object_data_id], ['price']);
                    }
                }


                if (count($product_inventory_arr) > 0 && $product_price_arr) {

                    /* CASE-1 When we have Inventory and Price Update Available */
                    $sum = 0;
                    foreach ($product_inventory_arr as $product_inventory) {
                        if (in_array($product_inventory->api_warehouse_id, $warehouseArray)) {
                            $sum += $product_inventory->quantity;
                        }
                    }
                    $updatePayload = [];
                    if ($product->gun_price_type == "Fixed") {
                        //$updatePayload['fixedprice'] = $product_price_arr->price;// comment this line because client dont want to sync
                    }
                    // else if ($product->gun_price_type == "Dynamic") {
                    //     $updatePayload['price'] = $product_price_arr->price;

                    // }

                    $updatePayload['quantity'] = $sum;
                    //dd($updatePayload,$product->gun_price_type );
                    $response = self::UpdatetProductByID($destination_account, $product->gun_api_product_id, $updatePayload);

                    if (!empty($response) && $response == "Item {$product->gun_api_product_id} updated") {
                        $inventory_sync_status = $product_sync_status = "Synced";

                        $log_status = "success";
                        $error = NULL;
                    } else {
                        $inventory_sync_status = $product_sync_status = "Failed";

                        $log_status = "failed";
                        $error = $response;
                    }
                    $return = $error;
                    $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => $inventory_sync_status, 'product_sync_status' => $product_sync_status], ['id' => $product->id]);

                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, $log_status, $product->id, $error);
                } else if (count($product_inventory_arr) > 0 && $product_price_arr == false) {
                    /* CASE-2 When we have only Inventory Update Available */

                    $sum = 0;
                    foreach ($product_inventory_arr as $product_inventory) {
                        if (in_array($product_inventory->api_warehouse_id, $warehouseArray)) {
                            $sum += $product_inventory->quantity;
                        }
                    }
                    $updatePayload = ['quantity' => $sum];
                    $response = self::UpdatetProductByID($destination_account, $product->gun_api_product_id, $updatePayload);

                    if (!empty($response) && $response == "Item {$product->gun_api_product_id} updated") {
                        $inventory_sync_status = "Synced";
                        $log_status = "success";
                        $error = NULL;
                    } else {
                        $inventory_sync_status = "Failed";
                        $log_status = "failed";
                        $error = $response;
                    }
                    $return = $error;
                    $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => $inventory_sync_status], ['id' => $product->id]);

                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, $log_status, $product->id, $error);
                } else if (count($product_inventory_arr) == 0 && $product_price_arr) {
                    if ($product->gun_price_type == "Fixed") {
                       
                        // $updatePayload['fixedprice'] = $product_price_arr->price;// comment this line because client dont want to sync
                        // $response = self::UpdatetProductByID($destination_account, $product->gun_api_product_id, $updatePayload);

                        // if (!empty($response) && $response == "Item {$product->gun_api_product_id} updated") {
                        //     $product_sync_status = "Synced";
                        //     $log_status = "success";
                        //     $error = NULL;
                        // } else {
                        //     $product_sync_status = "Failed";
                        //     $log_status = "failed";
                        //     $error = $response;
                        // }
                        $product_sync_status = "Synced";
                        $log_status = "success";
                        $error = NULL;

                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => $product_sync_status], ['id' => $product->id]);
                        $return = $error;
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, $log_status, $product->id, $error);
                    }
                    // else if ($product->gun_price_type == "Dynamic") {
                    //     $updatePayload['price'] = $product_price_arr->price;
                    // }
                    /* CASE-3 When we have only Price Update Available */
                }
                //     else{
                //          /* CASE-4 When we have only Inventory Update Available */

                //         $sum = 0;
                //         $updatePayload=['quantity' => $sum];

                //         $response = self::UpdatetProductByID($destination_account, $product->gun_api_product_id, $updatePayload);

                //          if (empty($response) && $response=="Item {$product->gun_api_product_id} updated") {
                //             $inventory_sync_status = "Synced";
                //             $log_status = "success";
                //             $error = NULL;
                //         } else {
                //             $inventory_sync_status = "Failed";
                //             $log_status = "failed";
                //             $error = $response;

                //         }

                //         $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => $inventory_sync_status], ['id' => $product->id]);

                //         $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id,  $source_platform_id, $this->platformId, $object_id, $log_status, $product->id, $error);
                //     }
                // }
            }
        }
        return $return;
    }
}
