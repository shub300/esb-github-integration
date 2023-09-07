<?php

namespace App\Http\Controllers\ShipHawk;

use App\Helper\Api\ShipHawkApiServices;

class ShipHawkServices extends ShipHawkApiServices
{
    protected $key = '';
    protected $env = '';

    public function __construct($key, $env = 'production')
    {
        $this->key = $key;
        $this->env = $env;
        parent::__construct();
    }

    // CREATE::START
    public function createOrUpdateWebhook($webhookFor, $user_integration_id)
    {
        $apidata = $this->getApiDataForWebhooks($webhookFor, $user_integration_id);
        $resp = $this->postRequest('/webhooks', json_encode($apidata), $this->key);
        if(!is_array($resp)){
            if(isset(json_decode($resp)->id)) {
                return json_decode($resp, true);
            }
        }
        return $resp;
    }
 /* Filter Shipment Details By Date Range etc */
 public function getShipmentOrderDetails($postQuery)
 {

     $response = $this->postRequest('/shipments/query', json_encode($postQuery), $this->key);
     if(!is_array($response)){
         if(isset(json_decode($response)->id)) {
             return json_decode($response, true);
         }
         else
         {
             return json_decode($response, true);
         }
     }

     return $response;
 }
    public function createOrdersForShiphawk($order, $api_order_id)
    {
        if($api_order_id)
        {
            $response = $this->postRequest('/orders/'.$api_order_id, json_encode($order), $this->key);
        }
        else
        {
            $response = $this->postRequest('/orders', json_encode($order), $this->key);
        }

        if(!is_array($response)){
            if(isset(json_decode($response)->id)) {
                return json_decode($response, true);
            }
            else
            {
                return json_decode($response, true);
            }
        }

        return $response;
    }
    // CREATE::END

    // GET::START
    public function getWarehouse()
    {
        $resp = $this->getRequest('/warehouses', $this->key);
        if(!is_array($resp)){
            if(isset(json_decode($resp)->id)) {
                return json_decode($resp, true);
            }
        }
        return $resp;
    }

    public function getWarehouseById($id)
    {
        $resp = $this->getRequest("/warehouses/$id", $this->key);
        if(!is_array($resp)){
            if(isset(json_decode($resp)->id)) {
                return json_decode($resp, true);
            }
        }
        return $resp;
    }
    // GET::END

    public function base_url()
    {
        if($this->env === 'production'){
            return 'https://shiphawk.com/api/v4';
        }else{
            return 'https://sandbox.shiphawk.com/api/v4';
        }
    }

    public function checkForConnectedAPICredential()
    {
        $resp = $this->getRequest('/users', $this->key);
        if(isset($resp['error'])){
            return false;
        }else{
            return true;
        }
    }

    private function getApiDataForWebhooks($webhookFor, $user_integration_id)
    {
        $apidata = [];
        if(env('APP_ENV') == 'prod'){
            $env = 1;
        }else{
            $env = 2;
        }
        $apidata['callback_url'] = env('APP_WEBHOOK_URL')."/shiphawk/index.php?for=$webhookFor&uid=$user_integration_id&env=$env";
        if($webhookFor == 'shipment'){
            $apidata['events'] = ["shipment.status_update", "shipment.tracking_update", "shipment.create_from_order"];
        }
        return $apidata;
    }
}
