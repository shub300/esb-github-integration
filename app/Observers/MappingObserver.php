<?php

namespace App\Observers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\History;
use App\Models\PlatformDataMapping;

use App\Models\PlatformObject;
use App\Models\PlatformObjectData;
use App\Models\PlatformField;
use App\Models\PlatformStates;


use Illuminate\Support\Facades\App;

class MappingObserver
{
    public $afterCommit = true;

    /**
     * Handle the PlatformDataMapping "created" event.
     *
     * @param  \App\Models\PlatformDataMapping  $PlatformDataMapping
     * @return void
     */
    public function creating(PlatformDataMapping $PlatformDataMapping) 
    {
        // $oldData = $PlatformDataMapping->getOriginal();
        // $newData = $PlatformDataMapping->getAttributes();
        // dd($oldData, $newData);
    }
    public function created(PlatformDataMapping $PlatformDataMapping)
    {
        $user_integration_id = "";
        $newData = $PlatformDataMapping->getAttributes();
        
        //formate new data
        if( isset($newData) ) {
           $formated_data =  $this->formateMappingData($newData);
           if($formated_data) {
                $newData = $formated_data['data'];
                $user_integration_id = $formated_data['user_integration_id'];
           } 
        }
        
        History::create([
            'action' => 'Mapping Added',
            'action_by' => Auth::user()->id,
            'user_integration_id' => $user_integration_id, // The name of the table being updated
            'old_data' => NULL, // Previous data before update
            'new_data' => json_encode($newData) // New data after update
        ]); 

 
    }

    /**
     * Handle the PlatformDataMapping "updated" event.
     *
     * @param  \App\Models\PlatformDataMapping  $PlatformDataMapping
     * @return void
     */

    //this method can be use before update...
    public function updating(PlatformDataMapping $PlatformDataMapping)
    {   
        // $user_integration_id = "";
        // $oldData = $PlatformDataMapping->getOriginal();
        // $newData = $PlatformDataMapping->getAttributes();

        // //formate new data
        // if( isset($newData) ) {
        //    $formated_data =  $this->formateMappingData($newData);
        //     if($formated_data) {
        //         $newData = $formated_data['data'];
        //         $user_integration_id = $formated_data['user_integration_id'];
        //     } 
        // }
        // //formate old data
        // if( isset($oldData) ) {
        //     $formated_data =  $this->formateMappingData($oldData);
        //     if($formated_data) {
        //         $oldData = $formated_data['data'];
        //         $user_integration_id = $formated_data['user_integration_id'];
        //     } 
        // }

        // if ($oldData !== $newData) {
        
        //     History::create([
        //         'action' => 'Mapping Update',
        //         'action_by' => Auth::user()->id,
        //         'user_integration_id' => $user_integration_id, 
        //         'old_data' => json_encode($oldData), // Previous data before update
        //         'new_data' => json_encode($newData) // New data after update
        //     ]);

        // }
        
    }
    public function updated(PlatformDataMapping $PlatformDataMapping)
    {   
        $user_integration_id = "";
        $oldData = $PlatformDataMapping->getOriginal();
        $newData = $PlatformDataMapping->getAttributes();

        //formate new data
        if( isset($newData) ) {
           $formated_data =  $this->formateMappingData($newData);
            if($formated_data) {
                $newData = $formated_data['data'];
                $user_integration_id = $formated_data['user_integration_id'];
            } 
        }
        //formate old data
        if( isset($oldData) ) {
            $formated_data =  $this->formateMappingData($oldData);
            if($formated_data) {
                $oldData = $formated_data['data'];
                $user_integration_id = $formated_data['user_integration_id'];
            } 
        }

        if ($oldData !== $newData) {
        
            History::create([
                'action' => 'Mapping Update',
                'action_by' => Auth::user()->id,
                'user_integration_id' => $user_integration_id, 
                'old_data' => json_encode($oldData), // Previous data before update
                'new_data' => json_encode($newData) // New data after update
            ]);

        }    

    }

    /**
     * Handle the PlatformDataMapping "deleted" event.
     *
     * @param  \App\Models\PlatformDataMapping  $PlatformDataMapping
     * @return void
     */
    public function deleting(PlatformDataMapping $PlatformDataMapping)
    {
        // $oldData = $PlatformDataMapping->getOriginal();
        // dd($oldData,'yes its here');
    }
    public function deleted(PlatformDataMapping $PlatformDataMapping)
    {
        $user_integration_id = "";
        $oldData = $PlatformDataMapping->getOriginal();

        //formate old data
        if( isset($oldData) ) {
            $formated_data =  $this->formateMappingData($oldData);
            if($formated_data) {
                $oldData = $formated_data['data'];
                $user_integration_id = $formated_data['user_integration_id'];
            } 
        }


        History::create([
            'action' => 'Mapping Delete',
            'action_by' => Auth::user()->id,
            'user_integration_id' => $user_integration_id, 
            'old_data' => json_encode($oldData), // Previous data before update
            'new_data' => NULL 
        ]);
    }

    /**
     * Handle the PlatformDataMapping "restored" event.
     *
     * @param  \App\Models\PlatformDataMapping  $PlatformDataMapping
     * @return void
     */
    public function restored(PlatformDataMapping $PlatformDataMapping)
    {
        //
    }

    /**
     * Handle the PlatformDataMapping "force deleted" event.
     *
     * @param  \App\Models\PlatformDataMapping  $PlatformDataMapping
     * @return void
     */
    public function forceDeleted(PlatformDataMapping $PlatformDataMapping)
    {
        //
    }


    //formate mapping data for history...
    public function formateMappingData($data)
    {   

        $response_data = [];

        //get platform object name
        if( isset($data['platform_object_id']) ) {
            $object_data = PlatformObject::where('id',$data['platform_object_id'])->select('name','display_name')->first();
            if($object_data) {
                $data['mapping_object_name'] = $object_data->name;
                $data['mapping_object_display_name'] = $object_data->display_name;
            }
        }
        //end
  
        //Get platform details
        $sourcePlt = $destPlt = NULL;
        if( isset($data['platform_workflow_rule_id']) ) {
            $dataPltInteg = DB::table('platform_workflow_rule as pwfr')->join('platform_integrations as pfInt','pfInt.id','pwfr.platform_integration_id')
            ->join('platform_lookup as pl1','pl1.id','pfInt.source_platform_id')
            ->join('platform_lookup as pl2','pl2.id','pfInt.destination_platform_id')
            ->where('pwfr.id',$data['platform_workflow_rule_id'])
            ->select('pl1.platform_name as sourcePlt','pl2.platform_name as destPlt')->first();
            
            if($dataPltInteg){
                $sourcePlt = $dataPltInteg->sourcePlt;
                $destPlt = $dataPltInteg->destPlt;
            }
        }


        //set dynamic model name to get data
        if( isset($data['data_map_type']) && $data['data_map_type'] =="field" ) {
            $sourcemodelName = 'PlatformField';
            $destmodelName = 'PlatformField';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="object" ) {
            $sourcemodelName = 'PlatformObjectData';
            $destmodelName = 'PlatformObjectData';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="object_and_custom" ) {
            $sourcemodelName = 'PlatformObjectData';
            $destmodelName = NULL;
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="custom_and_object" ) {
            $sourcemodelName = NULL;
            $destmodelName = 'PlatformObjectData';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="field_and_custom" ) {
            $sourcemodelName = 'PlatformField';
            $destmodelName = NULL;
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="custom_and_field" ) {
            $sourcemodelName = NULL;
            $destmodelName = 'PlatformField';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="timezone" ) {
            $sourcemodelName = NULL;
            $destmodelName = NULL;
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="state_and_object" ) {
            $sourcemodelName = 'PlatformStates';
            $destmodelName = 'PlatformObjectData';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="object_and_state" ) {
            $sourcemodelName = 'PlatformObjectData';
            $destmodelName = 'PlatformStates';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="field_and_object" ) {
            $sourcemodelName = 'PlatformField';
            $destmodelName = 'PlatformObjectData';
        } else if( isset($data['data_map_type']) && $data['data_map_type'] =="object_and_field" ) {
            $sourcemodelName = 'PlatformObjectData';
            $destmodelName = 'PlatformField';
        } else {
            $sourcemodelName = NULL;
            $destmodelName = NULL;
        }
        //end


        
        // Create an instance of the dynamic model
        $sourceDynamicModel = NULL;
        if($sourcemodelName) {
            $sourceDynamicModel = App::make('App\\Models\\' . $sourcemodelName);
        } 
        $destDynamicModel = NULL;
        if($destmodelName) {
            $destDynamicModel = App::make('App\\Models\\' . $destmodelName);
        }
        //end dynamic model creation
        

        //handle source mapping values
        if( $sourceDynamicModel && $data['source_row_id'] && $data['source_row_id'] !=null && $data['source_row_id'] !="null" ) {
            $source_map_val = $sourceDynamicModel::where('id',$data['source_row_id'])->select('name')->pluck('name')->first();
            if($source_map_val) {
                $data['source_mapping_value'] = $source_map_val." (".$sourcePlt.")";
            } else {
                $data['source_mapping_value'] = $data['source_row_id']; 
            }
        }
        //handle destination mapping values
        if( $destDynamicModel && $data['destination_row_id'] && $data['destination_row_id'] !=null && $data['destination_row_id'] !="null" ) {
            $dest_map_val = $destDynamicModel::where('id',$data['destination_row_id'])->select('name')->pluck('name')->first();
            if($dest_map_val) {
                $data['destination_mapping_value'] = $dest_map_val." (".$destPlt.")";
            } else {
                $data['destination_mapping_value'] = $data['destination_row_id'];
            }
        }
        //handle custom mapping value
        if(isset($data['custom_data']) && $data['custom_data'] && $data['custom_data'] !=null && $data['custom_data'] !="null") {
            $data['custom_mapping_value'] = $data['custom_data'];
        } 

        //add in response
        $user_integration_id = "";
        if( isset($data['user_integration_id'])) {
            $user_integration_id = $data['user_integration_id'];
        } 
        $response_data['user_integration_id'] = $user_integration_id;

        

        // List of keys to keep  'data_map_type','mapping_type','source_row_id','destination_row_id','custom_data','status','created_at','updated_at'
        $selectedKeys = ['mapping_object_name','mapping_object_display_name','source_mapping_value','destination_mapping_value','custom_mapping_value'];
        $data = array_filter(
            $data,
            function ($key) use ($selectedKeys) {
                return in_array($key, $selectedKeys);
            },
            ARRAY_FILTER_USE_KEY
        );
        // end fileter data

        //add mapping data in response
        $response_data['data'] = $data;


        return $response_data;

    }


}
