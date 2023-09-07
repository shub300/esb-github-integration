<?php

namespace App\Helper\Api;

use App\CountryCodes;
use App\Helper\MainModel;
use App\Common;
use App\Models\Enum\CustomFieldType;
use Illuminate\Support\Collection;
use NetSuite\Classes\Address;
use NetSuite\Classes\BooleanCustomFieldRef;
use NetSuite\Classes\ClassificationSearchBasic;
use NetSuite\Classes\Country;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerAddressbook;
use NetSuite\Classes\CustomerAddressbookList;
use NetSuite\Classes\CustomerDeposit; //
use NetSuite\Classes\CustomizationRefList;
use NetSuite\Classes\CustomizationType;
use NetSuite\Classes\DateCustomFieldRef;
use NetSuite\Classes\Deposit;
use NetSuite\Classes\DepositApplicationApply;
use NetSuite\Classes\DiscountItem;
use NetSuite\Classes\GetAllRecord;
use NetSuite\Classes\GetCustomizationIdRequest;
use NetSuite\Classes\GetCustomizationType;
use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\InventoryTransfer;
use NetSuite\Classes\InventoryTransferInventory;
use NetSuite\Classes\InventoryTransferInventoryList;
use NetSuite\Classes\ItemType;
use NetSuite\Classes\LandedCostMethod;
use NetSuite\Classes\NonInventorySaleItem;
use NetSuite\Classes\NullField;
use NetSuite\Classes\SalesOrderItem;
use NetSuite\Classes\SalesOrderItemList;
use NetSuite\Classes\SalesTaxItem;
use NetSuite\Classes\SalesTaxItemSearch;
use NetSuite\Classes\SalesTaxItemSearchBasic;
use NetSuite\Classes\SearchStringFieldOperator;
use NetSuite\NetSuiteService;
use NetSuite\Classes\GetDataCenterUrlsRequest;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\Subsidiary;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\VendorSearchBasic;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\EmployeeSearchBasic;
use NetSuite\Classes\LocationSearchBasic;
use NetSuite\Classes\SubsidiarySearchBasic;
use NetSuite\Classes\TransactionSearchBasic;
use NetSuite\Classes\SearchDateField;
use NetSuite\Classes\ItemSearchBasic;
use NetSuite\Classes\StringCustomField;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Currency;
use NetSuite\Classes\GetAllRequest;
use NetSuite\Classes\UpdateRequest;
use NetSuite\Classes\SelectCustomFieldRef;
use NetSuite\Classes\RecordRefList;
//use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\PurchaseOrder;
use NetSuite\Classes\PurchaseOrderItemList;
use NetSuite\Classes\PurchaseOrderItem;
use NetSuite\Classes\TransferOrder;
use NetSuite\Classes\TransferOrderItemList;
use NetSuite\Classes\TransferOrderItem;
use NetSuite\Classes\getSelectValue;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\Invoice;
use NetSuite\Classes\InvoiceItem;
use NetSuite\Classes\InvoiceItemList;
use NetSuite\Classes\getSelectValueRequest;
use NetSuite\Classes\GetSelectValueFieldDescription;
use NetSuite\Classes\Vendor;
use NetSuite\Classes\InitializeRecord;
use NetSuite\Classes\InitializeRef;
use NetSuite\Classes\InitializeRequest;
#use NetSuite\Classes\ItemFulfillmentPackage;
#use NetSuite\Classes\ItemFulfillmentPackageList;
#use NetSuite\Classes\ItemFulfillment;
#use NetSuite\Classes\ItemFulfillmentItemList ;
#use NetSuite\Classes\ItemFulfillmentItem;
use NetSuite\Classes\InventoryAdjustment;
use NetSuite\Classes\InventoryAdjustmentInventory;
use NetSuite\Classes\InventoryAdjustmentInventoryList;
use NetSuite\Classes\ItemSearchAdvanced;
use NetSuite\Classes\ItemSearchRow;
use NetSuite\Classes\ItemSearchRowBasic;
use NetSuite\Classes\TransferOrderOrderStatus;
use NetSuite\Classes\ItemVendorList;
use NetSuite\Classes\ItemVendor;
use NetSuite\Classes\LocationSearchRowBasic;
use NetSuite\Classes\Preferences;
use NetSuite\Classes\Price;
use NetSuite\Classes\PriceLevel;
use NetSuite\Classes\PriceList;
use NetSuite\Classes\Pricing;
use NetSuite\Classes\PricingMatrix;
use NetSuite\Classes\PurchaseOrderExpense;
use NetSuite\Classes\PurchaseOrderExpenseList;
use NetSuite\Classes\PriceLevelSearch;
use NetSuite\Classes\SearchColumnDateField;
use NetSuite\Classes\SearchColumnDoubleField;
use NetSuite\Classes\SearchColumnSelectField;
use NetSuite\Classes\SearchColumnStringField;
use NetSuite\Classes\SearchDoubleField;
use NetSuite\Classes\SearchEnumMultiSelectField;
use NetSuite\Classes\SearchMoreWithIdRequest;
use NetSuite\Classes\SearchMultiSelectField;
use NetSuite\Classes\SearchPreferences;
use NetSuite\Classes\TransactionSearch;
use NetSuite\Classes\TransactionSearchAdvanced;

class NetsuiteApi
{
    public $mobj;

    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function GetNetsuiteService($user_integration_id = 0, $platform_id = 0, $account = null, $consumerKey = null, $consumerSecret = null, $token = null, $tokenSecret = null, $host = 'https://webservices.netsuite.com', $endpoint = '2020_2')
    {
        if ($user_integration_id && $platform_id) {
            $nsToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['account_name', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'api_domain']);

            if (empty($nsToken)) {
                return false;
            }

            $config = [
                // required -------------------------------------
                "endpoint"       => $endpoint,
                "host"           => $nsToken->api_domain,
                "account"        => $nsToken->account_name,
                "consumerKey"    => $this->mobj->encrypt_decrypt($nsToken->app_id, 'decrypt'),
                "consumerSecret" => $this->mobj->encrypt_decrypt($nsToken->app_secret, 'decrypt'),
                "token"          => $this->mobj->encrypt_decrypt($nsToken->refresh_token, 'decrypt'),
                "tokenSecret"    => $this->mobj->encrypt_decrypt($nsToken->access_token, 'decrypt'),
                // optional -------------------------------------
                "signatureAlgorithm" => 'sha256', // Defaults to 'sha256'
                //     "logging"  => true,
                //    "log_path" => storage_path('logs/netsuite')
            ];
        } else {
            $config = [
                // required -------------------------------------
                "endpoint"       => $endpoint,
                "host"           => $host,
                "account"        => $account,
                "consumerKey"    => $consumerKey,
                "consumerSecret" => $consumerSecret,
                "token"          => $token,
                "tokenSecret"    => $tokenSecret,
                // optional -------------------------------------
                "signatureAlgorithm" => 'sha256', // Defaults to 'sha256'
                //     "logging"  => true,
                //    "log_path" => storage_path('logs/netsuite')
            ];
        }


        $service = new NetSuiteService($config);

        return $service;
    }


    public function getPO(NetSuiteService  $service, $id)
    {
        $req = new GetRequest();
        $req->baseRef = new RecordRef();
        $req->baseRef->type = RecordType::purchaseOrder;
        $req->baseRef->internalId = $id;

        return json_encode($service->get($req));
    }


    public function GetNetsuiteHostByAccount($service, $netsuite_account)
    {

        $params = new GetDataCenterUrlsRequest();
        $params->account = $netsuite_account;
        $response = $service->getDataCenterUrls($params);

        if ($response->getDataCenterUrlsResult->status->isSuccess) {

            if (($webservicesDomain = @$response->getDataCenterUrlsResult->dataCenterUrls->webservicesDomain) !== NULL) {
                return $webservicesDomain;
            }
        }
        return false;
    }

    public function GetNetsuiteCustomFields(NetSuiteService $service, $type = 'purchase_order')
    {
        $req = new GetCustomizationIdRequest();
        $req->customizationType = new CustomizationType();
        $req->customizationType->getCustomizationType = $type == 'product' ? GetCustomizationType::itemCustomField : GetCustomizationType::transactionBodyCustomField;

        $req->includeInactives = false;

        $res = $service->getCustomizationId($req);
        if (isset($res->getCustomizationIdResult) && $res->getCustomizationIdResult->status->isSuccess) {
            return  $this->GetListsByCustomizationRefList($service, $res->getCustomizationIdResult->customizationRefList, $type);
        }
    }

    public function GetListsByCustomizationRefList(NetSuiteService $service, CustomizationRefList $customizationRefList, $type = 'purchase_order')
    {
        $req = new GetListRequest();
        $baseRefs = [];
        foreach ($customizationRefList->customizationRef as $ref) {
            $baseRef = new RecordRef();
            $baseRef->type = $type == 'product' ?  RecordType::itemCustomField : RecordType::transactionBodyCustomField;

            $baseRef->internalId = $ref->internalId;
            array_push($baseRefs, $baseRef);
        }
        $req->baseRef = $baseRefs;
        $resp =  $service->getList($req);
        if (isset($resp->readResponseList)) {
            if ($resp->readResponseList->status->isSuccess) {
                return $resp->readResponseList->readResponse;
            }
        }
        return false;
    }

    public function GetNetsuiteSubsidiaries($service)
    {
        // Limit to 20 results per page
        $service->setSearchPreferences(true, 1000, true);

        $searchField = new SearchStringField();
        $searchField->operator = "startsWith";
        $searchField->searchValue = "";

        $search = new SubsidiarySearchBasic();

        $search->name = $searchField;


        $request = new SearchRequest();
        $request->searchRecord = $search;

        $searchResponse = $service->search($request);

        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }

    public function GetNetsuiteLocations($service)
    {
        // Limit to 20 results per page
        $service->setSearchPreferences(false, 1000);

        $searchField = new SearchStringField();
        $searchField->operator = "startsWith";
        $searchField->searchValue = "";

        $search = new LocationSearchBasic();

        $search->name = $searchField;


        $request = new SearchRequest();
        $request->searchRecord = $search;

        $searchResponse = $service->search($request);

        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    public function GetNetsuitePriceLevels($service, $Limit, $searchId = null, $pageIndex = null)
    {
        if ($searchId && $pageIndex) {
            $service->setSearchPreferences(false, $Limit, true);
            $searchMoreWithIdRequest = new SearchMoreWithIdRequest();
            $searchMoreWithIdRequest->searchId = $searchId;
            $searchMoreWithIdRequest->pageIndex = $pageIndex;
            $searchResponse = $service->searchMoreWithId($searchMoreWithIdRequest);
        } else {
            $service->setSearchPreferences(false, $Limit, true);
            $pricelevelSearchBasic = new PriceLevelSearch();
            $request = new SearchRequest();
            $request->searchRecord = $pricelevelSearchBasic;
            $searchResponse = $service->search($request);
        }
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    public function getCurrencyList(NetSuiteService  $service)
    {
        $req = new GetRequest();
        $req->baseRef = new RecordRef();
        $req->baseRef->type = RecordType::currency;
        $req->baseRef->internalId = 1;

        return json_encode($service->get($req));
    }
    /* Get Item Fulfillment By Internal ID */
    public function getItemFulfillment($service, $internalID)
    {
        $req = new GetRequest();
        $req->baseRef = new RecordRef();
        $req->baseRef->type = RecordType::itemFulfillment;
        $req->baseRef->internalId = $internalID;

        return json_encode($service->get($req));
    }
    public function GetNetsuiteCustomForms($service)
    {
        $svr = new getSelectValueRequest();
        $svr->fieldDescription = new GetSelectValueFieldDescription();
        $svr->pageIndex = 1;
        $svr->fieldDescription->recordType = RecordType::purchaseOrder;
        $svr->fieldDescription->field = "customForm";
        $gsv = $service->getSelectValue($svr);

        return (array) $gsv;
    }

    public function GetNetsuiteCustomerById($service, $internal_id)
    {
        $request = new GetRequest();
        $request->baseRef = new RecordRef();
        $request->baseRef->internalId = $internal_id;
        $request->baseRef->type = "customer";
        $response = $service->get($request);

        // if ( !empty($response->readResponse->status->isSuccess)) {

        //  if( isset($response->readResponse->record))
        ///{
        $customer = $response->readResponse->record;
        return $customer;
        // }
        //  }
        // return false;

    }


    public function GetNetsuiteProductById($service, $internal_id, $type)
    {
        $request = new GetRequest();
        $request->baseRef = new RecordRef();
        $request->baseRef->internalId = $internal_id;
        $request->baseRef->type = $type;
        $response = $service->get($request);

        // if ( !empty($response->readResponse->status->isSuccess)) {

        //  if( isset($response->readResponse->record))
        ///{
        $customer = $response->readResponse->record;

        if (empty($customer)) {
            $request = new GetRequest();
            $request->baseRef = new RecordRef();
            $request->baseRef->internalId = $internal_id;
            $request->baseRef->type = "nonInventoryPurchaseItem";
            $response = $service->get($request);
            $customer = $response->readResponse->record;
        }
        if (empty($customer)) {
            $request = new GetRequest();
            $request->baseRef = new RecordRef();
            $request->baseRef->internalId = $internal_id;
            $request->baseRef->type = "nonInventoryResaleItem";
            $response = $service->get($request);
            $customer = $response->readResponse->record;
        }
        if (empty($customer)) {
            $request = new GetRequest();
            $request->baseRef = new RecordRef();
            $request->baseRef->internalId = $internal_id;
            $request->baseRef->type = "nonInventorySaleItem";
            $response = $service->get($request);
            $customer = $response->readResponse->record;
        }
        return $customer;
        // }
        //  }
        // return false;

    }

    public function CreateNetsuiteCustomer($service, $data)
    {
        $request = new Customer();

        $request->firstName = $data['firstName'];
        $request->lastName = $data['lastName'];
        $request->companyName = $data['companyName'];
        $request->email = str_replace([' '], '', $data['email']);
        $request->addressbookList = new CustomerAddressbookList();

        $customerAddressBook = new CustomerAddressbook();
        $customerAddressBook->addressbookAddress = new Address();
        $customerAddressBook->addressbookAddress->addr1 = $data['address1'];
        $customerAddressBook->addressbookAddress->addr2 = $data['address2'];
        //        $customerAddressBook->addressbookAddress->addr3 = $data['address3'];
        $customerAddressBook->addressbookAddress->city = $data['address3'];
        $customerAddressBook->addressbookAddress->country = CountryCodes::getNetSuiteSpecificCountryNameByIso($data['country']);
        $customerAddressBook->addressbookAddress->addrPhone = $data['phone'];
        $customerAddressBook->addressbookAddress->addressee = $data['full_name'];

        $request->addressbookList->addressbook = [$customerAddressBook];

        if (!empty($data['phone'])) {
            $request->phone = $data['phone'];
        }

        if (!empty($data['subsidiary'])) {
            $request->subsidiary = new Subsidiary();
            $request->subsidiary->internalId = $data['subsidiary'];
        }


        $request_submit = new AddRequest();
        $request_submit->record = $request;

        $addResponse = $service->add($request_submit);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($baseref_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $baseref_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }


    public function SearchNetsuiteCustomer($service, $search_by, $search_string)
    {
        // Limit to 10 results per page
        $service->setSearchPreferences(false, 10);
        $searchField = new SearchStringField();
        $searchField->operator = "is";
        $searchField->searchValue = str_replace([' '], '', $search_string);
        $search = new CustomerSearchBasic();
        if ($search_by == 'email') {
            $search->email = $searchField;
        } else if ($search_by == 'companyName') {
            $search->companyName = $searchField;
        } else {
            $search->firstName = $searchField;
        }

        $request = new SearchRequest();
        $request->searchRecord = $search;

        $response = $service->search($request);

        $customer = @$response->searchResult->recordList;
        if ($response->searchResult->totalRecords > 0) {
            return json_decode(json_encode($customer), false);
        } else {
            return  0;
        }


        return false;
    }
    /*  */
    public function SearchNetsuiteCustomerByID($service, $InternalID)
    {

        $request = new GetRequest();
        $request->baseRef = new RecordRef();
        $request->baseRef->internalId = $InternalID;
        $request->baseRef->type = "customer";
        $getResponse = $service->get($request);

        if (!$getResponse->readResponse->status->isSuccess) {
            return  "Record not found";
        } else {
            return $getResponse->readResponse->record;
        }

        return false;
    }

    public function GetAllRecords($service)
    {

        $service->setSearchPreferences(false, 20, true);


        $searchMultiSelectEnumField = new SearchEnumMultiSelectField();
        setFields($searchMultiSelectEnumField, array('operator' => 'anyOf', 'searchValue' => "_inventoryItem"));


        $search = new ItemSearchBasic();
        $search->type = $searchMultiSelectEnumField;
        $search->pageIndex = 2;

        $request = new SearchRequest();
        $request->searchRecord = $search;

        $searchResponse = $service->search($request);

        if ($searchResponse->searchResult->totalRecords > 0) {
            return $searchResponse->searchResult->totalRecords;
        } else {
            return  0;
        }


        return false;
    }

    public function SearchNetsuiteVendor($service, $search_by, $search_string)
    {
        // Limit to 10 results per page
        $service->setSearchPreferences(false, 10);

        $searchField = new SearchStringField();
        $searchField->operator = "is";
        $searchField->searchValue = str_replace([' '], '', $search_string); //

        $search = new VendorSearchBasic();

        if ($search_by == 'email') {
            $search->email = $searchField;
        } else if ($search_by == 'companyName') {
            $search->companyName = $searchField;
        } else {
            $search->firstName = $searchField;
        }

        $request = new SearchRequest();
        $request->searchRecord = $search;

        $response = $service->search($request);

        $vendor = @$response->searchResult->recordList;
        if ($response->searchResult->totalRecords > 0) {
            return json_decode(json_encode($vendor), false);
        } else {
            return  0;
        }


        return false;
    }



    public function SearchNetsuiteEmployee($service, $search_by, $search_string)
    {
        // Limit to 10 results per page
        $service->setSearchPreferences(false, 10);

        $searchField = new SearchStringField();
        $searchField->operator = "is";
        $searchField->searchValue = str_replace([' '], '', $search_string); //

        $search = new EmployeeSearchBasic();

        if ($search_by == 'email') {
            $search->email = $searchField;
        } else if ($search_by == 'companyName') {
            $search->companyName = $searchField;
        } else {
            $search->firstName = $searchField;
        }

        $request = new SearchRequest();
        $request->searchRecord = $search;

        $response = $service->search($request);

        $vendor = @$response->searchResult->recordList;
        if ($response->searchResult->totalRecords > 0) {
            return json_decode(json_encode($vendor), false);
        } else {
            return  0;
        }


        return false;
    }

    public function SearchNetsuiteProduct($service, $search_by, $search_string)
    {
        // Limit to 10 results per page
        $service->setSearchPreferences(false, 10, true);

        $searchField = new SearchStringField();
        $searchField->operator = "is";
        $searchField->searchValue = trim($search_string);

        $search = new ItemSearchBasic();

        if ($search_by == 'upc') {
            $search_by = 'upcCode';
        } else if ($search_by == 'product_name') {
            $search_by = 'itemId';
        } else if ($search_by == 'sku') {
            $search_by = 'itemId';
        }

        $search->$search_by = $searchField;
        $request = new SearchRequest();
        $request->searchRecord = $search;

        $response = $service->search($request);


        $product = @$response->searchResult->recordList;

        if ($response->searchResult->totalRecords > 0) {
            return json_decode(json_encode($product), false);
        } else {
            return 0;
        }


        return false;
    }


    /* Get Sales Orders List*/
    public function GetNetsuiteOrder($service, $afterModifiedDate, $lastModifiedDate, $operator, $Limit = 30, $searchId = null, $pageIndex = null)
    {

        if ($searchId && $pageIndex) {
            $service->setSearchPreferences(false, $Limit, true);
            $searchMoreWithIdRequest = new SearchMoreWithIdRequest();
            $searchMoreWithIdRequest->searchId = $searchId;
            $searchMoreWithIdRequest->pageIndex = $pageIndex;
            $searchResponse = $service->searchMoreWithId($searchMoreWithIdRequest);
        } else {
            $service->setSearchPreferences(false, $Limit, true);
            $transactionSearchBasic = new TransactionSearchBasic();

            // $prefs = new Preferences();
            // $service->preferences = $prefs;

            // $searchPreferences = new SearchPreferences();
            // $searchPreferences->bodyFieldsOnly = false;
            // $service->searchPreferences = $searchPreferences;

            $searchMultiSelectEnumField = new SearchEnumMultiSelectField();
            setFields($searchMultiSelectEnumField, array('operator' => 'anyOf', 'searchValue' => "_salesOrder"));


            $searchDateField = new SearchDateField();
            if ($operator == "within") {
                setFields($searchDateField, array(
                    "predefinedSearchValue" => "",
                    "searchValue" => $afterModifiedDate, //from
                    "searchValue2" => $lastModifiedDate, //to date
                    "operator" => $operator,
                ));
            } else {
                setFields($searchDateField, array(
                    "predefinedSearchValue" => "",
                    "searchValue" => $afterModifiedDate, //from
                    // "searchValue2" => $lastModifiedDate,//to date
                    "operator" => $operator
                ));
            }


            $transactionSearchBasic->type = $searchMultiSelectEnumField;
            $transactionSearchBasic->lastModifiedDate = $searchDateField;
            $transactionSearch = new TransactionSearch();
            $transactionSearch->basic = $transactionSearchBasic;

            $request = new SearchRequest();
            $request->searchRecord = $transactionSearch;
            $searchResponse = $service->search($request);
        }


        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    public function GetNetsuiteOrderBackUp($service, $lastModifiedDate, $Limit = 20)
    {
        $service->setSearchPreferences(false, $Limit, true);
        $transactionSearchBasic = new TransactionSearchBasic();

        // $prefs = new Preferences();
        // $service->preferences = $prefs;

        // $searchPreferences = new SearchPreferences();
        // $searchPreferences->bodyFieldsOnly = false;
        // $service->searchPreferences = $searchPreferences;

        $searchMultiSelectEnumField = new SearchEnumMultiSelectField();
        setFields($searchMultiSelectEnumField, array('operator' => 'anyOf', 'searchValue' => "_salesOrder"));


        $searchDateField = new SearchDateField();
        setFields($searchDateField, array(
            "predefinedSearchValue" => "",
            "searchValue" => $lastModifiedDate, //from
            //"searchValue2" => "28/7/2022",//to date
            "operator" => "after",
        ));

        $transactionSearchBasic->type = $searchMultiSelectEnumField;
        $transactionSearchBasic->lastModifiedDate = $searchDateField;
        $transactionSearch = new TransactionSearch();
        $transactionSearch->basic = $transactionSearchBasic;

        $request = new SearchRequest();
        $request->searchRecord = $transactionSearch;
        $searchResponse = $service->search($request);

        //check if the records are present?
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {

            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record];
        }
        return false;
    }
    public function GetNetsuiteOrderAwa($service, $afterModifiedDate, $lastModifiedDate, $operator, $Limit = 30, $searchId = null, $index = null)
    {

        if ($searchId && $index) {
            $service->setSearchPreferences(false, $Limit, true);
            $searchMoreWithIdRequest = new SearchMoreWithIdRequest();
            $searchMoreWithIdRequest->searchId = $searchId;
            $searchMoreWithIdRequest->pageIndex = $index;
            $searchResponse = $service->searchMoreWithId($searchMoreWithIdRequest);
        } else {
            $service->setSearchPreferences(false, $Limit, true);
            $transactionSearchBasic = new TransactionSearchBasic();

            // $prefs = new Preferences();
            // $service->preferences = $prefs;

            // $searchPreferences = new SearchPreferences();
            // $searchPreferences->bodyFieldsOnly = false;
            // $service->searchPreferences = $searchPreferences;

            $searchMultiSelectEnumField = new SearchEnumMultiSelectField();
            setFields($searchMultiSelectEnumField, array('operator' => 'anyOf', 'searchValue' => "_salesOrder"));


            $searchDateField = new SearchDateField();
            if ($operator == "within") {
                setFields($searchDateField, array(
                    "predefinedSearchValue" => "",
                    "searchValue" => $afterModifiedDate, //from
                    "searchValue2" => $lastModifiedDate, //to date
                    "operator" => $operator,
                ));
            } else {
                setFields($searchDateField, array(
                    "predefinedSearchValue" => "",
                    "searchValue" => $afterModifiedDate, //from
                    // "searchValue2" => $lastModifiedDate,//to date
                    "operator" => $operator
                ));
            }


            $transactionSearchBasic->type = $searchMultiSelectEnumField;
            $transactionSearchBasic->lastModifiedDate = $searchDateField;
            $transactionSearch = new TransactionSearch();
            $transactionSearch->basic = $transactionSearchBasic;

            $request = new SearchRequest();
            $request->searchRecord = $transactionSearch;
            $searchResponse = $service->search($request);
        }


        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    /* Get Order Detail By Order Internal ID */
    public function GetNetsuiteOrderByInternalID($service, $InternalID)
    {
        $request = new GetRequest();
        $request->baseRef = new RecordRef();
        $request->baseRef->internalId = $InternalID;
        $request->baseRef->type = "salesOrder";
        $getResponse = $service->get($request);
        if (!$getResponse->readResponse->status->isSuccess) {
            return  "Record not found";
        } else {
            return $getResponse->readResponse->record;
        }

        return false;
    }


    public function CreateNetsuiteVendor($service, $data)
    {
        $request = new Vendor();

        $request->firstName = $data['firstName'];
        $request->lastName = $data['lastName'];

        $request->companyName = $data['companyName'];

        $request->email = str_replace([' '], '', $data['email']);
        if (!empty($data['phone'])) {
            $request->phone = $data['phone'];
        }

        if (!empty($data['subsidiary'])) {
            $subsidiary = new Subsidiary();
            $subsidiary->internalId = $data['subsidiary'];
            $request->subsidiary = $subsidiary;
        }


        $request_submit = new AddRequest();
        $request_submit->record = $request;

        $addResponse = $service->add($request_submit);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($baseref_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $baseref_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }

    public function CreatePurchaseOrderInNetsuite($service, $order_data)
    {
        $po = new PurchaseOrder();

        // Associate a customer record with this order
        $po->entity = new RecordRef();
        $po->entity->type = 'customer';
        $po->entity->internalId = $order_data['customerInternalId'];

        // Set the date of the order
        $po->tranDate = $order_data['orderDate'];

        // Set the create date of order
        //    $po->createdDate = $order_data['orderDate'];

        if (!empty($order_data['location'])) {
            $po->location = new RecordRef();
            $po->location->internalId = $order_data['location'];
        }

        // if(!empty($order_data['employeeInternalId']))
        // {
        //     $po->employee = new RecordRef();
        //     $po->employee->internalId = $order_data['employeeInternalId'];
        // }

        if (!empty($order_data['customForm'])) {
            $po->customForm = new RecordRef();
            $po->customForm->internalId = $order_data['customForm'];
        }
        if (!empty($order_data['memo'])) {
            $po->memo = $order_data['memo'];
        }

        if (count($order_data['shippingAddress'])) {
            $address = $order_data['shippingAddress'];

            $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);

            $po->shippingAddress = new Address();
            $po->shippingAddress->zip = @$address['zip'];
            $po->shippingAddress->city = @$address['city'];
            $po->shippingAddress->addressee = @$address['street'];
            $po->shippingAddress->state = $address['state'];
            $po->shippingAddress->country = $nsSpecificCountry;
        }

        if (count($order_data['billingAddress'])) {
            $address = $order_data['billingAddress'];

            $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);

            $po->billingAddress = new Address();
            $po->billingAddress->zip = $address['zip'];
            $po->billingAddress->city = $address['city'];
            $po->billingAddress->state = $address['state'];
            $po->billingAddress->addressee = $address['street'];
            $po->billingAddress->country = $nsSpecificCountry;
        }
        if (isset($order_data['classificationId'])) {
            $po->class = new RecordRef();
            $po->class->internalId = $order_data['classificationId'];
        }
        if (isset($order_data['expense'])) {
            $po->expenseList =  new PurchaseOrderExpenseList();
            $purchase_order_expense = [];
            foreach ($order_data['expense'] as $expense) {
                $poe = new PurchaseOrderExpense();

                $poe->account = new RecordRef();
                $poe->account->internalId  = $expense['account'];
                $poe->memo    = $expense['description'];
                $poe->amount  = $expense['amount'];
                $purchase_order_expense[] = $poe;
            }
            $po->expenseList->expense = $purchase_order_expense;
        }


        $po->itemList = new PurchaseOrderItemList();
        $order_line_items = [];
        foreach ($order_data['items'] as $item) {

            $poi = new PurchaseOrderItem();

            $poi->item = new RecordRef();
            $poi->item->internalId = $item['internalId'];
            $poi->quantity = $item['quantity'];

            if (isset($order_data['landedCostTemplate'])) {
                $poi->customFieldList = new CustomFieldList();

                $customField = new SelectCustomFieldRef();
                $customField->internalId = 280;
                $customField->value = new ListOrRecordRef();
                $customField->value->internalId = $order_data['landedCostTemplate'];
                $customField->value->typeId = 39;

                $poi->customFieldList->customField = [$customField];
            }
            $poi->rate = $item['price'];
            $poi->amount = $item['total'];
            if ($item['taxCode'] != 0) {
                $poi->taxCode = new RecordRef();
                $poi->taxCode->internalId = $item['taxCode'];
            }
            $order_line_items[] = $poi;
        }

        $po->itemList->item = $order_line_items;

        $po->customFieldList = new CustomFieldList();
        $po->customFieldList->customField = [];
        foreach ($order_data['custom_fields'] as $cusFieldArr) {
            if ($cusFieldArr['fieldType'] == CustomFieldType::MULTI_SELECT) {
                // Not supported at the moment
                continue;
            }
            if ($cusFieldArr['fieldType'] == CustomFieldType::BOOLEAN) {
                $cusField = new BooleanCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($po->customFieldList->customField, $cusField);
            } else if ($cusFieldArr['fieldType'] == CustomFieldType::DATE) {
                $cusField = new DateCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($po->customFieldList->customField, $cusField);
            } else if ($cusFieldArr['fieldType'] == CustomFieldType::SELECT) {
                $cusField = new StringCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($po->customFieldList->customField, $cusField);
            } else {
                $cusField = new StringCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($po->customFieldList->customField, $cusField);
            }
        }


        $request = new AddRequest();
        $request->record = $po;

        $addResponse = $service->add($request);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($po_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $po_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }

    public function CreateInventoryTransfer($service, $data)
    {
        try {
            $transfer = new InventoryTransfer();

            $transfer->subsidiary = new Subsidiary();
            $transfer->subsidiary->internalId = $data['subsidiary'];

            if (!empty($data['location'])) {
                $transfer->location = new RecordRef();
                $transfer->location->internalId = $data['location'];
            }

            if (!empty($data['memo'])) {
                $transfer->memo = $data['memo'];
            }

            if (!empty($data['to_location'])) {
                $transfer->transferLocation = new RecordRef();
                $transfer->transferLocation->internalId = $data['to_location'];
            }
            // Custom Field Added in Case of Transfer Order SO
            if (isset($data['called_from']) && $data['called_from'] == 'TO-SO') {
                $transfer->customFieldList = new CustomFieldList();
                $transfer->customFieldList->customField = [];
                foreach ($data['custom_fields'] as $cusFieldArr) {
                    if ($cusFieldArr['fieldType'] == CustomFieldType::MULTI_SELECT) {
                        // Not supported at the moment
                        continue;
                    }
                    if ($cusFieldArr['fieldType'] == CustomFieldType::BOOLEAN) {
                        // Not supported at the moment
                        $cusField = new BooleanCustomFieldRef();
                        $cusField->value = $cusFieldArr['value'];
                        $cusField->internalId = $cusFieldArr['internalId'];
                        // array_push($transfer->customFieldList->customField, $cusField);
                    } else if ($cusFieldArr['fieldType'] == CustomFieldType::DATE) {
                        // Not supported at the moment
                        $cusField = new DateCustomFieldRef();
                        $cusField->value = $cusFieldArr['value'];
                        $cusField->internalId = $cusFieldArr['internalId'];
                        // array_push($transfer->customFieldList->customField, $cusField);
                    } else if ($cusFieldArr['fieldType'] == CustomFieldType::SELECT) {
                        // Not supported at the moment
                        $cusField = new StringCustomFieldRef();
                        $cusField->value = $cusFieldArr['value'];
                        $cusField->internalId = $cusFieldArr['internalId'];
                        //  array_push($transfer->customFieldList->customField, $cusField);
                    } else {
                        $cusField = new StringCustomFieldRef();
                        $cusField->value = $cusFieldArr['value'];
                        $cusField->internalId = $cusFieldArr['internalId'];
                        array_push($transfer->customFieldList->customField, $cusField);
                    }
                }
                unset($data['called_from']);
            }


            $transfer->inventoryList = new InventoryTransferInventoryList();
            $transfer->inventoryList->inventory = [];

            foreach ($data['items'] as $item) {
                $transferInventoryItem = new InventoryTransferInventory();
                $transferInventoryItem->item = new RecordRef();
                $transferInventoryItem->item->internalId = $item['internalId'];
                $transferInventoryItem->adjustQtyBy = $item['quantity'];
                array_push($transfer->inventoryList->inventory, $transferInventoryItem);
            }

            $request = new AddRequest();
            $request->record = $transfer;
            $addResponse = $service->add($request);

            if (!empty($addResponse->writeResponse->status->isSuccess)) {

                if (($po_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                    return $po_id;
                }
            } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            }
            return false;
        } catch (\Exception $ex) {
            return $ex->getMessage();
        }
    }

    public function CreateInvoice($service, $data)
    {
        try {
            $invoice = new Invoice();
            $invoice->customForm = new RecordRef();
            $invoice->customForm->internalId = 161;
            $invoice->entity = new RecordRef();
            $invoice->entity->type = 'customer';
            $invoice->entity->internalId = $data['customerInternalId'];
            $invoice->tranDate = $data['orderDate'];


            $invoice->subsidiary = new Subsidiary();
            $invoice->subsidiary->internalId = $data['subsidiary'];

            if (!empty($data['location'])) {
                $invoice->location = new RecordRef();
                $invoice->location->internalId = $data['location'];
            }

            if (!empty($data['memo'])) {
                $invoice->memo = $data['memo'];
            }

            $invoice->itemList = new InvoiceItemList();

            $invoiceItems = [];
            $totalPrice = 0;
            foreach ($data['items'] as $item) {
                $invItem = new InvoiceItem();
                $invItem->item = new RecordRef();
                $invItem->item->internalId = $item['internalId'];
                $invItem->quantity = $item['quantity'];
                $invItem->price = new RecordRef();
                $invItem->price->internalId = -1;
                $invItem->rate = $item['noTaxTotal'] / $item['quantity'];
                $invItem->amount = $item['total'];
                $totalPrice += $item['total'];
                if (isset($data['classificationId'])) {
                    $invItem->class = new RecordRef();
                    $invItem->class->internalId = $data['classificationId'];
                }

                if ($item['taxCode'] != 0) {
                    $invItem->taxCode = new RecordRef();
                    $invItem->taxCode->internalId = $item['taxCode'];
                }
                array_push($invoiceItems, $invItem);
            }


            $invoice->itemList->item = $invoiceItems;

            if (isset($data['location'])) {
                $invoice->location = new RecordRef();
                $invoice->location->internalId = $data['location'];
            }
            if (isset($data['shippingAddress'])) {
                $address = $data['shippingAddress'];
                $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);
                $invoice->shippingAddress = new Address();

                if (isset($address['zip'])) {
                    $invoice->shippingAddress->zip = $address['zip'];
                }
                if (isset($address['city'])) {
                    $invoice->shippingAddress->city = $address['city'];
                }
                if (isset($address['street'])) {
                    $invoice->shippingAddress->addressee = $address['street'];
                }
                if (isset($address['state'])) {
                    $invoice->shippingAddress->state = $address['state'];
                }
                if ($nsSpecificCountry != '') {
                    $invoice->shippingAddress->country = $nsSpecificCountry;
                }
            }
            if (isset($data['shippingItemId'])) {
                $invoice->shipMethod = new RecordRef();
                $invoice->shipMethod->internalId = $data['shippingItemId'];
            }

            if (isset($data['classificationId'])) {
                $invoice->class = new RecordRef();
                $invoice->class->internalId = $data['classificationId'];
            }

            if (isset($data['shippingAddress'])) {
                $address = $data['billingAddress'];
                $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);
                $invoice->billingAddress = new Address();
                if (isset($address['zip'])) {
                    $invoice->billingAddress->zip = $address['zip'];
                }
                if (isset($address['city'])) {
                    $invoice->billingAddress->city = $address['city'];
                }
                if (isset($address['street'])) {
                    $invoice->billingAddress->addressee = $address['street'];
                }
                if (isset($address['state'])) {
                    $invoice->billingAddress->state = $address['state'];
                }
                if ($nsSpecificCountry != '') {
                    $invoice->billingAddress->country = $nsSpecificCountry;
                }
            }
            if (isset($data['memo'])) {
                $invoice->memo = $data['memo'];
            }

            $invoice->customFieldList = new CustomFieldList();
            $invoice->customFieldList->customField = [];

            $invoice->currency = new RecordRef();
            $invoice->currency->internalId = 1;

            if (isset($data['shipDate'])) {
                $invoice->shipDate = $data['shipDate'];
            }

            foreach ($data['custom_fields'] as $cusFieldArr) {
                if ($cusFieldArr['internalId'] == '2077' || $cusFieldArr['internalId'] == '2249') {
                    // Unsupported custom fields
                    continue;
                }
                if ($cusFieldArr['fieldType'] == CustomFieldType::MULTI_SELECT) {
                    // Not supported at the moment
                    continue;
                }
                if ($cusFieldArr['fieldType'] == CustomFieldType::BOOLEAN) {
                    $cusField = new BooleanCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                } else if ($cusFieldArr['fieldType'] == CustomFieldType::DATE) {
                    $cusField = new DateCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                } else {
                    $cusField = new StringCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                }

                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($invoice->customFieldList->customField, $cusField);
            }

            $request = new AddRequest();
            $request->record = $invoice;
            $addResponse = $service->add($request);

            if ($addResponse->writeResponse->status->isSuccess && isset($addResponse->writeResponse->baseRef)) {
                return $addResponse->writeResponse->baseRef->internalId;
            } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            }
        } catch (\Exception $ex) {
            // Exception Caught!
        }
        return 0;
    }

    public function CreateTransferOrderInNetsuite($service, $order_data)
    {
        $po = new TransferOrder();

        // Associate a customer record with this order
        $po->entity = new RecordRef();
        $po->entity->type = 'customer';
        $po->entity->internalId = $order_data['customerInternalId'];

        // Set the date of the order
        $po->tranDate = $order_data['orderDate'];

        // Set the create date of order
        //    $po->createdDate = $order_data['orderDate'];

        if (!empty($order_data['location'])) {
            $po->location = new RecordRef();
            $po->location->internalId = $order_data['location'];
        }

        if (!empty($order_data['to_location'])) {
            $po->transferLocation = new RecordRef();
            $po->transferLocation->internalId = $order_data['to_location'];
        }

        $po->orderStatus = TransferOrderOrderStatus::_pendingApproval;

        if (!empty($order_data['customForm'])) {
            $po->customForm = new RecordRef();
            $po->customForm->internalId = $order_data['customForm'];
        }

        $po->subsidiary = new Subsidiary();
        $po->subsidiary->internalId = $order_data['subsidiary'];

        if (!empty($order_data['memo'])) {
            $po->memo = $order_data['memo'];
        }

        if (count($order_data['shippingAddress'])) {
            $address = $order_data['shippingAddress'];

            $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);

            $po->shippingAddress = new Address();
            $po->shippingAddress->zip = @$address['zip'];
            $po->shippingAddress->city = @$address['city'];
            $po->shippingAddress->addressee = @$address['street'];
            $po->shippingAddress->state = $address['state'];
            $po->shippingAddress->country = $nsSpecificCountry;
        }

        if (count($order_data['billingAddress'])) {
            $address = $order_data['billingAddress'];

            $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);

            $po->billingAddress = new Address();
            $po->billingAddress->zip = $address['zip'];
            $po->billingAddress->city = $address['city'];
            $po->billingAddress->state = $address['state'];
            $po->billingAddress->addressee = $address['street'];
            $po->billingAddress->country = $nsSpecificCountry;
        }

        if (isset($order_data['discount']) && isset($order_data['discountItemId'])) {
            $po->discountItem = new RecordRef();
            $po->discountItem->internalId = $order_data['discountItemId'];
            $po->discountRate = abs($order_data['discount']);
        }
        if (isset($order_data['shippingPrice']) && isset($order_data['shippingItemId'])) {
            $po->shipMethod = new RecordRef();
            $po->shipMethod->internalId = $order_data['shippingItemId'];
            $po->shippingCost = $order_data['shippingPrice'];
        }


        $po->itemList = new TransferOrderItemList();

        $order_line_items = [];

        foreach ($order_data['items'] as $item) {

            $poi = new TransferOrderItem();
            $poi->item = new RecordRef();
            $poi->item->internalId = $item['internalId'];
            $poi->quantity = $item['quantity'];
            $poi->rate = $item['price'];
            $poi->amount = $item['total'];
            $order_line_items[] = $poi;
        }

        $po->itemList->item = $order_line_items;

        $cus_fields = [];

        foreach ($order_data['custom_fields'] as $cus_field_arr) {

            $cus_field = new StringCustomFieldRef();
            $cus_field->value = $cus_field_arr['value'];
            $cus_field->scriptId = $cus_field_arr['sciptId'];
            $cus_fields[] = $cus_field;
        }

        $po->customFieldList = new CustomFieldList();
        $po->customFieldList->customField = $cus_fields;


        $request = new AddRequest();
        $request->record = $po;

        $addResponse = $service->add($request);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($po_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $po_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }

    public function CreateSalesOrder($service, $orderData)
    {
        try {
            $salesOrder = new SalesOrder();
            $salesOrder->customForm = new RecordRef();
            $salesOrder->customForm->internalId = 172;
            $salesOrder->entity = new RecordRef();
            $salesOrder->entity->type = 'customer';
            $salesOrder->entity->internalId = $orderData['customerInternalId'];
            $salesOrder->tranDate = $orderData['orderDate'];
            $salesOrder->itemList = new SalesOrderItemList();
            $lineItems = [];
            $totalPrice = 0;
            foreach ($orderData['items'] as $item) {
                $lineItem = new SalesOrderItem();
                $lineItem->item = new RecordRef();
                $lineItem->item->internalId = $item['internalId'];
                $lineItem->quantity = $item['quantity'];
                $lineItem->price = new RecordRef();
                $lineItem->price->internalId = -1;
                $lineItem->rate = $item['noTaxTotal'] / $item['quantity'];
                $lineItem->amount = $item['total'];
                $totalPrice += $item['total'];
                if (isset($orderData['classificationId'])) {
                    $lineItem->class = new RecordRef();
                    $lineItem->class->internalId = $orderData['classificationId'];
                }

                if ($item['taxCode'] != 0) {
                    $lineItem->taxCode = new RecordRef();
                    $lineItem->taxCode->internalId = $item['taxCode'];
                }
                array_push($lineItems, $lineItem);
            }
            $salesOrder->itemList->item = $lineItems;
            if (isset($orderData['s_order_api_id'])) {
                $salesOrder->checkNumber = $orderData['s_order_api_id'];
            }
            if (isset($orderData['location'])) {
                $salesOrder->location = new RecordRef();
                $salesOrder->location->internalId = $orderData['location'];
            }
            if (isset($orderData['subsidiary'])) {
                $salesOrder->subsidiary = new RecordRef();
                $salesOrder->subsidiary->internalId = $orderData['subsidiary'];
            }
            if (isset($orderData['shippingAddress'])) {
                $address = $orderData['shippingAddress'];
                $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);
                $salesOrder->shippingAddress = new Address();

                if (isset($address['zip'])) {
                    $salesOrder->shippingAddress->zip = $address['zip'];
                }
                if (isset($address['city'])) {
                    $salesOrder->shippingAddress->city = $address['city'];
                }
                if (isset($address['street'])) {
                    $salesOrder->shippingAddress->addressee = $address['street'];
                }
                if (isset($address['state'])) {
                    $salesOrder->shippingAddress->state = $address['state'];
                }
                if ($nsSpecificCountry != '') {
                    $salesOrder->shippingAddress->country = $nsSpecificCountry;
                }
            }
            if (isset($orderData['shippingItemId'])) {
                $salesOrder->shipMethod = new RecordRef();
                $salesOrder->shipMethod->internalId = $orderData['shippingItemId'];
            }

            if (isset($orderData['classificationId'])) {
                $salesOrder->class = new RecordRef();
                $salesOrder->class->internalId = $orderData['classificationId'];
            }

            if (isset($orderData['shippingAddress'])) {
                $address = $orderData['billingAddress'];
                $nsSpecificCountry = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);
                $salesOrder->billingAddress = new Address();
                if (isset($address['zip'])) {
                    $salesOrder->billingAddress->zip = $address['zip'];
                }
                if (isset($address['city'])) {
                    $salesOrder->billingAddress->city = $address['city'];
                }
                if (isset($address['street'])) {
                    $salesOrder->billingAddress->addressee = $address['street'];
                }
                if (isset($address['state'])) {
                    $salesOrder->billingAddress->state = $address['state'];
                }
                if ($nsSpecificCountry != '') {
                    $salesOrder->billingAddress->country = $nsSpecificCountry;
                }
            }
            if (isset($orderData['memo'])) {
                $salesOrder->memo = $orderData['memo'];
            }

            $salesOrder->customFieldList = new CustomFieldList();
            $salesOrder->customFieldList->customField = [];

            $salesOrder->currency = new RecordRef();
            $salesOrder->currency->internalId = 1;

            if (isset($orderData['shipDate'])) {
                $salesOrder->shipDate = $orderData['shipDate'];
            }


            foreach ($orderData['custom_fields'] as $cusFieldArr) {
                if ($cusFieldArr['fieldType'] == CustomFieldType::MULTI_SELECT) {
                    // Not supported at the moment
                    continue;
                }
                if ($cusFieldArr['fieldType'] == CustomFieldType::BOOLEAN) {
                    $cusField = new BooleanCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                } else if ($cusFieldArr['fieldType'] == CustomFieldType::DATE) {
                    $cusField = new DateCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                } else if (0) {
                    $cusField = new SelectCustomFieldRef();
                    $cusField->value = new ListOrRecordRef();
                    $cusField->value->internalId = $cusFieldArr['value'];
                } else {
                    $cusField = new StringCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                }

                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($salesOrder->customFieldList->customField, $cusField);
            }
            $request = new AddRequest();
            $request->record = $salesOrder;
            $addResponse = $service->add($request);

            if ($addResponse->writeResponse->status->isSuccess && isset($addResponse->writeResponse->baseRef)) {
                return $addResponse->writeResponse->baseRef->internalId;
            } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            }
        } catch (\Exception $ex) {
            //            print_r("Error occurred: ".$ex->getMessage());
        }
        return 0;
    }
    /* Update Sales Order */
    public function UpdateSalesOrder($service, $orderData)
    {
        try {
            $salesOrder = new SalesOrder();
            $salesOrder->internalId = $orderData['internalId'];

            if (isset($orderData['s_order_api_id'])) {
                $salesOrder->checkNumber = $orderData['s_order_api_id'];
            }
            if (isset($orderData['location'])) {
                $salesOrder->location = new RecordRef();
                $salesOrder->location->internalId = $orderData['location'];
            }
            if (isset($orderData['subsidiary'])) {
                $salesOrder->subsidiary = new RecordRef();
                $salesOrder->subsidiary->internalId = $orderData['subsidiary'];
            }
            if (isset($orderData['shippingItemId'])) {
                $salesOrder->shipMethod = new RecordRef();
                $salesOrder->shipMethod->internalId = $orderData['shippingItemId'];
            }

            if (isset($orderData['classificationId'])) {
                $salesOrder->class = new RecordRef();
                $salesOrder->class->internalId = $orderData['classificationId'];
            }
            if (isset($orderData['memo'])) {
                $salesOrder->memo = $orderData['memo'];
            }
            if (isset($orderData['shipDate'])) {
                $salesOrder->shipDate = $orderData['shipDate'];
            }
            $salesOrder->customFieldList = new CustomFieldList();
            $salesOrder->customFieldList->customField = [];

            foreach ($orderData['custom_fields'] as $cusFieldArr) {
                if ($cusFieldArr['fieldType'] == CustomFieldType::MULTI_SELECT) {
                    // Not supported at the moment
                    continue;
                }
                if ($cusFieldArr['fieldType'] == CustomFieldType::BOOLEAN) {
                    $cusField = new BooleanCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                } else if ($cusFieldArr['fieldType'] == CustomFieldType::DATE) {
                    $cusField = new DateCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                } else if (0) {
                    $cusField = new SelectCustomFieldRef();
                    $cusField->value = new ListOrRecordRef();
                    $cusField->value->internalId = $cusFieldArr['value'];
                } else {
                    $cusField = new StringCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                }
                $cusField->internalId = $cusFieldArr['internalId'];
                array_push($salesOrder->customFieldList->customField, $cusField);
            }
            $request = new UpdateRequest();
            $request->record = $salesOrder;
            $addResponse = $service->update($request);

            if ($addResponse->writeResponse->status->isSuccess && isset($addResponse->writeResponse->baseRef)) {
                return $addResponse->writeResponse->baseRef->internalId;
            } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            }
        } catch (\Exception $ex) {
            return ['error' => "Exception can't update order"];
        }
        return ['error' => "Can't update order"];
    }

    /* Error handling */

    public function ErrorHandling($object)
    {
        $errorList = null;
        if ($object) {
            $errors = isset($object->readResponse->status->statusDetail) ? $object->readResponse->status->statusDetail : [];
            if (empty($errors)) {
                $errors = isset($object->writeResponse->status->statusDetail) ? $object->writeResponse->status->statusDetail : [];
            }
            foreach ($errors as $error) {
                $errorList .= $error->message . ",";
            }
            $errorList = rtrim($errorList, ",");
        }
        return $errorList;
    }

    public function CreateItemFulfillment($service, $data)
    {
        // Initialize an item fulfillment from an existing Sales Order
        $reference = new InitializeRef();
        $reference->type = 'salesOrder';
        $reference->internalId = $data['order_internalId'];


        $record = new InitializeRecord();
        $record->type = 'itemFulfillment';
        $record->reference = $reference;

        $request = new InitializeRequest();
        $request->initializeRecord = $record;

        $initResponse = $service->initialize($request);

        if (empty($initResponse->readResponse->status->isSuccess)) {
            return ['error' => $this->ErrorHandling($initResponse)];
        }

        $fulfillment = $initResponse->readResponse->record;
        unset($fulfillment->handlingCost);
        if (isset($data['rows']) && !empty($data['rows'])) {
            $fulfillment->itemList->replaceAll = false;

            $items_arr = array_column($data['rows'], 'itemInternalId');
            foreach ($fulfillment->itemList->item as $key => $row) {
                //Passing the items from the sales order to item fulfillment
                $index = array_search($row->item->internalId, $items_arr);

                if ($index !== false) {
                    $fulfillment->itemList->item[$key]->internalId = $data['rows'][$index]['itemInternalId'];
                    $fulfillment->itemList->item[$key]->quantity = $data['rows'][$index]['qty'];
                    $ref = new RecordRef();
                    $ref->internalId = $data['rows'][0]['location_internalId'];
                    $fulfillment->itemList->item[$key]->location = $ref;
                } else {
                    $fulfillment->itemList->item[$key]->internalId = $row->item->internalId;
                    $fulfillment->itemList->item[$key]->quantity = 0;
                    $ref = new RecordRef();
                    $ref->internalId = $data['rows'][0]['location_internalId'];
                    $fulfillment->itemList->item[$key]->location = $ref;
                }
            }
        }
        /* If you want to update custom field by their internal id (Only String Fields) */

        if (isset($data['customFields']) && !empty($data['customFields'])) {
            foreach ($data['customFields'] as $key => $row) {
                $ref = new StringCustomFieldRef();
                $ref->value = $row['value'];
                $ref->internalId = $row['internalId'];
                $fulfillment->customFieldList->customField[$key] = $ref;
            }
        }


        // Adds the fulfillment into netsuite using the Service object.
        $addrequest = new AddRequest();
        $addrequest->record = $fulfillment;

        $addResponse = $service->add($addrequest);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($baseRef_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $baseRef_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }

    public function mapUnits($unit)
    {
        $ns_unit = '_g';
        switch ($unit) {
            case "GRAM":
                $ns_unit = '_g';
                break;
            case "KILOGRAM":
                $ns_unit = '_kg';
                break;
            case "POUND":
                $ns_unit = '_lb';
                break;
            case "OUNCE":
                $ns_unit = '_oz';
                break;
        }
        return $ns_unit;
    }

    /* Get Inventory By External ID or Sku*/
    public function GetInventoryByItemExternalID($service, $search, $field)
    {
        if ($field == 'internalId') {

            $request = new GetRequest();
            $request->baseRef = new RecordRef();
            $request->baseRef->internalId = trim($search);

            $request->baseRef->type = RecordType::inventoryItem;
            $getResponse = $service->get($request);

            if (!$getResponse->readResponse->status->isSuccess) {
                return  "Product not found";
            } else {
                return $getResponse->readResponse->record;
            }
            return false;
        } else {


            $service->setSearchPreferences(false, 10, true);

            $searchField = new SearchStringField();
            $searchField->operator = "is";
            $searchField->searchValue = trim($search);

            $search = new ItemSearchBasic();

            if ($field == 'upc') {
                $field = 'upcCode';
            } else if ($field == 'product_name' || $field == 'itemId') {
                $field = 'itemId';
            }

            $search->$field = $searchField;

            $request = new SearchRequest();
            $request->searchRecord = $search;

            $response = $service->search($request);


            $product = @$response->searchResult->recordList;

            if ($response->searchResult->totalRecords > 0 && $response->searchResult->status->isSuccess) {
                return $product->record[0];
            } else {
                return  "Product not found";
            }
        }
    }
    public function AdjustInventory($service, $data)
    {
        $item = new InventoryAdjustment();
        $item->memo = 'Onhand  qty updated';
        $item->account = new RecordRef();
        $item->account->internalId = $data['incomeAccount'];

        $item->adjLocation = new RecordRef();
        $item->adjLocation->internalId = $data['location'];

        $item->subsidiary = new RecordRef();
        $item->subsidiary->internalId = $data['subsidiary'];
        //
        //        $item->customer = new RecordRef();
        //        $item->customer->internalId = 412;
        /*$item->displayName='Test-Shubham-Item';
        $item->currency = 'USD';
        $item->averageCost = '1.00';
        $item->itemId = 'Test-Shubham-Item';*/

        // Associate a customer record with this order


        $adjustment_inventory = new InventoryAdjustmentInventory();
        $adjustment_inventory->item = new RecordRef();
        $adjustment_inventory->item->internalId = $data['product_internalId'];

        $adjustment_inventory->adjustQtyBy = $data['qty'];
        //$adjustment_inventory->quantityOnHand = $data['qty'];
        //  $adjustment_inventory->newQuantity = $data['qty'];

        $adjustment_inventory->location = new RecordRef();
        $adjustment_inventory->location->internalId = $data['location'];

        $item->inventoryList = new InventoryAdjustmentInventoryList();
        $item->inventoryList->inventory = array($adjustment_inventory);

        $addrequest = new AddRequest();
        $addrequest->record = $item;
        $addResponse = $service->add($addrequest);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($baseRef_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $baseRef_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }


    public function CreateUpdateInventoryItem($service, $data, $action = 'create')
    {
        if ($action == 'update') {
            $item = $this->GetNetsuiteProductById($service, $data['itemId'], $data['isInventoryItem'] ? RecordType::inventoryItem : RecordType::nonInventorySaleItem);

            if ($item == false) {
                return false;
            }

            unset($item->lastModifiedDate);
            unset($item->averageCost);
            unset($item->lastPurchasePrice);
            unset($item->createdDate);
        } else {
            if ($data['isInventoryItem']) {
                $item = new InventoryItem();
            } else {
                $item = new NonInventorySaleItem();
            }
        }
        $item->itemId = $data['sku'];
        $item->isTaxable = true;

        if ($action != 'update') {
            if ($data['isInventoryItem']) {
                $item->originalItemType = ItemType::_inventoryItem;
            } else {
                $item->originalItemType = ItemType::_nonInventoryItem;
            }

            $subsidiaryList = new RecordRef();
            $subsidiaryList->internalId = $data['subsidiary'];

            $item->subsidiaryList = new RecordRefList();
            $item->subsidiaryList->recordRef = array($subsidiaryList);
        }
        if (isset($data['priceMatrix']) && $data['priceMatrix']) {
            $priceMatrix  = ['pricing' => $data['priceMatrix']];
            $item->pricingMatrix = $priceMatrix;
        }

        //

        // $item->pricingMatrix = new PricingMatrix();

        // $pricing = new Pricing();

        // $pricing->currency = new Currency();
        // $pricing->currency->internalId=1;
        // $pricing->currency->externalId=null;
        // $pricing->currency->name='USA';

        // $pricing->priceLevel = new PriceLevel();
        // $pricing->priceLevel->internalId=1;
        // $pricing->priceLevel->externalId=null;
        // $pricing->priceLevel->name='Retail Price';

        // $pricing->priceList = new PriceList();

        // $price = new Price();
        // $price->value=15;
        // $price->quantity=0;
        // $pricing->priceList->price= array($price);
        // $item->pricingMatrix->pricing = $pricing;



        $item->externalId = $data['sku'];
        $item->trackLandedCost = true;
        $item->includeChildren = true;
        $item->autoReorderPoint = false;
        $item->autoPreferredStockLevel = false;
        $item->autoLeadTime = false;
        $item->cost = $data['cost'];

        // $item->averageCost = $data['cost'];
        $item->rate = $data['cost'];
        $item->mpn = $data['mpn'];
        $item->upcCode = $data['barcode'];
        $item->weight = $data['weight'];

        if (!empty($data['weightUnit'])) {
            $item->weightUnit = $this->mapUnits($data['weightUnit']);
        } else {
            $item->weightUnit = $this->mapUnits('');
        }
        $item->displayName = $data['product_name'];
        $item->salesDescription = $data['product_name'];
        if ($data['isInventoryItem']) {
            $item->purchaseDescription = $data['product_name'];
        }


        /*$item->currency = 'USD';*/
        $item->customFieldList = new CustomFieldList();
        $item->customFieldList->customField = [];

        foreach ($data['custom_fields'] as $cusFieldArr) {
            if ($cusFieldArr['field_type'] == CustomFieldType::MULTI_SELECT) {
                // Not supported at the moment
                continue;
            }
            if ($cusFieldArr['field_type'] == CustomFieldType::BOOLEAN) {
                $cusField = new BooleanCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
            } else if ($cusFieldArr['field_type'] == CustomFieldType::DATE) {
                $cusField = new DateCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
            } else if ($cusFieldArr['field_type'] == CustomFieldType::SELECT) {
                if ($cusFieldArr['option_id'] == 0 || $cusFieldArr['option_id'] == 0.0) {
                    continue;
                }
                $cusField = new SelectCustomFieldRef();
                $cusField->value = new ListOrRecordRef();
                $cusField->value->internalId = $cusFieldArr['option_id'];
            } else {
                if ($cusFieldArr['value'] == 0 || $cusFieldArr['value'] == 0.0) {
                    continue;
                }
                $cusField = new StringCustomFieldRef();
                $cusField->value = $cusFieldArr['value'];
            }

            $cusField->internalId = $cusFieldArr['internalId'];
            array_push($item->customFieldList->customField, $cusField);
        }

        if (isset($data['nullFields']) && count($data['nullFields'])) {
            $item->nullFieldList = new NullField();
            $item->nullFieldList->name = $data['nullFields'];
        }


        if (isset($data['customerInternalId']) && $data['customerInternalId']) {
            $item->itemVendorList = new ItemVendorList();
            $vendor = new ItemVendor();
            $vendor->vendor = new RecordRef();
            $vendor->vendor->internalId = $data['customerInternalId'];
            $item->itemVendorList->itemVendor = array($vendor);
        }

        if (isset($data['customerName'])) {
            $item->vendorName = $data['customerName'];
        }

        if (!empty($data['taxSchedule'])) {
            $item->taxSchedule = new RecordRef();
            $item->taxSchedule->internalId = 1;
        }

        if (!empty($data['location'])) {
            $item->location = new RecordRef();
            $item->location->internalId = $data['location'];
        }
        if ($action == 'create') {
            $request = new AddRequest();
            $request->record = $item;
            $addResponse = $service->add($request);
        } else {
            $request = new UpdateRequest();
            $request->record = $item;
            $addResponse = $service->update($request);
        }


        if (!empty($addResponse->writeResponse->status->isSuccess)) {
            if (($baseRef_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $baseRef_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $addResponse->readResponse->status->statusDetail[0]->message];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $addResponse->writeResponse->status->statusDetail[0]->message];
        }
        return false;
    }

    /* Get Products */

    public function searchNetsuiteProductList($service, $startDateDate = null, $lastModifiedDate = null, $columnName = null, $operator = "after", $limit = 30, $searchId = null, $pageIndex = null)
    {
        $service->setSearchPreferences(false, $limit, true);
        if ($searchId && $pageIndex) {
            $searchMoreWithIdRequest = new SearchMoreWithIdRequest();
            $searchMoreWithIdRequest->searchId = $searchId;
            $searchMoreWithIdRequest->pageIndex = $pageIndex;
            $searchResponse = $service->searchMoreWithId($searchMoreWithIdRequest);
        } else {
            // Create a basic item search
            $itemSearch = new ItemSearchBasic();
            // Create a datetime filter
            if ($operator == "within") {
                $dateField = new SearchDateField();
                $dateField->operator = $operator;
                $dateField->searchValue = $startDateDate; // Start date
                $dateField->searchValue2 = $lastModifiedDate; // End date
                $itemSearch->$columnName = $dateField;
            } else {
                if ($startDateDate) {
                    $dateField = new SearchDateField();
                    $dateField->operator = $operator;
                    $dateField->searchValue = $startDateDate; // Start date
                    $itemSearch->$columnName = $dateField;
                }
            }

            // Create a search request
            $searchRequest = new SearchRequest();
            $searchRequest->searchRecord = $itemSearch;
            // Perform the search
            $searchResponse = $service->search($searchRequest);
        }
        //check if the records are present?
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    /* Get Vendors */
    public function searchNetsuiteVendorList($service, $startDateDate = null, $lastModifiedDate = null, $columnName = null, $operator = "after", $limit = 30, $searchId = null, $pageIndex = null)
    {
        $service->setSearchPreferences(false, $limit, true);
        if ($searchId && $pageIndex) {
            $searchMoreWithIdRequest = new SearchMoreWithIdRequest();
            $searchMoreWithIdRequest->searchId = $searchId;
            $searchMoreWithIdRequest->pageIndex = $pageIndex;
            $searchResponse = $service->searchMoreWithId($searchMoreWithIdRequest);
        } else {
            // Create a basic item search
            $vendorSearch = new VendorSearchBasic();
            // Create a datetime filter
            if ($operator == "within") {
                $dateField = new SearchDateField();
                $dateField->operator = $operator;
                $dateField->searchValue = $startDateDate; // Start date
                $dateField->searchValue2 = $lastModifiedDate; // End date
                $vendorSearch->$columnName = $dateField;
            } else {
                if ($startDateDate) {
                    $dateField = new SearchDateField();
                    $dateField->operator = $operator;
                    $dateField->searchValue = $startDateDate; // Start date
                    $vendorSearch->$columnName = $dateField;
                }
            }

            // Create a search request
            $searchRequest = new SearchRequest();
            $searchRequest->searchRecord = $vendorSearch;
            // Perform the search
            $searchResponse = $service->search($searchRequest);
        }
        //check if the records are present?
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    /* Get Inventory */
    public function searchNetsuiteInventoryList($service, $startDateDate = null, $lastModifiedDate = null, $operator = "after", $limit = 50, $searchId = null, $pageIndex = null)
    {
        $service->setSearchPreferences(false, $limit, true);
        if ($searchId && $pageIndex) {
            $searchMoreWithIdRequest = new SearchMoreWithIdRequest();
            $searchMoreWithIdRequest->searchId = $searchId;
            $searchMoreWithIdRequest->pageIndex = $pageIndex;
            $searchResponse = $service->searchMoreWithId($searchMoreWithIdRequest);
        } else {
            // Create a basic item search
            $criteria = new ItemSearch();
            $criteria->basic = new ItemSearchBasic();
            // Create a datetime filter
            if ($operator == "within") {
                $criteria->basic->lastQuantityAvailableChange = new SearchDateField();
                $criteria->basic->lastQuantityAvailableChange->operator = $operator;
                $criteria->basic->lastQuantityAvailableChange->searchValue = $startDateDate; // Start date
                $criteria->basic->lastQuantityAvailableChange->searchValue2 = $lastModifiedDate; // End date

            } else {
                if ($startDateDate) {
                    $criteria->basic->lastQuantityAvailableChange = new SearchDateField();
                    $criteria->basic->lastQuantityAvailableChange->operator = $operator;
                    $criteria->basic->lastQuantityAvailableChange->searchValue = $startDateDate; // Start date
                }
            }

            $criteria->basic->locationQuantityOnHand = new SearchDoubleField();
            $criteria->basic->locationQuantityOnHand->operator = "greaterThan";
            $criteria->basic->locationQuantityOnHand->searchValue = 0;

            // Build the columns
            $columns = new ItemSearchRow();
            $columns->basic = new ItemSearchRowBasic();

            $columns->basic->internalId = new SearchColumnSelectField();
            $columns->basic->itemId = new SearchColumnStringField();
            $columns->basic->locationQuantityOnHand = new SearchColumnDoubleField();
            $columns->basic->lastQuantityAvailableChange = new SearchColumnDateField();

            $columns->inventoryLocationJoin = new LocationSearchRowBasic();
            $columns->inventoryLocationJoin->internalId = new SearchColumnSelectField();
            $columns->inventoryLocationJoin->name = new SearchColumnStringField();

            // Build the search record
            $searchRecord = new ItemSearchAdvanced();
            $searchRecord->criteria = $criteria;
            $searchRecord->columns = $columns;

            // Build the search request
            $searchRequest = new SearchRequest();
            $searchRequest->searchRecord = $searchRecord;
            // Perform the search
            $searchResponse = $service->search($searchRequest);
        }
        //check if the records are present?
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->searchRowList->searchRow, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    /* Get Currency By ID */
    public function getCurrencyById($service, $Id)
    {
        $req = new GetRequest();
        $req->baseRef = new RecordRef();
        $req->baseRef->type = RecordType::currency;
        $req->baseRef->internalId = $Id;
        $response = $service->get($req);

        if (!empty($response->readResponse->status->isSuccess)) {

            if (isset($response->readResponse->record)) {
                return $response->readResponse->record;
            }
        }
        return false;
    }
    public function getProductById($service)
    {
        $service->setSearchPreferences(false, 5, true);

        // Create a basic item search
        $itemSearch = new ItemSearchBasic();
        // Create a datetime filter


        // Create a search request
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $itemSearch;
        // Perform the search
        $searchResponse = $service->search($searchRequest);

        //check if the records are present?
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
    /* New method by Awadhesh | Please follow this below method for new platforms */
    public function createPO($service, $order_data)
    {
        $po = new PurchaseOrder();

        // Associate a customer record with this order
        $po->entity = new RecordRef();
        $po->entity->type = 'customer';
        $po->entity->internalId = $order_data['customerInternalId'];

        // Set the date of the order
        $po->tranDate = $order_data['orderDate'];

        // Set the due date of order
        $po->dueDate = $order_data['deliveryDate'];

        if (isset($order_data['location']) && !empty($order_data['location'])) {
            $po->location = new RecordRef();

            $po->location->internalId = $order_data['location'];
        }



        if (isset($order_data['customForm']) && !empty($order_data['customForm'])) {
            $po->customForm = new RecordRef();
            $po->customForm->internalId = $order_data['customForm'];
        }

        if (isset($order_data['memo']) && !empty($order_data['memo'])) {
            $po->memo = $order_data['memo'];
        }


        if (isset($order_data['shippingAddress']) && count($order_data['shippingAddress'])) {
            $address = $order_data['shippingAddress'];
            $po->shippingAddress = new Address();
            if (@$address['street'] && !empty($address['street'])) {
                $po->shippingAddress->addressee = $address['street'];
                $po->shippingAddress->addrText = $address['street'];
                //$po->shippingAddress->addr1 = $address['street'];
            }

            if (@$address['zip'] && !empty($address['zip'])) {
                $po->shippingAddress->zip = $address['zip'];
            }
            if (@$address['city'] && !empty($address['city'])) {
                $po->shippingAddress->city = $address['city'];
            }
            if (@$address['state'] && !empty($address['state'])) {
                $po->shippingAddress->state = $address['state'];
            }

            if (@$address['country'] && !empty($address['country'])) {

                $po->shippingAddress->country  = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);
            }
        }
        if (isset($order_data['billingAddress']) && count($order_data['billingAddress'])) {
            $address = $order_data['billingAddress'];
            $po->billingAddress = new Address();
            if (@$address['street'] && !empty($address['street'])) {
                $po->billingAddress->addressee = $address['street'];
                $po->billingAddress->addrText = $address['street'];
                // $po->billingAddress->addr1 = $address['street'];
            }

            if (@$address['zip'] && !empty($address['zip'])) {
                $po->billingAddress->zip = $address['zip'];
            }
            if (@$address['city'] && !empty($address['city'])) {
                $po->billingAddress->city = $address['city'];
            }
            if (@$address['state'] && !empty($address['state'])) {
                $po->billingAddress->state = $address['state'];
            }

            if (@$address['country'] && !empty($address['country'])) {

                $po->billingAddress->country  = CountryCodes::getNetSuiteSpecificCountryNameByIso(@$address['country']);
            }
        }

        if (isset($order_data['classificationId'])) {
            $po->class = new RecordRef();
            $po->class->internalId = $order_data['classificationId'];
        }
        if (isset($order_data['expense'])) {
            $po->expenseList =  new PurchaseOrderExpenseList();
            $purchase_order_expense = [];
            foreach ($order_data['expense'] as $expense) {
                $poe = new PurchaseOrderExpense();

                $poe->account = new RecordRef();
                $poe->account->internalId  = $expense['account'];
                $poe->memo    = $expense['description'];
                $poe->amount  = $expense['amount'];
                $purchase_order_expense[] = $poe;
            }
            $po->expenseList->expense = $purchase_order_expense;
        }


        $po->itemList = new PurchaseOrderItemList();
        $order_line_items = [];
        foreach ($order_data['items'] as $item) {

            $poi = new PurchaseOrderItem();

            $poi->item = new RecordRef();
            $poi->item->internalId = $item['internalId'];
            $poi->quantity = $item['quantity'];
            $poi->rate = $item['price'];
            $poi->amount = $item['total'];
            if ($item['taxCode'] != 0) {
                $poi->taxCode = new RecordRef();
                $poi->taxCode->internalId = $item['taxCode'];
            }
            $order_line_items[] = $poi;
        }

        $po->itemList->item = $order_line_items;


        $po->customFieldList = new CustomFieldList();
        $po->customFieldList->customField = [];

        if (isset($order_data['custom_fields'])) {
            foreach ($order_data['custom_fields'] as $cusFieldArr) {
                if ($cusFieldArr['fieldType'] == CustomFieldType::MULTI_SELECT) {
                    // Not supported at the moment
                    continue;
                }
                if ($cusFieldArr['fieldType'] == CustomFieldType::BOOLEAN) {
                    $cusField = new BooleanCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                    $cusField->internalId = $cusFieldArr['internalId'];
                    array_push($po->customFieldList->customField, $cusField);
                } else if ($cusFieldArr['fieldType'] == CustomFieldType::DATE) {
                    $cusField = new DateCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                    $cusField->internalId = $cusFieldArr['internalId'];
                    array_push($po->customFieldList->customField, $cusField);
                } else if ($cusFieldArr['fieldType'] == CustomFieldType::SELECT) {
                    $cusField = new StringCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                    $cusField->internalId = $cusFieldArr['internalId'];
                    array_push($po->customFieldList->customField, $cusField);
                } else {
                    $cusField = new StringCustomFieldRef();
                    $cusField->value = $cusFieldArr['value'];
                    $cusField->internalId = $cusFieldArr['internalId'];
                    array_push($po->customFieldList->customField, $cusField);
                }
            }
        }



        $request = new AddRequest();
        $request->record = $po;

        $addResponse = $service->add($request);

        if (!empty($addResponse->writeResponse->status->isSuccess)) {

            if (($po_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                return $po_id;
            }
        } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
            return ['error' => $this->ErrorHandling($addResponse)];
        }
        return false;
    }
    /* Create Transfer Order */
    public function createTO($service, $payload)
    {
        try {
            $transfer = new TransferOrder();
            if (isset($payload['subsidiary'])) {
                $transfer->subsidiary = new Subsidiary();
                $transfer->subsidiary->internalId = $payload['subsidiary'];
            }
            if (isset($payload['location'])) {
                $transfer->location = new RecordRef();
                $transfer->location->internalId = $payload['location'];
            }
            if (isset($payload['to_location'])) {
                $transfer->transferLocation = new RecordRef();
                $transfer->transferLocation->internalId = $payload['to_location'];
            }
            if (isset($payload['memo'])) {
                $transfer->memo = $payload['memo'];
            }
            // for createddate
            if (isset($payload['tranDate'])) {
                $transfer->tranDate = $payload['tranDate'];
            }
            // for ship date
            if (isset($payload['shipDate'])) {
                $transfer->tranDate = $payload['shipDate'];
            }
            // for shipping method
            if (isset($payload['shipMethod'])) {
                $shippingMethodRef = new RecordRef();
                $shippingMethodRef->internalId = $payload['shipMethod'];
                $transfer->shipMethod = $shippingMethodRef;
            }
            $transfer->itemList = new TransferOrderItemList();
            $transfer->itemList->item = [];
            if (isset($payload['items'])) {
                foreach ($payload['items'] as $item) {
                    // Add Transfer Order items
                    $items = new TransferOrderItem();
                    $items->item = new RecordRef();
                    $items->item->internalId =  $item['internalId']; // Replace with the actual internal ID of the first item
                    $items->quantity = $item['quantity'];
                    array_push($transfer->itemList->item, $items);
                }
            }
            $request = new AddRequest();
            $request->record = $transfer;
            $addResponse = $service->add($request);

            if (!empty($addResponse->writeResponse->status->isSuccess)) {

                if (($po_id = @$addResponse->writeResponse->baseRef->internalId) !== NULL) {
                    return $po_id;
                }
            } else if (!empty($addResponse->readResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            } else if (!empty($addResponse->writeResponse->status->statusDetail[0]->message)) {
                return ['error' => $this->ErrorHandling($addResponse)];
            }
            return false;
        } catch (\Exception $ex) {
            return $ex->getMessage();
        }
    }
    /* Search Vendor by ID */
    public function getVendorById($service, $vendorId)
    {
        $vendorInternalId = $vendorId;

        // Create a new RecordRef object for the vendor
        $vendorRef = new RecordRef();
        $vendorRef->internalId = $vendorInternalId;
        $vendorRef->type = 'vendor';

        // Create a new GetRequest object
        $request = new GetRequest();
        $request->baseRef = $vendorRef;

        // Retrieve the vendor using the Get operation
        $getResponse = $service->get($request);
        if (!$getResponse->readResponse->status->isSuccess) {
            return  "Record not found";
        } else {
            return $getResponse->readResponse->record;
        }

        return false;
    }
    /* Search Receipt */
    public function searchReciepts($service, $searchArray = [], $limit = 50)
    {
        $service->setSearchPreferences(false, $limit, true);
        // Create a basic search for get vendors, items etc.
        $Search = new TransactionSearchBasic();
        $Search->type = new SearchEnumMultiSelectField();
        $Search->type->operator = 'anyOf';
        $Search->type->searchValue = ['_itemReceipt'];
        $createdFromField = new SearchMultiSelectField();
        $createdFromField->operator = 'anyOf';
        $createdFromField->searchValue = $searchArray;
        $Search->createdFrom = $createdFromField;
        // Create a search request
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $Search;
        // Perform the search
        $searchResponse = $service->search($searchRequest);
        //check if the records are present?
        if (!$searchResponse->searchResult->status->isSuccess) {
            return "No record found";
        } else {
            return ['totalRecords' => $searchResponse->searchResult->totalRecords, 'totalPages' => $searchResponse->searchResult->totalPages, 'pageIndex' => $searchResponse->searchResult->pageIndex, 'recordList' => $searchResponse->searchResult->recordList->record, 'searchId' => $searchResponse->searchResult->searchId];
        }
        return false;
    }
}
