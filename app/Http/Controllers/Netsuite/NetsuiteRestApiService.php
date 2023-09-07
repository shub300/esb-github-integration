<?php

namespace App\Http\Controllers\Netsuite;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;

use App\Http\Controllers\Netsuite\Api\NetsuiteRestApi;
use App\Models\PlatformCustomer;
use App\Models\PlatformCustomerAdditionalInformation;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use Log;
use Auth;
use Carbon\Carbon;
use Lang;
use Validator;

class NetsuiteRestApiService extends  NetsuiteRestApi
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $myPlatform = "netsuiteerp";
    public $netsuiteApi, $helper, $log, $platformId, $mapping;
    public function __construct()
    {
       
        $this->mapping = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }
    /* Prepare Product Data */
    public function prepareProductData($product, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $product_name = @$product['fullname'];
        $attributes = [];
        if ($product['parentId'] != 0 && isset($product['attributeGroups']) && count($product['attributeGroups']) > 0) {
            foreach ($product['attributeGroups'] as $group) {
                if (isset($group['attributes']) && count($group['attributes']) > 0) {
                    foreach ($group['attributes'] as $attr) {
                        if (isset($attr['name']) && $attr['name'] != '') {
                            $attributes[] = $attr['name'];
                        }
                    }
                }
            }
            $product_name = trim(@$product['name'] . ' - ' . implode(', ', $attributes));
        }


        $ProductPrimaryID = NULL;
        $productCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => @$product['id'],
            'sku' =>@$product['custitem_sku'],
            'upc' => @$product['upccode'],
            'product_name' =>  $product_name,
            'description' =>@$product['description'],
            'api_updated_at' => @$product['lastmodifieddate'],
            'api_created_at' => @$product['api_created_at'],
            'is_deleted' => isset($product['isinactive']) && $product['isinactive']=="F" ? 0 : 1,
            'product_sync_status' => "Ready",
            'price' => @$product['custitem_price'] ? @$product['custitem_price'] : 0,
        ];
        if (isset($product['linked_id'])) {
            $productCreate['linked_id'] = $product['linked_id'];
        }


        $findProduct = PlatformProduct::where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => @$product['id'],
        ])->first();
        if ($findProduct) {

            if ($findProduct->api_updated_at != $product['modifiedDate']) {
                $findProduct->product_sync_status = "Ready";
            }

            $ProductPrimaryID = $findProduct->id;
            $findProduct->sku =  isset($product['masterSku']) ? $product['masterSku'] : null;
            $findProduct->mpn = isset($product['mpn']) ? $product['mpn'] : null;
            $findProduct->upc = isset($product['upc']) ? $product['upc'] : null;
            $findProduct->bundle = isset($product['bundle']) ? $product['bundle'] : null;
            $findProduct->product_name =  $product_name;
            $findProduct->description =  isset($product['description']) ? $product['description'] : null;
            $findProduct->product_status =  isset($product['active']) ? $product['active'] : null;
            $findProduct->api_updated_at = isset($product['modifiedDate']) ? $product['modifiedDate'] : null;
            //$findProduct->stock_track = isset($product['TrackQtyOnHand']) ? $product['TrackQtyOnHand'] : null;
            $findProduct->price = @$product['vendorCost']['amount'] ? @$product['vendorCost']['amount'] : 0;
            $findProduct->is_deleted = 0;
            if (isset($product['linked_id'])) {
                $findProduct->linked_id = $product['linked_id'];
            }

            $findProduct->save();
        } else {
            $findProduct = PlatformProduct::create($productCreate);
            $ProductPrimaryID = $findProduct->id;
        }

        if ($ProductPrimaryID) {
            /* Store product extra attributes */
            $AttributeData = [
                'lenght' => isset($product['length']) ? $product['length'] : NULL,
                'height' => isset($product['height']) ? $product['height'] : NULL,
                'width' => isset($product['width']) ? $product['width'] : NULL,
            ];
            $AttributeData['platform_product_id'] = $ProductPrimaryID;
           // $this->createOrUpdateProductAttributes($ProductPrimaryID, $AttributeData);
            /* Store prices of product */
            $product['platform_product_id'] =  $ProductPrimaryID;
           // $this->createPriceList("pricelist",  $product);
        }

        return $ProductPrimaryID;
    }
    /* Prepare Vendor Data */
    public function prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $vendorPrimaryID = NULL;
       
        $vendorCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_customer_id' => isset($vendor['id']) ? $vendor['id'] : null,
            'api_customer_code' => isset($vendor['id']) ? $vendor['id'] : null,//isset($vendor['externalid']) ? $vendor['externalid'] : null,
            'first_name' => isset($vendor['legalname']) ? $vendor['legalname'] : null,
            'email' =>  isset($vendor['email']) ? $vendor['email'] : null,
            'customer_name' => isset($vendor['legalname']) ? $vendor['legalname'] : null,
            'phone' => isset($vendor['phone']) ? $vendor['phone'] : null,
            'company_name' => isset($vendor['companyname']) ? $vendor['companyname'] : null,
            'is_deleted' => $vendor['isinactive']=="F" ? 0 : 1,
            'api_updated_at' => isset($vendor['lastmodifieddate']) ? $vendor['lastmodifieddate'] : null,
            'api_created_at' => isset($vendor['datecreated']) ? $vendor['datecreated'] : null,
            'address1' =>  isset($vendor['defaultbillingaddress']) ? $vendor['defaultbillingaddress'] : null,
            'type' => "Vendor",

        ];
        if ($vendor['isinactive']=="F") {
            $vendorCreate['sync_status'] = "Ready";
        } else {
            $vendorCreate['sync_status'] = "Inactive";
        }
        if (isset($vendor['linked_id'])) {
            $vendorCreate['linked_id'] = $vendor['linked_id'];
        }
        if ($is_initial_sync) { //When is_initial_sync=1
            $findVendor = PlatformCustomer::create($vendorCreate);
            $vendorPrimaryID = isset($findVendor->id) ? $findVendor->id : null;
            if ($vendorPrimaryID) {
                $currency = @$vendor['currency'];
                if ($currency) {
                    PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'currency' => $currency]);
                }
            }
          
        } else {
            //When is_initial_sync=0
            $findVendor = PlatformCustomer::where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_customer_id' => $vendor['id'],
            ])->first();
            if (!$findVendor) {
                $findVendor = PlatformCustomer::create($vendorCreate);
                $vendorPrimaryID = isset($findVendor->id) ? $findVendor->id : null;
                if ($vendorPrimaryID) {
                    $currency = @$vendor['currency'];
                    if ($currency) {
                        PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'currency' => $currency]);
                    }
                }
            }
        }


        return  $vendorPrimaryID;
    }
    /* Get Order Location/Warehouse and Update */
    public function GetOrderLocation($order, $user_id, $user_integration_id, $location_object_id)
    {
        // $return = null;

        // if (isset($order)) {
        //     $ord_warehouse = $this->getFirstResultByConditions('platform_object_data', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $location_object_id, 'api_id' => $order->localtion->internalId], ['id']);
        //     if ($ord_warehouse) {
        //         $order_warehouse_id = $ord_warehouse->id;
        //     } else {

        //         $order_warehouse_id = $this->makeInsertGetId('platform_object_data', [
        //             'user_id' => $user_id,
        //             'platform_id' => $this->platformId,
        //             'api_id' => $order->localtion->internalId,
        //             'name' => $order->localtion->name,
        //             'user_integration_id' => $user_integration_id,
        //             'platform_object_id' => $location_object_id,

        //         ]);
        //     }
        //     $return = $order_warehouse_id;
        // }
        // return $return;
    }
    /* Search Line Items */
    public function getLineItems($transactionId,$orderPrimaryID,$user_id,$user_integration_id,$account){

        $filters = [
            "transactionId" => $transactionId
        ];
        $apicall = $this->salesOrderList($account, $filters,[],null,"query");

        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
            $ordersItems = $apicall['body']['items'];
            if (count($ordersItems)) {
                $lineItems = [];
                foreach ($ordersItems as $key => $items) {
                            $subtotal_tax=0;
                            if(isset($items["taxline"]) && $items["taxline"]== "T"){
                                $subtotal_tax=@$items['ratepercent'];
                            }
                            $lineItems[] = [
                                'platform_order_id' => $orderPrimaryID,
                                'api_order_line_id' => @$items['id'],
                                'api_product_id' => @$items['item'],
                                'product_name' => null,
                                'sku' =>  null,
                                'qty' =>@$items['quantity'],
                                'taxes' => null,
                                'total_tax' => 0, //total tax
                                'subtotal' => @$items['netamount'], //sub total
                                'subtotal_tax' => $subtotal_tax, //sub total tax
                                'total' => @$items['netamount'],
                                'unit_price' =>  @$items['price'],
                                'row_type' => "ITEM",
                                'item_row_sequence' => 1,
                            ];
                }
                if ($lineItems) {
                    PlatformOrderLine::insert($lineItems);
                    
                }
           }
        }
    }
     /* Search customer id in platform_customer table */
     public function SearchCustomerByIDAndSave($order , $userId, $userIntegrationId)
     {
         $return_response = ['customerId' => null, 'email' => null];
         $find = PlatformCustomer::select('id', 'email')->where([
             'user_integration_id'=> $userIntegrationId,
             'platform_id'=> $this->platformId,
             'api_customer_id'=> @$order['customerid'],
             'is_deleted'=> 0,
         ])->first();
 
         if ($find) {
             $return_response = ['customerId' => $find->id, 'email' => $find->email];
         } else {
            $customersList = [
                'user_id' => $userId,
                'user_integration_id' => $userIntegrationId,
                'platform_id' => $this->platformId,
                'api_customer_id' => @$order['customerid'],
                'first_name' => @$order['customer_firstname'],
                'email' =>@$order['customer_email'],
                'customer_name' => @$order['customer_firstname'],
                'company_name' => @$order['customer_companyname'],
            ];
            $save=PlatformCustomer::create($customersList);
            if ($save) {
                $return_response = ['customerId' => $save->id, 'email' => $save->email];
            }
         }
         return $return_response;
     }
    /* Prepare Sales Order Data */
    public function prepareOrderData($type="SO",$order, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $orderPrimaryID = NULL;
        $customer=$this->SearchCustomerByIDAndSave($order , $user_id, $user_integration_id);
        if(!empty($customer['customer_id'])){
            $orderDetail = [
                'user_id' => $user_id,
                'platform_id' => $this->platformId,
                'user_integration_id' => $user_integration_id,
                'platform_customer_id' => $customer['customer_id'],
                'order_type' => $type,
                'customer_email' => @$order['email'],
                'api_order_id' => @$order['id'],
                'order_number' =>@$order['tranid'],
                'order_date' => @$order['createddate'],
                'total_discount' =>  0,
                'discount_tax' => 0, //discount_tax
                'shipping_total' =>  0, //shipping_total
                'shipping_tax' =>  0, //shipping_tax
                'total_tax' =>  0,
                'total_amount' =>  @$order['foreigntotal'],
                'file_name' => @$order['status'],
                'sync_status' => "Ready",
                'carrier_code' =>  null,
                'payment_date' => @$order['trandate'],
                'currency' => @$order['currency'],
                'shipping_method' =>  null,
                'delivery_date' =>  null,
                'order_updated_at' => date('Y-m-d H:i:s'),
                'api_updated_at' => @$order['lastmodifieddate'],
              
            ];
           
            if (isset($orderDetail['linked_id'])) {
                $orderDetail['linked_id'] = $order['linked_id'];
            }
            if ($is_initial_sync) { //When is_initial_sync=1
                $findOrder = PlatformOrder::create($orderDetail);
                $orderPrimaryID = isset($findOrder->id) ? $findOrder->id : null;
                if ($orderPrimaryID) {
                   
                }
              
            } else {
                //When is_initial_sync=0
                $findOrder = PlatformOrder::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'api_customer_id' => @$order['id'],
                ])->first();
                if (!$findOrder) {
                    $saveOrder = PlatformOrder::create($orderDetail);
                    $orderPrimaryID = isset($saveOrder->id) ? $saveOrder->id : null;
                    if ($orderPrimaryID) {
                       
                    }
                }
            }
        }
       


        return  $orderPrimaryID;
    }
    /* Store/Update vendor Address */
    public function prepareVendorAddress($vendorAddrs,$Id){

        PlatformCustomer::where('id',$Id)->update(['address1'=>$vendorAddrs]);
    }
}
