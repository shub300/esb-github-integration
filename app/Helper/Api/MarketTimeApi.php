<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use App\Common;

class MarketTimeApi
{

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->my_platform = 'markettime';
        $this->helper = new ConnectionHelper();
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    public function GetCompIDAPIKey($user_id)
    {
        $acc_detail = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $this->my_platform_id, 'user_id' => $user_id]);
        if ($acc_detail)
            return ['companyID' => $acc_detail->app_id, 'api_key' => $this->mobj->encrypt_decrypt($acc_detail->access_token, 'decrypt')];

        return false;
    }
    public function GetOrders($user_id)
    {
        $gettoken = $this->GetCompIDAPIKey($user_id);
        if ($gettoken) {
            $headers = ['x-api-key: ' . $gettoken['api_key']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.MarketTimeApiUrl') . '/orders/export/'.$gettoken['companyID'].'/status/Open', [], $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function CreateOrderShipment($user_id, $order_id, $ShipmentData)
    {
        print_r($ShipmentData);
        $gettoken = $this->GetCompIDAPIKey($user_id);
        if ($gettoken) {
            $headers = ['Content-Type: application/json','x-api-key: ' . $gettoken['api_key']];
   //       $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.MarketTimeApiUrl') . '/import/'.$gettoken['companyID'].'/order/'.$order_id.'/invoice/trackingdetail/update', $ShipmentData, $headers);
            $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.MarketTimeApiUrl') . '/import/'.$gettoken['companyID'].'/order/'.$order_id.'/status/update', $ShipmentData, $headers);
            return $response;
        } else {
            return false; 
        }
    }

}
