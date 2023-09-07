<?php

namespace App\Helper\Api;

use App\Helper\MainModel;

class ShipRushApi
{
    /**
     * Function to check for the given credential
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     */
    protected static function checkAuthCredential( \StdClass $accountInfo ) {
        if( !empty( $accountInfo ) ) {
            $base_url = \Config::get('apiconfig.ShipRushBaseUrl');
            $url = $base_url . '/accountservice.svc/user/get';
            $postData = '<GetUserRequest></GetUserRequest>';

            $response = static::makeAPICall( $accountInfo, $url, $postData, true );
            if( $response ) {
                if( isset( $response['AccountId'] ) ) {
                    return true;
                }else{
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Set headers for the API call
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $forCheck, whether its true or false
     *
     * @return array
     */
    private static function setHeadersForAPI( object $accountInfo, bool $forCheck = false ) : array {
        $headers = [];
        if( !empty( $accountInfo ) ) {
            $headers = [
                'Content-Type' => 'text/xml'
            ];
            if( $forCheck ) {
                $headers['X-SHIPRUSH-VERSION'] = isset( $accountInfo->shiprush_version ) ? $accountInfo->shiprush_version : null;
                $headers['X-SHIPRUSH-USER-TOKEN'] = isset( $accountInfo->user_token ) ? $accountInfo->user_token : null;
                $headers['X-SHIPRUSH-DEVELOPER-TOKEN'] = isset( $accountInfo->developer_token ) ? $accountInfo->developer_token : null;
            } else {
                $mainModel = new MainModel();
                $headers['X-SHIPRUSH-VERSION'] = isset( $accountInfo->marketplace_id ) ? $accountInfo->marketplace_id : null;
                $headers['X-SHIPRUSH-USER-TOKEN'] = isset( $accountInfo->app_id ) ? $mainModel->encrypt_decrypt( $accountInfo->app_id, 'decrypt' ) : null;
                $headers['X-SHIPRUSH-DEVELOPER-TOKEN'] = isset( $accountInfo->app_secret ) ? $mainModel->encrypt_decrypt( $accountInfo->app_secret, 'decrypt' ) : null;
            }
        }
        return $headers;
    }

    /**
     * Main function for the API call to make
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $url, full url for the api call
     * @param $postData, data for the POST method
     * @param $forCheck, whether its true or false (when 'true' we use credentials to validate in the form of plain text, and when 'false' we creds to perform sync operation)
     */
    protected static function makeAPICall( object $accountInfo, string $url, string $postData, bool $forCheck = false ) {
        $response = [];
        if( !empty( $accountInfo ) ) {
            $headers = static::setHeadersForAPI( $accountInfo, $forCheck );
            if( count( $headers ) ) {
                $method = 'POST';
                $mainModel = new MainModel();
                $result = $mainModel->makeRequest( $method, $url, $postData, $headers, "xml");
                if( $result ) {
                    $result = (string) $result->getBody();
                }
                $xml = json_encode((array) simplexml_load_string($result));
                $data = json_decode($xml);
                $response = (array) $data;
            }
        }
        return $response;
    }

    /**
     * Function to create order shipment in ShipRush
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $postData, xml data for the orders
     */
    protected static function SyncOrderForShipRush( object $accountInfo, string $postData ) {
        if( !empty( $accountInfo ) ) {
            $base_url = \Config::get('apiconfig.ShipRushBaseUrl');
            $url = $base_url . '/shipmentservice.svc/shipments/Pending/add';

            $response = static::makeAPICall( $accountInfo, $url, $postData );
            if( $response ) {
                if( isset( $response['ShipmentId'] ) ) {
                    return $response;
                }else if( isset( $response['Message'] ) ){
                    return $response['Message'];
                }else{
                    return "Order failed to sync.";
                }
            }
        }
        return false;
    }

    /**
     * Function to get shipping details by ShipmentId from ShipRush
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $postData, xml data for the orders
     */
    public static function GetShipmentServiceInfo( object $accountInfo, string $postData ) {
        if( !empty( $accountInfo ) ) {
            $base_url = \Config::get('apiconfig.ShipRushBaseUrl');
            $url = $base_url . '/shipmentservice.svc/shipments/get';

            $response = static::makeAPICall( $accountInfo, $url, $postData );
            if( $response ) {
                if( isset( $response['ShipTransactions'] ) ) {
                    return $response;
                }else if( isset( $response['Message'] ) ){
                    return $response['Message'];
                }else{
                    return "Failed to get shipment information.";
                }
            }
        }
        return false;
    }
}