<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Common;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CronHelper
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }
    /* Get Calculate Run Time For Crons */
    public function CalculateRunTime($source_event, $destination_event)
    {
        $sourceRunTime = $source_event->run_in_min; //return default run time for source_event
        $destinationRunTime = $destination_event->run_in_min; //return default run time destination_event    
        /* Get Destination Run Time By Source Platform Name */
        if (isset(\Config::get('apisettings.CustomCronRunTime')[$source_event->platform_name])) { //if find source platform id in config CustomCronRunTime file
            if ($destination_event->run_in_min_custom > 0) { // if run_in_min_custom is greater than 0 then override run_in_min as run_in_min_custom in $destinationRunTime variable
                $destinationRunTime = $destination_event->run_in_min_custom;
            }
        }
        /* Get Source Run Time By Destination Platform Name */
        if (isset(\Config::get('apisettings.CustomCronRunTime')[$destination_event->platform_name])) { //if find destination platform id in config CustomCronRunTime file
            if ($source_event->run_in_min_custom > 0) { // if run_in_min_custom is greater than 0 then override run_in_min as run_in_min_custom in $sourceRunTime variable
                $sourceRunTime = $source_event->run_in_min_custom;
            }
        }

        return  ['sourceRunTime' => $sourceRunTime, 'destinationRunTime' => $destinationRunTime];
    }
    public function setDataInCache($key, $val, $expireTime = 60 * 30)
    {
        $updateVal = json_decode(json_encode($val));
        Cache::put($key, $updateVal, $expireTime);
    }

    public function getDataFromCache($key)
    {
        $data = null;
        if (Cache::has($key)) {
            $data = Cache::get($key);
        }
        return $data;
    }

    public function clearDataFromCache($key)
    {
        Cache::forget($key);
    }

    public function GetUserWorkFlow($limit, $page)
    {
        $user_arr = DB::table('user_workflow_rule')
            ->leftJoin('platform_workflow_rule', 'user_workflow_rule.platform_workflow_rule_id', '=', 'platform_workflow_rule.id')
            ->where('user_workflow_rule.status', 1)
            ->select(
                'user_workflow_rule.id as user_workflow_rule_id',
                'user_workflow_rule.user_id',
                'user_workflow_rule.user_integration_id',
                'user_workflow_rule.platform_workflow_rule_id',
                'user_workflow_rule.is_all_data_fetched',
                'platform_workflow_rule.source_event_id',
                'platform_workflow_rule.destination_event_id',
                'platform_workflow_rule.platform_integration_id'
            )
            ->orderBy('user_workflow_rule.id', 'ASC')
            ->skip($page * $limit)
            ->limit($limit)->get();
        return  $user_arr;
    }
    public function PlatformEvent($event_id, $select = [])
    {

        if ($select) {
            $select = array_merge($select, ['platform_lookup.id as lookup_id', 'platform_events.id as id', 'platform_lookup.platform_id as platform_name']);
        } else {
            $select = array_merge(['platform_events.*', 'platform_lookup.id as lookup_id', 'platform_events.id as id', 'platform_lookup.platform_id as platform_name']);
        }
        return DB::table('platform_events')->select($select)->join('platform_lookup', 'platform_lookup.id', '=', 'platform_events.platform_id')->where(['platform_events.id' => $event_id, 'platform_events.status' => 1])->first(); //$this->mobj->getFirstResultByConditions('platform_events', ['id' => $event_id, 'status' => 1], $select);
    }
    public function HandleFullEnventory($platform_workflow_rule_id, $user_integration_id)
    {
        $objId = "";
        $SynTime = "";
        $nextSynTime = "";
        $frequency = "";
        $mapObjId = $this->mobj->getFirstResultByConditions('platform_objects', ['name' => 'full_inventory_sync'], ['id', 'store_with']);
        $objId = $mapObjId->id;
        if ($mapObjId->store_with) {
            $objId = $mapObjId->store_with;
        }

        $getMapData = $this->mobj->getFirstResultByConditions('platform_data_mapping', ['platform_workflow_rule_id' => $platform_workflow_rule_id, 'platform_object_id' => $objId, 'user_integration_id' => $user_integration_id], ['id', 'custom_data']);
        if ($getMapData) {
            $custom_data = $getMapData->custom_data;
            if ($custom_data) {
                $custArr = explode("|", $custom_data);
                if (isset($custArr[0]) && isset($custArr[1])) {
                    $frequency = $custArr[0];
                    $runTime = strtotime($custArr[1]);
                    if ($frequency == "Twice") {
                        $SynTime = date('H:i', $runTime);
                        $nextSynTime = date('H:i', $runTime + 60 * 60 * 12);
                    } else {
                        $SynTime = date('H:i', $runTime);
                    }
                }
            }
            return (['frequency' => $frequency, 'SynTime' => $SynTime, 'nextSynTime' => $nextSynTime, 'status_code' => 1]);
        } else {
            return (['status_code' => 0]);
        }
    }

    /* Data retention to delete unusefull data at a time*/
    public function HandleDataRetention()
    {
        date_default_timezone_set('UTC');

        //limit & skip to process 
        $limit = 20;
        $skip = 0;

        //set object ids for which data will be delete from sync_log & keep last 1 record always
        $orderTypeArr = ['SO', 'PO', 'TO', 'IO'];
        //records type object ids used in sync_log
        $objectIds = DB::table('platform_objects')->whereIn('name', ['purchase_order', 'sales_order', 'transfer_order'])->pluck('id');
        //row type in transaction
        $orderTransRowTypes = ['PAYMENT', 'REFUND'];
        //order by column

        $orderBy = "updated_at";

        $drpData = DB::table('kernal_uwf_limit')->where('type', 'DATA_RETENTION_BOT')->select('id', 'url', 'updated_at', 'max_limit')->first();
        if ($drpData) {
            $drp_data_id = $drpData->id;
            $skip = $drpData->url;
            $limit = $drpData->max_limit;
            $url = $drpData->url + $limit;
        } else {
            $url = $skip + $limit;
            $drp_data_id = $this->mobj->makeInsertGetId('kernal_uwf_limit', ['type' => 'DATA_RETENTION_BOT', 'url' => $url, 'max_limit' => 20]);
        }

        //Get User Integrations where data retention policy active status with limit & offset by kernal_uwf_limit 
        $activeDRP = DB::table('user_integrations as ui')
            ->join('platform_integrations as pi', 'ui.platform_integration_id', 'pi.id')
            // ->where('ui.data_retention_status',1)
            ->where('pi.data_retention_status', 1)
            ->select('ui.id as ui_id', 'ui.user_id', 'ui.platform_integration_id as pi_id', 'ui.data_retention_period as ui_drp', 'pi.data_retention_period as pi_drp', 'pi.source_platform_id', 'pi.destination_platform_id', 'ui.created_at', 'pi.description')->skip($skip)->limit($limit)->get();

        if (count($activeDRP) > 0) {
            foreach ($activeDRP as $integ_data) {
                //current date - Data retention period = old date   // retention by $integ_data->ui_drp or  $integ_data->pi_drp
                $dataRetentionPeriod = $integ_data->pi_drp;
                $old_date = date('Y-m-d', (strtotime('-' . $dataRetentionPeriod . ' day', time())));



                //delete records from platform_order keep 5 of all order type
                $skip_ord_ids = [];
                foreach ($orderTypeArr as $type) {
                    $data_po = $this->mobj->getFirstResultByConditions('platform_order', ['user_id' => $integ_data->user_id, 'user_integration_id' => $integ_data->ui_id, 'order_type' => $type], ['id'], ['api_updated_at' => 'desc'], []);

                    if ($data_po) {
                        array_push($skip_ord_ids, $data_po->id);
                    }
                }


                $po_del_status = DB::table('platform_order')->where('user_id', $integ_data->user_id)->where('user_integration_id', $integ_data->ui_id)
                    ->where(DB::raw("(DATE_FORMAT(created_at,'%Y-%m-%d'))"), "<", $old_date)->whereNotIn('id', $skip_ord_ids)
                    ->whereIn('order_type', $orderTypeArr)->delete();



                //delete records from sync log keep 5 of all order types of object data
                $skip_sync_log_obj_ids = [];
                foreach ($objectIds as $objectId_item) {
                    $data_sync_log = $this->mobj->getFirstResultByConditions('sync_logs', ['user_id' => $integ_data->user_id, 'platform_object_id' => $objectId_item], ['id'], [$orderBy => 'desc'], ['source_platform_id' => [$integ_data->source_platform_id, $integ_data->destination_platform_id], 'destination_platform_id' => [$integ_data->destination_platform_id, $integ_data->source_platform_id]]);
                    if ($data_sync_log) {
                        array_push($skip_sync_log_obj_ids, $data_sync_log->id);
                    }
                }
                $log_del_status = DB::table('sync_logs')->where('user_id', $integ_data->user_id)
                    ->whereIn('source_platform_id', [$integ_data->source_platform_id, $integ_data->destination_platform_id])
                    ->whereIn('destination_platform_id', [$integ_data->destination_platform_id, $integ_data->source_platform_id])
                    ->whereIn('platform_object_id', $objectIds)
                    ->where(DB::raw("(DATE_FORMAT(created_at,'%Y-%m-%d'))"), "<", $old_date)
                    ->whereNotIn('id', $skip_sync_log_obj_ids)->delete();




                //delete platform_url
                $platform_urls_del_status = DB::table('platform_urls')->where('user_id', $integ_data->user_id)
                    ->whereIn('platform_id', [$integ_data->source_platform_id, $integ_data->destination_platform_id])
                    ->where('user_integration_id', $integ_data->ui_id)
                    ->where(DB::raw("(DATE_FORMAT(updated_at,'%Y-%m-%d'))"), "<", $old_date)->delete();


                //delete platform_inventory_trails
                $skip_trail_ids = [];
                $data_inv_trail = $this->mobj->getFirstResultByConditions('platform_inventory_trails', ['user_id' => $integ_data->user_id, 'user_integration_id' => $integ_data->ui_id], ['id'], ['api_updated_at' => 'desc'], []);
                if ($data_inv_trail) {
                    array_push($skip_trail_ids, $data_inv_trail->id);
                }
                $inventory_trail_del_status = DB::table('platform_inventory_trails')->where('user_id', $integ_data->user_id)
                    ->where('user_integration_id', $integ_data->ui_id)->where(DB::raw("(DATE_FORMAT(created_at,'%Y-%m-%d'))"), "<", $old_date)
                    ->whereNotIn('id', $skip_trail_ids)->delete();


                //delete from platform_invoice
                $skip_invc_ids = [];
                $data_ord_invc = $this->mobj->getFirstResultByConditions('platform_invoice', ['user_id' => $integ_data->user_id, 'user_integration_id' => $integ_data->ui_id], ['id'], ['api_updated_at' => 'desc'], []);
                if ($data_ord_invc) {
                    array_push($skip_invc_ids, $data_ord_invc->id);
                }
                $platform_invoice_del_status = DB::table('platform_invoice')->where('user_id', $integ_data->user_id)
                    ->where('user_integration_id', $integ_data->ui_id)->where(DB::raw("(DATE_FORMAT(created_at,'%Y-%m-%d'))"), "<", $old_date)
                    ->whereNotIn('id', $skip_invc_ids)->delete();


                //delete shipment
                $skip_shipment_ids = [];
                $data_shipment = $this->mobj->getFirstResultByConditions('platform_order_shipments', ['user_id' => $integ_data->user_id, 'user_integration_id' => $integ_data->ui_id], ['id'], ['created_on' => 'desc'], []);
                if ($data_shipment) {
                    array_push($skip_shipment_ids, $data_shipment->id);
                }
                $order_shipment_del_status = DB::table('platform_order_shipments')->where('user_id', $integ_data->user_id)
                    ->where('user_integration_id', $integ_data->ui_id)->where(DB::raw("(DATE_FORMAT(created_at,'%Y-%m-%d'))"), "<", $old_date)
                    ->whereNotIn('id', $skip_shipment_ids)->delete();


                //delete from platform_order_transactions & keep payment & refund both type minimun row 
                $skip_trans_ids = [];
                foreach ($orderTransRowTypes as $type) {
                    $data_ot = $this->mobj->getFirstResultByConditions('platform_order_transactions', ['user_integration_id' => $integ_data->ui_id, 'row_type' => $type], ['id'], [$orderBy => 'desc'], []);
                    if ($data_ot) {
                        array_push($skip_trans_ids, $data_ot->id);
                    }
                }
                $order_transactions_del_status = DB::table('platform_order_transactions')
                    ->where('user_integration_id', $integ_data->ui_id)->where(DB::raw("(DATE_FORMAT(created_at,'%Y-%m-%d'))"), "<", $old_date)
                    ->whereIn('row_type', $orderTransRowTypes)
                    ->whereNotIn('id', $skip_trans_ids)->delete();


                //write log for data retention
                Log::channel('dataRetention')->info('Run-Time :' . date("Y-m-d H:i:s") . ' ' . strip_tags($integ_data->description) . '  | UserIntegId : ' . $integ_data->ui_id . ' | DataRetentionPeriod(inDays) : ' . $dataRetentionPeriod . PHP_EOL . ' Delete Records by crated_at  <  ' . $old_date . ' from Tables  :  platform_order : ' . $po_del_status . ' | sync_logs : ' . $log_del_status . ' | del_objectIds ' . json_encode($objectIds) . ' | platform_urls_del_status: ' . $platform_urls_del_status . ' | inventory_trail_del_status : ' . $inventory_trail_del_status . ' | order_shipment_del_status : ' . $order_shipment_del_status . ' | platform_invoice_del_status : ' . $platform_invoice_del_status . ' | order_transactions_del_status : ' . $order_transactions_del_status . PHP_EOL);
            }

            //update limit after successfully process selected chunks
            if ($drp_data_id) {
                $this->mobj->makeUpdate('kernal_uwf_limit', ['url' => $url], ['id' => $drp_data_id]);
            }
        } else {
            if ($drp_data_id) {
                $this->mobj->makeUpdate('kernal_uwf_limit', ['url' => 0], ['id' => $drp_data_id]);
                Log::channel('dataRetention')->info('Run-Time :' . date("Y-m-d H:i:s") . ' No Record Find for delete' . PHP_EOL);
            }
        }
    }

    /* restore the record deleted by data retention or delete record permanently from archive tables*/
    public function restoreArchiveRecords($tableName, $where = [])
    {
        //get Record id by from first result
        $record_ids = [];
        if ($tableName) {
            try {
                $response = DB::table($tableName . '_archive');
                if (count($where)) {
                    $response->where($where);
                }
                $responseData =  $response->select('id')->first();

                if (isset($responseData)) {
                    array_push($record_ids, $responseData->id);
                }

                //start restore logic if response data find
                if (count($record_ids) > 0) {
                    //List of dependent child table delete record by cascadding....
                    if ($tableName == "platform_order") {
                        $DepTablArr = [
                            ['linkedTabName' => 'platform_order_additional_information', 'relationColumn' => 'platform_order_id'],
                            ['linkedTabName' => 'sync_logs', 'relationColumn' => 'record_id'],
                            ['linkedTabName' => 'platform_order_address', 'relationColumn' => 'platform_order_id'],
                            ['linkedTabName' => 'platform_order_line', 'relationColumn' => 'platform_order_id'],
                            ['linkedTabName' => 'platform_order_transactions', 'relationColumn' => 'platform_order_id'],

                            ['linkedTabName' => 'platform_order_shipments', 'relationColumn' => 'platform_order_id', 'subchild' => ['linkedTabName' => 'platform_order_shipment_lines', 'relationColumn' => 'platform_order_shipment_id']],

                            ['linkedTabName' => 'platform_invoice', 'relationColumn' => 'platform_order_id', 'subchild' => ['linkedTabName' => 'platform_invoice_line', 'relationColumn' => 'platform_invoice_id']],

                            ['linkedTabName' => 'platform_order_refunds', 'relationColumn' => 'platform_order_id', 'subchild' => ['linkedTabName' => 'platform_order_refund_lines', 'relationColumn' => 'platform_order_refund_id']]
                        ];
                    } else if ($tableName == "platform_order_shipments") {
                        $DepTablArr = [
                            ['linkedTabName' => 'platform_order_shipment_lines', 'relationColumn' => 'platform_order_shipment_id']
                        ];
                    } else if ($tableName == "platform_invoice") {
                        $DepTablArr = [
                            ['linkedTabName' => 'platform_invoice_line', 'relationColumn' => 'platform_invoice_id']
                        ];
                    } else if ($tableName == "platform_order_transactions") {
                        $DepTablArr = [];
                    } else {
                        $DepTablArr = [];
                    }

                    //start action
                    $restoredItemArr = [];
                    $restoreRows = DB::table($tableName . '_archive')->whereIn('id', $record_ids)->orWhereIn('linked_id', $record_ids)->get()->toArray();
                    if (count($restoreRows) > 0) {
                        foreach ($restoreRows as $item) {
                            $itemArray = json_decode(json_encode($item), true);
                            //set new created & updated at
                            $mainTabPrimaryId = $itemArray['id'];
                            $itemArray['created_at'] = date("Y-m-d H:i:s");
                            $itemArray['updated_at'] = date("Y-m-d H:i:s");

                            //1 restore Parent table records & delete row
                            $restoreStatus = DB::table($tableName)->insert($itemArray);
                            array_push($restoredItemArr, $item->id);

                            // restore dependent child table data if exists
                            if (count($DepTablArr) > 0) {
                                foreach ($DepTablArr as $childTable) {

                                    $linkedTabName = $childTable['linkedTabName'];
                                    $relationColumn = $childTable['relationColumn'];

                                    $childTabPrimaryId = "";
                                    $data_child_table_query = DB::table($linkedTabName . '_archive');
                                    if ($linkedTabName == "sync_logs") {
                                        $sourcePlatformId = $itemArray['platform_id'];
                                        $data_child_table_query->where('source_platform_id', $sourcePlatformId);
                                    }
                                    $data_child_table = $data_child_table_query->where($relationColumn, $mainTabPrimaryId)->get()->toArray();

                                    //handle child tables
                                    if ($data_child_table) {
                                        foreach ($data_child_table as $child_item) {

                                            $child_itemArray = json_decode(json_encode($child_item), true);
                                            $child_itemArray['created_at'] = date("Y-m-d H:i:s");
                                            $child_itemArray['updated_at'] = date("Y-m-d H:i:s");
                                            //get child table row id to get subchild table data
                                            $childTabPrimaryId = $child_itemArray['id'];

                                            //2 Restore child table & delete records
                                            $restoreChildTable = DB::table($linkedTabName)->insert($child_itemArray);
                                            // DB::table($linkedTabName.'_archive')->where($relationColumn,$mainTabPrimaryId)->delete();
                                            if ($linkedTabName == "sync_logs") {
                                                //manualy delete data from archive bcos no cascadding possible
                                                $sourcePlatformId = $itemArray['platform_id'];
                                                DB::table($linkedTabName . '_archive')->where('source_platform_id', $sourcePlatformId)->where($relationColumn, $mainTabPrimaryId)->delete();
                                            }
                                            // echo" child ------  ".$linkedTabName;
                                            // echo json_encode($child_itemArray).PHP_EOL.PHP_EOL;


                                            // 3 check subChild if exists then Restore subchild table & delete records
                                            if (isset($childTable['subchild'])) {
                                                $subchild_linkedTabName = $childTable['subchild']['linkedTabName'];
                                                $subchild_relationColumn = $childTable['subchild']['relationColumn'];
                                                $data_sub_child_table = DB::table($subchild_linkedTabName . '_archive')->where($subchild_relationColumn, $childTabPrimaryId)->get()->toArray();
                                                if ($data_sub_child_table) {
                                                    foreach ($data_sub_child_table as $sub_child_item) {
                                                        $sub_child_itemArray = json_decode(json_encode($sub_child_item), true);
                                                        $sub_child_itemArray['created_at'] = date("Y-m-d H:i:s");
                                                        $sub_child_itemArray['updated_at'] = date("Y-m-d H:i:s");

                                                        //  echo" Sub child ------ ".$subchild_linkedTabName;
                                                        //  echo json_encode($sub_child_itemArray).PHP_EOL.PHP_EOL;

                                                        $restoreSubChildTable = DB::table($subchild_linkedTabName)->insert($sub_child_itemArray);
                                                        // DB::table($subchild_linkedTabName.'_archive')->where($subchild_relationColumn,$childTabPrimaryId)->delete();

                                                    }
                                                }
                                            }
                                        }
                                    }
                                    //end child loop

                                }
                            }
                            //end DepTablArr = dependent tables loop


                            //delete main table data at the end then all will tables data will be automaticaly delete by cascadding
                            DB::table($tableName . '_archive')->where('id', $item->id)->delete();
                        }

                        //restore Primary table records & delete row
                        if ($restoredItemArr) {
                            return $responseData->id;
                            // echo "Restore Item IDs ".json_encode($restoredItemArr);
                        }
                    } else {
                        return false;
                        //echo "No Record found to restore";
                    }
                }
                //end

            } catch (\Exception $e) {
                throw new \Exception('Error: ' . $e->getMessage());
            }
        } else throw new \Exception("Argument not passed");
    }
}
