<?php

namespace App\Helper;


use DB;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Models\PlatformObjectData;
use App\Models\PlatformDataMapping;
use App\Models\PlatformStates;
use App\Helper\Cache\CacheDecoder;
use Illuminate\Support\Facades\Config;

class FieldMappingHelper
{
    public $helper,$mobj,$cache;

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper();
        $this->cache = new CacheDecoder();
        
    }
    /* Get Mapped Fields By This Below Method */
    public function GetMappedFieldRecord($object_id, $user_integration_id = NULL, $select_field_id = NULL, $by_type = "source_row_id", $platform_workflow_rule_id = NULL, $orderId = null)
    {
        $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'data_map_type' => 'field', 'status' => 1];
        if ($platform_workflow_rule_id) {
            $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
        }
        //dd($conditions);
        $get_mapped_fields = DB::table('platform_data_mapping')->where($conditions)
            ->where(function ($query) use ($select_field_id, $by_type) {
                if ($select_field_id) {
                    $query->where($by_type, $select_field_id);
                }
            })->get();

        $mappings = [];
        if ($get_mapped_fields) {
            foreach ($get_mapped_fields as $mapping_data) {

                $select = ['platform_fields.db_field_name as db_field_name', 'platform_fields.name As field_name', 'platform_fields.custom_field_id', 'platform_fields.custom_field_type', 'platform_fields.id', 'platform_custom_field_values.field_value'];

                $source_row_data = DB::table('platform_fields')->where(['platform_fields.id' => $mapping_data->source_row_id])->select($select)->leftjoin('platform_custom_field_values', function ($join) use ($user_integration_id, $orderId) {
                    $join->on('platform_custom_field_values.platform_field_id', '=', 'platform_fields.id')->where('platform_custom_field_values.user_integration_id', '=', $user_integration_id)->where('platform_custom_field_values.record_id', '=', $orderId);
                })->first();
                $destination_row_data = DB::table('platform_fields')->where(['platform_fields.id' => $mapping_data->destination_row_id])->select($select)->leftjoin('platform_custom_field_values', function ($join) use ($user_integration_id, $orderId) {
                    $join->on('platform_custom_field_values.platform_field_id', '=', 'platform_fields.id')->where('platform_custom_field_values.user_integration_id', '=', $user_integration_id)->where('platform_custom_field_values.record_id', '=', $orderId);
                })->first();


                if ($source_row_data && $destination_row_data) {
                    $arrayData = [
                        'source_field_primary_id' => $source_row_data->id,
                        'source_db_field_name' => $source_row_data->db_field_name,

                        'destination_db_field_name' => $destination_row_data->db_field_name,
                        'source_field_name' => $source_row_data->field_name,

                        'destination_field_primary_id' => $destination_row_data->id,
                        'destination_field_name' => $destination_row_data->field_name,

                        'source_custom_field_id' => $source_row_data->custom_field_id,
                        'destination_custom_field_id' => $destination_row_data->custom_field_id,

                        'source_custom_field_type' => $source_row_data->custom_field_type,
                        'destination_custom_field_type' => $destination_row_data->custom_field_type,
                        'source_custom_field_value' => $source_row_data->field_value,
                        'destination_custom_field_value' => $destination_row_data->field_value
                    ];
                    array_push($mappings, $arrayData);
                }
            }
        }
        return $mappings;
    }
    //new optimized function to get data from db or cache
    public function getMappedField_NEW($user_integration_id, $platform_workflow_rule_id, $object_id, $select = [], $source_field_id = '', $mapping_type = 'regular')
    {
        $mappingObjectName = $this->helper->getObjectNameById($object_id);
        $key = $this->mobj->generateIntegrationCacheKey($user_integration_id, $platform_workflow_rule_id, $mappingObjectName, $mapping_type);

        $find_in_cache = $this->mobj->get_or_set($key);
        if ($find_in_cache) {

            \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedField | From cache key - ' . $key . ' data' . json_encode($find_in_cache) . PHP_EOL . PHP_EOL);

            return $find_in_cache;
        } else {

            //start code to get mapping data from db
            $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'data_map_type' => 'field'];
            if ($platform_workflow_rule_id) {
                $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
            }
            $mapping_data = DB::table('platform_data_mapping')->where($conditions)
                ->where(function ($query) use ($source_field_id) {
                    if ($source_field_id) {
                        $query->where('source_row_id', $source_field_id);
                    }
                })
                ->first();
            $returnRespData = null;
            if ($mapping_data) {
                $source_row_data = DB::table('platform_fields')->join('platform_lookup', 'platform_fields.platform_id', '=', 'platform_lookup.id')->where(['platform_fields.id' => $mapping_data->source_row_id])->select('platform_fields.db_field_name as db_field_name', 'platform_lookup.platform_id as source_platform_id', 'platform_fields.name As field_name', 'platform_fields.custom_field_id', 'platform_fields.custom_field_type')->first();
                $destination_row_data = DB::table('platform_fields')->join('platform_lookup', 'platform_fields.platform_id', '=', 'platform_lookup.id')->where(['platform_fields.id' => $mapping_data->destination_row_id])->select('platform_fields.db_field_name as db_field_name', 'platform_lookup.platform_id as destination_platform_id', 'platform_fields.name As field_name', 'platform_fields.custom_field_id', 'platform_fields.custom_field_type')->first();
                if ($source_row_data && $destination_row_data) {

                    $returnRespData =  array('source_row_data' => $source_row_data->db_field_name, 'destination_row_data' => $destination_row_data->db_field_name, 'destination_platform_id' => $destination_row_data->destination_platform_id, 'source_platform_id' => $source_row_data->source_platform_id, 'source_field_name' => $source_row_data->field_name, 'source_custom_field_id' => $source_row_data->custom_field_id, 'source_custom_field_type' => $source_row_data->custom_field_type, 'destination_field_name' => $destination_row_data->field_name, 'destination_custom_field_id' => $destination_row_data->custom_field_id, 'destination_custom_field_type' => $destination_row_data->custom_field_type);

                    //store mapping data in cache
                    $this->mobj->get_or_set($key, $returnRespData);
                }
            }

            \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedField | From db key - ' . $key . ' data' . json_encode($returnRespData) . PHP_EOL . PHP_EOL);

            return $returnRespData;
        }
    }
    //old function to get mapping data directly from db
    public function getMappedField($user_integration_id, $platform_workflow_rule_id, $object_id, $select = [], $source_field_id = '', $mapping_type = 'regular')
    {
        $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'data_map_type' => 'field','status'=>1];

        if ($platform_workflow_rule_id) {
            $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
        }
        $mapping_data = DB::table('platform_data_mapping')->where($conditions)
            ->where(function ($query) use ($source_field_id) {
                if ($source_field_id) {
                    $query->where('source_row_id', $source_field_id);
                }
            })
            ->first();
        if ($mapping_data) {
            $source_row_data = DB::table('platform_fields')->join('platform_lookup', 'platform_fields.platform_id', '=', 'platform_lookup.id')->where(['platform_fields.id' => $mapping_data->source_row_id])->select('platform_fields.db_field_name as db_field_name', 'platform_lookup.platform_id as source_platform_id', 'platform_fields.name As field_name', 'platform_fields.custom_field_id', 'platform_fields.custom_field_type')->first();

            $destination_row_data = DB::table('platform_fields')->join('platform_lookup', 'platform_fields.platform_id', '=', 'platform_lookup.id')->where(['platform_fields.id' => $mapping_data->destination_row_id])->select('platform_fields.db_field_name as db_field_name', 'platform_lookup.platform_id as destination_platform_id', 'platform_fields.name As field_name', 'platform_fields.custom_field_id', 'platform_fields.custom_field_type')->first();

            if ($source_row_data && $destination_row_data) {
                return array('source_row_data' => $source_row_data->db_field_name, 'destination_row_data' => $destination_row_data->db_field_name, 'destination_platform_id' => $destination_row_data->destination_platform_id, 'source_platform_id' => $source_row_data->source_platform_id, 'source_field_name' => $source_row_data->field_name, 'source_custom_field_id' => $source_row_data->custom_field_id, 'source_custom_field_type' => $source_row_data->custom_field_type, 'destination_field_name' => $destination_row_data->field_name, 'destination_custom_field_id' => $destination_row_data->custom_field_id, 'destination_custom_field_type' => $destination_row_data->custom_field_type);
            }
        }
        return 0;
    }



    //new optimized function to get data from db or cache used in sku-way
    public function getMappedWarehouse_NEW($user_integration_id, $platform_workflow_rule_id, $object_id = '', $select = [], $api_warehouse_id = '', $mapping_type = "default")
    {
        //when object not passed
        if (!$object_id) {
            $get_object_id = $this->helper->getObjectId('warehouse');
            $mappingObjectName = $this->helper->getObjectNameById($get_object_id);
        } else {
            $mappingObjectName = $this->helper->getObjectNameById($object_id);
        }


        $key = $this->mobj->generateIntegrationCacheKey($user_integration_id, $platform_workflow_rule_id, $mappingObjectName, $mapping_type);
        //type of return data either Array or Object

        $find_in_cache = $this->mobj->get_or_set($key);
        if ($find_in_cache) {

            \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedWarehouse | From cache key - ' . $key . ' data' . json_encode($find_in_cache) . PHP_EOL . PHP_EOL);

            return $find_in_cache;
        } else {

            //start code to get data from table
            if (!$object_id) {
                $object_id = $this->helper->getObjectId('warehouse');
                $Warehouse_id = $warehouse_code = '';
                $warehouse = DB::table('platform_object_data')->where(['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id])
                    ->where(function ($query) use ($api_warehouse_id) {
                        $query->where('api_code', '=', $api_warehouse_id)
                            ->orWhere('api_id', '=', $api_warehouse_id);
                    })->select('id')->first();

                if ($warehouse) {
                    $mapping_data = DB::table('platform_data_mapping')->where(['user_integration_id' => $user_integration_id, 'source_row_id' => $warehouse->id])->select('id', 'destination_row_id', 'data_map_type')->first();
                    if ($mapping_data) {
                        if ($mapping_data->data_map_type == 'object') {
                            if ($mapping_data->destination_row_id) {
                                $source_row_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->destination_row_id], ['api_id', 'api_code']);
                                $Warehouse_id = $source_row_data->api_id;
                                $warehouse_code = $source_row_data->api_code;
                            }
                        }
                    }
                }

                $returnRespData = array('Warehouse_id' => $Warehouse_id, 'warehouse_code' => $warehouse_code);
                //store mapping data in cache

                $this->mobj->get_or_set($key, $returnRespData);

                \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedWarehouse | From db key - ' . $key . ' data' . json_encode($returnRespData) . PHP_EOL . PHP_EOL);


                return $returnRespData;
            } else {
                $returnRespData = null;
                $mapping_data = DB::table('platform_data_mapping')->where(['user_integration_id' => $user_integration_id, 'platform_workflow_rule_id' => $platform_workflow_rule_id, 'platform_object_id' => $object_id])->select('source_row_id', 'data_map_type', 'custom_data')->first();

                if ($mapping_data) {
                    if ($mapping_data->data_map_type == 'object') {
                        if ($mapping_data->source_row_id) {
                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->source_row_id], $select);

                            //store mapping data in cache
                            $this->mobj->get_or_set($key, $return);

                            \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedWarehouse | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                            return $return;
                        } else {
                            return $returnRespData;
                        }
                    } else if ($mapping_data->data_map_type == 'custom') {

                        //store mapping data in cache
                        $this->mobj->get_or_set($key, $mapping_data);

                        \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedWarehouse | From db key - ' . $key . ' data' . json_encode($mapping_data) . PHP_EOL . PHP_EOL);

                        return $mapping_data;
                    }
                } else {
                    return $returnRespData;
                }
            }
            //end

        }
    }
    //old function to get mapping data directly from db
    public function getMappedWarehouse($user_integration_id, $platform_workflow_rule_id, $object_id = '', $select = [], $api_warehouse_id = '', $mapping_type = "default")
    {
        if (!$object_id) {
            $object_id = $this->helper->getObjectId('warehouse');
            $Warehouse_id = $warehouse_code = '';
            $warehouse = DB::table('platform_object_data')->where(['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id])
                ->where(function ($query) use ($api_warehouse_id) {
                    $query->where('api_code', '=', $api_warehouse_id)
                        ->orWhere('api_id', '=', $api_warehouse_id);
                })->select('id')->first();
            if ($warehouse) {
                $mapping_data = DB::table('platform_data_mapping')->where(['user_integration_id' => $user_integration_id, 'source_row_id' => $warehouse->id])->select('id', 'destination_row_id', 'data_map_type')->first();
                if ($mapping_data) {
                    if ($mapping_data->data_map_type == 'object') {
                        if ($mapping_data->destination_row_id) {
                            $source_row_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->destination_row_id], ['api_id', 'api_code']);
                            $Warehouse_id = $source_row_data->api_id;
                            $warehouse_code = $source_row_data->api_code;
                        }
                    }
                }
            }
            return array('Warehouse_id' => $Warehouse_id, 'warehouse_code' => $warehouse_code);
        } else {
            $mapping_data = DB::table('platform_data_mapping')->where(['user_integration_id' => $user_integration_id, 'platform_workflow_rule_id' => $platform_workflow_rule_id, 'platform_object_id' => $object_id])->select('source_row_id', 'data_map_type', 'custom_data')->first();
            if ($mapping_data) {
                if ($mapping_data->data_map_type == 'object') {
                    if ($mapping_data->source_row_id) {
                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->source_row_id], $select);
                        return $return;
                    } else {
                        return 0;
                    }
                } else if ($mapping_data->data_map_type == 'custom') {
                    return $mapping_data;
                }
            } else {
                return 0;
            }
        }
    }


    /* Easy find by name */
    public function getMappedDataByName_NEW($user_integration_id, $platform_workflow_rule_id, $object_name = NULL, $select = [], $type = "default", $checkValue = NULL, $returnType = "single", $SourceOrDestination = "source", $destinationSelect = [], $Source_row_id = '')
    {
        $mappingObjectName = $object_name;
        $key = $this->mobj->generateIntegrationCacheKey($user_integration_id, $platform_workflow_rule_id, $mappingObjectName, $type);
        $objectID = $this->helper->getObjectId($object_name);
        if ($objectID) {
            //check data in cache or db
            $find_in_cache = $this->mobj->get_or_set($key);
            if ($find_in_cache) {

                \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From cache key - ' . $key . ' data' . json_encode($find_in_cache) . PHP_EOL . PHP_EOL);

                return $find_in_cache;
            } else {

                //start code to get data from db
                if ($type == "default") { //to Get Single Record based on source
                    $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $objectID, 'mapping_type' => $type, 'status' => "1"];
                    if ($platform_workflow_rule_id != 0 && $platform_workflow_rule_id != null) {
                        $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
                    }
                    $mapping_data = DB::table('platform_data_mapping')->where($conditions)->select('source_row_id', 'data_map_type', 'destination_row_id', 'custom_data', 'id')->first();

                    if ($mapping_data) {
                        if ($mapping_data->data_map_type == 'object') {
                            if ($mapping_data->destination_row_id) {
                                $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->destination_row_id, 'status' => 1], $select);

                                //store mapping data in cache
                                $this->mobj->get_or_set($key, $return);

                                \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                                return $return;
                            } else if ($mapping_data->source_row_id) {
                                $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->source_row_id, 'status' => 1], $select);

                                //store mapping data in cache
                                $this->mobj->get_or_set($key, $return);

                                \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                                return $return;
                            } else {
                                return false;
                            }
                        } else if ($mapping_data->data_map_type == 'custom') {
                            $return = $this->mobj->getFirstResultByConditions('platform_data_mapping', ['id' => $mapping_data->id], $select);

                            //store mapping data in cache
                            $this->mobj->get_or_set($key, $return);

                            \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                            return $return;
                        }
                    } else {
                        return false;
                    }
                } else {

                    $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $objectID, 'mapping_type' => $type, 'status' => "1"];
                    if ($platform_workflow_rule_id != 0 && $platform_workflow_rule_id != null) {
                        $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
                    }
                    if ($Source_row_id) {
                        $conditions['source_row_id'] = $Source_row_id;
                    }
                    $mapping_data = DB::table('platform_data_mapping')->where($conditions)->select('source_row_id', 'data_map_type', 'destination_row_id', 'custom_data', 'id')->get();


                    if ($returnType == "single") {

                        if (count($mapping_data) > 0) {
                            foreach ($mapping_data as $keys => $value) {

                                if ($value->data_map_type == 'object') {
                                    if ($SourceOrDestination == 'source') {

                                        if ($value->destination_row_id) {

                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->source_row_id, 'status' => 1], $select);
                                            if (isset($return)) {
                                                $resValue = (array) $return;

                                                if (isset($resValue[$select[0]])) {

                                                    if ($resValue[$select[0]] == $checkValue) {
                                                        if (!empty($destinationSelect)) {

                                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $destinationSelect);
                                                        } else {

                                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $select);
                                                        }

                                                        //store mapping data in cache
                                                        $this->mobj->get_or_set($key, $return);

                                                        \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                                                        return $return;
                                                    }
                                                }
                                            }
                                        } else {
                                            return false;
                                        }
                                    } else {

                                        if ($value->source_row_id) {

                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $select);
                                            if (isset($return)) {
                                                $resValue = (array) $return;

                                                if (isset($resValue[$select[0]])) {

                                                    if ($resValue[$select[0]] == $checkValue) {
                                                        if (!empty($destinationSelect)) {

                                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->source_row_id, 'status' => 1], $destinationSelect);
                                                        } else {

                                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->source_row_id, 'status' => 1], $select);
                                                        }

                                                        //store mapping data in cache
                                                        $this->mobj->get_or_set($key, $return);

                                                        \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                                                        return $return;
                                                    }
                                                }
                                            }
                                        } else {
                                            return false;
                                        }
                                    }
                                } elseif ($value->data_map_type == 'object_and_custom') {
                                    if ($value->custom_data) {

                                        //store mapping data in cache
                                        $customData = $value->custom_data;
                                        $this->mobj->get_or_set($key, $customData);

                                        \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($customData) . PHP_EOL . PHP_EOL);

                                        return $customData;
                                    }
                                } else if ($value->data_map_type == 'custom_and_object') {

                                    if ($value->custom_data && $value->destination_row_id) {

                                        if ($value->custom_data == $checkValue) {
                                            if (!empty($destinationSelect)) {

                                                $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $destinationSelect);
                                            } else {

                                                $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $select);
                                            }

                                            //store mapping data in cache
                                            $this->mobj->get_or_set($key, $return);

                                            \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($return) . PHP_EOL . PHP_EOL);

                                            return $return;
                                        }
                                    } else {
                                        return false;
                                    }
                                }
                            }
                        } else {
                            return false;
                        }
                    } else {
                        /* For multiple */

                        if (count($mapping_data) > 0) {
                            $array = [];
                            $fieldArray = [];

                            foreach ($mapping_data as $keys => $value) {
                                if ($value->data_map_type == 'object') {
                                    if ($SourceOrDestination == "source") {
                                        array_push($array, $value->source_row_id);
                                    } else {
                                        array_push($array, $value->destination_row_id);
                                    }
                                } elseif ($value->data_map_type === 'field') {
                                    if ($SourceOrDestination == "source") {
                                        array_push($fieldArray, $value->source_row_id);
                                    } else {
                                        array_push($fieldArray, $value->destination_row_id);
                                    }
                                }
                            }
                            if (!empty($array)) {

                                $dataArray = DB::table('platform_object_data')->select($select)->where('status', 1)->whereIn('id', $array)->pluck($select[0])->toArray();

                                //store mapping data in cache
                                $this->mobj->get_or_set($key, $dataArray);

                                \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($dataArray) . PHP_EOL . PHP_EOL);

                                return $dataArray;
                            }

                            if (!empty($fieldArray)) {

                                $dataArray =  DB::table('platform_fields')->select($select)->where('status', 1)->whereIn('id', $fieldArray)->pluck($select[0])->toArray();

                                //store mapping data in cache
                                $this->mobj->get_or_set($key, $dataArray);

                                \Storage::disk('local')->append('field_mapping_helper_log.txt', 'Method - getMappedDataByName | From db key - ' . $key . ' data' . json_encode($dataArray) . PHP_EOL . PHP_EOL);

                                return $dataArray;
                            }
                            return false;
                        } else {
                            return false;
                        }
                    }
                }
                //end

            }
        }
        return false;
    }

    public function getSourceDestinationMappedDataByName($user_integration_id, $object_name = NULL, $type = "regular")
    {
        $objectID = $this->helper->getObjectId($object_name);
        $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $objectID, 'mapping_type' => $type, 'status' => "1"];
        $mapping_data = PlatformDataMapping::where($conditions)->select('source_row_id', 'destination_row_id', 'id')->get();
        if ($mapping_data) {
            return $mapping_data;
        }
        return false;
    }

    /* Easy find by name */
    public function getMappedDataByName($user_integration_id, $platform_workflow_rule_id, $object_name = NULL, $select = [], $type = "default", $checkValue = NULL, $returnType = "single", $SourceOrDestination = "source", $destinationSelect = [], $Source_row_id = null)
    {
        $objectID = $this->helper->getObjectId($object_name);
        if ($objectID) {
            if ($type == "default") { //to Get Single Record based on source
                $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $objectID, 'mapping_type' => $type, 'status' => "1"];
                if ($platform_workflow_rule_id != 0 && $platform_workflow_rule_id != null) {
                    $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
                }

                $mapping_data = DB::table('platform_data_mapping')->where($conditions)->select('source_row_id', 'data_map_type', 'destination_row_id', 'custom_data', 'id')->first();

                if ($mapping_data) {
                    if ($mapping_data->data_map_type == 'object') {
                        if ($mapping_data->destination_row_id) {
                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->destination_row_id, 'status' => 1], $select);
                            return $return;
                        } else if ($mapping_data->source_row_id) {
                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $mapping_data->source_row_id, 'status' => 1], $select);
                            return $return;
                        } else {
                            return false;
                        }
                    } else if ($mapping_data->data_map_type == 'custom' || $mapping_data->data_map_type == 'timezone') {
                        $return = $this->mobj->getFirstResultByConditions('platform_data_mapping', ['id' => $mapping_data->id], $select);
                        return $return;
                    }
                } else {
                    return false;
                }
            } else {
                $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $objectID, 'mapping_type' => $type, 'status' => "1"];
                if ($platform_workflow_rule_id != 0 && $platform_workflow_rule_id != null) {
                    $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
                }
                if ($Source_row_id) {
                    $conditions['source_row_id'] = $Source_row_id;
                }
                $mapping_data = DB::table('platform_data_mapping')->where($conditions)->select('source_row_id', 'data_map_type', 'destination_row_id', 'custom_data', 'id')->get();


                if ($returnType == "single") {

                    if (count($mapping_data) > 0) {
                        foreach ($mapping_data as $key => $value) {

                            if ($value->data_map_type == 'object') {
                                if ($SourceOrDestination == 'source') {

                                    if ($value->destination_row_id) {

                                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->source_row_id, 'status' => 1], $select);
                                        if (isset($return)) {
                                            $resValue = (array) $return;

                                            if (isset($resValue[$select[0]])) {

                                                if (trim($resValue[$select[0]]) == trim($checkValue)) {
                                                    if (!empty($destinationSelect)) {

                                                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $destinationSelect);
                                                    } else {

                                                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $select);
                                                    }
                                                    return $return;
                                                }
                                            }
                                        }
                                    } else {
                                        return false;
                                    }
                                } else {

                                    if ($value->source_row_id) {

                                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $select);
                                        if (isset($return)) {
                                            $resValue = (array) $return;

                                            if (isset($resValue[$select[0]])) {

                                                if (trim($resValue[$select[0]]) == trim($checkValue)) {
                                                    if (!empty($destinationSelect)) {

                                                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->source_row_id, 'status' => 1], $destinationSelect);
                                                    } else {

                                                        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->source_row_id, 'status' => 1], $select);
                                                    }
                                                    return $return;
                                                }
                                            }
                                        }
                                    } else {
                                        return false;
                                    }
                                }
                            } elseif ($value->data_map_type == 'object_and_custom') {
                                if ($value->custom_data && $Source_row_id) {
                                    return $value->custom_data;
                                } else {
                                    /* for cross mapping | don't change this method with permission | any kind of changes causes issue in live/stag interations */
                                    if ($SourceOrDestination == 'source') {
                                        $source_destination_id = $value->source_row_id;
                                    } else {
                                        $source_destination_id = $value->destination_row_id;
                                    }
                                    $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $source_destination_id, 'status' => 1], $select);

                                    if (isset($return)) {
                                        $resValue = (array) $return;

                                        if (isset($resValue[$select[0]])) {

                                            if (trim($resValue[$select[0]]) == trim($checkValue)) {
                                                return $value;
                                            }
                                        }
                                    }
                                }
                            } else if ($value->data_map_type == 'custom_and_object') {

                                if ($value->custom_data && $value->destination_row_id) {

                                    if (trim($value->custom_data) == trim($checkValue)) {
                                        if (!empty($destinationSelect)) {

                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $destinationSelect);
                                        } else {

                                            $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $value->destination_row_id, 'status' => 1], $select);
                                        }
                                        return $return;
                                    }
                                } else {
                                    return false;
                                }
                            }
                        }
                    } else {
                        return false;
                    }
                } else {
                    /* For multiple */

                    if (count($mapping_data) > 0) {
                        $array = [];
                        $fieldArray = [];

                        foreach ($mapping_data as $key => $value) {
                            if ($value->data_map_type == 'object') {
                                if ($SourceOrDestination == "source") {
                                    array_push($array, $value->source_row_id);
                                } else {
                                    array_push($array, $value->destination_row_id);
                                }
                            } elseif ($value->data_map_type === 'field') {
                                if ($SourceOrDestination == "source") {
                                    array_push($fieldArray, $value->source_row_id);
                                } else {
                                    array_push($fieldArray, $value->destination_row_id);
                                }
                            }
                        }

                        if (!empty($array)) {


                            return DB::table('platform_object_data')->select($select)->where('status', 1)->whereIn('id', $array)->pluck($select[0])->toArray();
                        }

                        if (!empty($fieldArray)) {

                            return DB::table('platform_fields')->select($select)->where('status', 1)->whereIn('id', $fieldArray)->pluck($select[0])->toArray();
                        }
                        return false;
                    } else {
                        return false;
                    }
                }
            }
        }
        return false;
    }
   


    /* This method is used when we have many to one warehouse mapping */
    public function getManyToOneWarehouseMapping($objectID, $user_integration_id, $has_default_warehouse_mapping = false, $user_id = null, $platform_id = null, $mapping_type = 'regular', $data_map_type = 'object')
    {
        $array_bundle = [];
        $pltfrmObjData = new PlatformObjectData();
        if (!$has_default_warehouse_mapping) {
            $mapping_data = PlatformDataMapping::where([
                'user_integration_id' => $user_integration_id,
                'platform_object_id' => $objectID,
                'mapping_type' => $mapping_type,
                'data_map_type' => $data_map_type,
                'status' => "1"
            ])->select('source_row_id',  'destination_row_id', 'id')->get();
            if (count($mapping_data) > 0) {
                foreach ($mapping_data as $key => $value) {
                    $objectQuery = $pltfrmObjData->select('id', 'api_id');
                    $findSourceData = $objectQuery->where(['id' => $value->source_row_id, 'status' => 1])->first();
                    if ($findSourceData) {
                        $findDestinationData = $pltfrmObjData->select('api_id')->where(['id' => $value->destination_row_id, 'status' => 1])->first();
                        if (isset($findDestinationData->api_id)) {
                            $array_bundle[$findDestinationData->api_id][] = $findSourceData->api_id;
                        }
                    }
                }

                $return_value = ['mapped_warehouse' => $array_bundle];
            } else {
                $return_value = false;
            }
        } else {
            /* source platform warehouse ids */

            $warehouseList = $pltfrmObjData->where([
                'user_id' => $user_id,
                'platform_id' => $platform_id,
                'user_integration_id' => $user_integration_id,
                'platform_object_id' => $objectID,
                'status' => 1
            ])->pluck('api_id')->toArray();
            $array_bundle[1] = $warehouseList;
            $return_value = ['mapped_warehouse' => $array_bundle];
        }

        return $return_value;
    }



    /* Find ObectData by ID */
    public function getObjectDataByID($PrimaryID, $select = [])
    {
        $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $PrimaryID, 'status' => 1], $select);
        return $return;
    }
    /* Find ObjectData by selected field data || Please use this method carefully*/
    public function getObjectDataByFilterData($user_id, $user_integration_id, $platform_id, $platform_object_id, $field, $fieldValue, $select = [], $operator = "=", $operator_argument = NULL, $wildcardPosition = NULL)
    {
        //  $return = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id,'platform_object_id'=>$platform_object_id,$field => $fieldValue,'status'=>1], $select);
        $return = false;
        if (is_array($select)) {
            //$select = implode(', ', $select);
            $q = PlatformObjectData::select($select)->where([
                ['user_id', '=', $user_id],
                ['user_integration_id', '=', $user_integration_id],
                ['platform_id', '=', $platform_id],
                ['platform_object_id', '=', $platform_object_id],
                ['status', '=', 1]
            ]);
            if (strtolower($operator) == "like") {
                if ($wildcardPosition) {
                    if ($wildcardPosition == "first") {
                        //if first wild card position
                        $q->where($field, $operator, $operator_argument . $fieldValue);
                    } elseif ($wildcardPosition == "last") {
                        //if last wild card position
                        $q->where($field, $operator, $fieldValue . $operator_argument);
                    } else {
                        //if both wild card position
                        $q->where($field, $operator, $operator_argument . $fieldValue . $operator_argument);
                    }
                } else {
                    //if no wild card position mention
                    $q->where($field, $operator, $operator_argument . $fieldValue . $operator_argument);
                }
            } else {
                $q->where($field, $operator, $fieldValue);
            }

            $return = $q->first();
        }


        return $return;
    }
    public function ValidatePlatform($source_platform_id, $destination_platform_id, $platform_name)
    {
        if ($source_platform_id == $platform_name || $destination_platform_id == $platform_name) {
            return 1;
        }
        return 0;
    }
    public function getMappedApiIdByObjectId($userIntegrationId, $objId, $mapType = 'default', $selectField = 'api_id')
    {
        $respData = null;
        //get Selected Supplier Or Other Filter Data
        $maping_data = DB::table('platform_data_mapping')->where('user_integration_id', $userIntegrationId)->where('platform_object_id', $objId)
            ->where('mapping_type', $mapType)->where('status', 1)->select('source_row_id', 'destination_row_id')->first();

        if ($maping_data) {
            // $supplierFilter = true;
            if ($maping_data->source_row_id) {
                $mapObjDataId = $maping_data->source_row_id;
            } else {
                $mapObjDataId = $maping_data->destination_row_id;
            }
            //Get Api Id for that selected Filter object ex. supplier
            $respData = DB::table('platform_object_data')->where('id', $mapObjDataId)->pluck($selectField)->first();
        }

        return $respData;
    }
    public function getDataRetentionbyIntegration($user_integration_id)
    {
        date_default_timezone_set('UTC');

        $dataRetentionRow = DB::table('user_integrations as ui')->join('platform_integrations as pi', 'ui.platform_integration_id', 'pi.id')
            // ->where('ui.data_retention_status',1)
            ->where('pi.data_retention_status', 1)->where('ui.id', $user_integration_id)
            ->select('ui.data_retention_period as ui_drp', 'pi.data_retention_period as pi_drp')->first();

        return $dataRetentionRow;
    }

    public function getObjectDataByObjectName($user_integration_id, $object_name, $search_field = '', $search_value = '', $select = [])
    {
        $res = DB::table('platform_object_data as pod')
            ->join("platform_objects as po", function ($join) {
                $join->on("pod.platform_object_id", "=", "po.id");
            })->where(['po.name' => $object_name, 'po.status' => 1, 'pod.user_integration_id' => $user_integration_id, $search_field => $search_value])->select($select)->first();

        return $res;
    }

    //get user integrations detail by user integration id from cache or db
    public function getUserIntegrationDetailsById($userIntegrationId, $platform)
    {
        if(env('ALLOW_CACHE') == true && isset(Config::get('accesscontrolsetting.AllowIntegrationDataFromCache')[$platform])){
            if($this->integrationCache($userIntegrationId, 0)){
                return null;
            }
            $cacheResult = $this->cache->getIntegrationDetailsFromCache($userIntegrationId);
            $key = $cacheResult['key'];
            if($cacheResult['data']) {
                $data =  $cacheResult['data'];
            } else {
                $integration = $this->getIntegrationDetailsFromDB($userIntegrationId);
                if(!$integration){
                    $data = $this->integrationCache($userIntegrationId, 1); //set inactive cache & retun null
                }elseif($integration->workflow_status !='active'){
                    $data = $this->integrationCache($userIntegrationId, 1); //set inactive cache & retun null
                }else{
                    $this->cache->get_or_set($key, json_encode($integration), 7200, null);
                    $data = $integration;
                }
            }
        }else{
            $data = $this->getIntegrationDetailsFromDB($userIntegrationId);
        }
       
       return $data;
    }

    public function getIntegrationDetailsFromDB($userIntegrationId){
        $integration = $this->mobj->getFirstResultByConditions('user_integrations', ['id'=>$userIntegrationId], ['user_id', 'platform_integration_id', 'selected_sc_account_id', 'selected_dc_account_id','workflow_status'], []);
        return $integration;
    }
    public function integrationCache($userIntegrationId, $set=0){
        $status = null;
        $key =  $this->cache->generateCacheKey($userIntegrationId, 'inactive');
        if($set){
            $this->cache->get_or_set($key, 1, 7200, null);
            $status = null;
        }else{
            $status =  $this->cache->get_or_set($key, $value = null, $seconds = null, $cache_type = null);
        }
        return $status;
    }

    //get product user integrations detail by user integration id from cache or db
    public function getIntegProductById($userIntegrationId, $product_id, $event_name, $platform) 
    {
        if(env('ALLOW_CACHE') == true && isset(Config::get('accesscontrolsetting.AllowProductDataFromCache')[$platform])){
            $cacheResult = $this->cache->checkProductInCache($userIntegrationId, $product_id,$event_name); //$event_name like product.created, product.modified, store/product/created
            $key = $cacheResult['key'];
            if($cacheResult['data']) {
                return $cacheResult['data'];
            } else {
                $this->cache->get_or_set($key, 1, 6, null); //6 sec cache valid time 
                return false;
            }
       }else{
            return false;
       }
    }

    //get user workflow detail by user integration id from cache or db
    public function getUserIntegWorkFlow($userIntegrationId, $event_id, $fields, $platform){
          $data = null;
          if(env('ALLOW_CACHE') == true && isset(Config::get('accesscontrolsetting.AllowFlowDataFromCache')[$platform])){
            $cacheResult = $this->cache->getWorkFlowDataFromCache($userIntegrationId);
            $key = $cacheResult['key'];
            if($cacheResult['data'] && isset($cacheResult['data'][$event_id])) {
                $data =  $cacheResult['data'];
            }else{
                   $data = $this->getUserWorkFlowDataFromDB($userIntegrationId, $event_id, $fields);
                   if($cacheResult['data']){
                        $data = array_merge($data,$cacheResult['data']);
                   }
                   $this->cache->get_or_set($key, json_encode($data,true), 10800, null); //10800 seconds (3 hour) 
            }
          }else{
            $data = $this->getUserWorkFlowDataFromDB($userIntegrationId, $event_id, $fields);
          }
          return $data;
    }

    public function getUserWorkFlowDataFromDB($userIntegrationId, $event_id, $fields){
        $finalData = [];
        $q = DB::table('user_workflow_rule as ur')->select($fields)
            ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
            ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
            ->where('ur.user_integration_id', $userIntegrationId)
            ->where('e.event_id', $event_id);
        if ($q->count() > 0) {
            $q_result = [];
            foreach($fields as $field){
                $index = strpos($field, ".") + 1; //strpos() return index postion of . in given string
                $columnName = substr($field, $index); //  substr() extract a portion of a string & second param is start position
                if($field == 'e.event_id'){
                    $eventName = $q->pluck($field)->first(); // $eventName like GET_SALESORDER, GET_REFUND, GET_GOODSINNOTE, GET_INVOICE etc.
                }else{
                    $q_result[$columnName] = $q->pluck($field)->first();
                    $finalData[$eventName] = $q_result;
                }
            }
        }
        return $finalData;
    }
}
