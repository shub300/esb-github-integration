<?php

namespace App\Http\Controllers\Google;
use Google\Client;
use App\Helper\MainModel;
use Google\Service\Oauth2;
use App\Models\PlatformApiApp;
use App\Models\PlatformLookup;
use App\Models\PlatformAccount;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;

class GoogleAuthController extends Controller
{
    private $platformName;
    public $client, $platformId, $mobj;
    public $canLogIn = false;
    public function __construct($platformName, $isNull = null)
    {
        $this->platformName = $platformName;
        $this->mobj = new MainModel();
        if($isNull == null){
            $this->client = $this->getClient();
        }
    }

    public function getPlatformId()
    {
        $platform = PlatformLookup::where('platform_id', '=', $this->platformName)->first();
        return $platform->id;
    }

    public function InitiateGoogleAuth()
    {
        $platform = $this->platformName;
        if($this->canLogIn){
            return redirect()->away($this->authUrl);
            $authUrl = $this->authUrl;
            return view("pages.apiauth.auth_googlesheet", compact('platform','authUrl'));
        }
        return view("pages.apiauth.auth_googlesheet", compact('platform'));
    }

    public function getClient()
    {
        $data = [];
        list($clientId, $clientSecret) = $this->getClientCredential();
        try{
            $client = new Client();
            $client->setApplicationName(Config::get('apiconfig.GOOGLE_APPLICATION_NAME'));
            $client->setScopes(Config::get('apiconfig.GoogleSheetScope'));
            $client->setClientId($clientId);
            $client->setClientSecret($clientSecret);
            $client->setRedirectUri(env('APP_URL').'/google/authback');
            $client->setAccessType('offline');
            if(!$client->getAccessToken()){
                $this->canLogIn = true;
                $this->authUrl = $client->createAuthUrl();
            }
            return $client;
        }catch(\Exception $e){
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }

    public function getAuthUrlForToken( $code = '', $error = '' )
    {
        try{
            list($clientId, $clientSecret) = $this->getClientCredential();
            $client = new Client();
            $client->setApplicationName(Config::get('apiconfig.GOOGLE_APPLICATION_NAME'));
            $client->setScopes(Config::get('apiconfig.GoogleSheetScope'));
            $client->setClientId($clientId);
            $client->setClientSecret($clientSecret);
            $client->setRedirectUri(env('APP_URL').'/google/authback/token');
            $client->setAccessType('offline');
            $userData = Session::get('user_data');
            $userId = $userData['id'];
            if ( $code || $error ) {
                if ( $error ) return $error;
                $returnData = $client->authenticate($code);
                if(!in_array('error', $returnData)){
                    $tokenData = $client->getAccessToken();
                    $client->setAccessToken($tokenData);
                    $platformId = $this->getPlatformId();
                    // GET LOGGED USER INFO
                    $oauthUserData = new Oauth2($client);
                    $name = $oauthUserData->userinfo->get()['name'];
                    if ( isset($oauthUserData->userinfo->get()['email']) ) {
                        $email = $oauthUserData->userinfo->get()['email'];
                        $email = explode('@', $email);
                        $name = ((count($email) > 0) ? $email[0] : $name);
                    }
                    $check_account = PlatformAccount::where([
                        'user_id' => $userId,
                        'account_name' => $name,
                        'platform_id' => $platformId
                    ])->first();
                    if($check_account){
                        if(!isset($tokenData['refresh_token'])){
                            return 'No Need to get new token';
                        }else{
                            $env_type = 'production';
                            $google_data = array(
                                'user_id' => $userId,
                                'platform_id' => $platformId,
                                'account_name' => $name,
                                'refresh_token' => $this->mobj->encrypt_decrypt($tokenData['refresh_token']),
                                'access_token' => $this->mobj->encrypt_decrypt($tokenData['access_token']),
                                'env_type' => $env_type,
                                'token_type' => $tokenData['token_type'],
                                'expires_in' => $tokenData['expires_in'],
                                'token_refresh_time' => $tokenData['created']
                            );
                            $check_account->update($google_data);
                            return 'Account token refreshed successfully.';
                        }
                    } else {
                        $client->revokeToken();
                        return 'Account not found for this google account.';
                    }
                }else{
                    return $returnData['error_description'];
                }
            } else {
                $auth = '';
                if(!$client->getAccessToken()){
                    $auth = $client->createAuthUrl();
                }
                if (filter_var($auth, FILTER_VALIDATE_URL)) {
                    return redirect()->away($auth);
                } else {
                    return 'There is something wrong. Please try again!';
                }
            }
        }catch(\Exception $e){
            return $e->getMessage();
        }
    }

    protected function getClientCredential()
    {
        $platformId = $this->getPlatformId();
        $credential = PlatformApiApp::where('platform_id', '=', $platformId)->first();
        return array($credential->client_id, $credential->client_secret);
    }

    public function getClientAuth($code, $error = false)
    {
        $userData = Session::get('user_data');
        $userId = $userData['id'];
        $data = [];
        if($error){
            $data['status_code'] = 0;
            $data['status_text'] = $error;
            return json_encode($data);
        }elseif(isset($code)){
            try{
                $returnData = $this->client->authenticate($code);
                if(!in_array('error', $returnData)){
                    $tokenData = $this->client->getAccessToken();
                    $this->client->setAccessToken($tokenData);
                    $platformId = $this->getPlatformId();
                    // GET LOGGED USER INFO
                    $oauthUserData = new Oauth2($this->client);
                    $name = $oauthUserData->userinfo->get()['name'];
                    if ( isset($oauthUserData->userinfo->get()['email']) ) {
                        $email = $oauthUserData->userinfo->get()['email'];
                        $email = explode('@', $email);
                        $name = ((count($email) > 0) ? $email[0] : $name);
                    }
                    $check_account = PlatformAccount::where([
                        'user_id' => $userId,
                        'account_name' => $name,
                        'platform_id' => $platformId
                    ])->pluck('id')->first();
                    if(!$check_account){
                        if(!isset($tokenData['refresh_token'])){
                            $data['status_code'] = 0;
                            $data['status_text'] = 'Given details are already in use, Try with other details.';
                            Session::put('auth_msg', $data['status_text']);
                        }else{
                            $refresh_token = $this->mobj->encrypt_decrypt($tokenData['refresh_token']);
                            $env_type = 'production';
                            $google_data = array(
                                'user_id' => $userId,
                                'platform_id' => $platformId,
                                'account_name' => $name,
                                'refresh_token' => $this->mobj->encrypt_decrypt($tokenData['refresh_token']),
                                'access_token' => $this->mobj->encrypt_decrypt($tokenData['access_token']),
                                'env_type' => $env_type,
                                'token_type' => $tokenData['token_type'],
                                'expires_in' => $tokenData['expires_in'],
                                'token_refresh_time' => $tokenData['created']
                            );
                            PlatformAccount::create($google_data);
                            $data['status_code'] = 1;
                            $data['status_text'] = 'Account connected successfully.';
                        }
                    }else{
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Account already connected.';
                        Session::put('auth_msg', $data['status_text']);
                    }
                }else{
                    $data['status_code'] = 0;
                    $data['status_text'] = $returnData['error_description'];
                    Session::put('auth_msg', $data['status_text']);
                }
                echo "<script>window.close();</script>";
            }catch(\Exception $e){
                $data['status_code'] = 0;
                $data['status_text'] = $e->getMessage();
                echo "<script>window.close();</script>";
                Session::put('auth_msg', $data['status_text']);
            }
            return json_encode($data);
        }
    }

    public function access_token($user_integration_id)
    {
        $platformId = $this->getPlatformId();
        $tokenData = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platformId, ['id' , 'access_token', 'refresh_token', 'expires_in', 'token_type', 'token_refresh_time']);
        $returnData = false;
        $scopes = implode(' ', Config::get('apiconfig.GoogleSheetScope'));
        if($tokenData){
            if($this->client->isAccessTokenExpired()){
                $newtoken = PlatformAccount::find($tokenData->id);
                $returnData = $this->client->fetchAccessTokenWithRefreshToken($this->mobj->encrypt_decrypt($tokenData->refresh_token,'decrypt'));
                if(!isset($returnData['error'])){
                    $newtoken->access_token = $this->mobj->encrypt_decrypt($returnData['access_token']);
                    $newtoken->save();
                }else{
                    $returnData = false;
                }
            }else{
                $returnData = [
                    "access_token" => $this->mobj->encrypt_decrypt($tokenData->access_token,'decrypt'),
                    "expires_in" => $tokenData->expires_in,
                    "refresh_token" => $this->mobj->encrypt_decrypt($tokenData->refresh_token,'decrypt'),
                    "scope" => $scopes,
                    "token_type" => $tokenData->token_type,
                    "created" => $tokenData->token_refresh_time
                ];
            }
        }
        return $returnData;
    }
}