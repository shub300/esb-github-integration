<?php

namespace App\Http\Controllers\Spscommerce\Api;

use DB;
use Auth;
use Mail;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;

class SpscommerceApi extends Model
{

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper();
        $this->my_platform = 'spscommerce';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    public function GetAppInfo()
    {
        $api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id]);
        if ($api_app) {
            return $api_app;
        } else
            return false;
    }

    public function GetAccessTokenAndEnvType($account)
    {

        if ($account)
            return ['access_token' => $this->mobj->encrypt_decrypt($account->access_token,'decrypt'), 'env_type' => $account->env_type];

        return false;

        /*$acc_detail = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $this->my_platform_id, 'user_id' => $user_id]);
        if ($acc_detail)
            return ['access_token' => $this->mobj->encrypt_decrypt($acc_detail->access_token,'decrypt'), 'env_type' => $acc_detail->env_type];

        return false;*/
    }



    public function GetAllPO($account,$user_id,$user_integration_id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            //echo \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/PO*';
            //echo $gettoken['access_token'];die;
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/PO*', [], $headers);
            return $response;
        } else {
            return false;
        }
    }



    public function GetPOById($account,$user_id, $id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . $id, [], $headers);
            //. \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/'
            return $response;
        } else {
            return false;
        }
    }


    public function CreatePO($account,$user_id,$id,$postdata)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            //echo \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-in'] . '/' .$id;
            $headers = ['Authorization: Bearer ' . $gettoken['access_token'],'Content-Type: application/octet-stream'];
            $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-in'] . '/' .$id, $postdata, $headers);
            return $response;
        } else {
            return false;
        }
    }


    public function GetAllAcknowledgments($account,$user_id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/PR*', [], $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function GetAcknowledgmentById($account,$user_id, $id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . $id, [], $headers);
            //. \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/'
            return $response;
        } else {
            return false;
        }
    }




    public function GetAllInventory($account,$user_id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/IB*', [], $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function GetInventoryById($account,$user_id, $id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . $id, [], $headers);
            //. \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/'
            return $response;
        } else {
            return false;
        }
    }



    public function GetAllTransactions($account,$user_id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/PO*', [], $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function CreateInvoice($account,$user_id,$id, $postdata)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token'],'Content-Type: application/octet-stream'];

            $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-in'] . '/'.$id, $postdata, $headers);
            return $response;
        } else {
            return false;
        }
    }


    public function DeleteTransactions($account,$user_id,$id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('DELETE', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . $id, [], $headers);
            return $response;
        } else {
            return false;
        }
    }



    public function GetAllInvoices($account,$user_id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/IN*', [], $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function GetInvoiceById($account,$user_id, $id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . $id, [], $headers);
            //. \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/'
            return $response;
        } else {
            return false;
        }
    }


    public function GetAllShipments($account,$user_id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/SH*', [], $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function GetShipmentById($account,$user_id, $id)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token']];
            $response = $this->mobj->makeCurlRequest('GET', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . $id, [], $headers);
            //. \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-out'] . '/'
            return $response;
        } else {
            return false;
        }
    }

    public function CreateShipment($account,$user_id,$id, $postdata)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token'],'Content-Type: application/octet-stream'];
            $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-in'] . '/'.$id, $postdata, $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function CreateAcknowledgement($account,$user_id,$id,$postdata)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token'],'Content-Type: application/octet-stream'];
            $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-in'] . '/' .$id, $postdata, $headers);
            return $response;
        } else {
            return false;
        }
    }

    public function UpdateInventory($account,$user_id,$id,$postdata)
    {
        $gettoken = $this->GetAccessTokenAndEnvType($account);
        if ($gettoken) {
            $headers = ['Authorization: Bearer ' . $gettoken['access_token'],'Content-Type: application/octet-stream'];
            $response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SpsApiUrl') . '/transactions/v2/' . \Config::get('apiconfig.SpsApiDir')[$gettoken['env_type'] . '-in'] . '/' .$id, $postdata, $headers);
            return $response;
        } else {
            return false;
        }
    }



}
