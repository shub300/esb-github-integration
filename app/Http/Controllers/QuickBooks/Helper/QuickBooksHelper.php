<?php

namespace App\Http\Controllers\QuickBooks\Helper;

use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use App\Models\PlatformDataMapping;
use App\Models\PlatformObjectData;
use App\Models\PlatformStates;

class QuickBooksHelper extends MainModel
{
    public $helper;

    public function __construct()
    {
        $this->helper = new ConnectionHelper;
    }

    /* Custom Field Mapping For State Vs Other Field | Only Handled for source part*/
    public function getCustomMappingForState($state, $user_integration_id, $platform_workflow_rule_id, $object_name)
    {
        $response = false;
        $objectID = $this->helper->getObjectId($object_name);
        $conditions = ['user_integration_id' => $user_integration_id, 'platform_object_id' => $objectID, 'mapping_type' => 'cross', 'status' => "1"];
        if ($platform_workflow_rule_id != 0 && $platform_workflow_rule_id != null) {
            $conditions['platform_workflow_rule_id'] = $platform_workflow_rule_id;
        }

        $stateIds = PlatformDataMapping::where($conditions)->select('source_row_id', 'destination_row_id')->pluck('destination_row_id', 'source_row_id')->toArray();
        if ($stateIds) {
            $keys = array_keys($stateIds);
            $findState = PlatformStates::select('id')->where('iso2', $state)->whereIn('id', $keys)->first();
            if ($findState) {
                if (isset($stateIds[$findState->id])) {
                    $destinationId = $stateIds[$findState->id];
                    $result = PlatformObjectData::where(['id' => $destinationId, 'status' => 1])->select('api_id')->first();
                    if ($result) {
                        $response = $result->api_id;
                    }
                }
            }
        }
        return $response;
    }
}
