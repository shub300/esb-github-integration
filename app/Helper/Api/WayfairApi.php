<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Common;
use Illuminate\Support\Facades\Log;

class WayfairApi
{
    public static $integration_agent = 'SKUVAULT - v:1.0';
    public static $apiworx_agent = 'APIWORX-BRIGHTPEARL - v:1.0';

    public function __construct()
    {
        $this->mobj = new MainModel();
    }
    // get Tokan from wayfair.
    public function GetTokan($client_secret, $client_id, $Audience_url)
    {
        $service_url = \Config::get('apiconfig.WayfairUrl') . '/oauth/token';

        $curl_post_data = [
            "client_id" => $client_id,
            "client_secret" => $client_secret,
            "audience" => $Audience_url,
            "grant_type" => "client_credentials"
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $curl_post_data);
        return $response;
    }

    public function GetProduct($access_token, $url, $request_data_json, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {

        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $access_token,
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function getWFOrders($wfToken, $url, $fromDate, $limit, $hasResponse, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $this->mobj->encrypt_decrypt($wfToken, $action = 'decrypt'),
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];

        if ($hasResponse == '') {
            $has = '';
        }
        if (is_bool($hasResponse) && $hasResponse == true) {
            $has = 'hasResponse: true,';
        }
        if (is_bool($hasResponse) && $hasResponse == false) {
            $has = 'hasResponse: false,';
        }


        $curl_post_data = array("query" => 'query getDropshipPurchaseOrders {
                getDropshipPurchaseOrders (
                    ' . $has . '
                    limit: ' . $limit . ',
                    fromDate: "' . $fromDate . '",
                ) {
                    id,
                    poNumber,
                    poDate,
                    estimatedShipDate,
                    customerName,
                    customerAddress1,
                    customerAddress2,
                    customerCity,
                    customerState,
                    customerPostalCode,
                    orderType,
                    shippingInfo {
                        shipSpeed,
                        carrierCode
                    },
                    packingSlipUrl,
                    warehouse {
                        id,
                        name,
                        address {
                            name,
                            address1,
                            address2,
                            address3,
                            city,
                            state,
                            country,
                            postalCode
                        }
                    },
                    products {
                        partNumber,
                        quantity,
                        price,
                        sku,
                        event {
                            id,
                            type,
                            name,
                            startDate,
                            endDate
                        }
                    },
                    shipTo {
                        name,
                        address1,
                        address2,
                        address3,
                        city,
                        state,
                        country,
                        postalCode,
                        phoneNumber
                    },
                    billTo {
                        name,
                        address1,
                        address2,
                        address3,
                        city,
                        state,
                        country,
                        postalCode,
                        phoneNumber
                    }
                }
            }', "variables" => array());

        $request_data_json = json_encode($curl_post_data);

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);

        return $response;
    }

    public function getWFWarehouse($wfToken, $url, $fromDate, $limit, $hasResponse, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $sortOrderBy = 'DESC';
        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $this->mobj->encrypt_decrypt($wfToken, $action = 'decrypt'),
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];

        if ($hasResponse == '') {
            $has = '';
        }
        if (is_bool($hasResponse) && $hasResponse == true) {
            $has = 'hasResponse: true,';
        }
        if (is_bool($hasResponse) && $hasResponse == false) {
            $has = 'hasResponse: false,';
        }


        $curl_post_data = array("query" => 'query getDropshipPurchaseOrders {
                getDropshipPurchaseOrders (
                    ' . $has . '
                    limit: ' . $limit . ',
                    sortOrder: ' . $sortOrderBy . ',
                ) {
                    poNumber,
                    warehouse {
                        id,
                        name,
                        address {
                            name,
                            address1,
                            address2,
                            address3,
                            city,
                            state,
                            country,
                            postalCode
                        }
                    }
                }
            }', "variables" => array());

        $request_data_json = json_encode($curl_post_data);

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function UpdateInventory($access_token, $url, $request_data_json, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $access_token, //. $access_token,
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function Wfacceptorder($access_token, $url, $request_data_json, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $access_token,
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function shipment($access_token, $url, $request_data_json, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $access_token,
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function createShipmentLabel($wfToken, $url, $request_data_json, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $this->mobj->encrypt_decrypt($wfToken, $action = 'decrypt'),
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];

        // Log::info( "Wayfair Header".json_encode( $headers ) );
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    } 

    public function getWFOrders_test($wfToken, $url, $fromDate, $limit, $hasResponse, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $service_url = $url . '/v1/graphql';
        $headers = [
            'Authorization:Bearer ' . $this->mobj->encrypt_decrypt($wfToken, $action = 'decrypt'),
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];

        if ($hasResponse == '') {
            $has = '';
        }
        if (is_bool($hasResponse) && $hasResponse == true) {
            $has = 'hasResponse: true,';
        }
        if (is_bool($hasResponse) && $hasResponse == false) {
            $has = 'hasResponse: false,';
        }


        // $poNumbers = 'CA456863681';
        $poNumbers = 'CS456787141';

        $curl_post_data = array("query" => 'query getDropshipPurchaseOrders {
                getDropshipPurchaseOrders (
                    ' . $has . '
                    limit: ' . $limit . ',
                    poNumbers: "' . $poNumbers . '",
                ) {
                    id,
                    poNumber,
                    poDate,
                    estimatedShipDate,
                    customerName,
                    customerAddress1,
                    customerAddress2,
                    customerCity,
                    customerState,
                    customerPostalCode,
                    orderType,
                    shippingInfo {
                        shipSpeed,
                        carrierCode
                    },
                    packingSlipUrl,
                    warehouse {
                        id,
                        name,
                        address {
                            name,
                            address1,
                            address2,
                            address3,
                            city,
                            state,
                            country,
                            postalCode
                        }
                    },
                    products {
                        partNumber,
                        quantity,
                        price,
                        sku,
                        event {
                            id,
                            type,
                            name,
                            startDate,
                            endDate
                        }
                    },
                    shipTo {
                        name,
                        address1,
                        address2,
                        address3,
                        city,
                        state,
                        country,
                        postalCode,
                        phoneNumber
                    },
                    billTo {
                        name,
                        address1,
                        address2,
                        address3,
                        city,
                        state,
                        country,
                        postalCode,
                        phoneNumber
                    }
                }
            }', "variables" => array());

        $request_data_json = json_encode($curl_post_data);


        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
            
        return $response;
    }

    public function GetShippingLabel($access_token, $url, $request_data_json, $source_platform_id='skuvault', $dest_platform_id='skuvault')
    {
        if( $source_platform_id =='skuvault' || $dest_platform_id=='skuvault' ) {
            $agent = self::$integration_agent;
        } else {
            $agent = self::$apiworx_agent;
        }

        $headers = [
            'Authorization:Bearer ' . $this->mobj->encrypt_decrypt($access_token, $action = 'decrypt'),
            'Wayfair-Integration-Agent:' . $agent,
            'Content-Type:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('GET', $url, [], $headers); 
        return $response;
    }

    
}
