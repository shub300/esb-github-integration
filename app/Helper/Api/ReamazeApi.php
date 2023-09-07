<?php

namespace App\Helper\Api;

use App\Helper\MainModel;
use App\Models\PlatformLookup;
use App\Helper\ConnectionHelper;

class ReamazeApi
{
    public $mobj, $helper, $platformId;
    private static $platformName = 'reamaze';

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper;
        $platform = PlatformLookup::where('platform_id', '=', self::$platformName)->first();
        if($platform){
            $this->platformId = $platform->id;
        }
    }

    public function isJson($string, $return_data = false)
    {
        $data = json_decode($string, true);
        return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
    }

    public function callAPI($url, $method = 'GET', $accountInfo = false, $arr = false)
    {
        if($accountInfo){
            $data = [];
            $url = 'https://'.$accountInfo->api_domain.'.reamaze.io/api/v1'.$url;
            $data['status'] = false;
            try{
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_ENCODING, '');
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                switch ($method) {
                    case 'POST':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr));
                        break;
                    case 'PUT':
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr));
                        break;
                    default:
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                        break;
                }
                // AUTH
                if($accountInfo){
                    $token = $this->mobj->encrypt_decrypt($accountInfo->app_id, 'decrypt').":".$this->mobj->encrypt_decrypt($accountInfo->app_secret, 'decrypt');
                    $authArr = [
                        'Accept: application/json',
                        'Authorization: Basic '.base64_encode($token),
                        "cache-control: no-cache",
                        "content-type: application/json",
                    ];
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $authArr);
                }
                $response = curl_exec($ch);
                // CURL ERROR CHECK
                if(curl_errno($ch)){
                    $data['message'] = curl_error($ch);
                }
                curl_close($ch);
                // RESPONSE ERROR CHECK
                if($response){
                    $response = $this->isJson($response, true);
                    if($response != null){
                        if(isset($response['errors'])){
                            if(is_array($response['errors'])){
                                $data['message'] = '';
                                foreach($response['errors'] as $k => $v){
                                    $data['message'] .= ' '.$k;
                                    if(is_array($v)){
                                        foreach($v as $message){
                                            $data['message'] .= '-'.$message;
                                        }
                                    }
                                }
                            }else{
                                $data['message'] = $response['errors'];
                            }
                        }elseif(isset($response['error'])){
                            $data['message'] = $response['error'];
                        }else{
                            $data['status'] = true;
                            $data['data'] = $response;
                        }
                    }
                }
                if(!isset($data['data']) && !isset($data['message'])){
                    $data['message'] = 'No data found.';
                }
                return $data;
            }catch(\Exception $e){
                $data['message'] = $e->getMessage();
                return $data;
            }
        }else{
            return ['status' => false, 'message' => 'No credentials found.'];
        }
    }

    // GET DATA
    public function getCustomers($account)
    {
        $data = [];
        $data['status'] = false;
        try{
            $apiresponse = $this->callAPI("/contacts", 'GET', $account);
            if($apiresponse['status']){
                if(isset($apiresponse['contacts'])){
                    $data['status'] = true;
                    $data['data'] = $apiresponse['contacts'];
                }else{
                    $data['message'] = 'No customer found';
                }
            }
        }catch(\Exception $e){
            $data['message'] = $e->getMessage();
        }
        return $data;
    }
}
