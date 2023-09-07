<?php

namespace App\Http\Controllers\GunBroker\Api;

use App\Helper\MainModel;

use App\Models\PlatformAccount;

class GunBrokerApi
{
    public  $mobj;
    public function __construct()
    {
        $this->mobj = new MainModel();
    }
    /**
     * Function to check for the given credential
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     */
    protected function checkAuthCredential(\StdClass $accountInfo)
    {
        if (!empty($accountInfo)) {
            $url = self::setURL($accountInfo->env_type, '/Users/AccessToken');

            $postData = [
                "Username" => $accountInfo->username,
                "Password" => $accountInfo->password
            ];

            $response = self::makeAPICall($accountInfo, "POST", $url, $postData, true);

            if ($response = self::convertToData($response)) {

                if (isset($response['accessToken'])) {
                    return ['status' => true, 'token' => $response['accessToken']];
                } else {
                    return ['status' => false, 'token' => NULL];
                }
            }
        }
        return ['status' => false, 'token' => NULL];
    }

    /**
     * Set url endpoint with sandbox or production environment
     *
     * @param $env, environment of the GunBroker account
     * @param $endpoint, other endpoint of the GunBroker url
     *
     * @return array
     */
    protected function setURL(string $env, string $endpoint): string
    {

        return (($env === 'sandbox') ? \Config::get('apiconfig.GunBrokerUrlSandbox') : \Config::get('apiconfig.GunBrokerBaseUrl')) . $endpoint;
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
    private function isJson(string $retrun_string, bool $return_data = false)
    {
        $data = json_decode($retrun_string, true);
        return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
    }
    /**
     * Check for the response body to validate if it's a json and also returns array
     *
     * @param $return, object formated
     * @param $type, to get multiple type of data
     * @return array or string or integer
     */
    private function convertToData($return, $type = "body")
    {
        $data = NULL;
        if ($return) {
            if ($type == "body") {
                $data = self::isJson($return->getBody(), true);
            } else if ($type == "status") {
                $data = $return->getStatusCode();
            } else if ($type == "pharse") {
                $data = $return->getReasonPhrase();
            } else {
                $status = $return->getStatusCode();
                $pharse = $return->getReasonPhrase();
                $body = self::isJson($return->getBody(), true);
                $data = ['status' => $status, 'pharse' => $pharse, 'body' => $body];
            }
        }

        return $data;
    }

    /**
     * Set headers for the API call
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $forCheck, whether its true or false
     * @param $regenate, whether its true or false to regerate token
     *
     * @return array
     */
    private function setHeadersForAPI(object $accountInfo, bool $forCheck = false, $regenerate = false): array
    {
        $headers = [];
        if (!empty($accountInfo)) {
            $headers = [
                'Content-Type' => 'application/json'
            ];
            if ($forCheck) {
                $headers['X-DevKey'] = isset($accountInfo->dev_key) ? $accountInfo->dev_key : null;
            } else if ($regenerate) {
                $headers['X-DevKey'] = isset($accountInfo->access_key) ? $this->mobj->encrypt_decrypt($accountInfo->access_key, 'decrypt') : null; // Dev Key
            } else {

                $headers['X-DevKey'] = isset($accountInfo->access_key) ? $this->mobj->encrypt_decrypt($accountInfo->access_key, 'decrypt') : null; // Dev Key
                $headers['X-AccessToken'] = isset($accountInfo->access_token) ? $this->mobj->encrypt_decrypt($accountInfo->access_token, 'decrypt') : null; // Generated Access Token
            }
        }
        return $headers;
    }

    /**
     * Main function for the API call to make
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $method, name for method to call api
     * @param $url, full url for the api call
     * @param $postData, data for the POST method
     * @param $forCheck, whether its true or false (when 'true' we use credentials to validate in the form of plain text, and when 'false' we creds to perform sync operation)
     */
    protected function makeAPICall(object $accountInfo, string $method = "GET", string $url, array $postData = [], bool $forCheck = false)
    {
        $response = [];
        if (!empty($accountInfo)) {
            if (!$url) {
                $url = $accountInfo->api_domain . $url;
            }
            $headers = self::setHeadersForAPI($accountInfo, $forCheck);
            if (count($headers)) {
                $response = $this->mobj->makeRequest($method, $url, $postData, $headers);
                if ($status = self::convertToData($response, 'status')) {
                    if ($status == 401 && !$forCheck) { //if 401 status code found reset access token                      
                        $return = self::RegenerateAccessToken($accountInfo, true);
                        if ($return['status']) {
                            $headers = self::setHeadersForAPI($return['account'], $forCheck); //reset new header with new access token
                            $response = $this->mobj->makeRequest($method, $url, $postData, $headers);
                        }
                    }
                }
            }
        }
        return $response;
    }
    /* function is used to regenerate access token
     */
    protected function RegenerateAccessToken($accountInfo, $regenarate = false)
    {
        if (!empty($accountInfo)) {
            $url = $accountInfo->api_domain . '/Users/AccessToken';

            $postData = [
                "Username" => $this->mobj->encrypt_decrypt($accountInfo->app_id, 'decrypt'),
                "Password" => $this->mobj->encrypt_decrypt($accountInfo->app_secret, 'decrypt')
            ];
            $headers = self::setHeadersForAPI($accountInfo, false, $regenarate);
            $response = $this->mobj->makeRequest("POST", $url, $postData, $headers);
            if ($response = self::convertToData($response)) {

                if (isset($response['accessToken'])) {
                    $find = PlatformAccount::find($accountInfo->id);
                    if ($find) {
                        $find->access_token = $this->mobj->encrypt_decrypt($response['accessToken']);
                        $find->token_refresh_time = time();
                        $find->save();
                    }
                    return ['status' => true, 'account' =>  $find];
                } else {
                    return ['status' => false, 'account' => NULL];
                }
            }
        }
        return ['status' => false, 'account' => NULL];
    }

    /**
     * Function to create order shipment in ShipRush
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $postData, xml data for the orders
     */
    protected static function SyncOrderForGunBroker(object $accountInfo, array $postData)
    {
        if (!empty($accountInfo)) {
            $base_url = \Config::get('apiconfig.ShipRushBaseUrl');
            $url = $base_url . '/shipmentservice.svc/shipments/Pending/add';

            $response = static::makeAPICall($accountInfo, $url, $postData);
            if ($response) {
                if (isset($response['ShipmentId'])) {
                    return $response;
                } else if (isset($response['Message'])) {
                    return $response['Message'];
                } else {
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
    public static function GetShipmentServiceInfo(object $accountInfo, array $postData)
    {
        if (!empty($accountInfo)) {
            $base_url = \Config::get('apiconfig.ShipRushBaseUrl');
            $url = $base_url . '/shipmentservice.svc/shipments/get';

            $response = static::makeAPICall($accountInfo, $url, $postData);
            if ($response) {
                if (isset($response['ShipTransactions'])) {
                    return $response;
                } else if (isset($response['Message'])) {
                    return $response['Message'];
                } else {
                    return "Failed to get shipment information.";
                }
            }
        }
        return false;
    }
    /**
     * Function to get sales order details
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param date to filter the order by last modified date
     */
    public function GetOrders(object $accountInfo, $arguments)
    {
        $return = NULL;
        if (!empty($accountInfo)) {
            $base_url = $accountInfo->api_domain;
            if (!empty($arguments)) {
                $query = http_build_query($arguments);
                $arg = '?' . $query;
            } else {
                $arg = "";
            }
            $url =  $base_url . '/OrdersSold' . $arg;
            $response = self::makeAPICall($accountInfo, "GET", $url);
            if ($response = self::convertToData($response)) {
                if (isset($response['results']) ) { 
                    if (!empty($response['results'])) {
                        $return = $response['results'];
                    } else {
                        $return = "No order found";
                    }
                    
                } else if (isset($response['userMessage'])) {
                    $return = $response['userMessage'];
                } else {
                    $return = "Failed to get order information.";
                }
            }
        }
        return $return;
    }
    /* Get Order By ID */
    public function GetOrderByID(object $accountInfo, $OrderID)
    {
        $return = NULL;
        if (!empty($accountInfo)) {
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/Orders/{$OrderID}";
            $response = self::makeAPICall($accountInfo, "GET", $url);
            if ($response = self::convertToData($response)) {
                return $response;
                if (isset($response['userID'])) {
                    $return = $response;
                } else if (isset($response['userMessage'])) {
                    $return = $response['userMessage'];
                } else {
                    $return = "Failed to get user information.";
                }
            }
        }
        return $return;
    }
    /**
     * Function to get product details
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $arguments like page no, limit search 
 
     */
    public function GetProduct(object $accountInfo, $arguments)
    {
        $return = NULL;
        if (!empty($accountInfo)) {

            if (!empty($arguments)) {
                $query = http_build_query($arguments);
                $arg = '?' . $query;
            } else {
                $arg = "";
            }
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/ItemsSelling" . $arg;
            $response = self::makeAPICall($accountInfo, "GET", $url);

            if ($response = self::convertToData($response)) {


                if (isset($response['results'])) {
                    $return = $response['results'];
                } else if (isset($response['userMessage'])) {
                    $return = $response['userMessage'];
                } else {
                    $return = "Failed to get product information.";
                }
            }
        }
        return $return;
    }
    /**
     * Function to get carriers || shipping details
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $arguments like page no, limit search 
 
     */
    public function GetCarrier(object $accountInfo, $arguments)
    {
        $return = NULL;
        if (!empty($accountInfo)) {

            if (!empty($arguments)) {
                $query = http_build_query($arguments);
                $arg = '?' . $query;
            } else {
                $arg = "";
            }
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/Categories" . $arg;
            $response = self::makeAPICall($accountInfo, "GET", $url);
            if ($response = self::convertToData($response)) {
                if (isset($response['results']) && !empty($response['results'])) {
                    $return = $response['results'];
                } else if (isset($response['userMessage'])) {
                    $return = $response['userMessage'];
                } else {
                    $return = "Failed to get product information.";
                }
            }
        }
        return $return;
    }
    /**
     * Function to update order shipment
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $OrderID order id to update tracking info 
     * @param $Payload payload data e.g. tracking and carriers code
     *  
 
     */
    public function UpdateOrder(object $accountInfo, $OrderID, $Payload)
    {
        $return = NULL;
        if (!empty($accountInfo)) {
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/Orders/{$OrderID}/Shipping";
            $response = self::makeAPICall($accountInfo, "PUT", $url, $Payload);
            if ($response = self::convertToData($response, 'all')) {
                if (isset($response['status']) && $response['status'] == 200) {
                    $return = $response['status'];
                } else if (isset($response['body']['userMessage'])) {
                    $return = $response['body']['userMessage'];
                } else {
                    $return = "Failed to update order information.";
                }
            }
        }
        return $return;
    }
    /**
     * Function to update order shipment
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $OrderID order id to update order flag info 
     * @param $Payload payload data e.g. tracking and carriers code
     *  
 
     */
    public function UpdateOrderFlag(object $accountInfo, $OrderID, $Payload)
    {
        $return = NULL;
        if (!empty($accountInfo)) {
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/Orders/{$OrderID}/Flags";
            $response = self::makeAPICall($accountInfo, "PUT", $url, $Payload);
            if ($response = self::convertToData($response, 'all')) {
                \Storage::disk('local')->append('GB_Response.txt', json_encode($response));
                if (isset($response['status']) && $response['status'] == 200) {
                    $return = $response['status'];
                } else if (isset($response['body']['userMessage'])) {
                    $return = $response['body']['userMessage'];
                } else {
                    $return = "Failed to update order flag as shipped.";
                }
            }
        }
        return $return;
    }
    /**
     * Function to get product details
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $arguments like page no, limit search 
 
     */
    public function UpdatetProductByID(object $accountInfo, $productId, $payload)
    {
        $return = NULL;
        if (!empty($accountInfo)) {
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/Items/{$productId}";
            $response = self::makeAPICall($accountInfo, "PUT", $url, $payload);
            if ($response = self::convertToData($response)) {
                if (isset($response['userMessage'])) {
                    $return = $response['userMessage'];
                } else {
                    $return = "Failed to update information.";
                }
            }
        }
        return $return;
    }
    /**
     * Function to get user detail by user id 
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
    
     */
    public function GetUserByID(object $accountInfo, $UserID)
    {
        $return = NULL;
        if (!empty($accountInfo)) {
            $base_url = $accountInfo->api_domain;
            $url =  $base_url . "/Users/ContactInfo?userID={$UserID}";
            $response = self::makeAPICall($accountInfo, "GET", $url);
            if ($response = self::convertToData($response)) {

                if (isset($response['userID'])) {
                    $return = $response;
                } else if (isset($response['userMessage'])) {
                    $return = $response['userMessage'];
                } else {
                    $return = "Failed to get user information.";
                }
            }
        }
        return $return;
    }
}
