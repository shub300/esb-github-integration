<?php

namespace App\Http\Controllers\ExtensivBillingManager;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\Api\ExtensivBillingManagerApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomer;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceHistory;
use App\Models\PlatformInvoiceLine;
use App\Models\PlatformInvoiceTransaction;
use Carbon\Carbon;
use DB, Lang, Log, Session, Validator;

class ExtensivBillingManagerApiController extends Controller
{
    public static $invoiceApiKey = 'eBfFUdBisRxvaSISiIB70m4Xk8y4bG0ifcGrRjsbEMrqWUaFcSY5wANBpn8fGCxQqBaIaNl31DkGUUDYuE3NzHCWGscyLk9rlxvA75YmEPlFRbrEa9STN1HodXPnv84NKHIscZmCqtFNvGKidSSrtaO0DFF2PyH4SdqY98AHjigUkmSGJ0yDfulWgQoasRTeKH6dubPfdxcy7uYmalJyt2kAW2KIGBBkILUwJdo4Eer4amsZVruu95jRErss9TDy';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $tpl, $helper, $map, $platformId, $log;
    public static $myPlatform = 'extensivbillingmanager';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->ebm = new ExtensivBillingManagerApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper();
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    public function InitiateExtensivBillingManagerAuth(Request $request)
    {
        $allow_direct_connection = $this->helper->getPlatformConnTypeByName(self::$myPlatform);
        return view('pages.apiauth.auth_extensiv_billing_manager', ['platform' => self::$myPlatform, 'allow_direct_connection' => $allow_direct_connection]);
    }

    /* Save Extensiv Billing Manager Credential */
    public function ConnectExtensivBillingManagerAccount(Request $request)
    {
        $flag = true;

        if ($this->mobj->checkHtmlTags($request->all())) {
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }

        $validator = Validator::make($request->all(), ['account_name' => 'required']);
        if ($validator->fails()) {
            $flag = false;
            $data['status_code'] = 0;
            $data['status_text'] = $validator->getMessageBag()->toArray();
        } else {
            // To check whether given account name is already in use or not.
            // $obj_existing = PlatformAccount::where(['user_id' => Session::get('user_data')['id'], 'platform_id' => $this->platformId, 'account_name' => $request->account_name])->count();
            // if ($obj_existing) {
            //     $flag = false;
            //     $data['status_code'] = 0;
            //     $data['status_text'] = 'This account name already exist, Try with another account name.';
            // } else {
            if ($request->account_name) {
                /* Create Model Instance */
                $this->mobj->makeInsert('platform_accounts', ['account_name' => $request->account_name, 'user_id' => Session::get('user_data')['id'], 'platform_id' => $this->platformId, 'allow_refresh' => 0]);
            } else {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = 'Invalid account name!';
            }
            //}
        }

        if ($flag) {
            $data['status_code'] = 1;
            $data['status_text'] = 'Account connected successfully.';
        }
        return response()->json($data);
    }

    /* 
    //Checking Duplicate Account 
    public function CheckExistingConnectedAccount($client_id, $client_secret)
    {
        $client_id = $this->mobj->encrypt_decrypt($client_id);
        $client_secret = $this->mobj->encrypt_decrypt($client_secret);
        $obj_existing = PlatformAccount::where(['user_id' => Session::get('user_data')['id'], 'platform_id' => $this->platformId, 'app_id' => $client_id, 'app_secret' => $client_secret])->count();
        if ($obj_existing > 0) {
            return true;
        } else {
            return false;
        }
    }

    //Save Extensiv Billing Manager Credential
    public function ConnectExtensivBillingManagerAccount(Request $request)
    {
        $flag = true;
        date_default_timezone_set('UTC');

        if ($this->mobj->checkHtmlTags($request->all())) {
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }

        $validator = Validator::make($request->all(), ['account_name' => 'required', 'client_id' => 'required', 'client_secret' => 'required']);
        if ($validator->fails()) {
            $flag = false;
            $data['status_code'] = 0;
            $data['status_text'] = $validator->getMessageBag()->toArray();
        } else {
            // To check whether given account is already in use or not.
            $checkExistingAccount = $this->CheckExistingConnectedAccount($request->client_id, $request->client_secret);
            if ($checkExistingAccount) {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = 'This account detail already exist, Try with another account.';
            } else {
                $obj_existing = PlatformAccount::where(['user_id' => Session::get('user_data')['id'], 'platform_id' => $this->platformId, 'account_name' => $request->account_name])->count();
                if ($obj_existing) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'This account name already exist, Try with another account name.';
                } else {
                    $result = $this->ebm->CheckCredentials($request->client_id, $request->client_secret);
                    if (isset($result['access_token'])) {
                        // Create Model Instance 
                        $arr_field = [
                            'account_name' => $request->account_name,
                            'user_id' => Session::get('user_data')['id'],
                            'platform_id' => $this->platformId,
                            'app_id' => $this->mobj->encrypt_decrypt($request->client_id),
                            'app_secret' => $this->mobj->encrypt_decrypt($request->client_secret),
                            'access_token' => $this->mobj->encrypt_decrypt($result['access_token']),
                            'token_type' => $result['token_type'],
                            'expires_in' => $result['expires_in'],
                            'token_refresh_time' => time()
                        ];
                        $this->mobj->makeInsert('platform_accounts', $arr_field);
                    } else {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Invalid credentials!';
                    }
                }
            }
        }

        if ($flag) {
            $data['status_code'] = 1;
            $data['status_text'] = 'Account connected successfully.';
        }
        return response()->json($data);
    }
    
    // Refresh token
    function RefreshTokens($ID)
    {
        $return_response = false;
        date_default_timezone_set('UTC');
        try {
            $accDetail = PlatformAccount::select('id', 'app_id', 'app_secret')->find($ID);
            if ($accDetail) {
                $client_id = $this->mobj->encrypt_decrypt($accDetail->app_id, 'decrypt');
                $client_secret = $this->mobj->encrypt_decrypt($accDetail->app_secret, 'decrypt');
                $result = $this->ebm->CheckCredentials($client_id, $client_secret);
                if (isset($result['access_token'])) {
                    $accDetail->access_token = $this->mobj->encrypt_decrypt($result['access_token']);
                    $accDetail->expires_in = $result['expires_in'];
                    $accDetail->token_refresh_time = time();
                    $accDetail->save();
                    $return_response = true;
                } else {
                    $return_response = isset($result['Message']) ? $result['Message'] : 'API Error';
                }
            }
        } catch (\Exception $e) {
            Log::error($ID . ' -> ExtensivBillingManagerApiController -> RefreshTokens -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    */

    /* Receive webhook */
    public function receiveBillManagerHook(Request $request)
    {
        try {
            $header = apache_request_headers();
            $body = $request->getContent();

            \Storage::disk('local')->append('receiveBillManagerHook3PL_' . date('YmdHis') . '.txt', 'Date: ' . date('Y-m-d H:i:s') . ', Webhook Data: ' . $body . ', Header: ' . json_encode($header));
            Log::info('ExtensivBillingManagerApiController -> receiveBillManagerHook -> Triggered');

            if (isset($header['Apikey']) && $header['Apikey'] == self::$invoiceApiKey) {
                $result = json_decode($body, 1);
                if (isset($result['invoice']) && is_array($result['invoice']) && count($result['invoice'])) {
                    $invoice = $result['invoice'];
                    if (isset($invoice['orgKey']) && $invoice['orgKey'] && isset($invoice['created']) && $invoice['created']) {
                        $user = DB::table('users')->select('id')->where('cognito_org', $invoice['orgKey'])->where('role', 'user')->where('status', 1)->first();
                        if ($user) {
                            $user_id = $user->id;
                            $user_integration_id = 0;
                            $sync_start_date = NULL;
                            //store mapping data in cache
                            $key = $this->platformId . '_invoice_webhook_' . $user_id;

                            // $find_in_cache = $this->mobj->get_or_set($key);
                            // if ($find_in_cache) {
                            //     $user_integration_id = $find_in_cache['user_integration_id'];
                            //     $sync_start_date = $find_in_cache['sync_start_date'];
                            // } else {
                                $user_workflow_rule = DB::table('user_workflow_rule as ur')
                                    ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
                                    ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
                                    ->select('ur.user_integration_id', 'ur.sync_start_date')
                                    ->where('ur.user_id', $user_id)
                                    ->where('e.event_id', 'GET_INVOICE')
                                    ->where('ur.status', 1)
                                    ->where('pr.status', 1)
                                    ->where('e.status', 1)
                                    ->where('e.platform_id', $this->platformId)
                                    ->first();
                                if ($user_workflow_rule) {
                                    $user_integration_id = $user_workflow_rule->user_integration_id;
                                    $sync_start_date = $user_workflow_rule->sync_start_date;

                                    // $this->mobj->get_or_set($key, ['user_integration_id' => $user_integration_id, 'sync_start_date' => $sync_start_date], 10800); //3 hours 3x60x60=10800
                                }
                            //}

                            if ($user_integration_id) {
                                $platform_customer_id = NULL;
                                if (isset($result['customer']) && is_array($result['customer']) && count($result['customer'])) {
                                    $customer = $result['customer'];
                                    $fields = [
                                        'user_id' => $user_id,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'api_customer_id' => $customer['customerId'],
                                        'customer_name' => @$customer['customerName'], //@$customer['settings']['primaryAddress']['contactName'],
                                        'email' => @$customer['settings']['primaryAddress']['contactEmail'],
                                        'phone' => @$customer['settings']['primaryAddress']['contactPhone'],
                                        'company_name' => @$customer['customerName'],
                                        'address1' => trim(@$customer['settings']['primaryAddress']['addr1'] . ' ' . @$customer['settings']['primaryAddress']['addr2']),
                                        'address2' => @$customer['settings']['primaryAddress']['city'],
                                        'address3' => @$customer['settings']['primaryAddress']['state'],
                                        'postal_addresses' => @$customer['settings']['primaryAddress']['zip'],
                                        'country' => @$customer['settings']['primaryAddress']['country'],
                                        'is_deleted' => 0,
                                        'type' => 'Customer',
                                        'sync_status' => 'Ready'
                                    ];

                                    $where = ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_customer_id' => $customer['customerId'], 'type' => 'Customer'];
                                    $PlatformCustomer = PlatformCustomer::updateOrCreate($where, $fields);

                                    $platform_customer_id = $PlatformCustomer->id;
                                }

                               
                                $invoice_date = Carbon::createFromTimestamp($invoice['created'])->toDateTimeString();
                                if (isset($invoice['status']) && $invoice['status'] && strtotime($sync_start_date) <= strtotime($invoice_date)) {
                                    $platform_invoice_id = NULL;
                                    $platform_invoice = PlatformInvoice::select('id', 'linked_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_invoice_id' => $invoice['id'], 'is_dropship' => 0])->first();
                                    if (is_null($platform_invoice) && $platform_customer_id && ($invoice['status'] == 'published' || $invoice['status'] == 'paid')) {
                                        $platform_invoice = PlatformInvoice::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_invoice_id' => $invoice['id'], 'platform_customer_id' => $platform_customer_id, 'invoice_date' => $invoice_date, 'total_amt' => @$invoice['total'], 'ref_number' => @$invoice['number'], 'customer_name' => @$customer['customerName'], 'due_date' => @$invoice['dueDate'], 'total_qty' => @$invoice['chargeCount'], 'invoice_state' => $invoice['status'], 'order_doc_number' => @$invoice['number'], 'order_state' => @$invoice['productizedBy'], 'invoice_code' => @$invoice['externalInvoiceId'], 'tracking_number' => @$invoice['trackingNumber'], 'pay_date' => @$invoice['paidDate'], 'ship_date' => @$invoice['publishDate']]);

                                        $platform_invoice_id = $platform_invoice->id;
                                         //Insert Invoice History for paid and published status
                                         if($invoice['status'] == 'published'){
                                            $dateVersion=Carbon::createFromTimestamp($invoice['publishDate'])->toDateTimeString();
                                         }else if($invoice['status'] == 'paid'){
                                            $dateVersion=Carbon::createFromTimestamp($invoice['paidDate'])->toDateTimeString();
                                         }
                                         PlatformInvoiceHistory::create([
                                            'platform_invoice_id'=>$platform_invoice_id,
                                            'invoice_status'=>@$invoice['status'],
                                            'api_created_at'=>$dateVersion]);
                                    } else {
                                        $platform_invoice_id = $platform_invoice->id;

                                        $invoiceData = ['invoice_state' => $invoice_date, 'total_amt' => @$invoice['total'], 'ref_number' => @$invoice['number'], 'due_date' => @$invoice['dueDate'], 'total_qty' => @$invoice['chargeCount'], 'invoice_state' => $invoice['status'], 'order_doc_number' => @$invoice['number'], 'order_state' => @$invoice['productizedBy'], 'invoice_code' => @$invoice['externalInvoiceId']];

                                        if (isset($invoice['trackingNumber'])) {
                                            $invoiceData['tracking_number'] = $invoice['trackingNumber'];
                                        }

                                        if (isset($invoice['paidDate'])) {
                                            $invoiceData['pay_date'] = $invoice['paidDate'];
                                        }

                                        if (isset($invoice['publishDate'])) {
                                            $invoiceData['ship_date'] = $invoice['publishDate'];
                                        }

                                        PlatformInvoice::where('id', $platform_invoice_id)
                                            ->update($invoiceData);

                                        if ($invoice['status'] == 'unpublished' && $platform_invoice->linked_id) {
                                            PlatformInvoice::where('id', $platform_invoice_id)
                                                ->update(['sync_status' => 'Ready', 'is_dropship' => 1]);
                                        } elseif ($invoice['status'] == 'unpublished') {
                                            PlatformInvoice::where('id', $platform_invoice_id)
                                                ->update(['sync_status' => 'Inactive']);
                                            $platform_invoice_id = NULL;
                                        }
                                        if ($invoice['status'] == 'unpublished') {
                                         //Insert Invoice History for unpublished status
                                         PlatformInvoiceHistory::create([
                                            'platform_invoice_id'=>$platform_invoice_id,
                                            'invoice_status'=>@$invoice['status'],
                                            'api_created_at'=>$invoice_date ]);
                                         }
                                    }

                                    if ($platform_invoice_id) {

                                        if ($invoice['status'] == 'paid') {

                                            $platform_invoice_transaction = PlatformInvoiceTransaction::select('id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_invoice_id' => $platform_invoice_id])->first();
                                            if (is_null($platform_invoice_transaction)) {
                                                PlatformInvoiceTransaction::create(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_invoice_id' => $platform_invoice_id, 'transaction_id' => @$invoice['transactionId'], 'transaction_amount' => @$invoice['total'], 'transaction_datetime' => @$invoice['paidDate'], 'transaction_type' => @$invoice['productizedBy'], 'sync_status' => 'Ready']);
                                            }

                                            PlatformInvoice::where('id', $platform_invoice_id)
                                                ->update(['total_paid_amt' => @$invoice['total'], 'invoice_payment_status' => 'paid', 'sync_status' => 'Ready']);
                                        }

                                        if (isset($result['chargesCsv']) && $result['chargesCsv']) {
                                            /*
                                                    [0] => id
                                                    [1] => orgKey
                                                    [2] => customerId
                                                    [3] => customerName
                                                    [4] => rateId
                                                    [5] => eventId
                                                    [6] => invoiceId
                                                    [7] => invoiceNumber
                                                    [8] => warehouseId
                                                    [9] => warehouseName
                                                    [10] => transactionId
                                                    [11] => glAccount
                                                    [12] => transactionType
                                                    [13] => category
                                                    [14] => chargeType
                                                    [15] => chargeLabel
                                                    [16] => memo
                                                    [17] => countingUnit
                                                    [18] => countingMethod
                                                    [19] => qty
                                                    [20] => chargePerUnit
                                                    [21] => total
                                                    [22] => status
                                                    [23] => createdAsDate
                                                    [24] => created
                                                    [25] => inboundReferenceNumber
                                                    [26] => outboundReferenceNumber
                                                    [27] => trackingNumber
                                                    [28] => sku
                                                    [29] => weight
                                                    [30] => bolNumber
                                                    [31] => poNumber
                                                    [32] => lotNumber
                                                    [33] => serialNumber
                                                    [34] => muLabel
                                                    [35] => storageUoM
                                                    [36] => inventoryUoM
                                                    [37] => trailerNumber
                                                    [38] => confirmDate
                                                    [39] => parsedConfirmedDate
                                                    [40] => locationName
                                                */
                                            $products = [];
                                            $handle = fopen($result['chargesCsv'], 'r');
                                            if ($handle !== false) {
                                                // Skip the first line
                                                fgetcsv($handle);
                                                while (($data = fgetcsv($handle)) !== false) {
                                                    if (isset($data[12]) && isset($data[13]) && isset($data[19]) && isset($data[21]) && $data[19]) {
                                                        $productIndex = str_replace(' ', '-', trim($data[12] . '-' . $data[13]));
                                                        if (isset($products[$productIndex])) {
                                                            $products[$productIndex]['total_qty'] = $products[$productIndex]['total_qty'] + $data[19];
                                                            $products[$productIndex]['total_amount'] = $products[$productIndex]['total_amount'] + $data[21];
                                                        } else {
                                                            $products[$productIndex]['name'] = trim($data[12] . ' + ' . $data[13]);
                                                            $products[$productIndex]['total_qty'] = $data[19];
                                                            $products[$productIndex]['total_amount'] = $data[21];
                                                        }
                                                    }
                                                }
                                            }
                                            fclose($handle);

                                            foreach ($products as $api_code => $product) {
                                                $platform_invoice_line = PlatformInvoiceLine::select('id')->where('platform_invoice_id', $platform_invoice_id)->where('api_code', $api_code)->first();
                                                if (is_null($platform_invoice_line)) {
                                                    PlatformInvoiceLine::create(['platform_invoice_id' => $platform_invoice_id, 'api_code' => $api_code, 'product_name' => $product['name'], 'sku' => $api_code, 'qty' => $product['total_qty'], 'total' => $product['total_amount']]);
                                                } else {
                                                    //PlatformInvoiceLine::where('id', $platform_invoice_line->id)
                                                    //->update(['product_name' => $product['name'], 'sku' => $api_code, 'qty' => $product['total_qty'], 'total' => $product['total_amount']]);
                                                }
                                            }

                                            PlatformInvoice::where('id', $platform_invoice_id)
                                                ->update(['sync_status' => 'Ready']);

                                            //storing file in aws
                                            $chargesCsvURL = "";
                                            try {
                                                $content = file_get_contents($result['chargesCsv']);
                                                //formate store shipment label url
                                                $dynamic_file_name = 'extensiv/chargesCsv/' . $user_integration_id . '/' . $invoice['id'] . '.csv';
                                                //upload file in s3 bucket
                                                \Storage::disk('s3')->put($dynamic_file_name, $content);
                                                if (\Storage::disk('s3')->exists($dynamic_file_name)) {
                                                    $bucket_name = env('AWS_BUCKET');
                                                    $aws_region = env('AWS_DEFAULT_REGION');
                                                    $chargesCsvURL = 'https://' . $bucket_name . '.s3.' . $aws_region . '.amazonaws.com/' . $dynamic_file_name;
                                                }
                                            } catch (\Exception $e) {
                                                Log::error('ExtensivBillingManagerApiController -> receiveBillManagerHook -> ' . $e->getLine() . ' -> ' . $e->getMessage());
                                            }

                                            PlatformInvoice::where('id', $platform_invoice_id)->whereNull('file_path')
                                                ->update(['file_path' => $chargesCsvURL]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('ExtensivBillingManagerApiController -> receiveBillManagerHook -> ' . $e->getLine() . ' -> ' . $e->getMessage());
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /* 
    //Get Customers
    public function getCustomers($user_id, $user_integration_id, $is_initial_sync)
    {
        $return_response = true;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
            if ($account) {
                if ($is_initial_sync) {
                    $loop_status = true;
                    $pgnum = 1;
                    $pgsiz = 100;
                    while ($loop_status) {
                        $response = $this->ebm->CallAPI($account, 'GET', "/customers?pgsiz={$pgsiz}&pgnum={$pgnum}&sort=ReadOnly.creationDate", [], 'json');
                        $result = json_decode($response, true);
                        if (isset($result['ResourceList']) && is_array($result['ResourceList'])) {
                            foreach ($result['ResourceList'] as $customer) {
                                $this->manageCustomer($user_id, $user_integration_id, $customer);
                            }

                            if (count($result['ResourceList']) == $pgsiz) {
                                $pgnum++;
                            } else {
                                $return_response = true;
                                $loop_status = false;
                            }
                        } else if (isset($items['ErrorCode'])) {
                            $return_response = isset($items['Hint']) ? $items['Hint'] : $items['ErrorCode'];
                            $loop_status = false;
                        } else {
                            $return_response = 'API Error:Unauthorized';
                            $loop_status = false;
                        }
                    }
                } else {
                    $rql = '';
                    $latest_record = PlatformCustomer::select('api_created_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'type' => 'Customer'])->orderBy('api_created_at', 'DESC')->first();
                    if ($latest_record) {
                        $rql = "&rql=ReadOnly.creationDate=gt={$latest_record->api_created_at}";
                    }

                    $response = $this->ebm->CallAPI($account, 'GET', "/customers?pgsiz=100{$rql}&sort=ReadOnly.creationDate", [], 'json');
                    $result = json_decode($response, true);
                    if (isset($result['ResourceList']) && is_array($result['ResourceList']) && count($result['ResourceList'])) {
                        foreach ($result['ResourceList'] as $customer) {
                            $this->manageCustomer($user_id, $user_integration_id, $customer);
                        }

                        $return_response = true;
                    } else if (isset($items['ErrorCode'])) {
                        $return_response = isset($items['Hint']) ? $items['Hint'] : $items['ErrorCode'];
                    } else {
                        $return_response = 'API Error:Unauthorized';
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error($user_integration_id . ' -> ExtensivBillingManagerApiController -> GetCustomers -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    //Manage Customer
    public function manageCustomer($user_id, $user_integration_id, $customer)
    {
        $return_response = true;
        try {
            $fields = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_customer_id' => $customer['ReadOnly']['CustomerId'],
                'customer_name' => @$customer['CompanyInfo']['CompanyName'], //@$customer['CompanyInfo']['Name'],
                'email' => @$customer['CompanyInfo']['EmailAddress'],
                'phone' => @$customer['CompanyInfo']['PhoneNumber'],
                'company_name' => @$customer['CompanyInfo']['CompanyName'],
                'address1' => trim(@$customer['CompanyInfo']['Address1'] . ' ' . @$customer['CompanyInfo']['Address2']),
                'address2' => @$customer['CompanyInfo']['City'],
                'address3' => @$customer['CompanyInfo']['State'],
                'postal_addresses' => @$customer['CompanyInfo']['Zip'],
                'country' => @$customer['CompanyInfo']['Country'],
                'fax' => @$customer['CompanyInfo']['Fax'],
                'api_created_at' => $customer['ReadOnly']['CreationDate'],
                'is_deleted' => $customer['ReadOnly']['Deactivated'] ? 1 : 0,
                'type' => 'Customer',
                'sync_status' => $customer['ReadOnly']['Deactivated'] ? 'Inactive' : 'Ready'
            ];

            $where = ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_customer_id' => $customer['ReadOnly']['CustomerId'], 'type' => 'Customer'];
            PlatformCustomer::updateOrCreate($where, $fields);
        } catch (\Exception $e) {
            Log::error($user_integration_id . ' -> ExtensivBillingManagerApiController -> manageCustomer -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }
    */

    /* Execute Extensiv Billing Manager Method */
    public function ExecuteExtensivBillingManagerEvent($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
    {
        $response = true;
        /*
        if ($method == 'GET' && $event == 'CUSTOMER') {
            $response = $this->getCustomers($user_id, $user_integration_id, $is_initial_sync);
        }
        */
        return $response;
    }
}
