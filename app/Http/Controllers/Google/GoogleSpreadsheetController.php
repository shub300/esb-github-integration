<?php

namespace App\Http\Controllers\Google;

use App\Helper\Logger;
use GuzzleHttp\Client;
use App\Helper\MainModel;
use Google\Service\Script;
use Google\Service\Sheets;
use Illuminate\Http\Request;
use App\Models\PlatformField;
use App\Models\PlatformOrder;
use App\Models\PlatformLookup;
use App\Models\PlatformObject;
use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Models\UserIntegration;
use App\Helper\ConnectionHelper;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use App\Models\PlatformObjectData;
use Google\Service\Script\Content;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\PlatformProductOption;
use Google\Service\Script\ScriptFile;
use Google\Service\Sheets\DataFilter;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\Spreadsheet;
use Illuminate\Support\Facades\Config;
use App\Models\PlatformCustomFieldValue;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformProductDetailAttribute;
use Google\Service\Script\CreateProjectRequest;
use Google\Service\Sheets\DataFilterValueRange;
use Google\Service\Sheets\Request as GSRequest;
use App\Http\Controllers\Google\GoogleAuthController;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as GoogleSheet_Request;
use Google\Service\Sheets\BatchUpdateValuesByDataFilterRequest;

class GoogleSpreadsheetController extends Controller
{
    private static $platformName = 'googlesheet';
    public $authClass, $client, $service, $userData, $userId, $access_token, $mobj, $platformId, $conn, $log, $user_integration_id, $source_platform_name;
    public $spreadsheetId, $sheetId, $sheetTitle, $source_platform_id, $object_id, $firstRowArr, $firstRange, $firstColCount, $scriptData;
    public $colMeta = null;

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->conn=new ConnectionHelper();
        $this->log=new Logger();
        $this->authClass = new GoogleAuthController($platformName = self::$platformName);
        $this->platformId = $this->authClass->getPlatformId();
        $this->client = $this->authClass->getClient();
        $this->service = new Sheets($this->client);
    }

    public function logoutGoogleAccount($user_integration_id)
    {
        $access_token = $this->authClass->access_token($user_integration_id);
        if($access_token){
            $this->client->setAccessToken($access_token);
            return $this->client->revokeToken();
        }
        return true;
    }

    private function startSheetService($user_integration_id)
    {
        $data = [];
        try{
            $this->access_token = $this->authClass->access_token($user_integration_id);
            if($this->access_token){
                $this->client->setAccessToken($this->access_token);
                $data['status'] = true;
                $this->service = new Sheets($this->client);
            }else{
                $data['status'] = false;
                $data['message'] = 'Sheet Service is not started.';
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = 'SheetService - '.$e->getMessage();
        }
        return $data;
    }

    public function getNewRefreshTokenForTheCurrentUser()
    {
        $code = request('code');
        $error = request('error');
        if ( $code || $error ) {
            return $this->authClass->getAuthUrlForToken($code, $error);
        } else {
            return $this->authClass->getAuthUrlForToken();
        }
    }

    public function InitiateGoogleAuthForSpreadsheet(Request $request)
    {
        return $this->authClass->InitiateGoogleAuth();
    }

    public function checkForAuthCode()
    {
        $code = request('code');
        $error = request('error');
        return $this->authClass->getClientAuth($code, $error);
    }

    // ----- SPREADSHEET ADD, UPDATE
    public function createSpreadSheetForParticularModule($sheet_for, $user_integration_id)
    {
        $data = [];
        try{
            $objectGoogle = PlatformObject::where('name', '=', 'sheet')->first();
            if($objectGoogle == null){
                $objectGoogle = new PlatformObject();
                $objectGoogle->name = 'sheet';
                $objectGoogle->description = 'Google spreadsheet for add, update and delete';
                $objectGoogle->display_name = 'Spreadsheet';
                $objectGoogle->object_type = 'data_source';
                $objectGoogle->save();
            }
            if($sheet_for == 'CUSTOMER'){
                $resp = $this->addSpreadsheet($objectGoogle->id, 'Customer', $sheet_for, $user_integration_id);
            }elseif($sheet_for == 'SALESORDER'){
                $resp = $this->addSpreadsheet($objectGoogle->id, 'SalesOrder', $sheet_for, $user_integration_id);
            }elseif($sheet_for == 'PRODUCT'){
                $resp = $this->addSpreadsheet($objectGoogle->id, 'Product', $sheet_for, $user_integration_id);
            }
            if(!$resp['status']){
                $data['status'] = false;
                $data['message'] = $resp['message'];
            }else{
                $data['status'] = true;
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    public function addSpreadsheet($objectId, $title, $sheet_for, $user_integration_id)
    {
        $this->user_integration_id = $user_integration_id;
        $data = [];
        try{
            // CHECK FOR CUSTOMER / ORDER / PRODUCT
            if($sheet_for){
                $checkForSpreadsheet = PlatformObjectData::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $objectId,
                    'platform_id' => $this->platformId,
                    'api_id' => $sheet_for
                ])->first();
                if($checkForSpreadsheet == null){
                    $spreadsheet = new Spreadsheet([
                        'properties' => [
                            'title' => $title
                        ]
                    ]);
                    $this->access_token = $this->authClass->access_token($user_integration_id);
                    if($this->access_token){
                        $this->client->setAccessToken($this->access_token);
                    }
                    $spreadsheet = $this->service->spreadsheets->create($spreadsheet, [
                        'fields' => 'spreadsheetId'
                    ]);
                    $spreadsheetId = $spreadsheet->spreadsheetId;
                    if($spreadsheetId){
                        // ADD SPREADSHEET
                        $newObjectData = PlatformObjectData::create([
                            'name' => $title,
                            'api_code' => $spreadsheetId,
                            'api_id' => $sheet_for,
                            'user_id' => $this->userId,
                            'platform_id' => $this->platformId,
                            'platform_object_id' => $objectId,
                            'user_integration_id' => $user_integration_id,
                        ]);
                        if( $sheet_for == 'PRODUCT' || $sheet_for == 'CUSTOMER' ) {
                            // $this->createTheScriptForSheet($sheet_for, $spreadsheetId);
                        }
                        $data['status'] = true;
                    }else{
                        $data['status'] = false;
                        $data['message'] = $sheet_for.' spreadsheet not created. Server Problem!';
                    }
                }else{
                    $data['status'] = true;
                }
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    public function updateSpreadsheet(Request $request, $id)
    {
        $request->validate([
            'title' => 'required',
        ],[
            'title.required' => 'Title is required',
        ]);
        $title = $request->title;
        $spreadsheetData = PlatformObjectData::find($id);
        try{
            $reqData = [
                new GoogleSheet_Request([
                    'updateSpreadsheetProperties' => [
                        'properties' => [
                            'title' => $title
                        ],
                        'fields' => 'title'
                    ]
                ]),
            ];
            $reqUpdateBatch = new BatchUpdateSpreadsheetRequest([
                'requests' => $reqData
            ]);
            $response = $this->service->spreadsheets->batchUpdate($spreadsheetData->spreadsheetId, $reqUpdateBatch);
            $spreadsheetData->title = $title;
            $spreadsheetData->save();
            return 'done';
        }catch(\Exception $e){
            return $e->getMessage();
        }
    }

    // ----- SHEET ADD, DELETE, UPDATE
    public function addSheetRow($sheetFor, $values, $paramOption = 'RAW')
    {   \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow called for '.print_r($sheetFor, true));
        $data = $sheetData = $dimensions = [];
        $isnull = false;
        try{
            $spreadsheet = PlatformObjectData::where([
                'platform_object_id' => $this->object_id,
                'user_integration_id' => $this->user_integration_id,
                'platform_id' => $this->platformId,
                'api_id' => $sheetFor
            ])->first();
            if($spreadsheet->description != null){
                $sheetInfoArr = json_decode($spreadsheet->description, true);
                if(is_array($sheetInfoArr) && count($sheetInfoArr) == 3){
                    $dimensions['rowCount'] = $sheetInfoArr['rows'];
                    $sheetData = ['sheet_id' => $sheetInfoArr['sheet_id'], 'sheet_title' => $sheetInfoArr['sheet_title']];
                    $this->spreadsheetId = $spreadsheet->api_code;
                    $this->sheetId = $sheetInfoArr['sheet_id'];
                    $this->sheetTitle = $sheetInfoArr['sheet_title'];
                    $isnull = true;
                }
            }\Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: isnull set '.print_r($isnull, true));
            if(!$isnull){
                $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
                \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: getSheetInfoBySpreadsheetId call done');
                $this->saveSheetInfoInDatabase($spreadsheet->id, $sheetData);
                \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: saveSheetInfoInDatabase call done');
                $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: getDimensions call done');
                $isnull = true;
                if(isset($dimensions['error']) && $dimensions['error'] == true){
                    $isnull = false;
                    $firstRowAdded = $this->addFirstRowInSheetIfNot($sheetFor, $spreadsheet->api_code, $paramOption, $sheetData['sheet_id']);
                    \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: addFirstRowInSheetIfNot call done');
                    $this->saveSheetInfoInDatabase($spreadsheet->id, $firstRowAdded['data']);
                    \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: saveSheetInfoInDatabase call done');
                    if($firstRowAdded['status']){
                        $isnull = true;
                        $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                    }else{
                        $data['status'] = false;
                        $data['message'] = $firstRowAdded['message'];
                    }
                }
            }
            \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: befor insert section');
            if((isset($dimensions['error']) && $dimensions['error'] == false) || $isnull){
                $range = "A".($dimensions['rowCount']+1);
                $body = new  ValueRange([
                    'values' => $values
                ]);
                $params = [
                    'valueInputOption' => $paramOption, // RAW, USER_ENTERED
                ];
                \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: sheet update before call');
                $response = $this->service->spreadsheets_values->update($spreadsheet->api_code, $range, $body, $params);
                \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow: sheet update response: '.print_r($response, true));
                $data['status'] = true;
                $data['message'] = 'Value added successfully';
                $data['data'] = $response['updatedRows'];
                $jsondata = json_decode($spreadsheet->description, true);
                $newrow = $response['updatedRows'] + $jsondata['rows'];
                $newrows = ['rows' => $newrow];
                $this->saveSheetInfoInDatabase($spreadsheet->id, $newrows);
                if($newrow % 999 == 0){
                    $rowToStart = 2000;
                    $batchInsertRequest = new BatchUpdateSpreadsheetRequest(array(
                        'requests' => array(
                            'appendDimension' => array(
                                'sheetId' => $sheetData['sheet_id'],
                                'dimension' => "ROWS",
                                'length' => $rowToStart
                            )
                        )
                    ));
                    $this->service->spreadsheets->batchUpdate($spreadsheet->api_code, $batchInsertRequest);
                }
            }else{
                if(!isset($data['message'])){
                    $data['status'] = false;
                    $data['message'] = $dimensions['message'];
                }
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $dmsg = json_decode($e->getMessage());
            if(gettype($dmsg) == 'object' && isset($dmsg->error->code) && $dmsg->error->code == 429){
                $data['message'] = "Quota Error";
                $data['quotaerror'] = true;
            }elseif(gettype($dmsg) == 'object' && isset($dmsg->error->code) && $dmsg->error->code == 400){
                $rowToStart = 1000;
                $batchInsertRequest = new BatchUpdateSpreadsheetRequest(array(
                    'requests' => array(
                        'appendDimension' => array(
                            'sheetId' => $sheetData['sheet_id'],
                            'dimension' => "ROWS",
                            'length' => $rowToStart
                        )
                    )
                ));
                $this->service->spreadsheets->batchUpdate($spreadsheet->api_code, $batchInsertRequest);
                $data['message'] = "Error in the values. Please resync.";
            }else{
                $data['message'] = 'ASRE - '.$e->getMessage();
            }
        }
        return $data;
    }

    public function addFirstRowInSheetIfNot($sheetFor, $spreadsheetId, $paramOption, $sheet_id)
    {
        $data = [];
        $values = [];
        try{
            if($sheetFor == 'CUSTOMER'){
                $type = 'customer';
                $values[] = [
                    '#', 'Customer ID',
                    'Date Created', 'Company',
                    'Name', 'Email Address 1',
                    'Telephone 1'
                ];
            }elseif($sheetFor == 'SALESORDER'){
                $type = 'sales_order';
                $values[] = [
                    '#', 'Order ID', 'Invoice',
                    'Ref', 'Status', 'Shipping Status',
                    'Tracking Ref', 'Customer Name', 'Customer Email',
                    'Order Total', 'Paid', 'Order Currency',
                    'Date Created', 'Payment Due', 'Tax Date',
                    'Line Item SKU', 'Line Item Name', 'Nominal Code',
                    'Tax Code', 'Quantity', 'Price',
                    'Line Item Tax', 'Line Item Total',
                    'Shipping Method', 'Delivery Date', 'Default Address',
                    'Invoice Address', 'Delivery Address','Channel'
                ];
            }elseif($sheetFor == 'PRODUCT'){
                $type = 'product';
                $values[] = [
                    '#', 'Product ID', 'SKU',
                    'Product Name', 'Featured', 'Product Type',
                    'Product Brand', 'Category',
                    'Status', 'Barcode',
                    'Reporting Category', 'Reporting Sub Category',
                    'Product Variation 1', 'Product Variation 2', 'Product Variation 3',
                    'Product Variation 4', 'Condtion Notes', 'In Stock',
                    'Price List 1', 'Price List 2', 'Price List 3',
                    'Description', 'Weight (Inventory)', 'Height (Inventory)',
                    'Width (Inventory)', 'Length (Inventory)', 'Short Description',
                    'Individual/Product Box', 'Primary Vendors', 'Vendors'
                ];
            }
            $otherdata = $this->getCustomFieldsWithValuesForSheet($type); // CUSTOM FIELDS
            if(isset($otherdata['status']) && $otherdata['status']){
                // $values = array_unique(array_merge($values[0],$otherdata['data']), SORT_REGULAR);
                $values = array_merge($values[0],$otherdata['data']);
            }else{
                $values = $values[0];
            }
            $range = "A1";
            $body = new  ValueRange([
                'values' => [$values]
            ]);
            $params = [
                'valueInputOption' => $paramOption, // RAW, USER_ENTERED
            ];
            $response = $this->service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
            $data['status'] = true;
            $rangeArr = $this->getTheNewRow($response['updates']['updatedRange']);
            $data['data'] = $rangeArr;
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    public function updateSheetRow($ids, $sheetFor, $values, $paramOption = 'RAW', $ranges)
    {
        $data = $dimensions = $sheetData = [];
        $isnull = false;
        $data['status'] = true;
        try{
            $spreadsheet = PlatformObjectData::where([
                'platform_object_id' => $this->object_id,
                'user_integration_id' => $this->user_integration_id,
                'platform_id' => $this->platformId,
                'api_id' => $sheetFor
            ])->first();
            if($spreadsheet->description != null){
                $sheetInfoArr = json_decode($spreadsheet->description, true);
                if(is_array($sheetInfoArr) && count($sheetInfoArr) == 3){
                    $dimensions['rowCount'] = $sheetInfoArr['rows'];
                    $sheetData = ['sheet_id' => $sheetInfoArr['sheet_id'], 'sheet_title' => $sheetInfoArr['sheet_title']];
                    $this->spreadsheetId = $spreadsheet->api_code;
                    $this->sheetId = $sheetInfoArr['sheet_id'];
                    $this->sheetTitle = $sheetInfoArr['sheet_title'];
                    $isnull = true;
                }
            }
            if(!$isnull){
                $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
                $dimensions = $this->getSpecificDimensions($ids, $spreadsheet->api_code, $sheetData['sheet_id'], $sheetData['sheet_title']);
                if(isset($dimensions['error']) && $dimensions['error'] == true){
                    $data['status'] = false;
                    $data['message'] = $dimensions['message'];
                }else{
                    $range = "A{$dimensions['row']}:{$dimensions['col']}{$dimensions['row']}";
                }
            }
            if($this->colMeta == null){
                $colDimensions = $this->service->spreadsheets_values->batchGet(
                    $spreadsheet->api_code,
                    ['ranges' => $sheetData['sheet_title'].'!A1:1','majorDimension'=>'ROWS']
                );
                $colMeta = $colDimensions->getValueRanges()[0]->values;
                $this->colMeta = $this->colLengthToColumnAddress(count($colMeta[0]));
            }
            if($data['status']){
                for($x = 0; $x < count($values); $x++){
                    $range = 'A'.$ranges[$x].':'.$this->colMeta.$ranges[$x];
                    $body = new  ValueRange([
                        'values' => [$values[$x]]
                    ]);
                    $params = [
                        'valueInputOption' => $paramOption, // RAW, USER_ENTERED
                    ];
                    $response = $this->service->spreadsheets_values->update($spreadsheet->api_code, $range, $body, $params);
                    $data['status'] = true;
                    $data['message'] = 'Value updated successfully';
                    $data['data'] = $response['updatedRange'];
                }
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $dmsg = json_decode($e->getMessage());
            if(gettype($dmsg) == 'object' && isset($dmsg->error->code) && $dmsg->error->code == 429){
                $data['message'] = "Quota Error";
                $data['quotaerror'] = true;
            }elseif(gettype($dmsg) == 'object' && isset($dmsg->error->code) && $dmsg->error->code == 400){
                $data['message'] = "Error in the values. Please resync.";
            }else{
                $data['message'] = 'ASRE - '.$e->getMessage();
            }
        }
        return $data;
    }

    public function deleteSheetRow($id, $sheetFor)
    {
        $data = [];
        $data['status'] = true;
        try{
            $objectGoogle = PlatformObject::where('name', '=', 'sheet')->first();
            $spreadsheet = PlatformObjectData::where([
                'platform_object_id' => $objectGoogle->id,
                'platform_id' => $this->platformId,
                'user_integration_id' => $this->user_integration_id,
                'api_id' => $sheetFor
            ])->first();
            $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
            $dimensions = $this->getSpecificDimensions($id, $spreadsheet->api_code, $sheetData['sheet_id'], $sheetData['sheet_title']);
            if(isset($dimensions['error']) && $dimensions['error'] == true){
                $data['status'] = false;
                $data['message'] = $dimensions['message'];
            }else{
                $range = "A{$dimensions['row']}:{$dimensions['col']}{$dimensions['row']}";
            }
            if($data['status']){
                $batchDeleteRequest = new BatchUpdateSpreadsheetRequest(array(
                    'requests' => array(
                        'deleteDimension' => array(
                            'range' => array(
                                'sheetId' => $sheetData['sheet_id'],
                                'dimension' => "ROWS",
                                'startIndex' => $dimensions['row'] - 1,
                                'endIndex' => $dimensions['row']
                            )
                        )
                    )
                ));
                $response = $this->service->spreadsheets->batchUpdate($spreadsheet->api_code, $batchDeleteRequest);
                $data['status'] = true;
                $data['message'] = 'Value deleted successfully';
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    // ----- SHEET CREATION/UPDATE/DELETE :: START
    public function syncSheetForCustomer($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {
        $returnstatus = true;
        try{
            $sheet_for = "CUSTOMER";
            $source_platform_id = PlatformLookup::where(['platform_id' => $source_platform_id, 'status' => 1])->select('id')->first();
            if($source_platform_id){
                $source_platform_id = $source_platform_id->id;
                $this->source_platform_id = $source_platform_id;
                $object_id = PlatformObject::where(['name' => 'sheet', 'status' => 1])->select('id')->first();
                if($object_id){
                    $object_id = $object_id->id;
                    $this->object_id = $object_id;
                }
                if($record_id){
                    $sync_status = 'Failed';
                }else{
                    $sync_status = 'Ready';
                }
                $limit = 100;
                $parent_customers = PlatformCustomer::where([
                    'platform_id' => $source_platform_id,
                    'user_integration_id' => $user_integration_id,
                    'sync_status' => $sync_status
                ]);
                if($record_id){
                    $parent_customers = $parent_customers->where('id', $record_id);
                }
                $parent_customers = $parent_customers->limit($limit)->get();
                if($parent_customers){
                    $lastInserId = false;
                    $insertvalues = $updatevalues = $updateIds = $updateSheetId = [];
                    $spreadsheet = PlatformObjectData::where([
                        'platform_object_id' => $this->object_id,
                        'user_integration_id' => $this->user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_id' => $sheet_for
                    ])->first();
                    $isnull = false;
                    if($spreadsheet->description != null){
                        $sheetInfoArr = json_decode($spreadsheet->description, true);
                        if(is_array($sheetInfoArr) && count($sheetInfoArr) == 3){
                            $lastInserId = $sheetInfoArr['rows'] + 1;
                            $isnull = true;
                        }
                    }
                    if(!$isnull){
                        $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
                        $this->saveSheetInfoInDatabase($spreadsheet->id, $sheetData);
                        $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                        $isnull = true;
                        if(isset($dimensions['error']) && $dimensions['error'] == true){
                            $isnull = false;
                            $firstRowAdded = $this->addFirstRowInSheetIfNot($sheet_for, $spreadsheet->api_code, 'USER_ENTERED', $sheetData['sheet_id']);
                            $this->saveSheetInfoInDatabase($spreadsheet->id, $firstRowAdded['data']);
                            if($firstRowAdded['status']){
                                $isnull = true;
                                $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                            }else{
                                $data['status'] = false;
                                $data['message'] = $firstRowAdded['message'];
                            }
                        }
                    }
                    if(!$lastInserId){
                        $lastInserId = 2;
                    }
                    foreach($parent_customers as $parent_customer){
                        // CHECK FOR NULL VALUES
                        $customer_company_name = $parent_customer->company_name ? $parent_customer->company_name : '';
                        $customer_name = $parent_customer->customer_name ? $parent_customer->customer_name : '';
                        $customer_email = $parent_customer->email ? $parent_customer->email : '';
                        $customer_phone = $parent_customer->phone ? "'".$parent_customer->phone : '';
                        // $customer_job_title = $parent_customer->job_title ? $parent_customer->job_title : '';
                        // GET THE VALUES OF CUSTOMER TO SHEET TO ARRAY BEFORE CHECK IF THE LINKEDID IS 0
                        $values = [
                            "=ROW() - 1", $parent_customer->api_customer_id,
                            date('d-m-Y', strtotime($parent_customer->created_at)), $customer_company_name,
                            $customer_name, $customer_email,
                            $customer_phone
                        ];
                        $customfields = $this->addCustomFieldToRow($sheet_for, $values, $parent_customer->id);
                        if(isset($customfields['status']) && $customfields['status']){
                            $values = $customfields['data'];
                        }
                        if(!$parent_customer->linked_id){
                            $isinsert = true;
                            $insertvalues[] = $values;
                        }elseif($parent_customer->linked_id){
                            $isinsert = false;
                            $updateIds[] = $parent_customer->id;
                            $updatevalues[] = $values;
                        }
                        $updateParent = PlatformCustomer::find($parent_customer->id);
                        if($isinsert){
                            // INSERT THE SHEET INSERTED CUSTOMER
                            $child_customer = new PlatformCustomer();
                            $child_customer->user_id = $user_id;
                            $child_customer->user_integration_id = $user_integration_id;
                            $child_customer->platform_id = $this->platformId;
                            $child_customer->company_name = $customer_company_name;
                            $child_customer->customer_name = $customer_name;
                            $child_customer->email = $customer_email;
                            $child_customer->phone = $parent_customer->phone;
                            $child_customer->linked_id = $parent_customer->id;
                            $child_customer->sync_status = 'Pending';
                            $updateParent->sync_status = 'Synced';
                            $child_customer->api_customer_id = $lastInserId;
                            $statusForSync = 'success';
                            $message = 'Value added successfully';
                            $child_customer->save();
                            $updateParent->linked_id = $child_customer->id;
                        }else{
                            $child_customer = PlatformCustomer::find($parent_customer->linked_id);
                            // UPDATE THE CHILD CUSTOMER
                            $child_customer->company_name = $customer_company_name;
                            $child_customer->customer_name = $customer_name;
                            $child_customer->email = $customer_email;
                            $child_customer->phone = $parent_customer->phone;
                            $child_customer->sync_status = 'Pending';
                            $updateParent->sync_status = 'Synced';
                            $child_customer->api_customer_id = $child_customer->api_customer_id;
                            $statusForSync = 'success';
                            $message = 'Value updated successfully.';
                            $child_customer->save();
                        }
                        $updateParent->update();
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_customer->id, $message);
                        if($parent_customer->linked_id){
                            $updateSheetId[] = $child_customer->api_customer_id;
                        }
                        $lastInserId++;
                    }
                    if(count($insertvalues) > 0){
                        $insresp = $this->addSheetRow($sheet_for, $insertvalues, 'USER_ENTERED');
                    }
                    if(count($updatevalues) > 0){
                        $this->updateSheetRow($updateIds, $sheet_for, $updatevalues, 'USER_ENTERED', $updateSheetId);
                    }
                }else{
                    $returnstatus = 'No data to sync for customer';
                }
            }else{
                $returnstatus = 'No platform found';
            }
        }catch(\Exception $e){
            $returnstatus = 'Customer - '.$e->getMessage();
        }
        return $returnstatus;
    }

    public function syncSheetForProduct($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {
        $returnstatus = true;
        try{
            $sheet_for = "PRODUCT";
            $source_platform_id = PlatformLookup::where(['platform_id' => $source_platform_id, 'status' => 1])->select('id')->first();
            if($source_platform_id){
                $source_platform_id = $source_platform_id->id;
                $this->source_platform_id = $source_platform_id;
                $object_id = PlatformObject::where(['name' => 'sheet', 'status' => 1])->select('id')->first();
                if($object_id){
                    $object_id = $object_id->id;
                    $this->object_id = $object_id;
                }
                if($record_id){
                    $sync_status = 'Failed';
                }else{
                    $sync_status = 'Ready';
                }
                $limit = 80;
                $parent_products = PlatformProduct::where([
                    'platform_id' => $source_platform_id,
                    'user_integration_id' => $user_integration_id,
                    'product_sync_status' => $sync_status
                ]);
                if($record_id){
                    $parent_products = $parent_products->where('id', $record_id);
                }
                $parent_products = $parent_products->limit($limit)->get();
                if($parent_products->count()){
                    $lastInserId = false;
                    $insertvalues = $updatevalues = $updateSheetId = $updateIds = [];
                    $spreadsheet = PlatformObjectData::where([
                        'platform_object_id' => $this->object_id,
                        'user_integration_id' => $this->user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_id' => $sheet_for
                    ])->first();
                    $isnull = false;
                    if($spreadsheet->description != null){
                        $sheetInfoArr = json_decode($spreadsheet->description, true);
                        if(is_array($sheetInfoArr) && count($sheetInfoArr) == 3){
                            $lastInserId = $sheetInfoArr['rows'] + 1;
                            $isnull = true;
                        }
                    }
                    if(!$isnull){
                        $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
                        $this->saveSheetInfoInDatabase($spreadsheet->id, $sheetData);
                        $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                        $isnull = true;
                        if(isset($dimensions['error']) && $dimensions['error'] == true){
                            $isnull = false;
                            $firstRowAdded = $this->addFirstRowInSheetIfNot($sheet_for, $spreadsheet->api_code, 'USER_ENTERED', $sheetData['sheet_id']);
                            $this->saveSheetInfoInDatabase($spreadsheet->id, $firstRowAdded['data']);
                            if($firstRowAdded['status']){
                                $isnull = true;
                                $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                            }else{
                                $data['status'] = false;
                                $data['message'] = $firstRowAdded['message'];
                            }
                        }
                    }
                    if(!$lastInserId){
                        $lastInserId = 2;
                    }
                    $product_category_object = PlatformObject::where([
                        'name' => 'category',
                    ])->select('id')->first();
                    $product_brand_object = PlatformObject::where([
                        'name' => 'brand',
                    ])->select('id')->first();
                    foreach($parent_products as $parent_product){
                        $productDetails = PlatformProductDetailAttribute::where(['platform_product_id' => $parent_product->id])->first();
                        $productOptions = PlatformProductOption::where(['platform_product_id' => $parent_product->id, 'status' => 1])->get();
                        // CHECK FOR NULL VALUES
                        $product_variation = [];
                        $product_variation['variant_1'] = $product_variation['variant_2'] = $product_variation['variant_3'] = $product_variation['variant_4'] = '';
                        $product_type = $collection = $barcode = $reporting_category = $season = '';
                        $condition_notes = $height = $width = $length = $short_description = $indProductBox = '';
                        $primary_vendor = $vendor = $featured = $reporting_sub_category = '';
                        if($productDetails){
                            $height = $productDetails->height ? $productDetails->height : '';
                            $width = $productDetails->width ? $productDetails->width : '';
                            $length = $productDetails->length ? $productDetails->length : '';
                            $short_description = $productDetails->shortdescription ? $productDetails->shortdescription : '';
                        }
                        if($productOptions->count()){
                            $variantcount = 1;
                            foreach($productOptions as $productOption){
                                $product_variation['variant_'.$variantcount] = $productOption->option_name.' - '.$productOption->option_value;
                                $variantcount++;
                            }
                        }
                        $product_sku = $parent_product->sku ? $parent_product->sku : '';
                        $product_product_name = $parent_product->product_name ? $parent_product->product_name : '';
                        $product_brand_id = $parent_product->brand_id ? $parent_product->brand_id : '';
                        if ($product_brand_id) {
                            $product_brand = PlatformObjectData::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $source_platform_id,
                                'platform_object_id' => $product_brand_object->id,
                                'api_id' => $product_brand_id,
                            ])->select('name')->first();
                            if ($product_brand) {
                                $product_brand_id = $product_brand->name;
                            }
                        }
                        // PRODUCT CATEGORY NAMES
                        $product_category_names = [];
                        $product_category_id = $parent_product->category_id ? $parent_product->category_id : '';
                        if(!empty($product_category_id)){
                            $product_category_ids = explode(',', $product_category_id);
                            if(count($product_category_ids)){
                                foreach($product_category_ids as $cat_id){
                                    $product_object_data_for_category = PlatformObjectData::where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $source_platform_id,
                                        'platform_object_id' => $product_category_object->id,
                                        'api_id' => $cat_id,
                                    ])->select('name')->first();
                                    if($product_object_data_for_category){
                                        $product_category_names[] = $product_object_data_for_category->name;
                                    }
                                }
                            }
                        }
                        if(count($product_category_names)){
                            $product_category_names = implode(',', $product_category_names);
                        }else{
                            $product_category_names = '';
                        }
                        $product_product_status = $parent_product->product_status ? $parent_product->product_status : '';
                        $product_ean = $parent_product->ean ? $parent_product->ean : '';
                        $product_upc = $parent_product->upc ? $parent_product->upc : '';
                        $product_isbn = $parent_product->isbn ? $parent_product->isbn : '';
                        $product_mpn = $parent_product->mpn ? $parent_product->mpn : '';
                        $product_product_status = $parent_product->product_status ? $parent_product->product_status : '';
                        $product_stock_track = $parent_product->stock_track ? $parent_product->stock_track : '';
                        $product_description = $parent_product->description ? $parent_product->description : '';
                        $product_weight = $parent_product->weight ? $parent_product->weight : '';
                        $productPriceLists = PlatformProduct::find($parent_product->id)->platformProductPriceList;
                        $price_list = [];
                        $price_list[1] = $price_list[2] = $price_list[3] = '';
                        if($productPriceLists){
                            $x = 1;
                            foreach($productPriceLists as $productPriceList){
                                if($x <= 3){
                                    $price_list[$x] = $productPriceList->price;
                                }
                                $x++;
                            }
                        }

                        // GET THE VALUES OF PRODUCT TO SHEET TO ARRAY BEFORE CHECK IF THE LINKEDID IS 0
                        $values = [
                            '=ROW() - 1', $parent_product->api_product_id, $product_sku,
                            $product_product_name, $featured, $product_type,
                            $product_brand_id, $product_category_names,
                            $product_product_status, $barcode,
                            $reporting_category, $reporting_sub_category,
                            $product_variation['variant_1'], $product_variation['variant_2'], $product_variation['variant_3'],
                            $product_variation['variant_4'], $condition_notes, $product_stock_track,
                            $price_list[1], $price_list[2], $price_list[3],
                            $product_description, $product_weight, $height,
                            $width, $length, $short_description,
                            $indProductBox, $primary_vendor, $vendor
                        ];
                        $customfields = $this->addCustomFieldToRow($sheet_for, $values, $parent_product->id);
                        if(isset($customfields['status']) && $customfields['status']){
                            $values = $customfields['data'];
                        }
                        if(!$parent_product->linked_id){
                            $isinsert = true;
                            $insertvalues[] = $values;
                        }elseif($parent_product->linked_id){
                            $isinsert = false;
                            $updateIds[] = $parent_product->id;
                            $updatevalues[] = $values;
                        }
                        $updateParent = PlatformProduct::find($parent_product->id);
                        if($isinsert){
                            // INSERT PRODUCT IN SHEET
                            $child_product = new PlatformProduct();
                            $child_product->user_id = $user_id;
                            $child_product->user_integration_id = $user_integration_id;
                            $child_product->platform_id = $this->platformId;
                            $child_product->sku = $product_sku;
                            $child_product->product_name = $product_product_name;
                            $child_product->brand_id = $product_brand_id;
                            $child_product->category_id = $product_category_id;
                            $child_product->product_status = $product_product_status;
                            $child_product->ean = $product_ean;
                            $child_product->upc = $product_upc;
                            $child_product->isbn = $product_isbn;
                            $child_product->mpn = $product_mpn;
                            $child_product->product_status = $product_product_status;
                            $child_product->stock_track = $product_stock_track;
                            $child_product->description = $product_description;
                            $child_product->weight = $product_weight;
                            $child_product->linked_id = $parent_product->id;
                            $child_product->product_sync_status = 'Pending';
                            $updateParent->product_sync_status = 'Synced';
                            $child_product->api_product_id = $lastInserId;
                            $statusForSync = 'success';
                            $message = 'Value Added successfully.';
                            $child_product->save();
                            $updateParent->linked_id = $child_product->id;
                        }else{
                            // UPDATE THE CHILD PRODUCT
                            $child_product = PlatformProduct::find($parent_product->linked_id);
                            $child_product->sku = $product_sku;
                            $child_product->product_name = $product_product_name;
                            $child_product->brand_id = $product_brand_id;
                            $child_product->category_id = $product_category_id;
                            $child_product->product_status = $product_product_status;
                            $child_product->ean = $product_ean;
                            $child_product->upc = $product_upc;
                            $child_product->isbn = $product_isbn;
                            $child_product->mpn = $product_mpn;
                            $child_product->product_status = $product_product_status;
                            $child_product->stock_track = $product_stock_track;
                            $child_product->description = $product_description;
                            $child_product->weight = $product_weight;
                            $child_product->linked_id = $parent_product->id;
                            $child_product->product_sync_status = 'Pending';
                            $updateParent->product_sync_status = 'Synced';
                            $child_product->api_product_id = $child_product->api_product_id;
                            $statusForSync = 'success';
                            $message = 'Value updated successfully';
                            $child_product->save();
                        }
                        if($parent_product->linked_id){
                            $updateSheetId[] = $child_product->api_product_id;
                        }
                        $updateParent->save();
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_product->id, $message);
                        $lastInserId++;
                    }
                    if(count($insertvalues) > 0){
                        $this->addSheetRow($sheet_for, $insertvalues, 'USER_ENTERED');
                    }
                    if(count($updatevalues) > 0){
                        $this->updateSheetRow([], $sheet_for, $updatevalues, 'USER_ENTERED', $updateSheetId);
                    }
                }else{
                    $returnstatus = 'No data to sync for product';
                }
            }else{
                $returnstatus = 'No platform found';
            }
        }catch(\Exception $e){
            $returnstatus = 'Product - '.$e->getMessage();
        }
        return $returnstatus;
    }

    public function syncSheetForSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {
        $returnstatus = true;
        try{
            $sheet_for = "SALESORDER";
            $source_platform_id = PlatformLookup::where(['platform_id' => $source_platform_id, 'status' => 1])->select('id')->first();
            if($source_platform_id){
                $source_platform_id = $source_platform_id->id;
                $this->source_platform_id = $source_platform_id;
                $object_id = PlatformObject::where(['name' => 'sheet', 'status' => 1])->select('id')->first();
                if($object_id){
                    $object_id = $object_id->id;
                    $this->object_id = $object_id;
                }
                if($record_id){
                    $sync_status = 'Failed';
                }else{
                    $sync_status = 'Ready';
                }
                $limit = 50;
                $parent_salesorders = PlatformOrder::where([
                    'platform_id' => $source_platform_id,
                    'user_integration_id' => $user_integration_id,
                    'sync_status' => $sync_status
                ]);
                if($record_id){
                    $parent_salesorders = $parent_salesorders->where('id', $record_id);
                }
                $parent_salesorders = $parent_salesorders->limit($limit)->get();
                if($parent_salesorders){
                    $lastInserId = false;
                    $insertvalues = $updatevalues = $updateSheetId = [];
                    $spreadsheet = PlatformObjectData::where([
                        'platform_object_id' => $this->object_id,
                        'user_integration_id' => $this->user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_id' => $sheet_for
                    ])->first();
                    $isnull = false;
                    if($spreadsheet->description != null){
                        $sheetInfoArr = json_decode($spreadsheet->description, true);
                        if(is_array($sheetInfoArr) && count($sheetInfoArr) == 3){
                            $lastInserId = $sheetInfoArr['rows'] + 1;
                            $isnull = true;
                        }
                    }
                    if(!$isnull){
                        $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
                        $this->saveSheetInfoInDatabase($spreadsheet->id, $sheetData);
                        $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                        $isnull = true;
                        if(isset($dimensions['error']) && $dimensions['error'] == true){
                            $isnull = false;
                            $firstRowAdded = $this->addFirstRowInSheetIfNot($sheet_for, $spreadsheet->api_code, 'USER_ENTERED', $sheetData['sheet_id']);
                            $this->saveSheetInfoInDatabase($spreadsheet->id, $firstRowAdded['data']);
                            if($firstRowAdded['status']){
                                $isnull = true;
                                $dimensions = $this->getDimensions($spreadsheet->api_code, $sheetData['sheet_title']);
                            }else{
                                $data['status'] = false;
                                $data['message'] = $firstRowAdded['message'];
                            }
                        }
                    }
                    if(!$lastInserId){
                        $lastInserId = 2;
                    }
                    foreach($parent_salesorders as $parent_salesorder){
                        // CHECK FOR NULL VALUES
                        $invoice = $shipping_status = $tracking_ref = $nominal_code = $salesOrderCustomerInfo_customer_name = $salesOrderCustomerInfo_email = '';
                        $default_address = $invoice_address = $delivery_address = $channel = '';
                        $salesOrderLineInfo_sku = $salesOrderLineInfo_product_name = $salesOrderLineInfo_taxes = '';
                        $salesOrderLineInfo_qty = $salesOrderLineInfo_price = $salesOrderLineInfo_total_tax = $salesOrderLineInfo_total = '';
                        // CUSTOMER INFO
                        $salesOrderCustomerInfo = PlatformOrder::find($parent_salesorder->id)->platformCustomer;
                        if($salesOrderCustomerInfo){
                            $salesOrderCustomerInfo_customer_name = $salesOrderCustomerInfo->customer_name ? $salesOrderCustomerInfo->customer_name : '';
                            $salesOrderCustomerInfo_email = $salesOrderCustomerInfo->email ? $salesOrderCustomerInfo->email : '';
                        }
                        $salesorder_api_order_reference = $parent_salesorder->api_order_reference ? $parent_salesorder->api_order_reference : '';
                        $salesorder_order_status = $parent_salesorder->order_status ? $parent_salesorder->order_status : '';
                        $salesorder_total_amount = $parent_salesorder->total_amount ? $parent_salesorder->total_amount : '';
                        $salesorder_api_order_payment_status = $parent_salesorder->api_order_payment_status ? $parent_salesorder->api_order_payment_status : '';
                        $salesorder_currency = $parent_salesorder->currency ? $parent_salesorder->currency : '';
                        $salesorder_order_date = $parent_salesorder->order_date ? $parent_salesorder->order_date : '';
                        $salesorder_due_days = $parent_salesorder->due_days ? $parent_salesorder->due_days : '';
                        $salesorder_shipping_method = $parent_salesorder->shipping_method ? $parent_salesorder->shipping_method : '';
                        $salesorder_delivery_date = $parent_salesorder->delivery_date ? $parent_salesorder->delivery_date : '';
                        $salesorder_shipping_method = $parent_salesorder->shipping_method ? $parent_salesorder->shipping_method : '';
                        // INSERT UPDATE ORDER
                        if(!$parent_salesorder->linked_id){
                            $isinsert = true;
                        }elseif($parent_salesorder->linked_id){
                            $isinsert = false;
                        }
                        $updateParent = PlatformOrder::find($parent_salesorder->id);
                        if($isinsert){
                            // INSERT SALES-OREDR IN SHEET
                            $child_salesorder = new PlatformOrder();
                            $child_salesorder->user_id = $user_id;
                            $child_salesorder->user_integration_id = $user_integration_id;
                            $child_salesorder->platform_id = $this->platformId;
                            $child_salesorder->api_order_reference = $salesorder_api_order_reference;
                            $child_salesorder->order_status = $salesorder_order_status;
                            $child_salesorder->order_type = 'SO';
                            $child_salesorder->total_amount = $salesorder_total_amount;
                            $child_salesorder->api_order_payment_status = $salesorder_api_order_payment_status;
                            $child_salesorder->currency = $salesorder_currency;
                            $child_salesorder->order_date = $salesorder_order_date;
                            $child_salesorder->due_days = $salesorder_due_days;
                            $child_salesorder->shipping_method = $salesorder_shipping_method;
                            $child_salesorder->delivery_date = $salesorder_delivery_date;
                            $child_salesorder->shipping_method = $salesorder_shipping_method;
                            $child_salesorder->linked_id = $parent_salesorder->id;
                            $child_salesorder->platform_customer_id = $parent_salesorder->platform_customer_id;
                            $child_salesorder->sync_status = 'Ready';
                            $child_salesorder->order_updated_at = date('Y-m-d H:i:s');
                            $updateParent->sync_status = 'Synced';
                            $updateParent->order_updated_at = date('Y-m-d H:i:s');
                            $child_salesorder->api_order_id = $lastInserId;
                            $statusForSync = 'success';
                            $message = 'Value Inserted successfully.';
                            $child_salesorder->save();
                            $updateParent->linked_id = $child_salesorder->id;
                        }else{
                            $child_salesorder = PlatformOrder::find($parent_salesorder->linked_id);
                            // UPDATE THE CHILD SALESOREDR
                            $child_salesorder->api_order_reference = $salesorder_api_order_reference;
                            $child_salesorder->order_status = $salesorder_order_status;
                            $child_salesorder->total_amount = $salesorder_total_amount;
                            $child_salesorder->api_order_payment_status = $salesorder_api_order_payment_status;
                            $child_salesorder->currency = $salesorder_currency;
                            $child_salesorder->order_date = $salesorder_order_date;
                            $child_salesorder->due_days = $salesorder_due_days;
                            $child_salesorder->shipping_method = $salesorder_shipping_method;
                            $child_salesorder->delivery_date = $salesorder_delivery_date;
                            $child_salesorder->linked_id = $parent_salesorder->id;
                            $child_salesorder->platform_customer_id = $parent_salesorder->platform_customer_id;
                            $child_salesorder->sync_status = 'Ready';
                            $child_salesorder->order_updated_at = date('Y-m-d H:i:s');
                            $updateParent->sync_status = 'Synced';
                            $updateParent->order_updated_at = date('Y-m-d H:i:s');
                            $child_salesorder->api_order_id = $child_salesorder->api_order_id;
                            $statusForSync = 'success';
                            $child_salesorder->save();
                            $message = 'Value updated successfully.';
                        }
                        $updateParent->save();
                        // ORDER LINE INFO
                        $salesOrderLineInfos = PlatformOrder::find($parent_salesorder->id)->platformOrderLine;
                        if($salesOrderLineInfos){
                            foreach($salesOrderLineInfos as $salesOrderLineInfo){
                                $salesOrderLineInfo_sku = $salesOrderLineInfo->sku ? $salesOrderLineInfo->sku : '';
                                $salesOrderLineInfo_product_name = $salesOrderLineInfo->product_name ? $salesOrderLineInfo->product_name : '';
                                $salesOrderLineInfo_taxes = $salesOrderLineInfo->taxes ? $salesOrderLineInfo->taxes : '';
                                $salesOrderLineInfo_qty = $salesOrderLineInfo->qty ? $salesOrderLineInfo->qty : '';
                                $salesOrderLineInfo_price = $salesOrderLineInfo->price ? $salesOrderLineInfo->price : '';
                                $salesOrderLineInfo_total_tax = $salesOrderLineInfo->total_tax ? $salesOrderLineInfo->total_tax : '';
                                $salesOrderLineInfo_total = $salesOrderLineInfo->total ? $salesOrderLineInfo->total : '';
                                // GET THE VALUES OF CUSTOMER TO SHEET TO ARRAY BEFORE CHECK IF THE LINKEDID IS 0
                                $values = [
                                    '=ROW() - 1', $parent_salesorder->api_order_id, $invoice,
                                    $salesorder_api_order_reference, $salesorder_order_status, $shipping_status,
                                    $tracking_ref, $salesOrderCustomerInfo_customer_name, $salesOrderCustomerInfo_email,
                                    $salesorder_total_amount, $salesorder_api_order_payment_status, $salesorder_currency,
                                    $salesorder_order_date, $salesorder_due_days, $salesorder_order_date,
                                    $salesOrderLineInfo_sku, $salesOrderLineInfo_product_name, $nominal_code,
                                    $salesOrderLineInfo_taxes, $salesOrderLineInfo_qty, $salesOrderLineInfo_price,
                                    $salesOrderLineInfo_total_tax, $salesOrderLineInfo_total, $salesorder_shipping_method,
                                    $salesorder_delivery_date, $default_address, $invoice_address,
                                    $delivery_address, $channel
                                ];
                                $customfields = $this->addCustomFieldToRow($sheet_for, $values, $parent_salesorder->id);
                                if(isset($customfields['status']) && $customfields['status']){
                                    $values = $customfields['data'];
                                }
                                if(!$salesOrderLineInfo->linked_id){
                                    $isinsert = true;
                                    $insertvalues[] = $values;
                                }elseif($salesOrderLineInfo->linked_id){
                                    $isinsert = false;
                                    $updatevalues[] = $values;
                                }
                                $updateOrderLine = PlatformOrderLine::find($salesOrderLineInfo->id);
                                if($isinsert){
                                    $childOrderLine = new PlatformOrderLine();
                                    $childOrderLine->platform_order_id = $child_salesorder->id;
                                    $childOrderLine->api_order_line_id = $lastInserId;
                                    $childOrderLine->api_product_id = $updateOrderLine->api_product_id;
                                    $childOrderLine->product_name = $updateOrderLine->product_name;
                                    $childOrderLine->item_row_sequence = $updateOrderLine->item_row_sequence;
                                    $childOrderLine->ean = $updateOrderLine->ean;
                                    $childOrderLine->sku = $updateOrderLine->sku;
                                    $childOrderLine->gtin = $updateOrderLine->gtin;
                                    $childOrderLine->upc = $updateOrderLine->upc;
                                    $childOrderLine->mpn = $updateOrderLine->mpn;
                                    $childOrderLine->qty = $updateOrderLine->qty;
                                    $childOrderLine->subtotal = $updateOrderLine->subtotal;
                                    $childOrderLine->subtotal_tax = $updateOrderLine->subtotal_tax;
                                    $childOrderLine->total = $updateOrderLine->total;
                                    $childOrderLine->total_tax = $updateOrderLine->total_tax;
                                    $childOrderLine->taxes = $updateOrderLine->taxes;
                                    $childOrderLine->variation_id = $updateOrderLine->variation_id;
                                    $childOrderLine->price = $updateOrderLine->price;
                                    $childOrderLine->unit_price = $updateOrderLine->unit_price;
                                    $childOrderLine->uom = $updateOrderLine->uom;
                                    $childOrderLine->description = $updateOrderLine->description;
                                    $childOrderLine->notes = $updateOrderLine->notes;
                                    $childOrderLine->api_code = $updateOrderLine->api_code;
                                    $childOrderLine->row_type = $updateOrderLine->row_type;
                                    $childOrderLine->linked_id = $updateOrderLine->id;
                                }else{
                                    $childOrderLine = PlatformOrderLine::find($salesOrderLineInfo->linked_id);
                                    $childOrderLine->platform_order_id = $child_salesorder->id;
                                    $childOrderLine->api_order_line_id = $childOrderLine->api_order_line_id;
                                    $childOrderLine->api_product_id = $updateOrderLine->api_product_id;
                                    $childOrderLine->product_name = $updateOrderLine->product_name;
                                    $childOrderLine->item_row_sequence = $updateOrderLine->item_row_sequence;
                                    $childOrderLine->ean = $updateOrderLine->ean;
                                    $childOrderLine->sku = $updateOrderLine->sku;
                                    $childOrderLine->gtin = $updateOrderLine->gtin;
                                    $childOrderLine->upc = $updateOrderLine->upc;
                                    $childOrderLine->mpn = $updateOrderLine->mpn;
                                    $childOrderLine->qty = $updateOrderLine->qty;
                                    $childOrderLine->subtotal = $updateOrderLine->subtotal;
                                    $childOrderLine->subtotal_tax = $updateOrderLine->subtotal_tax;
                                    $childOrderLine->total = $updateOrderLine->total;
                                    $childOrderLine->total_tax = $updateOrderLine->total_tax;
                                    $childOrderLine->taxes = $updateOrderLine->taxes;
                                    $childOrderLine->variation_id = $updateOrderLine->variation_id;
                                    $childOrderLine->price = $updateOrderLine->price;
                                    $childOrderLine->unit_price = $updateOrderLine->unit_price;
                                    $childOrderLine->uom = $updateOrderLine->uom;
                                    $childOrderLine->description = $updateOrderLine->description;
                                    $childOrderLine->notes = $updateOrderLine->notes;
                                    $childOrderLine->api_code = $updateOrderLine->api_code;
                                    $childOrderLine->row_type = $updateOrderLine->row_type;
                                }
                                $childOrderLine->save();
                                $updateOrderLine->linked_id = $childOrderLine->id;
                                $updateOrderLine->save();
                                if($updateOrderLine->linked_id){
                                    $updateSheetId[] = $childOrderLine->api_order_line_id;
                                }
                            }
                        }
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_salesorder->id, $message);
                    }
                    if(count($insertvalues) > 0){
                        \Storage::append('syncSheetForSalesOrder.txt', '######');
                        \Storage::append('syncSheetForSalesOrder.txt', 'time: '.now().' | insertvalues: '.print_r($insertvalues,true));
                        $so_resp = $this->addSheetRow($sheet_for, $insertvalues, 'USER_ENTERED');
                        \Storage::append('syncSheetForSalesOrder.txt', 'addSheetRow response: '.print_r($so_resp,true));
                        \Storage::append('syncSheetForSalesOrder.txt', ' ');
                    }
                    if(count($updatevalues) > 0){
                        $this->updateSheetRow([], $sheet_for, $updatevalues, 'USER_ENTERED', $updateSheetId);
                    }
                }else{
                    $returnstatus = 'No data to sync for sales order';
                }
            }else{
                $returnstatus = 'No platform found';
            }
        }catch(\Exception $e){
            $returnstatus = 'Salesorder - '.$e->getMessage();
        }
        return $returnstatus;
    }
    // ----- SHEET CREATION/UPDATE/DELETE :: START

    // ----- EXTRA FUNCTIONS :: START
    private function createTheScriptForSheet($sheet_for, $spreadsheet_id)
    {
        if($this->access_token){
            $this->scriptData = $this->getScriptCredencialInfo($sheet_for);
            if($this->scriptData){
                // Initiated script class
                $script = new Script($this->client);
                // Initiated project to use within the sheet
                $project = new CreateProjectRequest();
                $project->setTitle($this->scriptData->title);
                $project->setParentId($spreadsheet_id);
                $projectResponse = $script->projects->create($project);
                // Check and get the script ID from the response of creating a project
                if($projectResponse){
                    $script_id = $projectResponse->getScriptId();
                    // Get the source code for the scripts to make the files for the script
                    // html
                    $htmlCode = $this->getCodeForScript('HTML', $sheet_for);
                    $htmlFile = new ScriptFile();
                    $htmlFile->setName($this->scriptData->files['html']['name']);
                    $htmlFile->setType($this->scriptData->files['html']['type']);
                    $htmlFile->setSource($htmlCode);
                    // Form
                    $formCode = $this->getCodeForScript('FORM', $sheet_for);
                    $formFile = new ScriptFile();
                    $formFile->setName($this->scriptData->files['form']['name']);
                    $formFile->setType($this->scriptData->files['form']['type']);
                    $formFile->setSource($formCode);
                    // Function
                    $funcCode = $this->getCodeForScript('FUNCTION', $sheet_for);
                    $funcFile = new ScriptFile();
                    $funcFile->setName($this->scriptData->files['function']['name']);
                    $funcFile->setType($this->scriptData->files['function']['type']);
                    $funcFile->setSource($funcCode);
                    // Manifest file must included
                    $manifestCode = <<<EOT
                    {
                    "timeZone": "Asia/Kolkata",
                    "exceptionLogging": "CLOUD"
                    }
                    EOT;
                    $manifestFile = new ScriptFile();
                    $manifestFile->setName('appsscript');
                    $manifestFile->setType('JSON');
                    $manifestFile->setSource($manifestCode);
                    // Add the files to the main script so that they are attached to the sheet for the good
                    $content = new Content();
                    $content->setScriptId($script_id);
                    $content->setFiles([$formFile, $htmlFile, $funcFile, $manifestFile]);
                    $update = $script->projects->updateContent($script_id, $content);
                    return $update;
                }
            }
        }
        return false;
    }

    public function addCallbackCustomerData(Request $request, $user_integration_id)
    {
        $error = '';
        $id = '';
        try{
            if($request->getContent()){
                parse_str($request->getContent(), $data);
                if (is_array($data)) {
                    foreach ($data as $key => $val) {
                        $$key = htmlspecialchars($val);
                    }
                    if($first_name && $last_name && $email && $phone && $company_name && $job_title && $addressLine1){
                        $checkCustomer = PlatformCustomer::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'email' => $email
                        ])->first();
                        if(!$checkCustomer){
                            $userIntegration = UserIntegration::find($user_integration_id);
                            if($userIntegration){
                                $countryIsoCode = DB::table('es_country_codes1')->select('iso3')->where('name', $country)->first();
                                $countryIsoCode = ($countryIsoCode) ? $countryIsoCode->iso3 : null;
                                $insertData = [
                                    'api_customer_id' => $sheet_row_id,
                                    'customer_name' => $first_name . ' ' . $last_name,
                                    'company_name' => $company_name,
                                    'first_name' => $first_name,
                                    'last_name' => $last_name,
                                    'phone' => $phone,
                                    'email' => $email,
                                    'address1' => $addressLine1,
                                    'address2' => $addressLine2,
                                    'address3' => $addressLine3,
                                    'postal_addresses' => $postalCode,
                                    'country' => $countryIsoCode,
                                    'api_created_at' => date('Y-m-d h:i:s'),
                                    'user_id' => $userIntegration->user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'sync_status' => 'Ready',
                                    'type' => 'Customer'
                                ];
                                $newCustomer = PlatformCustomer::create($insertData);
                                if($newCustomer){
                                    $objectdata = PlatformObjectData::where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'api_id' => 'CUSTOMER',
                                    ])->first();
                                    if($objectdata){
                                        $newrow = ['rows' => ($sheet_row_id + 1)];
                                        $this->saveSheetInfoInDatabase($objectdata->id, $newrow);
                                        $id = $newCustomer->id;
                                    }
                                }
                            }else{
                                $error = 'Integration not Existed';
                            }
                        }else{
                            $error = 'Customer Already Existed';
                        }
                    }else{
                        $error = "Fill the required fields.";
                    }
                } else {
                    $error = 'Try again! Data not inserted.';
                }
            }
        }catch(\Exception $e){
            $error = $e->getMessage();
        }
        return [
            'error' => $error,
            'id' => $id
        ];
    }

    public function addCallbackProductData(Request $request, $user_integration_id)
    {
        $error = '';
        $id = '';
        try{
            if($request->getContent()){
                parse_str($request->getContent(), $data);
                foreach ($data as $key => $val) {
                    $$key = htmlspecialchars($val);
                }
                if($product_name && $brand && $categories && $sku){
                    $checkProduct = PlatformProduct::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'sku' => $sku
                    ])->first();
                    if(!$checkProduct){
                        $userIntegration = UserIntegration::find($user_integration_id);
                        if($userIntegration){
                            if ( $brand && $categories ) {
                                $newproduct = new PlatformProduct();
                                $newproduct->user_id = $userIntegration->user_id;
                                $newproduct->user_integration_id = $user_integration_id;
                                $newproduct->platform_id = $this->platformId;
                                $newproduct->api_product_id = $sheet_row_id;
                                $newproduct->product_name = $product_name;
                                $newproduct->sku = $sku;
                                $newproduct->ean = $ean;
                                $newproduct->upc = $upc;
                                $newproduct->isbn = $isbn;
                                $newproduct->mpn = $mpn;
                                $newproduct->brand_id = $brand;
                                $newproduct->weight = $weight;
                                $newproduct->description = $description;
                                $newproduct->category_id =  $categories;
                                $newproduct->product_sync_status = 'Ready';
                                $newproduct->api_updated_at = date('Y-m-d h:i:s');
                                $newproduct->save();

                                if($newproduct->id){
                                    $attributes = PlatformProductDetailAttribute::create([
                                        'platform_product_id' => $newproduct->id,
                                        'shortdescription' => $short_description,
                                        'fulldescription' => $description,
                                        'lenght' => $length,
                                        'height' => $height,
                                        'width' => $width,
                                        'language_code' => 'EN'
                                    ]);
                                    $objectdata = PlatformObjectData::where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'api_id' => 'PRODUCT',
                                    ])->first();
                                    if($objectdata){
                                        $newrow = ['rows' => ($sheet_row_id + 1)];
                                        $this->saveSheetInfoInDatabase($objectdata->id, $newrow);
                                        $id = $newproduct->id;
                                    }
                                }else{
                                    $error = "Product is not added";
                                }
                            } else {
                                $error = 'Brand or Category not found or created. Product not added!';
                            }
                        }else{
                            $error = "Integration not existed.";
                        }
                    }else{
                        $error = "Product already existed.";
                    }
                }else{
                    $error = "Fill the required fields.";
                }
            }
        }catch(\Exception $e){
            $error = $e->getMessage();
        }
        return [
            'error' => $error,
            'id' => $id
        ];
    }

    private function getScriptCredencialInfo($sheet_for)
    {
        $data = [];
        if($sheet_for === 'CUSTOMER'){
            $data = [
                'title' => 'Customer Script - ' . rand(),
                'callback_url' => env('APP_URL').'/api/googlesheet/customer/create/'.$this->user_integration_id,
                'files' => [
                    'html' => [
                        'name' => 'customer_form',
                        'extenstion' => 'html',
                        'type' => 'HTML'
                    ],
                    'form' => [
                        'name' => 'customer_form_script',
                        'extenstion' => 'gs',
                        'type' => 'SERVER_JS'
                    ],
                    'function' => [
                        'name' => 'customer_form_function',
                        'extenstion' => 'gs',
                        'type' => 'SERVER_JS'
                    ],
                ],
            ];
        }elseif($sheet_for === 'PRODUCT'){
            $data = [
                'title' => 'Product Script - ' . rand(),
                'callback_url' => env('APP_URL').'/api/googlesheet/product/create/'.$this->user_integration_id,
                'files' => [
                    'html' => [
                        'name' => 'product_form',
                        'extenstion' => 'html',
                        'type' => 'HTML'
                    ],
                    'form' => [
                        'name' => 'product_form_script',
                        'extenstion' => 'gs',
                        'type' => 'SERVER_JS'
                    ],
                    'function' => [
                        'name' => 'product_form_function',
                        'extenstion' => 'gs',
                        'type' => 'SERVER_JS'
                    ],
                ],
            ];
        }
        return (Object) $data;
    }

    private function getCodeForScript($type, $sheet_for)
    {
        $code = '';
        if($sheet_for === 'CUSTOMER'){
            switch ($type) {
                case $type === 'HTML':
                    $code = $this->getSourceCodeForCustomerScriptHTML();
                    break;
                case $type === 'FORM':
                    $code = $this->getSourceCodeForCustomerScriptForm();
                    break;
                case $type === 'FUNCTION':
                    $code = $this->getSourceCodeForCustomerScriptFunction();
                    break;
                default:
                    break;
            }
        }elseif($sheet_for === 'PRODUCT'){
            switch ($type) {
                case $type === 'HTML':
                    $code = $this->getSourceCodeForProductScriptHTML();
                    break;
                case $type === 'FORM':
                    $code = $this->getSourceCodeForProductScriptForm();
                    break;
                case $type === 'FUNCTION':
                    $code = $this->getSourceCodeForProductScriptFunction();
                    break;
                default:
                    break;
            }
        }
        return $code;
    }

    private function getSourceCodeForCustomerScriptHTML()
    {
        $code = <<<EOT
        <!doctype html>
        <html lang="en">
        <head>
            <!-- Required meta tags -->
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <!-- Bootstrap CSS -->
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <title>New Customer</title>
        </head>
        <body>
            <div class="container">
            <form method="POST" id="customer_form" onsubmit="event.preventDefault(); afterSubmit(this);">
                <div class="mb-3">
                <label class="form-label">First Name <span class="req_fields">*</span></label>
                <input class="form-control" type="text" name="first_name" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Last Name <span class="req_fields">*</span></label>
                <input class="form-control" type="text" name="last_name" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Email <span class="req_fields">*</span></label>
                <input class="form-control" type="email" name="email" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Phone <span class="req_fields">*</span></label>
                <input class="form-control" type="text" name="phone" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Company Name <span class="req_fields">*</span></label>
                <input class="form-control" type="text" name="company_name" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Job Title <span class="req_fields">*</span></label>
                <input class="form-control" type="text" name="job_title" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Address Line 1 <span class="req_fields">*</span></label>
                <input class="form-control" type="text" name="addressLine1" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Address Line 2</label>
                <input class="form-control" type="text" name="addressLine2">
                </div>
                <div class="mb-3">
                <label class="form-label">Address Line 3</label>
                <input class="form-control" type="text" name="addressLine3">
                </div>
                <div class="mb-3">
                <label class="form-label">Postal Code</label>
                <input class="form-control" type="text" name="postalCode">
                </div>
                <div class="mb-3">
                <label class="form-label">Country</label>
                <input class="form-control" type="text" name="country">
                </div>
                <div class="mb-3">
                <input class="form-control" type="submit" value="Add Customer">
                </div>
            </form>
            <div class="mb-3">
                <div id="message">
                </div>
            </div>
            </div>

            <!-- Option 1: Bootstrap Bundle with Popper -->
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
            <script>

            // function to check for error
            function afterSubmit(e){
            if(e.first_name.value && e.last_name.value && e.email.value && e.phone.value && e.company_name.value && e.job_title.value && e.addressLine1.value) {
                var rowData = {};
                rowData['first_name'] = e.first_name.value;
                rowData['last_name'] = e.last_name.value;
                rowData['email'] = e.email.value;
                rowData['phone'] = e.phone.value;
                rowData['company_name'] = e.company_name.value;
                rowData['job_title'] = e.job_title.value;
                rowData['addressLine1'] = e.addressLine1.value;
                rowData['addressLine2'] = e.addressLine2.value;
                rowData['addressLine3'] = e.addressLine3.value;
                rowData['postalCode'] = e.postalCode.value;
                rowData['country'] = e.country.value;
                if(Object.keys(rowData).length > 0){
                google.script.run.withSuccessHandler(formSubmission).addNewRow(Object.keys(rowData), Object.values(rowData), rowData);
                e.reset();
                }else{
                alert('Something went wrong! Please try again.');
                }
            } else {
                alert('Required fields should not be empty');
            }
            return true;
            }

            function formSubmission(res){
            if(res.id){
                alert('Product added successfully.');
            }else{
                alert('Error - ' + res.error);
            }
            return true;
            }
            </script>
            </body>
        </html>
        EOT;
        return $code;
    }

    private function getSourceCodeForCustomerScriptForm()
    {
        $name = $this->scriptData->files['html']['name'];
        $code = <<<EOT
        // load customer form
        function loadCustomerForm() {
          const htmlForm = HtmlService.createTemplateFromFile("$name");
          const htmlOutput = htmlForm.evaluate();

          const ui = SpreadsheetApp.getUi();
          ui.showModalDialog(htmlOutput, "Add Customer");
        }
        // insert customer insert menu
        function createCustomerMenu(){
          const ui = SpreadsheetApp.getUi();
          const csmenu = ui.createMenu("Form");
          csmenu.addItem("Create Customer", "loadCustomerForm");
          csmenu.addToUi();
        }
        // on load of spreadsheet make the customs available
        function onOpen(){
          createCustomerMenu();
        }
        EOT;
        return $code;
    }

    private function getSourceCodeForCustomerScriptFunction()
    {
        $callback_url = $this->scriptData->callback_url;
        $code = <<<EOT
        function addNewRow(rows, rowData, arrData){
          if(rowData.length > 0){
            const ss = SpreadsheetApp.getActiveSpreadsheet();
            const ws = ss.getSheetByName('Sheet1');
            var data = {};
            data['sheet_row_id'] = ws.getLastRow();
            rows.forEach(function(key, i) { data[key] = rowData[i]; } );
            var options = {
              'method' : 'post',
              'Content-Type': 'application/json',
              'payload' : data
            };
            var response = UrlFetchApp.fetch("$callback_url", options);
            if (response.getResponseCode() == 200) {
              response = JSON.parse(response);
              if(response.error == ''){
                var rowId = response.id;
                var date = new Date();
                var day = date.getDate();
                var month = date.getMonth()+1;
                var year = date.getFullYear();
                ws.appendRow(["=ROW() - 1", rowId, day+"-"+month+"-"+year, arrData['company_name'], arrData['first_name'] + ' ' + arrData['last_name'], arrData['email'], arrData['phone']]);
                return {'id': ws.getLastRow()};
              }else{
                return { 'error': response.error };
              }
            }
          }else{
            return false;
          }
        }
        function checkForDuplicateEmail(email){
          const sheet  = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
          const column = 3;
          const row = sheet.getLastRow();
          const colRange = sheet.getRange(1, column, row);
          var rangeArray = colRange.getValues();
          rangeArray = [].concat.apply([], rangeArray);
          for(var x = 1; x < rangeArray.length; x++){
            if(rangeArray[x] == email){
              return x + 1;
            }
          }
          return false;
        }
        function getCustomerInputColums(){
          const ss = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet().getRange("A1:1").getValues();
          var fltSs = ss[0].filter(String);
          fltSs.shift();
          var i = fltSs.indexOf('Customer ID');
          if(i >= 0) {
             fltSs.splice(i,1);
          }
          i = fltSs.indexOf('Date Created');
          if(i >= 0) {
             fltSs.splice(i,1);
          }
          return fltSs;
        }
        EOT;
        return $code;
    }

    private function getSourceCodeForProductScriptHTML()
    {
        // $brandHtml = "<select name='brand' id='brand' class='form-control' required>";
        // $brandObject = PlatformObject::where('name', 'brand')->select('id')->first();
        // if ($brandObject) {
        //     $brands = PlatformObjectData::where([
        //         'user_integration_id' => $this->user_integration_id,
        //         'platform_object_id' => $brandObject->id
        //     ])->get();
        //     if ($brands) {
        //         foreach ($brands as $brand) {
        //             $brandHtml .= '<option value="' . $brand->api_id . '">'.$brand->name.'</option>';
        //         }
        //     }
        // }
        // $brandHtml .= '</select>';

        // $categoryHtml = "<select name='categories' id='categories' class='form-control' multiple required>";
        // $categoryObject = PlatformObject::where('name', 'category')->select('id')->first();
        // if ($categoryObject) {
        //     $categories = PlatformObjectData::where([
        //         'user_integration_id' => $this->user_integration_id,
        //         'platform_object_id' => $categoryObject->id
        //     ])->get();
        //     if ($categories) {
        //         foreach ($categories as $category) {
        //             $categoryHtml .= '<option value="' . $category->api_id . '">'.$category->name.'</option>';
        //         }
        //     }
        // }
        // $categoryHtml .= '</select>';

        // for( var option of e.categories.options){
        //     if(option.selected){
        //     rowData['categories'][] = option.value;
        //     }
        // }

        $code = <<<EOT
        <!doctype html>
        <html lang="en">
        <head>
            <!-- Required meta tags -->
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <!-- Bootstrap CSS -->
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <title>New Product</title>
        </head>
        <body>
            <div class="container">
            <form method="POST" id="product_form" onsubmit="event.preventDefault(); afterSubmit(this);">
                <div class="mb-3">
                <label for="product_name">Product Name <span class="req_field">*</span></label>
                <input class="form-control" type="text" id="product_name" name="product_name" required />
                </div>
                <div class="mb-3">
                <label for="sku">SKU <span class="req_field">*</span></label>
                <input class="form-control" type="text" id="sku" name="sku" required />
                </div>
                <div class="mb-3">
                <label for="ean">EAN</label>
                <input class="form-control" type="text" id="ean" name="ean" />
                </div>
                <div class="mb-3">
                <label for="upc">UPC</label>
                <input class="form-control" type="text" id="upc" name="upc" />
                </div>
                <div class="mb-3">
                <label for="isbn">ISBN</label>
                <input class="form-control" type="text" id="isbn" name="isbn" />
                </div>
                <div class="mb-3">
                <label for="mpn">MPN</label>
                <input class="form-control" type="text" id="mpn" name="mpn" />
                </div>
                <div class="mb-3">
                <label for="weight">Weight</label>
                <input class="form-control" type="text" id="weight" name="weight" />
                </div>
                <div class="mb-3">
                <label for="height">Height</label>
                <input class="form-control" type="text" id="height" name="height" />
                </div>
                <div class="mb-3">
                <label for="length">Length</label>
                <input class="form-control" type="text" id="length" name="length" />
                </div>
                <div class="mb-3">
                <label for="width">Width</label>
                <input class="form-control" type="text" id="width" name="width" />
                </div>
                <div class="mb-3">
                <label for="description">Description</label>
                <textarea class="form-control" id="description" name="description"></textarea>
                </div>
                <div class="mb-3">
                <label for="short_description">Short Description</label>
                <textarea class="form-control" id="short_description" name="short_description"></textarea>
                </div>
                <div class="mb-3">
                <label for="brand">Brand <span class="req_field">*</span></label>
                <input type="text" class="form-control" name="brand" id="brand">
                </div>
                <div class="mb-3">
                <label for="categories">Categories <span class="req_field">*</span></label>
                <input type="text" class="form-control" name="categories" id="categories">
                </div>
                <div class="mb-3">
                <input class="form-control" type="submit" value="Add Product" />
                </div>
            </form>
            <div class="mb-3">
                <div id="message">
                </div>
            </div>
            </div>
            <!-- Option 1: Bootstrap Bundle with Popper -->
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
            <script>
            // function to check for error
            function afterSubmit(e){
            var rowData = {};
            rowData['product_name'] = e.product_name.value;
            rowData['brand'] = e.brand.value;
            rowData['categories'] = e.categories.value;
            rowData['sku'] = e.sku.value;
            rowData['ean'] = e.ean.value;
            rowData['upc'] = e.upc.value;
            rowData['isbn'] = e.isbn.value;
            rowData['mpn'] = e.mpn.value;
            rowData['weight'] = e.weight.value;
            rowData['height'] = e.height.value;
            rowData['width'] = e.width.value;
            rowData['length'] = e.length.value;
            rowData['short_description'] = e.short_description.value;
            rowData['description'] = e.description.value;
            if(Object.keys(rowData).length > 0){
                google.script.run.withSuccessHandler(formSubmission).addNewRow(Object.keys(rowData), Object.values(rowData), rowData);
                e.reset();
            }else{
                alert('Something went wrong! Please try again.');
            }
            return true;
            }

            function formSubmission(res){
            if(res.id){
                alert('Product added successfully.');
            }else{
                alert('Error - ' + res.error);
            }
            return true;
            }
            </script>
            </body>
        </html>
        EOT;
        return $code;
    }

    private function getSourceCodeForProductScriptForm()
    {
        $name = $this->scriptData->files['html']['name'];
        $code = <<<EOT
        // load customer form
        function loadProductForm() {
          const htmlForm = HtmlService.createTemplateFromFile("$name");
          const htmlOutput = htmlForm.evaluate();

          const ui = SpreadsheetApp.getUi();
          ui.showModalDialog(htmlOutput, "Add Product");
        }
        // insert customer insert menu
        function createProductMenu(){
          const ui = SpreadsheetApp.getUi();
          const csmenu = ui.createMenu("Form");
          csmenu.addItem("Create Product", "loadProductForm");
          csmenu.addToUi();
        }
        // on load of spreadsheet make the customs available
        function onOpen(){
          createProductMenu();
        }
        EOT;
        return $code;
    }

    private function getSourceCodeForProductScriptFunction()
    {
        $callback_url = $this->scriptData->callback_url;
        $code = <<<EOT
        function addNewRow(rows, rowData, arrData){
          if(rowData.length > 0){
            const ss = SpreadsheetApp.getActiveSpreadsheet();
            const ws = ss.getSheetByName('Sheet1');
            var data = {};
            data['sheet_row_id'] = ws.getLastRow();
            rows.forEach(function(key, i) { data[key] = rowData[i]; } );
            var options = {
              'method' : 'post',
              'Content-Type': 'application/json',
              'payload' : data
            };
            var response = UrlFetchApp.fetch("$callback_url", options);
            if (response.getResponseCode() == 200) {
                response = JSON.parse(response);
                if(response.error == ''){
                    var rowId = response.id;
                    ws.appendRow(["=ROW() - 1", rowId, arrData['sku'], arrData['product_name'], '', '', arrData['brand'], arrData['categories'], 'LIVE', '', '', '', '', '', '', '', '', '', '', '', '', arrData['description'], arrData['weight'], arrData['height'], arrData['width'], arrData['length'], arrData['short_description']]);
                    return {'id': ws.getLastRow()};
                }else{
                    return { 'error': response.error };
                }
            }
          }else{
            return false;
          }
        }
        function checkForDuplicateEmail(email){
          const sheet  = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
          const column = 3;
          const row = sheet.getLastRow();
          const colRange = sheet.getRange(1, column, row);
          var rangeArray = colRange.getValues();
          rangeArray = [].concat.apply([], rangeArray);
          for(var x = 1; x < rangeArray.length; x++){
            if(rangeArray[x] == email){
              return x + 1;
            }
          }
          return false;
        }
        function getProductInputColums(){
          const ss = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet().getRange("A1:1").getValues();
          var fltSs = ss[0].filter(String);
          fltSs.shift();
          var i = fltSs.indexOf('Product ID');
          if(i >= 0) {
             fltSs.splice(i,1);
          }
          return fltSs;
        }
        EOT;
        return $code;
    }

    public function getDimensions($spreadsheetId, $sheetTitle)
    {
        $rowDimensions = $this->service->spreadsheets_values->batchGet(
            $spreadsheetId,
            ['ranges' => $sheetTitle.'!A:A','majorDimension'=>'COLUMNS']
        );

        $rowMeta = $rowDimensions->getValueRanges()[0]->values;
        if (!$rowMeta) {
            return [
                'error' => true,
                'message' => 'missing row data'
            ];
        }

        return [
            'error' => false,
            'rowCount' => count($rowMeta[0])
        ];
    }

    public function lastRowIdExistingData($sheetFor)
    {
        $objectGoogle = PlatformObject::where('name', '=', 'sheet')->first();
        $spreadsheet = PlatformObjectData::where([
            'platform_object_id' => $objectGoogle->id,
            'platform_id' => $this->platformId,
            'user_integration_id' => $this->user_integration_id,
            'api_id' => $sheetFor
        ])->first();
        $sheetData = $this->getSheetInfoBySpreadsheetId($spreadsheet->api_code);
        $rowDimensions = $this->service->spreadsheets_values->batchGet(
            $spreadsheet->api_code,
            ['ranges' => $sheetData['sheet_title'].'!A:A','majorDimension'=>'COLUMNS']
        );
        if(isset($rowDimensions['valueRanges'][0]['values'][0])){
            // $result = $this->service->spreadsheets_values->get($spreadsheet->api_code, $sheetData['sheet_title'].'!B'.count($rowDimensions['valueRanges'][0]['values'][0]).':B'.count($rowDimensions['valueRanges'][0]['values'][0]));
            // return $result->values[0][0]; // GET UNIQUE ID
            return ($rowDimensions['valueRanges'][0]['values'][0]) + 1; // GET THE ROW AFTER LAST INSERTED ROW
        }
        return 0;
    }

    public function getSpecificDimensions($id, $spreadsheetId, $sheetId, $sheetTitle, $searchFor = 'B')
    {
        $sql = urlencode("select * WHERE {$searchFor}={$id}");
        $url = Config::get('apiconfig.GoogleDocQueryUrl').$spreadsheetId.'/gviz/tq?gid='.$sheetId.'&tqx=out:json&tq='.$sql.'&access_token='.$this->access_token['access_token'];
        $res = $this->parseGV(file_get_contents($url));
        if (!isset($res[0])) {
            return [
                'error' => true,
                'message' => 'No Data found.'
            ];
        }
        $theRow = $res[0]['#'] + 1;
        $colDimensions = $this->service->spreadsheets_values->batchGet(
            $spreadsheetId,
            ['ranges' => $sheetTitle."!A{$theRow}:1",'majorDimension'=>'ROWS']
        );
        $colMeta = $colDimensions->getValueRanges()[0]->values;
        return [
            'error' => false,
            'row' => $theRow,
            'col' => $this->colLengthToColumnAddress(count($colMeta[0]))
        ];
    }

    public  function colLengthToColumnAddress($number)
    {
        if ($number <= 0) return null;

        $letter = '';
        while ($number > 0) {
            $temp = ($number - 1) % 26;
            $letter = chr($temp + 65) . $letter;
            $number = ($number - $temp - 1) / 26;
        }
        return $letter;
    }

    public function parseGV($gv)
    {
        $response = substr($gv, strpos($gv, "\n") + 1);
        $data = preg_replace('/google.visualization.Query.setResponse\(/', '', $response);
        $data = preg_replace('/\)\;/', '', $data);
        $data = json_decode($data);
        $data_keys = $data->table->cols;
        $data_rows = $data->table->rows;
        $arr = array();
        foreach ($data_rows as $key => $row) {
            $a = array();
            foreach ($row->c as $k => $value) {
                if(isset($value->v)) {
                    $a[$data_keys[$k]->label] = $value->v;
                }
                else {
                    $a[$data_keys[$k]->label] = null;
                }
            }
            $arr[] = $a;
        }
        return ($arr);
    }

    public function getSheetInfoBySpreadsheetId($spreadsheetId)
    {
        $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
        // Only to get the first sheet
        $sheetInfo = [];
        $sheetInfo['sheet_id'] = $spreadsheet[0]['properties']['sheetId'];
        $sheetInfo['sheet_title'] = $spreadsheet[0]['properties']['title'];
        // ALSO SAVE THE SHEETID, SPREADSHEETID AND SHEETTITLE AS GLOBAL
        $this->spreadsheetId = $spreadsheetId;
        $this->sheetId = $sheetInfo['sheet_id'];
        $this->sheetTitle = $sheetInfo['sheet_title'];
        return $sheetInfo;
    }

    public function saveSheetInfoInDatabase($id, $data)
    {
        $spreadsheet = PlatformObjectData::find($id);
        if($spreadsheet->description == null){
            $datas = json_encode($data);
        }else{
            $datas = json_decode($spreadsheet->description, true);
            foreach($data as $k => $v){
                $datas[$k] = $v;
            }
            $datas = json_encode($datas);
        }
        $spreadsheet->description = $datas;
        $spreadsheet->save();
        return true;
    }

    public function getTheNewRow($str)
    {
        $str = explode('!', $str);
        $str = explode(':', $str[1]);
        $str = preg_replace( '/[^0-9]/i', '', $str[0]);
        return (['rows' => $str]);
    }

    public function getWholeRow($spreadsheetId)
    {
        $data = [];
        try{
            // GET WHOLE ROW VALUES
            $result = $this->service->spreadsheets_values->get($spreadsheetId, 'Sheet1!A1:1');
            if($result->getValues()){
                $arrCount = count($result->getValues());
                if($arrCount == 1){
                    $cols = $result->getValues()[0];
                    $count = count($cols);
                    // GET THE SPECIFI COLUMN NAME
                    if($count){
                        $data['status'] = true;
                        $data['data'] = $cols;
                        $this->firstRowArr = $cols;
                        $this->firstRange = $result->getRange();
                        $data['range'] = $result->getRange();
                    }
                }
            }
            if(!isset($data['data'])){
                $data['status'] = false;
                $data['data'] = null;
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    public function addCustomFieldToRow($sheet_for, $arr, $mainId)
    {
        $data = [];
        try{
            $spreadsheet = PlatformObjectData::where([
                'platform_object_id' => $this->object_id,
                'user_integration_id' => $this->user_integration_id,
                'platform_id' => $this->platformId,
                'api_id' => $sheet_for
            ])->first();
            if(!$spreadsheet){
                return ['status' => false];
            }
            $spreadsheetId = $spreadsheet->api_code;
            // GET A ARRAY FOR THE CUSTOM FIELDS VALUES
            if($sheet_for == 'CUSTOMER'){
                $type = 'customer';
            }elseif($sheet_for == 'PRODUCT'){
                $type = 'product';
            }elseif($sheet_for == 'SALESORDER'){
                $type = 'sales_order';
            }
            $customFields = $this->getCustomFieldsWithValuesForSheet($type, $mainId);
            if(!empty($this->firstRowArr) && is_array($this->firstRowArr)){
                $res = ['status' => true, 'data' => $this->firstRowArr, 'range' => $this->firstRange];
            }else{
                $res = $this->getWholeRow($spreadsheetId);
            }
            if($res['status']){
                if(isset($customFields['status']) && $customFields['status']==true){
                    if(count($customFields['data']) > 0){
                        foreach($customFields['data'] as $customField => $customFieldValue){
                            $idOfCol = $this->search_array($customField,$res['data']);
                            if($idOfCol && $customFieldValue != null){
                                    $arr[$idOfCol] = $customFieldValue;
                            }
                        }
                    }
                }
            }
            if(empty($this->firstColCount)){
                $colDimensions = $this->service->spreadsheets_values->batchGet(
                    $spreadsheetId,
                    ['ranges' => "Sheet1!A1:1",'majorDimension'=>'ROWS']
                );
                if(isset($colDimensions->getValueRanges()[0]->values[0])){
                    $this->firstColCount = count($colDimensions->getValueRanges()[0]->values[0]);
                }
            }
            if(!empty($this->firstColCount)){
                $colMetaCount = $this->firstColCount;
                $values = [];
                for($x=0; $x<$colMetaCount; $x++){
                    if(!array_key_exists($x, $arr)){
                        $values[$x] = '';
                    }else{
                        $values[$x] = $arr[$x];
                    }
                }
                $data['status'] = true;
                $data['data'] = $values;
            }else{
                $data['status'] = true;
                $data['data'] = $arr;
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    public function getCustomFieldsWithValuesForSheet($type, $withValues=false)
    {
        $data = [];
        try{
            $object = PlatformObject::where(['name' => $type, 'status' => 1])->first();
            $fields = PlatformField::where([
                'user_integration_id' => $this->user_integration_id,
                'platform_id' => $this->source_platform_id,
                'status' => 1,
                'platform_object_id' => $object->id,
            ])->select('id','description')->get();
            $values = [];
            if($fields->count()){
                foreach($fields as $field){
                    if(!$withValues){
                        $values[] = $field->description;
                    }else{
                        // GET FIELD VALUES
                        $fieldvalue = PlatformCustomFieldValue::where([
                            'platform_field_id' => $field->id,
                            'user_integration_id' => $this->user_integration_id,
                            'platform_id' => $this->source_platform_id,
                            'record_id' => $withValues,
                            'status' => 1
                        ])->select('field_value')->orderBy('id', 'desc')->first();
                        if($fieldvalue){
                            $values[$field->description] = $fieldvalue->field_value;
                        }else{
                            $values[$field->description] = '';
                        }
                    }
                }
            }
            $data['status'] = true;
            $data['data'] = $values;
            if(!isset($data['data']) && !is_array($data['data']) && count($data['data']) == 0){
                $data['status'] = false;
                $data['message'] = 'Not found custom fields';
            }
        }catch(\Exception $e){
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        }
        return $data;
    }

    public function getSheetInfoBySheetForParameter($sheetFor)
    {
        $objectGoogle = PlatformObject::where('name', '=', 'sheet')->first();
        $spreadsheet = PlatformObjectData::where([
            'platform_object_id' => $objectGoogle->id,
            'user_integration_id' => $this->user_integration_id,
            'platform_id' => $this->platformId,
            'api_id' => $sheetFor
        ])->first();
        $data = [
            'spreadsheet' => $spreadsheet->api_code
        ];
        return $data;
    }

    public function search_array($needle, $haystack)
    {
        $index = false;
        foreach($haystack as $k => $v){
            if($v === $needle){
                $index = $k;
            }
        }
        return $index;
    }
    // ----- EXTRA FUNCTIONS :: END

    public function ExecuteGoogleSpreadsheet($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        $response = true;
        try {
            $this->userId = $user_id;
            $this->source_platform_name = $source_platform_id;
            $this->user_integration_id = $user_integration_id;
            $serviceStarted = $this->startSheetService($user_integration_id);
            if(isset($serviceStarted['status']) && $serviceStarted['status']==true){
                if($method == 'MUTATE' && $event == 'CUSTOMER'){
                    $result = $this->syncSheetForCustomer($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
                }elseif($method == 'MUTATE' && $event == 'PRODUCT'){
                    $result = $this->syncSheetForProduct($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
                }elseif($method == 'MUTATE' && $event == 'SALESORDER'){
                    $result = $this->syncSheetForSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
                }elseif($method == 'GET' && $event == 'CREATESHEETSALESORDER'){
                    if($is_initial_sync){
                        $result = $this->createSpreadSheetForParticularModule('SALESORDER', $user_integration_id);
                        $result = $result['status'];
                    }
                    $result = true;
                }elseif($method == 'GET' && $event == 'CREATESHEETPRODUCT'){
                    if($is_initial_sync){
                        $result = $this->createSpreadSheetForParticularModule('PRODUCT', $user_integration_id);
                        $result = $result['status'];
                    }
                    $result = true;
                }elseif($method == 'GET' && $event == 'CREATESHEETCUSTOMER'){
                    if($is_initial_sync){
                        $result = $this->createSpreadSheetForParticularModule('CUSTOMER', $user_integration_id);
                        $result = $result['status'];
                    }
                    $result = true;
                }elseif($method == 'GET' && $event == 'CUSTOMER'){
                    $result = true;
                }elseif($method == 'GET' && $event == 'PRODUCT'){
                    $result = true;
                }
                $response = $result;
            }else{
                $response = $serviceStarted['message'];
            }
        }catch(\Exception $e){
            $response = $e->getMessage();
        }
        return $response;
    }
}