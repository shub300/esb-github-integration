<?php
 
namespace App\Http\Controllers;
 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestoreController extends Controller
{
    private $liveDB, $backupDB;

    public function __construct()
    {
        $this->liveDB = DB::connection('mysql');
        $this->backupDB = DB::connection('backup_connection');
    }
    
    public function restore()
    {
        exit();
        \Storage::disk('local')->append('restore_data_log_time.txt', 'start: '.date('Y-m-d H:i:s'));

        

        $userId = 209;
        $userIntgId = 256;
        $platformId = 1;
        $date = '2023-07-30';
        $limit = 300;
        $offset = 0;
        $bp_platform_id = 1;
        $info_platform_id = 28;

        $restoreLimit = $this->liveDB->table('platform_urls')->where(['user_integration_id' => $userIntgId, 'platform_id' => $platformId, 'url_name' => 'restore_data'])->select('id','url')->first();

        if($restoreLimit){
            $offset = intval($restoreLimit->url);
        }else{
            $this->liveDB->table('platform_urls')->insert(['user_id'=>$userId, 'user_integration_id' => $userIntgId, 'platform_id' => $platformId, 'url'=> ($offset + $limit), 'url_name' => 'restore_data']);
        }

        $backup_orders = $this->backupDB->table('platform_order')->where(['user_integration_id'=>$userIntgId, 'platform_id'=>$platformId])
                  ->where('created_at','>',$date)->orderBy('id','asc')->skip($offset)->take($limit)->get(); 

        if(count($backup_orders)){
            
            
            foreach($backup_orders as $backup_order){    
                
                //to get sync log
                if($backup_order->order_type ==  'SO'){
                    $object_name = 'sales_order';
                    $event_type = 'GET_GOODSOUTNOTECREATED';
                }elseif($backup_order->order_type == 'PO'){
                    $object_name = 'purchase_order';
                    $event_type = 'GET_PURCHASEORDER';
                }else{
                    $object_name = 'sales_credit';
                    $event_type = 'GET_SALESCREDIT';
                }

                $platformObjectId = $this->backupDB->table('platform_objects')->where('name',$object_name)->pluck('id')->first();

                $user_workflow_rule =   $this->backupDB->table('user_workflow_rule as ur')->select('ur.id')
                ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
                ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
                ->where('ur.user_integration_id', $userIntgId)
                ->where('e.event_id', $event_type)->first();
                $user_workflow_rule_id = $user_workflow_rule->id;
                
                //end to get sync log

                //restore BP platform_customer
                $new_platform_customer_id = 0;
                if($backup_order->platform_customer_id > 0){
                   $backup_platform_customer =  $this->backupDB->table('platform_customer')->where(['user_integration_id'=>$backup_order->user_integration_id,'platform_id'=>$backup_order->platform_id,'id'=>$backup_order->platform_customer_id])->first();
                   if($backup_platform_customer){
                    $backup_platform_customer_id = $backup_platform_customer->id;
                    $platform_customer = $this->liveDB->table('platform_customer')->where(['user_integration_id'=>$backup_platform_customer->user_integration_id,'platform_id'=>$backup_platform_customer->platform_id, 'api_customer_id'=>$backup_platform_customer->api_customer_id, 'type'=>$backup_platform_customer->type])->first();
                     if(!$platform_customer){    
                        $platform_customer = (array) $backup_platform_customer;
                        unset($platform_customer['id']);
                        $new_platform_customer_id = $this->liveDB->table('platform_customer')->insertGetId($platform_customer);
                     }else{
                        $new_platform_customer_id = $platform_customer->id;
                     }
                   }
                }
            
                //restore source order
                $bpBkpOdrId = $backup_order->id;
                $source_platform_order = $this->liveDB->table('platform_order')->where(['user_integration_id'=>$userIntgId,'platform_id'=>$backup_order->platform_id,'order_type'=>$backup_order->order_type, 'api_order_id'=>$backup_order->api_order_id, 'order_number'=>$backup_order->order_number])->first();
                if(!$source_platform_order){
                    $source_platform_order = (array) $backup_order;
                    unset($source_platform_order['id']);
                    if($new_platform_customer_id){
                        $source_platform_order['platform_customer_id']= $new_platform_customer_id; //update restored platform customer id
                    }
                    $bpNewOdrId = $this->liveDB->table('platform_order')->insertGetId($source_platform_order);
                }else{
                    $bpNewOdrId = $source_platform_order->id;
                }
                
                //bp  sync log restore
                $whereCon = ['user_workflow_rule_id' => $user_workflow_rule_id, 'platform_object_id' => $platformObjectId, 'source_platform_id' => $bp_platform_id, 'destination_platform_id' => $info_platform_id,'record_id'=>$bpBkpOdrId, 'user_id' => $userId];
		        $bp_sync_log = $this->backupDB->table('sync_logs')->where($whereCon)->first();
                if($bp_sync_log){
                    $bp_sync_log = (array) $bp_sync_log;
                    unset($bp_sync_log['id']);
                    $bp_sync_log['record_id']=$bpNewOdrId;
                    $this->liveDB->table('sync_logs')->insertGetId($bp_sync_log);
                }
                

                //restore dest order
                $infoBkpOdrId = null;
                $infoNewOdrId = null;
                if($backup_order->linked_id>0){
                    $dest_backup_order = $this->backupDB->table('platform_order')->where(['user_integration_id'=>$userIntgId,'id'=>$backup_order->linked_id])->first();
                    if($dest_backup_order){
                        $infoBkpOdrId = $dest_backup_order->id;
                        $dest_platform_order = $this->liveDB->table('platform_order')->where(['user_integration_id'=>$userIntgId,'platform_id'=>$dest_backup_order->platform_id,'order_type'=>$dest_backup_order->order_type, 'api_order_id'=>$dest_backup_order->api_order_id, 'order_number'=>$dest_backup_order->order_number])->first();
                        if(!$dest_platform_order){
                            $dest_platform_order = (array) $dest_backup_order;
                            unset($dest_platform_order['id']);
                            $dest_platform_order['linked_id'] = $bpNewOdrId;
                            $infoNewOdrId = $this->liveDB->table('platform_order')->insertGetId($dest_platform_order);
                        }
                        else{
                            $infoNewOdrId = $dest_platform_order->id;
                        }
                        
                        $this->liveDB->table('platform_order')->where('id',$bpNewOdrId)->update(['linked_id'=>$infoNewOdrId]);

                        //info  sync log restore
                        $whereCon = ['user_workflow_rule_id' => $user_workflow_rule_id, 'platform_object_id' => $platformObjectId, 'source_platform_id' => $info_platform_id, 'destination_platform_id' => $bp_platform_id,'record_id'=>$infoBkpOdrId, 'user_id' => $userId];
                        $info_sync_log = $this->backupDB->table('sync_logs')->where($whereCon)->first();
                        if($info_sync_log){
                            $info_sync_log = (array) $info_sync_log;
                            unset($info_sync_log['id']);
                            $info_sync_log['record_id']=$infoNewOdrId;
                            $this->liveDB->table('sync_logs')->insertGetId($info_sync_log);
                        }

                        
                    }
                }

                $this->restoreChildTableRecord($userIntgId, $bpBkpOdrId, $bpNewOdrId); // restore brightpearl side child table rec
                if($infoBkpOdrId && $infoNewOdrId){
                    $this->restoreChildTableRecord($userIntgId, $infoBkpOdrId, $infoNewOdrId); // restore infoplus side child table rec
                } 
                
                $this->restoreLinkedTableRecord($userIntgId, $bpBkpOdrId, $bpNewOdrId, $infoBkpOdrId, $infoNewOdrId); //hanlde restore  of linked tables like shipments commonally

            }

            
            //update limit after restored records
            if($restoreLimit){
                if(count($backup_orders)<intval($limit)){
                    $restored = intval($restoreLimit->url) + count($backup_orders);// for last call only
                }else{
                    $restored = intval($restoreLimit->url) + $limit; //update restored limit on each call
                }
                $this->liveDB->table('platform_urls')->where('id',$restoreLimit->id)->update(['url'=>$restored]);
            }
            
        }
        \Storage::disk('local')->append('restore_data_log_time.txt', 'end: '.date('Y-m-d H:i:s'));
    }

    public function restoreChildTableRecord($userIntgId, $bkpPlatformOrdId, $newPlatformOrdId){
        
        //restore platform_order_line
        $backup_odr_lines = $this->backupDB->table('platform_order_line')->where('platform_order_id',$bkpPlatformOrdId)->get();
        if(count($backup_odr_lines)){
            foreach($backup_odr_lines as $backup_odr_line){
                $odr_line = $this->liveDB->table('platform_order_line')->where(['platform_order_id'=>$newPlatformOrdId, 'api_order_line_id'=>$backup_odr_line->api_order_line_id])->first();
                if(!$odr_line){
                    $odr_line = (array) $backup_odr_line;
                    unset($odr_line['id']);
                    $odr_line['platform_order_id']=$newPlatformOrdId;
                    $this->liveDB->table('platform_order_line')->insert($odr_line);
                }
            }
        }

        //restore platform_order_refunds
        $backup_odr_refund = $this->backupDB->table('platform_order_refunds')->where('platform_order_id',$bkpPlatformOrdId)->first();
        if($backup_odr_refund){
            $backup_odr_refund_id = $backup_odr_refund->id;

            //delete platform_order_refunds from live DB before insert
            $this->liveDB->table('platform_order_refunds')->where('platform_order_id',$newPlatformOrdId)->delete();

            $odr_refund = (array) $backup_odr_refund;
            unset($odr_refund['id']);
            $odr_refund['platform_order_id']=$newPlatformOrdId;
            $new_platform_order_refund_id = $this->liveDB->table('platform_order_refunds')->insertGetId($odr_refund);
            
            //restore platform_order_refund_lines 
            $backup_platform_order_refund_lines = $this->backupDB->table('platform_order_refund_lines')->where('platform_order_refund_id',$backup_odr_refund_id)->get();
            if(count($backup_platform_order_refund_lines) && $new_platform_order_refund_id){
                foreach($backup_platform_order_refund_lines as $backup_platform_order_refund_line){
                        $platform_order_refund_line = (array) $backup_platform_order_refund_line;
                        unset($platform_order_refund_line['id']);
                        $platform_order_refund_line['platform_order_refund_id'] = $new_platform_order_refund_id;
                        $this->liveDB->table('platform_order_refund_lines')->insert($platform_order_refund_line);
                }
            }
        }

        
        //restore platform_order_additional_information 
        $backup_odr_add_info = $this->backupDB->table('platform_order_additional_information')->where('platform_order_id',$bkpPlatformOrdId)->first();
        if($backup_odr_add_info){ 
            $odr_add_info = $this->liveDB->table('platform_order_additional_information')->where('platform_order_id',$newPlatformOrdId)->first();
            if(!$odr_add_info){
                $odr_add_info = (array) $backup_odr_add_info;
                unset($odr_add_info['id']);
                $odr_add_info['platform_order_id'] = $newPlatformOrdId;
                $this->liveDB->table('platform_order_additional_information')->insert($odr_add_info);
            }
        }

        //restore platform_order_address
        $backup_odr_addresses = $this->backupDB->table('platform_order_address')->where('platform_order_id',$bkpPlatformOrdId)->get();
        if(count($backup_odr_addresses)){
            foreach($backup_odr_addresses as $backup_odr_address){
                $odr_address = $this->liveDB->table('platform_order_address')->where(['platform_order_id'=>$newPlatformOrdId, 'address_type'=>$backup_odr_address->address_type])->first();
                if(!$odr_address){
                    $odr_address = (array) $backup_odr_address;
                    unset($odr_address['id']);
                    $odr_address['platform_order_id']=$newPlatformOrdId;
                    $this->liveDB->table('platform_order_address')->insert($odr_address);
                }
            }
        }
    }

    
    public function restoreLinkedTableRecord($userIntgId, $bpBkpOdrId, $bpNewOdrId, $infoBkpOdrId, $infoNewOdrId){

        //restore platform_order_shipments
        if($bpBkpOdrId && $bpNewOdrId && $infoBkpOdrId && $infoNewOdrId){// restore both side shipment tables records

            $info_backup_odr_shipments = $this->backupDB->table('platform_order_shipments')->where(['user_integration_id'=>$userIntgId, 'platform_order_id'=> $bpBkpOdrId])->get();
            if(count($info_backup_odr_shipments)){
                
                //delete shipment for both platform order from live DB before insert
                $this->liveDB->table('platform_order_shipments')->where('user_integration_id',$userIntgId)->whereIn('platform_order_id',[$bpNewOdrId, $infoNewOdrId])->delete();
            
                foreach($info_backup_odr_shipments as $info_backup_odr_shipment){
                    $info_backup_platform_order_shipment_id = $info_backup_odr_shipment->id;
                    //info shipment restore
                    $bp_new_odr_shipment_id = 0;
                    if($info_backup_odr_shipment->linked_id > 0){

                        $bp_backup_odr_shipment = $this->backupDB->table('platform_order_shipments')->where(['id'=>$info_backup_odr_shipment->linked_id])->first();

                        if($bp_backup_odr_shipment){
                            $bp_backup_platform_order_shipment_id = $bp_backup_odr_shipment->id;
                            $bp_backup_odr_shipment = (array) $bp_backup_odr_shipment;
                            unset($bp_backup_odr_shipment['id']);
                            $bp_backup_odr_shipment['platform_order_id']=$infoNewOdrId;//changeId
                            $bp_new_odr_shipment_id =  $this->liveDB->table('platform_order_shipments')->insertGetId($bp_backup_odr_shipment);

                            //restore info platform_order_shipment_lines
                            $bp_backup_platform_order_shipment_lines = $this->backupDB->table('platform_order_shipment_lines')->where('platform_order_shipment_id',$bp_backup_platform_order_shipment_id)->get();
                            if(count($bp_backup_platform_order_shipment_lines) && $bp_new_odr_shipment_id){
                                foreach($bp_backup_platform_order_shipment_lines as $bp_backup_platform_order_shipment_line){
                                        $bp_platform_order_shipment_line = (array) $bp_backup_platform_order_shipment_line;
                                        unset($bp_platform_order_shipment_line['id']);
                                        $bp_platform_order_shipment_line['platform_order_shipment_id'] = $bp_new_odr_shipment_id;
                                        $this->liveDB->table('platform_order_shipment_lines')->insert($bp_platform_order_shipment_line);
                                }
                            }
                        }
                        
                    }

                    //bp shipment restore
                    $info_backup_odr_shipment = (array) $info_backup_odr_shipment;
                    unset($info_backup_odr_shipment['id']);
                    if($bp_new_odr_shipment_id){
                        $info_backup_odr_shipment['linked_id'] = $bp_new_odr_shipment_id;
                    }
                    $info_backup_odr_shipment['platform_order_id']=$bpNewOdrId;
                    $info_new_odr_shipment_id =  $this->liveDB->table('platform_order_shipments')->insertGetId($info_backup_odr_shipment);

                    //restore bp platform_order_shipment_lines
                    $info_backup_platform_order_shipment_lines = $this->backupDB->table('platform_order_shipment_lines')->where('platform_order_shipment_id',$info_backup_platform_order_shipment_id)->get();
                    if(count($info_backup_platform_order_shipment_lines) && $info_new_odr_shipment_id){
                        foreach($info_backup_platform_order_shipment_lines as $info_backup_platform_order_shipment_line){
                                $info_backup_platform_order_shipment_line = (array) $info_backup_platform_order_shipment_line;
                                unset($info_backup_platform_order_shipment_line['id']);
                                $info_backup_platform_order_shipment_line['platform_order_shipment_id'] = $info_new_odr_shipment_id;
                                $this->liveDB->table('platform_order_shipment_lines')->insert($info_backup_platform_order_shipment_line);
                        }
                    }

                    if($bp_new_odr_shipment_id){
                        $this->liveDB->table('platform_order_shipments')->where('id',$bp_new_odr_shipment_id)->update(['linked_id'=>$info_new_odr_shipment_id]); //update linked_id for info shipment rec
                    }
                    
                }
            }
        }else{ //in case infoplus side record not found then restore shipment for bp only
                
                $bp_backup_odr_shipments = $this->backupDB->table('platform_order_shipments')->where(['user_integration_id'=>$userIntgId, 'platform_order_id'=> $bpBkpOdrId])->get();
                
                if(count($bp_backup_odr_shipments)){

                    //delete shipment from live DB before insert
                   $del_res =  $this->liveDB->table('platform_order_shipments')->where(['user_integration_id'=>$userIntgId, 'platform_order_id'=> $bpNewOdrId])->delete();
                    
                    foreach($bp_backup_odr_shipments as $bp_backup_odr_shipment){
                        $backup_platform_order_shipment_id = $bp_backup_odr_shipment->id;
                        $bp_backup_odr_shipment = (array) $bp_backup_odr_shipment;
                        
                        unset($bp_backup_odr_shipment['id']);
                        $bp_backup_odr_shipment['platform_order_id']=$bpNewOdrId;
                        $bp_new_odr_shipment_id =  $this->liveDB->table('platform_order_shipments')->insertGetId($bp_backup_odr_shipment);

                        //restore platform_order_shipment_lines
                        $bp_backup_platform_order_shipment_lines = $this->backupDB->table('platform_order_shipment_lines')->where('platform_order_shipment_id',$backup_platform_order_shipment_id)->get();
                        if(count($bp_backup_platform_order_shipment_lines) && $bp_new_odr_shipment_id){
                            foreach($bp_backup_platform_order_shipment_lines as $bp_backup_platform_order_shipment_line){
                                    $bp_platform_order_shipment_line = (array) $bp_backup_platform_order_shipment_line;
                                    unset($bp_platform_order_shipment_line['id']);
                                    $bp_platform_order_shipment_line['platform_order_shipment_id'] = $bp_new_odr_shipment_id;
                                    $this->liveDB->table('platform_order_shipment_lines')->insert($bp_platform_order_shipment_line);
                            }
                        }
                        
                    }
                }
        }
    }

    public function inventoryPull(){
        exit();
        $userId = 209;
        $userIntgId = 256;
        $initial_sync = 1;
        app('App\Http\Controllers\Infoplus\InfoplusApiController')->GetInventory($userId, $userIntgId, $initial_sync);
    }
}