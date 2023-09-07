<?php

namespace App\Http\Controllers\QuickBooks;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Http\Controllers\QuickBooks\Api\QuickBooksApi;
use App\Models\PlatformCustomer;
use App\Models\PlatformField;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBundle;
use App\Models\PlatformProductPriceList;
use Illuminate\Support\Facades\DB;

class QuickBooksServiceController extends QuickBooksApi
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $helper, $map, $platformId, $log;
    public static $myPlatform = 'quickbooks';
    public function __construct()
    {
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    /* find Product By SKU */
    public function findProductBySKU($sku, $user_id, $user_integration_id, $account)
    {
        $arguments = ["query" => "select * from Item where sku='{$sku}' startPosition 1 maxResults 1"];
        $itemId = null;
        $result = $this->APICALL($account, "GET", "query", $arguments);
        if (isset($result['status_code']) && $result['status_code'] == 200) {
            if (isset($result['status_code']) && $result['status_code'] == 200) {
                $products = isset($result['body']['QueryResponse']['Item']) ? $result['body']['QueryResponse']['Item'] : [];
                if (count($products)) {
                    foreach ($products as $value) {
                        $this->prepareProductData($value, $user_id, $user_integration_id, 0);
                        $itemId = $value['Id'];
                    }
                }
            }
            return $itemId;
        }
    }
    public function findProductBySKUAndStore($sku, $user_id, $user_integration_id, $account)
    {
        $arguments = ["query" => "select * from Item where sku='{$sku}' startPosition 1 maxResults 1"];
        $itemId = $type = $detail = null;
        $result = $this->APICALL($account, "GET", "query", $arguments);
        if (isset($result['status_code']) && $result['status_code'] == 200) {
            if (isset($result['status_code']) && $result['status_code'] == 200) {
                $products = isset($result['body']['QueryResponse']['Item']) ? $result['body']['QueryResponse']['Item'] : [];
                if (count($products)) {
                    foreach ($products as $value) {
                        $itemId = $value['Id'];
                        $detail = $value;
                        $type = $value['Type'] == "Group" ? 1 : 0;
                        $this->prepareProductData($value, $user_id, $user_integration_id, 0);
                    }
                }
            }
        }
        return ['itemId' => $itemId, 'type' => $type, 'detail' => $detail];
    }
    public function findServiceProductBySKU($sku, $user_id, $user_integration_id, $account)
    {
        /* This method is used to search server type product by Sku when in SO we have shipping amount>0 */
        $return = ['name' => null, 'sku' => null, 'id' => null];
        if ($sku) {
            $find = PlatformProduct::where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'sku' => $sku, 'is_deleted' => 0])->first();
            if ($find) {
                $return = ['name' => $find->product_name, 'sku' => $find->sku, 'id' => $find->api_product_id];
            } else {
                $arguments = ["query" => "select * from Item where Type='Service' And sku='{$sku}' startPosition 1 maxResults 1"];

                $result = $this->APICALL($account, "GET", "query", $arguments);
                if (isset($result['status_code']) && $result['status_code'] == 200) {
                    if (isset($result['status_code']) && $result['status_code'] == 200) {
                        $products = isset($result['body']['QueryResponse']['Item']) ? $result['body']['QueryResponse']['Item'] : [];
                        if (count($products)) {
                            foreach ($products as $value) {
                                $this->prepareProductData($value, $user_id, $user_integration_id, 0);
                                $return = ['name' => $value['Name'], 'sku' => $value['Sku'], 'id' => $value['Id']];
                            }
                        }
                    }
                }
            }
        }

        return $return;
    }

    /* Prepare Modal Data */
    public function prepareProductData($product, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $ProductPrimaryID = NULL;
        $productCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $product['Id'],
            'sku' => isset($product['Sku']) ? $product['Sku'] : null,
            'bundle' => isset($product['Type']) && $product['Type'] == "Group" ? 1 : 0,
            'product_status' => isset($product['Active']) ? $product['Active'] : null,
            'product_name' => isset($product['Name']) ? $product['Name'] : null,
            'description' => isset($product['Description']) ? $product['Description'] : null,
            'stock_track' => isset($product['TrackQtyOnHand']) ? $product['TrackQtyOnHand'] : null,
            'api_updated_at' => isset($product['MetaData']['LastUpdatedTime']) ? $product['MetaData']['LastUpdatedTime'] : null,
            'is_deleted' => 0,
            'product_sync_status' => "Ready",
        ];

        if (isset($product['linked_id'])) {
            $productCreate['linked_id'] = $product['linked_id'];
        }
        //When is_initial_sync=0
        $findProduct = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $product['Id']])->first();
        if ($findProduct) {
            $ProductPrimaryID = $findProduct->id;
            $findProduct->sku = isset($product['Sku']) ? $product['Sku'] : null;
            $findProduct->bundle = isset($product['Type']) && $product['Type'] == "Group" ? 1 : 0;
            $findProduct->product_name = isset($product['Name']) ? $product['Name'] : null;
            $findProduct->description = isset($product['Description']) ? $product['Description'] : null;
            $findProduct->product_status = isset($product['Active']) ? $product['Active'] : null;
            $findProduct->api_updated_at = isset($product['MetaData']['LastUpdatedTime']) ? $product['MetaData']['LastUpdatedTime'] : null;
            $findProduct->stock_track = isset($product['TrackQtyOnHand']) ? $product['TrackQtyOnHand'] : null;
            $findProduct->is_deleted = 0;

            if (isset($product['linked_id'])) {
                $findProduct->linked_id = $product['linked_id'];
            }

            $findProduct->product_sync_status = "Ready";
            $findProduct->save();
        } else {
            $findProduct = PlatformProduct::create($productCreate);
            $ProductPrimaryID = $findProduct->id;
        }
        if ($ProductPrimaryID) {
            $price = isset($product['UnitPrice']) ? $product['UnitPrice'] : 0;
            $this->createPriceList($ProductPrimaryID, "pricelist", $price);
            if (isset($product['Type']) && $product['Type'] == "Group") {
                $product['platform_product_id'] = $ProductPrimaryID;
                $this->prepareBundleItems($product, $user_id, $user_integration_id);
            }
        }

        return $ProductPrimaryID;
    }
    /* prepare product bundle -> child items */
    public function prepareBundleItems($product, $user_id, $user_integration_id)
    {
        PlatformProductBundle::where('platform_product_id', $product['platform_product_id'])->update(['status' => 0]);
        if (isset($product['ItemGroupDetail']['ItemGroupLine'])) {

            $updateBundleStatus = [];
            foreach ($product['ItemGroupDetail']['ItemGroupLine'] as $bundle) {
                $find = PlatformProduct::select('id', 'sku')->where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $bundle['ItemRef']['value']])->first();
                if ($find) {
                    $findBundle = PlatformProductBundle::where(['platform_product_id' => $product['platform_product_id'], 'platform_product_bundle_id' => $find->id])->first();
                    if ($findBundle) {

                        if ($findBundle->bundle_qty != $bundle['Qty'] || $findBundle->sku != $find->sku) {
                            $findBundle->bundle_qty = $bundle['Qty'];
                            $findBundle->sku = $find->sku;
                            $findBundle->status = 1;
                            $findBundle->save();
                        } else {
                            $updateBundleStatus[] = $findBundle->id;
                        }
                    } else {
                        PlatformProductBundle::create([
                            'platform_product_id' => $product['platform_product_id'],
                            'platform_product_bundle_id' => $find->id,
                            'sku' => $find->sku,
                            'bundle_qty' => $bundle['Qty'],
                            'status' => 1
                        ]);
                    }
                } else {
                    $create = PlatformProduct::create([
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_product_id' => $bundle['ItemRef']['value'],
                        'bundle' => isset($bundle['ItemRef']['type']) && $bundle['ItemRef']['type'] == "Group" ? 1 : 0,
                    ]);
                    if ($create) {
                        $primaryID = $create->id; // pass last param as 1 for child product
                        PlatformProductBundle::create([
                            'platform_product_id' => $product['platform_product_id'],
                            'platform_product_bundle_id' => $primaryID,
                            'bundle_qty' => $bundle['Qty'],
                            'status' => 1
                        ]);
                    }
                }
            }
            if ($updateBundleStatus) {
                PlatformProductBundle::whereIn('id', $updateBundleStatus)->update(['status' => 1]);
            }
        }
    }

    /* Prepare Modal Data */
    public function prepareAccountData($account, $objectId, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;
        $create = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_id' => @$account['Id'],
            'platform_object_id' => $objectId,
            'api_code' => @$account['AccountType'],
            'other_code' => @$account['AccountSubType'],
            'status' => @$account['Active'],
            'name' => @$account['Name'],
        ];
        if ($is_initial_sync) { //When is_initial_sync=1
            $save = PlatformObjectData::create($create);
            $primaryID = $save->id;
        } else {
            //When is_initial_sync=0
            $find = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $objectId, 'api_id' => @$account['Id']])->first();
            if ($find) {
                $primaryID = $find->id;
                $find->status = @$account['Active'];
                $find->name = @$account['Name'];
                $find->api_code = @$account['AccountType'];
                $find->other_code = @$account['AccountSubType'];
                $find->save();
            } else {
                $findProduct = PlatformObjectData::create($create);
                $primaryID = $findProduct->id;
            }
        }

        return $primaryID;
    }

    /* Prepare Tax Rate Data */
    public function prepareTaxRateData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;

        $create = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_id' => @$value['Id'],
            'platform_object_id' => $objectId,
            'api_code' => @$value['RateValue'],
            'status' => @$value['Active'],
            'name' => @$value['Name'],
            'description' => @$value['Description'],
        ];
        if ($is_initial_sync) { //When is_initial_sync=1
            $save = PlatformObjectData::create($create);
            $primaryID = $save->id;
        } else {
            //When is_initial_sync=0
            $find = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $objectId, 'api_id' => @$value['Id']])->first();
            if ($find) {
                $primaryID = $find->id;
                $find->status = @$value['Active'];
                $find->name = @$value['Name'];
                $find->api_code = @$value['RateValue'];
                $find->description = @$value['Description'];
                $find->save();
            } else {
                $findProduct = PlatformObjectData::create($create);
                $primaryID = $findProduct->id;
            }
        }
        return $primaryID;
    }

    /* Prepare Tax Code Data */
    public function prepareTaxCodeData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;

        $create = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_id' => @$value['Id'],
            'platform_object_id' => $objectId,
            'api_code' => isset($value['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value']) ? $value['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value'] : NULL, //sales tax rate
            'status' => @$value['Active'],
            'name' => @$value['Name'],
            'description' => isset($value['PurchaseTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value']) ? $value['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value'] : NULL, //purchase tax rate
        ];
        if ($is_initial_sync) { //When is_initial_sync=1
            $save = PlatformObjectData::create($create);
            $primaryID = $save->id;
        } else {
            //When is_initial_sync=0
            $find = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $objectId, 'api_id' => @$value['Id']])->first();
            if ($find) {
                $primaryID = $find->id;
                $find->status = @$value['Active'];
                $find->name = @$value['Name'];
                $find->api_code = isset($value['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value']) ? $value['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value'] : NULL; //sales tax rate
                $find->description = isset($value['PurchaseTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value']) ? $value['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['Value'] : NULL; //purchase tax rate
                $find->save();
            } else {
                $findProduct = PlatformObjectData::create($create);
                $primaryID = $findProduct->id;
            }
        }

        return $primaryID;
    }

    /* Prepare Tax Code Data */
    public function prepareCustomFieldData($value, $objectId, $type, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;
        $customName = $customValue = NULL;
        if ($value['Type'] == "StringType") {
            $customValue = (int)filter_var(@$value['Name'], FILTER_SANITIZE_NUMBER_INT);
            $concatCustomName = @$value['StringValue'];
            $customName = $concatCustomName;

            // else if($value['Type']=="BooleanType"){
            //  $customValue=@$value['BooleanValue'];
            //  $customName=@$value['Name']."(".$customValue.")";

            // }
            $create = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'field_type' => 'default',
                'platform_object_id' => $objectId,
                'type' => $type,
                'status' => 1,
                'name' => @$value['Name'],
                'db_field_name' => $customValue,
                'description' => $customName,
            ];
            if ($is_initial_sync) { //When is_initial_sync=1
                $save = PlatformField::create($create);
                $primaryID = $save->id;
            } else {
                //When is_initial_sync=0
                $find = PlatformField::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'field_type' => 'default', 'platform_object_id' => $objectId, 'type' => $type, 'name' => @$value['Name']])->first();
                if ($find) {
                    $primaryID = $find->id;
                    $find->name = @$value['Name'];
                    $find->description = $customName;
                    $find->db_field_name = $customValue;
                    $find->status = 1;
                    $find->save();
                } else {
                    $findProduct = PlatformField::create($create);
                    $primaryID = $findProduct->id;
                }
            }
        }

        return $primaryID;
    }

    /* Insert / Update Product Price */
    private function createPriceList($ProductPrimaryID, $ObjectName, $UnitPrice = 0)
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
                    $UnitPrice = isset($UnitPrice) ? $UnitPrice : 0;
                    if ($find->api_id == "unit_price") { //we have unit price (UnitPrice in api)
                        PlatformProductPriceList::updateOrCreate(['platform_product_id' => $ProductPrimaryID], ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $find->id, 'price' => $UnitPrice]);
                    }
                }
            }
        }
    }

    /* Product Identity Mapping */
    public function productIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $mapping_data = $this->map->getMappedField($userIntegrationId, $PlatformWorkFlowRuleID, $product_identity_obj_id);

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
    public function prepareOrderLineTest($order, $user_id, $user_integration_id, $source_platform_id, $source_platform_name, $source_identity, $destination_identity, $shippingAccountId = null, $otherCostAccountId = null, $discountAccountId = null, $taxCode = null, $account)
    {
        $items = [];
        $productNotFound = false;
        $total_amount = 0;
        $orderLines = $order->platformOrderLine;

        if ($orderLines) {
            $qty = 0;

            foreach ($orderLines as $key => $val) {

                if ($val->row_type == "ITEM") {
                    $lineItem = $val->toArray();
                    if (isset($lineItem[$source_identity])) {
                        if ($source_platform_name == "skubana") { //if source platform is skubana (Extensiv order manager) then must be check bundle's child product
                            $sourceItem = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, $source_identity => $lineItem[$source_identity], 'is_deleted' => 0])->first();

                            if ($sourceItem) {
                                if ($sourceItem->bundle) {
                                    $bundleItems = @$sourceItem->PlatformProductBundle;
                                    $totalBundleQty = @$sourceItem->PlatformProductBundle->sum('bundle_qty') ? @$sourceItem->PlatformProductBundle->sum('bundle_qty') : 0;
                                    $childPrice = !empty($val->subtotal) ? $val->subtotal / ($totalBundleQty * $val->qty) : 0;

                                    if ($bundleItems) {
                                        foreach ($bundleItems as $bundle) {

                                            $childProduct = @$bundle->PlatformProductChild ? @$bundle->PlatformProductChild->toArray() : [];
                                            if (isset($childProduct[$destination_identity])) {
                                                $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $childProduct[$destination_identity], 'is_deleted' => 0])->first();

                                                if (!$product) {
                                                    $itemId = $this->findProductBySKU($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                                                } else {
                                                    $itemId = $product->api_product_id;
                                                }

                                                if ($itemId) {
                                                    $qty = (int) $val->qty * $bundle->bundle_qty;
                                                    $childSubtotalPrice = $qty * $childPrice;
                                                    array_push(
                                                        $items,
                                                        [
                                                            "DetailType" => "ItemBasedExpenseLineDetail",
                                                            "Amount" => $childSubtotalPrice,
                                                            "ItemBasedExpenseLineDetail" => [
                                                                "ItemRef" => ["value" => $itemId],
                                                                "Qty" => $qty,
                                                                "UnitPrice" => $childPrice
                                                            ]
                                                        ]
                                                    );
                                                    $total_amount = $total_amount + $childSubtotalPrice;
                                                } else {
                                                    $productNotFound = true;
                                                }
                                            } else {
                                                $productNotFound = true;
                                            }
                                        }
                                    }
                                } else {
                                    if (isset($lineItem[$destination_identity])) {
                                        $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();
                                        if (!$product) {
                                            $itemId = $this->findProductBySKU($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                                        } else {
                                            $itemId = $product->api_product_id;
                                        }

                                        if ($itemId) {
                                            $qty = (int) $val->qty;
                                            array_push(
                                                $items,
                                                [
                                                    "DetailType" => "ItemBasedExpenseLineDetail",
                                                    "Amount" => $val->subtotal,
                                                    "ItemBasedExpenseLineDetail" => [
                                                        "ItemRef" => ["value" => $itemId],
                                                        "Qty" => $qty,
                                                        "UnitPrice" => $val->price
                                                    ]
                                                ]
                                            );
                                            $total_amount = $total_amount + $val->subtotal;
                                        } else {
                                            $productNotFound = true;
                                        }
                                    } else {
                                        $productNotFound = true;
                                    }
                                }
                            } else {
                                $productNotFound = true;
                            }
                        } else {
                            if (isset($lineItem[$destination_identity])) {
                                $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();
                                if (!$product) {
                                    $itemId = $this->findProductBySKU($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                                } else {
                                    $itemId = $product->api_product_id;
                                }

                                if ($itemId) {
                                    $qty = (int) $val->qty;
                                    array_push(
                                        $items,
                                        [
                                            "DetailType" => "ItemBasedExpenseLineDetail",
                                            "Amount" => $val->subtotal,
                                            "ItemBasedExpenseLineDetail" => [
                                                "ItemRef" => ["value" => $itemId],
                                                "Qty" => $qty,
                                                "UnitPrice" => $val->price
                                            ]
                                        ]
                                    );
                                    $total_amount = $total_amount + $val->subtotal;
                                } else {
                                    $productNotFound = true;
                                }
                            } else {
                                $productNotFound = true;
                            }
                        }
                    }
                }
                if ($val->row_type == "SHIPPING") {
                    if ($shippingAccountId) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "AccountBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "Description" => $val->product_name,
                                "AccountBasedExpenseLineDetail" => [
                                    "AccountRef" => [
                                        "value" => $shippingAccountId,
                                        "name" => $val->product_name,
                                    ]
                                ]
                            ]
                        );
                        $total_amount = $total_amount + $val->subtotal;
                    }
                }

                if ($val->row_type == "OTHER") {
                    if ($otherCostAccountId) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "AccountBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "Description" => $val->product_name,
                                "AccountBasedExpenseLineDetail" => [
                                    "AccountRef" => [
                                        "value" => $otherCostAccountId,
                                        "name" => $val->product_name,
                                    ]
                                ]
                            ]
                        );
                        $total_amount = $total_amount + $val->subtotal;
                    }
                }
                // if ($val->row_type == "HANDLING") {

                //  if ($landedUnitAccountId) {
                //  array_push(
                //   $items,
                //   [
                //    "DetailType" => "AccountBasedExpenseLineDetail",
                //    "Amount" => $val->subtotal,
                //    "AccountBasedExpenseLineDetail" => [
                //    "AccountRef" => [
                //     "value" => $landedUnitAccountId,
                //     "name" => $val->product_name,
                //    ]
                //    ]
                //   ]
                //  );
                //  $total_amount = $total_amount + $val->subtotal;
                //  }
                // }
                if ($val->row_type == "DISCOUNT") {
                    if ($discountAccountId) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "AccountBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "Description" => $val->product_name,
                                "AccountBasedExpenseLineDetail" => [
                                    "AccountRef" => [
                                        "value" => $discountAccountId,
                                        "name" => $val->product_name,
                                    ]
                                ]
                            ]
                        );
                        $total_amount = $total_amount + ($val->subtotal);
                    }
                }
            }
        }

        return ['items' => $items, 'total_amount' => $total_amount, 'productNotFound' => $productNotFound];
    }
    /* Prepare Order Lines */
    public function prepareOrderLine($order, $user_id, $user_integration_id, $destination_platform_id, $source_identity, $shippingAccountId = null, $otherCostAccountId = null, $discountAccountId = null, $taxCode = null, $account)
    {
        $items = [];
        $productNotFound = false;
        $total_amount = 0;
        $orderLines = $order->platformOrderLine;

        if ($orderLines) {
            $qty = 0;

            foreach ($orderLines as $key => $val) {
                if ($val->row_type == "ITEM") {
                    $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $destination_platform_id, $source_identity => $val->sku, 'is_deleted' => 0])->first();
                    if (!$product) {
                        $itemId = $this->findProductBySKU($val->sku, $user_id, $user_integration_id, $account);
                    } else {
                        $itemId = $product->api_product_id;
                    }

                    if ($itemId) {
                        $qty = (int) $val->qty;
                        array_push(
                            $items,
                            [
                                "DetailType" => "ItemBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "ItemBasedExpenseLineDetail" => [
                                    "ItemRef" => ["value" => $itemId],
                                    "Qty" => $qty,
                                    "UnitPrice" => $val->price
                                ]
                            ]
                        );
                        $total_amount = $total_amount + $val->subtotal;
                    } else {
                        $productNotFound = true;
                    }
                }
                if ($val->row_type == "SHIPPING") {
                    if ($shippingAccountId) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "AccountBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "Description" => $val->product_name,
                                "AccountBasedExpenseLineDetail" => [
                                    "AccountRef" => [
                                        "value" => $shippingAccountId,
                                        "name" => $val->product_name,
                                    ]
                                ]
                            ]
                        );
                        $total_amount = $total_amount + $val->subtotal;
                    }
                }

                if ($val->row_type == "OTHER") {
                    if ($otherCostAccountId) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "AccountBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "Description" => $val->product_name,
                                "AccountBasedExpenseLineDetail" => [
                                    "AccountRef" => [
                                        "value" => $otherCostAccountId,
                                        "name" => $val->product_name,
                                    ]
                                ]
                            ]
                        );
                        $total_amount = $total_amount + $val->subtotal;
                    }
                }
                // if ($val->row_type == "HANDLING") {

                //  if ($landedUnitAccountId) {
                //  array_push(
                //   $items,
                //   [
                //    "DetailType" => "AccountBasedExpenseLineDetail",
                //    "Amount" => $val->subtotal,
                //    "AccountBasedExpenseLineDetail" => [
                //    "AccountRef" => [
                //     "value" => $landedUnitAccountId,
                //     "name" => $val->product_name,
                //    ]
                //    ]
                //   ]
                //  );
                //  $total_amount = $total_amount + $val->subtotal;
                //  }
                // }
                if ($val->row_type == "DISCOUNT") {
                    if ($discountAccountId) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "AccountBasedExpenseLineDetail",
                                "Amount" => $val->subtotal,
                                "Description" => $val->product_name,
                                "AccountBasedExpenseLineDetail" => [
                                    "AccountRef" => [
                                        "value" => $discountAccountId,
                                        "name" => $val->product_name,
                                    ]
                                ]
                            ]
                        );
                        $total_amount = $total_amount + ($val->subtotal);
                    }
                }
            }
        }

        return ['items' => $items, 'total_amount' => $total_amount, 'productNotFound' => $productNotFound];
    }

    /* Prepare Invoice Lines */
    public function prepareInvoiceLine($platform_invoice, $user_integration_id, $platform_workflow_rule_id, $default_invoice_service_item_id)
    {
        $Lines = [];
        $invoiceLines = $platform_invoice->platformInvoiceLine;
        if ($invoiceLines) {
            $default_invoice_service_item_total_amount = 0;
            $default_invoice_service_item_total_qty = 0;

            foreach ($invoiceLines as $invoiceLine) {
                $invoice_service_item = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'invoice_transaction_type', ['api_id'], 'cross', $invoiceLine->api_code);
                if ($invoice_service_item) {
                    $Lines[] = ['Description' => $invoiceLine->product_name, 'DetailType' => 'SalesItemLineDetail', 'Amount' => $invoiceLine->total, 'SalesItemLineDetail' => ['ItemRef' => ['value' => $invoice_service_item->api_id], 'Qty' => $invoiceLine->qty, 'UnitPrice' => ($invoiceLine->total / $invoiceLine->qty), 'TaxCodeRef' => ['value' => 'NON']]];
                } else {
                    $default_invoice_service_item_total_amount = $default_invoice_service_item_total_amount + $invoiceLine->total;
                    $default_invoice_service_item_total_qty = $default_invoice_service_item_total_qty + $invoiceLine->qty;
                }
            }

            if ($default_invoice_service_item_id && $default_invoice_service_item_total_qty) {
                $Lines[] = ['Description' => 'Default Service Item', 'DetailType' => 'SalesItemLineDetail', 'Amount' => $default_invoice_service_item_total_amount, 'SalesItemLineDetail' => ['ItemRef' => ['value' => $default_invoice_service_item_id], 'Qty' => $default_invoice_service_item_total_qty, 'UnitPrice' => ($default_invoice_service_item_total_amount / $default_invoice_service_item_total_qty), 'TaxCodeRef' => ['value' => 'NON']]];
            }
        }

        return $Lines;
    }
    public function prepareSOOrderLineTest($order, $user_id, $user_integration_id, $source_platform_id, $source_platform_name, $source_identity, $destination_identity, $discountAccountId = null, $shippingItemSKU = null, $account)
    {
        $items = [];
        $productNotFound = false;
        $discountError = $shippingError = null;
        $total_discount_amount = $taxApply = $taxTotal = 0;
        $orderLines = isset($order->platformOrderLine) ? $order->platformOrderLine : null;

        if ($orderLines) {
            $qty = 0;

            foreach ($orderLines as $key => $val) {
                if ($val->row_type == "ITEM") {
                    $lineItem = $val->toArray();
                    if (isset($lineItem[$source_identity])) {
                        if ($source_platform_name == "skubana") { //if source platform is skubana (Extensiv order manager) then must be check bundle's child product

                            if (isset($lineItem[$destination_identity])) {
                                $itemDetail = null;
                                $findBySku = false;
                                $product = PlatformProduct::select('id', 'api_product_id', 'bundle')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();
                                if (!$product) {
                                    $item = $this->findProductBySKUAndStore($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                                    $bundle = $item['type'];

                                    if ($bundle) {
                                        $findBySku = true;
                                        $itemDetail = $item['detail'];
                                        $isItemLineBundle = true;
                                    } else {
                                        $isItemLineBundle = false;
                                    }
                                    $itemId = $item['itemId'];
                                } else {

                                    if ($product->bundle) {
                                        $isItemLineBundle = true;
                                    } else {
                                        $isItemLineBundle = false;
                                    }
                                    $itemId = $product->api_product_id;
                                }

                                if ($itemId) {
                                    $qty = (int) $val->qty;
                                    $price = floatval($val->price);

                                    $line_total_amount = $qty * $price;
                                    if ($val->subtotal_tax > 0) {
                                        $taxCodeForLine = "TAX";
                                        $taxApply++;
                                        $taxTotal = $taxTotal + $val->subtotal_tax;
                                    } else {
                                        $taxCodeForLine = "NON";
                                    }
                                    if ($isItemLineBundle) {
                                        $childProductLine = [];
                                        if ($findBySku) {
                                            //this will work only when product searched by api and it is a bundle product
                                            $totalBundleQty = 0;
                                            if (isset($itemDetail['ItemGroupDetail']['ItemGroupLine'])) {

                                                foreach ($itemDetail['ItemGroupDetail']['ItemGroupLine'] as $bundle) {
                                                    $totalBundleQty = $totalBundleQty + $bundle['Qty'];
                                                }
                                                $childPrice = !empty($val->subtotal) ? $val->subtotal / ($totalBundleQty * $val->qty) : 0;
                                                foreach ($itemDetail['ItemGroupDetail']['ItemGroupLine'] as $bundle) {
                                                    $childItemId = $bundle['ItemRef']['value'];
                                                    $childQty = (int) $val->qty * $bundle['Qty'];
                                                    $child_line_total_amount = $childQty * $childPrice;
                                                    array_push(
                                                        $childProductLine,
                                                        [
                                                            "DetailType" => "SalesItemLineDetail",
                                                            "Description" => $bundle['ItemRef']['name'],
                                                            "Amount" => $child_line_total_amount,
                                                            "SalesItemLineDetail" => [
                                                                "ItemRef" => ["value" => $childItemId],
                                                                "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                                "Qty" => $childQty,
                                                                "UnitPrice" => $childPrice
                                                            ]
                                                        ]
                                                    );
                                                }
                                            }
                                            $bundleItems=false;
                                        } else {
                                            $bundleItems = @$product->PlatformProductBundle;
                                            $totalBundleQty = @$product->PlatformProductBundle->sum('bundle_qty') ? @$product->PlatformProductBundle->sum('bundle_qty') : 0;
                                            $childPrice = !empty($val->subtotal) ? $val->subtotal / ($totalBundleQty * $val->qty) : 0;
                                        }




                                        if ($bundleItems) {

                                            foreach ($bundleItems as $bundle) {

                                                $childProduct = @$bundle->PlatformProductChild ? @$bundle->PlatformProductChild->toArray() : [];
                                                if (isset($childProduct[$destination_identity])) {
                                                    $childItemId = $childProduct['api_product_id'];
                                                    $childQty = (int) $val->qty * $bundle->bundle_qty;
                                                    $child_line_total_amount = $childQty * $childPrice;
                                                    array_push(
                                                        $childProductLine,
                                                        [
                                                            "DetailType" => "SalesItemLineDetail",
                                                            "Description" => @$childProduct['product_name'] ? $childProduct['product_name'] : $val->notes,
                                                            "Amount" => $child_line_total_amount,
                                                            "SalesItemLineDetail" => [
                                                                "ItemRef" => ["value" => $childItemId],
                                                                "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                                "Qty" => $childQty,
                                                                "UnitPrice" => $childPrice
                                                            ]
                                                        ]
                                                    );
                                                } else {
                                                    $productNotFound = true;
                                                }
                                            }
                                        }
                                        $lines = [
                                            "DetailType" => "GroupLineDetail",
                                            "Description" => $val->notes ? $val->notes : $val->product_name,
                                            "Amount" => $line_total_amount,
                                            "GroupLineDetail" => [
                                                "GroupItemRef" => ["value" => $itemId],
                                                "Quantity" => $qty,

                                            ],


                                        ];
                                        if ($childProductLine) {
                                            //Assign only when we have child products other wise QB automatically handle the child products without price and qty
                                            $lines["GroupLineDetail"]["Line"] = $childProductLine;
                                        }
                                    } else {
                                        $lines = [
                                            "DetailType" => "SalesItemLineDetail",
                                            "Description" => $val->notes ? $val->notes : $val->product_name,
                                            "Amount" => $line_total_amount,
                                            "SalesItemLineDetail" => [
                                                "ItemRef" => ["value" => $itemId],
                                                "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                "Qty" => $qty,
                                                "UnitPrice" => $price
                                            ]
                                        ];
                                    }
                                    array_push(
                                        $items,
                                        $lines
                                    );
                                    $total_discount_amount = $total_discount_amount + $val->discount_amount;
                                } else {
                                    $productNotFound = true;
                                }
                            } else {
                                $productNotFound = true;
                            }
                        } else {
                            if (isset($lineItem[$destination_identity])) {
                                $product = PlatformProduct::select('id', 'api_product_id', 'bundle')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();
                                if (!$product) {
                                    $item = $this->findProductBySKUAndStore($lineItem[$destination_identity], $user_id, $user_integration_id, $account);

                                    $bundle = $item['type'];
                                    if ($bundle) {
                                        $isItemLineBundle = true;
                                    } else {
                                        $isItemLineBundle = false;
                                    }
                                    $itemId = $item['itemId'];
                                } else {
                                    if ($product->bundle) {
                                        $isItemLineBundle = true;
                                    } else {
                                        $isItemLineBundle = false;
                                    }
                                    $itemId = $product->api_product_id;
                                }

                                if ($itemId) {
                                    $qty = (int) $val->qty;
                                    $price = floatval($val->price);

                                    $line_total_amount = $qty * $price;
                                    if ($val->subtotal_tax > 0) {
                                        $taxCodeForLine = "TAX";
                                        $taxApply++;
                                        $taxTotal = $taxTotal + $val->subtotal_tax;
                                    } else {
                                        $taxCodeForLine = "NON";
                                    }
                                    if ($isItemLineBundle) {
                                        $lines = [
                                            "DetailType" => "GroupLineDetail",
                                            "Description" => $val->notes ? $val->notes : $val->product_name,
                                            "Amount" => $line_total_amount,
                                            "GroupLineDetail" => [
                                                "ItemRef" => ["value" => $itemId],
                                                "Quantity" => $qty,

                                            ]
                                        ];
                                    } else {
                                        $lines = [
                                            "DetailType" => "SalesItemLineDetail",
                                            "Description" => $val->notes ? $val->notes : $val->product_name,
                                            "Amount" => $line_total_amount,
                                            "SalesItemLineDetail" => [
                                                "ItemRef" => ["value" => $itemId],
                                                "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                "Qty" => $qty,
                                                "UnitPrice" => $price
                                            ]
                                        ];
                                    }
                                    array_push(
                                        $items,
                                        $lines
                                    );
                                    $total_discount_amount = $total_discount_amount + $val->discount_amount;
                                } else {
                                    $productNotFound = true;
                                }
                            } else {
                                $productNotFound = true;
                            }
                        }
                    } else {
                        $productNotFound = true;
                    }
                }
            }

            /* Order Discount + Linewise discount */
            if ($order->total_discount > 0 || $total_discount_amount > 0) {
                if ($discountAccountId) {
                    array_push(
                        $items,
                        [
                            "DetailType" => "DiscountLineDetail",
                            "Amount" => $order->total_discount + $total_discount_amount,
                            "DiscountLineDetail" => [
                                "PercentBased" => false,
                                "DiscountPercent" => 0,
                                "DiscountAccountRef" => ["value" => $discountAccountId]
                            ]
                        ]
                    );
                } else {
                    $discountError = "No discount account mapping found, please check mapping";
                }
            }

            /* Shipping Cost */
            if ($order->shipping_total > 0) {
                if ($shippingItemSKU) {
                    $itemId = $this->findServiceProductBySKU($shippingItemSKU, $user_id, $user_integration_id, $account);
                    if (isset($itemId['id']) && !empty($itemId['id'])) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "SalesItemLineDetail",
                                "Amount" => $order->shipping_total,
                                "SalesItemLineDetail" => [
                                    "ItemRef" => ["value" => $itemId['id']],
                                ]
                            ]
                        );
                    } else {
                        $shippingError = "No service type product found for shipping to sync this order,please check mapping";
                    }
                } else {
                    $shippingError = "No service type shipping product found, please check mapping";
                }
            }
        }

        return ['items' => $items, 'productNotFound' => $productNotFound, 'taxApply' => $taxApply, 'taxTotal' => $taxTotal, 'shippingError' => $shippingError, 'discountError' => $discountError];
    }
    public function prepareSOOrderLineTestBK($order, $user_id, $user_integration_id, $source_platform_id, $source_platform_name, $source_identity, $destination_identity, $discountAccountId = null, $shippingItemSKU = null, $account)
    {
        $items = [];
        $productNotFound = false;
        $discountError = $shippingError = null;
        $total_discount_amount = $taxApply = $taxTotal = 0;
        $orderLines = isset($order->platformOrderLine) ? $order->platformOrderLine : null;

        if ($orderLines) {
            $qty = 0;

            foreach ($orderLines as $key => $val) {
                if ($val->row_type == "ITEM") {
                    $lineItem = $val->toArray();
                    if (isset($lineItem[$source_identity])) {
                        if ($source_platform_name == "skubana") { //if source platform is skubana (Extensiv order manager) then must be check bundle's child product
                            $sourceItem = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, $source_identity => $lineItem[$source_identity], 'is_deleted' => 0])->first();
                            if ($sourceItem) {
                                if ($sourceItem->bundle) {
                                    $bundleItems = @$sourceItem->PlatformProductBundle;
                                    $totalBundleQty = @$sourceItem->PlatformProductBundle->sum('bundle_qty') ? @$sourceItem->PlatformProductBundle->sum('bundle_qty') : 0;
                                    $childPrice = !empty($val->subtotal) ? $val->subtotal / ($totalBundleQty * $val->qty) : 0;
                                    if ($val->subtotal_tax > 0) {
                                        $taxTotal = $val->subtotal_tax;
                                    }
                                    if ($bundleItems) {
                                        foreach ($bundleItems as $bundle) {

                                            $childProduct = @$bundle->PlatformProductChild ? @$bundle->PlatformProductChild->toArray() : [];
                                            if (isset($childProduct[$destination_identity])) {

                                                $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $childProduct[$destination_identity], 'is_deleted' => 0])->first();
                                                if (!$product) {
                                                    $itemId = $this->findProductBySKU($childProduct[$destination_identity], $user_id, $user_integration_id, $account);
                                                } else {
                                                    $itemId = $product->api_product_id;
                                                }

                                                if ($itemId) {
                                                    $qty = (int) $val->qty * $bundle->bundle_qty;
                                                    $line_total_amount = $qty * $childPrice;
                                                    if ($val->subtotal_tax > 0) {
                                                        $taxCodeForLine = "TAX";
                                                        $taxApply++;
                                                    } else {
                                                        $taxCodeForLine = "NON";
                                                    }
                                                    array_push(
                                                        $items,
                                                        [
                                                            "DetailType" => "SalesItemLineDetail",
                                                            "Description" => $val->notes ? $val->notes : $childProduct['product_name'],
                                                            "Amount" => $line_total_amount,
                                                            "SalesItemLineDetail" => [
                                                                "ItemRef" => ["value" => $itemId],
                                                                "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                                "Qty" => $qty,
                                                                "UnitPrice" => $childPrice
                                                            ]
                                                        ]
                                                    );
                                                    $total_discount_amount = $total_discount_amount + $val->discount_amount;
                                                } else {
                                                    $productNotFound = true;
                                                }
                                            } else {
                                                $productNotFound = true;
                                            }
                                        }
                                    }
                                } else {
                                    if (isset($lineItem[$destination_identity])) {
                                        $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();
                                        if (!$product) {
                                            $itemId = $this->findProductBySKU($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                                        } else {
                                            $itemId = $product->api_product_id;
                                        }

                                        if ($itemId) {
                                            $qty = (int) $val->qty;
                                            $price = floatval($val->price);

                                            $line_total_amount = $qty * $price;
                                            if ($val->subtotal_tax > 0) {
                                                $taxCodeForLine = "TAX";
                                                $taxApply++;
                                                $taxTotal = $taxTotal + $val->subtotal_tax;
                                            } else {
                                                $taxCodeForLine = "NON";
                                            }
                                            array_push(
                                                $items,
                                                [
                                                    "DetailType" => "SalesItemLineDetail",
                                                    "Description" => $val->notes ? $val->notes : $val->product_name,
                                                    "Amount" => $line_total_amount,
                                                    "SalesItemLineDetail" => [
                                                        "ItemRef" => ["value" => $itemId],
                                                        "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                        "Qty" => $qty,
                                                        "UnitPrice" => $price
                                                    ]
                                                ]
                                            );
                                            $total_discount_amount = $total_discount_amount + $val->discount_amount;
                                        } else {
                                            $productNotFound = true;
                                        }
                                    } else {
                                        $productNotFound = true;
                                    }
                                }
                            } else {
                                $productNotFound = true;
                            }
                        } else {
                            if (isset($lineItem[$destination_identity])) {
                                $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();
                                if (!$product) {
                                    $itemId = $this->findProductBySKU($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                                } else {
                                    $itemId = $product->api_product_id;
                                }

                                if ($itemId) {
                                    $qty = (int) $val->qty;
                                    $price = floatval($val->price);

                                    $line_total_amount = $qty * $price;
                                    if ($val->subtotal_tax > 0) {
                                        $taxCodeForLine = "TAX";
                                        $taxApply++;
                                        $taxTotal = $taxTotal + $val->subtotal_tax;
                                    } else {
                                        $taxCodeForLine = "NON";
                                    }
                                    array_push(
                                        $items,
                                        [
                                            "DetailType" => "SalesItemLineDetail",
                                            "Description" => $val->notes ? $val->notes : $val->product_name,
                                            "Amount" => $line_total_amount,
                                            "SalesItemLineDetail" => [
                                                "ItemRef" => ["value" => $itemId],
                                                "TaxCodeRef" => ["value" => $taxCodeForLine],
                                                "Qty" => $qty,
                                                "UnitPrice" => $price
                                            ]
                                        ]
                                    );
                                    $total_discount_amount = $total_discount_amount + $val->discount_amount;
                                } else {
                                    $productNotFound = true;
                                }
                            } else {
                                $productNotFound = true;
                            }
                        }
                    } else {
                        $productNotFound = true;
                    }
                }
            }

            /* Order Discount + Linewise discount */
            if ($order->total_discount > 0 || $total_discount_amount > 0) {
                if ($discountAccountId) {
                    array_push(
                        $items,
                        [
                            "DetailType" => "DiscountLineDetail",
                            "Amount" => $order->total_discount + $total_discount_amount,
                            "DiscountLineDetail" => [
                                "PercentBased" => false,
                                "DiscountPercent" => 0,
                                "DiscountAccountRef" => ["value" => $discountAccountId]
                            ]
                        ]
                    );
                } else {
                    $discountError = "No discount account mapping found, please check mapping";
                }
            }

            /* Shipping Cost */
            if ($order->shipping_total > 0) {
                if ($shippingItemSKU) {
                    $itemId = $this->findServiceProductBySKU($shippingItemSKU, $user_id, $user_integration_id, $account);
                    if (isset($itemId['id']) && !empty($itemId['id'])) {
                        array_push(
                            $items,
                            [
                                "DetailType" => "SalesItemLineDetail",
                                "Amount" => $order->shipping_total,
                                "SalesItemLineDetail" => [
                                    "ItemRef" => ["value" => $itemId['id']],
                                ]
                            ]
                        );
                    } else {
                        $shippingError = "No service type product found for shipping to sync this order,please check mapping";
                    }
                } else {
                    $shippingError = "No service type shipping product found, please check mapping";
                }
            }
        }

        return ['items' => $items, 'productNotFound' => $productNotFound, 'taxApply' => $taxApply, 'taxTotal' => $taxTotal, 'shippingError' => $shippingError, 'discountError' => $discountError];
    }
    /* Prepare Order SO Lines */
    public function prepareSOOrderLine($order, $user_id, $user_integration_id, $destination_platform_id, $source_identity, $discountAccountId = null, $account)
    {
        $items = [];
        $productNotFound = false;
        $total_discount_amount = $taxApply = $taxTotal = 0;
        $orderLines = isset($order->platformOrderLine) ? $order->platformOrderLine : null;

        if ($orderLines) {
            $qty = 0;

            foreach ($orderLines as $key => $val) {
                if ($val->row_type == "ITEM") {
                    $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $destination_platform_id, $source_identity => $val->sku, 'is_deleted' => 0])->first();
                    if (!$product) {
                        $itemId = $this->findProductBySKU($val->sku, $user_id, $user_integration_id, $account);
                    } else {
                        $itemId = $product->api_product_id;
                    }

                    if ($itemId) {
                        $qty = (int) $val->qty;
                        $price = floatval($val->price);

                        $line_total_amount = $qty * $price;
                        if ($val->subtotal_tax > 0) {
                            $taxCodeForLine = "TAX";
                            $taxApply++;
                            $taxTotal = $taxTotal + $val->subtotal_tax;
                        } else {
                            $taxCodeForLine = "NON";
                        }
                        array_push(
                            $items,
                            [
                                "DetailType" => "SalesItemLineDetail",
                                "Description" => $val->notes ? $val->notes : $val->product_name,
                                "Amount" => $line_total_amount,
                                "SalesItemLineDetail" => [
                                    "ItemRef" => ["value" => $itemId],
                                    "TaxCodeRef" => ["value" => $taxCodeForLine],
                                    "Qty" => $qty,
                                    "UnitPrice" => $price
                                ]
                            ]
                        );
                        $total_discount_amount = $total_discount_amount + $val->discount_amount;
                    } else {
                        $productNotFound = true;
                    }
                }
            }

            /* Order Discount + Linewise discount */
            if ($order->total_discount > 0 || $total_discount_amount > 0) {
                if ($discountAccountId) {
                    array_push(
                        $items,
                        [
                            "DetailType" => "DiscountLineDetail",
                            "Amount" => $order->total_discount + $total_discount_amount,
                            "DiscountLineDetail" => [
                                "PercentBased" => false,
                                "DiscountPercent" => 0,
                                "DiscountAccountRef" => ["value" => $discountAccountId]
                            ]
                        ]
                    );
                }
            }

            /* Shipping Cost */
            if ($order->shipping_total > 0) {
                array_push(
                    $items,
                    [
                        "DetailType" => "SalesItemLineDetail",
                        "Amount" => $order->shipping_total,
                        "SalesItemLineDetail" => [
                            "ItemRef" => ["value" => "SHIPPING_ITEM_ID"],
                        ]
                    ]
                );
            }
        }

        return ['items' => $items, 'productNotFound' => $productNotFound, 'taxApply' => $taxApply, 'taxTotal' => $taxTotal];
    }

    /* search Terms By Name */
    public function searchTerms($name, $user_id, $user_integration_id, $account)
    {
        $return = null;
        try {
            $objectId = $this->helper->getObjectId('pay_terms');
            $find = PlatformObjectData::where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'platform_object_id' => $objectId,
                'name' => $name,
                'status' => 1
            ])->first();

            if ($find) {
                $return = $find->api_id;
            } else {
                $arguments = ["query" => "select * from Term Where Name='{$name}' startPosition 1 maxResults 1"];
                $apicall = $this->APICALL($account, "GET", "query", $arguments);
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $terms = isset($apicall['body']['QueryResponse']['Term']) ? $apicall['body']['QueryResponse']['Term'] : [];
                    if (count($terms) > 0) {
                        foreach ($terms as $key => $value) {
                            $this->prepareTermData($value, $objectId, $user_id, $user_integration_id, 0);
                            $return = $value['Id'];
                        }
                    } else {
                        if ($name) {
                            $payload = ['Name' => $name, 'DueDays' => 0];
                            $apicall = $this->APICALL($account, "POST", "term", [], $payload);
                            if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                $term = isset($apicall['body']['Term']) ? $apicall['body']['Term'] : [];
                                if (isset($term['Id'])) {
                                    $this->prepareTermData($term, $objectId, $user_id, $user_integration_id, 0);
                                    $return = $term['Id'];
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error(' -> QuickBooksServiceController -> searchTerms -> ' . $name . " -> " . $e->getMessage());
            $return = $e->getMessage();
        }
        return $return;
    }

    /* ---Insert Order Details--- */
    public function saveOrderDetails($payload)
    {
        $orderID = false;
        if (!empty($payload)) {
            DB::beginTransaction();
            try {
                $order = new PlatformOrder();
                $order->user_id = $payload['user_id'];
                $order->platform_id = $payload['platform_id'];
                $order->user_integration_id = $payload['user_integration_id'];
                $order->order_type = $payload['order_type'];
                $order->api_order_id = $payload['api_order_id'];
                $order->order_date = $payload['order_date'];
                $order->order_number = $payload['order_number'];
                $order->sync_status = $payload['sync_status'];
                $order->linked_id = $payload['linked_id'];
                $order->shipment_status = $payload['shipment_status'];
                $order->order_updated_at = $payload['order_updated_at'];
                if ($order->save()) {
                    $orderID = $order->id;
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error($payload['user_integration_id'] . ' -> QuickBooksServiceController -> saveOrderDetails -> ' . $payload['api_order_id'] . " -> " . $e->getMessage());
            }
        }

        return $orderID;
    }

    /* Prepare Vendor Data */
    public function prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $vendorPrimaryID = NULL;
        $email = isset($vendor['PrimaryEmailAddr']) ? $vendor['PrimaryEmailAddr']['Address'] : null;
        $name = @$vendor['DisplayName'];
        if (!$name) {
            $name = @$vendor['GivenName'];
        }

        $vendorCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_customer_id' => isset($vendor['Id']) ? $vendor['Id'] : null,
            'api_customer_code' => isset($vendor['SyncToken']) ? $vendor['SyncToken'] : null,
            'company_name' => @$vendor['CompanyName'],
            'phone' => @$vendor['PrimaryPhone']['FreeFormNumber'],
            'first_name' => $name,
            'name_name' => @$vendor['FamilyName'],
            'email' => $email,
            'customer_name' => $name,
            'is_deleted' => isset($vendor['Active']) ? 0 : $vendor['Active'],
            'api_updated_at' => isset($vendor['MetaData']['LastUpdatedTime']) ? $vendor['MetaData']['LastUpdatedTime'] : null,
            'api_created_at' => isset($vendor['MetaData']['CreateTime']) ? $vendor['MetaData']['CreateTime'] : null,
            'address1' => isset($vendor['BillAddr']['Line1']) ? $vendor['BillAddr']['Line1'] : null,
            'address2' => isset($vendor['BillAddr']['City']) ? $vendor['BillAddr']['City'] : null,
            'address3' => isset($vendor['BillAddr']['CountrySubDivisionCode']) ? $vendor['BillAddr']['CountrySubDivisionCode'] : null,
            'country' => isset($vendor['BillAddr']['Country']) ? $vendor['BillAddr']['Country'] : null,
            'postal_addresses' => isset($vendor['BillAddr']['PostalCode']) ? $vendor['BillAddr']['PostalCode'] : null,
            'type' => 'Vendor'
        ];

        if ($vendor['Active']) {
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
        } else {
            //When is_initial_sync=0
            $findVendor = PlatformCustomer::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_customer_id' => $vendor['Id'], 'type' => 'Vendor'])->first();

            if ($findVendor) {
                $vendorPrimaryID = $findVendor->id;
                if ($findVendor->api_updated_at != isset($vendor['MetaData']['LastUpdatedTime']) ? $vendor['MetaData']['LastUpdatedTime'] : null) {
                    $findVendor->first_name = $name;
                    $findVendor->customer_name = $name;
                    $findVendor->company_name = @$vendor['CompanyName'];
                    $findVendor->api_customer_code = isset($vendor['SyncToken']) ? $vendor['SyncToken'] : null;
                    $findVendor->phone = @$vendor['PrimaryPhone']['FreeFormNumber'];
                    $findVendor->email = $email;
                    $findVendor->is_deleted = isset($vendor['Active']) ? 0 : $vendor['Active'];
                    $findVendor->api_updated_at = isset($vendor['MetaData']['LastUpdatedTime']) ? $vendor['MetaData']['LastUpdatedTime'] : null;
                    $findVendor->address1 = isset($vendor['BillAddr']['Line1']) ? $vendor['BillAddr']['Line1'] : null;
                    $findVendor->address2 = isset($vendor['BillAddr']['City']) ? $vendor['BillAddr']['City'] : null;
                    $findVendor->address3 = isset($vendor['BillAddr']['CountrySubDivisionCode']) ? $vendor['BillAddr']['CountrySubDivisionCode'] : null;
                    $findVendor->country = isset($vendor['BillAddr']['Country']) ? $vendor['BillAddr']['Country'] : null;
                    $findVendor->postal_addresses = isset($vendor['BillAddr']['PostalCode']) ? $vendor['BillAddr']['PostalCode'] : null;
                    if ($vendor['Active']) {
                        $findVendor->sync_status = "Ready";
                    } else {
                        $findVendor->sync_status = "Inactive";
                    }
                }

                if (isset($vendor['linked_id'])) {
                    $findVendor->linked_id = $vendor['linked_id'];
                }

                $findVendor->save();
            } else {
                $findVendor = PlatformCustomer::create($vendorCreate);
                $vendorPrimaryID = isset($findVendor->id) ? $findVendor->id : null;
            }
        }

        return $vendorPrimaryID;
    }

    /* find Vendor By Id */
    public function findVendorByID($apiVendorID, $user_id, $user_integration_id, $account)
    {
        try {
            $vendorId = $vendorPrimaryId = $syncToken = null;
            if ($apiVendorID && $account) {
                $apicall = $this->APICALL($account, "GET", "vendor/{$apiVendorID}");
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $vendorData = $apicall['body']['Vendor'];
                    $vendorPrimaryId = $this->prepareVendorData($vendorData, $user_id, $user_integration_id, 0);
                    $vendorId = $vendorData['Id'];
                    $syncToken = $vendorData['SyncToken'];
                } else {
                    $error = $this->handleResponseError($apicall);
                    $vendorId = !empty($error) ? $error : "API Error";
                }
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksServiceController - findVendorByID - ' . $e->getLine() . " -> " . $e->getMessage());
            $vendorId = $e->getMessage();
        }
        return ['vendorId' => $vendorId, 'vendorPrimaryId' => $vendorPrimaryId, 'syncToken' => $syncToken];
    }

    /* find Customer By Id */
    public function findCustomerByID($apiCustomerID, $user_id, $user_integration_id, $account)
    {
        try {
            $customerId = $customerPrimaryId = $syncToken = null;
            if ($apiCustomerID && $account) {
                $apicall = $this->APICALL($account, "GET", "customer/{$apiCustomerID}");
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $customerData = $apicall['body']['Customer'];
                    $customerPrimaryId = $this->prepareCustomerData($customerData, $user_id, $user_integration_id, 0);
                    $customerId = $customerData['Id'];
                    $syncToken = $customerData['SyncToken'];
                } else {
                    $error = $this->handleResponseError($apicall);
                    $customerId = !empty($error) ? $error : "API Error";
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksServiceController -> findCustomerByID -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $customerId = $e->getMessage();
        }
        return ['customerId' => $customerId, 'customerPrimaryId' => $customerPrimaryId, 'syncToken' => $syncToken];
    }

    /* find Search Vendor/update/create and store */
    public function searchVendorOrCreateOrUpdateAndStore($vendor = null, $payload = [], $type = "search", $account = null)
    {
        $vendorId = $vendorPrimaryId = $syncToken = null;
        try {
            if ($type == "search") {
                if (isset($vendor->customer_name)) {
                    $findDestinationVendor = PlatformCustomer::select('id', 'user_id', 'user_integration_id', 'api_customer_id', 'api_customer_code', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'fax', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'sync_status', 'type', 'linked_id', 'is_deleted')->where(['platform_id' => $this->platformId, 'user_integration_id' => $vendor->user_integration_id, 'customer_name' => $vendor->customer_name, 'type' => "Vendor", 'is_deleted' => 0])->first();
                    if ($findDestinationVendor) {
                        $vendorId = $findDestinationVendor->api_customer_id;
                        $vendorPrimaryId = $findDestinationVendor->id;
                        $vendorData = $this->findVendorByID($vendorId, $vendor->user_id, $vendor->user_integration_id, $account);
                        $syncToken = !empty($vendorData['syncToken']) ? $vendorData['syncToken'] : $findDestinationVendor->api_customer_code;
                        $findDestinationVendor->linked_id = $vendor->id;
                        $findDestinationVendor->save();
                    } else {
                        $page = 1;
                        $pageLimit = 1;
                        $vendorName = $vendor->customer_name;
                        $arguments = [
                            "query" => "select * from Vendor Where DisplayName='{$vendorName}' startPosition {$page} maxResults {$pageLimit}",
                        ];
                        $apicall = $this->APICALL($account, "GET", "query", $arguments);
                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $vendorData = isset($apicall['body']['QueryResponse']['Vendor'][0]) ? $apicall['body']['QueryResponse']['Vendor'][0] : [];
                            if ($vendorData) {
                                $vendorData['type'] = 'Vendor';
                                $vendorData['linked_id'] = $vendor->id;
                                $vendorPrimaryId = $this->prepareVendorData($vendorData, $vendor->user_id, $vendor->user_integration_id, 0);
                                $vendorId = $vendorData['Id'];
                                $syncToken = $vendorData['SyncToken'];
                            } else {
                                $vendorId = "No vendor found";
                            }
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $vendorId = !empty($error) ? $error : "API Error";
                        }
                    }
                } else {
                    $vendorId = "Vendor name is not available to create a vendor in QuickBooks";
                }
            } else if ($type == "create") {
                if ($payload) {
                    $apicall = $this->APICALL($account, "POST", "vendor", [], $payload);
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $vendorData = $apicall['body']['Vendor'];
                        $vendorData['linked_id'] = $vendor->id;
                        $vendorPrimaryId = $this->prepareVendorData($vendorData, $vendor->user_id, $vendor->user_integration_id, 0);
                        $vendorId = $vendorData['Id'];
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $vendorId = !empty($error) ? $error : "API Error";
                    }
                } else {
                    $vendorId = "Vendor to create payload data is invalid or empty in QuickBooks";
                }
            } else if ($type == "update") {
                if ($payload) {
                    $apicall = $this->APICALL($account, "POST", "vendor", [], $payload);
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $vendorData = $apicall['body']['Vendor'];
                        $vendorData['linked_id'] = $vendor->id;
                        $vendorPrimaryId = $this->prepareVendorData($vendorData, $vendor->user_id, $vendor->user_integration_id, 0);
                        $vendorId = $vendorData['Id'];
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $vendorId = !empty($error) ? $error : "API Error";
                    }
                } else {
                    $vendorId = "Vendor to update payload data is invalid or empty in QuickBooks";
                }
            }
        } catch (Exception $e) {
            \Log::error('QuickBooksServiceController - searchVendorOrCreateOrUpdateAndStore - ' . $e->getLine() . " -> " . $e->getMessage());
            $vendorId = $e->getMessage();
        }
        return ['vendorId' => $vendorId, 'vendorPrimaryId' => $vendorPrimaryId, 'syncToken' => $syncToken];
    }

    /* find Search Customer/update/create and store */
    public function searchCustomerOrCreateOrUpdateAndStore($customer = null, $payload = [], $type = "search", $account = null)
    {
        $customerId = $customerPrimaryId = $syncToken = null;
        try {
            if ($type == "search") {
                if (isset($customer->customer_name)) {
                    $findDestinationCustomer = PlatformCustomer::select('id', 'api_customer_id')->where(['platform_id' => $this->platformId, 'user_integration_id' => $customer->user_integration_id, 'customer_name' => $customer->customer_name, 'type' => "Customer", 'is_deleted' => 0])->first();
                    if ($findDestinationCustomer) {
                        $customerId = $findDestinationCustomer->api_customer_id;
                        $customerPrimaryId = $findDestinationCustomer->id;

                        $vendorData = $this->findVendorByID($customerId, $customer->user_id, $customer->user_integration_id, $account);
                        $syncToken = !empty($vendorData['syncToken']) ? $vendorData['syncToken'] : $findDestinationCustomer->api_customer_code;
                        $findDestinationCustomer->linked_id = $customer->id;
                        $findDestinationCustomer->save();
                    } else {
                        $page = 1;
                        $pageLimit = 1;
                        $customerName = $customer->customer_name;
                        $arguments = ["query" => "select * from Customer Where DisplayName='{$customerName}' startPosition {$page} maxResults {$pageLimit}"];
                        $apicall = $this->APICALL($account, "GET", "query", $arguments);
                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $customerData = isset($apicall['body']['QueryResponse']['Customer'][0]) ? $apicall['body']['QueryResponse']['Customer'][0] : [];
                            if ($customerData) {
                                $customerData['linked_id'] = $customer->id;
                                $customerPrimaryId = $this->prepareCustomerData($customerData, $customer->user_id, $customer->user_integration_id, 0);
                                $customerId = $customerData['Id'];
                                $syncToken = $customerData['SyncToken'];
                            } else {
                                $customerId = "No Customer found";
                            }
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $customerId = !empty($error) ? $error : "API Error";
                        }
                    }
                } else {
                    $customerId = "Customer name is not available to create a customer in QuickBooks";
                }
            } else if ($type == "create") {
                if ($payload) {
                    $apicall = $this->APICALL($account, "POST", "customer", [], $payload);
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $customerData = $apicall['body']['Customer'];
                        $customerData['linked_id'] = $customer->id;
                        $customerPrimaryId = $this->prepareCustomerData($customerData, $customer->user_id, $customer->user_integration_id, 0);
                        $customerId = $customerData['Id'];
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $customerId = !empty($error) ? $error : "API Error";
                    }
                } else {
                    $customerId = "Customer to create payload data is invalid or empty in QuickBooks";
                }
            } else if ($type == "update") {
                if ($payload) {
                    $apicall = $this->APICALL($account, "POST", "customer", [], $payload);
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $customerData = $apicall['body']['Customer'];
                        $customerData['linked_id'] = $customer->id;
                        $customerPrimaryId = $this->prepareCustomerData($customerData, $customer->user_id, $customer->user_integration_id, 0);
                        $customerId = $customerData['Id'];
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $customerId = !empty($error) ? $error : "API Error";
                    }
                } else {
                    $customerId = "Customer to update payload data is invalid or empty in QuickBooks";
                }
            }
        } catch (Exception $e) {
            \Log::error('QuickBooksServiceController - searchCustomerOrCreateOrUpdateAndStore - ' . $e->getLine() . " -> " . $e->getMessage());
            $customerId = $e->getMessage();
        }
        return ['customerId' => $customerId, 'customerPrimaryId' => $customerPrimaryId, 'syncToken' => $syncToken];
    }

    /* find vendor details */
    public function findVendor($vendorPrimaryID, $account)
    {
        $vendorAddress = [];
        $vendorId = $email = null;
        $return_response = ['vendorId' => $vendorId, 'email' => $email, 'vendorAddress' => $vendorAddress];
        try {
            $findSourceVendor = PlatformCustomer::select('id', 'user_id', 'platform_id', 'user_integration_id', 'api_customer_id', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'type', 'linked_id', 'is_deleted', 'sync_status')->where('id', $vendorPrimaryID)->first();
            if ($findSourceVendor) {

                if ($findSourceVendor->customer_name) {
                    $findDestinationVendor = PlatformCustomer::select('id', 'user_id', 'platform_id', 'user_integration_id', 'api_customer_id', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'type', 'linked_id', 'is_deleted', 'sync_status')->where(['platform_id' => $this->platformId, 'user_integration_id' => $findSourceVendor->user_integration_id, 'customer_name' => $findSourceVendor->customer_name, 'type' => "Vendor", 'is_deleted' => 0])->first();
                    if ($findDestinationVendor) {

                        $vendorId = $findDestinationVendor->api_customer_id;
                        $vendorAddress = [
                            "City" => $findDestinationVendor->address2,
                            "Country" => $findDestinationVendor->country,
                            "Line1" => $findDestinationVendor->address1,
                            "PostalCode" => $findDestinationVendor->postal_addresses,
                            "CountrySubDivisionCode" => $findDestinationVendor->address3,
                        ];
                        $return_response = ['vendorId' => $vendorId, 'email' => $findSourceVendor->email, 'vendorAddress' => $vendorAddress];
                    } else {

                        $vendorName = $findSourceVendor->customer_name;
                        $arguments = [
                            "query" => "select * from Vendor Where DisplayName='{$vendorName}' startPosition 1 maxResults 1",
                        ];
                        $apicall = $this->APICALL($account, "GET", "query", $arguments);

                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $vendorData = isset($apicall['body']['QueryResponse']['Vendor'][0]) ? $apicall['body']['QueryResponse']['Vendor'][0] : [];
                            if ($vendorData) {
                                $vendorData['type'] = 'Vendor';
                                $this->prepareVendorData($vendorData, $findSourceVendor->user_id, $findSourceVendor->user_integration_id, 0);
                                $vendorId = $vendorData['Id'];
                            } else {
                                $vendorId = "No vendor found";
                            }
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $vendorId = !empty($error) ? $error : "API Error";
                        }
                        /* prepare return values */
                        $mail = $findSourceVendor->email;
                        $vendorAddress = [
                            "City" => $findSourceVendor->address2,
                            "Country" => $findSourceVendor->country,
                            "Line1" => $findSourceVendor->address1,
                            "PostalCode" => $findSourceVendor->postal_addresses,
                            "CountrySubDivisionCode" => $findSourceVendor->address3,
                        ];
                        if (!is_numeric($vendorId)) { //if vendor not found by display name
                            $payload = [

                                "PrimaryEmailAddr" => [
                                    "Address" => $mail
                                ],
                                "PrimaryPhone" => [
                                    "FreeFormNumber" => $findSourceVendor->phone
                                ],
                                "CompanyName" => $findSourceVendor->company_name,
                                "BillAddr" => $vendorAddress,
                                "GivenName" => $findSourceVendor->customer_name,


                            ];
                            $apicall = $this->APICALL($account, "POST", "vendor", [], $payload);
                            if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                $vendor = $apicall['body']['Vendor'];
                                $vendor['linked_id'] = $vendorPrimaryID;
                                $vendor['type'] = 'Vendor';
                                $QbVendorPrimaryId = $this->prepareVendorData($vendor, $findSourceVendor->user_id, $findSourceVendor->user_integration_id, 0);
                                $vendorId = $vendor['Id'];
                                /* Linked Vendor Ids in both side */
                                $findSourceVendor->linked_id = $QbVendorPrimaryId;
                                $findSourceVendor->sync_status = "Synced";
                                $findSourceVendor->save();
                                // $vendor_object_id = $this->helper->getObjectId('vendor');
                                // $this->log->syncLog($findSourceVendor->user_id, $findSourceVendor->user_integration_id, $user_workflow_rule_id, $findSourceVendor->platform_id, $this->platformId, $vendor_object_id, 'success', $vendorPrimaryID, null);
                            } else {
                                $error = $this->handleResponseError($apicall);
                                $vendorId = !empty($error) ? $error : "API Error";
                            }
                        }

                        $return_response = ['vendorId' => $vendorId, 'email' => $mail, 'vendorAddress' => $vendorAddress];
                    }
                } else {

                    $vendorId = "Vendor name is not available to create a vendor in QuickBooks";
                    $return_response = ['vendorId' => $vendorId, 'email' => $email, 'vendorAddress' => $vendorAddress];
                }
            } else {
                $vendorId = "No vendor detail found from source platform";
                $return_response = ['vendorId' => $vendorId, 'email' => $email, 'vendorAddress' => $vendorAddress];
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksServiceController - findVendor - ' . $e->getLine() . " -> " . $e->getMessage());

            $return_response = ['vendorId' => $e->getMessage(), 'email' => $email, 'vendorAddress' => $vendorAddress];
        }
        return $return_response;
    }

    /* find payment method */
    public function findPaymentMethodAndSave($order, $account, $object_id = null)
    {
        $return = null;
        if (isset($order->order_transaction)) {
            if (!$object_id) {
                $object_id = $this->helper->getObjectId('payment');
            }
            $paymentMethod = PlatformObjectData::select('id', 'api_id', 'name')->where(['user_integration_id' => $order->user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'name' => $order->order_transaction->transaction_method])->first();
            if ($paymentMethod) {
                $return = $paymentMethod->api_id;
            } else {
                $arguments = [
                    "query" => "select * from PaymentMethod Where Name='{$order->order_transaction->transaction_method}' startPosition 1 maxResults 1",
                ];
                $apicall = $this->APICALL($account, "GET", "query", $arguments);
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $method = isset($apicall['body']['QueryResponse']['PaymentMethod'][0]) ? $apicall['body']['QueryResponse']['PaymentMethod'][0] : [];
                    if ($method) {
                        PlatformObjectData::create([
                            'user_id' => $order->user_id,
                            'platform_id' => $this->platformId,
                            'api_id' => @$method['Id'],
                            'name' => @$method['Name'],
                            'user_integration_id' => $order->user_integration_id,
                            'platform_object_id' => $object_id,
                        ]);
                        $return = @$method['Id'];
                    }
                } else {
                    $error = $this->handleResponseError($apicall);
                    $return = !empty($error) ? $error : "API Error";
                }
            }
        }
        return $return;
    }

    /* find customer and create */
    public function findCustomer($customerPrimaryID, $account)
    {
        $customerAddress = [];
        $customerId = $email = null;
        $return_response = ['customerId' => $customerId, 'email' => $email, 'customerAddress' => $customerAddress];
        try {
            $findSourceCustomer = PlatformCustomer::select('id', 'user_id', 'platform_id', 'user_integration_id', 'api_customer_id', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'type', 'linked_id', 'is_deleted', 'sync_status')->where('id', $customerPrimaryID)->first();
            if ($findSourceCustomer) {
                if ($findSourceCustomer->customer_name) {
                    $findDestinationCustomer = PlatformCustomer::select('id', 'user_id', 'platform_id', 'user_integration_id', 'api_customer_id', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'type', 'linked_id', 'is_deleted', 'sync_status')
                        ->where(['user_integration_id' => $findSourceCustomer->user_integration_id, 'platform_id' => $this->platformId, 'customer_name' => $findSourceCustomer->customer_name, 'type' => "Customer", 'is_deleted' => 0])->first();
                    if ($findDestinationCustomer) {
                        $customerId = $findDestinationCustomer->api_customer_id;
                        $customerAddress = [
                            "City" => $findDestinationCustomer->address2,
                            "Country" => $findDestinationCustomer->country,
                            "Line1" => $findDestinationCustomer->address1,
                            "PostalCode" => $findDestinationCustomer->postal_addresses,
                            "CountrySubDivisionCode" => $findDestinationCustomer->address3,
                        ];
                        $return_response = ['customerId' => $customerId, 'email' => $findSourceCustomer->email, 'customerAddress' => $customerAddress];
                    } else {
                        $customerName = $findSourceCustomer->customer_name;
                        $arguments = ["query" => "select * from Customer Where DisplayName='{$customerName}' startPosition 1 maxResults 1"];
                        $apicall = $this->APICALL($account, "GET", "query", $arguments);
                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $customerData = isset($apicall['body']['QueryResponse']['Customer'][0]) ? $apicall['body']['QueryResponse']['Customer'][0] : [];
                            if ($customerData) {
                                $this->prepareCustomerData($customerData, $findSourceCustomer->user_id, $findSourceCustomer->user_integration_id, 0);
                                $customerId = $customerData['Id'];
                            } else {
                                $customerId = "No customer found";
                            }
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $customerId = !empty($error) ? $error : "API Error";
                        }
                        /* prepare return values */
                        $email = $findSourceCustomer->email;
                        $customerAddress = [
                            "City" => $findSourceCustomer->address2,
                            "Country" => $findSourceCustomer->country,
                            "Line1" => $findSourceCustomer->address1,
                            "PostalCode" => $findSourceCustomer->postal_addresses,
                            "CountrySubDivisionCode" => $findSourceCustomer->address3,
                        ];
                        if (!is_numeric($customerId)) { //if customer not found by display name
                            $payload = [
                                "PrimaryEmailAddr" => ["Address" => $email],
                                "PrimaryPhone" => ["FreeFormNumber" => $findSourceCustomer->phone],
                                "CompanyName" => $findSourceCustomer->company_name,
                                "BillAddr" => $customerAddress,
                                "GivenName" => $findSourceCustomer->customer_name
                            ];

                            $result = $this->APICALL($account, "POST", "customer", [], $payload);
                            if (isset($result['status_code']) && $result['status_code'] == 200) {
                                $customerData = $result['body']['Customer'];
                                $this->prepareCustomerData($customerData, $findSourceCustomer->user_id, $findSourceCustomer->user_integration_id, 0);
                                $customerId = $customerData['Id'];
                            } else {
                                $error = $this->handleResponseError($result);
                                $customerId = !empty($error) ? $error : "API Error";
                            }
                        }

                        $return_response = ['customerId' => $customerId, 'email' => $email, 'customerAddress' => $customerAddress];
                    }
                } else {
                    $customerId = "Customer name is not available to create a customer in QuickBooks";
                    $return_response = ['customerId' => $customerId, 'email' => $email, 'customerAddress' => $customerAddress];
                }
            } else {
                $customerId = "No customer detail found from source platform";
                $return_response = ['customerId' => $customerId, 'email' => $email, 'customerAddress' => $customerAddress];
            }
        } catch (\Exception $e) {
            \Log::error($customerPrimaryID . '-> QuickBooksServiceController -> findCustomer -> ' . $e->getLine() . ' -> ' . $e->getMessage());

            $return_response = ['customerId' => $e->getMessage(), 'email' => $email, 'customerAddress' => $customerAddress];
        }
        return $return_response;
    }

    /* find shipping address by warehouse id */
    public function findShippingAddressByWarehouseID($warehousePrimaryID)
    {
        $return_response = [];
        try {
            $find = PlatformObjectData::with('getPlatformObjectExtraInformation')->find($warehousePrimaryID);
            if (isset($find->getPlatformObjectExtraInformation)) {
                $return_response = [
                    "City" => $find->getPlatformObjectExtraInformation->city,
                    "Country" => $find->getPlatformObjectExtraInformation->country,
                    "Line1" => $find->getPlatformObjectExtraInformation->address1,
                    "PostalCode" => $find->getPlatformObjectExtraInformation->postal_code,
                    "CountrySubDivisionCode" => $find->getPlatformObjectExtraInformation->state,
                ];
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksServiceController - findShippingAddressByWarehouseID - ' . $e->getLine() . " -> " . $e->getMessage());

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Ser status=0 for platform_object_data table */
    public function setStatus($user_id, $user_integration_id, $platform_id, $object_id, $parent_id = NULL)
    {
        $condition = [
            'user_integration_id' => $user_integration_id,
            'platform_id' => $platform_id,
            'platform_object_id' => $object_id,
        ];

        if ($parent_id) {
            $condition['parent_id'] = $parent_id;
        }

        PlatformObjectData::where($condition)->update(['status' => 0]);
    }

    /* Prepare Order Lines */
    public function prepareBillLine($lines, $link_order_line_detail, $link_order_id, $user_id, $user_integration_id, $source_platform_id, $destination_platform_id, $source_identity, $destination_identity, $account)
    {
        $items = $memo = [];
        $productNotFound = false;
        $total_amount = 0;


        if ($lines) {
            $qty = 0;

            foreach ($lines as $key => $val) {
                if ($val->user_batch_reference) {
                    $memo[] = $val->user_batch_reference;
                }


                $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $destination_platform_id, $source_identity => $val->sku, 'is_deleted' => 0])->first();


                if (!$product) {
                    $itemId = $this->findProductBySKU($val->sku, $user_id, $user_integration_id, $account);
                } else {
                    $itemId = $product->api_product_id;
                }

                if ($itemId) {

                    $qty = (int) $val->quantity;

                    $subtotal = floatval($val->price);
                    $unit_price = floatval($val->price) / $qty;

                    $new_item = [
                        "DetailType" => "ItemBasedExpenseLineDetail",
                        "Amount" => $subtotal,
                        "ItemBasedExpenseLineDetail" => [
                            "ItemRef" => [
                                "value" => $itemId
                            ],

                            "Qty" => $qty,
                            "UnitPrice" => $unit_price
                        ],

                    ];

                    if (isset($link_order_line_detail[$itemId])) {
                        $new_item["LinkedTxn"][] = ["TxnId" => $link_order_id, "TxnType" => "PurchaseOrder", "TxnLineId" => $link_order_line_detail[$itemId]];
                    }


                    array_push(
                        $items,
                        $new_item

                    );
                    $total_amount = $total_amount + $subtotal;
                } else {
                    $productNotFound = true;
                }
            }
        }

        $memo = array_unique($memo);

        return ['items' => $items, 'total_amount' => $total_amount, 'productNotFound' => $productNotFound, 'memo' => implode(', ', $memo)];
    }
    public function prepareBillLineTest($lines, $link_order_line_detail, $link_order_id, $user_id, $user_integration_id, $source_platform_name, $source_platform_id, $source_identity, $destination_identity, $account)
    {
        $items = $memo = [];
        $productNotFound = false;
        $total_amount = 0;


        if ($lines) {
            $qty = 0;

            foreach ($lines as $key => $val) {

                if ($val->user_batch_reference) {
                    $memo[] = $val->user_batch_reference;
                }
                $lineItem = $val->toArray();

                if (isset($lineItem[$source_identity])) {

                    if ($source_platform_name == "skubana") {
                        $sourceItem = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, $source_identity => $lineItem[$source_identity], 'is_deleted' => 0])->first();
                        if ($sourceItem) {
                            if ($sourceItem->bundle) {
                                $bundleItems = @$sourceItem->PlatformProductBundle;
                                $totalBundleQty = @$sourceItem->PlatformProductBundle->sum('bundle_qty') ? @$sourceItem->PlatformProductBundle->sum('bundle_qty') : 0;
                                $childPrice = !empty($val->price) ? $val->price / ($totalBundleQty * $val->quantity) : 0;


                                if ($bundleItems) {
                                    foreach ($bundleItems as $bundle) {

                                        $childProduct = @$bundle->PlatformProductChild ? @$bundle->PlatformProductChild->toArray() : [];
                                        if (isset($childProduct[$destination_identity])) {

                                            $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $childProduct[$destination_identity], 'is_deleted' => 0])->first();
                                            if (!$product) {
                                                $itemId = $this->findProductBySKU($childProduct[$destination_identity], $user_id, $user_integration_id, $account);
                                            } else {
                                                $itemId = $product->api_product_id;
                                            }

                                            if ($itemId) {
                                                $qty = (int) $val->quantity * $bundle->bundle_qty;
                                                $line_total_amount = $qty * $childPrice;

                                                $new_item = [
                                                    "DetailType" => "ItemBasedExpenseLineDetail",
                                                    "Amount" => $line_total_amount,
                                                    "ItemBasedExpenseLineDetail" => [
                                                        "ItemRef" => [
                                                            "value" => $itemId
                                                        ],

                                                        "Qty" => $qty,
                                                        "UnitPrice" => $childPrice
                                                    ],

                                                ];
                                                if (isset($link_order_line_detail[$itemId])) {
                                                    $new_item["LinkedTxn"][] = ["TxnId" => $link_order_id, "TxnType" => "PurchaseOrder", "TxnLineId" => $link_order_line_detail[$itemId]];
                                                }
                                                array_push(
                                                    $items,
                                                    $new_item
                                                );
                                                $total_amount = $total_amount + $line_total_amount;
                                            } else {
                                                $productNotFound = true;
                                            }
                                        } else {
                                            $productNotFound = true;
                                        }
                                    }
                                }
                            } else {
                                if (isset($lineItem[$destination_identity])) {
                                    $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();

                                    if (!$product) {
                                        $itemId = $this->findProductBySKU($val->sku, $user_id, $user_integration_id, $account);
                                    } else {
                                        $itemId = $product->api_product_id;
                                    }

                                    if ($itemId) {

                                        $qty = (int) $val->quantity;

                                        $subtotal = floatval($val->price);
                                        $unit_price = floatval($val->price) / $qty;

                                        $new_item = [
                                            "DetailType" => "ItemBasedExpenseLineDetail",
                                            "Amount" => $subtotal,
                                            "ItemBasedExpenseLineDetail" => [
                                                "ItemRef" => [
                                                    "value" => $itemId
                                                ],

                                                "Qty" => $qty,
                                                "UnitPrice" => $unit_price
                                            ],

                                        ];

                                        if (isset($link_order_line_detail[$itemId])) {
                                            $new_item["LinkedTxn"][] = ["TxnId" => $link_order_id, "TxnType" => "PurchaseOrder", "TxnLineId" => $link_order_line_detail[$itemId]];
                                        }


                                        array_push(
                                            $items,
                                            $new_item

                                        );
                                        $total_amount = $total_amount + $subtotal;
                                    } else {
                                        $productNotFound = true;
                                    }
                                }
                            }
                        } else {

                            $product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_identity => $lineItem[$destination_identity], 'is_deleted' => 0])->first();


                            if (!$product) {
                                $itemId = $this->findProductBySKU($lineItem[$destination_identity], $user_id, $user_integration_id, $account);
                            } else {
                                $itemId = $product->api_product_id;
                            }

                            if ($itemId) {

                                $qty = (int) $val->quantity;

                                $subtotal = floatval($val->price);
                                $unit_price = floatval($val->price) / $qty;

                                $new_item = [
                                    "DetailType" => "ItemBasedExpenseLineDetail",
                                    "Amount" => $subtotal,
                                    "ItemBasedExpenseLineDetail" => [
                                        "ItemRef" => [
                                            "value" => $itemId
                                        ],

                                        "Qty" => $qty,
                                        "UnitPrice" => $unit_price
                                    ],

                                ];

                                if (isset($link_order_line_detail[$itemId])) {
                                    $new_item["LinkedTxn"][] = ["TxnId" => $link_order_id, "TxnType" => "PurchaseOrder", "TxnLineId" => $link_order_line_detail[$itemId]];
                                }


                                array_push(
                                    $items,
                                    $new_item

                                );
                                $total_amount = $total_amount + $subtotal;
                            } else {
                                $productNotFound = true;
                            }
                        }
                    } else {
                        $productNotFound = true;
                    }
                }
            }
        }

        $memo = array_unique($memo);

        return ['items' => $items, 'total_amount' => $total_amount, 'productNotFound' => $productNotFound, 'memo' => implode(', ', $memo)];
    }

    /* Prepare Tax Rate Data */
    public function prepareTermData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $primaryID = NULL;

        if (isset($value['Id'])) {
            $create = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_id' => $value['Id'],
                'platform_object_id' => $objectId,
                'api_code' => $value['Name'],
                'status' => @$value['Active'],
                'name' => @$value['Name'],
                'description' => @$value['DueDays'],
            ];
            if ($is_initial_sync) { //When is_initial_sync=1
                $save = PlatformObjectData::create($create);
                $primaryID = $save->id;
            } else {
                //When is_initial_sync=0
                $find = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $objectId, 'api_id' => $value['Id']])->first();
                if ($find) {
                    $primaryID = $find->id;
                    $find->status = @$value['Active'];
                    $find->name = $value['Name'];
                    $find->api_code = $value['Name'];
                    $find->description = @$value['DueDays'];
                    $find->save();
                } else {
                    $findProduct = PlatformObjectData::create($create);
                    $primaryID = $findProduct->id;
                }
            }
        }
        return $primaryID;
    }

    /* find Product By Id */
    public function findProductByID($apiProductID, $user_id, $user_integration_id, $account)
    {
        try {
            $productId = $productPrimaryId = $syncToken = null;
            if ($apiProductID && $account) {
                $apicall = $this->APICALL($account, "GET", "item/{$apiProductID}");
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $productData = $apicall['body']['Item'];
                    $productPrimaryId = $this->prepareProductData($productData, $user_id, $user_integration_id, 0);
                    $productId = $productData['Id'];
                    $syncToken = $productData['SyncToken'];
                } else {
                    $error = $this->handleResponseError($apicall);
                    $productId = !empty($error) ? $error : "API Error";
                }
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksServiceController - findProductByID - ' . $e->getLine() . " -> " . $e->getMessage());
            $productId = $e->getMessage();
        }
        return ['productId' => $productId, 'productPrimaryId' => $productPrimaryId, 'syncToken' => $syncToken];
    }

    /* Get SO Order Shipping Address */
    public function getShippingAddress($order)
    {
        $address = isset($order->order_address) ? $order->order_address : null;
        if ($address) {
            return [
                "FullAddress" => $address->address_name,
                "Line1" => $address->address1,
                "Line2" => $address->address2,
                "City" => $address->city,
                "State" => $address->state,
                "Country" => $address->country,
                "Email" => $address->email,
                "PostalCode" => $address->postal_code,
            ];
        } else {
            return [];
        }
    }

    /* Prepare Service Item Data */
    public function prepareServiceItemData($Item, $objectId, $user_id, $user_integration_id)
    {
        if (isset($Item['Id'])) {
            $platform_object_data = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $objectId, 'api_id' => $Item['Id']])->first();
            if ($platform_object_data) {

                $sku=$category=$name=null;
                if(isset($Item['Sku']) && !empty($Item['Sku'])){
                    $sku=$Item['Sku'];
                }
                if(isset($Item['ParentRef']['name']) && !empty($Item['ParentRef']['name'])){

                  $category=$Item['ParentRef']['name'];

                }

                if(!empty($sku) && !is_null($sku)){
                    if(!empty($category) && !is_null($category)){
                        $name=$sku."-".$category."-".$Item['Name'];
                    }else{
                        $name=$sku."-".$Item['Name'];
                    }
                }else{
                    if(!empty($category) && !is_null($category)){
                        $name=$category."-".$Item['Name'];
                    }else{
                         $name=$Item['Name'];
                    }
                }

                $platform_object_data->status = $Item['Active'];
                $platform_object_data->name =  (strlen($name) > 255) ? substr($name,0,255).'...' : $name;
                $platform_object_data->api_code =  (strlen($name) > 255) ? substr($name,0,255).'...' : $name;
                $platform_object_data->description = @$Item['Description'];
                $platform_object_data->save();
            } else {
                PlatformObjectData::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_id' => $Item['Id'], 'platform_object_id' => $objectId, 'api_code' => $Item['Name'], 'status' => $Item['Active'], 'name' => $Item['Name'], 'description' => @$Item['Description']]);
            }
        }
    }

    /* Prepare Customer Data */
    public function prepareCustomerData($customer, $user_id, $user_integration_id, $is_initial_sync = 0)
    {
        $customerPrimaryID = NULL;

        $email = @$customer['PrimaryEmailAddr']['Address'];
        $name = @$customer['DisplayName'];
        if (!$name) {
            $name = @$customer['GivenName'];
        }

        $customerCreate = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_customer_id' => $customer['Id'],
            'api_customer_code' => @$customer['SyncToken'],
            'company_name' => @$customer['CompanyName'],
            'phone' => @$customer['PrimaryPhone']['FreeFormNumber'],
            'first_name' => $name,
            'name_name' => @$customer['FamilyName'],
            'email' => $email,
            'customer_name' => $name,
            'api_updated_at' => @$customer['MetaData']['LastUpdatedTime'],
            'api_created_at' => @$customer['MetaData']['CreateTime'],
            'address1' => @$customer['BillAddr']['Line1'],
            'address2' => @$customer['BillAddr']['City'],
            'address3' => @$customer['BillAddr']['CountrySubDivisionCode'],
            'country' => @$customer['BillAddr']['Country'],
            'postal_addresses' => @$customer['BillAddr']['PostalCode'],
            'type' => 'Customer',
            'sync_status' => $customer['Active'] ? 'Ready' : 'Inactive',
            'is_deleted' => $customer['Active'] ? 0 : 1
        ];

        if (isset($customer['linked_id'])) {
            $customerCreate['linked_id'] = $customer['linked_id'];
        }

        if ($is_initial_sync) {
            //When is_initial_sync=1
            $platform_customer = PlatformCustomer::create($customerCreate);
            $customerPrimaryID = isset($platform_customer->id) ? $platform_customer->id : null;
        } else {
            //When is_initial_sync=0
            $platform_customer = PlatformCustomer::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_customer_id' => $customer['Id'], 'type' => 'Customer'])->first();
            if ($platform_customer) {
                $customerPrimaryID = $platform_customer->id;
                if ($platform_customer->api_updated_at != isset($customer['MetaData']['LastUpdatedTime']) ? $customer['MetaData']['LastUpdatedTime'] : null) {
                    $platform_customer->first_name = $name;
                    $platform_customer->customer_name = $name;
                    $platform_customer->company_name = @$customer['CompanyName'];
                    $platform_customer->api_customer_code = @$customer['SyncToken'];
                    $platform_customer->phone = @$customer['PrimaryPhone']['FreeFormNumber'];
                    $platform_customer->email = $email;
                    $platform_customer->api_updated_at = @$customer['MetaData']['LastUpdatedTime'];
                    $platform_customer->address1 = @$customer['BillAddr']['Line1'];
                    $platform_customer->address2 = @$customer['BillAddr']['City'];
                    $platform_customer->address3 = @$customer['BillAddr']['CountrySubDivisionCode'];
                    $platform_customer->country = @$customer['BillAddr']['Country'];
                    $platform_customer->postal_addresses = @$customer['BillAddr']['PostalCode'];
                    $platform_customer->sync_status = $customer['Active'] ? 'Ready' : 'Inactive';
                }

                $platform_customer->is_deleted = $customer['Active'] ? 0 : 1;
                if (isset($customer['linked_id'])) {
                    $platform_customer->linked_id = $customer['linked_id'];
                }

                $platform_customer->save();
            } else {
                $platform_customer = PlatformCustomer::create($customerCreate);
                $customerPrimaryID = isset($platform_customer->id) ? $platform_customer->id : null;
            }
        }

        return $customerPrimaryID;
    }
    /* Send Email Notification */
    // public function notifyCustomerByEmail($payload=[],$organizationId=null){
    //     $mailData = array(
    //         'body_msg' => @$payload['body'],
    //         'to' => @$payload['email'],
    //         'to_name' => @$payload['to_name'],
    //         'subject' => @$payload['subject'],
    //         'from' => @$payload['from'],
    //         'from_name' => @$payload['from_name'],
    //         'email' => @$payload['email'],
    //     );
    //     $response = \App\Http\Controllers\CommonController::sendMail($mailData,$organizationId);// return true and false value
    // }
    public function notifyCustomerByEmail($payload=[],$organizationId=null){

        $org_id = $organizationId;
        $to = @$payload['email'];
        $name=@$payload['to_name'];
        $message='<table style="border: 1px solid black;border-collapse: collapse;width:100%;text-align:center;">
        <thead>
        <tr style="border: 1px solid black;border-collapse: collapse;">
        <th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Payment Tye</th>
        <th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Sales Orders</th>
        </tr>
        </thead>
        <tbody>
        <tr style="border: 1px solid black;border-collapse: collapse;">
        <td style="border: 1px solid black;border-collapse: collapse;padding:3px">' . @$payload['paymenttypes'] . '</td>
        <td style="border: 1px solid black;border-collapse: collapse;padding:3px">' . @$payload['orders'] . '</td>
        </tr></tbody>';;
        $from = NULL; // These both variable will be set in CommonController
        $from_name = NULL;
        $data=DB::table('es_organizations')->where('organization_id',$org_id)->first();
        if (isset($data->logo_url) && !empty($data->logo_url)) {
            $public_path = $data->logo_url;
            $logo_src = env('CONTENT_SERVER_PATH') . $public_path;
            $logo = '<img src="' . $logo_src . '" alt="Logo" style="margin-left:30%;width:30%;"><br>';
        } else {
            $logo = '';
        }
        $org_name =isset($data->name)?$data->name: null;
        // Check whether custom verification template is created for this company
        $template_setting = DB::table('es_email_template')
            ->select('mail_subject', 'mail_body')
            ->where(['organization_id' => $org_id, 'mail_type' => 'payment_type_missing_notification', 'active' => 1])
            ->first();

        if (isset($template_setting) && !empty($template_setting)) {
            $raw_mail_subject = $template_setting->mail_subject;
            $raw_body_content = $template_setting->mail_body;
            $search = array(
                '@org_name', '@name', '@email', '@logo', '@message'
            );
            $replace = array(
                $org_name, $name, $to, $logo, $message
            );
            $subject = str_replace($search, $replace, $raw_mail_subject);
            $body = str_replace($search, $replace, $raw_body_content);
            $mailData = array(
                'body_msg' => $body,
                'to' =>  $to,
                'to_name' => $name,
                'subject' => $subject,
               'from' => $from,
               'from_name' => $from_name,
                'email' =>  $to,
            );

            $response = \App\Http\Controllers\CommonController::sendMail($mailData,$organizationId);// return true and false value
       }
    }
}
