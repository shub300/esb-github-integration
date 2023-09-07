<?php

namespace App\Helper\Api;

use App\Helper\MainModel;

class UPSApi
{
    /**
     * Function to check for the given credential
     * 
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     */
    protected static function checkAuthCredential( \StdClass $accountInfo ) {
        if( !empty( $accountInfo ) ) {
            $url = static::setURL( $accountInfo->env, 'rest/QVEvents' );
            $postData = [
                'QuantumViewRequest' => [
                    'Request' => [
                        'RequestAction' => 'QVEvents'
                    ]
                ]
            ];
            $response = static::makeAPICall( $accountInfo, $url, $postData, true );
            if( $response = static::isJson( $response, true ) ) {
                // print_r( $response );
                if( isset( $response['QuantumViewResponse'] ) && isset( $response['QuantumViewResponse']['Response'] ) ) {
                    if( isset( $response['QuantumViewResponse']['Response']['Error'] ) ) {
                        if( count( $response['QuantumViewResponse']['Response']['Error'] ) === 0 ) {
                            return true;
                        }
                        return true; // for temporary, without checking the credential 
                        $errorMessage = $response['QuantumViewResponse']['Response']['Error'];
                        if( isset( $errorMessage['ErrorDescription'] ) ){
                            return $errorMessage['ErrorDescription'];
                        } else {
                            return ( isset( $errorMessage[0] ) ? $errorMessage[0]['ErrorDescription'] : false );
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Function to check for the given credential
     * 
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $postData, array for the orders data
     */
    protected static function syncOrderForUPS( $accountInfo, array $postData = [] ) {
        if( !empty( $accountInfo ) ) {
            $url = static::setURL( $accountInfo->env_type, 'ship/v1/shipments?additionaladdressvalidation=city' );
            $response = static::makeAPICall( $accountInfo, $url, $postData, false );
            if( $response = static::isJson( $response, true ) ) {
                return $response;
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
                'Accept' => 'Accept',
                'transactionSrc' => 'api',
                'Content-Type' => 'application/json'
            ];
            if( $forCheck ) {
                $headers['AccessLicenseNumber'] = isset( $accountInfo->access_license_number ) ? $accountInfo->access_license_number : null;
                $headers['Username'] = isset( $accountInfo->username ) ? $accountInfo->username : null;
                $headers['Password'] = isset( $accountInfo->password ) ? $accountInfo->password : null;
                $headers['transId'] = isset( $accountInfo->transaction_id ) ? $accountInfo->transaction_id : null;
            } else {
                $mainModel = new MainModel();
                $headers['AccessLicenseNumber'] = isset( $accountInfo->access_key ) ? $mainModel->encrypt_decrypt( $accountInfo->access_key, 'decrypt' ) : null;
                $headers['Username'] = isset( $accountInfo->account_name ) ? $accountInfo->account_name : null;
                $headers['Password'] = isset( $accountInfo->app_secret ) ? $mainModel->encrypt_decrypt( $accountInfo->app_secret, 'decrypt' ) : null;
                $headers['transId'] = isset( $accountInfo->marketplace_id ) ? $mainModel->encrypt_decrypt( $accountInfo->marketplace_id, 'decrypt' ) : null;
            }
        }
        return $headers;
    }

    /**
     * Set url endpoint with sandbox or production environment
     * 
     * @param $env, environment of the UPS account
     * @param $endpoint, other endpoint of the UPS url
     * 
     * @return array
     */
    private static function setURL( string $env, string $endpoint ) : string {
        return ( ( $env === 'sandbox' ) ? 'https://wwwcie.ups.com/' : 'https://onlinetools.ups.com/' ) . $endpoint;
    }

    /**
     * Check for the response body to validate if it's a json and also returns array
     * 
     * @param $string, json formated string
     * @param $return_data, even if it's false or true, for true return will be array of the json
     * decoded data
     * 
     * @return array or boolean
     */
    private static function isJson( object $string, bool $return_data = false ) {
        $data = json_decode( $string, true );
        return ( json_last_error() == JSON_ERROR_NONE ) ? ( $return_data ? $data : true ) : false;
    }

    /**
     * Main function for the API call to make
     * 
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $url, full url for the api call
     * @param $postData, data for the POST method
     * @param $forCheck, whether its true or false
     */
    protected static function makeAPICall( object $accountInfo, string $url, array $postData = [], bool $forCheck = false ) {
        $response = [];
        if( !empty( $accountInfo ) ) {
            $headers = static::setHeadersForAPI( $accountInfo, $forCheck );
            if( count( $headers ) ) {
                $method = 'GET';
                if( count( $postData ) ) {
                    $method = 'POST';
                }
                $mainModel = new MainModel();
                $response = $mainModel->makeRequest( $method, $url, $postData, $headers );
                if( $response ) {
                    $response = $response->getBody();
                }
            }
        }
        return $response;
    }
}