<?php

namespace App\Helper\Api;

use App\Helper\MainModel;
use GuzzleHttp\HandlerStack;
use Mockery\Exception;

abstract class ShipHawkApiServices
{
    protected $mainModel;

    public function __construct()
    {
        $this->mainModel = new MainModel();
    }

    public function postRequest($endPoint, $data, $key) {
        try {
            $mainUrl = $this->base_url();
            $url = $mainUrl.$endPoint;
            $header = $this->getRequestHeader($key);
            $resp = $this->mainModel->makeCurlRequest("POST", $url, $data, $header);
            if($resp){
                $resp = json_decode($resp, true);
                return $resp;
            }
        } catch (\Exception $ex) {
            return ['error' => $ex->getMessage()];
        }
    }

    public function getRequest($endPoint, $key) {
        try {
            $mainUrl = $this->base_url();
            $url = $mainUrl.$endPoint;
            $header = $this->getRequestHeader($key);
            $response = $this->mainModel->makeCurlRequest("GET", $url, null, $header);
            if($response){
                $response = json_decode($response, true);
                return $response;
            }
        } catch (\Exception $ex) {
            return ['error' => $ex->getMessage()];
        }
    }

    private function getRequestHeader ($key) {
        $key = $this->mainModel->encrypt_decrypt($key,'decrypt');
        return array('Content-Type: application/json', 'x-api-key: '.$key);
    }

    abstract protected function base_url();
}
