<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Session;
use App\MainModel;
use DB;

use function GuzzleHttp\json_encode;

class AuthenticationMethodController extends Controller
{
    public static $OAUTH_REDIRECT_PATH = "/oauth_authenticate";

    public function SamlError(Request $request) {
        return view('pages.saml.saml_error');
    }

    public static function FormatX509Certificate($cert, $heads = true) // Format x509 certificate code
    {
        $x509cert = str_replace(array("\x0D", "\r", "\n"), "", $cert);
        if (!empty($x509cert)) {
            $x509cert = str_replace('-----BEGIN CERTIFICATE-----', "", $x509cert);
            $x509cert = str_replace('-----END CERTIFICATE-----', "", $x509cert);
            $x509cert = str_replace(' ', '', $x509cert);

            if ($heads) {
                $x509cert = "-----BEGIN CERTIFICATE-----\n".chunk_split($x509cert, 64, "\n")."-----END CERTIFICATE-----\n";
            }
        }
        return $x509cert;
    }

    public function ValidateX509Certificate($data)  // Will validate x509 certificate
    {
        $pattern = '/^-----BEGIN CERTIFICATE-----([^-]*)^-----END CERTIFICATE-----/m';
        if (false == preg_match($pattern, $data, $matches)) {
            // throw new \InvalidArgumentException('Invalid PEM encoded certificate');
            return ['status_code'=>0,'status_text'=>'Invalid X.509 certificate. Please try again'];
        }

        try{
           openssl_x509_read($data);
           $res = ['status_code'=>1,'status_text'=>'Success'];
        }catch(\Exception $e){
            $msg = $e->getMessage();
            $res = ['status_code'=>0,'status_text'=>"Invalid X.509 certificate. $msg "];
        }
        return $res;
    }

    public function getSamlConfig($saml_config,$auth_config_id)
    {
        $error = 0;

            $config_i = DB::table('es_auth_config')->leftJoin('es_organizations','es_organizations.organization_id','=','es_auth_config.organization_id')
            ->where(['es_auth_config.id'=>$auth_config_id,'es_auth_config.auth_type'=>'SAML 2.0','es_auth_config.status'=>1])
            ->select('es_organizations.access_url','es_auth_config.id','es_auth_config.x509_certificate'
            ,'es_auth_config.login_url','es_auth_config.logout_url')->first();

            if($config_i){
                $entityId = '';
                if(env('APP_ENV')!='local' && $config_i->access_url){
                    $acs_url = 'https://'.$config_i->access_url.'/'.'saml2'.'/'.$config_i->id.'/acs';
                    $sls_url = 'https://'.$config_i->access_url.'/'.'saml2'.'/'.$config_i->id.'/sls';
                    $entityId = 'https://'.$config_i->access_url.'/'.'saml2'.'/'.$config_i->id.'/metadata';
                }else{
                    $acs_url = env('DEV_SAML_URL').'/'.$config_i->id.'/acs';
                    $sls_url = env('DEV_SAML_URL').'/'.$config_i->id.'/sls';
                    $entityId = env('DEV_SAML_URL').'/'.$config_i->id.'/metadata';
                }

                // Start: SP Configuration
                if($entityId && isset($saml_config['sp']) && isset($saml_config['sp']['entityId'])){
                    $saml_config['sp']['entityId'] = $entityId;
                }
                if(isset($saml_config['sp']) && isset($saml_config['sp']['assertionConsumerService'])){
                    $saml_config['sp']['assertionConsumerService']['url'] = $acs_url;
                }
                if(isset($saml_config['sp']) && isset($saml_config['sp']['singleLogoutService'])){
                    $saml_config['sp']['singleLogoutService']['url'] = $sls_url;
                }
                // End: SP Configuration

                // Start: IDP Configuration
                if($entityId && isset($saml_config['idp']) && isset($saml_config['idp']['entityId'])){
                    $saml_config['idp']['entityId'] = $entityId;
                }
                if(isset($saml_config['idp']) && isset($saml_config['idp']['singleSignOnService'])){
                    $saml_config['idp']['singleSignOnService']['url'] = $config_i->login_url;
                }
                if(isset($saml_config['idp']) && isset($saml_config['idp']['singleLogoutService'])){
                    $saml_config['idp']['singleLogoutService']['url'] = $config_i->logout_url;
                }
                if(isset($saml_config['idp']) && isset($saml_config['idp']['x509cert'])){
                    $saml_config['idp']['x509cert'] = $this->FormatX509Certificate($config_i->x509_certificate,false);
                }

                return ['status_code'=>1,'status_text'=>'Success','response'=>$saml_config];
                // End: IDP Configuration
            }else{
                $error = 1;
            }

        if($error){
            $response = view('pages.saml.saml_error')->render();
            return ['status_code'=>0,'status_text'=>'Failed','response'=>$response];
        }

    }

    public function decryptToken($key){
        return base64_decode($key);
    }

    public function SamlRedirectHandler(){
        if(isset($_COOKIE['user_id'])) {
            $user_id = $_COOKIE['user_id'];
            if(Auth::loginUsingId($user_id)){
                $user_data = Auth::user();
                Session::put('user_data', $user_data);
                setcookie('user_id', '', time() - 3600, '/');
                return redirect('/');
            }
        }else{
            return view('pages.saml.saml_error');
        }
    }

    /*public static function checkUserAuthrization(){
        if(Auth::user()){
            return true;
        }
        else{
            return false;
        }
    }*/
}
