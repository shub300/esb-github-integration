<?php

namespace App\Http\Controllers\Netsuite;

use App\Helper\Api\NetsuiteRestServices;
use App\Http\Controllers\Controller;
use App\Models\Enum\CustomFieldType;
use App\Models\PlatformProductDetailAttribute;
use App\Models\Enum\PlatformObjectName;
use App\Models\Enum\PlatformRecordType;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformCustomer;
use App\Models\PlatformDataMapping;
use App\Models\PlatformField;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProduct;
use App\Models\PlatformInvoice;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordType;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Logger;
use App\Helper\Api\NetsuiteApi;
use App\Helper\Api\BrightpearlApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformUrl;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use NetSuite\Classes\TransactionBodyCustomField;
use Log;
use Auth;
use Carbon\Carbon;
use Lang;

class NetsuiteApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $myPlatform = 'netsuite';

    protected $netsuiteSyncServices;
    public $mobj, $netsuiteApi, $brightpearlApi, $helper, $log, $platformId, $mapping;

    public function __construct()
    {
        $this->netsuiteSyncServices = new NetsuiteServices();
        $this->mobj = new MainModel();
        $this->netsuiteApi = new NetsuiteApi();
        $this->brightpearlApi = new BrightpearlApi();
        $this->log = new Logger();
        $this->mapping = new FieldMappingHelper();
        $this->helper = new ConnectionHelper();
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    public function InitiateNSAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.auth_netsuite", compact('platform'));
    }

    public function connectNetsuiteAuth(Request $request)
    {
        //server validation
        $request->validate([
            'account_name' => 'required',
            'ns_endpoint' => 'required',
            'ns_host' => 'required',
            'consumer_key' => 'required',
            'consumer_secret' => 'required',
            'ns_token' => 'required',
            'ns_token_secret' => 'required',
        ]);

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $data = [];

        if ($this->mobj->checkHtmlTags($request->all())) {
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }

        try {
            $flag = true;

            $service =  $this->netsuiteApi->GetNetsuiteService(0, 0, $request->account_name, $request->consumer_key, $request->consumer_secret, $request->ns_token, $request->ns_token_secret);

            $webservicesDomain = $this->netsuiteApi->GetNetsuiteHostByAccount($service, $request->account_name);

            if (!empty($webservicesDomain)) {
                $validate = $this->netsuiteApi->GetNetsuiteCustomerById($service, 101);

                if ($validate === false) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'Netsuite credentials are incorrect';
                } else {
                    $account_name = $request->account_name;
                    $consumer_key = $this->mobj->encrypt_decrypt($request->consumer_key);
                    $consumer_secret = $this->mobj->encrypt_decrypt($request->consumer_secret);
                    $ns_token = $this->mobj->encrypt_decrypt($request->ns_token);
                    $ns_token_secret = $this->mobj->encrypt_decrypt($request->ns_token_secret);

                    $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['api_domain' => $webservicesDomain, 'account_name' => $account_name, 'platform_id' => $this->platformId], ['user_id']);
                    if ($obj_existing) {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Given details are already in use, Try with other details.';
                        return json_encode($data);
                    }

                    $tokens = array(
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'account_name' => $account_name,
                        'api_domain' => $webservicesDomain,
                        'app_id' => $consumer_key,
                        'app_secret' => $consumer_secret,
                        'refresh_token' => $ns_token,
                        'access_token' => $ns_token_secret,
                        'allow_refresh' => 0
                    );

                    DB::table('platform_accounts')->insert($tokens);
                }
            } else {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = 'Netsuite credentials are incorrect';
            }

            if ($flag) {
                $data['status_code'] = 1;
                $data['status_text'] = 'Account connected successfully.';
            }

            return json_encode($data);
        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();

            return json_encode($data);
        }
    }

    public function NetsuiteGetLocations($user_id, $user_integration_id, $source_platform_name, $destination_platform_name)
    {
        $return_response = true;
        try {


            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {
                $list = $this->netsuiteApi->GetNetsuiteLocations($service);

                if ($list === false || is_string($list)) {
                    $return_response = is_bool($list) ? "No record found" : $list;
                } else if (is_array($list)) {
                    $order_warehouse_object_id = $this->helper->getObjectId('location');

                    if ($order_warehouse_object_id) {
                        // update users integration warehouse status to 0.
                        PlatformObjectData::where(['user_integration_id' => $user_integration_id,  'platform_id' => $this->platformId, 'platform_object_id' => $order_warehouse_object_id])->update(['status' => 0]);
                        foreach ($list['recordList'] as $record) {
                            $status = $record->isInactive == true ? false : true;
                            $insertList = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'name' => $record->name, 'api_id' => $record->internalId, 'status' => $status, 'platform_object_id' => $order_warehouse_object_id];
                            PlatformObjectData::updateOrCreate([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'platform_object_id' => $order_warehouse_object_id,
                                'api_id' => $record->internalId,
                            ], $insertList);
                        }
                        app('App\Http\Controllers\Snowflake\SnowflakeApiController')->getWarehouse($user_id, $user_integration_id, $this->platformId, $source_platform_name);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function NetsuiteGetCustomFieldsList($userId, $uIntegrationId, $objectName = 'purchase_order')
    {
        try {
            $objectId = $this->helper->getObjectId($objectName);
            $service =  $this->netsuiteApi->GetNetsuiteService($uIntegrationId, $this->platformId);
            if ($service) {
                $lists = $this->netsuiteApi->GetNetsuiteCustomFields($service, $objectName);

                $list = new TransactionBodyCustomField();
                if ($lists) {
                    foreach ($lists as $list) {
                        if ($list->status->isSuccess) {
                            if (($objectName == 'purchase_order' && $list->record->bodyPurchase) || ($objectName == 'sales_order' && $list->record->bodySale)
                                || ($objectName == 'product' && $list->record->appliesToInventory)
                            ) {
                                $platformField = PlatformField::where('user_integration_id', $uIntegrationId)
                                    ->where('platform_id', $this->platformId)->where('platform_object_id', $objectId)->where('custom_field_id', $list->record->internalId)
                                    ->where('field_type', 'custom')->first();

                                if (!$platformField) {
                                    $platformField = new PlatformField();
                                    $platformField->user_id = $userId;
                                    $platformField->platform_id = $this->platformId;
                                    $platformField->user_integration_id = $uIntegrationId;
                                    $platformField->custom_field_id = $list->record->internalId;
                                    $platformField->field_type = 'custom';
                                    $platformField->status = 1;
                                    $platformField->required = 'No';
                                    $platformField->platform_object_id = $objectId;
                                    $platformField->type = $objectName;
                                }
                                $platformField->custom_field_option_group_id = (isset($list->record->selectRecordType) && isset($list->record->selectRecordType->internalId)) ?  $list->record->selectRecordType->internalId : null;
                                $platformField->name = $list->record->label;
                                $platformField->custom_field_type = $this->netsuiteSyncServices->getCustomFieldTypeForNetsuite($list->record->fieldType);
                                $platformField->description = $list->record->scriptId;
                                $platformField->save();
                            }
                        }
                    }
                }
            }
            return true;
        } catch (\Exception $ex) {
            \Log::error($ex->getMessage());
            return $ex->getMessage();
        }
    }

    public function NetsuiteGetForms($user_id, $user_integration_id)
    {
        $return_response = false;
        try {

            $object_id = $this->helper->getObjectId('transaction_forms');

            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {

                $list = $this->netsuiteApi->GetNetsuiteCustomForms($service);

                if (empty($list['getSelectValueResult']->baseRefList->baseRef)) {
                    $return_response = "API Error";
                } else {

                    if (isset($object_id)) {

                        $list = $list['getSelectValueResult']->baseRefList->baseRef;

                        // update users integration forms status to 0.
                        $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id,  'platform_id' => $this->platformId, 'platform_object_id' => $object_id]);

                        foreach ($list as $record) {
                            $insertList = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'name' => $record->name, 'api_id' => $record->internalId, 'status' => 1, 'platform_object_id' => $object_id];
                            $findRecord = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'platform_object_id' => $object_id,
                                'api_id' => $record->internalId,
                            ], ['id']);
                            if ($findRecord) {
                                $this->mobj->makeUpdate(
                                    'platform_object_data',
                                    $insertList,
                                    ['id' => $findRecord->id]
                                );
                            } else {
                                $this->mobj->makeInsert('platform_object_data', $insertList);
                            }
                        }
                        $return_response = true;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function GetCustomFieldOptionData($userId, $userIntegrationId)
    {
        $service = $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);
        $customFields = PlatformField::where([
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $this->platformId, 'custom_field_type' => CustomFieldType::SELECT
        ])->get();
        return $this->netsuiteSyncServices->getSelectCustomFieldValueForCustomFields($service, $customFields);
    }

    public function NetsuiteGetSubsidiary($user_id, $user_integration_id)
    {
        $return_response = true;
        try {
            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {

                $list = $this->netsuiteApi->GetNetsuiteSubsidiaries($service);

                if ($list === false || is_string($list)) {
                    $return_response = is_bool($list) ? "No record found" : $list;
                } else if (is_array($list)) {
                    $object_id = $this->helper->getObjectId('subsidiary');
                    if ($object_id) {
                        // update users integration warehouse status to 0.
                        PlatformObjectData::where(['user_integration_id' => $user_integration_id,  'platform_id' => $this->platformId, 'platform_object_id' => $object_id])->update(['status' => 0]);
                        foreach ($list['recordList'] as $record) {
                            $status = $record->isInactive == true ? false : true;
                            $insertList = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'name' => $record->name, 'api_id' => $record->internalId, 'status' => $status, 'platform_object_id' => $object_id];

                            PlatformObjectData::updateOrCreate([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'platform_object_id' => $object_id,
                                'api_id' => $record->internalId,
                            ], $insertList);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    //type = 'vendor' or 'customer'
    public function getCustomerMapping($service, $source_customer, $platform_order_id, $user_workflow_rule_id, $platform_workflow_rule_id, $destination_platform_id, $object_id, $type = 'vendor', $source_platform_name = null)
    {
        $firstInternalId = $firstPlatformCustomerId = 0;
        $error_msg = '';
        $return = false;
        $returnValue = ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
        if ($source_platform_name == "snowflake") {
            if (empty($source_customer->customer_name)) {
                $returnValue = ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
                $return = true;
            }

            $conditions = [
                'platform_id' => $destination_platform_id, 'customer_name' => $source_customer->customer_name,
                'user_integration_id' => $source_customer->user_integration_id, 'type' => $type == 'vendor' ? PlatformRecordType::VENDOR : PlatformRecordType::CUSTOMER
            ];
        } else {
            if (empty($source_customer->email)) {
                $returnValue = ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
                $return = true;
            }

            $conditions = [
                'platform_id' => $destination_platform_id, 'email' => $source_customer->email,
                'user_integration_id' => $source_customer->user_integration_id, 'type' => $type == 'vendor' ? PlatformRecordType::VENDOR : PlatformRecordType::CUSTOMER
            ];
        }
        if ($return) {
            return $returnValue;
        }


        $findCustomer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            $conditions,
            ['api_customer_id', 'id']
        );

        if (!isset($findCustomer->id)) {
            $mapping_result = ($type == 'vendor') ? $this->netsuiteApi->SearchNetsuiteVendor($service, 'email', $source_customer->email) : $this->netsuiteApi->SearchNetsuiteCustomer($service, 'email', $source_customer->email);

            if ($mapping_result !== false && !empty($mapping_result)) {


                foreach ($mapping_result->record as $netsuite_customer) {
                    if (empty($firstInternalId) && !empty($netsuite_customer->internalId)) {
                        $firstInternalId = $netsuite_customer->internalId;
                    }
                    $address = @$netsuite_customer->addressbookList->addressbook;
                    if ($address) {
                        $address = (($address && is_array($address)) ?  $address[0]->addressbookAddress : $address->addressbookAddress);
                        $address = (array) $address;
                    } else {
                        $address = [];
                    }


                    $fields = array(
                        'user_id' => $source_customer->user_id,
                        'user_integration_id' => $source_customer->user_integration_id,
                        'platform_id' => $destination_platform_id,
                        'api_customer_id' => $netsuite_customer->internalId,
                        'first_name' => @$netsuite_customer->firstName,
                        'last_name' => @$netsuite_customer->lastName,
                        'company_name' => @$netsuite_customer->companyName,
                        'phone' =>  @$netsuite_customer->phone,
                        'email' => @$netsuite_customer->email,
                        'type' => $type == 'vendor' ? PlatformRecordType::VENDOR : PlatformRecordType::CUSTOMER
                        //  'postal_addresses' => json_encode($address)

                    );
                    $platform_customer_id =   $this->mobj->makeInsertGetId('platform_customer', $fields);

                    if (empty($firstPlatformCustomerId) && !empty($platform_customer_id)) {
                        $firstPlatformCustomerId = $platform_customer_id;
                    }
                }



                return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
            } else if ($mapping_result !== false && $mapping_result === 0) {

                /** Create Netsuite Customer */

                $first_name = explode(' ', ($source_customer->customer_name ? $source_customer->customer_name : $source_customer->company_name), 2)[0];
                if (isset($source_customer->first_name) && $source_customer->first_name) {
                    $first_name  = $source_customer->first_name;
                }
                $last_name =   @explode(' ', ($source_customer->customer_name ? $source_customer->customer_name : $source_customer->company_name), 2)[1];
                if (isset($source_customer->last_name) && $source_customer->last_name) {
                    $last_name  = $source_customer->last_name;
                }
                $data = [
                    'firstName' => $first_name,
                    'lastName' => $last_name,
                    'companyName' => $source_customer->company_name, 'email' => $source_customer->email, 'phone' => $source_customer->phone,
                    'address1' => $source_customer->address1, 'address2' => $source_customer->address2, 'address3' => $source_customer->address3,
                    'postal_addresses' => $source_customer->postal_addresses, 'country' => $source_customer->country, 'full_name' => $source_customer->customer_name
                ];

                /** Default Subsidiary */
                $subsidiary = $this->mapping->getMappedDataByName($source_customer->user_integration_id, null, "subsidiary", ['api_id']);
                if (!empty($subsidiary)) {
                    $data['subsidiary'] = $subsidiary->api_id;
                }


                $response = ($type == 'vendor') ? $this->netsuiteApi->CreateNetsuiteVendor($service, $data) : $this->netsuiteApi->CreateNetsuiteCustomer($service, $data);

                if ($response === false || isset($response['error'])) {
                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $platform_order_id]);
                    $sync_error = ($type == 'vendor') ? 'Vendor sync failed' : 'Customer sync failed';
                    $sync_error .= ' ' . @$response['error'];
                    $this->log->syncLog($source_customer->user_id, $source_customer->user_integration_id, $user_workflow_rule_id, $source_customer->platform_id, $destination_platform_id, $object_id, 'failed', $platform_order_id, $sync_error);

                    return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $sync_error];
                } else {
                    $fields = array(
                        'user_id' => $source_customer->user_id,
                        'user_integration_id' => $source_customer->user_integration_id,
                        'platform_id' => $destination_platform_id,
                        'api_customer_id' => $response,
                        'customer_name' => $source_customer->customer_name,
                        'company_name' => $source_customer->company_name,
                        'phone' =>  $source_customer->phone,
                        'email' => $source_customer->email,
                        'type' => $type == 'vendor' ? PlatformRecordType::VENDOR : PlatformRecordType::CUSTOMER
                        //  'postal_addresses' => json_encode($address)

                    );
                    $platform_customer_id =   $this->mobj->makeInsertGetId('platform_customer', $fields);

                    if (empty($firstPlatformCustomerId) && !empty($platform_customer_id)) {
                        $firstPlatformCustomerId = $platform_customer_id;
                    }

                    if (empty($firstInternalId) && !empty($response)) {
                        $firstInternalId = $response;
                    }
                }


                return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
            }

            return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $error_msg];
        }

        return ['api_customer_id' => $findCustomer->api_customer_id, 'platform_customer_id' => $findCustomer->id, 'error_msg' => $error_msg];
    }

    public function GetAllClassifications($userId, $userIntegrationId)
    {
        try {
            $objId = $this->helper->getObjectId('classification');
            $service =  $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);
            if ($service) {
                $classifications = $this->netsuiteSyncServices->getAllClassifications($service);
                if ($classifications) {
                    foreach ($classifications as $classification) {
                        if (!$classification->isInactive) {
                            $platformObj = PlatformObjectData::where([
                                'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId, 'api_id' => $classification->internalId
                            ])->first();

                            if (!$platformObj && !isset($platformObj->id)) {
                                $platformObj = new PlatformObjectData();
                                $platformObj->user_id = $userId;
                                $platformObj->user_integration_id = $userIntegrationId;
                                $platformObj->platform_id = $this->platformId;
                                $platformObj->platform_object_id = $objId;
                            }
                            $platformObj->api_id = $classification->internalId;
                            $platformObj->description = $classification->parent ? $classification->parent->name . ' : ' . $classification->name : $classification->name;
                            $platformObj->name = $classification->name;
                            $platformObj->save();
                        }
                    }
                    return true;
                }
            }
            return "No Classes found";
        } catch (\Exception $ex) {
            //print_r("\n Error\Exception while fetching all classes: " . $ex->getMessage() . " at " . $ex->getLine());
            return 'Exception\Error occurred: ' . $ex->getMessage();
        }
    }

    public function GetAllAccounts($userId, $userIntegrationId)
    {
        try {
            $objId = $this->helper->getObjectId('payment');
            $service =  $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);
            if ($service) {
                $accounts = $this->netsuiteSyncServices->getAllAccounts($service);
                if ($accounts) {
                    foreach ($accounts as $account) {
                        $existingItem = $this->mobj->getFirstResultByConditions('platform_object_data', [
                            'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId, 'api_id' => $account->internalId
                        ]);
                        if (!$existingItem && !isset($existingItem->id)) {
                            $this->mobj->makeInsert('platform_object_data', [
                                'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId,
                                'api_id' => $account->internalId, 'name' => $account->acctName, 'description' => $account->acctType, 'api_code' => $account->acctNumber
                            ]);
                        } else {
                            $this->mobj->makeUpdate('platform_object_data', [
                                'name' => $account->acctName, 'description' => $account->acctType,
                                'api_code' => $account->acctNumber
                            ], ['id' => $existingItem->id]);
                        }
                    }
                    return true;
                }
            }
            return "No Accounts found";
        } catch (\Exception $ex) {
            //print_r("\n Error\Exception while fetching all accounts: " . $ex->getMessage() . " at " . $ex->getLine());
            return 'Exception\Error occurred: ' . $ex->getMessage();
        }
    }

    public function GetAllTaxCodes($userId, $userIntegrationId)
    {
        try {
            $objId = $this->helper->getObjectId(PlatformObjectName::TAX_CODE);
            $service = $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);
            if ($service) {
                $taxCodes = $this->netsuiteSyncServices->getAllTaxCodes($service);
                if ($taxCodes) {
                    foreach ($taxCodes as $taxCode) {
                        $platformObj = PlatformObjectData::where('user_integration_id', $userIntegrationId)
                            ->where('platform_id', $this->platformId)->where('platform_object_id', $objId)->where('api_id', $taxCode->internalId)->first();
                        if (!$platformObj) {
                            $platformObj = new PlatformObjectData();
                            $platformObj->user_id = $userId;
                            $platformObj->user_integration_id = $userIntegrationId;
                            $platformObj->platform_id = $this->platformId;
                            $platformObj->platform_object_id = $objId;
                        }
                        $platformObj->api_id = $taxCode->internalId;
                        $platformObj->name = $taxCode->itemId;
                        $platformObj->description = $taxCode->description;
                        $platformObj->api_code = $taxCode->rate;
                        $platformObj->save();
                    }
                    return true;
                }
                return 'No Tax Code found';
            }
            return 'User error occurred';
        } catch (\Exception $ex) {
            //print_r("\n Error\Exception while fetching all Tax Codes: " . $ex->getMessage() . " at " . $ex->getLine());
            return 'Exception\Error occurred: ' . $ex->getMessage();
        }
    }

    public function GetAllShippingItems($userId, $userIntegrationId)
    {
        try {
            $objId = $this->helper->getObjectId('shipping_method');
            $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId);
            $nsRestServices = new NetsuiteRestServices($account->id);
            $shippingItems = $nsRestServices->getShippingItems();

            if (isset($shippingItems->items)) {
                foreach ($shippingItems->items as $shippingItem) {
                    $itemData = $nsRestServices->getShippingItemById($shippingItem->id);
                    if (isset($itemData->id)) {
                        $existingItem = $this->mobj->getFirstResultByConditions('platform_object_data', [
                            'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId, 'api_id' => $itemData->id
                        ]);
                        if (!$existingItem && !isset($existingItem->id)) {
                            $this->mobj->makeInsert('platform_object_data', [
                                'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId,
                                'api_id' => $itemData->id, 'name' => isset($itemData->displayName) ? $itemData->displayName : $itemData->itemId, 'description' => @$itemData->description, 'api_code' => $itemData->itemId
                            ]);
                        } else {
                            $this->mobj->makeUpdate('platform_object_data', [
                                'name' => isset($itemData->displayName) ? $itemData->displayName : $itemData->itemId, 'description' => @$itemData->description,
                                'api_code' => $itemData->itemId
                            ], ['id' => $existingItem->id]);
                        }
                    }
                }
                return true;
            }
            return false;
        } catch (\Exception $ex) {
            // print_r("\n Error\Exception while fetching all sales items: " . $ex->getMessage() . " at " . $ex->getLine());
            return 'Exception\Error occurred: ' . $ex->getMessage();
        }
    }

    public function GetAllDiscountItems($userId, $userIntegrationId)
    {
        try {
            $objId = $this->helper->getObjectId('discount_item');
            $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId);
            $nsRestServices = new NetsuiteRestServices($account->id);
            $discountItems = $nsRestServices->getDiscountItems();
            if (isset($discountItems->items)) {
                foreach ($discountItems->items as $discountItem) {
                    $itemData = $nsRestServices->getDiscountItemById($discountItem->id);
                    if (isset($itemData->id)) {
                        $existingItem = $this->mobj->getFirstResultByConditions('platform_object_data', [
                            'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId, 'api_id' => $itemData->id
                        ]);
                        if (!$existingItem && !isset($existingItem->id)) {
                            $this->mobj->makeInsert('platform_object_data', [
                                'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objId,
                                'api_id' => $itemData->id, 'name' => @$itemData->itemId, 'description' => @$itemData->description, 'api_code' => $itemData->itemId
                            ]);
                        } else {
                            $this->mobj->makeUpdate('platform_object_data', [
                                'name' => @$itemData->itemId, 'description' => @$itemData->description,
                                'api_code' => $itemData->itemId
                            ], ['id' => $existingItem->id]);
                        }
                    }
                }
                return true;
            }
            return false;
        } catch (\Exception $ex) {
            // print_r("\n Error\Exception while fetching all sales items: " . $ex->getMessage() . " at " . $ex->getLine());
            return 'Exception\Error occurred: ' . $ex->getMessage();
        }
    }

    public function getProductMapping($service, $source_product, $destination_platform_id, $source_field_match_by, $destination_field_match_by, $save_record = 1, $customFieldProductName = null)
    {
        $match_string = $source_product->$source_field_match_by;
        $match_string = trim($match_string);
        $destination_field_match_by = trim($destination_field_match_by);
        if (empty($match_string)) {
            return 0;
        }
        $findProduct = PlatformProduct::where([
            'platform_id' => $destination_platform_id,
            'user_integration_id' => $source_product->user_integration_id,
            strval($destination_field_match_by) => $match_string,
        ])->first();

        if (isset($findProduct->api_product_code) && !empty($findProduct->api_product_code)) {

            return $findProduct;
        } else {
            $mapping_result = $this->netsuiteApi->SearchNetsuiteProduct($service, $destination_field_match_by, $match_string);


            if ($mapping_result !== false && !empty($mapping_result)) {
                $firstInternalId = 0;

                foreach ($mapping_result->record as $netsuite_record) {
                    if (empty($firstInternalId) && !empty($netsuite_record->internalId)) {
                        $firstInternalId = $netsuite_record->internalId;
                    }

                    if ($save_record) {
                        $product_name = null;

                        if ($customFieldProductName) {
                            $customFields = $this->netsuiteSyncServices->GetSearchItemCustomField($netsuite_record);

                            if (isset($customFields[$customFieldProductName])) {

                                if (!empty($customFields[$customFieldProductName])) {
                                    $product_name = $customFields[$customFieldProductName];
                                } else {

                                    $product_name = $netsuite_record->itemId;
                                }
                            } else {
                                $product_name = $netsuite_record->itemId;
                            }
                        } else {

                            $product_name = isset($netsuite_record->displayName) ? $netsuite_record->displayName : $netsuite_record->itemId;
                        }
                        $fields = array(
                            'user_id' => $source_product->user_id,
                            'user_integration_id' => $source_product->user_integration_id,
                            'platform_id' => $destination_platform_id,
                            'api_product_id' => $netsuite_record->internalId,
                            'product_name' => $product_name,
                            'api_product_code' => @$netsuite_record->incomeAccount->internalId,
                            'upc' => @$netsuite_record->upcCode,
                            'sku' => $netsuite_record->sku ?? trim(@$netsuite_record->itemId),
                            'price' => ((!empty($netsuite_record->amount)) ? $netsuite_record->amount : 0)
                        );

                        $productResponse = PlatformProduct::updateOrCreate([
                            'user_id' => $source_product->user_id,
                            'platform_id' => $destination_platform_id,
                            'user_integration_id' => $source_product->user_integration_id,
                            $destination_field_match_by => $match_string,

                        ], $fields);
                        /* Get Update Parent Product Id & IncomeAsset Id in child products from parent product */
                        if ($netsuite_record->matrixType == "_child" && isset($netsuite_record->parent->internalId)) {
                            $parr = $this->netsuiteSyncServices->SearchAndStoreProduct($service, $source_product->user_id, $source_product->user_integration_id, $netsuite_record->parent->internalId, 'internalId');
                            if (isset($parr['id'])) {
                                $productResponse->api_product_code = $parr['api_product_code'];
                                $productResponse->parent_product_id = $parr['id'];
                                $productResponse->save();
                            }
                        }
                    }
                }

                $findProduct =  PlatformProduct::where([
                    'platform_id' => $destination_platform_id,
                    'user_integration_id' => $source_product->user_integration_id,
                    $destination_field_match_by => $match_string,
                ])->first();


                return $findProduct;
            } else if ($mapping_result !== false && $mapping_result === 0) {
                return 0;
            }
        }
    }

    public function getSO($uIntegrationId, $orderId)
    {
        $service =  $this->netsuiteApi->GetNetsuiteService($uIntegrationId, $this->platformId);
        $req = new GetRequest();
        $req->baseRef = new RecordRef();
        $req->baseRef->internalId = $orderId;
        $req->baseRef->type  = RecordType::salesOrder;

        return json_encode($service->get($req));
    }


    /* Dynamic order creation by type PO*/
    public function createPOOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_name, $sync_status, $record_id)
    {
        $return_response = true;
        $limit = 10;

        try {
            $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
            $default_expense_sku_id = null;
            $po_expense_ac_number = null;
            $object_id = $this->helper->getObjectId('purchase_order');
            //  /** Get default BP Expense SKU */
            $default_expense_sku_id = $this->mapping->getMappedDataByName($user_integration_id, null, "default_expense_sku", ['custom_data']);
            $default_expense_sku_id = $default_expense_sku_id && $default_expense_sku_id->custom_data ? $default_expense_sku_id->custom_data : NULL;

            //  Get Default BP Expense Account Number
            $po_expense_ac_number = $this->mapping->getMappedDataByName($user_integration_id, null, "po_default_expense_ac_number", ['custom_data']);
            $po_expense_ac_number = $po_expense_ac_number && $po_expense_ac_number->custom_data ? $po_expense_ac_number->custom_data : NULL;


            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {

                $conditions = ['user_workflow_rule_id' => $user_workflow_rule_id,  'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => "PO"];

                if ($record_id) {
                    $conditions['id'] = $record_id;
                } else {
                    $conditions['sync_status'] = $sync_status;
                }
                $list =  PlatformOrder::where($conditions)->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    /** Get Product Identity */
                    $product_identity = $this->netsuiteSyncServices->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id);

                    if ($product_identity) {
                        /** Default NS Form */
                        $customFormId = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "transaction_forms", ['api_id']);

                        /** Get default NS Location */
                        $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "porder_location", ['api_id']);
                        $default_location_id = $default_location_id && $default_location_id->api_id ? $default_location_id->api_id : NULL;

                        /** Default Subsidiary */
                        $subsidiary = $this->mapping->getMappedDataByName($user_integration_id, null, "subsidiary", ['api_id']);
                        $subsidiary = $subsidiary ? $subsidiary->api_id : NULL;
                        foreach ($list as $row) {
                            if ($row->sync_status == "Synced" || $row->linked_id) {
                                // Check already synced platform order, if already synced then status update IGNORE & Continue
                                $row->sync_status = 'Ignore';
                                $row->save();
                                continue;
                            }
                            $attributes = PlatformOrderAdditionalInformation::where(['platform_order_id' => $row->id])->first();

                            if (!empty($attributes) && $attributes->api_channel_id) {
                                $objId = $this->helper->getObjectId(PlatformObjectName::CHANNEL);
                                $sChannelObj = PlatformObjectData::where([
                                    'api_id' => $attributes->api_channel_id, 'user_integration_id' => $user_integration_id,
                                    'platform_id' => $source_platform_id, 'platform_object_id' => $objId
                                ])->first();
                                if ($sChannelObj) {
                                    $objId = $this->helper->getObjectId(PlatformObjectName::SO_CHANNEL);
                                    $map = PlatformDataMapping::where(['platform_object_id' => $objId, 'user_integration_id' => $user_integration_id, 'source_row_id' => $sChannelObj->id])->first();

                                    if ($map) {
                                        $classification = PlatformObjectData::find($map->destination_row_id);
                                        $order_array['classificationId'] = $classification->api_id;
                                    }
                                }
                            }
                            //Order customer details
                            $findCustomer = PlatformCustomer::where('id', $row->platform_customer_id)->first();


                            if (isset($findCustomer->id)) {
                                $getCustomerMapping = $this->getCustomerMapping($service, $findCustomer, $row->id,  $user_workflow_rule_id, $platform_workflow_rule_id, $this->platformId, $object_id, 'vendor', $source_platform_name);

                                $customerInternalId = $getCustomerMapping['api_customer_id'];

                                $destination_platform_customer_id = $getCustomerMapping['platform_customer_id'];
                                if ($customerInternalId  === false) {
                                    $return_response = 'Vendor mapping error ' . $getCustomerMapping['error_msg'];
                                    $row->sync_status = PlatformStatus::FAILED;
                                    $row->save();
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                    continue;
                                }

                                if ($customerInternalId  === 0) {
                                    $return_response = 'Vendor is not mapped ' . $getCustomerMapping['error_msg'];
                                    $row->sync_status = PlatformStatus::FAILED;
                                    $row->save();
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                    continue;
                                }
                                $order_array['shippingAddress'] = $order_array['billingAddress'] = [];

                                // if ($source_platform_name == "snowflake") {
                                //     $vendorDetail = $this->netsuiteApi->getVendorById($service, $customerInternalId);

                                //     if (!is_string($vendorDetail) || !is_bool($vendorDetail)) {

                                //         if (isset($vendorDetail->addressbookList->addressbook)) {

                                //             foreach ($vendorDetail->addressbookList->addressbook as $address) {
                                //                 if ($address->defaultShipping == true && $address->defaultBilling == true) {

                                //                     $order_array['billingAddress']['zip'] =  $order_array['shippingAddress']['zip'] = $address->addressbookAddress->zip;
                                //                     $order_array['billingAddress']['city'] = $order_array['shippingAddress']['city'] = $address->addressbookAddress->city;
                                //                     $order_array['billingAddress']['street'] = $order_array['shippingAddress']['street'] = $address->addressbookAddress->addressee;
                                //                     $order_array['billingAddress']['state'] = $order_array['shippingAddress']['state'] = $address->addressbookAddress->state;
                                //                     $order_array['billingAddress']['country'] = $order_array['shippingAddress']['country'] = $address->addressbookAddress->country;
                                //                 } else if ($address->defaultShipping == true  && $address->defaultBilling == false) {
                                //                     $order_array['shippingAddress']['zip'] = $address->addressbookAddress->zip;
                                //                     $order_array['shippingAddress']['city'] = $address->addressbookAddress->city;
                                //                     $order_array['shippingAddress']['street'] = $address->addressbookAddress->addressee;
                                //                     $order_array['shippingAddress']['state'] = $address->addressbookAddress->state;
                                //                     $order_array['shippingAddress']['country'] = $address->addressbookAddress->country;
                                //                 } else if ($address->defaultBilling == true  && $address->defaultShipping == false) {
                                //                     $order_array['billingAddress']['zip'] =   $address->addressbookAddress->zip;
                                //                     $order_array['billingAddress']['city'] =  $address->addressbookAddress->city;
                                //                     $order_array['billingAddress']['street'] =  $address->addressbookAddress->addressee;
                                //                     $order_array['billingAddress']['state'] = $address->addressbookAddress->state;
                                //                     $order_array['billingAddress']['country'] = $address->addressbookAddress->country;
                                //                 }
                                //             }
                                //         }
                                //     } else {
                                //         $return_response = 'Vendor detail  not found ';
                                //         $row->sync_status = PlatformStatus::FAILED;
                                //         $row->save();
                                //         $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                //         continue;
                                //     }
                                // }
                                //order data

                                $order_array['s_order_api_id'] =  $row->api_order_id;
                                if (!empty($customerInternalId)) {
                                    $order_array['customerInternalId'] = $customerInternalId;
                                }
                                if ($row->api_order_reference) {
                                    $order_array['memo'] = $row->api_order_reference;
                                }
                                $order_array['orderDate'] = Carbon::parse($row->order_date)->format('c');

                                $order_array['items'] = [];
                                if ($row->delivery_date && $row->delivery_date != '') {
                                    $order_array['deliveryDate'] = Carbon::parse($row->delivery_date)->format('c');
                                }
                                //get order line items
                                $totalDiscount = 0;
                                $totalShipping = 0;
                                $order_lines = isset($row->platformOrderLine) ? $row->platformOrderLine : [];
                                $error_msg = 0;
                                $orderLineNsIdMap = [];
                                if (count($order_lines)) {
                                    foreach ($order_lines as $order_line) {
                                        $line = $order_line->toArray();

                                        if (($order_line->row_type == 'ITEM' && isset($line[$product_identity['source_identity']]) && $line[$product_identity['source_identity']]) || ($order_line->row_type == 'DISCOUNT' && isset($line[$product_identity['source_identity']]) && $line[$product_identity['source_identity']])
                                            || ($order_line->row_type == 'SHIPPING' && isset($line[$product_identity['source_identity']]) && $line[$product_identity['source_identity']])
                                        ) {


                                            //Order product details
                                            $findProduct = PlatformProduct::where([
                                                'platform_id' => $source_platform_id,
                                                $product_identity['source_identity'] => $line[$product_identity['source_identity']],
                                                'user_integration_id' => $user_integration_id
                                            ])->first();

                                            if (!isset($findProduct->id)) {
                                                $return_response =  'Invalid Order product';
                                                $row->sync_status = PlatformStatus::FAILED;
                                                $row->save();
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                                $error_msg = 1;
                                                break;
                                            }
                                            $linked_product = $this->getProductMapping($service, $findProduct, $this->platformId, $product_identity['source_identity'], $product_identity['destination_identity'], 1, $customFieldProductName = null);
                                            if ($linked_product === false) {

                                                $return_response = 'Product mapping error';
                                                $row->sync_status = PlatformStatus::FAILED;
                                                $row->save();
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                                $error_msg = 1;
                                                break;
                                            } else if ($linked_product === 0) {
                                                $return_response = 'Product is not mapped ';
                                                $row->sync_status = PlatformStatus::FAILED;
                                                $row->save();
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                                $error_msg = 1;
                                                break;
                                            }
                                            $productInternalId = $linked_product->api_product_id;


                                            //Compare Default Expense Sku Id with order item sku id
                                            if (($default_expense_sku_id == $line[$product_identity['source_identity']]) && $po_expense_ac_number) {

                                                $order_array['expense'][] = ['account' => $po_expense_ac_number, 'amount' => $order_line->total + $order_line->total_tax, 'description' => $default_expense_sku_id . " - " . $order_line->product_name];
                                                continue;
                                            }

                                            $taxCodeId = 0;

                                            $sTaxCode = PlatformObjectData::where([
                                                'user_integration_id' => $user_integration_id,
                                                'platform_id' => $source_platform_id, 'api_id' => $order_line->taxes,
                                                'platform_object_id' => $this->helper->getObjectId(PlatformObjectName::TAX_CODE)
                                            ])->first();

                                            if ($sTaxCode) {
                                                $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, PlatformObjectName::PO_TAX_CODE_MAP, ['id'], 'regular', $sTaxCode->id);
                                                if ($taxCodeMap) {
                                                    $taxCode = PlatformObjectData::find($taxCodeMap->id);
                                                    $taxCodeId = $taxCode->api_id;
                                                }
                                            }

                                            if ($taxCodeId == 0) {
                                                $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, PlatformObjectName::PO_TAX_CODE_MAP, ['id']);
                                                if ($taxCodeMap) {
                                                    $taxCode = PlatformObjectData::find($taxCodeMap->id);
                                                    $taxCodeId = $taxCode->api_id;
                                                }
                                            }

                                            $order_array['items'][] = [
                                                'quantity' => $order_line->qty, 'internalId' => $productInternalId,
                                                'price' => $order_line->unit_price, 'total' => $order_line->total + $order_line->total_tax,
                                                'taxCode' => $taxCodeId, 'noTaxTotal' => $order_line->total
                                            ];

                                            $orderLineNsIdMap[$order_line->id] = $productInternalId;
                                        }
                                    }


                                    $order_array['discount'] = $totalDiscount;
                                    $order_array['shippingPrice'] = $totalShipping;

                                    if ($row->shipping_method && $row->shipping_method != '') {
                                        $objId = $this->helper->getObjectId(PlatformObjectName::SHIPPING_METHOD);
                                        $sShippingMethod = PlatformObjectData::where(['api_id' => strval($row->shipping_method), 'platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $objId])->first();
                                        if ($sShippingMethod) {
                                            $dShippingMethod = NetsuiteServices::GetNetsuiteObjectByName($sShippingMethod->name, $objId, $user_integration_id);
                                            if ($dShippingMethod) {
                                                $order_array['shippingItemId'] = $dShippingMethod->api_id;
                                            }
                                        }
                                    }

                                    if (!$error_msg) {

                                        if ($source_platform_name != "snowflake") {

                                            $shipping_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type' => 'shipping', 'platform_order_id' => $row->id]);

                                            if (!empty($shipping_address)) {
                                                $order_array['shippingAddress']['zip'] = $shipping_address->postal_code;
                                                $order_array['shippingAddress']['city'] = $shipping_address->city;
                                                $order_array['shippingAddress']['street'] = $shipping_address->address1 . ' ' . $shipping_address->address2;
                                                $order_array['shippingAddress']['state'] = $shipping_address->state;
                                                $order_array['shippingAddress']['country'] = $shipping_address->country;
                                            }

                                            $billing_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type' => 'billing', 'platform_order_id' => $row->id]);

                                            if (!empty($billing_address)) {
                                                $order_array['billingAddress']['zip'] = $billing_address->postal_code;
                                                $order_array['billingAddress']['city'] = $billing_address->city;
                                                $order_array['billingAddress']['street'] = $billing_address->address1 . ' ' . $billing_address->address2;
                                                $order_array['billingAddress']['state'] = $billing_address->state;
                                                $order_array['billingAddress']['country'] = $billing_address->country;
                                            }
                                        }



                                        /** WH - Location  Mapping */

                                        $warehouse_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $row->warehouse_id, 'status' => 1], ['api_id']);

                                        if ($warehouse_object_data) {
                                            $warehouseId = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $warehouse_object_data->api_id);

                                            if ($warehouseId) {
                                                $default_location_id = $warehouseId->api_id;
                                            }
                                        }



                                        if (!empty($default_location_id)) {
                                            $order_array['location'] = $default_location_id;
                                        }


                                        if (!empty($customFormId)) {
                                            $order_array['customForm'] = $customFormId->api_id;
                                        }

                                        if (!empty($subsidiary)) {
                                            $order_array['subsidiary'] = $subsidiary;
                                        }


                                        if (count($order_array['items']) < 0) {
                                            $return_response = 'Products missing';
                                            $row->sync_status = PlatformStatus::FAILED;
                                            $row->save();
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                            $error_msg = 1;
                                            break;
                                        }
                                        if ($source_platform_name == "snowflake") {
                                            $poCustomFields = PlatformField::select('id')->where(['field_type' => 'custom', 'platform_id' => $source_platform_id, 'platform_object_id' => $object_id])->get();
                                        } else {
                                            $poCustomFields = PlatformField::where(['user_integration_id' => $user_integration_id, 'field_type' => 'custom', 'platform_id' => $source_platform_id, 'platform_object_id' => $object_id])->get();
                                        }


                                        if (count($poCustomFields)) {

                                            foreach ($poCustomFields as $customField) {
                                                $getMappedField = $this->mapping->getMappedField($user_integration_id, null, $object_id, [], $customField->id);
                                                if (!empty($getMappedField['destination_field_name'])) {
                                                    $castRow = $row->toArray();
                                                    $castExtraRow = $row->order_extra_information->toArray();

                                                    if (isset($castRow[$getMappedField['source_row_data']])) {
                                                        $order_array['custom_fields'][] = [
                                                            'sciptId' => $getMappedField['destination_field_name'],
                                                            'internalId' => $getMappedField['destination_custom_field_id'],
                                                            'value' => $castRow[$getMappedField['source_row_data']],
                                                            'fieldType' => $getMappedField['destination_custom_field_type']
                                                        ];
                                                    } else if (isset($castExtraRow[$getMappedField['source_row_data']])) {
                                                        $order_array['custom_fields'][] = [
                                                            'sciptId' => $getMappedField['destination_field_name'],
                                                            'internalId' => $getMappedField['destination_custom_field_id'],
                                                            'value' => $castExtraRow[$getMappedField['source_row_data']],
                                                            'fieldType' => $getMappedField['destination_custom_field_type']
                                                        ];
                                                    }
                                                }
                                            }
                                        }

                                        //create netsuite purchase order api
                                        $response =  $this->netsuiteApi->createPO($service, $order_array);

                                        if ($response === false || $response == 0 || isset($response['error'])) {

                                            $return_response = 'Order sync failed ' . @$response['error'];
                                            $row->sync_status = PlatformStatus::FAILED;
                                            $row->save();
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                                        } else {

                                            //store netsuite order details into db and link with parent order
                                            $arr_po_order = array();
                                            $arr_po_order['user_id'] = $user_id;
                                            $arr_po_order['user_workflow_rule_id'] = $user_workflow_rule_id;
                                            $arr_po_order['platform_id'] = $this->platformId;
                                            $arr_po_order['order_date'] = $row->order_date;
                                            $arr_po_order['user_integration_id'] = $user_integration_id;
                                            $arr_po_order['order_type'] = $row->order_type;
                                            $arr_po_order['api_order_id'] = $response;
                                            $arr_po_order['platform_customer_id'] = $destination_platform_customer_id;
                                            $arr_po_order['customer_email'] = $row->customer_email;
                                            $arr_po_order['order_number'] = $response;
                                            $arr_po_order['api_order_reference'] = $row->api_order_reference;
                                            $arr_po_order['total_amount'] = $row->total_amount;
                                            $arr_po_order['net_amount'] = $row->net_amount;
                                            $arr_po_order['sync_status'] = PlatformStatus::PENDING;
                                            $arr_po_order['shipment_status'] = PlatformStatus::PENDING;
                                            $arr_po_order['linked_id'] = $row->id; //parent platform order row id

                                            $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_po_order);

                                            foreach ($order_lines as $key => $order_line) {
                                                if (isset($orderLineNsIdMap[$order_line->id]) && $orderLineNsIdMap[$order_line->id]) {
                                                    $newLine = new PlatformOrderLine();
                                                    $newLine->platform_order_id = $linked_platform_order_id;
                                                    $newLine->api_order_line_id = $key + 1;
                                                    $newLine->api_product_id = $orderLineNsIdMap[$order_line->id];
                                                    $newLine->product_name = $order_line->product_name;
                                                    $newLine->ean = $order_line->ean;
                                                    $newLine->sku = $order_line->sku;
                                                    $newLine->qty = $order_line->qty;
                                                    $newLine->subtotal = $order_line->subtotal;
                                                    $newLine->subtotal_tax = $order_line->subtotal_tax;
                                                    $newLine->total = $order_line->total;
                                                    $newLine->total_tax = $order_line->total_tax;
                                                    $newLine->unit_price = $order_line->unit_price;
                                                    $newLine->row_type = $order_line->row_type;
                                                    $newLine->is_deleted = 0;
                                                    $newLine->linked_id = $order_line->id;
                                                    $newLine->save();
                                                }
                                            }


                                            $return_response =  'Order synced successfully';
                                            $row->sync_status = PlatformStatus::SYNCED;
                                            $row->linked_id = $linked_platform_order_id;
                                            $row->save();

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $row->id, $return_response);
                                        }
                                    }
                                }
                            } else {
                                $row->sync_status = PlatformStatus::FAILED;
                                $row->save();
                                $return_response = 'Invalid Order customer';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $return_response);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - createPOOrder - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Dynamic order creation by type TO */
    public function createTOOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_name, $sync_status, $record_id)
    {
        $return_response = true;
        $limit = 10;

        try {
            $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);

            $object_id = $this->helper->getObjectId('transfer_order');
            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {

                if ($record_id) {
                    $conditions['id'] = $record_id;
                } else {
                    $conditions = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => "TO"];
                    $conditions['sync_status'] = $sync_status;
                }
                $orders = PlatformOrder::where($conditions)->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($orders) && count($orders) > 0) {

                    /** Default Subsidiary */
                    $subsidiary = $this->mapping->getMappedDataByName($user_integration_id, null, "default_transfer_order_subsidiary", ['api_id']);
                    $subsidiary = $subsidiary ? $subsidiary->api_id : NULL;
                    /** Get Product Identity */
                    $product_identity = $this->netsuiteSyncServices->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id);

                    foreach ($orders as $order) {

                        if ($order->sync_status == "Synced" || $order->linked_id) {
                            continue;
                        }

                        $list = PlatformOrderShipment::where('platform_order_id', $order->id)->get();

                        if (!empty($list) && count($list) > 0) {

                            if ($product_identity) {


                                foreach ($list as $row) {
                                    if ($row->sync_status == "Synced" || $row->linked_id) {
                                        continue;
                                    }
                                    $to_location_id = $from_location_id = null;
                                    /** from - Location/Warehouse  Mapping */

                                    $from_warehouse_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $row->warehouse_id, 'status' => 1], ['api_id']);

                                    if ($from_warehouse_object_data) {
                                        $warehouseId = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $from_warehouse_object_data->api_id);

                                        if ($warehouseId) {
                                            $from_location_id = $warehouseId->api_id;
                                        }
                                    }
                                    /** to - Location/Warehouse  Mapping */

                                    $to_warehouse_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $row->to_warehouse_id, 'status' => 1], ['api_id']);

                                    if ($to_warehouse_object_data) {
                                        $warehouseId = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $to_warehouse_object_data->api_id);

                                        if ($warehouseId) {
                                            $to_location_id = $warehouseId->api_id;
                                        }
                                    }
                                    if ($to_location_id && $from_location_id) {
                                        //order data
                                        if ($row->api_order_reference) {
                                            $order_array['memo'] = $row->api_order_reference;
                                        }
                                        $order_array['subsidiary'] = $subsidiary;
                                        $order_array['to_location'] = $to_location_id;
                                        $order_array['location'] = $from_location_id;
                                        $productIdentityDestination = $product_identity['destination_identity'];
                                        if ($product_identity['destination_identity'] == "api_product_id") {
                                            $productIdentityDestination = "product_id"; //if  api_product_id then set product_id because in shipment line no api_product_id column available
                                        }
                                        //get shipment line items
                                        $orderLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $row->id)->groupBy($productIdentityDestination)
                                            ->selectRaw('*, sum(quantity) as sum')->get(); //isset($value->platformShippingLines) ? $value->platformShippingLines : NULL;

                                        if ($orderLines) {
                                            $orderLines = $this->netsuiteSyncServices->prepareOrderLine("TO", $orderLines, $user_id, $user_integration_id, $product_identity['destination_identity']);

                                            if (count($orderLines['items']) > 0) {
                                                if ($orderLines['productNotFound']) {
                                                    /* Shipment Table sync status updated */
                                                    $order->sync_status = PlatformStatus::FAILED;
                                                    $order->save();
                                                    $return_response = "No lines are mateched with Netsuite product";
                                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $return_response);
                                                } else {
                                                    $order_array['items'] = $orderLines['items'];
                                                    $response =  $this->netsuiteApi->createTO($service, $order_array);
                                                    if ($response === false || $response == 0 || isset($response['error'])) {
                                                        $order->sync_status = PlatformStatus::FAILED;
                                                        $order->save();
                                                        $return_response = 'Record sync failed ' . @$response['error'];
                                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $return_response);

                                                        continue;
                                                    } else {

                                                        $create = PlatformOrder::create([
                                                            'api_order_id' => $response,
                                                            'user_id' => $user_id,
                                                            'user_integration_id' => $user_integration_id,
                                                            'platform_id' => $this->platformId,
                                                            'order_type' => "TO",
                                                            'user_workflow_rule_id' => $user_workflow_rule_id,
                                                            'order_number' => $response,
                                                            'linked_id' => $order->id,
                                                            'sync_status' => PlatformStatus::PENDING,
                                                            'shipment_status' => PlatformStatus::PENDING,
                                                            'api_order_reference' => $order->api_order_reference,
                                                            'currency' => $order->currency,
                                                            'order_date' => $order->order_date,
                                                            'order_updated_at' => $order->order_updated_at,
                                                        ]);
                                                        if ($create) {
                                                            /* Insert infoplus order details  */
                                                            $OrderLinked = $this->mobj->makeInsertGetId('platform_order_shipments', [
                                                                'user_id' => $user_id,
                                                                'platform_id' => $this->platformId,
                                                                'user_integration_id' => $user_integration_id,
                                                                'shipment_id' => $response,
                                                                'shipment_sequence_number' => 0,
                                                                'warehouse_id' =>  $row->warehouse_id,
                                                                'to_warehouse_id' =>   $row->to_warehouse_id,
                                                                'platform_order_id' =>   $create->id,
                                                                'created_on' => date("Y-m-d H:i:s"),
                                                                'order_id' => $response,
                                                                'type' => PlatformRecordType::TRANSFER,
                                                                'sync_status' => 'Pending',
                                                                'linked_id' =>  $row->id,
                                                            ]);

                                                            /* Shipment Table sync status updated */
                                                            $row->sync_status = PlatformStatus::SYNCED;
                                                            $row->linked_id = $OrderLinked;
                                                            $row->save();
                                                            $order->sync_status = PlatformStatus::SYNCED;
                                                            $order->linked_id = $create->id;
                                                            $order->save();

                                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $order->id, null);
                                                        }
                                                    }
                                                }
                                            } else {
                                                /* If no shipment lines found  */
                                                $order->sync_status = PlatformStatus::FAILED;
                                                $order->save();
                                                $return_response = "No matched lines are found in netsuite";
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $return_response);
                                            }
                                        } else {
                                            /* If no shipment lines found  */
                                            $order->sync_status = PlatformStatus::FAILED;
                                            $order->save();
                                            $return_response = "No lines are found";
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $return_response);
                                        }
                                    } else {
                                        $order->sync_status = PlatformStatus::FAILED;
                                        $order->save();
                                        $return_response = 'Source or from location detail is not found for inventory transfer';
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $return_response);
                                    }
                                }
                            }
                        } else {
                            $order->sync_status = PlatformStatus::SYNCED;
                            $order->save();
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $order->id, null);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - createTOOrder - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get pull transfer / purchase order reciept*/
    public function getReceipts($user_id, $user_integration_id, $sync_status, $type = "PO")
    {
        $return_response = true;
        $limit = 50;

        try {
            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {

                $list = PlatformOrder::where([
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'order_type' => $type
                ])
                    ->whereIn('shipment_status', [$sync_status, 'Partial'])
                    ->orderBy('updated_at', 'ASC')
                    ->take($limit)
                    ->pluck('id', 'api_order_id')
                    ->toArray();

                if (!empty($list) && count($list) > 0) {
                    $orderPrimaryIds = array_values($list);
                    $orderIds = array_keys($list);
                    $postArray = [];
                    foreach ($orderIds as $id) {
                        $postArray[] = ['internalId' => $id];
                    }

                    if ($postArray) {

                        $response = $this->netsuiteApi->searchReciepts($service, $postArray);

                        if (isset($response['recordList']) && is_array($response['recordList']) && count($response['recordList'])) {
                            foreach ($response['recordList'] as $receipt) {
                                $recordNo = @$receipt->createdFrom->internalId;

                                if ($recordNo && isset($list[$recordNo])) {
                                    $platformOrderId = $list[$recordNo];
                                    $this->netsuiteSyncServices->prepareReceiptData($receipt, $user_id, $user_integration_id, $platformOrderId, $type, $service);
                                }
                            }

                            PlatformOrder::whereIn('id', $orderPrimaryIds)->update(['updated_at' => date('Y-m-d H:i:s')]);
                        }
                    } else {
                        $return_response = "No record found from DB to get {$type} receipts.";
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - getReceipt - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }


    /* Dynamic order creation by type SO,TO,PO,IO */
    public function CreateOrdersByType($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_name, $destination_platform_name, $sync_status, $record_id, $type = 'purchase_orders')
    {

        $sync_error = false;
        $orderType = ($type == 'purchase_orders') ? 'PO' : 'SO';
        try {

            $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
            $default_expense_sku_id = null;
            $po_expense_ac_number = null;
            if ($type == 'purchase_orders') {

                $object_id = $this->helper->getObjectId('purchase_order');
                //  /** Get default BP Expense SKU */
                $default_expense_sku_id = $this->mapping->getMappedDataByName($user_integration_id, null, "default_expense_sku", ['custom_data']);
                $default_expense_sku_id = $default_expense_sku_id && $default_expense_sku_id->custom_data ? $default_expense_sku_id->custom_data : NULL;

                //  Get Default BP Expense Account Number
                $po_expense_ac_number = $this->mapping->getMappedDataByName($user_integration_id, null, "po_default_expense_ac_number", ['custom_data']);
                $po_expense_ac_number = $po_expense_ac_number && $po_expense_ac_number->custom_data ? $po_expense_ac_number->custom_data : NULL;
            } else if ($type == 'sales_orders') {
                $object_id = $this->helper->getObjectId('sales_order');
            } else if ($type == 'invoice_orders') {
                $object_id = $this->helper->getObjectId('invoice_order');
            } else {
                $object_id = $this->helper->getObjectId('transfer_order');
            }


            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            $userIntegrationObj = $this->mapping->getUserIntegrationDetailsById($user_integration_id, self::$myPlatform);

            if ($service !== false && $userIntegrationObj) {
                $additionalAccountInfo = null;
                if ($source_platform_id == 1) {
                    $additionalAccountInfo = $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['user_integration_id' => $user_integration_id, 'account_id' => $userIntegrationObj->selected_sc_account_id]);
                }

                $conditions = ['user_workflow_rule_id' => $user_workflow_rule_id,  'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id];

                if ($record_id) {
                    $conditions['id'] = $record_id;
                } else {
                    $conditions['sync_status'] = $sync_status;
                }

                $result_order = $this->mobj->getResultByConditions('platform_order', $conditions, [], ['created_at' => 'asc'], 10);

                $success_orders = $failed_orders = array();

                if (count($result_order) > 0) {
                    /** Get Product Identity */
                    $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                    $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;

                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');

                    $mapping_data = $this->mapping->getMappedField($user_integration_id, null, $product_identity_obj_id);
                    $source_pi_field_match_by = $destination_pi_field_match_by = null;
                    if (!empty($mapping_data)) {
                        $source_pi_field_match_by =  $mapping_data['source_row_data'];
                        $destination_pi_field_match_by = $mapping_data['destination_row_data'];
                    }

                    /** Default NS Form */
                    $customFormId = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "transaction_forms", ['api_id']);

                    /** Get default NS Location */
                    $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "porder_location", ['api_id']);
                    $default_location_id = $default_location_id && $default_location_id->api_id ? $default_location_id->api_id : NULL;

                    /** Default Subsidiary */
                    $subsidiary = $this->mapping->getMappedDataByName($user_integration_id, null, "subsidiary", ['api_id']);
                    $subsidiary = $subsidiary ? $subsidiary->api_id : NULL;
                }

                foreach ($result_order as $row) {
                    $order_array = [];
                    //Get mapping fields
                    if (empty($source_pi_field_match_by) || empty($destination_pi_field_match_by)) {
                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                        $sync_error = 'Incorrect Field Mapping';
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                        continue;
                    }
                    // Check already synced platform order, if already synced then status update IGNORE & Continue
                    $alreadySyncedPlatformOrder = $this->mobj->getFirstResultByConditions('platform_order', [
                        'order_number' => $row->order_number, 'sync_status' => 'Synced', 'order_type' => $row->order_type, 'user_integration_id' => $user_integration_id
                    ]);
                    if ($alreadySyncedPlatformOrder) {
                        //      \Log::info('CreateOrdersByType - Netsuite', ['order_number' => $row->order_number, 'id' => $row->id]);
                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Ignore', 'order_updated_at' => date_create()], ['id' => $row->id]);
                        continue;
                    }

                    $attributes = $this->mobj->getFirstResultByConditions('platform_order_additional_information', [
                        'platform_order_id' => $row->id
                    ]);

                    $defaultCustomerEmail = '';
                    if (!empty($attributes) && $attributes->api_channel_id) {
                        $objId = $this->helper->getObjectId(PlatformObjectName::CHANNEL);
                        $sChannelObj = PlatformObjectData::where([
                            'api_id' => $attributes->api_channel_id, 'user_integration_id' => $user_integration_id,
                            'platform_id' => $source_platform_id, 'platform_object_id' => $objId
                        ])->first();


                        if ($sChannelObj) {
                            if ($type == 'sales_orders'  || $type == 'invoice_orders') {
                                $objId = $this->helper->getObjectId(PlatformObjectName::CUSTOMER_SOURCE_CHANNEL);
                                //'platform_workflow_rule_id' => $platform_workflow_rule_id,
                                $skipCustChannelMap = PlatformDataMapping::where([
                                    'user_integration_id' => $user_integration_id, 'source_row_id' => $sChannelObj->id, 'platform_object_id' => $objId
                                ])->first();
                                if ($skipCustChannelMap) {
                                    $defaultCustomerEmail = $skipCustChannelMap->custom_data;
                                }
                            }

                            $objId = $this->helper->getObjectId(PlatformObjectName::SO_CHANNEL);
                            $map = PlatformDataMapping::where(['platform_object_id' => $objId, 'user_integration_id' => $user_integration_id, 'source_row_id' => $sChannelObj->id])->first();

                            if ($map) {
                                $classification = PlatformObjectData::find($map->destination_row_id);
                                $order_array['classificationId'] = $classification->api_id;
                            }
                        }

                        if ($type == 'transfer_orders') {
                            /** Channel - Location  Mapping */
                            $netsuite_to_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "transfer_order_channel", ['api_id'], 'cross', $attributes->api_channel_id);
                            $netsuite_to_location_id = $netsuite_to_location_id && $netsuite_to_location_id->api_id ? $netsuite_to_location_id->api_id : '';
                            $order_array['to_location'] = $netsuite_to_location_id;
                        }
                    }
                    //Order customer details
                    $findCustomer = $this->mobj->getFirstResultByConditions(
                        'platform_customer',
                        [
                            'platform_id' => $source_platform_id, 'id' => $row->platform_customer_id,
                            'user_integration_id' => $user_integration_id
                        ]
                    );

                    if (empty($findCustomer->id)) {
                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                        $sync_error = 'Invalid Order customer';
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                        continue;
                    }


                    if (($type == 'sales_orders' || $type == 'invoice_orders') && $defaultCustomerEmail != '') {
                        $getCustomerMapping = $this->netsuiteSyncServices->getDefaultCustomerForChannel($service, $user_id, $defaultCustomerEmail, $user_integration_id);
                    } else {
                        $getCustomerMapping = (strtolower($row->order_type) == strtolower('PO')) ? $this->getCustomerMapping($service, $findCustomer, $row->id,  $user_workflow_rule_id, $platform_workflow_rule_id, $this->platformId, $object_id) : $this->getCustomerMapping($service, $findCustomer, $row->id, $user_workflow_rule_id, $platform_workflow_rule_id, $this->platformId, $object_id, 'customer');
                    }

                    $customerInternalId = $getCustomerMapping['api_customer_id'];
                    $destination_platform_customer_id = $getCustomerMapping['platform_customer_id'];



                    if ($customerInternalId  === false) {
                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                        $sync_error = 'Customer mapping error ' . $getCustomerMapping['error_msg'];
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                        continue;
                    }

                    if ($customerInternalId  === 0) {
                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                        $sync_error = 'Customer is not mapped ' . $getCustomerMapping['error_msg'];
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                        continue;
                    }


                    //order data

                    $order_array['s_order_api_id'] =  $row->api_order_id;
                    if (!empty($customerInternalId)) {
                        $order_array['customerInternalId'] = $customerInternalId;
                    }
                    if ($row->api_order_reference) {
                        $order_array['memo'] = $row->api_order_reference;
                    }
                    $order_array['orderDate'] = $row->order_date;
                    $order_array['items'] = [];
                    if ($row->delivery_date && $row->delivery_date != '') {
                        $order_array['deliveryDate'] = $row->delivery_date;
                    }


                    //get order line items
                    $totalDiscount = 0;
                    $totalShipping = 0;
                    $order_lines = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $row->id]);
                    $error_msg = 0;
                    $orderLineNsIdMap = [];

                    foreach ($order_lines as $order_line) {

                        if (($order_line->row_type == 'ITEM' && $order_line->sku) || ($order_line->row_type == 'DISCOUNT' && $order_line->sku)
                            || ($order_line->row_type == 'SHIPPING' && $order_line->sku)
                        ) {

                            //Order product details
                            $findProduct = $this->mobj->getFirstResultByConditions(
                                'platform_product',
                                [
                                    'platform_id' => $source_platform_id, 'api_product_id' => $order_line->api_product_id,
                                    'user_integration_id' => $user_integration_id
                                ]
                            );
                            if (empty($findProduct->id)) {
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $sync_error = 'Invalid Order product';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);
                                $error_msg = 1;
                                break;
                            }
                            $linked_product = $this->getProductMapping($service, $findProduct, $this->platformId, $source_pi_field_match_by, $destination_pi_field_match_by, 1, $customFieldProductName);
                            if ($linked_product === false) {
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                                $sync_error = 'Product mapping error';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);
                                $error_msg = 1;
                                break;
                            } else if ($linked_product === 0) {
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                                $sync_error = 'Product is not mapped ';
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);
                                $error_msg = 1;
                                break;
                            }
                            $productInternalId = $linked_product->api_product_id;

                            //Compare Default Expense Sku Id with order item sku id
                            if (($default_expense_sku_id == $order_line->sku) && $po_expense_ac_number) {
                                $order_array['expense'][] = ['account' => $po_expense_ac_number, 'amount' => $order_line->total + $order_line->total_tax, 'description' => $default_expense_sku_id . " - " . $order_line->product_name];
                                continue;
                            }

                            $taxCodeId = 0;
                            if (1) {
                                $sTaxCode = PlatformObjectData::where([
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $source_platform_id, 'api_id' => $order_line->taxes,
                                    'platform_object_id' => $this->helper->getObjectId(PlatformObjectName::TAX_CODE)
                                ])->first();

                                if ($sTaxCode) {
                                    $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type == 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id'], 'regular', $sTaxCode->id);
                                    if (!$taxCodeMap) {
                                        $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type != 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id'], 'regular', $sTaxCode->id);
                                    }
                                    if ($taxCodeMap) {
                                        $taxCode = PlatformObjectData::find($taxCodeMap->id);
                                        $taxCodeId = $taxCode->api_id;
                                    }
                                }

                                if ($taxCodeId == 0) {
                                    $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type == 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id']);
                                    if (!$taxCodeMap) {
                                        $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type != 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id']);
                                    }
                                    if ($taxCodeMap) {
                                        $taxCode = PlatformObjectData::find($taxCodeMap->id);
                                        $taxCodeId = $taxCode->api_id;
                                    }
                                }
                            }



                            $order_array['items'][] = [
                                'quantity' => $order_line->qty, 'internalId' => $productInternalId,
                                'price' => $order_line->unit_price, 'total' => $order_line->total + $order_line->total_tax,
                                'taxCode' => $taxCodeId, 'noTaxTotal' => $order_line->total
                            ];
                            $orderLineNsIdMap[$order_line->id] = $productInternalId;
                        } else {
                            if ($order_line->row_type == 'DISCOUNT') {
                                $object_id2 = $this->helper->getObjectId('default_discount_sku');
                            } else if ($order_line->row_type == 'SHIPPING') {
                                $object_id2 = $this->helper->getObjectId('default_shipping_sku');
                            } else {
                                $object_id2 = $this->helper->getObjectId('default_miscellaneous_sku');
                            }

                            $map = PlatformDataMapping::where(['platform_object_id' => $object_id2, 'user_integration_id' => $user_integration_id])->first();
                            if ($map) {
                                $products = $this->netsuiteApi->SearchNetsuiteProduct($service, 'product_name', $map->custom_data);
                                if (isset($products->record)) {
                                    foreach ($products->record as $product) {
                                        if (isset($product->internalId) && $product->itemId == $map->custom_data) {
                                            $taxCodeId = 0;
                                            //Temp
                                            if (1) {
                                                $sTaxCode = PlatformObjectData::where([
                                                    'user_integration_id' => $user_integration_id,
                                                    'platform_id' => $source_platform_id, 'api_id' => $order_line->taxes,
                                                    'platform_object_id' => $this->helper->getObjectId(PlatformObjectName::TAX_CODE)
                                                ])->first();

                                                if ($sTaxCode) {
                                                    $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type == 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id'], 'regular', $sTaxCode->id);
                                                    if (!$taxCodeMap) {
                                                        $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type != 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id'], 'regular', $sTaxCode->id);
                                                    }
                                                    if ($taxCodeMap) {
                                                        $taxCode = PlatformObjectData::find($taxCodeMap->id);
                                                        $taxCodeId = $taxCode->api_id;
                                                    }
                                                }

                                                if ($taxCodeId == 0) {
                                                    $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type == 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id']);
                                                    if (!$taxCodeMap) {
                                                        $taxCodeMap = $this->mapping->getMappedDataByName($user_integration_id, null, $type != 'purchase_orders' ? PlatformObjectName::PO_TAX_CODE_MAP : PlatformObjectName::SO_TAX_CODE_MAP, ['id']);
                                                    }
                                                    if ($taxCodeMap) {
                                                        $taxCode = PlatformObjectData::find($taxCodeMap->id);
                                                        $taxCodeId = $taxCode->api_id;
                                                    }
                                                }
                                            }
                                            $order_array['items'][] = [
                                                'quantity' => $order_line->qty, 'internalId' => $product->internalId,
                                                'price' => $order_line->unit_price, 'total' => $order_line->total + $order_line->total_tax,
                                                'taxCode' => $taxCodeId, 'noTaxTotal' => $order_line->total
                                            ];
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $order_array['discount'] = $totalDiscount;
                    $order_array['shippingPrice'] = $totalShipping;
                    //                    if($totalDiscount) {
                    //                        $map = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_discount", ['api_id']);
                    //                        $order_array['discountItemId'] = $map ? $map->api_id : 0;
                    //                    }

                    //Shipping method
                    if ($row->shipping_method && $row->shipping_method != '') {
                        $objId = $this->helper->getObjectId(PlatformObjectName::SHIPPING_METHOD);
                        $sShippingMethod = PlatformObjectData::where(['api_id' => strval($row->shipping_method), 'platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $objId])->first();
                        if ($sShippingMethod) {
                            $dShippingMethod = NetsuiteServices::GetNetsuiteObjectByName($sShippingMethod->name, $objId, $user_integration_id);
                            if ($dShippingMethod) {
                                $order_array['shippingItemId'] = $dShippingMethod->api_id;
                            }
                        }
                    }


                    if (!$error_msg) {

                        $order_array['shippingAddress'] = $order_array['billingAddress'] = [];
                        $shipping_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type' => 'shipping', 'platform_order_id' => $row->id]);

                        if (!empty($shipping_address)) {
                            $order_array['shippingAddress']['zip'] = $shipping_address->postal_code;
                            $order_array['shippingAddress']['city'] = $shipping_address->city;
                            $order_array['shippingAddress']['street'] = $shipping_address->address1 . ' ' . $shipping_address->address2;
                            $order_array['shippingAddress']['state'] = $shipping_address->state;
                            $order_array['shippingAddress']['country'] = $shipping_address->country;
                        }

                        $billing_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['address_type' => 'billing', 'platform_order_id' => $row->id]);

                        if (!empty($billing_address)) {
                            $order_array['billingAddress']['zip'] = $billing_address->postal_code;
                            $order_array['billingAddress']['city'] = $billing_address->city;
                            $order_array['billingAddress']['street'] = $billing_address->address1 . ' ' . $billing_address->address2;
                            $order_array['billingAddress']['state'] = $billing_address->state;
                            $order_array['billingAddress']['country'] = $billing_address->country;
                        }


                        /** WH - Location  Mapping */
                        $netsuite_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $row->warehouse_id);
                        $default_location_id = $netsuite_location_id && $netsuite_location_id->api_id ? $netsuite_location_id->api_id : $default_location_id;


                        if (!empty($default_location_id)) {
                            $order_array['location'] = $default_location_id;
                        }


                        if (!empty($customFormId)) {
                            $order_array['customForm'] = $customFormId->api_id;
                        }

                        if (!empty($subsidiary)) {
                            $order_array['subsidiary'] = $subsidiary;
                        }

                        $orderInvoice = PlatformInvoice::where(['platform_order_id' => $row->id])->first();


                        $order_array['custom_fields'] = [];

                        /** Custom Fields */
                        //get custom field values
                        $cus_values = $this->mobj->getResultByConditions('platform_custom_field_values', [
                            'record_id' => $row->id,
                            'platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id
                        ], ['field_value', 'platform_field_id']);

                        foreach ($cus_values as $cus_value) {
                            $bpFieldObj = PlatformField::find($cus_value->platform_field_id);
                            if ($bpFieldObj && $bpFieldObj->custom_field_id == 140) {
                                $objId = $this->helper->getObjectId(PlatformObjectName::PO_LINE);
                                $sObj = NetsuiteServices::GetNetsuiteObjectByName($cus_value->field_value, $objId, $user_integration_id);

                                if ($sObj) {
                                    $order_array['landedCostTemplate'] = $sObj->api_id;
                                }
                            }
                            if ($bpFieldObj && $bpFieldObj->custom_field_id == 44) {
                                $order_array['shipDate'] = $cus_value->field_value;
                            }

                            $getMappedField = $this->mapping->getMappedField($user_integration_id, null, $type == 'invoice_orders' ? $this->helper->getObjectId('sales_order') : $object_id, [], $cus_value->platform_field_id);

                            if (!empty($getMappedField['destination_field_name'])) {
                                $cus_field_value = $cus_value->field_value;

                                if ($getMappedField['source_custom_field_type'] == CustomFieldType::BOOLEAN) {
                                    $cus_field_value = (bool) $cus_field_value;
                                }

                                $order_array['custom_fields'][] = [
                                    'sciptId' => $getMappedField['destination_field_name'],
                                    'internalId' => $getMappedField['destination_custom_field_id'], 'value' => $cus_field_value,
                                    'fieldType' => $getMappedField['source_custom_field_type']
                                ];
                            }
                        }

                        $userCustomFields = PlatformField::where(['user_integration_id' => $user_integration_id, 'field_type' => 'custom', 'platform_id' => $this->platformId])->get();
                        //                        $order_array['custom_fields'] = [];
                        foreach ($userCustomFields as $customField) {
                            if ($customField->custom_field_id == '1567') {
                                array_push($order_array['custom_fields'], [
                                    'sciptId' => $customField->name,
                                    'internalId' => $customField->custom_field_id, 'value' => $row->api_order_id,
                                    'fieldType' => $customField->custom_field_type
                                ]);
                            } else if ($customField->custom_field_id == '1568') {
                                array_push($order_array['custom_fields'], [
                                    'sciptId' => $customField->name,
                                    'internalId' => $customField->custom_field_id, 'value' => $row->api_order_reference,
                                    'fieldType' => $customField->custom_field_type
                                ]);
                            } else if ($customField->custom_field_id == '2077') {
                                array_push($order_array['custom_fields'], [
                                    'sciptId' => $customField->name,
                                    'internalId' => $customField->custom_field_id, 'value' => $row->order_status,
                                    'fieldType' => $customField->custom_field_type
                                ]);
                            } else if ($customField->custom_field_id == '2078') {
                                array_push($order_array['custom_fields'], [
                                    'sciptId' => $customField->name,
                                    'internalId' => $customField->custom_field_id, 'value' => $row->order_status,
                                    'fieldType' => $customField->custom_field_type
                                ]);
                            } else if ($customField->custom_field_id == '1568') {
                                array_push($order_array['custom_fields'], [
                                    'sciptId' => $customField->name,
                                    'internalId' => $customField->custom_field_id, 'value' => $row->api_order_reference,
                                    'fieldType' => $customField->custom_field_type
                                ]);
                            } else if ($customField->custom_field_id == '2127') {
                                if ($orderInvoice) {
                                    array_push($order_array['custom_fields'], [
                                        'sciptId' => $customField->name,
                                        'internalId' => $customField->custom_field_id, 'value' => $orderInvoice->api_invoice_id,
                                        'fieldType' => $customField->custom_field_type
                                    ]);
                                }
                            }
                        }


                        if (!count($order_array['items'])) {
                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                            $sync_error = 'Products missing ';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                            $error_msg = 1;
                            break;
                        }

                        if (!empty($attributes)) {
                            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $source_platform_id, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

                            /** Map Employee */

                            $getEmployeeMapping = $this->getOrderAssignedToMapping($service, $attributes->api_owner_id, $user_id, $user_integration_id, $source_platform_id);


                            $customerInternalId = $getEmployeeMapping['api_customer_id'];
                            $destination_platform_customer_id = $getEmployeeMapping['platform_customer_id'];



                            /*  if(  $customerInternalId  === false)
                              {
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                    $sync_error = 'Employee mapping error '.$getEmployeeMapping['error_msg'];
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                                    continue;

                              }

                               if(  $customerInternalId  === 0)
                              {
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                    $sync_error = 'Employee is not mapped '.$getEmployeeMapping['error_msg'];
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                                    continue;

                              }*/

                            if (!empty($customerInternalId)) {
                                $order_array['employeeInternalId'] = $customerInternalId;
                            }

                            /** Map Employee */
                        }


                        //create netsuite purchase order api
                        if ($type == 'purchase_orders') {
                            $response =   $this->netsuiteApi->CreatePurchaseOrderInNetsuite($service, $order_array);
                        } else if ($type == 'sales_orders') {
                            $response =  $this->netsuiteApi->CreateSalesOrder($service, $order_array);
                        } else if ($type == 'invoice_orders') {
                            $order_array['orderDate'] = ($row->tax_date) ? $row->tax_date : $row->order_date;
                            $response = $this->netsuiteApi->CreateInvoice($service, $order_array);
                        } else {
                            $order_array['called_from'] = 'TO-SO';
                            $response =  $this->netsuiteApi->CreateInventoryTransfer($service, $order_array);
                        }


                        if ($response === false || $response == 0 || isset($response['error'])) {
                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date_create()], ['id' => $row->id]);
                            $sync_error = 'Order sync failed ' . @$response['error'];
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);

                            $error_msg = 1;
                            continue;
                        } else {
                            // Create Deposit for Sales order if a transaction exists from the source platform
                            //                            $transactions = $this->netsuiteSyncServices->getTransactionsForOrder($row->id);
                            //                            if(count($transactions)) {
                            //                                $paymentObjId = $this->helper->getObjectId('payment');
                            //                                foreach ($transactions as $transaction) {
                            //
                            //                                    $paymentDetail = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_code' => $transaction->transaction_method, 'platform_object_id' => $paymentObjId]);
                            //                                    if($paymentDetail) {
                            //                                        $map = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_payment", ['api_id'], 'regular', $paymentDetail->api_id);
                            //                                        if(!$map) {
                            //                                            $map = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_payment", ['api_id']);
                            //                                        }
                            //                                        $res = $this->netsuiteSyncServices->CreateCustomerDeposit($service, $transaction, $response,$map ? $map->api_id : 0, $type);
                            //                                    }
                            //                                }
                            //                            }

                            //store netsuite order details into db and link with parent order
                            $arr_po_order = array();
                            $arr_po_order['user_id'] = $user_id;
                            $arr_po_order['user_workflow_rule_id'] = $user_workflow_rule_id;
                            $arr_po_order['platform_id'] = $this->platformId;
                            $arr_po_order['order_date'] = $row->order_date;
                            $arr_po_order['user_integration_id'] = $user_integration_id;
                            $arr_po_order['order_type'] = $row->order_type;
                            $arr_po_order['api_order_id'] = $response;
                            $arr_po_order['platform_customer_id'] = $destination_platform_customer_id;
                            $arr_po_order['customer_email'] = $row->customer_email;
                            $arr_po_order['order_number'] = $response;
                            $arr_po_order['api_order_reference'] = $row->api_order_reference;
                            $arr_po_order['total_amount'] = $row->total_amount;
                            $arr_po_order['net_amount'] = $row->net_amount;
                            $arr_po_order['sync_status'] = 'Ready';
                            $arr_po_order['linked_id'] = $row->id; //parent platform order row id

                            $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_po_order);

                            foreach ($order_lines as $key => $order_line) {
                                if (isset($orderLineNsIdMap[$order_line->id]) && $orderLineNsIdMap[$order_line->id]) {
                                    $newLine = new PlatformOrderLine();
                                    $newLine->platform_order_id = $linked_platform_order_id;
                                    $newLine->api_order_line_id = $key + 1;
                                    $newLine->api_product_id = $orderLineNsIdMap[$order_line->id];
                                    $newLine->product_name = $order_line->product_name;
                                    $newLine->ean = $order_line->ean;
                                    $newLine->sku = $order_line->sku;
                                    $newLine->qty = $order_line->qty;
                                    $newLine->subtotal = $order_line->subtotal;
                                    $newLine->subtotal_tax = $order_line->subtotal_tax;
                                    $newLine->total = $order_line->total;
                                    $newLine->total_tax = $order_line->total_tax;
                                    $newLine->unit_price = $order_line->unit_price;
                                    $newLine->row_type = $order_line->row_type;
                                    $newLine->is_deleted = 0;
                                    $newLine->linked_id = $order_line->id;
                                    $newLine->save();
                                }
                            }

                            //update acknowledge
                            $update_arr = ['sync_status' => 'Synced', 'linked_id' => $linked_platform_order_id, 'order_updated_at' => date_create()];
                            //update destination order record
                            $this->mobj->makeUpdate('platform_order', $update_arr, ['id' => $row->id]);
                            //sync logger
                            $sync_error = 'Order synced successfully';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $row->id, $sync_error);
                        }
                    }
                }
            }

            return $sync_error;
        } catch (\Exception $e) {
            throw new \Exception('Error: ' . $e->getMessage());
        }
    }

    public function GetLinkedOrder($platform_order_id, $user_id, $user_integration_id, $source_platform_id, $destination_platform_id, $select = [])
    {
        $findRecord = $this->mobj->getFirstResultByConditions(
            'platform_order',
            [
                'platform_id' => $source_platform_id, 'id' => $platform_order_id,
                'user_integration_id' => $user_integration_id
            ],
            ['linked_id']
        );

        if (empty($findRecord->linked_id)) {
            return false;
        }

        $findRecord = $this->mobj->getFirstResultByConditions(
            'platform_order',
            [
                'platform_id' => $destination_platform_id, 'id' => $findRecord->linked_id,
                'user_integration_id' => $user_integration_id
            ],
            $select
        );

        return $findRecord;
    }

    public function GetOrderShipmentStatus($platform_order_id, $user_id, $user_integration_id, $source_platform_id)
    {
        $totalOrderQty =  DB::table('platform_order_line')->where('platform_order_id', $platform_order_id)->whereNotNull('api_product_id')->whereNotNull('sku')->sum('qty');

        $conditions = [
            'platform_id' => $source_platform_id,
            'user_integration_id' => $user_integration_id,
            'sync_status' => 'Synced',
            'platform_order_id' => $platform_order_id
        ];
        $shipments_ids =  DB::table('platform_order_shipments')->where($conditions)->select('id')->pluck('id')->toArray();

        $totalShippedQty = DB::table('platform_order_shipment_lines')->whereIn('platform_order_shipment_id', $shipments_ids)->sum('quantity');

        if ($totalShippedQty < $totalOrderQty) {
            return 'Partial';
        }

        return 'Synced';
    }

    public function CreateItemFulfillment($user_id, $user_integration_id,  $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_name, $sync_status,  $record_id, $type = 'sales_order')
    {
        $return_response = true;
        $sync_error = null;
        $syncExecution = true;
        $limit = 10;

        try {
            $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
            $object_id = $this->helper->getObjectId('sales_order_shipment');
            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
            if ($service) {
                $conditions =
                    [
                        'user_id' => $user_id,
                        'platform_id' => $source_platform_id,
                        'user_integration_id' => $user_integration_id,
                    ];
                if ($record_id) {
                    $conditions['id'] = $record_id;
                    $query = PlatformOrder::with('shipmentsFailedAndReady')->where($conditions);
                } else {
                    $query = PlatformOrder::with('shipmentsFailedAndReady')->where($conditions)->where('attempt', '<=', 4)->whereIn('shipment_status', ['Failed', $sync_status]);
                }

                $result = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                $default_netsuite_location_id = $default_ns_shipping_method_id = $source_pi_field_match_by = $destination_pi_field_match_by = '';

                if (count($result) > 0) {
                    /** Get Product Identity */
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');

                    /** Get default NS Location */
                    $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "porder_location", ['api_id']);
                    if (!$default_location_id) {
                        //If default location not found by object name porder_location now search by sorder_location
                        $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_location", ['api_id']);
                    }

                    $default_netsuite_location_id = $default_location_id && isset($default_location_id->api_id) ? $default_location_id->api_id : NULL;
                    //  Get track info custom field
                    $customTrackInfoField = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_trackinginfo", ['custom_data']);

                    //  Get track url custom field
                    if (isset($customTrackInfoField->custom_data)) {
                        $customTrackInfoField = $customTrackInfoField->custom_data;
                    }
                    $customTrackUrlField = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_trackingurl", ['custom_data']);
                    if (isset($customTrackUrlField->custom_data)) {
                        $customTrackUrlField = $customTrackUrlField->custom_data;
                    }

                    /** Get default NS shipping method id */
                    $default_shipping_method_id = $this->mapping->getMappedDataByName($user_integration_id, null, "default_sorder_shipping_method_ns", ['api_id'], 'default');
                    $default_ns_shipping_method_id = $default_shipping_method_id && isset($default_shipping_method_id->api_id) ? $default_shipping_method_id->api_id : NULL;

                    $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                    $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;

                    /** Get product identity field */
                    $mapping_data = $this->mapping->getMappedField($user_integration_id, null, $product_identity_obj_id);

                    if (!empty($mapping_data)) {
                        if ($mapping_data['destination_platform_id'] == $this->platformId) {
                            $destination_pi_field_match_by = $mapping_data['destination_row_data'];
                            $source_pi_field_match_by = $mapping_data['source_row_data'];
                        } else {
                            $destination_pi_field_match_by = $mapping_data['source_row_data'];
                            $source_pi_field_match_by = $mapping_data['destination_row_data'];
                        }
                    }

                    foreach ($result as $row) {
                        $error_msg = 0;
                        $source_order_status = null;
                        if ($row->order_type == 'SO') {
                            /* Loop for shipment entries */
                            if ((int)$row->attempt <= 4 || $record_id) {
                                $itemCount = PlatformOrderShipment::where('platform_order_id', $row->id)->count();
                                if ($itemCount) {
                                    if (isset($row->shipmentsFailedAndReady) && count($row->shipmentsFailedAndReady)) {

                                        foreach ($row->shipmentsFailedAndReady as $shipment) {
                                            $linked_destination_order = $this->GetLinkedOrder($shipment->platform_order_id, $user_id, $user_integration_id, $source_platform_id, $this->platformId, ['api_order_id', 'id']);

                                            if (!$linked_destination_order) {
                                                continue;
                                            }
                                            $linked_destination_order_id = $linked_destination_order->api_order_id;

                                            $data_array = ['order_internalId' => $linked_destination_order_id, 'rows' => []];

                                            /** one to one WH - Location  Mapping */
                                            $one_to_one_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $shipment->warehouse_id);

                                            $netsuite_location_id = $one_to_one_location_id && isset($one_to_one_location_id->api_id) ? $one_to_one_location_id->api_id : $default_netsuite_location_id;

                                            /** One to one Shipping Method  Mapping */
                                            $default_shipping_method_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_shipping_method", ['api_id'], 'regular', $shipment->shipping_method, 'single', 'destination');


                                            $netsuite_shipping_method_id = $default_shipping_method_id &&  isset($default_shipping_method_id->api_id) ? $default_shipping_method_id->api_id : $default_ns_shipping_method_id;

                                            $data_array['shipping_method_id'] = $netsuite_shipping_method_id;

                                            /** Get Order Shipment Rows */
                                            $shipment_lines = $this->mobj->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $shipment->id]);

                                            $noProductFound = false;

                                            if ($shipment_lines) {
                                                $netsuite_location_array = [];

                                                foreach ($shipment_lines as $shipment_line) {
                                                    //Order product details
                                                    $cast_to_array = (array)$shipment_line;


                                                    if (isset($cast_to_array[$source_pi_field_match_by])) {

                                                        $source_product = $shipment_line;
                                                        $source_product->user_id = $user_id;
                                                        $source_product->user_integration_id = $user_integration_id;
                                                    } else {
                                                        $source_product = $this->mobj->getFirstResultByConditions(
                                                            'platform_product',
                                                            [
                                                                'user_id' => $user_id, 'platform_id' => $source_platform_id,
                                                                'user_integration_id' => $user_integration_id,
                                                                'api_product_id' => $shipment_line->product_id
                                                            ]
                                                        );
                                                    }


                                                    if (empty($source_product->id) || empty($source_pi_field_match_by) || empty($destination_pi_field_match_by)) {
                                                        continue;
                                                    }

                                                    $match_string = $source_product->$source_pi_field_match_by;


                                                    if (empty($match_string)) {
                                                        continue;
                                                    }

                                                    $destinationProduct =  $this->getProductMapping($service, $source_product, $this->platformId, $source_pi_field_match_by, $destination_pi_field_match_by, 1, $customFieldProductName);

                                                    if ($destinationProduct) {
                                                        if (!$one_to_one_location_id) {
                                                            //If one to one location not found by object name order_warehouse now search by sorder_location
                                                            if (isset($netsuite_location_array[$netsuite_location_id])) {
                                                                $netsuite_location_id = $netsuite_location_array[$netsuite_location_id];
                                                            } else {
                                                                $one_to_one_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_location", ['api_id'], 'regular', $shipment_line->location_id, 'single', 'destination');
                                                                $netsuite_location_id = $one_to_one_location_id && isset($one_to_one_location_id->api_id) ? $one_to_one_location_id->api_id : $default_netsuite_location_id;
                                                                $netsuite_location_array[$netsuite_location_id] = $netsuite_location_id;
                                                            }
                                                        }
                                                        $item_row = [];
                                                        $item_row['qty'] = $shipment_line->quantity;
                                                        $item_row['location_internalId'] = $netsuite_location_id;
                                                        $item_row['itemInternalId'] = $destinationProduct->api_product_id;

                                                        $data_array['rows'][] = $item_row;
                                                    } else {
                                                        $noProductFound = true;
                                                        $sync_error = " No machting product line found in Netsuite";
                                                    }
                                                }
                                            } else {
                                                $syncExecution = false;
                                                $sync_error = " No shipment lines found";
                                            }
                                            // \Log::channel('webhook')->info("NSSYNC -" . $user_id . " Integration " . $user_integration_id . " Response: " . print_r($data_array, true) . " Created Date : " . date('Y-m-d H:i:s'));
                                            /* If you want to update custom field by their internal id (Only String Fields-tracking_info,tracking_url) */
                                            if ($customTrackUrlField) {
                                                $data_array['customFields'][] = ['internalId' => $customTrackInfoField, 'value' => $shipment->tracking_info];
                                            }
                                            if ($customTrackUrlField) {
                                                $data_array['customFields'][] = ['internalId' => $customTrackUrlField, 'value' => $shipment->tracking_url];
                                            }

                                            //create netsuite item fulfillment
                                            if ($syncExecution && $noProductFound == false) {
                                                $response = $this->netsuiteApi->CreateItemFulfillment($service, $data_array);
                                            } else {
                                                $response = false;
                                            }

                                            $fileLog = date('d-m-Y') . "_NS_fullfil_";
                                            if ($response === false || $response == 0 || isset($response['error'])) {
                                                if (isset($response['error'])) {
                                                    $api_error = $response['error'];
                                                } else {
                                                    $api_error = $sync_error;
                                                }
                                                $word = 'You do not have permissions'; //if this kind of error found skip the order to sync
                                                if (strpos($api_error, $word) !== false) {
                                                    continue;
                                                }

                                                if (isset($response['error']) && strpos($response['error'], 'the order is already closed') !== false) {
                                                    $shipment->sync_status = 'Synced';
                                                    $error_msg = 0;
                                                    $source_order_status = "Synced";
                                                    $sync_error = '';
                                                } else {
                                                    $shipment->sync_status = 'Failed';
                                                    $error_msg = 1;
                                                    $source_order_status = "Failed";
                                                    $sync_error = 'Shipment sync failed. ' . $api_error;
                                                }
                                                $shipment->save();

                                                //\Storage::disk('local')->append($fileLog, 'User Integration: ' . $user_integration_id . ' Payload: ' . json_encode($data_array) . ' Response: ' .  $api_error . now()->format('d-m-Y H:i:s'));


                                                //continue;
                                            } else {
                                                //store netsuite shipment details into db and link with parent shipment record
                                                $arr = array();
                                                $arr['user_id'] = $user_id;
                                                $arr['platform_id'] = $this->platformId;
                                                $arr['user_integration_id'] = $user_integration_id;
                                                $arr['sync_status'] = 'Ready';
                                                $arr['order_id'] = $linked_destination_order_id;
                                                $arr['warehouse_id'] = $netsuite_location_id;
                                                $arr['shipping_method'] = $netsuite_shipping_method_id;
                                                $arr['platform_order_id'] = $linked_destination_order->id;
                                                $arr['linked_id'] = $shipment->id;
                                                $arr['shipment_id'] = $response;

                                                $this->mobj->makeInsertGetId('platform_order_shipments', $arr);

                                                //update acknowledge
                                                $shipment->sync_status = 'Synced';
                                                $shipment->save();



                                                //update source order record
                                                $source_order_status = $this->GetOrderShipmentStatus($shipment->platform_order_id, $user_id, $user_integration_id, $source_platform_id);
                                                // $row->shipment_status = $source_order_status;
                                                // $row->save();
                                                // $this->mobj->makeUpdate('platform_order', ['shipment_status' => $source_order_status], ['id' => $shipment->platform_order_id]);
                                                /* Prepare to update tracking information */
                                                $orderData = [];
                                                $acknowledge = null;
                                                $counterToStopUpdateTrackingInfo = DB::table('platform_order_shipment_lines')->where(['platform_order_shipment_id' => $shipment->id, 'sync_status' => "Synced"])->count();

                                                if ($counterToStopUpdateTrackingInfo == 0) {

                                                    if ($customTrackInfoField || $customTrackUrlField) {
                                                        $orderData['internalId'] =  $linked_destination_order_id;
                                                        if ($customTrackUrlField) {
                                                            $orderData['custom_fields'][] = ['fieldType' => "string", 'internalId' => $customTrackInfoField, 'value' => $shipment->tracking_info];
                                                        }
                                                        if ($customTrackUrlField) {
                                                            $orderData['custom_fields'][] = ['fieldType' => "string", 'internalId' => $customTrackUrlField, 'value' => $shipment->tracking_url];
                                                        }
                                                        $response =  $this->netsuiteApi->UpdateSalesOrder($service, $orderData);
                                                        if ($response === false || $response == 0 || isset($response['error'])) {
                                                            if (isset($response['error'])) {
                                                                $api_error = $response['error'];
                                                            } else {
                                                                $api_error = "tracking information or url not updated in Netsuite";
                                                            }
                                                            $acknowledge =  $api_error;
                                                        }
                                                    }
                                                }


                                                //sync logger
                                                if ($acknowledge) {
                                                    $orderData = null; //array free memory
                                                    $sync_error = 'Shipment synced successfully but ' . $acknowledge;
                                                } else {
                                                    $sync_error = 'Shipment synced successfully';
                                                }
                                                //  \Storage::disk('local')->append($fileLog, 'User Integration: '.$user_integration_id .' Sync-Response: '.  $api_error. now()->format('d-m-Y H:i:s'));
                                                //  $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $row->id, $sync_error);
                                                //$return_response = $sync_error;
                                            }
                                        }
                                        if ($error_msg) {
                                            $row->shipment_status = $source_order_status;
                                            $row->attempt = $row->attempt + 1;
                                            if ($row->attempt > 5) {
                                                $sync_error = $sync_error . ' | You have reached maximum attempt to sync the record automatically. Try manual resync.';
                                            }
                                        } else {
                                            $row->shipment_status = $source_order_status;
                                        }
                                        $row->save();
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);
                                        $return_response = $sync_error;
                                    } else {
                                        $sync_error = null;
                                        $row->shipment_status = "Synced";
                                        $row->save();
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);
                                        $return_response = $sync_error;
                                    }
                                } else {
                                    $sync_error = "No shipment line item found from source platform";
                                    $row->shipment_status = "Failed";
                                    $row->attempt = $row->attempt + 1;
                                    $row->save();
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->id, $sync_error);
                                    $return_response = $sync_error;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function CreateItemFulfillmentBK($user_id, $user_integration_id,  $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_name, $sync_status,  $record_id, $type = 'sales_order')
    {
        $return_response = true;
        $sync_error = false;
        $syncExecution = true;
        $limit = 10;
        $error_msg = 0;
        try {
            $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
            $object_id = $this->helper->getObjectId('sales_order_shipment');
            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
            if ($service) {
                $conditions =
                    [
                        'user_id' => $user_id,
                        'platform_id' => $source_platform_id,
                        'user_integration_id' => $user_integration_id
                    ];

                if ($record_id) {
                    $conditions['platform_order_id'] = $record_id;
                    $query = PlatformOrderShipment::with('platformOrder')->where($conditions)->whereIn('sync_status', [$sync_status, 'Failed']);
                } else {
                    $conditions['sync_status'] = $sync_status;
                    $query = PlatformOrderShipment::with('platformOrderShipmentStatusReady')->where($conditions)->whereIn('sync_status', [$sync_status, 'Failed']);
                }

                $result = $query->orderBy('id', 'ASC')->take($limit)->get();

                $default_netsuite_location_id = $default_ns_shipping_method_id = $source_pi_field_match_by = $destination_pi_field_match_by = '';

                if (count($result) > 0) {
                    /** Get Product Identity */
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');

                    /** Get default NS Location */
                    $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "porder_location", ['api_id']);
                    if (!$default_location_id) {
                        //If default location not found by object name porder_location now search by sorder_location
                        $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_location", ['api_id']);
                    }

                    $default_netsuite_location_id = $default_location_id && isset($default_location_id->api_id) ? $default_location_id->api_id : NULL;
                    //  Get track info custom field
                    $customTrackInfoField = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_trackinginfo", ['custom_data']);

                    //  Get track url custom field
                    if (isset($customTrackInfoField->custom_data)) {
                        $customTrackInfoField = $customTrackInfoField->custom_data;
                    }
                    $customTrackUrlField = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_trackingurl", ['custom_data']);
                    if (isset($customTrackUrlField->custom_data)) {
                        $customTrackUrlField = $customTrackUrlField->custom_data;
                    }

                    /** Get default NS shipping method id */
                    $default_shipping_method_id = $this->mapping->getMappedDataByName($user_integration_id, null, "default_sorder_shipping_method_ns", ['api_id'], 'default');
                    $default_ns_shipping_method_id = $default_shipping_method_id && isset($default_shipping_method_id->api_id) ? $default_shipping_method_id->api_id : NULL;

                    $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                    $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;

                    /** Get product identity field */
                    $mapping_data = $this->mapping->getMappedField($user_integration_id, null, $product_identity_obj_id);

                    if (!empty($mapping_data)) {
                        if ($mapping_data['destination_platform_id'] == $this->platformId) {
                            $destination_pi_field_match_by = $mapping_data['destination_row_data'];
                            $source_pi_field_match_by = $mapping_data['source_row_data'];
                        } else {
                            $destination_pi_field_match_by = $mapping_data['source_row_data'];
                            $source_pi_field_match_by = $mapping_data['destination_row_data'];
                        }
                    }

                    foreach ($result as $row) {

                        if ($row->platformOrder && $row->platformOrder->order_type == 'SO') {
                            $linked_destination_order = $this->GetLinkedOrder($row->platform_order_id, $user_id, $user_integration_id, $source_platform_id, $this->platformId, ['api_order_id', 'id']);

                            if (!$linked_destination_order) {
                                continue;
                            }
                            $linked_destination_order_id = $linked_destination_order->api_order_id;

                            $data_array = ['order_internalId' => $linked_destination_order_id, 'rows' => []];

                            /** one to one WH - Location  Mapping */
                            $one_to_one_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $row->warehouse_id);

                            $netsuite_location_id = $one_to_one_location_id && isset($one_to_one_location_id->api_id) ? $one_to_one_location_id->api_id : $default_netsuite_location_id;

                            /** One to one Shipping Method  Mapping */
                            $default_shipping_method_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_shipping_method", ['api_id'], 'regular', $row->shipping_method, 'single', 'destination');


                            $netsuite_shipping_method_id = $default_shipping_method_id &&  isset($default_shipping_method_id->api_id) ? $default_shipping_method_id->api_id : $default_ns_shipping_method_id;

                            $data_array['shipping_method_id'] = $netsuite_shipping_method_id;

                            /** Get Order Shipment Rows */
                            $shipment_lines = $this->mobj->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $row->id]);

                            $noProductFound = false;

                            if ($shipment_lines) {
                                $netsuite_location_array = [];

                                foreach ($shipment_lines as $shipment_line) {
                                    //Order product details
                                    $cast_to_array = (array)$shipment_line;
                                    if (isset($cast_to_array[$source_pi_field_match_by])) {

                                        $source_product = $shipment_line;
                                        $source_product->user_id = $user_id;
                                        $source_product->user_integration_id = $user_integration_id;
                                    } else {
                                        $source_product = $this->mobj->getFirstResultByConditions(
                                            'platform_product',
                                            [
                                                'platform_id' => $source_platform_id,
                                                'user_integration_id' => $user_integration_id,
                                                'api_product_id' => $shipment_line->product_id
                                            ]
                                        );
                                    }


                                    if (empty($source_product->id) || empty($source_pi_field_match_by) || empty($destination_pi_field_match_by)) {
                                        continue;
                                    }

                                    $match_string = $source_product->$source_pi_field_match_by;


                                    if (empty($match_string)) {
                                        continue;
                                    }

                                    $destinationProduct =  $this->getProductMapping($service, $source_product, $this->platformId, $source_pi_field_match_by, $destination_pi_field_match_by, 1, $customFieldProductName);

                                    if ($destinationProduct) {
                                        if (!$one_to_one_location_id) {
                                            //If one to one location not found by object name order_warehouse now search by sorder_location
                                            if (isset($netsuite_location_array[$netsuite_location_id])) {
                                                $netsuite_location_id = $netsuite_location_array[$netsuite_location_id];
                                            } else {
                                                $one_to_one_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_location", ['api_id'], 'regular', $shipment_line->location_id, 'single', 'destination');
                                                $netsuite_location_id = $one_to_one_location_id && isset($one_to_one_location_id->api_id) ? $one_to_one_location_id->api_id : $default_netsuite_location_id;
                                                $netsuite_location_array[$netsuite_location_id] = $netsuite_location_id;
                                            }
                                        }
                                        $item_row = [];
                                        $item_row['qty'] = $shipment_line->quantity;
                                        $item_row['location_internalId'] = $netsuite_location_id;
                                        $item_row['itemInternalId'] = $destinationProduct->api_product_id;

                                        $data_array['rows'][] = $item_row;
                                    } else {
                                        $noProductFound = true;
                                        $sync_error = " No shipment lines found in Netsuite";
                                    }
                                }
                            } else {
                                $syncExecution = false;
                                $sync_error = " No shipment lines found";
                            }
                            // \Log::channel('webhook')->info("NSSYNC -" . $user_id . " Integration " . $user_integration_id . " Response: " . print_r($data_array, true) . " Created Date : " . date('Y-m-d H:i:s'));
                            /* If you want to update custom field by their internal id (Only String Fields-tracking_info,tracking_url) */
                            if ($customTrackUrlField) {
                                $data_array['customFields'][] = ['internalId' => $customTrackInfoField, 'value' => $row->tracking_info];
                            }
                            if ($customTrackUrlField) {
                                $data_array['customFields'][] = ['internalId' => $customTrackUrlField, 'value' => $row->tracking_url];
                            }
                            //create netsuite item fulfillment
                            if ($syncExecution && $noProductFound == false) {
                                $response = $this->netsuiteApi->CreateItemFulfillment($service, $data_array);
                            } else {
                                $response = false;
                            }
                            if ($response === false || $response == 0 || isset($response['error'])) {
                                // $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $row->sync_status = 'Failed';
                                $row->save();
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $row->platform_order_id]);
                                if (isset($response['error'])) {
                                    $api_error = $response['error'];
                                } else {
                                    $api_error = $sync_error;
                                }

                                $sync_error = 'Shipment sync failed.' . $api_error;

                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $row->platformOrder->id, $sync_error);
                                $return_response = $sync_error;
                                $error_msg = 1;
                                //continue;
                            } else {
                                //store netsuite shipment details into db and link with parent shipment record
                                $arr = array();
                                $arr['user_id'] = $user_id;
                                $arr['platform_id'] = $this->platformId;
                                $arr['user_integration_id'] = $user_integration_id;
                                $arr['sync_status'] = 'Ready';
                                $arr['order_id'] = $linked_destination_order_id;
                                $arr['warehouse_id'] = $netsuite_location_id;
                                $arr['shipping_method'] = $netsuite_shipping_method_id;
                                $arr['platform_order_id'] = $linked_destination_order->id;
                                $arr['shipment_id'] = $response;

                                $this->mobj->makeInsertGetId('platform_order_shipments', $arr);

                                //update acknowledge

                                $row->sync_status = 'Synced';
                                $row->save();


                                //update source order record
                                $source_order_status = $this->GetOrderShipmentStatus($row->platform_order_id, $user_id, $user_integration_id, $source_platform_id);
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => $source_order_status], ['id' => $row->platform_order_id]);
                                /* Prepare to update tracking information */
                                $orderData = [];
                                $acknowledge = null;
                                $counterToStopUpdateTrackingInfo = DB::table('platform_order_shipment_lines')->where(['platform_order_shipment_id' => $row->id, 'sync_status' => "Synced"])->count();

                                if ($counterToStopUpdateTrackingInfo == 0) {

                                    if ($customTrackInfoField || $customTrackUrlField) {
                                        $orderData['internalId'] =  $linked_destination_order_id;
                                        if ($customTrackUrlField) {
                                            $orderData['custom_fields'][] = ['fieldType' => "string", 'internalId' => $customTrackInfoField, 'value' => $row->tracking_info];
                                        }
                                        if ($customTrackUrlField) {
                                            $orderData['custom_fields'][] = ['fieldType' => "string", 'internalId' => $customTrackUrlField, 'value' => $row->tracking_url];
                                        }
                                        $response =  $this->netsuiteApi->UpdateSalesOrder($service, $orderData);
                                        if ($response === false || $response == 0 || isset($response['error'])) {
                                            if (isset($response['error'])) {
                                                $api_error = $response['error'];
                                            } else {
                                                $api_error = "tracking information or url not updated in Netsuite";
                                            }
                                            $acknowledge =  $api_error;
                                        }
                                    }
                                }


                                //sync logger
                                if ($acknowledge) {
                                    $orderData = null; //array free memory
                                    $sync_error = 'Shipment synced successfully but ' . $acknowledge;
                                } else {
                                    $sync_error = 'Shipment synced successfully';
                                }

                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $row->platformOrder->id, $sync_error);
                                $return_response = $sync_error;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function SyncInventory($user_id, $source_platform_name, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id, $record_id = 0)
    {
        date_default_timezone_set("US/Eastern");
        $sync_error = '';
        $return_response = true;
        try {
            if (date('H') == 00 || $record_id) {
                $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
                $object_id = $this->helper->getObjectId('inventory');

                $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

                if ($service !== false) {
                    $inventory_warehouse_object_id = $this->helper->getObjectId('inventory_warehouse');

                    /* Find many to one & one to one warehouse mapping | here key is destination id and value is source id*/
                    $ManyToOneMappedWarehouseArray = $this->mapping->getManyToOneWarehouseMapping($inventory_warehouse_object_id, $user_integration_id, false, $user_id, $source_platform_id, "cross", "object");

                    $source_pi_field_match_by = $destination_pi_field_match_by = '';

                    /** Get Product Identity */
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');

                    /** Get product identity field */
                    $mapping_data = $this->mapping->getMappedField($user_integration_id, null, $product_identity_obj_id);

                    if (!empty($mapping_data)) {
                        if ($mapping_data['destination_platform_id'] == $this->platformId) {
                            $destination_pi_field_match_by = $mapping_data['destination_row_data'];
                            $source_pi_field_match_by = $mapping_data['source_row_data'];
                        } else {
                            $destination_pi_field_match_by = $mapping_data['source_row_data'];
                            $source_pi_field_match_by = $mapping_data['destination_row_data'];
                        }
                    }
                    /** Default Subsidiary */
                    $default_subsidiary = $this->mapping->getMappedDataByName($user_integration_id, null, "inventory_subsidiary", ['api_id']);

                    $default_subsidiary_id = null;
                    if (!empty($default_subsidiary)) {
                        $default_subsidiary_id = $default_subsidiary->api_id;
                    }
                    $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                    $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;
                    $conditions =
                        [
                            'platform_id' => $source_platform_id,
                            'user_integration_id' => $user_integration_id
                        ];

                    if ($record_id) {
                        $conditions['id'] = $record_id;
                    } else {
                        $conditions['inventory_sync_status'] = $sync_status;
                    }

                    $products = $this->mobj->getResultByConditions('platform_product', $conditions, [], ['id' => 'asc'], 10);

                    foreach ($products as $product) {
                        //Get Linked Product
                        $linkedProduct = $this->getProductMapping($service, $product, $this->platformId, $source_pi_field_match_by, $destination_pi_field_match_by, 1, $customFieldProductName);

                        if ($linkedProduct === false) {
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $product->id]);
                            $sync_error = 'Product mapping error';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                            continue;
                        }
                        if ($linkedProduct === 0) {
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $product->id]);
                            $sync_error = 'Product is not mapped ';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                            continue;
                        }
                        $failed_inventory = $success_inventory = $warehouse_unmapped = $no_inventory = [];

                        if (isset($ManyToOneMappedWarehouseArray['mapped_warehouse']) && !is_bool($ManyToOneMappedWarehouseArray)) {
                            $productInternalId = $linkedProduct->api_product_id;
                            /* Get Inventory By External Id or Sku */

                            $locationWiseQty = $this->netsuiteSyncServices->GetInventoryQuantityByLocations($service, $linkedProduct->sku, 'itemId');

                            if (!is_array($locationWiseQty)) {
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $product->id]);
                                $sync_error = $locationWiseQty;
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                                continue;
                            }

                            foreach ($ManyToOneMappedWarehouseArray['mapped_warehouse'] as $key => $value) {
                                $netsuite_location_id = $key;
                                $conditions_inv = [
                                    'platform_id' => $source_platform_id,
                                    'user_integration_id' => $user_integration_id,
                                    'api_product_id' => $product->api_product_id
                                ];
                                $query = DB::table('platform_product_inventory')->select('id',)->where($conditions_inv)->whereIn('api_warehouse_id', $value);

                                if ($query->count() > 0) {
                                    $total_quantity = $query->sum('quantity');
                                    /* Calculate Adjustment */
                                    if (isset($locationWiseQty[$netsuite_location_id])) {
                                        $onHandQuantity = $locationWiseQty[$netsuite_location_id];

                                        if ($onHandQuantity > 0) {
                                            $availAdjustmentQty = $onHandQuantity - $total_quantity;
                                            $availAdjustmentQty = -$availAdjustmentQty;
                                        } else {
                                            $availAdjustmentQty = $total_quantity;
                                        }
                                        if ($onHandQuantity == $availAdjustmentQty) {
                                            $success_inventory[$product->id] = $product->id;
                                            //if quantities are same
                                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Synced'], ['id' => $product->id]);
                                            //sync logger
                                            $sync_error = $return_response = 'Inventory synced successfully';
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $product->id, $sync_error);
                                            continue;
                                        }
                                    } else {
                                        continue;
                                    }

                                    $data_array = ['product_internalId' => $productInternalId, 'qty' => $availAdjustmentQty, 'location' => $netsuite_location_id, 'subsidiary' => $default_subsidiary_id, 'incomeAccount' => $linkedProduct->api_product_code];

                                    //adjust inventory
                                    $response =  $this->netsuiteApi->AdjustInventory($service, $data_array);

                                    if ($response === false || $response == 0 || isset($response['error'])) {
                                        $return_response = $sync_error = 'Inventory sync failed.' . @$response['error'];
                                        $failed_inventory[$product->id] = $sync_error;
                                    } else {
                                        //update acknowledge
                                        $success_inventory[$product->id] = $product->id;
                                        //sync logger
                                        $return_response = 'Inventory synced successfully';
                                    }
                                } else {
                                    $warehouse_unmapped[$product->id] = $product->id;
                                }
                            }
                        } else {
                            $warehouse_unmapped[$product->id] = $product->id;
                        }
                        if (count($success_inventory)) {
                            //update product inventory sync status
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Synced'], ['id' => $product->id]);
                            //sync logger
                            $sync_error = $return_response = 'Inventory synced successfully';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $product->id, $sync_error);
                        } else if (count($failed_inventory)) {
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $product->id]);
                            $sync_error = implode(' ', $failed_inventory);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                            // continue;
                        } else if (count($warehouse_unmapped)) {
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $product->id]);
                            $sync_error = $return_response = 'Warehouse is not mapped or no inventory found';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                            // continue;
                        } else {
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $product->id]);
                            $sync_error = $return_response = 'Inventory sync failed. Unknown error';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                            // continue;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function getProductTypeMapping($service, $product_type_id, $user_id, $user_integration_id,  $source_platform_id)
    {
        $object_id = $this->helper->getObjectId('product_type');
        $type_name = null;

        $findRecord = $this->mobj->getFirstResultByConditions('platform_object_data', [
            'user_integration_id' => $user_integration_id,
            'platform_id' => $source_platform_id,
            'platform_object_id' => $object_id,
            'api_id' => $product_type_id,
        ], ['id', 'api_id', 'name']);
        if (empty($findRecord)) {
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $source_platform_id, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            $product_type_response = $this->brightpearlApi->GetProductType($ufound, $product_type_id);

            if ($product_types = json_decode($product_type_response->getBody(), true)) {

                if (!empty($product_types) && isset($product_types['response']) && is_array($product_types['response'])) {
                    $type_name = @$product_types['response'][0]['name'];
                }
            }

            if (empty($type_name)) {
                return false;
            }

            $insertList = ['user_id' => $user_id, 'platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'name' => $type_name, 'api_id' => $product_type_id, 'status' => 1, 'platform_object_id' => $object_id];
            $this->mobj->makeInsert('platform_object_data', $insertList);
        } else {
            $type_name = $findRecord->name;
        }


        /** Product Type Mapping */
        $netsuite_product_type_id = $this->mapping->getMappedDataByName($user_integration_id, null, "product_type", ['api_id', 'name'], 'regular', $product_type_id);
        $netsuite_product_type_id = $netsuite_product_type_id && $netsuite_product_type_id->api_id ? $netsuite_product_type_id->api_id : null;

        if ($netsuite_product_type_id) {
            return $netsuite_product_type_id;
        }

        $match_string = $type_name;

        //           $mapping_result = $this->netsuiteApi->SearchNetsuiteProductType($service,$destination_field_match_by,$match_string);
        $mapping_result = false;
        if ($mapping_result !== false && !empty($mapping_result)) {
            $firstInternalId = 0;

            /*   foreach($mapping_result->record as $netsuite_record)
               {
                   if(empty($firstInternalId) && !empty($netsuite_record->internalId))
                   {
                     $firstInternalId = $netsuite_record->internalId;
                   }

                   if($save_record)
                   {

                   $fields = array(
                                 'user_id' => $source_product->user_id,
                                 'user_integration_id' => $source_product->user_integration_id,
                                 'platform_id' => $destination_platform_id,
                                 'api_product_id' => $netsuite_record->internalId,
                                 'product_name' => @$netsuite_record->itemId,
                                 'api_product_code' => @$netsuite_record->incomeAccount->internalId,
                                 'upc' => @$netsuite_record->upcCode,
                                 'sku' => @$netsuite_record->sku,
                                 'price' => ((!empty($netsuite_record->amount)) ? $netsuite_record->amount : 0)


                             );
                   $checkProduct = $this->mobj->getFirstResultByConditions('platform_product',
                  [ 'user_id' => $source_product->user_id, 'platform_id' => $destination_platform_id, $destination_field_match_by => $match_string,
                  'user_integration_id' => $source_product->user_integration_id]);

                  if($checkProduct)
                  {
                          $this->mobj->makeUpdate('platform_product', $fields, ['id' => $checkProduct->id]);
                  }
                  else
                  {
                          $this->mobj->makeInsert('platform_product', $fields);
                  }
                  }

               }

             $findProduct = $this->mobj->getFirstResultByConditions('platform_product',
          [ 'user_id' => $source_product->user_id, 'platform_id' => $destination_platform_id, $destination_field_match_by => $match_string,
          'user_integration_id' => $source_product->user_integration_id]);


               return $findProduct;*/
        } else if ($mapping_result !== false && $mapping_result === 0) {
            return 0;
        }

        return false;


        // return $findProduct;



    }

    public function getProductVendorMapping($service, $primary_supplier_id, $user_id, $user_integration_id,  $source_platform_id)
    {
        $object_id = $this->helper->getObjectId('supplier');

        $firstInternalId = $firstPlatformCustomerId = 0;
        $error_msg = '';

        $source_customer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            [
                'platform_id' => $source_platform_id, 'api_customer_id' => $primary_supplier_id,
                'user_integration_id' => $user_integration_id
            ]
        );

        if (empty($source_customer->id)) {
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $source_platform_id, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            $supplier_response = $this->brightpearlApi->GetCustomers($ufound, 'contact/' . $primary_supplier_id);

            if ($supplier_details = json_decode($supplier_response->getBody(), true)) {

                if (!empty($supplier_details) && isset($supplier_details['response']) && is_array($supplier_details['response'])) {
                    $supplier = @$supplier_details['response'][0];

                    $platform_customer_id = 0;

                    $companyName = null;

                    if (!empty($supplier['companyId'])) {
                        $company_response = $this->brightpearlApi->GetCustomers($ufound, 'company/' . $supplier['companyId']);

                        if ($company_details = json_decode($company_response->getBody(), true)) {
                            if (!empty($company_details) && isset($company_details['response']) && is_array($company_details['response'])) {
                                $companyName = @$company_details['response'][0]['name'];
                            }
                        }
                    }

                    /** save customer/supplier details */

                    $contact_id = $supplier['contactId'];
                    $fields = array(
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $source_platform_id,
                        'api_customer_id' => $contact_id,
                        'first_name' => @$supplier['firstName'],
                        'last_name' => @$supplier['lastName'],
                        'customer_name' => @$supplier['firstName'] . ' ' . @$supplier['lastName'],
                        'company_id' => @$supplier['companyId'],
                        'company_name' => $companyName,
                        'phone' => ((@$supplier['communication']['telephones']['PRI']) ? @$supplier['communication']['telephones']['PRI'] : @$supplier['communication']['telephones']['MOB']),
                        'email' => @$supplier['communication']['emails']['PRI']['email'],
                        'address1' => @array_values($supplier['postalAddresses'])[0]['addressLine1'],
                        'address2' => @array_values($supplier['postalAddresses'])[0]['addressLine2'],
                        'address3' => @array_values($supplier['postalAddresses'])[0]['addressLine3'],
                        'postal_addresses' => @array_values($supplier['postalAddresses'])[0]['postalCode'],
                        'country' => @array_values($supplier['postalAddresses'])[0]['countryIsoCode']
                    );

                    $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer', [
                        'platform_id' => $source_platform_id, 'api_customer_id' => $contact_id, 'user_integration_id' => $user_integration_id,
                    ], ['id']);
                    if (!empty($findCustomer->id)) {
                        $platform_customer_id = $findCustomer->id;
                        $this->mobj->makeUpdate('platform_customer', $fields, [
                            'id' => $platform_customer_id
                        ]);
                    } else {
                        $fields['sync_status'] = 'Ready';
                        $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $fields);
                    }
                }
            }
        }

        $source_customer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            [
                'platform_id' => $source_platform_id, 'api_customer_id' => $primary_supplier_id,
                'user_integration_id' => $user_integration_id
            ]
        );


        if (empty($source_customer->email)) {
            return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
        }

        $findCustomer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            [
                'platform_id' => $this->platformId, 'email' => $source_customer->email,
                'user_integration_id' => $source_customer->user_integration_id, 'type' => PlatformRecordType::VENDOR
            ],
            ['api_customer_id', 'id']
        );

        if (empty($findCustomer->id)) {
            $mapping_result =  $this->netsuiteApi->SearchNetsuiteVendor($service, 'email', $source_customer->email);
            if ($mapping_result !== false && !empty($mapping_result)) {


                foreach ($mapping_result->record as $netsuite_customer) {
                    if (empty($firstInternalId) && !empty($netsuite_customer->internalId)) {
                        $firstInternalId = $netsuite_customer->internalId;
                    }
                    $address = @$netsuite_customer->addressbookList->addressbook;
                    if ($address) {
                        $address = (($address && is_array($address)) ?  $address[0]->addressbookAddress : $address->addressbookAddress);
                        $address = (array) $address;
                    } else {
                        $address = [];
                    }


                    $fields = array(
                        'user_id' => $source_customer->user_id,
                        'user_integration_id' => $source_customer->user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $netsuite_customer->internalId,
                        'first_name' => @$netsuite_customer->firstName,
                        'last_name' => @$netsuite_customer->lastName,
                        'company_name' => @$netsuite_customer->companyName,
                        'phone' =>  @$netsuite_customer->phone,
                        'email' => @$netsuite_customer->email,
                        'type' => PlatformRecordType::VENDOR
                        //  'postal_addresses' => json_encode($address)

                    );
                    $platform_customer_id =   $this->mobj->makeInsertGetId('platform_customer', $fields);

                    if (empty($firstPlatformCustomerId) && !empty($platform_customer_id)) {
                        $firstPlatformCustomerId = $platform_customer_id;
                    }
                }



                return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
            }

            return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $error_msg];
        }

        return ['api_customer_id' => $findCustomer->api_customer_id, 'platform_customer_id' => $findCustomer->id, 'error_msg' => $error_msg];
    }

    public function getOrderAssignedToMapping($service, $order_owner_id, $user_id, $user_integration_id,  $source_platform_id)
    {
        $object_id = $this->helper->getObjectId('employee');

        $firstInternalId = $firstPlatformCustomerId = 0;
        $error_msg = '';

        $source_customer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            [
                'platform_id' => $source_platform_id, 'api_customer_id' => $order_owner_id,
                'user_integration_id' => $user_integration_id
            ]
        );

        if (empty($source_customer->id)) {
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $source_platform_id, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            $supplier_response = $this->brightpearlApi->GetCustomers($ufound, 'contact/' . $order_owner_id);

            if ($supplier_details = json_decode($supplier_response->getBody(), true)) {

                if (!empty($supplier_details) && isset($supplier_details['response']) && is_array($supplier_details['response'])) {
                    $supplier = @$supplier_details['response'][0];

                    $platform_customer_id = 0;

                    $companyName = null;

                    if (!empty($supplier['companyId'])) {
                        $company_response = $this->brightpearlApi->GetCustomers($ufound, 'company/' . $supplier['companyId']);

                        if ($company_details = json_decode($company_response->getBody(), true)) {
                            if (!empty($company_details) && isset($company_details['response']) && is_array($company_details['response'])) {
                                $companyName = @$company_details['response'][0]['name'];
                            }
                        }
                    }

                    /** save staff details */

                    $contact_id = $supplier['contactId'];
                    $fields = array(
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $source_platform_id,
                        'api_customer_id' => $contact_id,
                        'first_name' => @$supplier['firstName'],
                        'last_name' => @$supplier['lastName'],
                        'customer_name' => @$supplier['firstName'] . ' ' . @$supplier['lastName'],
                        'company_id' => @$supplier['companyId'],
                        'company_name' => $companyName,
                        'phone' => ((@$supplier['communication']['telephones']['PRI']) ? @$supplier['communication']['telephones']['PRI'] : @$supplier['communication']['telephones']['MOB']),
                        'email' => @$supplier['communication']['emails']['PRI']['email'],
                        'address1' => @array_values($supplier['postalAddresses'])[0]['addressLine1'],
                        'address2' => @array_values($supplier['postalAddresses'])[0]['addressLine2'],
                        'address3' => @array_values($supplier['postalAddresses'])[0]['addressLine3'],
                        'postal_addresses' => @array_values($supplier['postalAddresses'])[0]['postalCode'],
                        'country' => @array_values($supplier['postalAddresses'])[0]['countryIsoCode']
                    );

                    $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer', [
                        'platform_id' => $source_platform_id, 'api_customer_id' => $contact_id, 'user_integration_id' => $user_integration_id,
                    ], ['id']);
                    if (!empty($findCustomer->id)) {
                        $platform_customer_id = $findCustomer->id;
                        $this->mobj->makeUpdate('platform_customer', $fields, [
                            'id' => $platform_customer_id
                        ]);
                    } else {
                        $fields['sync_status'] = 'Ready';
                        $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $fields);
                    }
                }
            }
        }

        $source_customer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            [
                'platform_id' => $source_platform_id, 'api_customer_id' => $order_owner_id,
                'user_integration_id' => $user_integration_id
            ]
        );


        if (empty($source_customer->email)) {
            return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
        }

        $findCustomer = $this->mobj->getFirstResultByConditions(
            'platform_customer',
            [
                'platform_id' => $this->platformId, 'email' => $source_customer->email,
                'user_integration_id' => $source_customer->user_integration_id, 'type' => PlatformRecordType::EMPLOYEE
            ],
            ['api_customer_id', 'id']
        );

        if (empty($findCustomer->id)) {
            $mapping_result =  $this->netsuiteApi->SearchNetsuiteEmployee($service, 'email', $source_customer->email);
            if ($mapping_result !== false && !empty($mapping_result)) {


                foreach ($mapping_result->record as $netsuite_customer) {
                    if (empty($firstInternalId) && !empty($netsuite_customer->internalId)) {
                        $firstInternalId = $netsuite_customer->internalId;
                    }
                    $address = @$netsuite_customer->addressbookList->addressbook;
                    if ($address) {
                        $address = (($address && is_array($address)) ?  $address[0]->addressbookAddress : $address->addressbookAddress);
                        $address = (array) $address;
                    } else {
                        $address = [];
                    }


                    $fields = array(
                        'user_id' => $source_customer->user_id,
                        'user_integration_id' => $source_customer->user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $netsuite_customer->internalId,
                        'first_name' => @$netsuite_customer->firstName,
                        'last_name' => @$netsuite_customer->lastName,
                        'company_name' => @$netsuite_customer->companyName,
                        'phone' =>  @$netsuite_customer->phone,
                        'email' => @$netsuite_customer->email,
                        'type' => PlatformRecordType::EMPLOYEE
                        //  'postal_addresses' => json_encode($address)

                    );
                    $platform_customer_id =   $this->mobj->makeInsertGetId('platform_customer', $fields);

                    if (empty($firstPlatformCustomerId) && !empty($platform_customer_id)) {
                        $firstPlatformCustomerId = $platform_customer_id;
                    }
                }



                return ['api_customer_id' => $firstInternalId, 'platform_customer_id' => $firstPlatformCustomerId, 'error_msg' => $error_msg];
            }

            return ['api_customer_id' => false, 'platform_customer_id' => false, 'error_msg' => $error_msg];
        }

        return ['api_customer_id' => $findCustomer->api_customer_id, 'platform_customer_id' => $findCustomer->id, 'error_msg' => $error_msg];
    }

    public function CreateProduct($user_id, $source_platform_name, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id, $record_id = '')
    {
        $sync_error = true;
        $mapped_netsuite_internal_list = [];
        try {
            $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
            $object_id = $this->helper->getObjectId('product');

            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {

                $source_pi_field_match_by = $destination_pi_field_match_by = '';

                /** Get Product Identity */
                $product_identity_obj_id = $this->helper->getObjectId('product_identity');

                /** Get product identity field */
                $mapping_data = $this->mapping->getMappedField($user_integration_id, null, $product_identity_obj_id);

                /** Default Purchase Price */
                $product_pricelist_mapped = $this->mapping->getMappedDataByName($user_integration_id, null, "product_pricelist", ['id', 'api_id']);
                $product_pricelist = $product_pricelist_mapped ? $product_pricelist_mapped->api_id : NULL;
                $default_pricelist_id = $product_pricelist_mapped ? $product_pricelist_mapped->id : NULL;

                /** Default mapped Price List */
                $mapped_pricelist = $this->mapping->getSourceDestinationMappedDataByName($user_integration_id, "product_pricelist", 'regular');
                $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;
                if (!empty($mapping_data)) {
                    $source_pi_field_match_by =  $mapping_data['source_row_data'];
                    $destination_pi_field_match_by = $mapping_data['destination_row_data'];
                }
                /** Default Subsidiary */
                $subsidiary = $this->mapping->getMappedDataByName($user_integration_id, null, "product_subsidiary", ['api_id']);
                if (!empty($subsidiary)) {
                    $subsidiary = $subsidiary->api_id;
                }

                $conditions =
                    [
                        'platform_id' => $source_platform_id,
                        'user_integration_id' => $user_integration_id,
                        'is_deleted' => 0
                    ];

                if ($record_id) {
                    $conditions['id'] = $record_id;
                } else {
                    $conditions['product_sync_status'] = $sync_status;
                }

                $products = $this->mobj->getResultByConditions('platform_product', $conditions, [], ['id' => 'asc'], 50);

                foreach ($products as $product) {
                    if ($product->stock_track == 0) {
                        //Skip not track inventory
                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Inactive'], ['id' => $product->id]);
                        continue;
                    }

                    /** WH - Location  Mapping */
                    $netsuite_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $product->api_warehouse_id);
                    $netsuite_location_id = $netsuite_location_id  ? $netsuite_location_id->api_id : null;


                    //Get Linked Product
                    $linkedProduct = $this->getProductMapping($service, $product, $this->platformId, $source_pi_field_match_by, $destination_pi_field_match_by, 1, $customFieldProductName);
                    if ($linkedProduct === false) {
                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $product->id]);
                        $sync_error = 'Product check in Netsuite failed';
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                        continue;
                    }

                    $data = ['api_product_id'  => $product->api_product_id, 'product_name' => $product->product_name, 'sku' => $product->sku, 'subsidiary' => $subsidiary, 'location' => $netsuite_location_id, 'price' => $product->price];


                    $data['custom_fields'] = [];
                    $processedCustomFields = [];

                    /** Custom Fields */
                    //get custom field values
                    $cus_values = $this->mobj->getResultByConditions('platform_custom_field_values', [
                        'record_id' => $product->id,
                        'platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id
                    ], ['field_value', 'platform_field_id']);

                    foreach ($cus_values as $cus_value) {
                        array_push($processedCustomFields, $cus_value->platform_field_id);
                        $getMappedField = $this->mapping->getMappedField($user_integration_id, null, $object_id, [], $cus_value->platform_field_id);

                        if (!empty($getMappedField['destination_field_name'])) {
                            $destinationField = PlatformField::where([
                                'platform_id' => $this->platformId,
                                'custom_field_id' => $getMappedField['destination_custom_field_id'], 'platform_object_id' => $object_id
                            ])->first();
                            if ($destinationField) {
                                $optionId = 0;
                                $cus_field_value = $cus_value->field_value;
                                if ($destinationField->custom_field_type == CustomFieldType::SELECT) {
                                    $optionObj = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($cus_field_value, $destinationField->id);
                                    if ($optionObj) {
                                        $optionId = $optionObj->field_value_id;
                                    }
                                }
                                if ($getMappedField['source_custom_field_type'] == 'YES_NO') {
                                    $cus_field_value = $cus_field_value ? true : false;
                                }
                                $data['custom_fields'][] = [
                                    'sciptId' => $getMappedField['destination_field_name'],
                                    'internalId' => $getMappedField['destination_custom_field_id'], 'value' => $cus_field_value,
                                    'field_type' => $getMappedField['destination_custom_field_type'], 'option_id' => $optionId
                                ];

                                if ($getMappedField['destination_custom_field_id'] == 1162) {
                                    $customDestinationField = PlatformField::where([
                                        'platform_id' => $this->platformId,
                                        'custom_field_id' => '1550', 'user_integration_id' => $user_integration_id
                                    ])->first();
                                    if ($customDestinationField) {
                                        $licenseBrandSegment = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($cus_field_value, $customDestinationField->id);
                                        if ($licenseBrandSegment) {
                                            $data['custom_fields'][] = [
                                                'sciptId' => $getMappedField['destination_field_name'],
                                                'internalId' => 1550, 'value' => $cus_field_value,
                                                'field_type' => $getMappedField['destination_custom_field_type'], 'option_id' => $licenseBrandSegment->field_value_id
                                            ];
                                        }
                                    }
                                }

                                // if($getMappedField['destination_custom_field_id'] == 1156) {
                                //     $customDestinationField = PlatformField::where(['platform_id' => $this->platformId,
                                //         'custom_field_id' => '1545', 'user_integration_id' => $user_integration_id])->first();
                                //     if($customDestinationField) {
                                //         $licenseBrandSegment = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($cus_field_value, $customDestinationField->id);
                                //         if($licenseBrandSegment) {
                                //             $data['custom_fields'][] = ['sciptId' => $getMappedField['destination_field_name'],
                                //             'internalId' => 1545, 'value' => $cus_field_value,
                                //             'field_type' => $getMappedField['destination_custom_field_type'], 'option_id' => $licenseBrandSegment->field_value_id];
                                //         }
                                //     }


                                // }
                            }
                        }
                    }
                    $productTypeId = 0;
                    $prodDetailedAttributes = PlatformProductDetailAttribute::where(['platform_product_id' => $product->id])->first();
                    if ($prodDetailedAttributes) {
                        $prodTypeArray = explode(',', $prodDetailedAttributes->product_type_ids);
                        if (isset($prodTypeArray[0])) {
                            $productTypeId = $prodTypeArray[0];
                        }
                    }


                    if ($productTypeId) {
                        $pTypeObjId = $this->helper->getObjectId('get_product_type');
                        $sProdType = PlatformObjectData::where([
                            'user_integration_id' => $user_integration_id, 'platform_object_id' => $pTypeObjId,
                            'platform_id' => $source_platform_id, 'api_id' => $productTypeId
                        ])
                            ->select('name')->first();
                        if ($sProdType) {
                            $productTypeSegment = PlatformField::where([
                                'platform_id' => $this->platformId,
                                'custom_field_id' => '1545', 'user_integration_id' => $user_integration_id
                            ])
                                ->select('id', 'custom_field_id', 'custom_field_type')->first();
                            if ($productTypeSegment) {
                                $productTypeSegmentValue = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($sProdType->name, $productTypeSegment->id);

                                if ($productTypeSegmentValue) {
                                    $data['custom_fields'][] = [
                                        'sciptId' => '',
                                        'internalId' => $productTypeSegment->custom_field_id, 'value' => $productTypeSegmentValue->field_value,
                                        'field_type' => $productTypeSegment->custom_field_type, 'option_id' => $productTypeSegmentValue->field_value_id
                                    ];
                                }
                            }

                            $lovepopProdType = PlatformField::where([
                                'platform_id' => $this->platformId,
                                'custom_field_id' => '1156', 'user_integration_id' => $user_integration_id
                            ])
                                ->select('id', 'custom_field_id', 'custom_field_type')->first();
                            if ($lovepopProdType) {
                                $lovepopProdTypeValue = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($sProdType->name, $lovepopProdType->id);
                                if ($lovepopProdTypeValue) {
                                    $data['custom_fields'][] = [
                                        'sciptId' => '',
                                        'internalId' => $lovepopProdType->custom_field_id, 'value' => $lovepopProdTypeValue->field_value,
                                        'field_type' => $lovepopProdType->custom_field_type, 'option_id' => $lovepopProdTypeValue->field_value_id
                                    ];
                                }
                            }
                        }
                    }

                    if ($product->category_id) {
                        $categoryObjId = $this->helper->getObjectId('category');
                        $sCategory = PlatformObjectData::where([
                            'user_integration_id' => $user_integration_id, 'platform_object_id' => $categoryObjId,
                            'platform_id' => $source_platform_id, 'api_id' => $product->category_id
                        ])
                            ->select('name')->first();
                        if ($sCategory) {
                            $lovepopCategory = PlatformField::where([
                                'platform_id' => $this->platformId,
                                'custom_field_id' => '1157', 'user_integration_id' => $user_integration_id
                            ])
                                ->select('id', 'custom_field_id', 'custom_field_type')->first();
                            if ($lovepopCategory) {
                                $lovepopCategoryValue = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($sCategory->name, $lovepopCategory->id);
                                if ($lovepopCategoryValue) {
                                    $data['custom_fields'][] = [
                                        'sciptId' => '',
                                        'internalId' => $lovepopCategory->custom_field_id, 'value' => $lovepopCategoryValue->field_value,
                                        'field_type' => $lovepopCategory->custom_field_type, 'option_id' => $lovepopCategoryValue->field_value_id
                                    ];
                                }
                            }
                        }
                    }
                    if ($product->brand_id) { // mapping to send brand data
                        $brandObjId = $this->helper->getObjectId('brand');
                        $sBrand = PlatformObjectData::where([
                            'user_integration_id' => $user_integration_id, 'platform_object_id' => $brandObjId,
                            'platform_id' => $source_platform_id, 'api_id' => $product->brand_id
                        ])
                            ->select('name')->first();
                        if ($sBrand) {
                            $lovepopBrand = PlatformField::where([
                                'platform_id' => $this->platformId,
                                'custom_field_id' => '1158', 'user_integration_id' => $user_integration_id
                            ])
                                ->select('id', 'custom_field_id', 'custom_field_type')->first();
                            if ($lovepopBrand) {
                                $lovepopBrandValue = NetsuiteServices::GetNetsuiteCustomFieldOptionByName($sBrand->name, $lovepopBrand->id);
                                if ($lovepopBrandValue) {
                                    $data['custom_fields'][] = [
                                        'sciptId' => '',
                                        'internalId' => $lovepopBrand->custom_field_id, 'value' => $lovepopBrandValue->field_value,
                                        'field_type' => $lovepopBrand->custom_field_type, 'option_id' => $lovepopBrandValue->field_value_id
                                    ];
                                }
                            }
                        }
                    }
                    /** Product Price List */
                    $cost_price =  $this->mobj->getFirstResultByConditions('platform_porduct_price_list', [
                        'platform_product_id' => $product->id,
                        'platform_object_data_id' => $default_pricelist_id,
                    ], ['api_currency_code', 'price']);



                    if ($mapped_pricelist) {
                        $priceMatrix = [];
                        $currencyInternalId = 1;
                        foreach ($mapped_pricelist as $mapped) {
                            $sourcePlatformPrice = PlatformProductPriceList::where('platform_product_id', $product->id)
                                ->where('platform_object_data_id', $mapped->source_row_id)
                                ->select('price')->first();
                            if (!isset($mapped_netsuite_internal_list[$mapped->destination_row_id])) {
                                $destinationPlatformObjectData =
                                    $this->mapping->getObjectDataByID($mapped->destination_row_id, ['api_id']);


                                if ($destinationPlatformObjectData) {
                                    $mapped_netsuite_internal_list[$mapped->destination_row_id] = $destinationPlatformObjectData->api_id;
                                }
                            }
                            if ($mapped_netsuite_internal_list[$mapped->destination_row_id] && $sourcePlatformPrice) {
                                $priceMatrix[]  = [
                                    'currency' => ['internalId' => $currencyInternalId],
                                    'priceLevel' => ['internalId' => $mapped_netsuite_internal_list[$mapped->destination_row_id]],
                                    'priceList' => ['price' => ['value' => $sourcePlatformPrice->price, 'quantity' => 0]]
                                ];
                            }
                        }
                        $data['priceMatrix'] = $priceMatrix;
                    }
                    $data['cost'] = $cost_price ? $cost_price->price : 0;
                    $data['mpn'] = $product->mpn;
                    $data['barcode'] = $product->barcode;
                    $data['weight'] = $product->weight;
                    $data['isInventoryItem'] = $product->stock_track;
                    $additional = $this->mobj->getAdditionalAccountDataByIntegrationId($user_integration_id);

                    if (!empty($additional->account_product_weight_unit)) {
                        $data['weightUnit'] = $additional->account_product_weight_unit;
                    }


                    $attributes = $this->mobj->getFirstResultByConditions('platform_product_detail_attributes', [
                        'platform_product_id' => $product->id
                    ]);

                    if ($attributes) {
                        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $source_platform_id, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

                        /** Map Product Type */
                        /*
                              $getProductTypeMapping = $this->getProductTypeMapping($service,$attributes->product_type_ids,$user_id,$user_integration_id,$source_platform_id);

                              if(  $getProductTypeMapping  === false)
                              {
                              $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $product->id]);
                              $sync_error = 'Product type mapping failed';
                              $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                              continue;
                              }

                              if(  $getProductTypeMapping  === 0)
                              {
                              $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $product->id]);
                              $sync_error = 'Product type is not mapped';
                              $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                              continue;
                              }



                              if(!empty($getProductTypeMapping))
                              {
                              $data['product_type_id'] = $getProductTypeMapping;
                              }

                              */

                        /** Map Tax Schedule */
                        $taxSchedule = '';
                        if (isset($attributes->taxable)) {
                            $taxSchedule =  $attributes->taxable ? '1' : '2';
                        }
                        $data['taxSchedule'] = $taxSchedule;

                        /** Map Vendor */

                        $getVendorMapping = $this->getProductVendorMapping($service, $attributes->primary_supplier_id, $user_id, $user_integration_id, $source_platform_id);


                        $customerInternalId = $getVendorMapping['api_customer_id'];
                        $destination_platform_customer_id = $getVendorMapping['platform_customer_id'];


                        //
                        //                        if(  $customerInternalId  === false)
                        //                        {
                        //                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $product->id]);
                        //                            $sync_error = 'Vendor mapping error '.$getVendorMapping['error_msg'];
                        //                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                        //
                        //                            continue;
                        //
                        //                        }
                        //
                        //                        if(  $customerInternalId  === 0)
                        //                        {
                        //                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $product->id]);
                        //                            $sync_error = 'Vendor is not mapped '.$getVendorMapping['error_msg'];
                        //                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                        //
                        //                            continue;
                        //
                        //                        }

                        if (!empty($customerInternalId) && $customerInternalId) {
                            $data['customerInternalId'] = $customerInternalId;
                            $customerDest = PlatformCustomer::find($destination_platform_customer_id);
                            $data['customerName'] = empty($customerDest->customer_name) ? $customerDest->email : $customerDest->customer_name;
                        }
                    }

                    $nullFields = [];
                    $fieldMappingRows = PlatformDataMapping::where([
                        'mapping_type' => 'regular', 'data_map_type' => 'field',
                        'platform_object_id' => $object_id, 'status' => 1, 'user_integration_id' => $user_integration_id
                    ])
                        ->where('destination_row_id', '!=', 0)->whereNotIn('source_row_id', $processedCustomFields)->get();

                    foreach ($fieldMappingRows as $filedMap) {
                        $destField = PlatformField::find($filedMap->destination_row_id);
                        if ($destField && $destField->description) {
                            array_push($nullFields, $destField->description);
                        }
                    }
                    $data['nullFields'] = $nullFields;

                    if ($linkedProduct === 0) {
                        $response =  $this->netsuiteApi->CreateUpdateInventoryItem($service, $data);
                    } else {
                        $data['itemId'] = $linkedProduct->api_product_id;

                        $response =  $this->netsuiteApi->CreateUpdateInventoryItem($service, $data, 'update');
                    }


                    if ($response === false || $response == 0 || isset($response['error'])) {
                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $product->id]);
                        $sync_error = $sync_error = 'Product sync failed.' . @$response['error'];
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $product->id, $sync_error);
                        continue;
                    }

                    $fields = array(
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'api_product_id' => $response,
                        'product_name' => $product->$source_pi_field_match_by,
                        'description' => $product->product_name,
                        // 'api_product_code' => $product->product_name,
                        //  'upc' => @$netsuite_record->upcCode,
                        'sku' => $product->sku,
                        'price' => $product->price


                    );
                    if ($linkedProduct === 0) {
                        $this->mobj->makeInsert('platform_product', $fields);
                    } else {
                        $this->mobj->makeUpdate('platform_product', $fields, ['id' => $linkedProduct->id]);
                    }

                    //update acknowledge
                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Synced'], ['id' => $product->id]);
                    $sync_error = $sync_error = 'Product synced successfully';
                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $product->id, $sync_error);
                }
            }
            return $sync_error;
        } catch (\Exception $e) {
            throw new \Exception('Error: ' . $e->getMessage() . ' : ' . $e->getLine());
        }
    }

    public function CreateItemReceipt($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformName, $retryId = null)
    {
        $sPlatformId = $this->helper->getPlatformIdByName($sPlatformName);

        $checks = [
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $sPlatformId,
            // 'sync_status' => PlatformStatus::READY,
            'type' => PlatformRecordType::POSHIPMENT
        ];

        if ($retryId && $retryId != '') {
            $checks = [];
            $checks['platform_order_id'] = $retryId;
        }

        $shipments = PlatformOrderShipment::where($checks)
            ->whereIn('sync_status', [PlatformStatus::READY, PlatformStatus::FAILED])
            ->whereHas('platformOrder', function ($query) use ($retryId) {
                $query->where('order_type', '=', 'PO');
                if ($retryId == '') {
                    $query->where('shipment_status', '=', PlatformStatus::READY);
                }
            })->take(30)->get();

        $nsService = $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);
        foreach ($shipments as $shipment) {
            $sOrder = $shipment->platformOrder;
            if ($sOrder && $sOrder->order_type == 'PO') {
                $res = $this->netsuiteSyncServices->createItemReceipt($shipment, $nsService);

                if ($res && isset($res->internalId)) {
                    try {
                        $newShipment = new PlatformOrderShipment();
                        $newShipment->user_id = $userId;
                        $newShipment->user_integration_id = $userIntegrationId;
                        $newShipment->shipment_id = $res->internalId;
                        $newShipment->sync_status = PlatformStatus::SYNCED;
                        $newShipment->platform_order_id  = $sOrder->linkedOrder->id;
                        $newShipment->linked_id = $shipment->id;
                        $newShipment->save();

                        foreach ($shipment->platformShippingLines as $line) {
                            $sProduct = PlatformProduct::where([
                                'user_integration_id' => $userIntegrationId,
                                'platform_id' => $sPlatformId, 'api_product_id' => $line->product_id
                            ])->first();
                            $sLineItem = PlatformOrderLine::where(['platform_order_id' => $sOrder->id, 'api_order_line_id' => $line->row_id])->first();
                            if ($sProduct && $sLineItem) {
                                $nsProduct = PlatformProduct::where([
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId, 'product_name' => $sProduct->sku
                                ])->first();
                                $nsLineItem = PlatformOrderLine::where(['linked_id' => $sLineItem->id])->first();
                                if ($nsProduct && $nsLineItem) {
                                    $newShipmentLine = new PlatformOrderShipmentLine();
                                    $newShipmentLine->row_id = $nsLineItem->id;
                                    $newShipmentLine->platform_order_shipment_id = $newShipment->id;
                                    $newShipmentLine->product_id = $nsProduct->api_product_id;
                                    $newShipmentLine->sku = $line->sku;
                                    $newShipmentLine->barcode = $line->barcode;
                                    $newShipmentLine->currency = $line->currency;
                                    $newShipmentLine->price = $line->price;
                                    $newShipmentLine->quantity = $line->quantity;
                                    $newShipmentLine->user_batch_reference = $line->user_batch_reference;
                                    $newShipmentLine->save();
                                }
                            }
                        }

                        $shipment->sync_status = PlatformStatus::SYNCED;
                        $sOrder->shipment_status = PlatformStatus::SYNCED;
                        $shipment->linked_id = $newShipment->id;
                        $this->log->syncLog($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformId, $this->platformId, null, 'success', $sOrder->id, 'Item receipt synced successfully');
                    } catch (\Exception $ex) {
                        $shipment->sync_status = PlatformStatus::FAILED;
                        $sOrder->shipment_status = PlatformStatus::FAILED;
                        $this->log->syncLog($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformId, $this->platformId, null, 'failed', $sOrder->id, $ex->getMessage());
                    }
                } else {
                    if (is_string($res) && ($res == 'All lines of sublist itemList have to be specified when replace All is requested.') || ($res == 'Adding new line to sublist item is not allowed.')) {
                        $shipment->sync_status = PlatformStatus::SYNCED;
                        $sOrder->shipment_status = PlatformStatus::SYNCED;
                    } else {
                        $shipment->sync_status = PlatformStatus::FAILED;
                        $sOrder->shipment_status = PlatformStatus::FAILED;
                        $this->log->syncLog($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformId, $this->platformId, null, 'failed', $sOrder->id, is_string($res) ? $res : json_encode($res));
                    }
                }
                $shipment->save();
                $sOrder->save();
            }
        }
        return true;
    }

    public function CreateInventoryTransfers($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformName, $retryId = null)
    {
        $errorFlag = 0;
        $errorMsg = '';
        $sPlatformId = $this->helper->getPlatformIdByName($sPlatformName);
        $checks = [
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $sPlatformId, 'sync_status' => PlatformStatus::READY,
            'type' => PlatformRecordType::TRANSFER
        ];

        if ($retryId && $retryId != '') {
            $checks = [];
            $checks['id'] = $retryId;
        }
        $productIdentityObjId = $this->helper->getObjectId('product_identity');
        $mapData = $this->mapping->getMappedField($userIntegrationId, null, $productIdentityObjId);

        if (!empty($mapData)) {
            $sProdFieldMatchBy =  $mapData['source_row_data'];
            $dProdFieldMatchBy = $mapData['destination_row_data'];
        }

        $shipments = PlatformOrderShipment::where($checks)->take(6)->get();
        $nsService = $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);
        $subsidiary = $this->mapping->getMappedDataByName($userIntegrationId, null, "subsidiary", ['api_id']);
        $subsidiaryId = $subsidiary ? $subsidiary->api_id : NULL;
        /** Get default NS Location */
        $defaultLocationObj = $this->mapping->getMappedDataByName($userIntegrationId, null, "porder_location", ['api_id']);
        $defaultLocationId = $defaultLocationObj && $defaultLocationObj->api_id ? $defaultLocationObj->api_id : NULL;
        $customFieldProductName = $this->mapping->getMappedDataByName($userIntegrationId, null, "custom_field_product_identifier", ['custom_data'], "default");
        $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;
        foreach ($shipments as $shipment) {
            $data = [];
            $toLocationObj = PlatformObjectData::where([
                'user_integration_id' => $userIntegrationId, 'api_id' => $shipment->to_warehouse_id,
                'platform_object_id' => $this->helper->getObjectId('warehouse'), 'platform_id' => $sPlatformId
            ])->first();
            $toLocationId = null;
            if ($toLocationObj) {
                $map = PlatformDataMapping::where([
                    'source_row_id' => $toLocationObj->id, 'platform_object_id' => $this->helper->getObjectId('order_warehouse'),
                    'user_integration_id' => $userIntegrationId, 'status' => 1, 'mapping_type' => 'cross'
                ])->first();
                if ($map) {
                    $nsLocationObj = PlatformObjectData::find($map->destination_row_id);
                    $toLocationId = $nsLocationObj && $nsLocationObj->api_id ? $nsLocationObj->api_id : null;
                }
            }

            $fromLocationObj = PlatformObjectData::where([
                'user_integration_id' => $userIntegrationId, 'api_id' => $shipment->warehouse_id,
                'platform_object_id' => $this->helper->getObjectId('warehouse'), 'platform_id' => $sPlatformId
            ])->first();
            $fromLocationId = null;
            if ($fromLocationObj) {
                $map = PlatformDataMapping::where([
                    'source_row_id' => $fromLocationObj->id, 'platform_object_id' => $this->helper->getObjectId('order_warehouse'),
                    'user_integration_id' => $userIntegrationId, 'status' => 1, 'mapping_type' => 'cross'
                ])->first();
                if ($map) {
                    $nsLocationObj = PlatformObjectData::find($map->destination_row_id);
                    $fromLocationId = $nsLocationObj && $nsLocationObj->api_id ? $nsLocationObj->api_id : null;
                }
            }

            if (!$toLocationId && !$fromLocationId) {
                $errorFlag = 1;
                $errorMsg .= 'Location mapping not found';
            }

            $items = [];
            if (!$errorFlag) {
                foreach ($shipment->platformShippingLines as $lineItem) {
                    $sProduct = PlatformProduct::where([
                        'user_integration_id' => $userIntegrationId, 'platform_id' => $sPlatformId,
                        'api_product_id' => $lineItem->product_id
                    ])->first();
                    if ($sProduct) {
                        $nsProduct = $this->getProductMapping($nsService, $sProduct, $this->platformId, $sProdFieldMatchBy, $dProdFieldMatchBy, 1, $customFieldProductName);
                        if ($nsProduct) {
                            array_push($items, ['internalId' => $nsProduct->api_product_id, 'quantity' => $lineItem->quantity]);
                        } else {
                            $errorMsg .= 'Netsuite product not found';
                            $errorFlag = 1;
                        }
                    } else {
                        $errorMsg .= 'Source product not found';
                        $errorFlag = 1;
                    }
                }
            }

            if (!$errorFlag) {
                $data = [];
                $data['location'] = $fromLocationId;
                $data['to_location'] = $toLocationId;
                $data['memo'] = 'IT-' . $shipment->shipment_id;
                $data['subsidiary'] = $subsidiaryId;
                $data['items'] = $items;
                $res = $this->netsuiteApi->CreateInventoryTransfer($nsService, $data);
                if ($res != false && $res != 0 && !isset($res['error'])) {
                    $newShipment = new PlatformOrderShipment();
                    $newShipment->user_id = $userId;
                    $newShipment->platform_id = $this->platformId;
                    $newShipment->user_integration_id = $userIntegrationId;
                    $newShipment->shipment_id  = $res;
                    $newShipment->sync_status  = PlatformStatus::SYNCED;
                    $newShipment->warehouse_id = $fromLocationId;
                    $newShipment->to_warehouse_id = $toLocationId;
                    $newShipment->shipment_transfer = 1;
                    $newShipment->type = PlatformRecordType::TRANSFER;
                    $newShipment->save();
                } else if (isset($res['error'])) {
                    $errorMsg .= 'Error: ' . $res['error'];
                    $errorFlag = 1;
                } else {
                    $errorMsg .= 'Unexpected error occurred.';
                    $errorFlag = 1;
                }
            }

            if ($errorFlag) {
                $shipment->sync_status = PlatformStatus::FAILED;
                $this->log->syncLog($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformId, $this->platformId, null, 'failed', $shipment->id, $errorMsg);
            } else {
                $shipment->sync_status = PlatformStatus::SYNCED;
                $this->log->syncLog($userId, $userIntegrationId, $userWorkflowRuleId, $sPlatformId, $this->platformId, null, 'success', $shipment->id, 'Transfer Order synced successfully');
            }
            $shipment->save();
            if ($retryId && $retryId != null) {
                return $errorFlag ? $errorMsg : true;
            }
        }
    }

    public function CreateInventoryAdjustment($userId, $userIntegrationId, $sPlatformName, $userWorkflowId, $retryId = null)
    {
        $sPlatformId = $this->helper->getPlatformIdByName($sPlatformName);
        $nsService = $this->netsuiteApi->GetNetsuiteService($userIntegrationId, $this->platformId);

        $query = PlatformProduct::where('user_integration_id', $userIntegrationId)->where('platform_id', $sPlatformId)
            ->where('inventory_sync_status', PlatformStatus::READY)->where('is_deleted', 0);
        if ($retryId) {
            $query = PlatformProduct::where('id', $retryId);
        }
        $products = $query->take(25)->get();

        $prodIds = $products->pluck('id');
        if ($prodIds) {
            PlatformProduct::whereIn('id', $prodIds)->update(['inventory_sync_status' => PlatformStatus::PROCESSING]);
        }

        foreach ($products as $product) {
            $res = $this->netsuiteSyncServices->createInventoryAdjustmentForProduct($nsService, $userIntegrationId, $product, $userWorkflowId, ($retryId ? true : false));

            if ($retryId) {
                return $res;
            }
        }

        $check_old_processing_records = PlatformProduct::where('user_integration_id', $userIntegrationId)->where('platform_id', $sPlatformId)->where('inventory_sync_status', PlatformStatus::PROCESSING)->whereDate('updated_at', '!=', date('Y-m-d'))->where('is_deleted', 0)->pluck('id')->toArray();
        if (count($check_old_processing_records)) {
            PlatformProduct::whereIn('id', $check_old_processing_records)
                ->update(['inventory_sync_status' => PlatformStatus::READY]);
        }

        return true;
    }

    /* Get PriceList Method */
    public function GetPriceList($user_id, $user_integration_id, $is_initial_sync)
    {
        $return_response = true;
        try {

            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
            if ($service !== false) {
                $list = $this->netsuiteApi->GetNetsuitePriceLevels($service, 50);
                if ($list === false) {
                    $return_response = "API Error";
                } else if (is_string($list)) {
                    $return_response = $list;
                } else if (isset($list['recordList']) && is_array($list['recordList']) && count($list['recordList'])) {
                    $objectId = $this->helper->getObjectId('pricelist');
                    $apiInternalId = [];
                    PlatformObjectData::where([
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'platform_object_id' => $objectId
                    ])->update(['status' => 0]);
                    foreach ($list['recordList'] as $record) {

                        $insertList = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'name' => $record->name, 'api_id' => $record->internalId, 'status' => 1, 'platform_object_id' => $objectId];

                        $where = [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'platform_object_id' => $objectId,
                            'api_id' => $record->internalId,
                        ];
                        PlatformObjectData::updateOrCreate($where, $insertList);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "-- NetsuiteApiController GetPriceList -->" . $e->getMessage() . '--->' . $e->getLine());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Update Sales Order as acknowledge | Basically only status is updated */
    public function UpdateSalesOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_name, $sync_status = "Pending", $record_id)
    {

        $return_response = true;

        try {
            $limit = 10;
            $sales_order_object_id = $this->helper->getObjectId('sales_order');
            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);

            if ($service !== false) {
                $source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);

                $conditions = ['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'is_deleted' => 0];

                if ($record_id) {
                    $conditions['id'] = $record_id;
                } else {
                    $conditions['sync_status'] = $sync_status;
                }

                $orders = PlatformOrder::where($conditions)->take($limit)->orderBy('id', 'asc')->get();

                if (count($orders) > 0) {
                    /** Get default NS Location */
                    $default_location_id = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_location", ['api_id']);
                    $default_location = $default_location_id && $default_location_id->api_id ? $default_location_id->api_id : NULL;

                    /** Default Subsidiary */
                    $subsidiary = $this->mapping->getMappedDataByName($user_integration_id, null, "inventory_subsidiary", ['api_id']);
                    $subsidiary = $subsidiary ? $subsidiary->api_id : NULL;
                    /** Default customFieldAcknowledge */
                    $customFieldAcknowledge = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_acknowledge", ['custom_data'], "default");

                    $customFieldAcknowledge = isset($customFieldAcknowledge->custom_data) ? $customFieldAcknowledge->custom_data : NULL;


                    foreach ($orders as $order) {
                        if ($order->linked_id > 0) {
                            $netsuiteOrder = PlatformOrder::find($order->linked_id);

                            if ($netsuiteOrder) {
                                $order_array = [];
                                //order data
                                $order_array['internalId'] =  $netsuiteOrder->api_order_id;

                                if (!empty($default_location)) {
                                    // $order_array['location'] = $default_location;
                                }

                                if (!empty($subsidiary)) {
                                    $order_array['subsidiary'] = $subsidiary;
                                }
                                if ($customFieldAcknowledge) {
                                    $breakCustomField = explode('=', $customFieldAcknowledge);
                                    $CFieldInternalId = isset($breakCustomField[0]) ? $breakCustomField[0] : null;
                                    $CFieldValue = isset($breakCustomField[1]) ? $breakCustomField[1] : null;
                                    $order_array['custom_fields'][] = ['fieldType' => "string", 'internalId' => $CFieldInternalId, 'value' => $CFieldValue];
                                }

                                if ($order_array && $customFieldAcknowledge) {
                                    $response =  $this->netsuiteApi->UpdateSalesOrder($service, $order_array);

                                    if ($response === false || $response == 0 || isset($response['error'])) {
                                        $order->sync_status = "Failed";
                                        $order->order_updated_at = date_create();
                                        $order->save();
                                        $return_response = 'Order sync failed ' . @$response['error'];
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'failed', $order->id, $return_response);
                                    } else {
                                        $order->sync_status = "Synced";
                                        $order->order_updated_at = date_create();
                                        $order->save();
                                        $return_response = 'Order synced successfully';
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'success', $order->id, $return_response);
                                    }
                                } else {
                                    $order->sync_status = "Failed";
                                    $order->order_updated_at = date_create();
                                    $order->save();
                                    $return_response = 'Empty payload is not supported to update order';
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'failed', $order->id, $return_response);
                                }
                            }
                        } else {
                            $order->sync_status = "Ignore";
                            $order->order_updated_at = date_create();
                            $order->save();
                            $return_response = 'No order detail found in Netsuite';
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'failed', $order->id, $return_response);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get Sales Order By Date Filter */
    // public function GetSalesOrderBackup($user_id, $user_integration_id)
    // {

    //     $return_response = false;
    //     try {
    //         $limit = 20;
    //         $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);
    //         if ($account) {
    //             $q = DB::table('user_workflow_rule as ur')->select('e.event_id', 'ur.platform_workflow_rule_id', 'ur.sync_start_date')
    //                 ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
    //                 ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
    //                 ->where('pr.status', 1)
    //                 ->where('ur.status', 1)
    //                 ->where('e.status', 1)
    //                 ->where('ur.user_id', $user_id)->where('ur.user_integration_id', $user_integration_id);
    //             if ($q->count() > 0) {
    //                 $order_sync_start_date = $q->pluck('ur.sync_start_date')->first();
    //                 $user_workflow = $q->pluck('e.event_id')->toArray();
    //                 $EventID = "GET_SALESORDER";
    //                 if ($user_workflow) {

    //                     /* Check whether shipment is ON */
    //                     if (in_array($EventID, $user_workflow)) {
    //                         $platform_urls =   PlatformUrl::where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'ns_order_lasttime'])
    //                             ->select('url', 'id')->first();
    //                         if ($platform_urls) {
    //                             /* If Order last time found */
    //                             $modified_after = $this->netsuiteSyncServices->updateDateTimeISOFormat(trim($platform_urls->url), "|");
    //                             $five_sec_minus = \Carbon\Carbon::parse($modified_after)->subSeconds(2)->format('c');
    //                             $modified_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($five_sec_minus);
    //                         } else {
    //                             $modified_after = $this->netsuiteSyncServices->getLastOrderDateTime($user_id, $user_integration_id, $order_sync_start_date);
    //                         }
    //                         $end_created_on_date = $modified_after;
    //                         $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
    //                         if ($service) {

    //                             $orderList = $this->netsuiteApi->GetNetsuiteOrder($service, $end_created_on_date, $limit);

    //                             if (isset($orderList['recordList']) && is_array($orderList['recordList'])) {
    //                                 /* Return all multi selected order status */

    //                                 $order_location_object_id = $this->helper->getObjectId('location');
    //                                 $orderStatusArray = $this->mapping->getMappedDataByName($user_integration_id, null, "get_sorder_status", ['api_id'], "regular", null, "multi", "source");
    //                                 $customChannel = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_channel", ['custom_data'], "default");
    //                                 $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
    //                                 $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;


    //                                 foreach ($orderList['recordList'] as $key => $order) {

    //                                     if ($orderStatusArray) {

    //                                         $end_created_on_date = $order->lastModifiedDate;
    //                                         /* First Check Sync Start Date time Set or Not */

    //                                         $byPass = $this->netsuiteSyncServices->isValidOrder($order_sync_start_date, $order->lastModifiedDate);

    //                                         if ($byPass) {

    //                                             if (in_array($order->status, $orderStatusArray)) {

    //                                                 $findOrder = $this->netsuiteSyncServices->checkPlatformOrderExist($user_id, $user_integration_id, $order->internalId);

    //                                                 if (!$findOrder) {
    //                                                     if (isset($customChannel->custom_data)) { //Find Custom Field Values Filter
    //                                                         $breakCustomField = explode('=', $customChannel->custom_data);
    //                                                         $CFieldInternalId = isset($breakCustomField[0]) ? trim($breakCustomField[0]) : null;
    //                                                         $CFieldValues = isset($breakCustomField[1]) ? trim($breakCustomField[1]) : null;
    //                                                         $CFieldValue = [];
    //                                                         if ($CFieldValues) {
    //                                                             $CFieldValue = explode(',', $CFieldValues);
    //                                                         }
    //                                                         $customFields = $this->netsuiteSyncServices->GetSearchOrderCustomField($order);
    //                                                         if ($customFields) {
    //                                                             if (isset($customFields[$CFieldInternalId]) && in_array($customFields[$CFieldInternalId], $CFieldValue)) {
    //                                                                 /* Check  Customer ID If not found search via API Call */
    //                                                     $CustomerEmail =null;
    // if (isset($order->entity->internalId) && $order->entity->internalId !== 0) {
    //     $Customer = $this->netsuiteSyncServices->SearchCustomerByID($order->entity->internalId, $user_id, $user_integration_id, $this->platformId, $service);

    //     if (is_array($Customer)) {
    //         $CustomerID = isset($Customer['customerId'])?$Customer['customerId']:0;
    //         $CustomerEmail = isset($Customer['email'])?$Customer['email']:null;
    //     } else {
    //         $CustomerID = 0;
    //     }
    // } else {
    //     $CustomerID = 0;
    // }
    //                                                                 $order->platform_customer_id = $CustomerID;

    //                                                                 $order->warehouse_id = $this->netsuiteSyncServices->GetOrderLocation($order, $user_id, $user_integration_id, $order_location_object_id);

    //                                                                 $lastOrderID = $this->netsuiteSyncServices->StoreOrderDetails($order, $user_id, $user_integration_id,$service);
    //                                                                 /*-- Store Address-- */
    //                                                                 $this->netsuiteSyncServices->StoreAddress($order, $lastOrderID,$CustomerEmail);
    //                                                                 /* --Insert Line Items--*/
    //                                                                 $this->netsuiteSyncServices->StoreLineItems($order, $lastOrderID, 'insert', $service, $user_id, $user_integration_id, $customFieldProductName);

    //                                                                 /* --Insert Transaction/Payments-- */
    //                                                                 // app('App\Http\Controllers\Netsuite\NetsuiteServices')->StorePaymentDetails($order, $lastOrderID);
    //                                                             }
    //                                                         }
    //                                                     }
    //                                                 }
    //                                             }
    //                                         }
    //                                     }
    //                                 }
    //                                 if ($orderList['recordList']) {

    //                                     if ($platform_urls) {
    //                                         //Update last order fetch created time
    //                                         $platform_urls->url = $end_created_on_date;
    //                                         $platform_urls->save();
    //                                     } else {
    //                                         //insert last order fetch created time
    //                                         PlatformUrl::insert([
    //                                             'user_id' => $user_id,
    //                                             'platform_id' => $this->platformId,
    //                                             'user_integration_id' => $user_integration_id,
    //                                             'url' => $end_created_on_date,
    //                                             'url_name' => 'ns_order_lasttime',
    //                                         ]);
    //                                     }
    //                                 }
    //                             } else {
    //                                 if ($platform_urls == "" || empty($platform_urls)) {
    //                                     //insert last order fetch created time
    //                                     PlatformUrl::insert([
    //                                         'user_id' => $user_id,
    //                                         'platform_id' => $this->platformId,
    //                                         'user_integration_id' => $user_integration_id,
    //                                         'url' => $end_created_on_date,
    //                                         'url_name' => 'ns_order_lasttime',
    //                                     ]);
    //                                 }
    //                             }
    //                         }
    //                     }
    //                 }
    //             }
    //         }
    //     } catch (\Exception $e) {
    //         \Log::error($e->getMessage());
    //         $return_response = $e->getMessage();
    //     }
    //     return $return_response;
    // }
    /* Order Details */
    public function orderDetails($service, $order, $user_id, $user_integration_id, $order_location_object_id, $customFieldProductName)
    {
        /* Check  Customer ID If not found search via API Call */
        $CustomerEmail = null;
        if (isset($order->entity->internalId) && $order->entity->internalId !== 0) {
            $Customer = $this->netsuiteSyncServices->SearchCustomerByID($order->entity->internalId, $user_id, $user_integration_id, $this->platformId, $service);

            if (is_array($Customer)) {
                $CustomerID = isset($Customer['customerId']) ? $Customer['customerId'] : 0;
                $CustomerEmail = isset($Customer['email']) ? $Customer['email'] : null;
            } else {
                $CustomerID = 0;
            }
        } else {
            $CustomerID = 0;
        }
        $order->platform_customer_id = $CustomerID;

        $order->warehouse_id = $this->netsuiteSyncServices->GetOrderLocation($order, $user_id, $user_integration_id, $order_location_object_id);

        $lastOrderID = $this->netsuiteSyncServices->StoreOrderDetails($order, $user_id, $user_integration_id, $service);
        /*-- Store Address-- */
        $this->netsuiteSyncServices->StoreAddress($order, $lastOrderID, $CustomerEmail);
        /* --Insert Line Items--*/
        $this->netsuiteSyncServices->StoreLineItems($order, $lastOrderID, 'insert', $service, $user_id, $user_integration_id, $customFieldProductName);

        /* --Insert Transaction/Payments-- */
        // app('App\Http\Controllers\Netsuite\NetsuiteServices')->StorePaymentDetails($order, $lastOrderID);
    }

    /* Get Sales Order By Date Filter */
    public function GetSalesOrder($user_id, $user_integration_id, $destination_platform_name, $event = null)
    {

        $return_response = false;
        try {
            $limit = 20;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);
            if ($account) {
                $EventID = "GET_SALESORDER";
                $selectFields = ['e.event_id', 'ur.sync_start_date', 'ur.status'];
                $user_workflow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);
                if (isset($user_workflow[$EventID])) {
                    $order_sync_start_date = $user_workflow[$EventID]['sync_start_date'];
                    $fileLog = now()->format('d-m-Y') . "_NSGETORDER_{$user_integration_id}.log";
                    /* Check whether shipment is ON */
                    if ($user_workflow[$EventID]['status'] == 1) {
                        if ($event == "SALESORDERBACKUP") {
                            $url_name = 'ns_order_backup';
                            $modified_date_after =  Carbon::yesterday()->subSecond()->format('c'); //set yesterday's date
                            $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                            $last_date_till =   Carbon::today()->format('c'); //set today's date
                            $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($last_date_till);
                            $operator = "within";
                        } else {
                            $url_name = 'ns_order_lasttime';
                            $operator = "after";
                            $last_date_till = null;
                        }

                        $platform_urls =   PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => $url_name])
                            ->select('url', 'id')->first();

                        $searchId = $pageIndex = null;
                        if (isset($platform_urls->url)) {

                            /* If Order last time found */
                            $dates = $this->netsuiteSyncServices->UrlDate(trim($platform_urls->url), "|");

                            if (is_array($dates)) {
                                //Making Date Range
                                if ($event == "SALESORDERBACKUP") {
                                } else {
                                    $Date1 = Carbon::parse($dates[0])->subSecond()->format('c');
                                    $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date1);
                                    $Date2 = Carbon::parse($dates[1])->addSecond()->format('c');
                                    $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date2);
                                }
                                $searchId = $dates[2];
                                $pageIndex = $dates[3];
                                $operator = "within";
                            } else {
                                if ($event == "SALESORDERBACKUP") {
                                    if (is_null($dates) || empty($dates)) { // if $dates is empty
                                    } else if (!empty($dates) && Carbon::now()->format('Y-m-d') > Carbon::parse($dates)->format('Y-m-d')) { //if current time is greater than equal to old datetime
                                        $modified_date_after =  Carbon::parse($dates)->subSecond()->format('c'); //set yesterday's date
                                        $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                                    } else {
                                        //do nothing if date is same
                                        $modified_date_after = null;
                                        $operator = "donothing";
                                    }
                                } else {
                                    $subDate = Carbon::parse($dates)->subSecond()->format('c');
                                    $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($subDate);
                                    $operator = "after";
                                }
                            }
                        } else {
                            if ($event == "SALESORDERBACKUP") {
                                //if current time is greater than equal to todays datetime
                                if (Carbon::now()->format('c') < Carbon::today()->format('c')) {
                                    $operator = "donothing";
                                }
                            } else {
                                $modified_date_after = $this->netsuiteSyncServices->getLastOrderDateTime($user_id, $user_integration_id, $order_sync_start_date);
                                $operator = "after";
                                // Storage::disk('local')->append($fileLog, 'Read Last Record Date: ' . $modified_date_after . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                            }
                        }
                        if ($last_date_till) {
                            $end_created_on_date = $modified_date_after . "|" . $last_date_till . "|" . $searchId . "|" . $pageIndex;
                        } else {
                            $end_created_on_date = $modified_date_after;
                        }
                        if ($end_created_on_date && !empty($end_created_on_date) && $operator != "donothing") {
                            $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                            if ($service) {
                                // Storage::disk('local')->append($fileLog, 'Before API Call Modified Date: ' . $modified_date_after . ' Last Date: ' . $last_date_till . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                                if ($searchId && $pageIndex) { //Increase page index to get next page data
                                    $pageIndex = $pageIndex + 1;
                                }
                                $orderList = $this->netsuiteApi->GetNetsuiteOrder($service, $modified_date_after, $last_date_till, $operator, $limit, $searchId, $pageIndex);
                                if ($event == "SALESORDERBACKUP") {
                                    //    Storage::disk('local')->append($fileLog, 'Response: ' . print_r($orderList, true) . ' Read Url Date: ' .  $end_created_on_date . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                                }
                                if (isset($orderList['recordList']) && is_array($orderList['recordList']) && count($orderList['recordList'])) {

                                    $order_location_object_id = $this->helper->getObjectId('location');
                                    $orderStatusArray = $this->mapping->getMappedDataByName($user_integration_id, null, "get_sorder_status", ['api_id'], "regular", null, "multi", "source");
                                    $customChannel = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_channel", ['custom_data'], "default");
                                    $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                                    $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;
                                    $lastModifiedDate = null;

                                    foreach ($orderList['recordList'] as $order) {
                                        if ($operator == "within") {
                                            $searchId = $orderList['searchId'];
                                            $pageIndex = $orderList['pageIndex'];
                                        }
                                        if ($event == "SALESORDERBACKUP") {
                                            $lastModifiedDate = $last_date_till;
                                        } else {
                                            if (!$lastModifiedDate) {
                                                $lastModifiedDate = \Carbon\Carbon::parse($order->lastModifiedDate)->format('c');
                                            } else if (\Carbon\Carbon::parse($order->lastModifiedDate)->format('c') > $lastModifiedDate) {
                                                $lastModifiedDate = \Carbon\Carbon::parse($order->lastModifiedDate)->format('c');
                                            }
                                        }


                                        $end_created_on_date = $modified_date_after . "|" . $lastModifiedDate . "|" . $searchId . "|" . $pageIndex;


                                        /* Main Logic */
                                        if ($orderStatusArray) {

                                            /* First Check Sync Start Date time Set or Not */

                                            $byPass = $this->netsuiteSyncServices->isValidOrder($order_sync_start_date, $order->lastModifiedDate);

                                            if ($byPass) {
                                                $findOrder = $this->netsuiteSyncServices->checkPlatformOrderExist($user_id, $user_integration_id, $order->internalId);
                                                if (!$findOrder) {
                                                    if (in_array($order->status, $orderStatusArray)) {

                                                        //  $findOrder = $this->netsuiteSyncServices->checkPlatformOrderExist($user_id, $user_integration_id, $order->internalId);

                                                        // if (!$findOrder) {

                                                        if (isset(\Config::get('apisettings.AllowOrderCustomFilterInNetSuite')[$destination_platform_name])) {
                                                            if (isset($customChannel->custom_data)) { //Find Custom Field Values Filter
                                                                $breakCustomField = explode('=', $customChannel->custom_data);
                                                                $CFieldInternalId = isset($breakCustomField[0]) ? trim($breakCustomField[0]) : null;
                                                                $CFieldValues = isset($breakCustomField[1]) ? trim($breakCustomField[1]) : null;
                                                                $CFieldValue = [];
                                                                if ($CFieldValues) {
                                                                    $CFieldValue = explode(',', $CFieldValues);
                                                                }
                                                                $customFields = $this->netsuiteSyncServices->GetSearchOrderCustomField($order);
                                                                if ($customFields) {
                                                                    if (isset($customFields[$CFieldInternalId]) && in_array($customFields[$CFieldInternalId], $CFieldValue)) {
                                                                        $this->orderDetails($service, $order, $user_id, $user_integration_id, $order_location_object_id, $customFieldProductName);
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            $this->orderDetails($service, $order, $user_id, $user_integration_id, $order_location_object_id, $customFieldProductName);
                                                        }

                                                        //   }
                                                        // else{
                                                        //     $findOrder->api_updated_at = $order->lastModifiedDate;
                                                        //     $findOrder->save();


                                                        // }
                                                    }
                                                } else {
                                                    if ($findOrder->api_updated_at != $order->lastModifiedDate) {

                                                        if (in_array($destination_platform_name, [])) { //if platform is snowflake handle canncel order || input destination platform name to activate this logic
                                                            $cancelStatus = $order->status == "Cancelled" ? 1 : 0;
                                                            if ($findOrder->sync_status == PlatformStatus::SYNCED) {

                                                                if ($cancelStatus) {
                                                                    $findOrder->sync_status = PlatformStatus::READY;
                                                                    $findOrder->is_voided = $cancelStatus;
                                                                    $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                                }
                                                            } else if ($findOrder->sync_status == PlatformStatus::READY || $findOrder->sync_status == PlatformStatus::PENDING || $findOrder->sync_status == PlatformStatus::FAILED) {

                                                                if ($cancelStatus) {
                                                                    $findOrder->sync_status = PlatformStatus::IGNORE;
                                                                    $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                                }
                                                            }
                                                        }

                                                        $findOrder->api_updated_at = $order->lastModifiedDate;
                                                        $findOrder->save();
                                                    }
                                                }
                                            }
                                        }
                                        /* --------- */
                                    }

                                    if ($orderList['recordList']) {

                                        if ($platform_urls) {

                                            if ($operator == "within" && $orderList['totalPages'] == $orderList['pageIndex'] &&  $platform_urls->url) {
                                                //  Storage::disk('local')->append($fileLog, 'After API Call Save Date: NULL  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                                //Update last order fetch created time

                                                if ($event == "SALESORDERBACKUP") {
                                                    $platform_urls->url = Carbon::today()->format('c');
                                                } else {
                                                    $platform_urls->url = null;
                                                }

                                                $platform_urls->save();
                                            } else {
                                                // Storage::disk('local')->append($fileLog, 'After API Call Save Date: ' . $end_created_on_date . '  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                                //Update last order fetch created time
                                                $platform_urls->url = $end_created_on_date;
                                                $platform_urls->save();
                                            }
                                        } else {
                                            //insert last order fetch created time
                                            PlatformUrl::insert([
                                                'user_id' => $user_id,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $user_integration_id,
                                                'url' => $end_created_on_date,
                                                'url_name' =>  $url_name,
                                            ]);
                                        }
                                    }
                                } else {
                                    if (!$platform_urls) {
                                        //insert last order fetch created time
                                        PlatformUrl::insert([
                                            'user_id' => $user_id,
                                            'platform_id' => $this->platformId,
                                            'user_integration_id' => $user_integration_id,
                                            'url' => $end_created_on_date,
                                            'url_name' =>  $url_name,
                                        ]);
                                    } else {
                                        //  Storage::disk('local')->append($fileLog, 'After API Call Save Date At Last: NULL Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last order fetch created time
                                        if ($event == "SALESORDERBACKUP") {
                                            $platform_urls->url = Carbon::today()->format('c');
                                        } else {
                                            $platform_urls->url = null;
                                        }
                                        $platform_urls->save();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function GetSalesOrderDemo($user_id, $user_integration_id)
    {


        $return_response = false;
        try {
            $limit = 20;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);
            if ($account) {
                $EventID = "GET_SALESORDER";
                $selectFields = ['e.event_id', 'ur.sync_start_date', 'ur.status'];

                $user_workflow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);
                if (isset($user_workflow[$EventID])) {
                    $order_sync_start_date = $user_workflow[$EventID]['sync_start_date'];
                    $fileLog = now()->format('d-m-Y') . "_NSGETORDER_{$user_integration_id}.log";
                    /* Check whether shipment is ON */
                    if ($user_workflow[$EventID]['status'] == 1) {
                        $platform_urls =   PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'ns_order_lasttime'])
                            ->select('url', 'id')->first();

                        $last_date_till = null;
                        $operator = "after";
                        if ($platform_urls->url) {
                            // Storage::disk('local')->append($fileLog, ' Read Url Date: '.$platform_urls->url. "  Current Date: ".now()->format('d-m-Y H:i:s'));
                            /* If Order last time found */
                            $dates = $this->netsuiteSyncServices->UrlDate(trim($platform_urls->url), "|");

                            if (is_array($dates)) {
                                //Making Date Range
                                $Date1 = Carbon::parse($dates[0])->subSecond()->format('c');
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date1);
                                $Date2 = Carbon::parse($dates[1])->addSecond()->format('c');
                                $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date2);
                                $operator = "within";
                            } else {
                                $subDate = Carbon::parse($dates)->subSecond()->format('c');
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($subDate);
                                $operator = "after";
                            }
                        } else {
                            $modified_date_after = $this->netsuiteSyncServices->getLastOrderDateTime($user_id, $user_integration_id, $order_sync_start_date);
                            $operator = "after";
                            //  Storage::disk('local')->append($fileLog, 'Read Last Record Date: '.$modified_date_after. "  Current Date: ".now()->format('d-m-Y H:i:s'));
                        }
                        if ($last_date_till) {
                            $end_created_on_date = $modified_date_after . "|" . $last_date_till;
                        } else {
                            $end_created_on_date = $modified_date_after;
                        }

                        $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                        if ($service) {
                            //  Storage::disk('local')->append($fileLog, 'Before API Call Modified Date: '.$modified_date_after.' Last Date: '. $last_date_till. "  Current Date: ".now()->format('d-m-Y H:i:s'));
                            $orderList = $this->netsuiteApi->GetNetsuiteOrder($service, $modified_date_after, $last_date_till, $operator, $limit, null, null);
                            echo "<pre>";
                            print_r($orderList);
                            dd($orderList, $modified_date_after, $last_date_till, $operator, $limit);
                            if (isset($orderList['recordList']) && is_array($orderList['recordList'])) {

                                $order_location_object_id = $this->helper->getObjectId('location');
                                $orderStatusArray = $this->mapping->getMappedDataByName($user_integration_id, null, "get_sorder_status", ['api_id'], "regular", null, "multi", "source");
                                $customChannel = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_channel", ['custom_data'], "default");
                                $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                                $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;

                                foreach ($orderList['recordList'] as $order) {
                                    $end_created_on_date = $modified_date_after . "|" . $order->lastModifiedDate;
                                    $last_date_till = $order->lastModifiedDate;


                                    /* Main Logic */
                                    if ($orderStatusArray) {

                                        /* First Check Sync Start Date time Set or Not */

                                        $byPass = $this->netsuiteSyncServices->isValidOrder($order_sync_start_date, $order->lastModifiedDate);

                                        if ($byPass) {

                                            if (in_array($order->status, $orderStatusArray)) {

                                                $findOrder = $this->netsuiteSyncServices->checkPlatformOrderExist($user_id, $user_integration_id, $order->internalId);

                                                if (!$findOrder) {
                                                    if (isset($customChannel->custom_data)) { //Find Custom Field Values Filter
                                                        $breakCustomField = explode('=', $customChannel->custom_data);
                                                        $CFieldInternalId = isset($breakCustomField[0]) ? trim($breakCustomField[0]) : null;
                                                        $CFieldValues = isset($breakCustomField[1]) ? trim($breakCustomField[1]) : null;
                                                        $CFieldValue = [];
                                                        if ($CFieldValues) {
                                                            $CFieldValue = explode(',', $CFieldValues);
                                                        }
                                                        $customFields = $this->netsuiteSyncServices->GetSearchOrderCustomField($order);
                                                        if ($customFields) {
                                                            if (isset($customFields[$CFieldInternalId]) && in_array($customFields[$CFieldInternalId], $CFieldValue)) {
                                                                /* Check  Customer ID If not found search via API Call */
                                                                $CustomerEmail = null;
                                                                if (isset($order->entity->internalId) && $order->entity->internalId !== 0) {
                                                                    $Customer = $this->netsuiteSyncServices->SearchCustomerByID($order->entity->internalId, $user_id, $user_integration_id, $this->platformId, $service);

                                                                    if (is_array($Customer)) {
                                                                        $CustomerID = isset($Customer['customerId']) ? $Customer['customerId'] : 0;
                                                                        $CustomerEmail = isset($Customer['email']) ? $Customer['email'] : null;
                                                                    } else {
                                                                        $CustomerID = 0;
                                                                    }
                                                                } else {
                                                                    $CustomerID = 0;
                                                                }
                                                                $order->platform_customer_id = $CustomerID;

                                                                $order->warehouse_id = $this->netsuiteSyncServices->GetOrderLocation($order, $user_id, $user_integration_id, $order_location_object_id);

                                                                $lastOrderID = $this->netsuiteSyncServices->StoreOrderDetails($order, $user_id, $user_integration_id, $service);
                                                                /*-- Store Address-- */
                                                                $this->netsuiteSyncServices->StoreAddress($order, $lastOrderID, $CustomerEmail);
                                                                /* --Insert Line Items--*/
                                                                $this->netsuiteSyncServices->StoreLineItems($order, $lastOrderID, 'insert', $service, $user_id, $user_integration_id, $customFieldProductName);

                                                                /* --Insert Transaction/Payments-- */
                                                                // app('App\Http\Controllers\Netsuite\NetsuiteServices')->StorePaymentDetails($order, $lastOrderID);
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    /* --------- */
                                }


                                if ($orderList['recordList']) {

                                    if ($platform_urls) {

                                        if ($modified_date_after <= $last_date_till &&  $platform_urls->url) {
                                            Storage::disk('local')->append($fileLog, 'After API Call Save Date: NULL  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                            //Update last order fetch created time
                                            $platform_urls->url = null;
                                            $platform_urls->save();
                                        } else {
                                            Storage::disk('local')->append($fileLog, 'After API Call Save Date: ' . $end_created_on_date . '  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                            //Update last order fetch created time
                                            $platform_urls->url = $end_created_on_date;
                                            $platform_urls->save();
                                        }
                                    } else {
                                        //insert last order fetch created time
                                        PlatformUrl::insert([
                                            'user_id' => $user_id,
                                            'platform_id' => $this->platformId,
                                            'user_integration_id' => $user_integration_id,
                                            'url' => $end_created_on_date,
                                            'url_name' => 'ns_order_lasttime',
                                        ]);
                                    }
                                }
                            } else {
                                if (!$platform_urls) {
                                    //insert last order fetch created time
                                    PlatformUrl::insert([
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'url' => $end_created_on_date,
                                        'url_name' => 'ns_order_lasttime',
                                    ]);
                                } else {
                                    if ($modified_date_after <= $last_date_till && $platform_urls->url) {
                                        Storage::disk('local')->append($fileLog, 'After API Call Save Date At Last: NULL Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last order fetch created time
                                        $platform_urls->url = null;
                                        $platform_urls->save();
                                    } else {
                                        Storage::disk('local')->append($fileLog, 'After API Call Save Date At Last: ' . $end_created_on_date . '  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last order fetch created time
                                        $platform_urls->url = $end_created_on_date;
                                        $platform_urls->save();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get Sales Order By Internal ID */
    public function SyncSalesOrderByInternalId($user_id, $user_integration_id, $internalId)
    {

        $return_response = false;
        try {
            $limit = 20;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);
            if ($account) {

                $EventID = "GET_SALESORDER";
                $selectFields = ['e.event_id', 'ur.sync_start_date', 'ur.status'];

                $user_workflow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields,  self::$myPlatform);

                if (isset($user_workflow[$EventID])) {
                    $order_sync_start_date = $user_workflow[$EventID]['sync_start_date'];
                    /* Check whether shipment is ON */
                    if ($user_workflow[$EventID]['status'] == 1) {

                        $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                        if ($service) {

                            $order = $this->netsuiteApi->GetNetsuiteOrderByInternalID($service, $internalId);

                            if (isset($order->createdDate)) {
                                /* Return all multi selected order status */
                                $order_location_object_id = $this->helper->getObjectId('location');
                                $orderStatusArray = $this->mapping->getMappedDataByName($user_integration_id, null, "get_sorder_status", ['api_id'], "regular", null, "multi", "source");
                                $customChannel = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_channel", ['custom_data'], "default");
                                $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                                $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;

                                if ($orderStatusArray) {


                                    /* First Check Sync Start Date time Set or Not */

                                    $byPass = 1; // $this->netsuiteSyncServices->isValidOrder($order_sync_start_date, $order->createdDate);

                                    if ($byPass) {

                                        if (in_array($order->status, $orderStatusArray)) {

                                            $findOrder = $this->netsuiteSyncServices->checkPlatformOrderExist($user_id, $user_integration_id, $order->internalId);

                                            if (!$findOrder) {
                                                if (isset($customChannel->custom_data)) { //Find Custom Field Values Filter
                                                    $breakCustomField = explode('=', $customChannel->custom_data);
                                                    $CFieldInternalId = isset($breakCustomField[0]) ? trim($breakCustomField[0]) : null;
                                                    $CFieldValues = isset($breakCustomField[1]) ? trim($breakCustomField[1]) : null;
                                                    $CFieldValue = [];
                                                    if ($CFieldValues) {
                                                        $CFieldValue = explode(',', $CFieldValues);
                                                    }
                                                    $customFields = $this->netsuiteSyncServices->GetSearchOrderCustomField($order);
                                                    if ($customFields) {
                                                        if (isset($customFields[$CFieldInternalId]) && in_array($customFields[$CFieldInternalId], $CFieldValue)) {
                                                            /* Check  Customer ID If not found search via API Call */
                                                            $CustomerEmail = null;
                                                            if (isset($order->entity->internalId) && $order->entity->internalId !== 0) {
                                                                $Customer = $this->netsuiteSyncServices->SearchCustomerByID($order->entity->internalId, $user_id, $user_integration_id, $this->platformId, $service);

                                                                if (is_array($Customer)) {
                                                                    $CustomerID = isset($Customer['customerId']) ? $Customer['customerId'] : 0;
                                                                    $CustomerEmail = isset($Customer['email']) ? $Customer['email'] : null;
                                                                } else {
                                                                    $CustomerID = 0;
                                                                }
                                                            } else {
                                                                $CustomerID = 0;
                                                            }
                                                            $order->platform_customer_id = $CustomerID;

                                                            $order->warehouse_id = $this->netsuiteSyncServices->GetOrderLocation($order, $user_id, $user_integration_id, $order_location_object_id);

                                                            $lastOrderID = $this->netsuiteSyncServices->StoreOrderDetails($order, $user_id, $user_integration_id, $service);
                                                            /*-- Store Address-- */
                                                            $this->netsuiteSyncServices->StoreAddress($order, $lastOrderID, $CustomerEmail);
                                                            /* --Insert Line Items--*/
                                                            $this->netsuiteSyncServices->StoreLineItems($order, $lastOrderID, 'insert', $service, $user_id, $user_integration_id, $customFieldProductName);

                                                            /* --Insert Transaction/Payments-- */
                                                            // app('App\Http\Controllers\Netsuite\NetsuiteServices')->StorePaymentDetails($order, $lastOrderID);

                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        dd("created_date_greater than mapping");
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get Products */
    public function getProducts($event, $user_id, $user_integration_id, $destination_platform_name, $is_initial_sync = 0)
    {
        $return_response = true;
        try {
            $limit = 50; // default limit
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);

            if ($account) {
                $columnDBFilter = "api_updated_at";
                $columnColumnFilter = $columnFilter = "lastModifiedDate"; // default column filter which is oky for default for all
                //make sure here to change column filter as per your platform requirement
                if (isset(\Config::get('apisettings.AllowColumWiseFilterInNetsuite')[$destination_platform_name])) {
                    $columnFilter = "created";
                    $columnColumnFilter = "createdDate";
                    $columnDBFilter = "api_created_at";
                }
                if ($event == "PRODUCTBACKUP") {
                    $url_name = 'ns_product_backup';
                    $modified_date_after =  Carbon::yesterday()->subSecond()->format('c'); //set yesterday's date
                    $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                    $last_date_till =   Carbon::today()->format('c'); //set today's date
                    $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($last_date_till);
                    $operator = "within";
                } else {
                    $url_name = 'ns_product_lasttime';
                    $operator = "after";
                    $last_date_till = null;
                }

                $platform_urls =   PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => $url_name])
                    ->select('url', 'id')->first();

                $searchId = $pageIndex = null;
                if (isset($platform_urls->url)) {
                    /* If product last time found */
                    $dates = $this->netsuiteSyncServices->UrlDate(trim($platform_urls->url), "|");
                    if (is_array($dates)) {
                        //making Date Range
                        if ($event == "PRODUCTBACKUP") {
                        } else {
                            if ($dates[0]) {
                                $Date1 = Carbon::parse($dates[0])->subSecond()->format('c');
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date1);
                            } else {
                                $modified_date_after = $this->netsuiteSyncServices->getLastProductDateTime($user_id, $user_integration_id, $columnDBFilter);
                            }
                            $Date2 = Carbon::parse($dates[1])->addSecond()->format('c');
                            $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date2);
                        }
                        $searchId = $dates[2];
                        $pageIndex = $dates[3];
                        $operator = "within";
                    } else {
                        if ($event == "PRODUCTBACKUP") {
                            if (is_null($dates) || empty($dates)) { // if $dates is empty
                            } else if (!empty($dates) && Carbon::now()->format('Y-m-d') > Carbon::parse($dates)->format('Y-m-d')) { //if current time is greater than equal to old datetime
                                $modified_date_after =  Carbon::parse($dates)->subSecond()->format('c'); //set yesterday's date
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                            } else {
                                //do nothing if date is same
                                $modified_date_after = null;
                                $operator = "donothing";
                            }
                        } else {
                            $subDate = Carbon::parse($dates)->subSecond()->format('c');
                            $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($subDate);
                            $operator = "after";
                        }
                    }
                } else {
                    if ($event == "PRODUCTBACKUP") {
                        //if current time is greater than equal to todays datetime
                        if (Carbon::now()->format('c') < Carbon::today()->format('c')) {
                            $operator = "donothing";
                        }
                    } else {
                        $modified_date_after = $this->netsuiteSyncServices->getLastProductDateTime($user_id, $user_integration_id, $columnDBFilter);
                        $operator = "after";
                        // Storage::disk('local')->append($fileLog, 'Read Last Record Date: ' . $modified_date_after . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                    }
                }
                if ($last_date_till) {
                    $end_created_on_date = $modified_date_after . "|" . $last_date_till . "|" . $searchId . "|" . $pageIndex;
                } else {
                    $end_created_on_date = $modified_date_after;
                }
                if ($operator != "donothing") {
                    $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                    if ($service) {

                        // Storage::disk('local')->append($fileLog, 'Before API Call Modified Date: ' . $modified_date_after . ' Last Date: ' . $last_date_till . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                        if ($searchId && $pageIndex) { //Increase page index to get next page data
                            $pageIndex = $pageIndex + 1;
                        }
                        $productList = $this->netsuiteApi->searchNetsuiteProductList($service, $modified_date_after, $last_date_till, $columnFilter, $operator, $limit, $searchId, $pageIndex);

                        if (isset($productList['recordList']) && is_array($productList['recordList']) && count($productList['recordList'])) {


                            $lastModifiedDate = null;

                            foreach ($productList['recordList'] as $product) {
                                if ($operator == "within") {
                                    $searchId = $productList['searchId'];
                                    $pageIndex = $productList['pageIndex'];
                                }
                                if ($event == "PRODUCTBACKUP") {
                                    $lastModifiedDate = $last_date_till;
                                } else {
                                    if (!$lastModifiedDate) {
                                        $lastModifiedDate = \Carbon\Carbon::parse($product->$columnColumnFilter)->format('c');
                                    } else if (\Carbon\Carbon::parse($product->$columnColumnFilter)->format('c') > $lastModifiedDate) {
                                        $lastModifiedDate = \Carbon\Carbon::parse($product->$columnColumnFilter)->format('c');
                                    }
                                }

                                $end_created_on_date = $modified_date_after . "|" . $lastModifiedDate . "|" . $searchId . "|" . $pageIndex;
                                $findProduct = $this->netsuiteSyncServices->checkPlatformProductExist($user_id, $user_integration_id, $product->internalId);
                                if (!$findProduct) {
                                    //if (!$product->isInactive) { //accept only active products
                                    $this->netsuiteSyncServices->prepareProductData($product, $user_id, $user_integration_id, $destination_platform_name, $service);
                                    //}
                                } else {
                                    $findProduct->api_updated_at = $product->lastModifiedDate;
                                    $findProduct->api_created_at = $product->createdDate;
                                    $findProduct->save();
                                }


                                /* --------- */
                            }

                            if ($productList['recordList']) {

                                if ($platform_urls) {

                                    if ($operator == "within" && $productList['totalPages'] == $productList['pageIndex'] &&  $platform_urls->url) {
                                        //  Storage::disk('local')->append($fileLog, 'After API Call Save Date: NULL  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last product fetch created time

                                        if ($event == "PRODUCTRBACKUP") {
                                            $platform_urls->url = Carbon::today()->format('c');
                                        } else {
                                            $platform_urls->url = null;
                                        }

                                        $platform_urls->save();
                                    } else {
                                        // Storage::disk('local')->append($fileLog, 'After API Call Save Date: ' . $end_created_on_date . '  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last product fetch created time
                                        $platform_urls->url = $end_created_on_date;
                                        $platform_urls->save();
                                    }
                                } else {
                                    //insert last product fetch created time
                                    PlatformUrl::insert([
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'url' => $end_created_on_date,
                                        'url_name' =>  $url_name,
                                    ]);
                                }
                            }
                        } else {
                            if (!$platform_urls) {
                                //insert last product fetch created time
                                PlatformUrl::insert([
                                    'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'url' => $end_created_on_date,
                                    'url_name' =>  $url_name,
                                ]);
                            } else {
                                //  Storage::disk('local')->append($fileLog, 'After API Call Save Date At Last: NULL Current Date: ' . now()->format('d-m-Y H:i:s'));
                                //Update last product fetch created time
                                if ($event == "PRODUCTBACKUP") {
                                    $platform_urls->url = Carbon::today()->format('c');
                                } else {
                                    $platform_urls->url = null;
                                }
                                $platform_urls->save();
                            }
                        }
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - getProducts - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get Vendors */
    public function getVendors($event, $user_id, $user_integration_id, $destination_platform_name, $is_initial_sync = 0)
    {
        $return_response = true;
        try {
            $limit = 50; // default limit
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);

            if ($account) {
                $columnDBFilter = "api_updated_at";
                $columnFilter = "lastModifiedDate"; // default column filter which is oky for default for all
                //make sure here to change column filter as per your platform requirement
                if (isset(\Config::get('apisettings.AllowColumWiseFilterInNetsuite')[$destination_platform_name])) {
                    $columnFilter = "dateCreated";
                    $columnDBFilter = "api_created_at";
                }
                if ($event == "VENDORBACKUP") {
                    $url_name = 'ns_vendor_backup';
                    $modified_date_after =  Carbon::yesterday()->subSecond()->format('c'); //set yesterday's date
                    $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                    $last_date_till =   Carbon::today()->format('c'); //set today's date
                    $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($last_date_till);
                    $operator = "within";
                } else {
                    $url_name = 'ns_vendor_lasttime';
                    $operator = "after";
                    $last_date_till = null;
                }

                $platform_urls =   PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => $url_name])
                    ->select('url', 'id')->first();

                $searchId = $pageIndex = null;
                if (isset($platform_urls->url)) {

                    /* If product last time found */
                    $dates = $this->netsuiteSyncServices->UrlDate(trim($platform_urls->url), "|");

                    if (is_array($dates)) {
                        //making Date Range
                        if ($event == "VENDORBACKUP") {
                        } else {
                            if ($dates[0]) {
                                $Date1 = Carbon::parse($dates[0])->subSecond()->format('c');
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date1);
                            } else {
                                $modified_date_after = $this->netsuiteSyncServices->getLastVendorDateTime($user_id, $user_integration_id, $columnDBFilter);
                            }

                            $Date2 = Carbon::parse($dates[1])->addSecond()->format('c');
                            $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date2);
                        }

                        $searchId = $dates[2];
                        $pageIndex = $dates[3];
                        $operator = "within";
                    } else {
                        if ($event == "VENDORBACKUP") {
                            if (is_null($dates) || empty($dates)) { // if $dates is empty
                            } else if (!empty($dates) && Carbon::now()->format('Y-m-d') > Carbon::parse($dates)->format('Y-m-d')) { //if current time is greater than equal to old datetime
                                $modified_date_after =  Carbon::parse($dates)->subSecond()->format('c'); //set yesterday's date
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                            } else {
                                //do nothing if date is same
                                $modified_date_after = null;
                                $operator = "donothing";
                            }
                        } else {
                            $subDate = Carbon::parse($dates)->subSecond()->format('c');
                            $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($subDate);
                            $operator = "after";
                        }
                    }
                } else {
                    if ($event == "VENDORBACKUP") {
                        //if current time is greater than equal to todays datetime
                        if (Carbon::now()->format('c') < Carbon::today()->format('c')) {
                            $operator = "donothing";
                        }
                    } else {
                        $modified_date_after = $this->netsuiteSyncServices->getLastVendorDateTime($user_id, $user_integration_id, $columnDBFilter);
                        $operator = "after";
                        // Storage::disk('local')->append($fileLog, 'Read Last Record Date: ' . $modified_date_after . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                    }
                }

                if ($last_date_till) {
                    $end_created_on_date = $modified_date_after . "|" . $last_date_till . "|" . $searchId . "|" . $pageIndex;
                } else {
                    $end_created_on_date = $modified_date_after;
                }
                if ($operator != "donothing") {
                    $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                    if ($service) {

                        // Storage::disk('local')->append($fileLog, 'Before API Call Modified Date: ' . $modified_date_after . ' Last Date: ' . $last_date_till . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                        if ($searchId && $pageIndex) { //Increase page index to get next page data
                            $pageIndex = $pageIndex + 1;
                        }

                        $vendorList = $this->netsuiteApi->searchNetsuiteVendorList($service, $modified_date_after, $last_date_till, $columnFilter, $operator, $limit, $searchId, $pageIndex);
                        if (isset($vendorList['recordList']) && is_array($vendorList['recordList']) && count($vendorList['recordList'])) {


                            $lastModifiedDate = null;

                            foreach ($vendorList['recordList'] as $vendor) {

                                if ($operator == "within") {
                                    $searchId = $vendorList['searchId'];
                                    $pageIndex = $vendorList['pageIndex'];
                                }
                                if ($event == "VENDORBACKUP") {
                                    $lastModifiedDate = $last_date_till;
                                } else {
                                    if (!$lastModifiedDate) {
                                        $lastModifiedDate = \Carbon\Carbon::parse($vendor->$columnFilter)->format('c');
                                    } else if (\Carbon\Carbon::parse($vendor->$columnFilter)->format('c') > $lastModifiedDate) {
                                        $lastModifiedDate = \Carbon\Carbon::parse($vendor->$columnFilter)->format('c');
                                    }
                                }

                                $end_created_on_date = $modified_date_after . "|" . $lastModifiedDate . "|" . $searchId . "|" . $pageIndex;
                                $findRecord = $this->netsuiteSyncServices->checkPlatformVendorExist($user_id, $user_integration_id, $vendor->internalId);
                                if (!$findRecord) {

                                    //    if (!$vendor->isInactive) {//accept only active products
                                    $this->netsuiteSyncServices->prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync, $service);

                                    //    }
                                } else {
                                    $findRecord->api_updated_at = $vendor->lastModifiedDate;
                                    $findRecord->api_created_at = $vendor->dateCreated;
                                    $findRecord->save();
                                }


                                /* --------- */
                            }

                            if ($vendorList['recordList']) {

                                if ($platform_urls) {

                                    if ($operator == "within" && $vendorList['totalPages'] == $vendorList['pageIndex'] &&  $platform_urls->url) {
                                        //  Storage::disk('local')->append($fileLog, 'After API Call Save Date: NULL  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last product fetch created time

                                        if ($event == "VENDORBACKUP") {
                                            $platform_urls->url = Carbon::today()->format('c');
                                        } else {
                                            $platform_urls->url = null;
                                        }

                                        $platform_urls->save();
                                    } else {
                                        // Storage::disk('local')->append($fileLog, 'After API Call Save Date: ' . $end_created_on_date . '  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last product fetch created time
                                        $platform_urls->url = $end_created_on_date;
                                        $platform_urls->save();
                                    }
                                } else {
                                    //insert last product fetch created time
                                    PlatformUrl::insert([
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'url' => $end_created_on_date,
                                        'url_name' =>  $url_name,
                                    ]);
                                }
                            }
                        } else {
                            if (!$platform_urls) {
                                //insert last product fetch created time
                                PlatformUrl::insert([
                                    'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'url' => $end_created_on_date,
                                    'url_name' =>  $url_name,
                                ]);
                            } else {
                                //  Storage::disk('local')->append($fileLog, 'After API Call Save Date At Last: NULL Current Date: ' . now()->format('d-m-Y H:i:s'));
                                //Update last product fetch created time
                                if ($event == "VENDORBACKUP") {
                                    $platform_urls->url = Carbon::today()->format('c');
                                } else {
                                    $platform_urls->url = null;
                                }
                                $platform_urls->save();
                            }
                        }
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - getVendors - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get NetsuiteInventory */
    public function getInventory($event, $user_id, $user_integration_id)
    {
        $return_response = true;
        try {
            $limit = 1000; // default limit
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);

            if ($account) {
                if ($event == "INVENTORYBACKUP") {
                    $url_name = 'ns_inventory_backup';
                    $modified_date_after =  Carbon::yesterday()->subSecond()->format('c'); //set yesterday's date
                    $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                    $last_date_till =   Carbon::today()->format('c'); //set today's date
                    $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($last_date_till);
                    $operator = "within";
                } else {
                    $url_name = 'ns_inventory_lasttime';
                    $operator = "after";
                    $last_date_till = null;
                }

                $platform_urls =   PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => $url_name])
                    ->select('url', 'id')->first();

                $searchId = $pageIndex = null;
                if (isset($platform_urls->url)) {

                    /* If product inventory last time found */
                    $dates = $this->netsuiteSyncServices->UrlDate(trim($platform_urls->url), "|");

                    if (is_array($dates)) {
                        //making Date Range
                        if ($event == "INVENTORYBACKUP") {
                        } else {
                            if ($dates[0]) {
                                $Date1 = Carbon::parse($dates[0])->subSecond()->format('c');
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date1);
                            } else {
                                $modified_date_after = $this->netsuiteSyncServices->getLastInventoryDateTime($user_id, $user_integration_id);
                            }

                            $Date2 = Carbon::parse($dates[1])->addSecond()->format('c');
                            $last_date_till = $this->netsuiteSyncServices->updateDateTimeISOFormat($Date2);
                        }

                        $searchId = $dates[2];
                        $pageIndex = $dates[3];
                        $operator = "within";
                    } else {
                        if ($event == "INVENTORYBACKUP") {
                            if (is_null($dates) || empty($dates)) { // if $dates is empty
                            } else if (!empty($dates) && Carbon::now()->format('Y-m-d') > Carbon::parse($dates)->format('Y-m-d')) { //if current time is greater than equal to old datetime
                                $modified_date_after =  Carbon::parse($dates)->subSecond()->format('c'); //set yesterday's date
                                $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($modified_date_after);
                            } else {
                                //do nothing if date is same
                                $modified_date_after = null;
                                $operator = "donothing";
                            }
                        } else {
                            $subDate = Carbon::parse($dates)->subSecond()->format('c');
                            $modified_date_after = $this->netsuiteSyncServices->updateDateTimeISOFormat($subDate);
                            $operator = "after";
                        }
                    }
                } else {
                    if ($event == "INVENTORYBACKUP") {
                        //if current time is greater than equal to todays datetime
                        if (Carbon::now()->format('c') < Carbon::today()->format('c')) {
                            $operator = "donothing";
                        }
                    } else {
                        $modified_date_after = $this->netsuiteSyncServices->getLastInventoryDateTime($user_id, $user_integration_id);
                        $operator = "after";
                        // Storage::disk('local')->append($fileLog, 'Read Last Record Date: ' . $modified_date_after . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                    }
                }

                if ($last_date_till) {
                    $end_created_on_date = $modified_date_after . "|" . $last_date_till . "|" . $searchId . "|" . $pageIndex;
                } else {
                    $end_created_on_date = $modified_date_after;
                }
                if ($operator != "donothing") {
                    $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                    if ($service) {

                        // Storage::disk('local')->append($fileLog, 'Before API Call Modified Date: ' . $modified_date_after . ' Last Date: ' . $last_date_till . "  Current Date: " . now()->format('d-m-Y H:i:s'));
                        if ($searchId && $pageIndex) { //Increase page index to get next page data
                            $pageIndex = $pageIndex + 1;
                        }

                        $inventoryList = $this->netsuiteApi->searchNetsuiteInventoryList($service, $modified_date_after, $last_date_till, $operator, $limit, $searchId, $pageIndex);
                        if (isset($inventoryList['recordList']) && is_array($inventoryList['recordList']) && count($inventoryList['recordList'])) {


                            $lastModifiedDate = null;
                            $findProducts = [];
                            foreach ($inventoryList['recordList'] as $item) {
                                //dd($item);
                                if ($operator == "within") {
                                    $searchId = $inventoryList['searchId'];
                                    $pageIndex = $inventoryList['pageIndex'];
                                }
                                if ($event == "INVENTORYBACKUP") {
                                    $lastModifiedDate = $last_date_till;
                                } else {
                                    if (!$lastModifiedDate) {
                                        $lastModifiedDate = \Carbon\Carbon::parse($item->basic->lastQuantityAvailableChange[0]->searchValue)->format('c');
                                    } else if (\Carbon\Carbon::parse($item->basic->lastQuantityAvailableChange[0]->searchValue)->format('c') > $lastModifiedDate) {
                                        $lastModifiedDate = \Carbon\Carbon::parse($item->basic->lastQuantityAvailableChange[0]->searchValue)->format('c');
                                    }
                                }


                                $end_created_on_date = $modified_date_after . "|" . $lastModifiedDate . "|" . $searchId . "|" . $pageIndex;
                                $internalId = $item->basic->internalId[0]->searchValue->internalId;

                                if (isset($findProducts[$internalId])) {
                                    $findRecord = $findProducts[$internalId];
                                } else {

                                    $findRecord = $this->netsuiteSyncServices->checkPlatformProductExist($user_id, $user_integration_id, $internalId);
                                    $findProducts[$internalId] = $findRecord;
                                }

                                if ($findRecord) {
                                    $item->platform_product_id = $findRecord->id;

                                    $this->netsuiteSyncServices->updateOrCreateProductInventory($user_id, $user_integration_id, $item);
                                    if ($findRecord->api_inventory_lastmodified_time != $item->basic->lastQuantityAvailableChange[0]->searchValue) {
                                        $findRecord->inventory_sync_status = PlatformStatus::READY;
                                        $findRecord->api_inventory_lastmodified_time = $item->basic->lastQuantityAvailableChange[0]->searchValue;
                                        $findRecord->save();
                                    }
                                }


                                /* --------- */
                            }

                            if ($inventoryList['recordList']) {

                                if ($platform_urls) {

                                    if ($operator == "within" && $inventoryList['totalPages'] == $inventoryList['pageIndex'] &&  $platform_urls->url) {
                                        //  Storage::disk('local')->append($fileLog, 'After API Call Save Date: NULL  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last product fetch created time

                                        if ($event == "INVENTORYBACKUP") {
                                            $platform_urls->url = Carbon::today()->format('c');
                                        } else {
                                            $platform_urls->url = null;
                                        }

                                        $platform_urls->save();
                                    } else {
                                        // Storage::disk('local')->append($fileLog, 'After API Call Save Date: ' . $end_created_on_date . '  Current Date: ' . now()->format('d-m-Y H:i:s'));
                                        //Update last product fetch created time
                                        $platform_urls->url = $end_created_on_date;
                                        $platform_urls->save();
                                    }
                                } else {
                                    //insert last product fetch created time
                                    PlatformUrl::insert([
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'url' => $end_created_on_date,
                                        'url_name' =>  $url_name,
                                    ]);
                                }
                            }
                        } else {
                            if (!$platform_urls) {
                                //insert last product fetch created time
                                PlatformUrl::insert([
                                    'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'url' => $end_created_on_date,
                                    'url_name' =>  $url_name,
                                ]);
                            } else {
                                //  Storage::disk('local')->append($fileLog, 'After API Call Save Date At Last: NULL Current Date: ' . now()->format('d-m-Y H:i:s'));
                                //Update last product fetch created time
                                if ($event == "INVENTORYBACKUP") {
                                    $platform_urls->url = Carbon::today()->format('c');
                                } else {
                                    $platform_urls->url = null;
                                }
                                $platform_urls->save();
                            }
                        }
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - getInventory - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function GetOrderByID($user_id, $user_integration_id, $internalId)
    {

        $return_response = false;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id']);
            if ($account) {
                $EventID = "GET_SALESORDER";
                $selectFields = ['e.event_id', 'ur.sync_start_date', 'ur.status'];

                $user_workflow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);
                if (isset($user_workflow[$EventID])) {
                    $order_sync_start_date = $user_workflow[$EventID]['sync_start_date'];
                    /* Check whether shipment is ON */
                    if ($user_workflow[$EventID]['status'] == 1) {


                        $service =  $this->netsuiteApi->GetNetsuiteService($user_integration_id, $this->platformId);
                        if ($service) {

                            $order = $this->netsuiteApi->GetNetsuiteOrderByInternalID($service, $internalId);

                            if (isset($order->createdDate)) {
                                /* Return all multi selected order status */

                                $order_location_object_id = $this->helper->getObjectId('location');
                                $orderStatusArray = $this->mapping->getMappedDataByName($user_integration_id, null, "get_sorder_status", ['api_id'], "regular", null, "multi", "source");
                                $customChannel = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_channel", ['custom_data'], "default");
                                $customFieldProductName = $this->mapping->getMappedDataByName($user_integration_id, null, "custom_field_product_identifier", ['custom_data'], "default");
                                $customFieldProductName = isset($customFieldProductName->custom_data) ? $customFieldProductName->custom_data : null;

                                if ($orderStatusArray) {
                                    /* First Check Sync Start Date time Set or Not */
                                    $byPass = $this->netsuiteSyncServices->isValidOrder($order_sync_start_date, $order->lastModifiedDate);
                                    //dd($order,"1");
                                    if ($byPass) {
                                        if (in_array($order->status, $orderStatusArray)) {
                                            // dd($order,"2");
                                            $findOrder = $this->netsuiteSyncServices->checkPlatformOrderExist($user_id, $user_integration_id, $order->internalId);

                                            if (!$findOrder) {
                                                if (isset($customChannel->custom_data)) { //Find Custom Field Values Filter
                                                    $breakCustomField = explode('=', $customChannel->custom_data);
                                                    $CFieldInternalId = isset($breakCustomField[0]) ? trim($breakCustomField[0]) : null;
                                                    $CFieldValues = isset($breakCustomField[1]) ? trim($breakCustomField[1]) : null;
                                                    $CFieldValue = [];
                                                    if ($CFieldValues) {
                                                        $CFieldValue = explode(',', $CFieldValues);
                                                    }
                                                    $customFields = $this->netsuiteSyncServices->GetSearchOrderCustomField($order);
                                                    if ($customFields) {
                                                        if (isset($customFields[$CFieldInternalId]) && in_array($customFields[$CFieldInternalId], $CFieldValue)) {
                                                            /* Check  Customer ID If not found search via API Call */
                                                            $CustomerEmail = null;
                                                            if (isset($order->entity->internalId) && $order->entity->internalId !== 0) {
                                                                $Customer = $this->netsuiteSyncServices->SearchCustomerByID($order->entity->internalId, $user_id, $user_integration_id, $this->platformId, $service);

                                                                if (is_array($Customer)) {
                                                                    $CustomerID = isset($Customer['customerId']) ? $Customer['customerId'] : 0;
                                                                    $CustomerEmail = isset($Customer['email']) ? $Customer['email'] : null;
                                                                } else {
                                                                    $CustomerID = 0;
                                                                }
                                                            } else {
                                                                $CustomerID = 0;
                                                            }
                                                            $order->platform_customer_id = $CustomerID;

                                                            $order->warehouse_id = $this->netsuiteSyncServices->GetOrderLocation($order, $user_id, $user_integration_id, $order_location_object_id);

                                                            $lastOrderID = $this->netsuiteSyncServices->StoreOrderDetails($order, $user_id, $user_integration_id, $service);
                                                            /*-- Store Address-- */
                                                            $this->netsuiteSyncServices->StoreAddress($order, $lastOrderID, $CustomerEmail);
                                                            /* --Insert Line Items--*/
                                                            $this->netsuiteSyncServices->StoreLineItems($order, $lastOrderID, 'insert', $service, $user_id, $user_integration_id, $customFieldProductName);

                                                            /* --Insert Transaction/Payments-- */
                                                            // app('App\Http\Controllers\Netsuite\NetsuiteServices')->StorePaymentDetails($order, $lastOrderID);
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteApiController - GetOrderByID - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function ExecuteEventNetsuite($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
            if ($method == 'GET' && $event == 'LOCATION') {
                $response =  $this->NetsuiteGetLocations($user_id, $user_integration_id, $source_platform_id, $destination_platform_id);
            } else if ($method == 'GET' && $event == 'SALESORDERCUSTOMFIELDS') {
                $response =  $this->NetsuiteGetCustomFieldsList($user_id, $user_integration_id, 'sales_order');
            } else if ($method == 'GET' && $event == 'PURCHASEORDERCUSTOMFIELDS') {
                $response =  $this->NetsuiteGetCustomFieldsList($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'PRODUCTCUSTOMFIELDS') {
                $response =  $this->NetsuiteGetCustomFieldsList($user_id, $user_integration_id, 'product');
            } else if ($method == 'GET' && $event == 'FORM') {
                $response =  $this->NetsuiteGetForms($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'SUBSIDIARY') {
                $response =  $this->NetsuiteGetSubsidiary($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'CLASSIFICATION') {
                $response = $this->GetAllClassifications($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'PAYMENTACCOUNTS') {
                $response =  $this->GetAllAccounts($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'TAXCODE') {
                $response = $this->GetAllTaxCodes($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'DISCOUNTITEMS') {
                $response =  $this->GetAllDiscountItems($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'SHIPPINGITEMS') {
                $response =  $this->GetAllShippingItems($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'PRICELIST') {
                $response =  $this->GetPriceList($user_id, $user_integration_id, $is_initial_sync);
            } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
                $sync_status = PlatformStatus::READY;
                if (isset(\Config::get('apisettings.AllowOrderCreationInNetSuiteForBP')[$source_platform_id])) { //Only for brightpearl

                    $response = $this->CreateOrdersByType($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_status, $record_id);
                } else {
                    /* other than brightpearl */
                    $response = $this->createPOOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
                }
            } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
                $sync_status = 'Ready';
                $this->CreateOrdersByType($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_status, $record_id, 'sales_orders');
            } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
                $sync_status = 'Ready';
                $response = $this->CreateItemFulfillment($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id, 'sales_order');
            } else if ($method == 'GET' && $event == 'TRANSFERORDERRECEIPT') {
                $sync_status = PlatformStatus::PENDING;
                $response = $this->getReceipts($user_id, $user_integration_id, $sync_status, "TO");
            } else if ($method == 'GET' && $event == 'PURCHASEORDERRECEIPT') {
                $sync_status = PlatformStatus::PENDING;
                $response = $this->getReceipts($user_id, $user_integration_id, $sync_status, "PO");
            } else if ($method == 'MUTATE' && $event == 'ITEMRECIEPT') {
                $this->CreateItemReceipt($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
            } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
                $sync_status = 'Ready';
                $response = $this->SyncInventory($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_status, $record_id);
            } else if ($method == 'MUTATE' && $event == 'TRANSFERORDER') {
                $sync_status = PlatformStatus::READY;
                $response =   $this->CreateOrdersByType($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_status, $record_id, 'transfer_orders');
            } else if ($method == 'MUTATE' && $event == 'INVOICES') {
                $sync_status = 'Ready';
                $this->CreateOrdersByType($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_status, $record_id, 'invoice_orders');
            } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
                $sync_status = 'Ready';
                $this->CreateProduct($user_id, $source_platform_id, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id, $record_id);
            } else if ($method == 'MUTATE' && $event == 'INVENTORYADJUSTMENT') {
                $sync_status = PlatformStatus::READY;
                $this->CreateInventoryAdjustment($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id);
            } else if ($method == 'MUTATE' && $event == 'INVENTORYTRANSFER') {
                $response =  $this->CreateInventoryTransfers($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
            } else if ($method == 'MUTATE' && $event == 'TRANSFERORDERTO') {
                $sync_status = PlatformStatus::READY;
                $response = $this->createTOOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            } else if ($method == 'GET' && $event == 'SALESORDER') {
                $response =  $this->GetSalesOrder($user_id, $user_integration_id, $destination_platform_id, $event);
            } else if ($method == 'GET' && $event == 'SALESORDERBACKUP') {
                $response =  $this->GetSalesOrder($user_id, $user_integration_id, $destination_platform_id, $event);
            } else if ($method == 'MUTATE' && $event == 'UPDATESALESORDER') {
                $response =  $this->UpdateSalesOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, "Pending", $record_id);
            } else if ($method == 'GET' && $event == 'PRODUCT') {
                $response =  $this->getProducts($event, $user_id, $user_integration_id, $destination_platform_id, $is_initial_sync);
            } else if ($method == 'GET' && $event == 'PRODUCTBACKUP') {
                $response =  $this->getProducts($event, $user_id, $user_integration_id, $destination_platform_id, $is_initial_sync);
            } else if ($method == 'GET' && $event == 'VENDOR') {
                $response =  $this->getVendors($event, $user_id, $user_integration_id, $destination_platform_id, $is_initial_sync);
            } else if ($method == 'GET' && $event == 'VENDORBACKUP') {
                $response =  $this->getVendors($event, $user_id, $user_integration_id, $destination_platform_id, $is_initial_sync);
            } else if ($method == 'GET' && $event == 'INVENTORY') {
                $response =  $this->getInventory($event, $user_id, $user_integration_id);
            }
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /* Sync Missing Order Manually */
    public function missingOrders($orderIds)
    {
        if (!empty($orderIds)) {
            $orderIds = explode(",", $orderIds);
            if ($orderIds && count($orderIds) < 4) {
                foreach ($orderIds as $id) {
                    if ($id) {
                        (app('App\Http\Controllers\Netsuite\NetsuiteApiController')->GetOrderByID(337, 309, $id));
                        sleep(3);
                    }
                }
                dd("Orders are synced " . print_r($orderIds, true));
            } else {
                dd("You can only sync 3 orders at a time");
            }
        }
    }

    public function test(Request $request)
    {
        $user_id = $userId = Auth::user()->id;
        // echo '<pre>';
        // $sync_status = 'Ready';
        $userIntegrationId = 702; // 131;
        $user_work_id = 232; // //192;


        $bp = new \App\Http\Controllers\Brightpearl\BrightPearlApiController();
        $bp_api = new \App\Helper\Api\BrightpearlApi();
        // dd($user_id);
        //dd($this->GetSalesOrder($user_id, 702, "snowflake", "SALESORDER"));
       // dd($this->getInventory("INVENTORY", $user_id, 749));

        dd($this->getReceipts($user_id, 702, "Pending", "PO"));
        dd(app('App\Http\Controllers\Snowflake\SnowflakeApiController')->createOrderReceipt($user_id, 702, 7, 1558, 248, null, "SKU", "PO"));

        //  $product = $this->netsuiteApi->GetInventoryByItemExternalID($service, 844686, "internalId");
        //    dd($this->netsuiteSyncServices->prepareProductData($product, $user_id, 702, $service));

        //}
        dd();
        // dd(app('App\Http\Controllers\Snowflake\SnowflakeApiController')->CreateVendor($user_id, $userIntegrationId, 7, "netsuite",1368, null));
        //  dd($this->netsuiteApi->getProductById($service));
        //  dd($this->getProducts("PRODUCT", $user_id, $userIntegrationId, "snowflake", 0));
        dd("STOP");
    }
}
