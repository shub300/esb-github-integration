<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;

use function GuzzleHttp\json_decode;

class ZulilyApi
{

    public $mobj, $helper, $myPlatform;
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper;
        $this->myPlatform = "zulily";
    }


    /* Get Token By User ID */
    public function getTokenByUserID($userId)
    {
        $platformId = $this->helper->getPlatformIdByName($this->myPlatform);
        $findApp = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $platformId]);
        if ($findApp &&  $platformId) {
            $accDetail = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platformId, 'user_id' => $userId]);
            if ($accDetail) {

                return ['access_token' => $this->mobj->encrypt_decrypt($accDetail->access_token, 'decrypt'), 'dev_ref' => $this->mobj->encrypt_decrypt($findApp->client_id, 'decrypt'), 'app_ref' => $this->mobj->encrypt_decrypt($findApp->app_ref, 'decrypt'), 'api_domain' => $accDetail->api_domain, 'app_id' => $accDetail->app_id, 'refresh_token' => $accDetail->refresh_token, 'env_type' => $accDetail->env_type];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function GetPurchaseOrder($account)
    {
        // dd($account);

        try {
            $response = false;
            $header = ['api-key:' . $this->mobj->encrypt_decrypt($account->access_key, 'decrypt')];

            $url = "https://" . $account->api_domain . "/a/ediPayloads?tradingPartnerId=" . $this->mobj->encrypt_decrypt($account->marketplace_id, 'decrypt');
            $response = $this->mobj->makeCurlRequest("GET", $url, $post_data = false, $header);
            return $response;
            // dd($response);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            // echo $e->getMessage();
        }
    }

    public function getPurchaseOrderByID($account, $documentNumber)
    {
        try {
            $response = false;
            $header = ['api-key:' . $this->mobj->encrypt_decrypt($account->access_key, 'decrypt')];

            $url = "https://" . $account->api_domain . "/a/ediPayloads?tradingPartnerId=" . $this->mobj->encrypt_decrypt($account->marketplace_id, 'decrypt') . '&documentNumber=' . $documentNumber;
            $response = $this->mobj->makeCurlRequest("GET", $url, $post_data = false, $header);
            return $response;
            // dd($response);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            // echo $e->getMessage();
        }
    }

    public function invetoryUpdate($account, $post_data)
    {

        try {
            $response = false;
            $header = [
                'api-key:' . $this->mobj->encrypt_decrypt($account->access_key, 'decrypt'),
                'Content-Type:application/json'
            ];
            $url = "https://" . $account->api_domain . "/p/inventoryUpdate?tradingPartnerId=" . $this->mobj->encrypt_decrypt($account->marketplace_id, 'decrypt');
            $response = $this->mobj->makeCurlRequest("POST", $url, $post_data, $header);
            return $response;
            // dd($response);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            // echo $e->getMessage();
        }
    }

    public function createShipment($account, $post_data)
    {

        try {
            $response = false;
            $header = [
                'api-key:' . $this->mobj->encrypt_decrypt($account->access_key, 'decrypt'),
                'Content-Type:application/json'
            ];
            $url = "https://" . $account->api_domain . "/p/shipmentNotice?tradingPartnerId=" . $this->mobj->encrypt_decrypt($account->marketplace_id, 'decrypt');
            // dd($post_data);exit;
            $response = $this->mobj->makeCurlRequest("POST", $url, $post_data, $header);
            return $response;
            // dd($response);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            // echo $e->getMessage();
        }
    }
}
