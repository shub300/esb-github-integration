<?php

namespace App\Http\Controllers\Peoplevox\Api;

use App\Http\Controllers\Controller;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class PeoplevoxApi extends Controller
{
    /**
     * Function to check for the given credential
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     */
    public static function checkAuthCredential(\StdClass $accountInfo, $authType)
    {
        $returnData = [];
        if (!empty($accountInfo)) {

            if ($authType == 'auth') {
                $postDataReqFields['toAuth'] = true;
            } else {
                $postDataReqFields['toReAuth'] = true;
            }

            $postData = static::setPostDataForAPI($accountInfo, $postDataReqFields);
            $response = static::makeAPICall($accountInfo, 'Authenticate', $postData);
            if (isset($response['status_code']) && $response['status_code'] == 1) {
                $sessionDetail = $response['status_data'];
                $arrDetail = explode(',', $sessionDetail);
                if (isset($arrDetail[1])) {
                    $returnData = ['status_code' => 1, 'status_data' => $arrDetail[1]];
                } else {
                    $returnData = ['status_code' => 0, 'status_data' => "Authenticated but session id not found."];
                }
            } else {
                $returnData = $response;
            }
        }
        return $returnData;
    }

    /** */
    public static function reAuthAccount(\StdClass $accountInfo)
    {
        $returnData = true;
        if (!empty($accountInfo)) {
            $conncetion = static::checkAuthCredential($accountInfo, 'reauth');
            if (isset($conncetion['status_code']) && $conncetion['status_code'] == 1) {
                // Add the given data
                $mainModel = new MainModel();

                $account = PlatformAccount::find($accountInfo->id);
                $account->access_token = $mainModel->encrypt_decrypt($conncetion['status_data']);
                $account->save();
            } else {
                $returnData = 'Please check for the given credential.';
                if (isset($conncetion['status_data'])) {
                    $returnData = $conncetion['status_data'];
                }
            }
        }
        //return $returnData;
    }

    /**
     * Set headers for the API call
     *
     * @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validatation
     * keys
     * @param $method, Whether to get record or to save record
     * @param $templateName, Name of the record being requested
     * @param $toAuth, To identify request is for cred authorization or for regular API call
     * @param $csvData, In this, taht record will come which has tobe saved
     *
     * @return array
     */
    protected static function setPostDataForAPI(object $accountInfo, array $reqIdentifiers = []): string
    {
        $mainModel = new MainModel();
        $method = $templateName = $searchTerm = $searchField = $csvData = null;
        $pageNo = 1;
        $toAuth = false;

        if (count($reqIdentifiers)) {
            $method = (isset($reqIdentifiers['method']) && $reqIdentifiers['method']) ? $reqIdentifiers['method'] : null;
            $templateName = (isset($reqIdentifiers['templateName']) && $reqIdentifiers['templateName']) ? $reqIdentifiers['templateName'] : null;
            $searchTerm = (isset($reqIdentifiers['searchTerm']) && $reqIdentifiers['searchTerm']) ? $reqIdentifiers['searchTerm'] : null;
            $searchField = (isset($reqIdentifiers['searchField']) && $reqIdentifiers['searchField']) ? $reqIdentifiers['searchField'] : null;
            $searchOperator = (isset($reqIdentifiers['searchOperator']) && $reqIdentifiers['searchOperator']) ? $reqIdentifiers['searchOperator'] : 'Equal';
            $orderBy = (isset($reqIdentifiers['orderBy']) && $reqIdentifiers['orderBy']) ? $reqIdentifiers['orderBy'] : null;
            $orderType = (isset($reqIdentifiers['orderType']) && $reqIdentifiers['orderType']) ? $reqIdentifiers['orderType'] : null;
            $pageNo = (isset($reqIdentifiers['pageNo']) && $reqIdentifiers['pageNo']) ? $reqIdentifiers['pageNo'] : 1;
            $toAuth = (isset($reqIdentifiers['toAuth']) && $reqIdentifiers['toAuth'] == true)  ? true : false;
            $toReAuth = (isset($reqIdentifiers['toReAuth']) && $reqIdentifiers['toReAuth'] == true)  ? true : false;
            $csvData = (isset($reqIdentifiers['csvData']) && $reqIdentifiers['csvData']) ? $reqIdentifiers['csvData'] : null;
            $limit = (isset($reqIdentifiers['limit']) && $reqIdentifiers['limit']) ? $reqIdentifiers['limit'] : 0;
        }

        $postData = '<?xml version="1.0" encoding="utf-8"?>';

        // soap:Envelope [start]
        $postData .= '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                        xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">';

        if (!empty($accountInfo)) {
            if ($toAuth) {
                $clientId = isset($accountInfo->peoplevoxClientId) ? $accountInfo->peoplevoxClientId : null;
                $userName = isset($accountInfo->peoplevoxUsername) ? $accountInfo->peoplevoxUsername : null;
                $password = isset($accountInfo->peoplevoxPassword) ? $accountInfo->peoplevoxPassword : null;

                $postData .=
                    '<soap:Body>
                    <Authenticate xmlns="http://www.peoplevox.net/">
                        <clientId>' . $clientId . '</clientId>
                        <username>' . $userName . '</username>
                        <password>' . base64_encode(html_entity_decode($password)) . '</password>
                    </Authenticate>
                </soap:Body>';
            } else if ($toReAuth) {
                $clientId = isset($accountInfo->account_name) ? $accountInfo->account_name : null;
                $userName = isset($accountInfo->app_id) ? $mainModel->encrypt_decrypt($accountInfo->app_id, 'decrypt') : null;
                $password = isset($accountInfo->app_secret) ? html_entity_decode($mainModel->encrypt_decrypt($accountInfo->app_secret, 'decrypt')) : null;

                $postData .=
                    '<soap:Body>
                    <Authenticate xmlns="http://www.peoplevox.net/">
                        <clientId>' . $clientId . '</clientId>
                        <username>' . $userName . '</username>
                        <password>' . base64_encode($password) . '</password>
                    </Authenticate>
                </soap:Body>';
            } else {
                $clientId = isset($accountInfo->account_name) ? $accountInfo->account_name : null;
                $sessionId = isset($accountInfo->access_token) ? $mainModel->encrypt_decrypt($accountInfo->access_token, 'decrypt') : null;

                $postData .=
                    '<soap:Header>
                    <UserSessionCredentials xmlns="http://www.peoplevox.net/">
                        <clientId>' . $clientId . '</clientId>
                        <SessionId>' . $sessionId . '</SessionId>
                    </UserSessionCredentials>
                </soap:Header>';

                if ($method == 'SaveData') {
                    $postData .=
                        '<soap:Body>
                        <SaveData xmlns="http://www.peoplevox.net/">
                            <saveRequest>
                                <TemplateName>' . $templateName . '</TemplateName>
                                <CsvData>' . $csvData . '</CsvData>
                                <Action>0</Action>
                            </saveRequest>
                        </SaveData>
                    </soap:Body>';
                } else if ($method == 'GetData') {
                    $postData .=
                        '<soap:Body>
                        <GetData xmlns="http://www.peoplevox.net/">
                            <getRequest>
                                <TemplateName>' . $templateName . '</TemplateName>
                                <ItemsPerPage>' . $limit . '</ItemsPerPage>
                                <PageNo>' . $pageNo . '</PageNo>';
                    if ($searchTerm) {
                        if($searchOperator == 'Not equal'){
                            $postData .= '<SearchClause>' . $searchField . '!="'  . $searchTerm . '"</SearchClause>';
                        } else{
                            $postData .= '<SearchClause>' . $searchField . '.Equals("' . $searchTerm . '")</SearchClause>';
                        }
                    }
                    $postData .= '</getRequest>
                        </GetData>
                    </soap:Body>';
                } else if ($method == 'GetReportData') {
                    $postData .=
                        '<soap:Body>
                        <GetReportData xmlns="http://www.peoplevox.net/">
                            <getReportRequest>
                                <TemplateName>' . $templateName . '</TemplateName>
                                <ItemsPerPage>' . $limit . '</ItemsPerPage>
                                <PageNo>' . $pageNo . '</PageNo>';
                    if ($orderBy) {
                        $postData .= '<OrderBy>[' . $orderBy . '] ' . $orderType . '</OrderBy>';
                    }
                    if ($searchTerm) {
                        if($searchOperator == 'Not equal'){
                            $postData .= '<SearchClause>' . $searchField . '!="'  . $searchTerm . '"</SearchClause>';
                        } else{
                            $postData .= '<SearchClause>' . $searchField . '.Equals("' . $searchTerm . '")</SearchClause>';
                        }
                    }
                    $postData .= '</getReportRequest>
                        </GetReportData>
                    </soap:Body>';
                }
            }
        }

        $postData .= '</soap:Envelope>';
        // soap:Envelope [end]

        return $postData;
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
    protected static function makeAPICall(object $accountInfo, string $method, string $postData)
    {
        $response = [];
        if (!empty($accountInfo)) {
            if ($method == 'Authenticate') {
                $clientId = isset($accountInfo->peoplevoxClientId) ? $accountInfo->peoplevoxClientId : null;
                if (isset($accountInfo->account_name)) {
                    $clientId = $accountInfo->account_name;
                }
            } else {
                $clientId = isset($accountInfo->account_name) ? $accountInfo->account_name : null;
            }

            if (!$clientId) {
                return "Client id not set";
            }
            $base_url = Config::get('apiconfig.PeoplevoxBaseUrl');

            //// Temp Handling to use client's test account
            if ($clientId == 'apiQac1234') {
                $base_url = 'https://integration-qac.peoplevox.net';
            }

            $url = $base_url . '/' . $clientId . '/resources/integrationservicev4.asmx';
            $headers = [
                'Host: peoplevox.net',
                'SOAPAction: http://www.peoplevox.net/' . $method,
                'Content-Type: text/xml'
            ];

            if (isset($postData)) {
                $mainModel = new MainModel();
                $result = $mainModel->makeCurlRequest('POST', $url, $postData, $headers);
                // Storage::append('PeopleVox/' . $accountInfo->id . '/makeAPICall/' . date('d-m-Y') . '.txt', "[" . date('h:i:s') . "] Post Data: ".print_r( $postData, 1 )." Result: " .print_r( $result, 1 ));

                if (!static::isValidXml($result)) {
                    return 'API error. Invalid request or user credentials are invalid.';
                }
                $xml = preg_replace("/(<\/?)(\w+):([^>]*>)/", '$1$2$3', $result);
                $xml = simplexml_load_string($xml);
                $json = json_encode($xml);
                $result = json_decode($json, true);

                $response = static::handleApiResponse($result, $method);
                if ($response && isset($response["status_data"]) && is_string($response["status_data"]) && str_contains(strtolower($response["status_data"]), 'session') ) {
                    // It means SessionId has beed expired. Need to refresh it again.
                    static::reAuthAccount($accountInfo);
                }
            }
        }
        return $response;
    }

    /**
     * Check whether given data is valid XML or not
     * keys
     * @param $content, the response value received from API call
     */
    private static function handleApiResponse(array $response, string $method): array
    {
        $returnData = ['status_code' => 0, 'status_data' => null];
        if ($response && is_array($response)) {
            if ($method == 'SaveData') { // saveData response handling
                if (isset($response['soapBody'][$method . 'Response'][$method . 'Result']['ResponseId']) && $response['soapBody'][$method . 'Response'][$method . 'Result']['ResponseId'] == 0) {
                    $statusResponses = isset($response['soapBody'][$method . 'Response'][$method . 'Result']['Statuses']['IntegrationStatusResponse']) ? $response['soapBody'][$method . 'Response'][$method . 'Result']['Statuses']['IntegrationStatusResponse'] : null;
                    $returnData = ['status_code' => 1, 'status_data' => $statusResponses];
                } else {
                    $error = isset($response['soapBody'][$method . 'Response'][$method . 'Result']['Detail']) ? $response['soapBody'][$method . 'Response'][$method . 'Result']['Detail'] : 'API call error';
                    $returnData = ['status_code' => 0, 'status_data' => $error];
                }
            } else { // GetData response handling
                if (isset($response['soapBody'][$method . 'Response'][$method . 'Result']['ResponseId']) && $response['soapBody'][$method . 'Response'][$method . 'Result']['ResponseId'] == 0) {
                    $csvString = $response['soapBody'][$method . 'Response'][$method . 'Result']['Detail'];

                    if (strstr($csvString, "\n")) {
                        $rows = explode("\n", trim($csvString)); // split the CSV string into rows
                        $header = str_getcsv(array_shift($rows)); // extract the header row and convert to array

                        $data = array();
                        foreach ($rows as $row) {
                            $data[] = array_combine($header, str_getcsv($row)); // convert each row to an associative array
                        }
                    } else {
                        $data = $csvString;
                    }

                    $returnData = ['status_code' => 1, 'status_data' => $data];
                } else {
                    $error = isset($response['soapBody'][$method . 'Response'][$method . 'Result']['Detail']) ? $response['soapBody'][$method . 'Response'][$method . 'Result']['Detail'] : 'API call error';
                    $returnData = ['status_code' => 0, 'status_data' => $error];
                }
            }
        } else {
            $returnData = ['status_code' => 0, 'status_data' => $response];
        }
        return $returnData;
    }

    /**
     * Check whether given data is valid XML or not
     * keys
     * @param $content, the response value received from API call
     */
    private static function isValidXml(string $content): bool
    {
        $content = trim($content);
        if (empty($content)) {
            return false;
        }
        //html go to hell!
        if (stripos($content, '<!DOCTYPE html>') !== false) {
            return false;
        }

        libxml_use_internal_errors(true);
        simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        return empty($errors);
    }
}
