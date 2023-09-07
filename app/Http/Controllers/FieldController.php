<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Helper\WorkflowSnippet;
use App\Helper\MainModel;
use DB;

class FieldController extends Controller
{
    public $wfsnip,$mobj;
    public function __construct()
    {
        $this->wfsnip = new WorkflowSnippet();
        $this->mobj = new MainModel();
    }

    public function getFields(Request $request){
        $user_data =  Session::get('user_data');
        $user_id = $user_data['id'];
        try{
            $field_type = $request->field_type;
            $platform = $request->platform_id;

            $fields = DB::table('platform_fields')->where(['type'=>$field_type, 'platform_id'=>$platform, 'status'=>1])
            ->whereIn('user_id',[0,$user_id])->select('name','description','id','db_field_name','platform_id','type')->get();

            return response()->json(['status_code' => 1,'status_text' => 'Field found','data'=>$fields], 200);
        }catch(\Exception $e){
            return response()->json(['status_code' => 0,'status_text' => $e->getMessage()], 200);
        }

    }

    public function getPlatformEventsAndAction(Request $request){
        $user_data =  Session::get('user_data');
        try{
            $from_platform = $request->from_platform;
            $to_platform = $request->to_platform;

            $event_opts = '<option value=""  data-icon="" selected>Select a Trigger </option>';
            $action_opts = '<option value=""  data-icon="" selected>Select an Action </option>';

            $from_acc_opts = '<option value=""  data-icon="" selected>Select Account</option>';
            $to_acc_opts = '<option value=""  data-icon="" selected>Select Account</option>';

            $fp = $this->wfsnip->getPlatformEvents($from_platform);
            $tp = $this->wfsnip->getPlatformEvents($to_platform);

            $facc = $this->wfsnip->getPlatformAccounts($from_platform);
            $tacc = $this->wfsnip->getPlatformAccounts($to_platform);

            if($fp['options']){
                $event_opts .= $fp['options'];
                $action_opts .= $fp['options'];
            }
            if($tp['options']){
                $action_opts .= $tp['options'];
                $event_opts .= $tp['options'];
            }
            if($facc['options']){
                $from_acc_opts .= $facc['options'];
            }
            if($tacc['options']){
                $to_acc_opts .= $tacc['options'];
            }

            $from_acc_opts .= '<option data-src="' . $facc['auth_endpoint'] . '" value="add-new" data-icon="">Add New Account</option>';
            $to_acc_opts .= '<option data-src="' . $tacc['auth_endpoint'] . '" value="add-new" data-icon="">Add New Account</option>';

            return response()->json(['status_code' => 1,'status_text' => 'Success','event_option'=>$event_opts,
            'action_option'=>$action_opts,'from_acc_opts'=>$from_acc_opts,'to_acc_opts'=>$to_acc_opts], 200);
        }catch(\Exception $e){
            return response()->json(['status_code' => 0,'status_text' => $e->getMessage()], 200);
        }
    }

    public function GetMappingFields(Request $request){
        $user_data =  Session::get('user_data');
        $user_id = $user_data['id'];
        $workflow_id = $request->workflow_id;
        $wfinfo = $this->mobj->getFirstResultByConditions('user_integrations',['user_id'=>$user_id,'id'=>$workflow_id]);
        // $workflow = DB::table('user_integrations')
        // ->where(['user_id'=>1,'ls_bp_fields.type'=>'product','ls_bp_fields.platform'=>'lightspeed'])->whereIn('ls_bp_fields.user_id',[0,Auth::user()->user_id])
        // ->where(function ($query1) {
        //     $query1->where(['mapping_ls_bp.user_id'=>Auth::user()->user_id,'mapping_ls_bp.mapping_type'=>'product'])
        //     ->orWhereNull('mapping_ls_bp.user_id');
        // })->orderBy('ls_bp_fields.order_val','ASC')->select('ls_bp_fields.*','mapping_ls_bp.ls_id','mapping_ls_bp.bp_id')->get();
        $lsfields = DB::table('platform_fields')->where(['status'=>1,'type'=>'product','platform_id'=>'brightpearl'])->whereIn('user_id',[0,$user_id])->get();
        $bpfields = DB::table('platform_fields')->where(['status'=>1,'type'=>'product','platform_id'=>'brightpearl'])->whereIn('user_id',[0,$user_id])->get();
        $new_rows = '';
        $bp_fields = '';
        $common_opt = '<option value="">--Select--</option>';
        $bp_fields .= $common_opt;
        $field_opt_arr = ['Product Name'=>'','UPC'=>''
        ,'EAN'=>'','SKU'=>'','Category'=>'','Brand'=>'','product Type'=>'','Supplier'=>'','Seasons'=>''];
        foreach($bpfields as $fk => $fv){
                $bp_fields .= '<option value="'.$fv->id.'">'.ucfirst($fv->description).'</option>';
                if(isset($field_opt_arr[$fv->description])){
                    $field_opt_arr[$fv->description] = $common_opt.'<option value="'.$fv->id.'">'.ucfirst($fv->description).'</option>';
                }
        }

        $map_bp_ls_common_i = ['description'=>'Product Name','upc'=>'UPC'
        ,'ean'=>'EAN','Category'=>'Category','Brand'=>'Brand'
        ,'item Type'=>'product Type','Vendor'=>'Supplier','Season'=>'Seasons'];
        // ,'Manufacturer Sku'=>'SKU' // commented
        // Key is LS field & Value is BP field (Both are from description column)
        foreach($lsfields as $fk => $fv){
            $bp_index = ($fk+1);

            if(isset($map_bp_ls_common_i[$fv->description])){
                $bp_fields_final = '<select class="form-control required select2 destination_field_id" data-index="'.$bp_index.'" name="destination_field_id" id="destination_field_id'.$bp_index.'" required>';
                $bp_fields_final .= $field_opt_arr[$map_bp_ls_common_i[$fv->description]];
            }else{
                $bp_fields_final = '<select class="form-control select2 destination_field_id" data-index="'.$bp_index.'" name="destination_field_id" id="destination_field_id'.$bp_index.'" required>';
                $bp_fields_final .= $bp_fields;
            }

            $bp_fields_final .= '</select>';
            $new_rows .= '<div class="col-xl-7 col-sm-12 col-md-7 col-12 mb-2 mb-xl-0">
						    <div class="select-item mb-1">
                              <input type="hidden" name="source_field_id" value="'.$fv->id.'" id="source_field_id"/>
							  <label class="my-label">'.$fv->description.'</label>'.
							  $bp_fields_final.
							  '<span class="field_error">Field value is required</span>
							    </div>
                            </div>
                        ';
        }
        return response()->json(['status_code' => 1, 'status_text' => 'Field updated successfully!','data'=>$new_rows]);
    }

}
