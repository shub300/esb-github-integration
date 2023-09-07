<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Common;

class MappingHelper
{

    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function getMappedField($user_integration_id,$platform_object_id)
    {
        $mapping_data = $this->mobj->getFirstResultByConditions('platform_data_mapping', ['user_integration_id' => $user_integration_id,
        'data_map_type' =>'field','platform_object_id'=> $platform_object_id,'mapping_type'=>'regular' ], ['source_row_id', 'destination_row_id']);
        if ($mapping_data) {
            $source_row_data = $this->mobj->getFirstResultByConditions('platform_fields', ['id' => $mapping_data->source_row_id], ['db_field_name','platform_id']);
            $destination_row_data = $this->mobj->getFirstResultByConditions('platform_fields', ['id' => $mapping_data->destination_row_id], ['db_field_name','platform_id']);
            
            $sourcePlt = $this->mobj->getFirstResultByConditions('platform_lookup', ['id' => $source_row_data->platform_id], ['platform_id']);
            $destPlt = $this->mobj->getFirstResultByConditions('platform_lookup', ['id' => $destination_row_data->platform_id], ['platform_id']);
           
            return array('source_row_data' => $source_row_data->db_field_name, 'destination_row_data' => $destination_row_data->db_field_name,
            'source_platform_id' => $source_row_data->platform_id, 'dest_platform_id' => $destination_row_data->platform_id,
            'sourcePlt'=>$sourcePlt->platform_id,'destPlt'=>$destPlt->platform_id);
        }
        return 0;
    }

    public function getMappedWarehouse($user_id, $api_warehouse_id, $user_integration_id, $platform_workflow_rule_id)
    {
        $Warehouse_id = '';
        $Warehouse_data = $this->mobj->getFirstResultByConditions('platform_warehouse', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'api_warehouse_code' => $api_warehouse_id], ['id']);
        if ($Warehouse_data) {
            $Warehouse_mapp_data = $this->mobj->getFirstResultByConditions('platform_data_mapping', ['source_row_id' => $Warehouse_data->id, 'platform_workflow_rule_id' => $platform_workflow_rule_id, 'mapping_type' => 'inventory'], ['id', 'destination_row_id']);
            if ($Warehouse_mapp_data) {
                $map_data = $this->mobj->getFirstResultByConditions('platform_warehouse', ['id' => $Warehouse_mapp_data->destination_row_id], ['api_warehouse_id']);
                if ($map_data) {
                    $Warehouse_id = $map_data->api_warehouse_id;
                }
            }
        }
        return $Warehouse_id;
    }

    public function ValidatePlatform($source_platform_id,$destination_platform_id,$platform_name){
         if($source_platform_id == $platform_name || $destination_platform_id == $platform_name ){
             return 1;
         }
         return 0;
    }
}
