<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Session;
use App\User;
use App\Models\NotificationEmail;
use DB;
use Validator;
use Config;
use Mail;
use Illuminate\Routing\UrlGenerator;
use App\Helper\MainModel;
use App\Mail\DefaultMailable;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LoginLogController;
use App\Models\PlatformAccount;
use App\Models\PlatformApiApp;
use App\Models\PlatformLookup;
use App\Models\UserIntegration;
use Artisan;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Illuminate\Support\Facades\Log;

class CommonController extends Controller
{
    public static $defaultToName = 'Team';
    public $defaultMailgunApiKey = '';
    public $defaultMailgunApiUrl = '';

    public function __construct()
    {
        $this->defaultMailgunApiKey = env( 'DEFAULT_MAILGUN_API_KEY' );
        $this->defaultMailgunApiUrl = env( 'DEFAULT_MAILGUN_API_URL' );
    }
    
    public function dashboard()
    {
        $user_data =  Session::get('user_data');
        $uid = $user_data['id'];
        $organization_id = $user_data['organization_id'];
        return view("pages.dashboard");
    }

    public function LoginAsUser(Request $request, $id)
    {
        if ($request->isMethod('get')) {
            if (isset(Auth::user()->role) && (Auth::user()->role == "master_staff" || Auth::user()->role == "user_staff")) {

                $nameParts = explode(' ', Auth::user()->name);

                if (Auth::user()->role == "user_staff") {
                    Session::put('switch_to_staff_dashboard', Auth::user()->id);
                    Session::put('switch_to_staff_role', Auth::user()->role);
                    $staff_username = isset($nameParts[0]) ? $nameParts[0] : "Staff";
                    Session::put('staff_username', $staff_username);
                } else {
                    Session::put('switch_to_user_dashboard', Auth::user()->id);
                    $master_username = isset($nameParts[0]) ? $nameParts[0] : "Master";
                    Session::put('master_username', $master_username);
                }

                Auth::logout(); // for end current session
                Auth::loginUsingId($id);
                $user_data = Auth::user();
                if (isset(Auth::user()->id)) {
                    Session::put('user_data', $user_data);
                    //Session::put('switch_to_user_dashboard', 'yes');
                    return redirect()->to('integrations');
                } else {
                    if (Auth::user()->role == "user_staff") {
                        Session::forget(['switch_to_staff_dashboard', 'staff_username', 'switch_to_staff_role']);
                    } else {
                        Session::forget(['switch_to_user_dashboard', 'master_username']);
                    }
                    Session::put('fail-msg', 'Invalid email or password!');
                    return redirect()->to('integrations');
                }
            } else {
                Session::put('fail-msg', 'Please try again');
                return redirect('/login');
            }
        } else {
            return redirect()->to('launchpad');
        }
    }
    public function SwitchBackToLaunchpad(Request $request)
    {
        if ($request->isMethod('get')) {
            if (isset(Auth::user()->role) && Auth::user()->role == "user") {

                if (Session::get('switch_to_staff_dashboard')) {
                    Auth::loginUsingId(Session::get('switch_to_staff_dashboard'));
                } else {
                    Auth::loginUsingId(Session::get('switch_to_user_dashboard'));
                }

                Session::forget(['switch_to_user_dashboard', 'master_username', 'switch_to_staff_dashboard', 'staff_username', 'switch_to_staff_role']);
                $user_data = Auth::user();
                Session::put('user_data', $user_data);
                if (Auth::check()) {
                    if (Auth::user()->role == "master_staff" || Auth::user()->role == "user_staff") {
                        return redirect()->to('launchpad');
                    } else {
                        Session::forget(['switch_to_user_dashboard', 'master_username', 'switch_to_staff_dashboard', 'staff_username', 'switch_to_staff_role']);
                        Session::put('fail-msg', 'Invalid email or password!');
                        return redirect()->to('login');
                    }
                } else {
                    return redirect()->to('login');
                }
                return redirect()->to('integrations');
            }
        } else {

            Session::forget(['switch_to_user_dashboard', 'master_username', 'user_data', 'switch_to_staff_dashboard', 'staff_username', 'switch_to_staff_role']);
            return redirect()->to('login');
        }
    }

    public function login(Request $request)
    {
        $logLogController = new LoginLogController();

        $user_data = Session::get('user_data');
        $organization_id = config('org_details.organization_id');
        if ($request->isMethod('post') && !$user_data) {
            $email = $request->email;
            $password = $request->password;
            $remember_me = $request->has('remember') ? true : false;
            $status = 1; // User is active
            $confirmed = 1; // User passed the verification process

            $query = DB::table('users')->select('id', 'role', 'organization_id')->where(['email' => $request->email, 'status' => 1]);
            if ($request->email != 'master@apiworx.net') {
                $query = $query->where(['organization_id' => $organization_id]);
            }

            $users_i = $query->first();

            if ($users_i) {
                if ($users_i->role == "master_staff") {
                    $login_params = ['email' => $email, 'password' => $password, 'confirmed' => 1, 'status' => 1,  'role' => ['master_staff']];
                } else {
                    $login_params = [];
                    if ($users_i->organization_id == $organization_id) {
                        $login_params = ['email' => $email, 'password' => $password, 'confirmed' => 1, 'status' => 1, 'organization_id' => $organization_id, 'role' => ['user', 'user_staff']];
                    }
                }
                if (Auth::attempt($login_params, $remember_me)) {
                    $user_data = Auth::user();

                    // set owner's id as user_id in case user_staff is logged-in
                    if ($user_data['role'] == 'user_staff') {
                        $user_data['staff_id'] = $user_data['id'];
                        $user_staff_q = DB::table('user_staff_integration_access')->select('parent_id')->where(['user_id' => $user_data['id']]);
                        $parentUserCount = $user_staff_q->count();
                        if ($parentUserCount) {
                            if ($parentUserCount > 1) {
                                return redirect('/launchpad');
                            }
                            $user_data['id'] = $user_staff_q->first()->parent_id;
                        }
                    }

                    if (env('LOGIN_RATE_LIMIT')) {
                        $lockLogin =  $logLogController->checkLoginLock($email, $logLogController->getIpAddr());
                        if ($lockLogin == 0) {
                            Session::forget('attempting_email');
                            Session::put('user_data', $user_data);

                            setcookie('user_id', $user_data['id'], time() + 3600, '/');
                            if ($user_data['role'] == 'master_staff') {
                                return redirect('/launchpad');
                            }

                            return redirect('/integrations');
                        } else {

                            Session::put('fail-msg', 'To many failed login attempts. Please login after 60 sec');
                            return redirect('/login');
                        }
                    } else {
                        Session::forget('attempting_email');
                        Session::put('user_data', $user_data);

                        setcookie('user_id', $user_data['id'], time() + 3600, '/');
                        if ($user_data['role'] == 'master_staff') {
                            return redirect('/launchpad');
                        }
                        return redirect('/integrations');
                    }
                } else {

                    if (env('LOGIN_RATE_LIMIT')) {
                        $logRes = $logLogController->loginAttempts($email, $logLogController->getIpAddr());
                        $appendMsg = $logRes ? $logRes : '';
                        Session::put('fail-msg', 'Invalid email or password! ' . $appendMsg);
                    } else {
                        Session::put('fail-msg', 'Invalid email or password!');
                        Session::put('attempting_email', $email);
                    }
                    return redirect('/login');
                }
            } else {
                Session::put('fail-msg', 'You are not a member!');
                return redirect('/login');
            }
        } else if ($request->isMethod('get') && $request->jwt) {
            $validate_status = app('App\Http\Controllers\AmazonCognitoController')->ValidateCognitoToken($request->jwt);
            if ($validate_status) {
                list($header, $payload, $signature) = explode('.', $request->jwt);

                //dd(base64_decode($header), base64_decode($payload), base64_decode($signature));

                $jsonToken = base64_decode($payload);
                $arrayToken = json_decode($jsonToken, true);
                //echo '<pre>';
                //print_r($arrayToken);
                if (isset($arrayToken['appaccess']) && strpos($arrayToken['appaccess'], 'canAccountingIntegration') !== false) {
                    $user_info = DB::table('users')->where('cognito_org', $arrayToken['org'])->where('email', $arrayToken['email'])->where('organization_id', $organization_id)->first();
                    if (is_null($user_info)) {
                        $userObject = new User;

                        $userObject->name = $arrayToken['email'];
                        $userObject->email = $arrayToken['email'];
                        $userObject->confirmed = 1;
                        $userObject->status = 1;
                        $user_role = DB::table('users')->where('cognito_org', $arrayToken['org'])->where('organization_id', $organization_id)->where('role', 'user')->first();
                        if (is_null($user_role)) {
                            $userObject->role = 'user';
                        } else {
                            $userObject->role = 'user_staff';
                        }
                        //$userObject->role = ($arrayToken['isowneruser'] == 'true') ? 'user' : 'user_staff';
                        $userObject->organization_id = $organization_id;
                        $userObject->cognito_sub = $arrayToken['sub'];
                        $userObject->cognito_org = $arrayToken['org'];

                        $userObject->save();
                        $user_id = $userObject->id;

                        if ($user_role) {
                            DB::table('user_staff_integration_access')->insert(['user_id' => $user_id, 'parent_id' => $user_role->id, 'organization_id' => $organization_id, 'status' => 1]);

                            $user_modules = DB::table('user_modules')->where('ideal_portal', 'integration')->where('status', 1)->get();
                            foreach ($user_modules as $user_module) {
                                DB::table('user_rights')->insert(['guest_user_id' => $user_id, 'user_id' => $user_role->id, 'module_id' => $user_module->id, 'view' => 1, 'create' => 1, 'edit' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                            }
                        }
                    } else {
                        $userObject = User::find($user_info->id);
                        $userObject->name = $arrayToken['email'];
                        $userObject->confirmed = 1;
                        $userObject->status = 1;
                        $userObject->cognito_sub = $arrayToken['sub'];
                        $userObject->cognito_org = $arrayToken['org'];
                        $userObject->save();
                        $user_id = $userObject->id;
                    }

                    //Auth::loginUsingId($user_id);
                    Auth::login($userObject);

                    $user_data = Auth::user();

                    // set owner's id as user_id in case user_staff is logged-in
                    if ($user_data['role'] == 'user_staff') {
                        $user_data['staff_id'] = $user_data['id'];
                        $user = DB::table('user_staff_integration_access')->select('parent_id')->where(['user_id' => $user_data['id']])->first();
                        if ($user) {
                            $user_data['id'] = $user->parent_id;
                        }
                    }

                    Session::put('user_data', $user_data);

                    setcookie('user_id', $user_data['id'], time() + 3600, '/');

                    //call addition function to setup notification email
                    $this->addStaffForNotificationEmail($arrayToken['org'],$arrayToken['email'],$organization_id);

                    return redirect('/integrations?jwt=' . $request->jwt);
                }
            }

            return redirect('/jwt-token-expired');
        } else if (!$user_data) {
            //return redirect(env('APP_OAUTH_CHANNEL').'?uid='.''.'&signintype='.'MA'.'&redirect_url='.url('oAuthResponseHandler').'&ptype=login'); // ptype is a page type
            session(['link' => url()->previous()]);
            $host = request()->getSchemeAndHttpHost();
            $MARKETPLACE_SAML_URL = (env('APP_ENV') == 'local') ? env('APP_URL') : $host;
            $MARKETPLACE_SAML_URL .= '/saml2';
            $authMethod = $this->checkAuthenticationMethod();
            $auth_type = 'common';

            return view('auth.login', compact('authMethod', 'MARKETPLACE_SAML_URL'));
        } else {
            return redirect('/integrations');
        }
    }


    //add staff in notification email for extensive
    public function addStaffForNotificationEmail($cognito_org,$email,$organization_id)
    {   
        $user_info = DB::table('users')->where('cognito_org', $cognito_org)->where('email', $email)->where('organization_id', $organization_id)->select('id','role')->first();
        if($user_info) {

            if( $user_info->role =="user_staff" ) {
                //find parent user id  
                $admin_user_id = DB::table('user_staff_integration_access')->where(['user_id' => $user_info->id, 'organization_id' => $organization_id])->select('parent_id')
                ->pluck('parent_id')->first();
            } else {
                $admin_user_id = $user_info->id;
            }

            if($admin_user_id) {
                //find in es_notification_email
                $find_notification_emails = DB::table('es_notification_email')->where('user_id',$admin_user_id)->select('id','emails')->first();
                if($find_notification_emails) {
                    
                    $old_notif_emails = $find_notification_emails->emails;
                    $email_array = explode(",",$old_notif_emails);
                    $email_array = array_filter($email_array,'strlen');
                    array_push($email_array,$email);
                    $email_array = array_unique($email_array);
                    $new_notif_emails = implode(",",$email_array);

                    //update notification email
                    DB::table('es_notification_email')->where('id',$find_notification_emails->id)->update(['emails'=>$new_notif_emails,'updated_at' => date('Y-m-d H:i:s')]);

                } else {
                    DB::table('es_notification_email')->insert(['user_id'=>$admin_user_id,'emails'=>$email,'created_at'=>date('Y-m-d H:i:s')]);   
                }
                
            }

        } 
        
    }

    public function register($platform_name = '')
    {
        $authMethod = $this->checkAuthenticationMethod();
        if ($authMethod && !empty($authMethod)) {
            if ($authMethod->auth_type != 'Basic Auth') {
                $basic_auth = false;
            } else {
                $basic_auth = true;
            }
        } else {
            $basic_auth = true;
        }
        // check if basic auth
        if ($basic_auth === true) {
            $platform_id = '';
            if ($platform_name) {
                $platform_id = $platform_name;
            }
            return view('auth.register', compact('platform_id'));
        } else {
            return redirect()->route('login');
        }
    }

    public function registerEmail(Request $request)
    {
        $rules = [
            'name' => 'required|min:1|regex:/^[a-zA-Z0-9\s]+$/',
            'email' => 'required|email',
            'password' => 'required|min:12|same:confirm_password|regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{12,}$/',
            'confirm_password' => 'required|min:12',
        ];

        $input = $request->only(
            'name',
            'email',
            'password',
            'confirm_password'
        );

        $messages = [
            'regex' => 'Password must be strong with the length of 12 characters and combination of uppercase characters (A – Z), lowercase characters (a – z), Base 10 digits (0 – 9), Non-alphanumeric (For example: !, $, #, or %).'
        ];

        $validator = Validator::make($input, $rules, $messages);

        if ($validator->fails()) {
            $messages = $validator->messages();
            Session::put('fail-msg', implode(' | ', $messages->all()));
            return redirect('/register');
        }

        if ($request->password == $request->name || $request->password == $request->email) {
            Session::put('fail-msg', 'Password should not be same as email or name.');
            return redirect("register");
        }

        $adminObj = new User;
        $integrationController = new IntegrationController();

        $organization_id = config('org_details.organization_id');

        if (!$organization_id) {
            Session::put('fail-msg', 'Invalid domain.');
            return redirect("register");
        }

        $user_info = DB::table('users')
            ->where(
                [
                    'email' => $request->email,
                    'status' => 1,
                    'organization_id' => $organization_id
                ]
            )
            ->first();

        if ($user_info) {
            Session::put('fail-msg', 'You are already a member.');
            return redirect("login");
        }

        $remember_token = str_random(30);
        $adminObj->name = $request->name;
        $adminObj->email = $request->email;
        $adminObj->role = 'user';
        $adminObj->password = bcrypt($request->password);
        $adminObj->remember_token = $remember_token;
        $adminObj->organization_id = $organization_id;

        $adminObj->save();
        $user_id = $adminObj->id;
        if ($request->platform_id) {
            $integrationController->savePlatformIntegrationReferred($request->platform_id, $user_id);
        }
        $to = trim($request->email);
        $project_name = config('org_details.name');
        $from = NULL; // These both variable will be set in CommonController
        $from_name = NULL;
        $acc_verification_url = url('register/verify/' . $remember_token);
        $login_url = url('login');

        $org_data = DB::table('es_organizations')->select('logo_url')->where('organization_id', $organization_id)->first();
        if (isset($org_data->logo_url)) {
            $public_path = $org_data->logo_url;
            $logo_src = env('CONTENT_SERVER_PATH') . $public_path;
            $logo = '<img src="' . $logo_src . '" alt="APIWORX" style="margin-left:30%;width:30%;"><br>';
        } else {
            $logo = '';
        }

        // Check whether custom verification template is created for this company
        $template_setting = DB::table('es_email_template')
            ->select('mail_subject', 'mail_body')
            ->where(['organization_id' => $organization_id, 'mail_type' => 'email_verification_integration', 'active' => 1])
            ->first();
        // if template is not available for any organization then it will get default template.
        if (!$template_setting) {
            $template_setting = DB::table('es_email_template')
                ->select('organization_id', 'mail_subject', 'mail_body')
                ->where(['organization_id' => 0, 'mail_type' => 'email_verification_integration', 'active' => 1])
                ->first();
        }
        if (isset($template_setting) && !empty($template_setting)) {
            $raw_mail_subject = $template_setting->mail_subject;
            $raw_body_content = $template_setting->mail_body;
            $search = array(
                '@org_name', '@name', '@email', '@date', '@date_time', '@login_url', '@logo', '@verification_url'
            );
            $replace = array(
                config('org_details.name'), $request->name, $request->email, date('d-M-Y'), date('d-M-Y H:i A'), '<a href="' . $login_url . '" target="_blank">Login</a>', $logo, $acc_verification_url //'<a href="'.$acc_verification_url.'" target="_blank">Verify Account</a>'
            );
            $subject = str_replace($search, $replace, $raw_mail_subject);
            $body = str_replace($search, $replace, $raw_body_content);
        } else {
            Session::put('fail-msg', 'Email content not found.');
            return redirect('/register');
        }


        //send new user subsription mail to support
        $notifySupport = config('org_details.notify_to_support');
        if ($user_id && $notifySupport) {
            $org_name = config('org_details.org_identity') ? config('org_details.org_identity') : config('org_details.name');
            $data = ['org_name' => ucfirst($org_name), 'name' => $request->name, 'email' => trim($request->email), 'logo' => $logo];
            $notify_body = view('template.notify_support_template', compact('data'))->render();
            $newUserNotifyArr = array(
                'body_msg' => $notify_body,
                'name' => $data['name'],
                'to' => 'support@apiworx.com',
                'to_name' => 'apiworx support',
                'subject' => 'A new user has been subscribed.',
                'from' => $from,
                'from_name' => $from_name,
            );
            $this->sendMail($newUserNotifyArr);
        }

        // Creating array to pass in its respective mail template
        $arrData = array(
            'body_msg' => $body,
            'name' => $request->name,
            'url_link' => $acc_verification_url,
            'to' => $to,
            'to_name' => $request->name,
            'subject' => $subject,
            'from' => $from,
            'from_name' => $from_name,
        );
        $response = $this->sendMail($arrData);

        if ($response == 1) {
            Session::put('info-msg', 'Thank you for registering. To complete your registration please check your email.');
            return redirect('/login');
        } else {
            Session::put('fail-msg', 'Connection could not be established with given host.');
            return redirect('/register');
        }

        return redirect('/register');
    }

    public function confirm($confirmation_code = '')
    {
        if (!$confirmation_code) {
            Session::put('fail-msg', 'Account verification failed please try again.');
            return redirect('/login');
        }

        $user = User::whereRememberToken($confirmation_code)->first();
        if (!$user) {
            Session::put('fail-msg', 'Invalid verification code');
            return redirect('/login');
        }

        $user->confirmed = 1;
        $user->remember_token = null;
        $user->save();
        Session::put('info-msg', 'Account verified successfully please login to continue.');
        return redirect('/login');
    }

    public function forgotPassword()
    {
        $authMethod = $this->checkAuthenticationMethod();
        if ($authMethod && !empty($authMethod)) {
            if ($authMethod->auth_type != 'Basic Auth') {
                $basic_auth = false;
            } else {
                $basic_auth = true;
            }
        } else {
            $basic_auth = true;
        }
        // check if basic auth
        if ($basic_auth === true) {
            return view('auth/forgot_password');
        } else {
            return redirect()->route('login');
        }
    }

    public function logoutUser(Request $request)
    {
        if (Session::has('user_data')) {
            Session::forget('user_data');
        }
        $request->session()->flush();
        return redirect('/login');
    }

    public function requestPass(Request $request)
    {
        $email = $request->email;
        $organization_id = config('org_details.organization_id');
        $user_data = DB::table('users')->where(['organization_id' => $organization_id, 'email' => $email, 'status' => 1, 'role' => 'user'])->first();
        if ($user_data) {
            $uniq_id = uniqid('R') . time();
            $email_id = $user_data->email;
            $reset_url = url("change-password/$uniq_id");
            $login_url = url('login');
            $from = null; // These both variable will be set in CommonController
            $from_name = null;

            $org_data = DB::table('es_organizations')->select('logo_url')->where('organization_id', $user_data->organization_id)->first();
            if (isset($org_data->logo_url)) {
                $public_path = $org_data->logo_url;
                $logo_src = env('CONTENT_SERVER_PATH') . $public_path;
                $logo = '<img src="' . $logo_src . '" alt="APIWORX" style="margin-left:30%;width:30%;"><br>';
            } else {
                $logo = '';
            }

            // Check whether custom verification template is created for this company
            $template_setting = DB::table('es_email_template')
                ->select('mail_subject', 'mail_body')
                ->where(['organization_id' => $user_data->organization_id, 'mail_type' => 'reset_password_integration', 'active' => 1])
                ->first();

            // if template is not available for any organization then it will get default template.
            if (!$template_setting) {
                $template_setting = DB::table('es_email_template')
                    ->select('organization_id', 'mail_subject', 'mail_body')
                    ->where(['organization_id' => 0, 'mail_type' => 'reset_password_integration', 'active' => 1])
                    ->first();
            }
            if (isset($template_setting) && !empty($template_setting)) {
                $raw_mail_subject = $template_setting->mail_subject;
                $raw_body_content = $template_setting->mail_body;
                $search = array(
                    '@org_name', '@name', '@email', '@date', '@date_time', '@login_url', '@logo', '@reset_url'
                );
                $replace = array(
                    config('org_details.name'), $user_data->name, $user_data->email, date('d-M-Y'), date('d-M-Y H:i A'), '<a href="' . $login_url . '" target="_blank">Login</a>', $logo, $reset_url  //'<a href="'.$reset_url.'" target="_blank">Reset</a>'
                );
                $subject = str_replace($search, $replace, $raw_mail_subject);
                $body = str_replace($search, $replace, $raw_body_content);
            } else {
                Session::put('fail-msg', 'Oops, something went wrong!');
                return redirect('/forget-password');
            }

            $arrData = array(
                'body_msg' => $body,
                'name' => $user_data->name,
                'url_link' => $reset_url,
                'to' => $email_id,
                'to_name' => $user_data->name,
                'subject' => $subject,
                'from' => $from,
                'from_name' => $from_name,
                'btn_name' => 'Reset',
                'email' => $email_id,
            );
            $response = $this->sendMail($arrData);

            if (!empty($user_data) && $response == 1) {
                DB::table('users')->where('id', $user_data->id)->update(['remember_token' => $uniq_id, 'remember_token_created_at' => Carbon::now()->addMinutes(60)]);
                Session::put('info-msg', 'We have e-mailed your password reset link!');
                return redirect('/forget-password');
            } else {
                Session::put('fail-msg', 'Error occured please try again!');
                return redirect('/forget-password');
            }
        } else {
            // Session::put('fail-msg', 'No user found!');
            Session::put('info-msg', 'Reset information successfully sent to email.');
            return redirect('/forget-password');
        }
    }

    public function showResetForm(Request $request)
    {
        $token = $request->token;
        return view('auth.change_password', ['token' => $token]);
    }

    public function changePass(Request $request)
    {
        if ($request->isMethod('post')) {
            $rules = [
                'password' => 'required|min:12|same:confirm_password|regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{12,}$/',
                'confirm_password' => 'required|min:12',
            ];

            $input = $request->only(
                'password',
                'confirm_password'
            );

            $messages = [
                'regex' => 'Password must be strong with the length of 12 characters and combination of uppercase characters (A – Z), lowercase characters (a – z), Base 10 digits (0 – 9), Non-alphanumeric (For example: !, $, #, or %).'
            ];

            $validator = Validator::make($input, $rules, $messages);

            if ($validator->fails()) {
                $messages = $validator->messages();
                Session::put('fail-msg', implode(' | ', $messages->all()));
                return redirect()->back();
            }

            $password = $request->password;
            $token = $request->token;
            $enc_pass = bcrypt($password);

            $user_data = DB::table('users')->where(['remember_token' => $token, 'status' => 1, 'role' => 'user'])->where('remember_token_created_at', '>', Carbon::now()->format('Y-m-d H:i:s'))->first();
            if ($user_data) {
                if ($user_data->password == $enc_pass) {
                    Session::put('fail-msg', 'The previous password cannot be reused');
                    return redirect()->back();
                } else {
                    DB::table('users')->where('id', $user_data->id)->update(['password' => $enc_pass, 'remember_token' => null, 'confirmed' => 1]);
                    Session::put('info-msg', 'Your password has been changed!');
                    return redirect('/login');
                }
            } else {
                Session::put('fail-msg', 'Invalid or token expired');
                return redirect('/forget-password');
            }
        }
    }

    public function userProfile()
    {
        if (isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')])) {
            return view('errors.404');
        }
        $user_data =  Session::get('user_data');
        $uid = $user_data['id'];
        $org_id = $user_data['organization_id'];
        $company_id = $user_data['company_id'];

        $user_info = DB::table('users')->where(['id' => $uid, 'status' => 1])->first();

        $notification_email = NotificationEmail::select('emails')->where('user_id', Auth::user()->id)->first();

        $timezoneInfo = "";
        $timezoneInfo = DB::table('users_information as ui')
            ->join('es_timezone as tz', 'tz.ISO_country_code', 'ui.iso_country_code')
            ->select('ui.iso_country_code as id', DB::raw("CONCAT(tz.country,' / ',tz.ISO_country_code, ' (',tz.timezone,')') AS text"))
            ->where('ui.user_id', $uid)
            ->first();

        return view("pages.user_profile", compact("user_info", "timezoneInfo", "notification_email"));
    }

    public function getUsersTimezone()
    {
        $user_data =  Session::get('user_data');
        $uid = $user_data['id'];
        $data_status = [];
        $timezoneInfo = "";
        $timezoneInfo = DB::table('users_information as ui')
            ->join('es_timezone as tz', 'tz.ISO_country_code', 'ui.iso_country_code')
            ->select('ui.iso_country_code', 'tz.country', 'tz.ISO_country_code', 'tz.timezone')
            ->where('ui.user_id', $uid)
            ->first();
        if ($timezoneInfo) {
            $data_status['status_code'] = 1;
            $data_status['timezone'] = $timezoneInfo->timezone;
        } else {
            $data_status['status_code'] = 0;
            $data_status['timezone'] = '';
        }



        return json_encode($data_status);
    }

    public function UpdateProfile(Request $request)
    {
        $name = $request->name;

        $user_data =  Session::get('user_data');
        $uid = $user_data['id'];

        $data_stat = [];

        $user_data_info = DB::table('users')->select('id')->where(['id' => $uid])->first();

        if ($user_data_info) {
            /*$existingPic = DB::table('users')->select('profile_pic_path')->where('id', $user_data_info->id)->first();
            if ((isset($request->profile_pic) && $file = $request->file('profile_pic'))) {
                $filename = time() . '-' . $file->getClientOriginalName();
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $filename = time() . '-' . rand() . '.' . $ext;
                $target_dir = "public/shared_files/devs_profile_pic/";

                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                    if (!file_exists($target_dir)) {
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Error in folder creation try again';
                        return json_encode($data);
                    }
                }

                if (!empty($existingPic)) {
                    $path = $existingPic->profile_pic_path;
                    if (file_exists($path)) {
                        unlink($path);
                    };
                }

                $path = public_path($target_dir . '/' . $filename);
                $img = $file->move($target_dir, $filename);
                $picpath = $target_dir . $filename;
                $result = $obj->makeUpdate2('users', ['name' => $name, 'profile_pic_path' => $picpath], ['id' => $user_data_info->id]);
            } else if (!isset($request->profile_pic) && !empty($existingPic)) {
                $result = $obj->makeUpdate2('users', ['name' => $name, 'profile_pic_path' => NULL], ['id' => $user_data_info->id]);
            } else {
                $result = $obj->makeUpdate2('users', ['name' => $name], ['id' => $user_data_info->id]);
            }*/

            $result = DB::table('users')->where(['id' => $user_data_info->id])->update(['name' => $name]);

            if ($result == 0) {
                $user_data['name'] = $name;
                Session::put('user_data', $user_data);
                $data_stat['status_code'] = 2;
                $data_stat['name'] = $name;
                $data_stat['status_text'] = "No change detected";
            } else {
                if ($result) {
                    $user_data['name'] = $name;
                    Session::put('user_data', $user_data);
                    $data_stat['status_code'] = 1;
                    $data_stat['name'] = $name;
                    $data_stat['status_text'] = 'Profile updated successfully!';
                } else {
                    $data_stat['status_code'] = 0;
                    $data_stat['status_text'] = 'Profile updation failed!';
                }
            }
        } else {
            $data_stat['status_code'] = 0;
            $data_stat['status_text'] = 'Profile updation failed!';
        }

        return json_encode($data_stat);
    }

    public function UpdatePassword(Request $request)
    {
        $password = $request->password;
        $user_data =  Session::get('user_data');
        $uid = $user_data['id'];

        $enc_pass = bcrypt($password);

        $data_stat = [];

        $user = DB::table('users')->select('id', 'password')->where(['id' => $uid])->first();

        if (Hash::check($request->current_password, $user->password)) {
            DB::table('users')->where(['id' => $user->id])->update(['password' => $enc_pass]);
            $data_stat['status_code'] = 1;
            $data_stat['status_text'] = 'Password updated successfully!';
        } else {
            $data_stat['status_code'] = 0;
            $data_stat['status_text'] = 'Your password was not updated, since the provided current password does not match.';
        }

        return json_encode($data_stat);
    }

    public function getTimezoneList(Request $request)
    {
        $searchVal = ($request['term']) ? $request['term'] : '';
        if (!$searchVal) {
            return response()->json([
                "items" => '',
                "pagination" => ["more" => true]
            ]);
        }

        $listTimezone = DB::table('es_timezone')->where('country', 'like', '%' . $searchVal . '%')
            ->orWhere('ISO_country_code', 'like', '%' . $searchVal . '%')
            ->select('ISO_country_code as id', DB::raw("CONCAT(country,' / ',ISO_country_code, ' (',timezone,')') AS text"))->get();

        return response()->json([
            "items" => $listTimezone,
            "pagination" => ["more" => true]
        ]);
    }

    public function UpdateTimeZone(Request $request)
    {
        $user_data =  Session::get('user_data');
        $uid = $user_data['id'];
        $data_stat = [];

        if ($request->timezone) {
            //update in table users_information
            $checkUserInfo = DB::table('users_information')->where('user_id', $uid)->select('id')->first();
            if ($checkUserInfo) {
                //update 
                $ssdad = DB::table('users_information')->where('id', $checkUserInfo->id)->update([
                    "iso_country_code" => $request->timezone
                ]);
                $data_stat['status_code'] = 1;
                $data_stat['status_text'] = 'Timezone updated successfully!';
            } else {
                //insert
                DB::table('users_information')->insert([
                    "user_id" => $uid,
                    "iso_country_code" => $request->timezone,
                ]);

                $data_stat['status_code'] = 1;
                $data_stat['status_text'] = 'Timezone set successfully!';
            }
        } else {
            $data_stat['status_code'] = 0;
            $data_stat['status_text'] = 'Timezone not added!';
        }
        return json_encode($data_stat);
    }


    public function updateNotificationEmail(Request $request)
    {
        Validator::extend('without_spaces', function ($attr, $value) {
            return preg_match('/^\S*$/u', $value);
        });

        Validator::replacer('without_spaces', function ($message, $attribute, $rule, $parameters) {
            return str_replace(':field', '', 'Email space not allowed.');
        });

        /* create custom validation for multiple email on comma separated */
        Validator::extend("multiple_emails", function ($attribute, $value, $parameters) {
            $rules = ['email' => 'required|email|without_spaces'];
            foreach (explode(',', rtrim($value, ',')) as $email) {
                if (trim($email)) {
                    $data = ['email' => trim($email)];
                    $validator = Validator::make($data, $rules);
                    if ($validator->fails()) {
                        return false;
                    }
                }
            }
            return true;
        });

        Validator::replacer('multiple_emails', function ($message, $attribute, $rule, $parameters) {
            return str_replace(':field', '', 'The email must be a valid email address.');
        });

        $rule = ['emails' => 'multiple_emails'];

        $request->validate($rule);

        $data_stat = [];
        $es_notification_email = NotificationEmail::select('id')->where('user_id', Auth::user()->id)->first();
        if (is_null($es_notification_email)) {
            if (trim($request->emails)) {
                //insert
                NotificationEmail::create(["user_id" => Auth::user()->id, "emails" => $request->emails]);

                $data_stat['status_code'] = 1;
                $data_stat['status_text'] = 'Notification email added successfully!';
            } else {
                $data_stat['status_code'] = 2;
                $data_stat['status_text'] = 'Notification email field is required.';
            }
        } else {
            //update
            if (trim($request->emails)) {
                NotificationEmail::where('id', $es_notification_email->id)->update(["emails" => $request->emails]);
            } else {
                NotificationEmail::where('id', $es_notification_email->id)->update(["emails" => NULL]);
            }
            $data_stat['status_code'] = 1;
            $data_stat['status_text'] = 'Notification email updated successfully!';
        }
        return json_encode($data_stat);
    }

    // Function to get domain name from given url
    public static function getDomainName($url)
    {
        $url = substr($url, 0, 4) == 'http' ? $url : 'http://' . $url;
        $d = parse_url($url);
        $tmp = explode('.', $d['host']);
        $n = count($tmp);
        if ($n >= 2) {
            if ($n == 4 || ($n == 3 && strlen($tmp[($n - 2)]) <= 3)) {
                $d['domain'] = $tmp[($n - 3)] . "." . $tmp[($n - 2)] . "." . $tmp[($n - 1)];
                $d['domainX'] = $tmp[($n - 3)];
            } else {
                $d['domain'] = $tmp[($n - 2)] . "." . $tmp[($n - 1)];
                $d['domainX'] = $tmp[($n - 2)];
            }
        }
        return $d;
    }

    // Common function to send email
    public static function sendMail($data, $organization_id = null)
    {
        $flag = false;
        if (!isset($organization_id)) {
            $organization_id = config('org_details.organization_id');
        }

        $organization_id = config('org_details.organization_id');
        if (isset($organization_id)) {
            $default_mail = DB::table('es_email_settings')->where(['organization_id' => $organization_id, 'active' => 1, 'is_default' => 1])->first();
            if ($default_mail && !empty($default_mail)) {
                if (isset($default_mail->smtp_host) && $default_mail->is_custom_smtp == 1) {
                    $flag = true;
                } else {
                    $flag = false;
                }
            } else {
                $flag = false;
            }
        } else {
            $flag = false;
            $default_mail = [];
        }

        if ($flag) {
            $from_email = $default_mail->from_email;
            $from_name = $default_mail->from_name;
            $smtp_host = $default_mail->smtp_host;
            $smtp_encryption = $default_mail->smtp_encryption;
            $smtp_port = $default_mail->smtp_port;
            $smtp_username = $default_mail->smtp_username;
            $smtp_password = $default_mail->smtp_password;

            $config = array(
                'driver' => 'smtp',
                'host' => $smtp_host,
                'port' => (int) $smtp_port,
                'from' => [
                    'address' => $smtp_username,
                    'name' => $from_name,
                ],
                'encryption' => $smtp_encryption,
                'username' => $smtp_username,
                'password' => $smtp_password,
                'sendmail' => '/usr/sbin/sendmail -bs',
                'pretend' => false,
                'stream' => [
                    'ssl' => [
                        'allow_self_signed' => true,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]
            );
            Config::set('mail', $config); // override default SMTP details
            DB::beginTransaction();
            Mail::to($data['to'])->send(new DefaultMailable($data));
            if (Mail::failures()) {
                $response = 0;
            } else {
                $response = 1;
            }
        } else {
            if ($default_mail && !empty($default_mail)) {
                $data['from'] = $default_mail->from_email;
                $data['from_name'] = $default_mail->from_name;
            } else {
                $data['from'] = env('MAIL_REGISTRATION_FROM_ADDRESS');
                $data['from_name'] = env('MAIL_FROM_NAME');
            }
            
            $commonContrl = new CommonController();
            $response = $commonContrl->sendMailByDefaultConfiguration($data);
        }

        return $response;
    }

    public function sendMailByDefaultConfiguration($data){
            $key = $this->defaultMailgunApiKey;
            $app_url = $this->defaultMailgunApiUrl;
            $mailgunKey = "api:" . $key;
            $html_content = $data['body_msg'];
            $curl_post_data = [
                'from' => ($data['from_name'] . ' <' . $data['from'] . '>'),
                'to' => $data['to'],
                'subject' => $data['subject'],
                'html' => $html_content,
                'o:tracking-clicks' => False
            ];
            $service_url = $app_url . '/messages';
            $curl = curl_init($service_url);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $mailgunKey);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $curl_post_data);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            if (!$response = curl_exec($curl)) {
                //$response = curl_error($curl);
                $response = 0;
            } else {
                $response = 1;
            }
            curl_close($curl);
            return $response;
    }

    public function checkAuthenticationMethod()
    {
        $org_id = config('org_details.organization_id');
        return  DB::table('es_auth_config')->where(['organization_id' => $org_id])->first();
    }

    public function encryptText(Request $request)
    {
        $mobj = new MainModel;
        $str = $request->text;
        return $mobj->encryptString($str);
    }

    public function decryptText(Request $request)
    {
        $mobj = new MainModel;
        $str = $request->text;
        return $mobj->decryptString($str);
    }

    public function sendInitialDataSyncedNotification()
    {
        try {
            $pending_intg = DB::table('user_workflow_rule AS usrWfRl')->select('user_integration_id')
                ->where(['status' => 1, 'is_notification_sent' => 0])
                ->where(function ($query1) {
                    $query1->where('is_all_data_fetched', 'pending')
                        ->orWhere('is_all_data_fetched', 'inprocess');
                })
                ->get();
            /** Query to get those user_integration_id from its group which fetched status is not completed. This is to skip these ids in next query */
            $arr_pending_intg = [];
            if ($pending_intg) {
                foreach ($pending_intg as $pending) {
                    $arr_pending_intg[] = $pending->user_integration_id;
                }
            }

            //events hardcode removed
            $users_to_notify = DB::table('user_workflow_rule AS usrWfRl')
                ->select(
                    'usrWfRl.user_integration_id',
                    'usr.email',
                    'usr.name',
                    'usr.organization_id',
                    'org.name AS organization_name',
                    'org.logo_url AS organization_logo_url',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfEvtDc.event_name as event_type'
                )
                ->join('users AS usr', 'usr.id', '=', 'usrWfRl.user_id')
                ->join('es_organizations AS org', 'org.organization_id', '=', 'usr.organization_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->where(['usrWfRl.is_all_data_fetched' => 'completed', 'usrWfRl.status' => 1, 'usrWfRl.is_notification_sent' => 0])
                ->whereNotIn('usrWfRl.user_integration_id', $arr_pending_intg)
                ->groupBy('usrWfRl.user_integration_id', 'pfEvtDc.event_id')
                ->get();

            if (count($users_to_notify)) {
                $result = json_decode($users_to_notify, true);
                $user_intg_data = array();

                foreach ($result as $val) {
                    if (!array_key_exists($val['user_integration_id'], $user_intg_data)) {
                        $user_intg_data[$val['user_integration_id']] = $val;
                    } else {
                        $user_intg_data[$val['user_integration_id']]['event_type'] .= ' | ' . $val['event_type'];
                    }
                }

                foreach ($user_intg_data as $data) {
                    $org_id = $data['organization_id'];
                    $to = trim($data['email']);
                    $org_name = $data['organization_name'];
                    $from = NULL; // These both variable will be set in CommonController
                    $from_name = NULL;
                    $flow_info = $data['source_platform_name'] . " and " . $data['dest_platform_name'] . " (" . $data['event_type'] . ")";
                    $login_url = url('login');

                    if (isset($data['organization_logo_url'])) {
                        $public_path = $data['organization_logo_url'];
                        $logo_src = env('CONTENT_SERVER_PATH') . $public_path;
                        $logo = '<img src="' . $logo_src . '" alt="Logo" style="margin-left:30%;width:30%;"><br>';
                    } else {
                        $logo = '';
                    }

                    // Check whether custom verification template is created for this company
                    $template_setting = DB::table('es_email_template')
                        ->select('mail_subject', 'mail_body')
                        ->where(['organization_id' => $org_id, 'mail_type' => 'init_data_sync_notification', 'active' => 1])
                        ->first();

                    if (isset($template_setting) && !empty($template_setting)) {
                        $raw_mail_subject = $template_setting->mail_subject;
                        $raw_body_content = $template_setting->mail_body;
                        $search = array(
                            '@org_name', '@name', '@email', '@date', '@date_time', '@login_url', '@logo', '@flow_info'
                        );
                        $replace = array(
                            $org_name, $data['name'], $data['email'], date('d-M-Y'), date('d-M-Y H:i A'), '<a href="' . $login_url . '" target="_blank">Login</a>', $logo, $flow_info
                        );
                        $subject = str_replace($search, $replace, $raw_mail_subject);
                        $body = str_replace($search, $replace, $raw_body_content);
                    } else {
                        Session::put('fail-msg', 'Email content not found.');
                        return redirect('/register');
                    }

                    // Creating array to pass in its respective mail template
                    $arrData = array(
                        'body_msg' => $body,
                        'name' => $data['name'],
                        'to' => $to,
                        'to_name' => $data['name'],
                        'subject' => $subject,
                        'from' => $from,
                        'from_name' => $from_name,
                    );
                    $response = $this->sendMail($arrData, $org_id);

                    // update user integration flow record to be notification sent
                    if ($response == 1) {
                        DB::table('user_workflow_rule')->where(['user_integration_id' => $data['user_integration_id'], 'status' => 1, 'is_all_data_fetched' => 'completed', 'is_notification_sent' => 0])
                            ->update(['is_notification_sent' => 1]);
                    }
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }

    //send email Notification
    public function sendEmailNotification($user_id, $subject, $body)
    {
        //send email notification
        $notification_email = NotificationEmail::select('emails')->where('user_id', $user_id)->first();

        if (isset($notification_email) && isset($notification_email->emails)) {

            $email_ids = explode(',', $notification_email->emails);

            foreach ($email_ids as $email) {

                $arrData = array(
                    'body_msg' => $body,
                    'name' => '',
                    'to' => $email,
                    'to_name' => '',
                    'subject' => $subject,
                    'from' => null,
                    'from_name' => null,
                );

                $this->sendMail($arrData, null);
            }
        }
    }
    
    //find duplicate product & delete 
    public function findDuplicateProductAndDelete($user_integration_id,$platform_id)
    {   
        
        //part 1
        $results = DB::select( DB::raw("SELECT 
        `id`,`api_product_id`, `linked_id`, COUNT(api_product_id) FROM platform_product WHERE `user_integration_id`='$user_integration_id' AND `platform_id`='$platform_id' GROUP BY api_product_id HAVING COUNT(api_product_id) > 1 limit 10") );
        
        $duplicate_api_product_id = [];
        $duplicate_products = json_decode(json_encode($results), true);
        if( count($duplicate_products) > 0 ) {
            foreach( $duplicate_products as $product) {
                array_push($duplicate_api_product_id, $product['api_product_id'] );
            }
        }

        

        //part 2 store product Ids to keep... addition check add linked_id == 0  for delete
        $list_product_ids_for_delete = [];
        $processedApiProductIds= [];
        $skipValidProductIds = [];

        //find products for delete
        $find_product_for_delete = DB::table('platform_product')->select('id','api_product_id','linked_id')
        ->where(['user_integration_id'=>$user_integration_id,'platform_id'=>$platform_id])->whereIn('api_product_id',$duplicate_api_product_id)->select('id','api_product_id','linked_id')->orderBy('api_product_id','asc')->get();


        if(count($find_product_for_delete) > 0) {

            foreach( $find_product_for_delete as $product ) {

                //add processed api product ids in processedApiProductIds... for keep 1 record always
                if(!in_array($product->api_product_id, $processedApiProductIds)) {
                    array_push($processedApiProductIds,$product->api_product_id);
                    //skip 1 product ids for delete...store id to ignore
                    array_push($skipValidProductIds,$product->id);
                }  
                
                //push product ids for delete
                if($product->linked_id==0) {
                    array_push($list_product_ids_for_delete,$product->id);
                }
            }
        }


    


        //part 3 delete 
        if($list_product_ids_for_delete) {

            //get for varify
            // $delete_data = DB::table('platform_product')->whereIn('id',$list_product_ids_for_delete)->whereNotIn('id',$skipValidProductIds)
            // ->select('id','api_product_id','linked_id')->orderBy('api_product_id','asc')->get();
            // dd($delete_data, $skipValidProductIds);
            
            \Storage::disk('local')->append('delete_duplicate_product_log.txt', 'user_integration_id: ' . $user_integration_id. ' platform_id : '.$platform_id . ' skipped product ids : '. json_encode($skipValidProductIds).PHP_EOL );
            
            //delete
            DB::table('platform_product')->whereIn('id',$list_product_ids_for_delete)->whereNotIn('id',$skipValidProductIds)->delete();

        }
    

    }

    /**
     * If an old refresh token is about to expire, this cron is in sending the user a refresh token message.
     */
    public function sendRefreshTokenReSyncNotification( $id, $user_integration_id )
    {
        $return_response = true;
        try {     
            $accountArr = DB::table('platform_accounts as pa')
            ->where( [
                'pa.id' => $id,
                'pa.status' => 1,
                'pa.allow_refresh' => 1,
                'pa.allow_reauth_refresh' => 0
            ] )
            ->select( 'pa.id as id', 'pa.platform_id as platform_id', 'pa.account_name', 'pa.refresh_expires_in', 'pa.last_refreshed_at', 
                        'usr.name', 'usr.email', 'usr.id as uid', 'usr.organization_id',
                        'pl.reauth_in_days' )
            ->join( 'users AS usr', 'usr.id', '=', 'pa.user_id')
            ->join( 'platform_lookup AS pl', 'pl.id', '=', 'pa.platform_id')
            ->first();

            if( $accountArr ){

                $from = NULL; // These both variable will be set in CommonController
                $from_name = NULL;
                // foreach( $accountArr as $data )
                $data = $accountArr;
                {
                    //check last_refreshed_at 
                    $last_refreshed_at  = $data->last_refreshed_at;
                    $reauth_in_days = $data->reauth_in_days; //365

                    //start before 30 days for now
                    $start_reauth_check_before_days = 30;
                    $currentDateTime = new DateTime();

                    $next_reauth_date = new DateTime($last_refreshed_at);
                    $next_reauth_date->add(new DateInterval("P" . ($reauth_in_days - $start_reauth_check_before_days) . "D"));

                    //calculate expire in days
                    $expired_in_day = new DateTime($last_refreshed_at);
                    $expired_in_day->add(new DateInterval("P" . $reauth_in_days. "D"));

                    $day_diff = $expired_in_day->diff($next_reauth_date)->format('%a');

                    if ( $next_reauth_date < $currentDateTime ) {
                        /**
                         *  sneha@apiworx.com
                         *  support@apiworx.com
                         *  subhadra@apiworx.com
                         *  nida@apiworx.com
                         */
                        // $to = "shubham.constacloud2@gmail.com, gautamk.constacloud@gmail.com";
                        $to = \Config::get('apisettings.sendRefreshTokenReSyncNotification');
                        $organization_id = $data->organization_id;

                        $integrationDetails = UserIntegration::select( 'id', 'flow_name', 'selected_sc_account_id', 'selected_dc_account_id' )
                        ->where( [
                            'id' => $user_integration_id,
                            'selected_dc_account_id' => $data->id,
                        ])->first();
                        
                        if( !$integrationDetails ){
                            $integrationDetails = UserIntegration::select( 'id', 'flow_name', 'selected_sc_account_id', 'selected_dc_account_id' )
                            ->where( [
                                'id' => $user_integration_id,
                                'selected_sc_account_id' => $data->id,
                            ])->first();
                        }

                        //store all organizations data in array by single query to reduce database call
                        $organization_data_arr = [];
                        $org_data = DB::table( 'es_organizations' )
                            ->select( 'organization_id', 'logo_url', 'name' )
                            ->where( 'status', 1 )
                            ->get();

                        for( $i=0; $i < count( $org_data ); $i++ )
                        {	
                            $organization_data_arr[$org_data[$i]->organization_id] = [
                                'name'=>$org_data[$i]->name,
                                'logo_url'=>$org_data[$i]->logo_url
                            ];
                        }
                        
                        //get organization details
                        $organization_name = "";
                        if ( array_key_exists( $organization_id, $organization_data_arr ) ){
                            $organization_name = $organization_data_arr[$organization_id]['name'];                            
                        } 
                        
                        $template_setting = true;

                        if ( $template_setting ) {
                            
                            $subject = "Snowflake Refresh Token Expiration";
                            $body = "
                            The refresh token for <b>".$data->account_name."</b> account is going to invalided in <b>".$day_diff."</b> days, Please perform re-authentication in order to refresh the token.
                            <br>
                            <p><b>Integration Name : </b>".$integrationDetails->flow_name."</p>
                            <p><b>integration ID: </b>".$user_integration_id."</p>
                            <p><b>User Name: </b>".$data->name."</p>
                            <p><b>User email: </b>".$data->email."</p>
                            <a href='".env('APP_URL')."/../integration_flow/".$user_integration_id."' style='display:inline-block;color:#ffffff;background-color:#3498db;border:solid 1px #3498db;border-radius:5px;box-sizing:border-box;text-decoration:none;font-size:14px;font-weight:bold;margin-bottom:10px;padding:6px 15px;text-transform:capitalize;border-color:#3498db' target='_blank' >
                                Take Action
                            </a>
                            <br>
                            Regards,<br>
                            ".$organization_name;
                            // <p><b>Take Action: </b>Open URL: ".env('APP_URL')." >> Login with Master Account >> Search Email: <b>".$data->email."</b> >> Login as search user >> Goto Active Integrations >> View Details in <b>".$integrationDetails->flow_name."</b> >> ReAuth SnowFlake Account </p>

                            // Creating array to pass in its respective mail template
                            $arrData = [
                                'body_msg' => $body,
                                'name' => $data->name,
                                'to' => $to,
                                'to_name' => $data->name,
                                'subject' => $subject,
                                'from' => $from,
                                'from_name' => $from_name,
                            ];
                            $response = $this->sendMail( $arrData );

                            // update user account to be notification sent with open re-auth btn
                            if ( $response == 1 ) {
                                $accountArr = PlatformAccount::find( $id );
                                $accountArr->allow_reauth_refresh = 1;
                                $accountArr->save();
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error( $user_integration_id . "-- SnowflakeApiController sendRefreshTokenReSyncNotification -->" . $e->getMessage() . '-->' . $e->getLine());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }
}
