<?php

namespace App\Http\Controllers\Brightpearl;

use App\Models\PlatformOrderShipmentLine;
use App\Models\Enum\PlatformRecordType;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformUrl;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformKitChildProductQuantity;
use App\Models\PlatformCustomFieldValue;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductOption;
use App\Models\PlatformField;
use App\Models\UserIntegration;
use App\Models\UserIntegrationSubEvent;
use Illuminate\Support\Facades\DB;
use App\Helper\WorkflowSnippet;
use App\Helper\ConnectionHelper;

class BrightpearlServices
{
    public $wfsnip, $helper;
    public function __construct()
    {
        $this->wfsnip = new WorkflowSnippet();
        $this->helper = new ConnectionHelper;
    }

    public static function getBPLineItemType($bpAdditionalInfos, $nominalApiCode) : string {
        if ($nominalApiCode == $bpAdditionalInfos->account_sale_nominal_code || $nominalApiCode == $bpAdditionalInfos->account_purchase_nominal_code)
            return 'ITEM';
        if ($nominalApiCode == $bpAdditionalInfos->account_discount_nominal_code)
            return 'DISCOUNT';
        if ($nominalApiCode == $bpAdditionalInfos->account_shipping_nominal_code)
            return 'SHIPPING';
        if ($nominalApiCode == $bpAdditionalInfos->account_giftcard_nominal_code)
            return 'GIFTCARD';
        return 'ITEM';
    }

    public function saveShipmentsFromGoodsOutNote($gon, $gonId, $userId, $userIntegrationId, $platformId) {
        if($gon && isset($gon['orderId'])) {
            return $this->saveShipment($gon, $gonId, $userId, $platformId, $userIntegrationId);
        }
    }
    
    private function saveShipment($go, $goId, $userId, $platformId, $userIntegrationId) {
        try {
            $shipment = new PlatformOrderShipment();
            $shipment->user_id = $userId;
            $shipment->platform_id = $platformId;
            $shipment->user_integration_id = $userIntegrationId;
            $shipment->shipment_id = $goId;
            $shipment->sync_status  = PlatformStatus::READY;
            $shipment->warehouse_id = $go['warehouseId'];
            $shipment->to_warehouse_id = $go['targetWarehouseId'];
            $shipment->shipment_transfer = 1;
            $shipment->type = PlatformRecordType::TRANSFER;
            $shipment->save();
            $this->saveShipmentLines($shipment, $go['transferRows']);
        } catch (\Exception $ex) {
            return $ex->getMessage();
        }
        return true;
    }
    
    private function saveShipmentLines($shipment, $items) {
        foreach($items as $item) {
            try {
                $line = new PlatformOrderShipmentLine();
                $line->platform_order_shipment_id = $shipment->id;
                $line->product_id  = $item['productId'];
                $line->location_id  = $item['locationId'];
                $line->quantity  = $item['quantity'];
                $line->save();
            } catch (\Exception $ex) {
                return $ex->getMessage();
            }
        }
        return true;
    }

    /*check parent user integration subevent status completed or not if complted then allow to share or copy parent integration data*/
    public function accessParentIngData($userIntegrationId, $userId, $subEvent, $sourcePlatformName, $platform_id, $type){

        $return_response = false; 

        $integInfo = UserIntegration::where('id',$userIntegrationId)->select('id','parent_integration_id','shared_platform_id')->first();
        
        if($integInfo->parent_integration_id){
            $platformNames = $this->helper->getPlatformIdsByPrimaryIds($integInfo->shared_platform_id); //it return platform_id from platform_lookup like [brightpearl,woocommerce]
            if(in_array($sourcePlatformName,$platformNames)){ 
                $iniSyncs =  $this->wfsnip->checkSimilarEventExists($integInfo->parent_integration_id, $subEvent, $platform_id); 
                if (count($iniSyncs)) {
                    $status = $iniSyncs[0]['status'];
                    $customObjectId = $this->helper->getObjectId('product');
                    if($status == 'completed'){
                        
                        $childIntgUserSubEventIds = DB::table('user_integration_sub_event AS p')
                        ->join('platform_sub_event AS c1', 'c1.id', '=', 'p.sub_event_id')
                        ->join('platform_events AS c2', 'c2.id', '=', 'c1.platform_event_id')
                        ->where(['p.user_integration_id' => $integInfo->id, 'c1.name' => $subEvent, 'c2.platform_id' => 1])->pluck('p.id')->toArray();

                        //if parent integration subevent completed then also make child subevent completed
                        UserIntegrationSubEvent::where('user_integration_id',$integInfo->id)->whereIn('id',[$childIntgUserSubEventIds])->update(['status' => 'completed']);
                        if($type == 'share'){
                            $return_response = true;
                        }else{
                            $return_response =$this->copyProductAndDetails($userId, $integInfo, $platform_id, $customObjectId);
                        }
                    }else{
                        $products = PlatformProduct::where(['user_integration_id'=>$integInfo->parent_integration_id, 'platform_id'=>$platform_id, 'is_deleted'=>0])->orderBy('id','asc')->count();
                        if($products){
                            if($type == 'share'){
                                $return_response = 'accessed chunks of products from parent integration to '.$type;
                            }else{
                                $this->copyProductAndDetails($userId, $integInfo, $platform_id, $customObjectId);
                                $return_response = 'accessed chunks of products from parent integration to '.$type;
                            }
                        }else{
                            $return_response = 'wait to '.$type.' parent integration products initial sync is in '.$status;
                        }
                        
                    }
                    
                }
            }
        }
        return $return_response;

    }

    /*copy parent integration platform_product & related child table data for current child integration*/
    public function copyProductAndDetails($userId, $integInfo, $platformId, $customObjectId){ 
        
            $limit = 200;
            $offset = 0;
        
            $processedLimit = PlatformUrl::where(['user_integration_id' => $integInfo->id, 'platform_id' => $platformId, 'url_name' => 'copyproduct_limit'])->select('id','url')->first();

            if($processedLimit){
                $offset = intval($processedLimit->url);
            }else{
                $process_rec = $offset + $limit;
                PlatformUrl::insert(['user_id'=>$userId, 'user_integration_id' => $integInfo->id, 'platform_id' => $platformId, 'url'=>$process_rec, 'url_name' => 'copyproduct_limit']);
            }

            $products = PlatformProduct::where(['user_integration_id'=>$integInfo->parent_integration_id, 'platform_id'=>$platformId, 'is_deleted'=>0])->orderBy('id','asc')->skip($offset)->take($limit)->get();
            
            if(count($products)){

                $newProducts = [];
                $newProductAtrributes = [];
                $newProductPrices = [];
                $newBundleProductQauntities = [];
                $newProductInventories = [];
                $newProductOptions = [];
                $newProductCustomFieldValues = [];
                
                    foreach($products as $product){
                            
                        $newProduct = $product->replicate()->toArray(); 
                        unset($newProduct['id']); 
                        unset($newProduct['linked_id']); 
                        $newProduct['user_integration_id'] = $integInfo->id; //update current integration id instead parent integration id
                        $newProduct['product_sync_status'] = 'Ready';
                        $newProduct['inventory_sync_status'] = 'Ready';
                        $newProducts[] = $newProduct;
                        
                        //prepare product attribute array to insert
                        if($attribute = $product->platformProductAttribute) {  // $product->platformProductAttribute == one to one relationship
                            $newProductAttribute = $attribute->replicate()->toArray(); 
                            unset($newProductAttribute['id']); 
                            unset($newProductAttribute['platform_product_id']);
                            $newProductAtrributes[] = $newProductAttribute;
                        }  

                        //prepare product price array to multi insert
                        if($product->platformProductPriceList){
                            foreach($product->platformProductPriceList as $price){
                                $newProductPrice = $price->replicate()->toArray(); 
                                unset($newProductPrice['id']); 
                                $newProductPrices[$newProductPrice['platform_product_id']][] = $newProductPrice;
                            }
                        }

                        //prepare product attribute array to insert
                        if($bundleProduct = $product->kitQuantity) {  // $product->kitQuantity == one to one relationship
                            $newBundleProduct = $bundleProduct->replicate()->toArray(); 
                            unset($newBundleProduct['id']); 
                            unset($newBundleProduct['platform_product_id']);
                            $newBundleProductQauntities[] = $newBundleProduct;
                        } 

                        //prepare product inventory array to multi insert
                        if($product->PlatformProductInventory){ 
                            foreach($product->PlatformProductInventory as $inventory){
                                $newInventory = $inventory->replicate()->toArray(); 
                                unset($newInventory['id']); 
                                $newInventory['sync_status'] = 'Ready';
                                $newInventory['user_integration_id'] = $integInfo->id;
                                $newProductInventories[$newInventory['platform_product_id']][] = $newInventory;
                            }
                        }

                        //prepare product options array to multi insert
                        if($product->PlatformProductOption){
                            foreach($product->PlatformProductOption as $option){
                                $newOption = $option->replicate()->toArray(); 
                                unset($newOption['id']); 
                                $newProductOptions[$newOption['platform_product_id']][] = $newOption;
                            }
                        }
                            
                    
                        //start get product custom field values 
                            $platformFieldIds = PlatformField::where(['platform_id' => $platformId, 'user_integration_id' => $integInfo->parent_integration_id, 'field_type' => 'custom', 'platform_object_id' => $customObjectId, 'status' => 1])->pluck('id')->toArray();
                            if(!empty($platformFieldIds)){
                                $getCustomFieldRecords =  PlatformCustomFieldValue::where(['record_id' => $product->id,'user_integration_id' => $integInfo->parent_integration_id, 'platform_id' => $platformId])
                                ->whereIn('platform_field_id',$platformFieldIds)->get();

                                if($getCustomFieldRecords){
                                    foreach($getCustomFieldRecords as $customFieldVal){
                                        $customFieldVal = $customFieldVal->replicate()->toArray();
                                        unset($customFieldVal['id']);
                                        $customFieldVal['user_integration_id'] = $integInfo->id;
                                        $newProductCustomFieldValues[$customFieldVal['record_id']][] = $customFieldVal;
                                    }
                                }
                                
                            }
                        //end get product custom field values
                        

                    }
                
            
                //start calculate the IDs of the new product rows
                PlatformProduct::insert($newProducts);
                $newProductIDs = PlatformProduct::where(['user_integration_id' => $integInfo->id,'platform_id' => $platformId])->whereIn('api_product_id', array_column($newProducts, 'api_product_id'))->pluck('id')->toArray();
                //end calculate the IDs of the new product rows

                //start attribute multi row insert
                for ($i = 0; $i < count($newProductAtrributes); $i++) {
                    $newProductAtrributes[$i]['platform_product_id'] = $newProductIDs[$i];
                }
                PlatformProductDetailAttribute::insert($newProductAtrributes);
                //end attribute multi row insert

                //start price multi row insert
                $pricesArr = [];
                $priceCounter = 0;
                foreach($newProductPrices as $productPrice){  
                    $priceCounter++;
                    foreach($productPrice as $price){ 
                        
                        $price['platform_product_id'] = $newProductIDs[$priceCounter-1];
                        
                        $pricesArr[] = $price;
                    }
                }
                PlatformProductPriceList::insert($pricesArr);
                //end price multi row insert

                //start bundle product qty multi row insert
                for ($i = 0; $i < count($newBundleProductQauntities); $i++) {
                    $newBundleProductQauntities[$i]['platform_product_id'] = $newProductIDs[$i];
                }
                PlatformKitChildProductQuantity::insert($newBundleProductQauntities);
                //end bundle product qty multi row insert

                //start inventory multi row insert
                $inventoryArr = [];
                $invCounter = 0;
                foreach($newProductInventories as $productInv){ 
                    $invCounter++;
                    foreach($productInv as $inv){ 
                        $inv['platform_product_id'] = $newProductIDs[$invCounter-1];
                        $inventoryArr[] = $inv;
                    }
                }
                PlatformProductInventory::insert($inventoryArr);
                //end inventory multi row insert

                //start inventory multi row insert
                $optionArr = [];
                $optionCounter = 0;
                foreach($newProductOptions as $productOption){ 
                    $optionCounter++;
                    foreach($productOption as $option){ 
                        $option['platform_product_id'] = $newProductIDs[$optionCounter-1];
                        $optionArr[] = $option;
                    }
                }
                PlatformProductOption::insert($optionArr);
                //end inventory multi row insert


                //start custom field value multi row insert
                $customValueArr = [];
                $customCounter = 0;
                foreach($newProductCustomFieldValues as $productCustomRec){ 
                    $customCounter++;
                    foreach($productCustomRec as $custom){ 
                        $custom['record_id'] = $newProductIDs[$customCounter-1];
                        $customValueArr[] = $custom;
                    }
                }
                
                PlatformCustomFieldValue::insert($customValueArr);
                //end custom field value multi row insert
                
                //update limit after copy records
                if($processedLimit){
                    if(count($products)<intval($limit)){
                        $processedLimit->url = intval($processedLimit->url) + count($products);// for last call only
                    }else{
                        $processedLimit->url = intval($processedLimit->url) + $limit; //update copied limit on each call
                    }
                    $processedLimit->save();
                }
            }
            
        return true;
    }

    
}
