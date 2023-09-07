<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Snowflake\Api\SnowflakeApi;
use App\Models\PlatformOrder;

class GKTestController extends SnowflakeApi
{
    public static $myPlatform = 'snowflake';
    public $SnowflakeApi;
    public $wfSnip;
    public $platformId = 53;

    public function _contructor()
    {
        $this->SnowflakeApi = new SnowflakeApi();
    }

    public function GetOrders()
    {
        $user_integration_id = 680;
        $user_workflow_rule_id = 1303;
        $platform_account = $this->SnowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

        /**
         '{
            "id":"1133",
            "account_name":"Ecovape Snowflake Ac",
            "app_id":"amk1OEp6V1JFMGQzaFJONmRTbVA1RHA3ZDNDY211WEFqMDlpR0lMY0QwUT0=",
            "app_secret":"SGFBTWZXVDhwOFFiRC8yRDIrK1ZZaVE0Ukt2QUVwamgxZnJPQ2cvTDhrbDlreG9JNTBxRDYzMk5Tc3F4U2t1OA==",
            "api_domain":"mu77556.ap-south-1",
            "access_token":"SThOQlBIL1JZTUNhU1A0bnNQcTVNTEVFeThxeXdqc0EydHJsMVU2clg1U3dqdFZCS1YwcWRrNzFqWkt0UmYxMlJWeWpQaTRnRjBLZVFDR2J3SWV6T3h1WXA4RkpYUTF1Tk4rQUQzQVNVRXVFMVZIUzBPNFQwWW5Iekl3R2p1UjhHWnJlZU5UcGdrMEFJVzVkcnd1ODBlNWRlRVNXSjgrdkVHdGFnR1hNS0U0NitkZFNlVHJYSVUwSXRYYjh6N25Lc2pkZ3lMWTk4SmowdWhkK2lCTFYvdVJQcXB4SmdCZEZvZkU0TjE2WWpUSkhBTExtTXlJTnlBN1BuZmFjZS9kZXpZQnhjdjNKOWRTeWxqL3I0emNqSzd4cHVNSFNRNDhGemVhUnA5OXZNRDJWd2tlZUt1WkJNSllFdE1nUXY4TkI=",
            "marketplace_id":"PEOPLEVOX",
            "custom_domain":"PEOPLEVOX_SCHEMA",
            "region":"MAIN_WH",
        }';

         */
        if ($platform_account) {
            $last_updated_at = "2023-06-07 11:57:41";

            $post_data = [
                "timeout" => 1000,
                "resultSetMetaData" => [
                    "format" => "json"
                ],
                "warehouse" => $platform_account->region,
                "role" => "SYSADMIN",
            ];

            if (isset($_GET['order_type'])) {
                $order_type = $_GET['order_type'];
            }

            $api_order_type = 'po';

            $limit = 100;
            $database = $platform_account->marketplace_id;
            $schema = $platform_account->custom_domain;
            $table = "PURCHASE_ORDERS"; // This table contains the both PO and TO records (separated by their specific types)
            $post_data["statement"] = "select * from $database.$schema.$table WHERE TYPE = '$api_order_type' AND UPDATED_AT > '$last_updated_at' ORDER BY 'UPDATED_AT' ASC LIMIT $limit;";
            $response = $this->SnowflakeApi->makeAPICall($platform_account, $post_data);

            if( isset($response['api_status']) && $response['api_status'] == 1 ){
                $orders = $response['api_data']['data'] ?? [];
                if (count($orders)) {
                    $recentToDate = null;
                    $platformOrderId = null;
                    foreach ($orders as $ord) {

                        if( !isset( $ord[1] ) || ( isset( $ord[1] ) && strtoupper( $ord[1] ) != strtoupper( $api_order_type ) ) ){ // TYPE (order type)
                            continue;
                        }

                        $ord_number = (isset($ord[0]) && $ord[0]) ? $ord[0] : NULL; // ID (order number)

                        /** Section: Order [start] */
                        $orderObj = PlatformOrder::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_order_id' => $ord_number,
                            'user_workflow_rule_id' => $user_workflow_rule_id,
                            'order_type' => "PO",
                        ])
                        ->first();

                        $platformOrderId = $orderObj->id;

                        if ($platformOrderId) {
                            if ($order_type == 'PO' || $order_type == 'SO') {
                                echo $order_line_id = (isset($ord[23]) && $ord[23]) ? $ord[23] : NULL; // LINE_ITEM_ID
                                echo "<br>";
                            }
                        }
                    }
                }
            }
        }
    }
}
