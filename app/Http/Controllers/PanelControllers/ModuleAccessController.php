<?php

namespace App\Http\Controllers\PanelControllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\CommonController;
use App\User;
use App\Models\UserStaffIntegrationAccess;
use Validator;
use App\Helper\MainModel;
use App\Models\UserRight;
use Illuminate\Support\Facades\Session;

class ModuleAccessController extends Controller
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function index()
    {
        
        if(isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')])){
            return view('errors.404');
        }
        if(Auth::user()->role!="master_staff" && isset(Auth::user()->role)){
        $view = $this->getAccessRight(Auth::user()->id, Auth::user()->role, 'staff', 'view');
        if($view == 1){
            $modify = $this->getAccessRight(Auth::user()->id, Auth::user()->role, 'staff', 'modify');
            return view("pages.module_access.staff_list", compact("modify"));
        }
        else{
            return redirect()->route('home.integrations');
        }
        }else{
            return redirect()->to('launchpad');
        }
    }

    public function getStaffMembers(Request $request){

        //admin user
        $Staff_ParentId = \Session::get('user_data')->id;
         //get staff users
         $staffArr = [];
         if($Staff_ParentId){
             $userStaffData = DB::table('user_staff_integration_access')->select('user_id')->where('parent_id',$Staff_ParentId)->get();
             foreach($userStaffData as $userStaff)
             {
                 array_push($staffArr,$userStaff->user_id);
             }
         }

        $modify = $this->getAccessRight(Auth::user()->id, Auth::user()->role, 'staff', 'modify');
        $org_id = Auth::user()->organization_id;
        $columns = array(
            'A.id','A.name','A.email', 'A.status'
        );

        $status_code = $request->get('status');
        if(isset($status_code) && ($status_code==1 || $status_code==0)){
            $whereClause = array('A.role'=>'user_staff', 'A.status'=>$status_code, 'A.organization_id'=>$org_id);
        }
        else{
            $whereClause = array('A.role'=>'user_staff', 'A.organization_id'=>$org_id);
        }

        
        $totalDataQry = DB::table('users AS A')->where($whereClause);
        if(isset($Staff_ParentId) && ($Staff_ParentId !="" )){
            $totalDataQry->whereIn('id',$staffArr);
        }
        $totalData = $totalDataQry->count();

        $totalFiltered = $totalData;
        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $search = $request->input('search.value');

        $query_row = DB::table('users AS A')->where($whereClause);
        if(isset($Staff_ParentId) && ($Staff_ParentId !="" )){
            $query_row->whereIn('id',$staffArr);
        }
        $query = $query_row->select('A.id', 'A.name', 'A.email', 'A.status')
        ->where(function ($query1) use ($search, $columns) {
            if ($search != '') {
                for ($i = 0; $i < count($columns); $i++) {
                    $query1->orWhere($columns[$i], 'like', '%' . $search . '%');
                }
            }
        });

        $totalFiltered = $query->count();
        $result = $query->orderBy($order, $dir)->skip($start)->take($limit)->get();
        $data = array();
        if(!empty($result))
        {
            foreach ($result as $key=>$rv)
            {
                if($rv->status == 1){
                    $status = "<span class='right badge badge-success'>Active</span>";
                }
                else{
                    $status = "<span class='right badge badge-danger'>Inactive</span>";
                }
                $id = $rv->id;
                $nestedData['id'] = $key+1;
                $nestedData['name'] = $rv->name;
                $nestedData['email'] = $rv->email;
                $nestedData['status'] = $status;

                $nestedData['action'] =
                '<div class="dropdown">
					<button class="btn btn-sm btn-icon" type="button" id="dropdownMenuButton'.$id.'" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
					    <i class="fa fa-ellipsis-h" data-toggle="tooltip" title="Click Here" data-placement="top" title="Actions"></i>
					</button>
					<div class="dropdown-menu" aria-labelledby="dropdownMenuButton'.$id.'">
                        <a class="dropdown-item edit_btn"
                            href="'.url('/').'/update-staff-member/'.$id.'"><i class="fa fa-eye mr-2"></i> View
                        </a>';
                if($rv->status == 1 && $modify == 1){
                    $nestedData['action'] .=
                            '<a class="dropdown-item text-danger generate_mdl_staff_delete" data-name="'.$rv->name.'" data-rowid="'.$rv->id.'"
                                href="javscript:void(0)"><i class="fa fa-ban mr-2"></i> Deactivate
                            </a>
                        </div>
                    </div>';
                }
                $data[] = $nestedData;
            }
        }
        $json_data = array(
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data
        );
        echo json_encode($json_data);
    }

    public function inviteStaffMember()
    {
        if(isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')])){
            return view('errors.404');
        }
        
        $org_id = Auth::user()->organization_id;

        $modify = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'staff', 'modify');
        if($modify == 0){
            return redirect()->route('home.integrations');
        }

        // show the user a form with an email field to invite a new user
        $modules = DB::table('user_modules')
        ->select('id', 'module_name', 'option_view', 'option_edit AS option_modify')
        ->where('ideal_portal', 'integration')
        ->where('status',1)
        ->get();

        return view("pages.module_access.staff_invite", compact('modules'));
    }

    public function sendInvitationMail(Request $request){
        
        // process the form submission and send the invite by email
        $invite = new User;
        $organization_id = Auth::user()->organization_id;
        $rules = [
            'email' => 'required|email',
            'name'=>'required|regex:/^[a-zA-Z0-9\s]+$/'
        ];

        $input = $request->only('email','name');

        $validator = Validator::make($input, $rules);

        if($validator->fails())
        {
            $messages = $validator->messages();
            \Session::put('failM', implode(' | ',$messages->all()));
            return redirect("invite-staff-member");
        }
        
        $send_invite_email = true;

        $user = DB::table('users')
        ->where(['email'=>$request->email, 'status'=>1, 'organization_id'=>$organization_id])
        ->first();

        if($user){
            $user_id = $user->id;
            $send_invite_email = false;
        }else{
            $user_name = $request->name;
            $user_email = $request->email;
            $remember_token = str_random(30);
            $txt_pwd = substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", 6)), 0, 6);
            $recipient_email = $request->email;

            $invite->name = $user_name;
            $invite->email = $user_email;
            $invite->role = 'user_staff';
            $invite->password = bcrypt($txt_pwd);
            $invite->remember_token = $remember_token;
            $invite->organization_id = $organization_id;
            $invite->save();
            $user_id = $invite->id;
        }

       if(isset($user_id)){

            $user_staff = UserStaffIntegrationAccess::where([
                'user_id' => $user_id,
                'parent_id' => \Session::get('user_data')->id,
                'organization_id' => Auth::user()->organization_id
            ])->first();
            
            if(!$user_staff){
                $user_staff = new UserStaffIntegrationAccess();
                $user_staff->user_id = $user_id;
                $user_staff->parent_id = \Session::get('user_data')->id;
                $user_staff->organization_id = Auth::user()->organization_id;
                $user_staff->save();

                // Submit user rights as given
                $module_id = $request->module_id;
                $access_info = json_decode($request->access_info);
                for($i=0; $i<count($access_info); $i++){
                    $view = $access_info[$i]->view;
                    $modify = $access_info[$i]->modify;

                    $user_right = new UserRight();
                    $user_right->guest_user_id = $user_id;
                    $user_right->module_id = $access_info[$i]->module_id;
                    $user_right->view = $view;
                    $user_right->create = $modify;
                    $user_right->edit = $modify;
                    $user_right->user_id = \Session::get('user_data')->id;
                    $user_right->save();

                }

            }else{
                \Session::put('failM', 'Already an member of this organization!');
                return redirect("invite-staff-member");
            }
       }

        if($send_invite_email){

            $to = trim($request->email);
            $project_name = env('APP_NAME');
            $from = null; // These both variable will be set in CommonController
            $from_name = null;
            $accept_invite_url = url("staff-accept-invite/" . $remember_token);
            $login_url = url('login');
            $logo = request()->getSchemeAndHttpHost()."/admin/public/login_assets/img/apiworx_logo.png";

            // Check whether custom verification template is created for this organization
            $template_setting = DB::table('es_email_template')
            ->select('mail_subject', 'mail_body')
            ->where(['organization_id'=>$organization_id, 'mail_type'=>'staff_invitation_user', 'active'=>1])
            ->first();
            if(!$template_setting){
                $template_setting = DB::table('es_email_template')
                ->select('organization_id', 'mail_subject', 'mail_body')
                ->where(['organization_id'=>0, 'mail_type'=>'staff_invitation_user', 'active'=>1])
                ->first();
            }
            if($template_setting && isset($template_setting)){
                $raw_mail_subject = $template_setting->mail_subject;
                $raw_body_content = $template_setting->mail_body;
                $search = array(
                    '@org_name', '@name', '@email', '@date', '@date_time', '@login_url', '@logo', '@accept_invite_url', '@password'
                );
                $replace = array(
                    $project_name, $user_name, $user_email, date('d-M-Y'), date('d-M-Y H:i A'), '<a href="'.$login_url.'" target="_blank">Login</a>', '<img src="'.$logo.'" alt="APIWORX" style="margin-left:30%;width:30%;"><br>', $accept_invite_url, $txt_pwd
                );
                $subject = str_replace($search ,$replace, $raw_mail_subject);
                $body = str_replace($search ,$replace, $raw_body_content);
            }
            else{
                \Session::put('failM', 'Email content not found.');
                return redirect("invite-staff-member");
            }

            $arrData = array(
                'body_msg' => $body,
                'name'=>$request->name,
                'url_link'=>$accept_invite_url,
                'to'=>$to,
                'to_name'=>$request->name,
                'subject'=>$subject,
                'from'=>$from,
                'from_name'=>$from_name,
                'txt_pwd'=>$txt_pwd,
            );

            $response = CommonController::sendMail($arrData);

            if($response == 1){
                \Session::put('successM', 'Invitation has been sent successfully.');
                return redirect("invite-staff-member");
            }
            else{
                \Session::put('failM', 'SMTP server error.');
                return redirect("invite-staff-member");
            }

        }else{
            \Session::put('successM', 'Staff user has added successfully.');
            return redirect("invite-staff-member");
        } 
    }

    public function staffAcceptInvitation($token=''){
        // Here we'll look up the user by the token sent provided in the URL
        if(!isset($token))
        {
            \Session::put('failM', 'Account verification failed please try again');
            return redirect('/login');
        }

        $user = User::whereRememberToken($token)->first();
        if (!$user){
            \Session::put('failM', 'Invalid verification code');
            return redirect('/login');
        }
        else{
            $user->confirmed = 1;
            $user->remember_token = null;
            $user->save();
            \Session::put('failM', 'Account verified successfully please login to continue');
            return redirect('/login');
        }
    }

    public function updateStaffMember($update_id){
        $view = $this->getAccessRight(Auth::user()->id, Auth::user()->role, 'staff', 'view');
        if($view == 1){
            $org_id = Auth::user()->organization_id;
            $user = DB::table('users')->where(['organization_id'=>$org_id, 'role'=>'user_staff', 'id'=>$update_id])
            ->select('id', 'name', 'email', 'created_at', 'status')
            ->first();
            
            if(!empty($user) && isset($user) && $this->validateStafffupdate($org_id,Auth::user()->id,Auth::user()->role,$update_id)){
                $modules = DB::table('user_modules')
                ->where('ideal_portal', 'integration')
                ->where('status',1)
                ->select('id', 'module_name', 'module_code', 'option_view', 'option_edit AS option_modify')
                ->get();

                $arrView = [];
                $arrModify = [];
                for($i=0; $i<count($modules); $i++){
                    $module_id[] = $modules[$i]->id;
                    $module_name[] = $modules[$i]->module_name;
                    $option_view[] = $modules[$i]->option_view;
                    $option_modify[] = $modules[$i]->option_modify;
                    $arrView[] = $this->getAccessRight($update_id, 'user_staff', $modules[$i]->module_code, 'view',1, $update_id);
                    $arrModify[] = $this->getAccessRight($update_id, 'user_staff', $modules[$i]->module_code, 'modify',1, $update_id);
                }

                $modify = $this->getAccessRight(Auth::user()->id, Auth::user()->role, 'staff', 'modify');
                return view("pages.module_access.staff_update_rights", compact("user", "module_id", "module_name", "option_view", "option_modify", "arrView", "arrModify", "modify"));
            }
            else{
                return redirect()->route('staff.list');
            }
        }
        else{
            return redirect()->route('home.integrations');
        }
    }

    public function validateStafffupdate($org_id,$auhtUserId,$authUserRole,$update_id){
        
        $user_parent_id = UserStaffIntegrationAccess::where(['organization_id'=>$org_id,'parent_id'=>$auhtUserId,'user_id'=>$update_id])
                          ->pluck('parent_id')->first();

        if($authUserRole == 'user_staff'){

            $user_staff_parnet_id = UserStaffIntegrationAccess::where(['organization_id'=>$org_id,'user_id'=>$auhtUserId])
                                    ->pluck('parent_id')->first();

            $res = ($user_parent_id == $user_staff_parnet_id) ? 1 : 0;
            
        }elseif($authUserRole == 'user'){

            $res = ($user_parent_id == $auhtUserId) ? 1 : 0;
            
        }
        
        return $res;
    }

    public function deleteStaffMember(Request $request){
        $org_id = Auth::user()->organization_id;
        $update_id = $request->update_id;
        $value = $request->check_val;
        $updated_at = new \DateTime();

        $data = []; // Initialize variable to return

        $user_arr = [
            'status'=>$value,
            'updated_at'=>$updated_at
        ];

        DB::beginTransaction();
        try {
            $result = $this->mobj->makeUpdate('users', $user_arr, ['id'=>$update_id, 'organization_id'=>$org_id]);
            DB::commit(); // all good

            $data['status_code'] = 1;
            $data['status_text'] = 'Status Updated Successfully';
            return json_encode($data);
        } catch (\Exception $e) {
            DB::rollback(); // something went wrong
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }

    public function staffUpdateRights(Request $request){
        $user_org = \Session::get('user_data')->organization_id;
        $user_id = isset(\Session::get('user_data')->staff_id) ? \Session::get('user_data')->staff_id : \Session::get('user_data')->id;
        $user_role = \Session::get('user_data')->role;
        $update_id = $request->update_id;
        
        $access_info = json_decode($request->access_info);
    
        $data = []; // Initialize variable to return

        try {

            if($this->validateStafffupdate($user_org,$user_id,$user_role,$update_id)){
                
                if($user_role == 'user_staff'){
                    $modify = $this->getAccessRight($user_id, $user_role, 'staff', 'modify');
                    if($modify == 1){
                       $resp = $this->updateStaffAccess($access_info,$update_id,$user_id);
                       if($resp){
                        $data['status_code'] = 1;
                        $data['status_text'] = 'Access module updated successfully';
                        return json_encode($data);
                        }
                    }else{
                        $data['status_code'] = 0;
                        $data['status_text'] = 'You do not have permission to modify this resource';
                        return json_encode($data);
                    }
                }
                $resp = $this->updateStaffAccess($access_info,$update_id,$user_id);
                if($resp){
                    $data['status_code'] = 1;
                    $data['status_text'] = 'Access module updated successfully';
                    return json_encode($data);
                }
                
            }else{
                $data['status_code'] = 0;
                $data['status_text'] = 'You do not have permission to modify this resource';
                return json_encode($data);
            }
            
        } catch (\Exception $e) {
            
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }

    public function updateStaffAccess($access_info,$update_id,$user_id){
        
        foreach ($access_info as $key => $value) {
            $module_access = [
                'guest_user_id' => $update_id,
                'module_id' => $value->module_id,
                'view' => $value->view,
                'create' => $value->modify,
                'edit' => $value->modify,
                'user_id' => $user_id,
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime()
            ];
            
            $isExist = DB::table('user_rights')->where(['module_id'=>$value->module_id, 'guest_user_id'=>$update_id,'user_id'=>$user_id])->count();
            
            if($isExist > 0){
                
                DB::table('user_rights')->where(['module_id'=>$value->module_id, 'guest_user_id'=>$update_id,'user_id'=>$user_id])->update($module_access);
            }
            else{
                DB::table('user_rights')->insert($module_access);
            }
        }
        
        return 1;
        
    }

    //$user_staff_id = 239, $role='user_staff', $module_code='integrations', $access_code='view' , $tst=1
    public static function getAccessRight($user_staff_id, $role, $module_code, $access_code , $staff_Id=0){
       
        if($role == 'user_staff' || (Session::get('switch_to_staff_dashboard') && Session::get('switch_to_staff_role'))){
            
            $is_auth_user_staff = false;

            if(Auth::user()->role == $role){//check auth user rolw is user_staff
                $is_auth_user_staff = true;
            }
            
            //to handel permission of user staff if loginAs main user
            if(Session::get('switch_to_staff_dashboard') && Session::get('switch_to_staff_role')){
                $is_auth_user_staff = false;
                if(!$staff_Id){ 
                    $user_staff_id = Session::get('switch_to_staff_dashboard');
                }
                
            }
            
            $query = DB::table('user_rights AS A');
            if($access_code == 'view'){
                $query->select('A.view');
            }
            // if($access_code == 'create'){
            //     $query->select('A.create');
            // }
            if($access_code == 'modify'){
                $query->select('A.edit');
            }

            if(!$is_auth_user_staff){
                $query =  $query->where('A.user_id',Auth::user()->id);
            }
            
            $right = $query->join('user_modules AS B', 'B.id', '=', 'A.module_id')
            ->where(['A.guest_user_id'=>$user_staff_id,'B.module_code'=>$module_code, 'B.ideal_portal'=>'integration'])
            ->where('status',1)->first();
            
          
            if(!empty($right)){
                if(isset($right->view)){
                    $result = $right->view;
                }
                // if(isset($right->create)){
                //     $result = $right->create;
                // }
                if(isset($right->edit)){
                    $result = $right->edit;
                }
            }
            else{
                $result = 0;
            }
        }else if($role == 'master_staff'){
            $result = 1;
        }
        else{
            $result = 1;
        }
        return $result;
    }

}