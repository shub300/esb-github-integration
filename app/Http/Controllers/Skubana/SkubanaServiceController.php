<?php

namespace App\Http\Controllers\Skubana;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Skubana\Api\SkubanaApi;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomer;
use App\Models\PlatformCustomerAdditionalInformation;
use App\Models\PlatformObjectData;
use App\Models\PlatformObjectDataAdditionalInformation;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformProductBundle;
use App\Models\PlatformProductInventory;
use App\Models\PlatformStates;
use Auth;
use Carbon\Carbon;
use Exception;
use Validator;

class SkubanaServiceController extends SkubanaApi
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $helper, $mapping, $platformId, $log;
    public static $myPlatform = 'skubana';
    public function __construct()
    {
        $this->mapping = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    /* Prepare Vendor Data */
    public function prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $vendorPrimaryID = NULL;
        $email = isset($vendor['contactEmail']) ? $vendor['contactEmail'] : null;
        if (!$email) {
            $email = isset($vendor['purchaseOrderEmail']) ? $vendor['purchaseOrderEmail'] : null;
        }

        $vendorCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_customer_id' => isset($vendor['vendorId']) ? $vendor['vendorId'] : null,
            'first_name' => isset($vendor['name']) ? $vendor['name'] : null,
            'email' => $email,
            'customer_name' => isset($vendor['name']) ? $vendor['name'] : null,
            'is_deleted' => $vendor['active'] ? 0 : $vendor['active'],
            'api_updated_at' => isset($vendor['modifiedDate']) ? $vendor['modifiedDate'] : null,
            'api_created_at' => isset($vendor['createdDate']) ? $vendor['createdDate'] : null,
            'address1' => isset($vendor['address']['address1']) ? $vendor['address']['address1'] : null,
            'address2' => isset($vendor['address']['city']) ? $vendor['address']['city'] : null,
            'address3' => isset($vendor['address']['state']) ? $vendor['address']['state'] : null,
            'country' => isset($vendor['address']['country']) ? $vendor['address']['country'] : null,
            'postal_addresses' => isset($vendor['address']['zipCode']) ? $vendor['address']['zipCode'] : null,
            'fax' => isset($vendor['fax']) ? $vendor['fax'] : null,
            'phone' => isset($vendor['contactPhone1']) ? $vendor['contactPhone1'] : null,
            'type' => isset($vendor['type']) ? $vendor['type'] : null,
        ];

        if ($vendor['active']) {
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
                $pay_terms = @$vendor['defaultVendorPaymentTerm']['description'];
                if ($pay_terms) {
                    PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'pay_terms' => $pay_terms]);
                }
            }
        } else {
            //When is_initial_sync=0
            $findVendor = PlatformCustomer::where(['api_customer_id' => $vendor['vendorId'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
            if ($findVendor) {
                $getReady = false;
                if ($findVendor->customer_name != @$vendor['name']) {
                    $getReady = true;
                } else if ($findVendor->address1 != @$vendor['address']['address1']) {
                    $getReady = true;
                } else if ($findVendor->address2 != @$vendor['address']['city']) {
                    $getReady = true;
                } else if ($findVendor->address3 != @$vendor['address']['state']) {
                    $getReady = true;
                } else if ($findVendor->country != @$vendor['address']['country']) {
                    $getReady = true;
                } else if ($findVendor->postal_addresses != @$vendor['address']['zipCode']) {
                    $getReady = true;
                } else if ($findVendor->email != $email) {
                    $getReady = true;
                } else if ($findVendor->fax != @$vendor['fax']) {
                    $getReady = true;
                } else if ($findVendor->phone != @$vendor['contactPhone1']) {
                    $getReady = true;
                }

                $findVendor->first_name = isset($vendor['name']) ? $vendor['name'] : null;
                $findVendor->customer_name = isset($vendor['name']) ? $vendor['name'] : null;
                $findVendor->email = $email;
                $findVendor->fax = isset($vendor['fax']) ? $vendor['fax'] : null;
                $findVendor->phone = isset($vendor['contactPhone1']) ? $vendor['contactPhone1'] : null;
                $findVendor->is_deleted = $vendor['active'] ? 0 : $vendor['active'];
                $findVendor->api_updated_at = isset($vendor['modifiedDate']) ? $vendor['modifiedDate'] : null;
                $findVendor->address1 = isset($vendor['address']['address1']) ? $vendor['address']['address1'] : null;
                $findVendor->address2 = isset($vendor['address']['city']) ? $vendor['address']['city'] : null;
                $findVendor->address3 = isset($vendor['address']['state']) ? $vendor['address']['state'] : null;
                $findVendor->country = isset($vendor['address']['country']) ? $vendor['address']['country'] : null;
                $findVendor->postal_addresses = isset($vendor['address']['zipCode']) ? $vendor['address']['zipCode'] : null;

                if (isset($vendor['linked_id'])) {
                    $findVendor->linked_id = $vendor['linked_id'];
                }

                if ($getReady) {
                    $findVendor->sync_status = "Ready";
                }

                $findVendor->save();
                $vendorPrimaryID = $findVendor->id;
                if ($vendorPrimaryID) {
                    $pay_terms = @$vendor['defaultVendorPaymentTerm']['description'];
                    if ($pay_terms) {
                        PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'pay_terms' => $pay_terms]);
                    }
                }
            } else {
                if ($vendor['active']) {
                    $findVendor = PlatformCustomer::create($vendorCreate);
                    $vendorPrimaryID = isset($findVendor->id) ? $findVendor->id : null;
                    if ($vendorPrimaryID) {
                        $pay_terms = @$vendor['defaultVendorPaymentTerm']['description'];
                        if ($pay_terms) {
                            PlatformCustomerAdditionalInformation::updateOrCreate(['platform_customer_id' => $vendorPrimaryID], ['platform_customer_id' => $vendorPrimaryID, 'pay_terms' => $pay_terms]);
                        }
                    }
                }
            }
        }

        return $vendorPrimaryID;
    }
    public function getProductByFilter($account, $productIdentiy, $productColomn = "productId")
    {
        $product = false;
        $apicall = $this->productByFilter($account, $productIdentiy, $productColomn);
        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
            $product = $apicall['body'];
        }
        return  $product;
    }

    /* prepare product bundle -> child items */
    public function prepareBundleItems($product, $user_id, $user_integration_id)
    {
        PlatformProductBundle::where('platform_product_id', $product['platform_product_id'])->update(['status' => 0]);
        if ($product['bundle']) {
            if (isset($product['bundledItems'])  && $product['bundledItems']) {
                $updateBundleStatus=[];
                foreach ($product['bundledItems'] as $bundle) {
                    $find = PlatformProduct::select('id')->where('api_product_id',$bundle['bundledProduct']['productId'])->first();
                    if ($find) {
                        $findBundle=PlatformProductBundle::where(['platform_product_id' => $product['platform_product_id'], 'platform_product_bundle_id' => $find->id])->first();
                        if($findBundle){
                            if($findBundle->api_product_bundle_id==$bundle['bundleItemId']){
                                if($findBundle->sku!=$bundle['bundledProduct']['masterSku'] || $findBundle->bundle_qty!=$bundle['bundledProductQuantity']){
                                    $findBundle->bundle_qty=$bundle['bundledProductQuantity'];
                                    $findBundle->sku=$bundle['bundledProduct']['masterSku'];
                                    $findBundle->status=1;
                                    $findBundle->save();
                                }else{
                                    $updateBundleStatus[]=$findBundle->id;
                                }
                            }

                        }else{
                            PlatformProductBundle::create([
                                'api_product_bundle_id' => $bundle['bundleItemId'],
                                'platform_product_id' => $product['platform_product_id'],
                                'platform_product_bundle_id' => $find->id,
                                'sku' => $bundle['bundledProduct']['masterSku'],
                                'bundle_qty' => $bundle['bundledProductQuantity'],
                                'status' => 1
                            ]);
                        }

                    } else {
                        $create = PlatformProduct::create([
                            'user_id'=>$user_id,
                            'user_integration_id'=>$user_integration_id,
                            'platform_id'=>$this->platformId,
                            'api_product_id'=>$bundle['bundledProduct']['productId'],
                            'sku' => isset($bundle['bundledProduct']['masterSku']) ? $bundle['bundledProduct']['masterSku'] : null,
                            'mpn' => isset($bundle['bundledProduct']['mpn']) ?$bundle['bundledProduct']['mpn'] : null,
                            'upc' => isset($bundle['bundledProduct']['upc']) ? $bundle['bundledProduct']['upc'] : null,
                            'bundle' => 0,
                        ]);
                        if ($create) {
                            $primaryID = $create->id; // pass last param as 1 for child product

                            PlatformProductBundle::create(['platform_product_id' => $product['platform_product_id'], 'platform_product_bundle_id' => $primaryID], ['api_product_bundle_id' => $bundle['bundleItemId'], 'platform_product_bundle_id' => $primaryID, 'platform_product_id' => $product['platform_product_id'],'sku' => $bundle['bundledProduct']['masterSku'], 'bundle_qty' => $bundle['bundledProductQuantity'], 'status' => 1]);

                        }
                    }
                }
                if($updateBundleStatus){
                    PlatformProductBundle::whereIn('id', $updateBundleStatus)->update(['status' => 1]);
                }

            }
        }
    }
    /* Prepare Product Data */
    public function prepareProductData($product, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $product_name = @$product['name'];
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

        $modifiedDate = isset($product['modifiedDate']) ? $product['modifiedDate'] : null;

        $ProductPrimaryID = NULL;
        $productCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $product['productId'],
            'sku' => isset($product['masterSku']) ? $product['masterSku'] : null,
            'mpn' => isset($product['mpn']) ? $product['mpn'] : null,
            'upc' => isset($product['upc']) ? $product['upc'] : null,
            'bundle' => $product['bundle'],
            'product_status' => isset($product['active']) ? $product['active'] : null,
            'product_name' => $product_name,
            'description' => isset($product['description']) ? $product['description'] : null,
            'api_updated_at' => $modifiedDate,
            'is_deleted' => 0,
            'weight' => isset($product['weight']) ? $product['weight'] : null,
            'product_status' => $product['type'],
            'product_sync_status' => "Ready",
            'price' => @$product['vendorCost']['amount'] ? @$product['vendorCost']['amount'] : 0,
        ];

        if ($product['type'] == 'VIRTUAL_PRODUCT') {
            $productCreate['product_sync_status'] = PlatformStatus::INACTIVE;
        }

        if (isset($product['linked_id'])) {
            $productCreate['linked_id'] = $product['linked_id'];
        }

        $findProduct = PlatformProduct::where(['api_product_id' => $product['productId'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
        if ($findProduct) {
            if ($product['type'] == 'VIRTUAL_PRODUCT') {
                $findProduct->product_sync_status = PlatformStatus::INACTIVE;
            } elseif ($findProduct->api_updated_at != $modifiedDate || $modifiedDate == null) {
                $findProduct->product_sync_status = PlatformStatus::READY;
            }

            $ProductPrimaryID = $findProduct->id;
            $findProduct->sku = isset($product['masterSku']) ? $product['masterSku'] : null;
            $findProduct->mpn = isset($product['mpn']) ? $product['mpn'] : null;
            $findProduct->upc = isset($product['upc']) ? $product['upc'] : null;
            $findProduct->bundle = $product['bundle'];
            $findProduct->product_name = $product_name;
            $findProduct->description = isset($product['description']) ? $product['description'] : null;
            $findProduct->product_status = isset($product['active']) ? $product['active'] : null;
            $findProduct->api_updated_at = $modifiedDate;
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
            /* Store Bundle Items */
            $product['platform_product_id']=$ProductPrimaryID;
            $this->prepareBundleItems($product, $user_id, $user_integration_id);
            /* Store product extra attributes */
            $AttributeData = [
                'lenght' => isset($product['length']) ? $product['length'] : NULL,
                'height' => isset($product['height']) ? $product['height'] : NULL,
                'width' => isset($product['width']) ? $product['width'] : NULL,
            ];
            $AttributeData['platform_product_id'] = $ProductPrimaryID;
            $this->createOrUpdateProductAttributes($ProductPrimaryID, $AttributeData);
            /* Store prices of product */
            $product['platform_product_id'] = $ProductPrimaryID;
            $this->createPriceList("pricelist", $product);
        }

        return $ProductPrimaryID;
    }

    /* Prepare Order Data */
    public function prepareOrderData($order_type, $order, $user_id, $user_integration_id, $user_workflow_rule_id, $purchase_object_id, $destinationPlatformId, $account)
    {
        $orderPrimaryID = NULL;
        if ($order_type == "PO") {
            $vendorId = @$order['vendorId'];
            $platform_customer_id = $this->findVendor("Vendor", $vendorId, $user_id, $user_integration_id, $account);
            $orderDetail = [
                'user_id' => $user_id,
                'user_workflow_rule_id' => $user_workflow_rule_id,
                'platform_id' => $this->platformId,
                'user_integration_id' => $user_integration_id,
                'platform_customer_id' => is_numeric($platform_customer_id) ? $platform_customer_id : null,
                'customer_email' => @$order['createdBy']['email'],
                'order_type' => $order_type,
                'api_order_id' => @$order['purchaseOrderId'],
                'order_number' => @$order['number'],
                'api_order_reference' => @$order['customPurchaseOrderNumber'],
                'currency' => @$order['currency'],
                'order_date' => @$order['dateCreated'],
                'order_status' => @$order['status'],
                'shipping_method' => @$order['delivery']['shippingMethodId'],
                'warehouse_id' => $this->getOrderWarehouse($order, $user_id, $user_integration_id, $account),
                'notes' => @$order['internalNotes'] ? $order['internalNotes'] : null,
                //'file_name' => @$order['messagesToVendor']?implode(",",$order['messagesToVendor']):null,
                'api_updated_at' => @$order['dateModified'],
                'order_updated_at' => date_create(),
                'sync_status' => "Ready",
            ];
        } else if ($order_type == "SO") {
        }
        $findOrder = PlatformOrder::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => $order_type, 'api_order_id' => @$order['purchaseOrderId']])->first();
        if (!$findOrder) {
            $saveOrder = PlatformOrder::create($orderDetail);
            $orderPrimaryID = isset($saveOrder->id) ? $saveOrder->id : null;
            if ($orderPrimaryID) {
                if (@$order['paymentTerm']['description']) { // if payment terms is available
                    PlatformOrderAdditionalInformation::updateOrCreate(['platform_order_id' => $orderPrimaryID], ['platform_order_id' => $orderPrimaryID, 'pay_terms' => @$order['paymentTerm']['description']]);
                }
                /* store order line items */
                if (isset($order['purchaseOrderItems']) && count($order['purchaseOrderItems'])) {
                    foreach ($order['purchaseOrderItems'] as $item) {
                        $originalPrice = @$item['billedUnitCost']['amount'] ? $item['billedUnitCost']['amount'] : 0;
                        $total = $item['quantity'] * $originalPrice;
                        //$percentage_amount=0;
                        // if(isset($item['percentageTax']) && $item['percentageTax']>0){
                        //  $percentage_amount=($total*$item['percentageTax'])/100;
                        //  $total=$total+$percentage_amount;
                        // }
                        $discount_amount = 0;
                        if (isset($item['discount'])) {
                            if ($item['discount']['discountType'] == "PERCENTAGE") {
                                $discount_amount = ($total * $item['discount']['amount']) / 100;
                            } else {
                                $discount_amount = $item['discount']['amount'];
                            }
                        }

                        $lineItems[] = [
                            'platform_order_id' => $orderPrimaryID,
                            'api_product_id' => @$item['purchaseOrderItemId'],
                            'product_name' => null,
                            'sku' => @$item['vendorProductMasterSku'],
                            'qty' => $item['quantity'],
                            'price' => $originalPrice,
                            'subtotal' => $total,
                            'row_type' => "ITEM",

                        ];
                        $findLine = PlatformOrderLine::where(['platform_order_id' => $orderPrimaryID, 'api_product_id' => @$item['purchaseOrderItemId']])->first();
                        if (!$findLine) {
                            if (isset($item['discount']['amount']) && $item['discount']['amount'] > 0) { // if we have discount amount
                                array_push($lineItems, [
                                    'platform_order_id' => $orderPrimaryID,
                                    'api_product_id' => null,
                                    'product_name' => isset($item['vendorProductMasterSku']) ? $item['vendorProductMasterSku'] . "-" . "Discount" : "Discount",
                                    'sku' => null,
                                    'price' => null,
                                    'qty' => 1,
                                    'subtotal' => -$discount_amount,
                                    'row_type' => "DISCOUNT",
                                ]);
                            }
                            // if(isset($item['landedUnitCost']['amount']) && $item['landedUnitCost']['amount']>0){// if we have landed unit amount
                            //  array_push($lineItems,[
                            //   'platform_order_id' => $orderPrimaryID,
                            //   'api_product_id' => null,
                            //   'product_name' => isset($item['vendorProductMasterSku'])?$item['vendorProductMasterSku']. "-"."Landed Unit Cost":"Landed Unit Cost",
                            //   'sku'=>null,
                            //   'price' => null,
                            //   'qty' => 1,
                            //   'subtotal' =>$item['landedUnitCost']['amount'],
                            //   'row_type' => "HANDLING",
                            //  ]);
                            // }


                            PlatformOrderLine::insert($lineItems);
                            $lineItems = null;
                        }
                    }
                    if (isset($order['shippingCost']['amount'])) {
                        $lineItems = [
                            'platform_order_id' => $orderPrimaryID,
                            'product_name' => "SHIPPING",
                            'qty' => 1,
                            'total' => $order['shippingCost']['amount'],
                            'subtotal' => $order['shippingCost']['amount'],
                            'row_type' => "SHIPPING"
                        ];
                        $findLine = PlatformOrderLine::where(['platform_order_id' => $orderPrimaryID, 'api_product_id' => "SHIPPING"])->first();
                        if (!$findLine) {
                            PlatformOrderLine::create($lineItems);
                        }
                    }
                    if (isset($order['otherCosts'])) {
                        foreach ($order['otherCosts'] as $item) {
                            $lineItems = [
                                'platform_order_id' => $orderPrimaryID,
                                'product_name' => isset($item['description']) ? $item['description'] : "OTHER COST",
                                'qty' => 1,
                                'total' => @$item['amount']['amount'],
                                'subtotal' => @$item['amount']['amount'],
                                'row_type' => "OTHER"
                            ];
                            $findLine = PlatformOrderLine::where(['platform_order_id' => $orderPrimaryID, 'product_name' => $item['description']])->first();
                            if (!$findLine) {
                                PlatformOrderLine::create($lineItems);
                            }
                        }
                    }
                } else {
                    PlatformOrder::where('id', $orderPrimaryID)->update(['sync_status' => 'Failed']);
                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $destinationPlatformId, $this->platformId, $purchase_object_id, 'success', $orderPrimaryID, "No line items found for order");
                }
            }
        }
        return $orderPrimaryID;
    }

    /* Get Warehouse and Update */
    public function getOrderWarehouse($order, $user_id, $user_integration_id, $account = null, $warehouse_object_id = null)
    {
        $return = null;
        if (isset($order['destinationWarehouseId'])) {
            if (!$warehouse_object_id) {
                $warehouse_object_id = $this->helper->getObjectId('warehouse');
            }
            $warehouse = PlatformObjectData::select('id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'api_id' => $order['destinationWarehouseId']])->first();
            if ($warehouse) {
                $return = $warehouse->id;
            } else {
                $save = PlatformObjectData::create([
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'api_id' => @$order['destinationWarehouseId'],
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouse_object_id,
                ]);
                if ($save) {
                    $apicall = $this->APICALL($account, "GET", "warehouse/{$order['destinationWarehouseId']}", [], [], "v1");

                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $warehouse = $apicall['body'];
                        if ($warehouse) {
                            PlatformObjectDataAdditionalInformation::create([
                                'city' => @$warehouse['address']['city'],
                                'state' => @$warehouse['address']['state'],
                                'country' => @$warehouse['address']['country'],
                                'address1' => @$warehouse['address']['address1'],
                                'postal_code' => @$warehouse['address']['zipCode'],
                                'api_address_id' => @$warehouse['address']['addressId'],
                                'user_integration_id' => $user_integration_id,
                                'platform_object_data_id' => $save->id,
                            ]);
                            $save->name = @$warehouse['name'];
                            $save->save();
                        }
                    }
                }

                $return = isset($save) ? $save->id : null;
            }
        }
        return $return;
    }

    /* Insert Update Product Attributes */
    public function createOrUpdateProductAttributes($ProductID = NULL, $PostData = [])
    {
        if ($ProductID && !empty($PostData)) {
            PlatformProductDetailAttribute::updateOrCreate(['platform_product_id' => $ProductID], $PostData);
        }
    }

    /* Insert / Update Product Price */
    private function createPriceList($ObjectName, $product)
    {
        $ProductPrimaryID = isset($product['platform_product_id']) ? $product['platform_product_id'] : null;
        if ($ProductPrimaryID) {
            $ObjectId = $this->helper->getObjectId($ObjectName);
            if ($ObjectId) {
                $objectData = PlatformObjectData::select('id', 'api_id')->where([
                    'user_id' => 0,
                    'user_integration_id' => 0,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $ObjectId,
                ])->get();

                if (count($objectData)) {
                    $vendor_cost = isset($product['vendorCost']['amount']) ? $product['vendorCost']['amount'] : 0;
                    $map_price = isset($product['mapPrice']['amount']) ? $product['mapPrice']['amount'] : 0;
                    $vendor_cost_currency_code = isset($product['vendorCost']['currency']) ? $product['vendorCost']['currency'] : 0;
                    $map_price_currency_code = isset($product['mapPrice']['currency']) ? $product['mapPrice']['currency'] : 0;
                    foreach ($objectData as $val) {
                        $platform_object_data_id = null;
                        if ($val->api_id == "vendorCost") { //we have unit price (vendorCost in api)
                            $platform_object_data_id = $val->id;
                            PlatformProductPriceList::updateOrCreate(['platform_product_id' => $ProductPrimaryID], ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $platform_object_data_id, 'price' => $vendor_cost, 'api_currency_code' => $vendor_cost_currency_code]);
                        } else if ($val->api_id == "mapPrice") { //we have unit price (mapPrice in api)
                            $platform_object_data_id = $val->id;
                            PlatformProductPriceList::updateOrCreate(['platform_product_id' => $ProductPrimaryID], ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $platform_object_data_id, 'price' => $map_price, 'api_currency_code' => $map_price_currency_code]);
                        }
                    }
                }
            }
        }
    }

    /* Product Identity Mapping */
    public function productIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $mapping_data = $this->mapping->getMappedField($userIntegrationId, $PlatformWorkFlowRuleID, $product_identity_obj_id);

        $source_row_data = $destination_row_data = 'sku';
        if ($mapping_data) {
            if ($mapping_data['destination_platform_id'] == self::$myPlatform) {
                $destination_row_data = $mapping_data['destination_row_data'];
                $source_row_data = $mapping_data['source_row_data'];
            } else {
                $destination_row_data = $mapping_data['source_row_data'];
                $source_row_data = $mapping_data['destination_row_data'];
            }
        }
        return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
    }

    /* Prepare Order Lines */
    public function prepareOrderLine($type = "SO", $orderLines, $userID, $userIntegrationId, $SourcePlatformId, $source_identity, $LOB = 0, $warehouseId = NULL, $vendorId = NULL, $date = NULL)
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
                            "orderedQty" => $qty
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
                                "orderQuantity" => $qty,
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

    /* find vendor */
    public function findVendor($type = "Vendor", $vendorId, $user_id, $user_integration_id, $account)
    {
        $return_response = null;
        try {
            $find = PlatformCustomer::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $vendorId, 'type' => $type, 'is_deleted' => 0])->first();
            if ($find) {
                $return_response = $find->id;
            } else {

                $apicall = $this->APICALL($account, "GET", "vendor/{$vendorId}", [], [], "v1");
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $vendor = $apicall['body'];
                    if ($vendor) {
                        $vendor['type'] = $type;
                        $return_response = $this->prepareVendorData($vendor, $user_id, $user_integration_id, 0);
                    } else {
                        $return_response = "No vendor detail found";
                    }
                } else {
                    $error = $this->handleResponseError($apicall);
                    $return_response = !empty($error) ? $error : "API Error";
                }
            }
        } catch (Exception $e) {
            \Log::error('SkubanaServiceController - findVendor - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Prepare Order Receipt Data */
    public function prepareOrderReceiptData($order_type, $shipment_type, $order, $user_id, $user_integration_id, $user_workflow_rule_id, $purchase_object_id, $account)
    {
        $findOrder = PlatformOrder::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => $order_type, 'api_order_id' => @$order['purchaseOrderId']])->select('id', 'api_updated_at')->first();
        if ($findOrder) {
            $platform_order_id = $findOrder->id;

            $manage_order_shipment_status = 0;
            if (count($order['purchaseOrderItems']) > 0) {
                foreach ($order['purchaseOrderItems'] as $rowitems) {
                    //Assuming like each line item as single shipment because we don't have any option to merge line items
                    if ($rowitems['status'] == 'RECEIVED') {

                        $shipmentinfo = [];
                        $shipmentinfo['user_id'] = $user_id;
                        $shipmentinfo['platform_id'] = $this->platformId;
                        $shipmentinfo['user_integration_id'] = $user_integration_id;
                        $shipmentinfo['platform_order_id'] = $platform_order_id;
                        $shipmentinfo['order_id'] = $order['purchaseOrderId'];
                        $shipmentinfo['shipment_id'] = $rowitems['purchaseOrderItemId'];
                        $shipmentinfo['created_on'] = @$rowitems['receivedDate'];
                        $shipmentinfo['realease_date'] = @$rowitems['billedDate'];
                        $shipmentinfo['transaction_id'] = @$rowitems['referenceNumber'];
                        $shipmentinfo['shipment_status'] = @$rowitems['status'];
                        $shipmentinfo['type'] = $shipment_type;
                        $findShipment = PlatformOrderShipment::where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id, 'shipment_id' => @$rowitems['purchaseOrderItemId']])->select('id')->first();
                        $shipmentPrimaryID = "";
                        if ($findShipment) {
                            //$shipmentPrimaryID = $findShipment->id;
                            //PlatformOrderShipment::where(['id' => $shipmentPrimaryID])->update($shipmentinfo);
                        } else {
                            $manage_order_shipment_status = 1;
                            $shipmentinfo['sync_status'] = 'Ready';

                            $saveShipment = PlatformOrderShipment::create($shipmentinfo);
                            $shipmentPrimaryID = isset($saveShipment->id) ? $saveShipment->id : null;
                        }

                        if ($shipmentPrimaryID) {
                            $originalPrice = @$rowitems['billedUnitCost']['amount'] ? $rowitems['billedUnitCost']['amount'] : 0;

                            $total = $rowitems['quantity'] * $originalPrice;
                            $percentage_amount = 0;
                            if (isset($rowitems['percentageTax']) && $rowitems['percentageTax'] > 0) {
                                $percentage_amount = ($total * $rowitems['percentageTax']) / 100;
                                $total = $total + $percentage_amount;
                            }
                            $discount_amount = 0;
                            if (isset($rowitems['discount'])) {
                                if ($rowitems['discount']['discountType'] == "PERCENTAGE") {
                                    $discount_amount = ($total * $rowitems['discount']['amount']) / 100;
                                } else {
                                    $discount_amount = $rowitems['discount']['amount'];
                                }
                            }

                            $shipmentlineinfo = [];
                            $shipmentlineinfo['platform_order_shipment_id'] = $shipmentPrimaryID;
                            $shipmentlineinfo['row_id'] = $rowitems['purchaseOrderItemId'];
                            $shipmentlineinfo['product_id'] = $rowitems['vendorProductId'];
                            $shipmentlineinfo['sku'] = $rowitems['vendorProductMasterSku'];
                            $shipmentlineinfo['price'] = $total;
                            $shipmentlineinfo['quantity'] = $rowitems['quantity'];
                            $shipmentlineinfo['currency'] = @$rowitems['billedUnitCost']['currency'];
                            $shipmentlineinfo['user_batch_reference'] = @$rowitems['memo'];

                            $findShipmentLine = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $shipmentPrimaryID, 'row_id' => $rowitems['purchaseOrderItemId']])->select('id')->first();
                            if ($findShipmentLine) {
                                PlatformOrderShipmentLine::where(['id' => $findShipmentLine->id])->update($shipmentlineinfo);
                            } else {
                                //$shipmentlineinfo['sync_status'] = 'Ready';
                                PlatformOrderShipmentLine::create($shipmentlineinfo);
                            }
                        }
                    }
                }
            }

            if ($manage_order_shipment_status == 1) {
                $findOrder->shipment_status = 'Ready';
            }
            $findOrder->api_updated_at = @$order['dateModified'];
            $findOrder->save();
        }
    }

    /* find & save Payment Methods */
    private function findAndSavePaymentMethod($order, $user_id, $user_integration_id)
    {
        if (isset($order)) {
            if (isset($order['paymentType']['orderPaymentTypeId'])) {
                $object_id = $this->helper->getObjectId("payment");
                PlatformObjectData::updateOrCreate([
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $object_id,
                    'api_id' => $order['paymentType']['orderPaymentTypeId'],
                ], [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $order['paymentType']['orderPaymentTypeId'],
                    'name' => $order['paymentType']['name'],
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $object_id,
                ]);
            }
        }
    }

    /* Insert / Update Product and Stock */
    public function prepareProductStockData($user_id, $user_integration_id, $product)
    {
        $return=null;
        if (isset($product['product']['productId']) && $product['product']['productId']) {
            $productData = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_product_id' => $product['product']['productId'],
                'product_name' => $product['product']['name'],
                'sku' => $product['product']['masterSku'],
                'mpn' => $product['product']['mpn'],
                'upc' => $product['product']['upc'],
                'weight' => $product['product']['productWeight'],
                'description' => $product['product']['description']
            ];

            if ($product['product']['productType'] == 'VIRTUAL_PRODUCT') {
                $productData['product_sync_status'] = PlatformStatus::INACTIVE;
                $productData['inventory_sync_status'] =  PlatformStatus::INACTIVE;
                $productData['api_inventory_lastmodified_time'] = NULL;
            } else {
                $productData['inventory_sync_status'] =  PlatformStatus::READY;
                $productData['api_inventory_lastmodified_time'] = date('Y-m-d H:i:s');
            }
            $platform_product = PlatformProduct::select('id')->where(['api_product_id' => $product['product']['productId'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
            if ($platform_product) {
                PlatformProduct::where('id', $platform_product->id)
                    ->update($productData);
                    $return=$platform_product_id = $platform_product->id;
             } else {
                $productData['product_sync_status'] =  PlatformStatus::READY;
                if ($product['product']['productType'] == 'VIRTUAL_PRODUCT') {
                    $productData['product_sync_status'] =  PlatformStatus::INACTIVE;
                }
                $platform_product = PlatformProduct::create($productData);
                $return=$platform_product_id = $platform_product->id;

            }

            PlatformProductInventory::updateOrCreate(
                ['platform_product_id' => $platform_product_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId],
                ['user_id' => $user_id, 'api_product_id' => $product['product']['productId'], 'sku' => $product['product']['masterSku'], 'quantity' => $product['onHandQuantity'], 'sync_status' => 'Ready']
            );
        }
        return $return;
    }

    /* Save Customer Detail */
    private function findCustomerAndSave($order, $user_id, $user_integration_id)
    {
        $return_response = null;
        try {
            if (isset($order['customer']['customerId'])) {
                $customerId = $order['customer']['customerId'];
                $find = PlatformCustomer::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $customerId, 'type' => "Customer", 'is_deleted' => 0])->first();
                if ($find) {
                    $return_response = $find->id;
                } else {
                    $save = PlatformCustomer::create([
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'api_customer_id' => $customerId,
                        'type' => "Customer",
                        'api_customer_id' => @$order['customer']['customerId'],
                        'first_name' => @$order['customer']['name'],
                        'email' => @$order['customer']['emailAddresses'][0],
                        'customer_name' => @$order['customer']['name'],
                        'company_name' => @$order['customer']['companyName'],
                        'phone' => @$order['shipPhone'],
                        'email2' => @$order['shipEmail'],
                        'address1' => @$order['shipAddress1'],
                        'address2' => @$order['shipCity'],
                        'address3' => @$order['shipState'],
                        'postal_addresses' => @$order['shipZipCode'],
                        'country' => @$order['shipCountry'],
                    ]);
                    $return_response = isset($save->id) ? $save->id : null;
                }
            }
        } catch (Exception $e) {
            \Log::error('SkubanaServiceController - findCustomerAndSave - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Prepare order data with api call */
    private function prepareOrderDetailData($orderApiId, $user_id, $user_integration_id, $account)
    {
        $return_response = null;
        try {
            $apicall = $this->APICALL($account, "GET", "orders/{$orderApiId}", [], [], "v1.1");

            if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                $order = $apicall['body'];

                if ($order) {
                    $platform_customer_id = $this->findCustomerAndSave($order, $user_id, $user_integration_id);
                    $orderInfo = [
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'platform_customer_id' => $platform_customer_id,
                        'user_integration_id' => $user_integration_id,
                        'api_order_id' => @$order['orderId'],
                        'order_number' => @$order['orderNumber'],
                        'customer_email' => @$order['customer']['emailAddresses'][0],
                        'order_date' => @$order['createdDate'],
                        'ship_date' => @$order['shipDate'],
                        'shipping_status' => @$order['deliveryStatus'],
                        'shipping_method' => @$order['shipMethod']['shippingCarrier'],
                        'warehouse_id' => @$order['warehouseId'],
                        'sync_status' => 'Ready',
                        'payment_date' => @$order['paymentDate'],
                        'shipment_status' => 'Ready',
                        'notes' => @$order['internalNotes'],
                        'total_amount' => @$order['orderTotal']['amount'],
                        'total_discount' => @$order['discount']['amount'],
                        'shipping_total' => @$order['shippingCost']['amount'],
                        'file_name' => @$order['notesFromBuyer'],
                        'api_updated_at' => @$order['modifiedDate'],
                        'order_updated_at' => date_create(),
                        'order_type' => "SO"
                    ];
                    $save = PlatformOrder::create($orderInfo);

                    if ($save) {
                        $order_primary_id = $save->id;
                        if (isset($order['orderItems'])) {
                            $items = [];
                            foreach ($order['orderItems'] as $item) {
                                $originalPrice = @$item['unitPrice']['amount'] ? $item['unitPrice']['amount'] : 0;
                                $total = $item['quantityOrdered'] * $originalPrice;
                                $items[] = [
                                    'platform_order_id' => $order_primary_id,
                                    'api_product_id' => @$item['product']['productId'],
                                    'api_order_line_id' => @$item['orderItemId'],
                                    'product_name' => @$item['product']['name'],
                                    'notes' => @$item['notes'],
                                    'sku' => @$item['product']['masterSku'],
                                    'qty' => $item['quantityOrdered'],
                                    'price' => $originalPrice,
                                    'subtotal' => $total,
                                    'subtotal_tax' => @$item['tax']['amount'],
                                    'discount_amount' => @$item['discount']['amount'],
                                    'row_type' => "ITEM",
                                ];
                            }

                            if ($items) {
                                PlatformOrderLine::insert($items);
                            }
                        }

                        /* store address */
                        if (@$order['shipment']) {
                            $address = array(
                                'platform_order_id' => $order_primary_id,
                                'address_type' => 'shipping',
                                'address_name' => @$order['shipName'],
                                'company' => @$order['shipCompany'],
                                'phone_number' => @$order['shipPhone'],
                                'email' => @$order['shipEmail'],
                                'address1' => @$order['shipAddress1'],
                                'address2' => @$order['shipAddress2'],
                                'address3' => @$order['shipAddress3'],
                                'address4' => @$order['address4'],
                                'city' => @$order['shipCity'],
                                'state' => @$order['shipState'],
                                'postal_code' => @$order['shipZipCode'],
                                'country' => @$order['shipCountry'],
                            );
                            PlatformOrderAddress::create($address);
                        }

                       //if (isset($order['paymentType']['orderPaymentTypeId'])) {
                            PlatformOrderTransaction::updateOrCreate([
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'platform_order_id' => $order_primary_id
                            ], [
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'platform_order_id' => $order_primary_id,
                                'transaction_amount' => isset($order['amountPaid']['amount']) ? $order['amountPaid']['amount'] : 0,
                                'transaction_method' => @$order['paymentType']['name'],
                                'transaction_datetime' => @$order['paymentDate'],
                                'transaction_id' => @$order['transactionId']
                            ]);
                      //  }

                        if (isset($order['salesChannel']['salesChannelId']) && $order['salesChannel']['salesChannelId']) {
                            PlatformOrderAdditionalInformation::updateOrCreate(['platform_order_id' => $order_primary_id], ['platform_order_id' => $order_primary_id, 'api_channel_id' => $order['salesChannel']['salesChannelId']]);
                        }

                        $return_response = $order_primary_id;
                    }
                } else {
                    $return_response = "No order detail found";
                }
            } else {
                $error = $this->handleResponseError($apicall);
                $return_response = !empty($error) ? $error : "API Error";
            }
        } catch (Exception $e) {
            \Log::error('SkubanaServiceController - prepareOrderDetailData - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Prepare Order Shipment Data */
    public function prepareOrderShipmentData($shipment_type, $shipment, $user_id, $user_integration_id, $account)
    {
        $findOrder = PlatformOrder::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => "SO", 'api_order_id' => @$shipment['orderId']])->select('id', 'api_updated_at')->first();
        if (!$findOrder) {
            $platform_order_id = $this->prepareOrderDetailData($shipment['orderId'], $user_id, $user_integration_id, $account);

            if ($platform_order_id) {
                $shipmentinfo = [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_order_id' => $platform_order_id,
                    'order_id' => @$shipment['orderId'],
                    'shipment_id' => @$shipment['shipmentId'],
                    'created_on' => @$shipment['created'],
                    'realease_date' => @$shipment['shipDate'],
                    'transaction_id' => @$shipment['transactionId'],
                    'tracking_info' => @$shipment['trackingNumber'],
                    'shipping_status' => @$shipment['deliveryStatus'],
                    'shipping_method' => @$shipment['shipMethod']['shippingCarrier'],
                    'warehouse_id' => @$shipment['warehouseId'],
                    'sync_status' => 'Ready',
                    'type' => $shipment_type
                ];

                $findShipment = PlatformOrderShipment::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id, 'shipment_id' => @$shipment['shipmentId']])->select('id')->first();
                if (!$findShipment) {
                    $saveShipment = PlatformOrderShipment::create($shipmentinfo);

                    $shipmentPrimaryID = isset($saveShipment->id) ? $saveShipment->id : null;
                }
            }
        }
    }

    /* Ser status=0 for platform_object_data table */
    public function setStatus($user_id, $user_integration_id, $platform_id, $object_id, $parent_id = NULL)
    {
        $condition = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $platform_id,
            'platform_object_id' => $object_id,
        ];
        if ($parent_id) {
            $condition['parent_id'] = $parent_id;
        }
        PlatformObjectData::where($condition)->update([
            'status' => 0
        ]);
    }

    /* Prepare Channel Data */
    public function prepareChannelData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;

            $create = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_id' => @$value['salesChannelId'],
                'platform_object_id' => $objectId,
                'api_code' => @$value['type'],
                'status' => @$value['active'],
                'name' => @$value['name'],
                'description' => @$value['companyName'],
            ];
            if ($is_initial_sync) { //When is_initial_sync=1
                $save = PlatformObjectData::create($create);
                $primaryID = $save->id;
            } else {
                //When is_initial_sync=0
                $find = PlatformObjectData::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $objectId,
                    'api_id' => @$value['salesChannelId'],
                ])->first();
                if ($find) {
                    $primaryID = $find->id;
                    $find->status = @$value['active'];
                    $find->name = @$value['name'];
                    $find->api_code = @$value['type'];
                    $find->description = @$value['companyName'];
                    $find->save();
                } else {
                    $findProduct = PlatformObjectData::create($create);
                    $primaryID = $findProduct->id;
                }
            }

        return $primaryID;
    }

    /* Prepare Types Data */
    public function preparePaymentTypesData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;
        $create = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_id' => @$value['orderPaymentTypeId'],
            'platform_object_id' => $objectId,
            'status' => 1,
            'name' => @$value['name'],
        ];
        if ($is_initial_sync) { //When is_initial_sync=1
            $save = PlatformObjectData::create($create);
            $primaryID = $save->id;
        } else {
            //When is_initial_sync=0
            $find = PlatformObjectData::where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'platform_object_id' => $objectId,
                'api_id' => @$value['orderPaymentTypeId'],
            ])->first();
            if ($find) {
                $primaryID = $find->id;
                $find->name = @$value['name'];
                $find->status = 1;
                $find->save();
            } else {
                $findProduct = PlatformObjectData::create($create);
                $primaryID = $findProduct->id;
            }
        }

        return $primaryID;
    }

    public function sliceUrl($dateTime, $sign = "|")
    {
        $date_slice = explode($sign, $dateTime);
        if (isset($date_slice[0]) && isset($date_slice[1])) {
            return [trim($date_slice[0]), trim($date_slice[1]), trim($date_slice[2])];
        } else {
            return trim($date_slice[0]);
        }
    }
}
