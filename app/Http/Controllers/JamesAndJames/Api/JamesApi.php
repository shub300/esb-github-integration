<?php

namespace App\Http\Controllers\JamesAndJames\Api;

use App\Http\Controllers\Controller;
use App\Helper\MainModel;

class JamesApi extends Controller
{
    /**
     * Flag for API call test mode ON/OFF. (set true in live server)
     */
    private const TEST_MODE = false;

    /**
     * Function to check for the given credential
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     */
    protected static function checkAuthCredential(\StdClass $accountInfo)
    {
        $return_data = [];
        if (!empty($accountInfo)) {
            $endPoint = '/product/stock';
            $postData = static::createPostData($accountInfo, '', 1);
            if (!$postData || ($postData && !count($postData))) {
                return ['status_code' => 0, 'status_data' => "Api key is invalid or undefined."];
            }

            $response = static::makeAPICall('GET-WITH-POSTDATA', $endPoint, json_encode($postData));
            if (isset($response['stock']) && count($response['stock'])) {
                $return_data = ['status_code' => 1, 'status_data' => 'Account connected successfully.'];
            } else {
                $error = "Unable to connect, API call error.";
                if (isset($response['error'])) {
                    $error = $response['error'];
                }
                $return_data = ['status_code' => 0, 'status_data' => $error];
            }
        }
        return $return_data;
    }

    protected static function createPostData($accountInfo, $requestType = '', $toAuth = 0)
    {
        $returnPostData = [];
        if (!empty($accountInfo)) {
            $now = new \DateTime();
            $unix = $now->getTimestamp();
            $postData = [
                'test' => self::TEST_MODE,
                'message_timestamp' => $unix
            ];
            if ($toAuth) {
                $api_key = isset($accountInfo->jamesApiKey) ? trim($accountInfo->jamesApiKey) : null;
            } else {
                $mainModel = new MainModel();
                $api_key = isset($accountInfo->app_id) ? $mainModel->encrypt_decrypt($accountInfo->app_id, 'decrypt') : null;
                if( $requestType && $requestType == 'TO'){
                    $api_key = isset($accountInfo->app_secret) ? $mainModel->encrypt_decrypt($accountInfo->app_secret, 'decrypt') : null;
                }
            }
            if ($api_key && strlen($api_key) == 32) {
                $postData['half_api_key'] = substr($api_key, 0, 16);
                $postData['security_hash'] = md5($unix . $api_key);
            } else {
                $postData = [];
            }
            $returnPostData = $postData;
        }
        return $returnPostData;
    }

    /**
     * Main function for the API call to make
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $url, full url for the api call
     * @param $postData, data for the POST method
     * @param $toAuth, whether its true or false (when 'true' we use credentials to validate in the form of plain text, and when 'false' we creds to perform sync operation)
     */
    //public function makeAPICall( string $method, string $endPoint, string $postData ) {
    protected static function makeAPICall(string $method, string $endPoint, string $postData)
    {
        $response = false;
        $headers = ['Content-Type: application/json'];
        $baseUrl = self::TEST_MODE == true ? \Config::get('apiconfig.JamesAndJamesSandboxUrl') : \Config::get('apiconfig.JamesAndJamesBaseUrl');
        $url = $baseUrl . $endPoint;
        $mainModel = new MainModel();
        // $result = $mainModel->makeCurlRequest( $method, $url, $postData, $headers );

        $result = static::makeCurlRequest($method, $url, $postData, $headers);
        if ($result) {
            $response = json_decode($result, true);
        }

        return $response;
    }

    // Temp function for curl request, need to use common curl after updating mainModel's method
    protected static function makeCurlRequest($method, $url, $post_data, $header = [])
    {
        $request_arr = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => ($method == 'GET-WITH-POSTDATA') ? 'GET' : $method, // condition to handle if GET method requires POST request ( ex. James & James )
            CURLOPT_COOKIE => true,
        );

        if (in_array(strtolower($method), ['post', 'put', 'patch', 'get-with-postdata']) && $post_data) { // get-with-postdata
            $request_arr[CURLOPT_POSTFIELDS] = $post_data;
        }

        if (count($header)) {
            $request_arr[CURLOPT_HTTPHEADER] = $header;
        }

        $curl = curl_init();
        curl_setopt_array($curl, $request_arr);

        $response = curl_exec($curl);
        if (!$response) {
            $response = curl_error($curl);
        }

        curl_close($curl);
        return $response;
    }
}
