<?php

namespace App\Http\Controllers\BlackLine;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\FieldMappingHelper;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformAccount;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformInvoice;
use App\Models\PlatformCustomer;
use App\Models\PlatformInvoiceTransaction;
use App\Models\PlatformOrderTransaction;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use phpseclib3\Net\SFTP;
use App\Helper\Api\BlackLineApi;

class BlackLineApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $myPlatform = 'blackline';
    var $isRemoveFileFromStorage = false;
    var $isMoveFileFromOtherFolder = false;
    var $transactionCount = 0;
    var $transactionUpdateCount = 0;
    /**
     *
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        $this->blacklineapi = new BlackLineApi();
        $this->helper = new ConnectionHelper();
        $this->wfsnip = new WorkflowSnippet();
        $this->my_platform = 'blackline';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    /**
     *
     */
    public function InitiateBlackLineAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.blackline_auth", compact('platform'));
    }

    /**
     * @deprecated version
     */
    public function ConnectBlackLineOauth(Request $request)
    {
        $account_id = trim($request->account_id);
        $app_id = trim($request->app_id);
        $app_secret = trim($request->app_secret);

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $isAllowed = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id], ['app_ref', 'client_id', 'client_secret']);

        if ($isAllowed) {
            $app_ref = $this->mobj->encrypt_decrypt($isAllowed->app_ref,'decrypt');
            $client_id = $this->mobj->encrypt_decrypt($isAllowed->client_id,'decrypt');
            $client_secret = $this->mobj->encrypt_decrypt($isAllowed->client_secret,'decrypt');

            $response = $this->blacklineapi->CheckAPIAndReturnSession($user_id,$account_id, $app_id, $app_secret);

            if ($response['api_status'] == 'success') {
                if (isset($response['operation']['result']['data']['api']['sessionid'])) {
                    //return $response['operation']['result']['data']['api']['sessionid'];

                    $OauthData = [
                        'access_token' => $this->mobj->encrypt_decrypt($response['operation']['result']['data']['api']['sessionid'],'encrypt'),
                        'app_id' => $this->mobj->encrypt_decrypt($app_id,'encrypt'),
                        'app_secret' => $this->mobj->encrypt_decrypt($app_secret,'encrypt'),
                        'account_name' => $account_id,
                        'user_id' => $user_id,
                        'platform_id' => $this->my_platform_id,
                        'expires_in' => 3600,
                        'token_refresh_time' => time()
                    ];

                    $ufound =  $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id,'platform_id' => $this->my_platform_id,'account_name' => $account_id],['id']);

                    if ($ufound) {
                        $this->mobj->makeUpdate('platform_accounts',$OauthData,['id' => $ufound->id]);
                    } else {
                        $this->mobj->makeInsert('platform_accounts',$OauthData);
                    }

                } else {
                    Session::put('auth_msg', 'Sign-in information is incorrect');
               }
            }else{
                Session::put('auth_msg', $response['api_error']);
            }
        } else {
            Session::put('auth_msg', 'Authentication Error');
        }

        echo '<script>window.close();</script>';
    }

    /**
     *
     */
    public function ConnectBlackLineAuth(Request $request)
    {
        if ($request->isMethod('post')) {
            $flag = true;
            $validator = Validator::make($request->all(), [
                'account_name' => 'required',
                'api_domain' => 'required',
                'region' => 'required',
                'secret_key' => 'required',
                'app_id' => 'required',
            ]);

            if ($validator->fails()) {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = $validator->getMessageBag()->toArray();
            } else {
                // to check whether given account is already in use or not.

                $checkExistingAc = $this->checkExistingConnectedAcc($this->my_platform_id, $request->api_domain, $request->app_id, $request->secret_key);

                if ($checkExistingAc) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'This account detail already exist, Try with another account.';
                } else {
                    $checkCredentials = $this->blacklineapi->CheckCredentials($request->api_domain, $request->marketplace_id, $request->app_id, $request->secret_key );

                    if (!$checkCredentials || !isset($this->my_platform_id)) {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Invalid BlackLine credentials!';
                    } else {
                        $arr_field = [
                            'account_name' => $request->account_name,
                            'user_id' => Auth::user()->id,
                            'platform_id' => $this->my_platform_id,
                            'app_id' => $request->app_id,
                            'api_domain' => $request->api_domain,
                            'region' => $request->region,
                            'secret_key' => $request->secret_key,
                        ];
                        $this->mobj->makeInsertGetId('platform_accounts', $arr_field);
                    }
                }
            }

            if ($flag) {
                $data['status_code'] = 1;
                $data['status_text'] = 'Account connected successfully.';
            }

            return response()->json($data);
        }
    }

    /**
     *
     */
    public function checkExistingConnectedAcc($platform_id, $api_domain, $app_id, $secret_key)
    {
        $checkAccount = PlatformAccount::where( ['platform_id' => $platform_id, 'api_domain' => $api_domain, 'app_id' => $app_id, 'secret_key' => $secret_key] )->first();
        if ($checkAccount) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
     */
    public function ExecuteEventBlackLine($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id='',$platform_workflow_rule_id='', $record_id = '')
    {
        //Log::info("ExecuteEventBlackLine- Method: ".$method.", event: ".$event);
        $response = true;
        if ($method == 'GET' && $event == 'CUSTOMER') {
            $response = $this->getBlackLineCustomerFiles($user_id,$user_integration_id,$is_initial_sync);
        } else if ($method == 'GET' && $event == 'INVOICE') {
            $response = $this->getBlacklineInvoicePayments($user_id,$user_integration_id, $destination_platform_id, $source_platform_id, $is_initial_sync );
        } else if ($method == 'MUTATE' && $event == 'INVOICE') {
            $response = $this->uploadBlacklineOpenInvoiceItems( $user_id, $user_integration_id, $destination_platform_id, $source_platform_id );
        } else if ($method == 'MUTATE' && $event == 'PAYMENT') {
            $response = $this->createBlacklineARPaymentSynchronize( $user_id, $user_integration_id, $destination_platform_id, $source_platform_id );
        } else if ($method == 'MUTATE' && $event == 'CUSTOMER') {
            $response = $this->uploadBlackLineCustomerFiles( $user_id, $user_integration_id, $destination_platform_id, $source_platform_id );
        }
        return $response;
    }

    /**
     * Fetch customer files from blackline server
     */
    public function getBlackLineCustomerFiles($user_id,$user_integration_id,$is_initial_sync=0)
    {
        // Log::info( "getBlackLineCustomerFiles- user_id: ".$user_id.", user_integration_id: ".$user_integration_id.", is_initial_sync: ".$is_initial_sync);
        date_default_timezone_set("US/Eastern");
        $return_response = false;

        // if( date('H') == 5 )// && !$this->cache->get_or_set( $user_integration_id."_custcsvfiledate" ) )
        {
            try{
                $getFTPDetails = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'region', 'app_id', 'secret_key'] );

                if( $getFTPDetails ){
                    $host = $getFTPDetails->api_domain;
                    $port = $getFTPDetails->region;
                    $user = $getFTPDetails->app_id;
                    $password = $getFTPDetails->secret_key;
                    $localFolder = "public/esb_asset/blackline-customer/".$user_integration_id."/";

                    $sftp = new SFTP($host, $port);

                    if (!$sftp->login($user, $password)) {
                    } else {

                        $fileLocation = $this->map->getMappedDataByName($user_integration_id, null, "fetch_customer_file_location", ['custom_data']);
                        $fileList = $sftp->nlist( $fileLocation->custom_data , FALSE);

                        foreach ($fileList as $file) {
                            if( $file != "." && $file != ".."){//&& $file == "Customer File.csv"

                                //check if its a file or not
                                if ($sftp->is_file( $fileLocation->custom_data.'/'.$file)) {
                                    if (!file_exists($localFolder)) {
                                        mkdir( $localFolder, 0777, true);
                                    }
                                    // echo $fileLocation->custom_data.'/'.$file, $localFolder.$file."<br>";
                                    $sftp->get( $fileLocation->custom_data.'/'.$file, $localFolder.$file);
                                }

                                if( $this->isRemoveFileFromStorage ){
                                    $sftp->delete( $file );
                                }

                                if( $this->isMoveFileFromOtherFolder ){
                                    if (!$sftp->file_exists($fileLocation->custom_data."/move")) {
                                        $sftp->mkdir( $fileLocation->custom_data."/move", 0777, true);
                                    }
                                    $sftp->rename( $fileLocation->custom_data."/".$file, $fileLocation->custom_data."/move/".$file );
                                }
                            }
                        }
                        $return_response = true;
                    }
                }
            } catch (\Exception $e) {
                Log::error($user_integration_id."--getBlackLineCustomerFiles-->".$e->getMessage());
                $return_response = false;//$e->getMessage();
            }
        }

        return $return_response;
    }

    /**
     *
     */
    public function readCSV($csvFile, $array)
    {
        $file_handle = fopen($csvFile, 'r');
        while (!feof($file_handle)) {
            $line_of_text[] = fgetcsv($file_handle, 0, $array['delimiter']);
        }
        fclose($file_handle);
        return $line_of_text;
    }

    /**
     * send customer file into blackline server
     */
    public function uploadBlackLineCustomerFiles( $user_id, $user_integration_id, $destination_platform = '', $source_platform='' )
    {
        // //Log::info("uploadBlackLineCustomerFiles- user_id".$user_id.", user_integration_id: ".$user_integration_id.", destination_platform: ".$destination_platform.", source_platform: ".$source_platform);
        $return_response = true;

        // $this->cache->get_or_set( $user_integration_id."_custcsvfiledate",  );

        $blackline_weekly_customer_csv_file_send = DB::table('platform_urls')
            ->where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->my_platform_id,
                'url_name' => 'blackline_weekly_customer_csv_file_send'
                ])
            ->select('id','url')
            ->first();

        //check current date + 1 day (Weekly)
        $run = false;
        if( $blackline_weekly_customer_csv_file_send == null
            ||
            ( isset( $blackline_weekly_customer_csv_file_send->url ) && strtotime( $blackline_weekly_customer_csv_file_send->url, strtotime("+1 days") ) === strtotime( date( 'Y-m-d' ) ) )
        ){
            $run = true;
        }

        if( ( ( date('H') == 3 || date('H') == 4 ) && $run ) || ( isset( $_GET['isTest'] ) && $_GET['isTest'] == 1 ) )
        {
            // try
            {
                $getFTPDetails = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'region', 'app_id', 'secret_key'] );
                echo "<pre>";
                print_r($getFTPDetails);
                if( $getFTPDetails )
                {
                    $host = trim( $getFTPDetails->api_domain );
                    $port = trim( $getFTPDetails->region );
                    $user = trim( $getFTPDetails->app_id );
                    $password = trim( $getFTPDetails->secret_key );
                    $sftp = new SFTP($host, $port);

                    if (!$sftp->login($user, $password)) {
                        $sftp->getLog();
                        $sftp->getSFTPLog();
                    }else{

                        $fileLocation = $this->map->getMappedDataByName($user_integration_id, null, "send_customer_file_location", ['custom_data']);
                        if( $fileLocation->custom_data ){

                            if (!file_exists( $fileLocation->custom_data ) ) {
                                mkdir( $fileLocation->custom_data, 0777, true);
                            }

                            /**
                             * URL base get customer records
                             */
                            $offset = 0;
                            $pagesize = 200;
                            $limit = [];

                            $limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->my_platform_id,
                                'url_name' => 'blackline_customer_limit'
                            ],
                            ['url', 'id']);

                            if ($limit) {
                                $offset = $limit->url;
                            }

                            $customers = array();
                            $col_val = [
                                "Division",
                                "LeadgerReference",
                                "LeadgerInstruction",
                                "CustomerName",
                                "AddressLine1",
                                "AddressLine2",
                                "AddressLine3",
                                "AddressLine4",
                                "PostCode",
                                "CostCenter",
                                "AccountStatus",
                                "CustomerBillingMethod"
                            ];

                            $customers[] = $col_val;

                            //fetch Source platform id by name
                            $source_platform_id = $this->helper->getPlatformIdByName( $destination_platform );

                            //get all customer from intacct server
                            $customerArr = PlatformCustomer::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $source_platform_id,
                                'is_deleted' => 0
                            ])
                            ->whereIn( 'sync_status', [ PlatformStatus::READY, PlatformStatus::SYNCED, PlatformStatus::FAILED ] )
                            ->offset($offset)
                            ->limit($pagesize)
                            ->get();

                            $path = '/esb_asset/intacct-customer/';
                            if( COUNT( $customerArr ) > 0 ){
                                foreach($customerArr as $row){
                                    $address = explode( ", ", $row['address1'] );
                                    $col_val = [
                                        "100USD",
                                        $row['api_customer_code'],
                                        "1",
                                        $row['customer_name'] ?? $row['first_name']." ".$row['last_name'],
                                        $address[0],
                                        $address[1] ?? '',
                                        $row['address2'],
                                        $row['address3'],
                                        $row['postal_addresses'],
                                        "",
                                        $row['account_status'],
                                        ""
                                    ];
                                    $customers[] = $col_val;

                                    //update customer data as Synced status
                                    $this->mobj->makeUpdate('platform_customer', ['sync_status' => 'Synced'], ['id' => $row['id'] ] );
                                }

                                $this->blacklineapi->createCSVFilesWithSpecificFolder( $customers, $user_integration_id, $path, 'customers' );

                                $return_response = 'data Remaining';
                                if ($limit) {
                                    $this->mobj->makeUpdate('platform_urls', ['url' => ( $offset + $pagesize )], ['id' => $limit->id]);
                                } else {
                                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => ( $offset + $pagesize ), 'url_name' => 'customer_limit']);
                                }
                            }
                            else
                            {
                                $files = glob( public_path( $path.$user_integration_id."/*.csv" ) );
                                if( COUNT( $files ) >0 ){
                                    foreach ( $files as $k=>$file) {
                                        $fileFolderArr = explode( "/", $file );
                                        $fileName = array_reverse( $fileFolderArr );
                                        $sftp->put( $fileLocation->custom_data."/".$fileName[0], $file, 1 );

                                        if( $this->isRemoveFileFromStorage ){
                                            unlink( $file );
                                        }
                                    }
                                    $return_response = true;
                                }

                                $nextDate = date('Y-m-d');
                                if ($blackline_weekly_customer_csv_file_send) {
                                    $this->mobj->makeUpdate('platform_urls', ['url' => $nextDate ], ['id' => $blackline_weekly_customer_csv_file_send->id]);
                                } else {
                                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => $nextDate, 'url_name' => 'blackline_weekly_customer_csv_file_send']);
                                }

                                //reset customer_limit
                                $this->mobj->makeUpdate('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => 0, 'url_name' => 'blackline_customer_limit']);
                            }
                        }
                    }
                }
            }
            // catch (\Exception $e) {
            //     Log::error($user_integration_id."--uploadBlackLineCustomerFiles-->".$e->getMessage());
            //     $return_response = $e->getMessage();
            // }
        }
        return $return_response;
    }

    /**
     * fetch Open Item/Invoice payment txt files form blackline server
     */
    public function getBlacklineInvoicePayments($user_id,$user_integration_id, $destination_platform='', $is_initial_sync){
        //Log::info("getBlacklineInvoicePayments- user_id: ".$user_id.", user_integration_id: ".$user_integration_id.", destination_platform: ".$destination_platform.", is_initial_sync: ".$is_initial_sync);
        $this->mobj->AddMemory();
        $return_response = true;

        if( $is_initial_sync )
            return $return_response;

        try{
            $getFTPDetails = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'region', 'app_id', 'secret_key'] );
            $host = $getFTPDetails->api_domain;
            $port = $getFTPDetails->region;
            $user = $getFTPDetails->app_id;
            $password = $getFTPDetails->secret_key;
            $sftp = new SFTP($host, $port);

            if (!$sftp->login($user, $password )) {
                $return_response = ( $sftp->getSFTPLog() != "" ) ? $sftp->getSFTPLog() : 'Cannot login into your server !';//getLastSFTPError, getSFTPErrors
            }else{
                $getRemotePath = $this->map->getMappedDataByName($user_integration_id, null, "fetch_invoice_file_location", ['custom_data']);
                $fileList = $sftp->nlist( $getRemotePath->custom_data , FALSE);

                $invoiceArr = [];
                $fileKey = -1;

                //add list file name you don't to read it
                $fileName = [
                    'AA3W.txt',
                ];

                // $l=0;

                foreach ($fileList as $k=>$file) {
                    $paymentKey = -1;
                    $customerKey = -1;
                    if( $file != "." && $file != ".." && !in_array( $file, $fileName ) ){

                        //check if its a file or not
                        $serverFile = $getRemotePath->custom_data."/".$file;
                        if ($sftp->is_file( $serverFile)) {

                            $readDirectory = $getRemotePath->custom_data."/move";
                            if (!$sftp->is_dir($readDirectory)) {
                                $sftp->mkdir($readDirectory);
                            }

                            // Log::info( "File: ".$serverFile );
                            $readFile = $sftp->get( $serverFile );
                            $readFile = explode("\n", $readFile);

                            foreach( $readFile as $line ){
                                if( $line != "" ){
                                    $data = explode( "|", $line );

                                    //H: Header
                                    if( $data['0'] == "H" ){
                                        $fileKey++;
                                        $invoiceArr[$fileKey]['header'] = $data;
                                    }

                                    // P: Payment
                                    if( $data['0'] == "P" && $fileKey >= 0 ){
                                        $paymentKey++;
                                        $customerKey = -1;
                                        $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                                        // $invoiceArr[$fileKey]['payment'] = $data;
                                    }

                                    // C: Customers
                                    if( $data['0'] == "C" && $fileKey >= 0 ){
                                        $customerKey++;
                                        $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey] = $data;
                                        // $invoiceArr[$fileKey]['customer'][] = $data;
                                    }

                                    //T: Transaction Level/Open Invoice
                                    if( $data['0'] == "T" && $customerKey >= 0 ){
                                        // Log::info( "Line: ".++$l." ".$line );
                                        $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                                        // $invoiceArr[$fileKey]['customer'][$customerKey]['transaction'][] = $data;
                                    }

                                    //A: Individual Allocation
                                    if( $data['0'] == "A" && $customerKey >= 0 ){
                                        // Log::info( "Line: ".++$l." ".$line );
                                        $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                                        // $invoiceArr[$fileKey]['customer'][$customerKey]['allocation'][] = $data;
                                    }

                                    //L: Holding/Suspend
                                    if( $data['0'] == "L" && $customerKey >= 0){
                                        $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                                        // $invoiceArr[$fileKey]['customer'][$customerKey]['holding'][] = $data;
                                    }
                                }
                            }
                        }

                        if( $this->isRemoveFileFromStorage ){
                            $sftp->delete( $serverFile );
                        }
                    }

                }

                /**
                 * Header(H)            Payment(P)              Customer(C)             Account(A)              Holding(L)              Transaction(T)
                 * 0.identifier         0.identifier            0.identifier            0.identifier            0.identifier            0.identifier
                 * 1.batch reference    1.account number        1.account number        1.posting type          1.account number        1.invoice number
                 * 2.line count         2.division              2.division              2.account number        2.division              2.document number
                 * 3.sum of payment     3.payment comments      3.payment comment       3.division              3.payment comment       3.invoice date
                 *                      4.debit value           4.debit value           4.payment comment       4.debit value           4.account allocation
                 *                      5.credit value          5.credit value          5.debit value           5.credit                5.allocation comment
                 *                      6.payment type          6.payment type          6.credit value          6.payment type          6.doc type
                 *                      7.posting date          7.posting date          7.payment type          7.posting date          7.invdata 1 - 8
                 *                      8.GL code               8.GL code               8.posting date          8.GL code               16.remdata 1 - 8
                 *                      9.payment ID            9.payment id            9.GL code               9.payment id
                 *                      10.allocation id        10.allocation id        10.payment id           10.allocation id
                 *                      11.payment name         11.payment name         11.allocation id        11.payment name
                 *                      12.payment reference    12.payment reference    12.payment name         12.payment reference
                 *                      13.currency             13.paymentdata 1 - 6    13.payment reference    13. paydata 1 - 6
                 *                      14.payment data 1 - 6                           14.custdata 1 - 5
                 */

                /**
                 * 0. Division 1. LedgerReference 2. LedgerInstruction 3. TransactionReference 4. TransactionDate 5. AmountOutstanding 6.StatementTransactionType
                 * 7. CustomerName 8. InvoiceDueDate 9. DiscountedAmountAvailable 10. DocumentNumber 11. P.O.Number 12. INVDATA1 13. INVDATA2
                 */

                //fetch Destination/Linked platform id by name
                // $destinationPlatformID = $this->helper->getPlatformIdByName( $destination_platform );

                foreach($invoiceArr as $k=>$invArr){
                    $sync_status = PlatformStatus::READY;

                    foreach( $invArr as $ar ){
                        $linkedId = 0;
                        //check txt file customer record exist
                        if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){
                            // //Log::info("File Name: ".$invoiceArr[$k]['header'][1]);
                            $fileName = $invoiceArr[$k]['header'][1];
                            $scenarioAccept = true;

                            foreach($ar['customer'] as $c=>$cr){

                                $acceptTransaction = $acceptAllocation = true;

                                //generate customer resources
                                $customerArr = $this->blacklineapi->generateCustomerArr( $cr, $user_id, $user_integration_id );

                                //check current platform customer record exist in our database
                                $pc = PlatformCustomer::where( [
                                        // 'api_customer_id' => $customerArr['api_customer_id'],
                                        'api_customer_code' => $customerArr['api_customer_code'],
                                        'platform_id' => $this->my_platform_id,//pass source platform id
                                        'user_integration_id' => $user_integration_id
                                    ] )->select('id')->first();

                                //customer exist then get customer primary id
                                if ( $pc ) {
                                    $platform_customer_id = $pc->id;
                                } else {
                                    $customerArr['sync_status'] = 'Pending';
                                    $platform_customer_id = PlatformCustomer::insertGetId($customerArr);
                                }

                                // create order invoice with transaction
                                if( isset( $cr['transaction'] ) && COUNT( $cr['transaction'] ) >0 ){

                                    foreach($cr['transaction'] as $trnx){

                                        //Generate transaction records
                                        $invoiceTransaction = [];
                                        $invoiceTransaction['platform_id'] = $this->my_platform_id;
                                        $invoiceTransaction['user_integration_id'] = $user_integration_id;
                                        // $invoiceTransaction['platform_order_id'] = $platform_order_id;
                                        $invoiceTransaction['platform_invoice_id'] = $trnx[2];
                                        $invoiceTransaction['transaction_id'] = $trnx[1];
                                        $invoiceTransaction['memo'] = $ar['payment'][3];
                                        $invoiceTransaction['transaction_method'] = $ar['payment'][6];
                                        $invoiceTransaction['transaction_amount'] = $trnx[4];
                                        $invoiceTransaction['transaction_approval'] = $trnx[0]."-".$invoiceArr[$k]['header'][1];
                                        $invoiceTransaction['platform_customer_id'] = $platform_customer_id;
                                        $invoiceTransaction['currency_code'] = $ar['payment'][13];
                                        if( $trnx[3] ){
                                            $dateArr = explode( "/", $trnx[3] );
                                            $invoiceTransaction['receipt_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                        }

                                        $invoiceTransaction['reference_no'] = is_numeric( $ar['payment'][12] ) ?  $ar['payment'][12] : null;

                                        if( $ar['payment'][7] ){
                                            $dateArr = explode( "/", $ar['payment'][7] );
                                            $invoiceTransaction['payment_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                        }

                                        //Check Scenario 6: One Payment to one customer
                                        if( isset( $cr['transaction'] ) && COUNT( $cr['transaction'] ) > 0
                                            && ( !isset( $cr['allocation'] ) )
                                        ){
                                            // //Log::info("Scenario 6: Simple Transation");
                                            $invoiceTransaction['transaction_reference'] = "(".$trnx[1].") It's simply create_arpayment(AR) Transaction";
                                            $invoiceTransaction['row_type'] = "PAYMENT";
                                            $invoiceTransaction['transaction_type'] = "SIMPLETRANSACTION";
                                            $invoiceTransaction['linked_id'] = $this->saveInvoiceScenario( $trnx[2], $cr[1], 6 );

                                            $linkedId = $this->savePlatformInvoiceTransaction( $invoiceTransaction, $fileName );
                                            $$acceptTransaction = false;
                                        }

                                        //Check Scenario 1: Overpayment to be Written Off
                                        else if( ( isset( $cr['transaction'] ) && COUNT( $cr['transaction'] ) == 1 ) //check customer available in 1 transaction
                                            && $cr['transaction'][0][7] == "INV" //check 1st transaction not available reference number in +ve format or GLCode
                                            && ( isset( $cr['allocation'] ) && $cr['allocation'][0][6] > 0 ) //Use in allocation payment in 6th position
                                        ){
                                            // //Log::info("Scenario 1: ".$cr['transaction'][0][1]);

                                            $invoiceTransaction['transaction_reference'] = "(".$trnx[1].") It's simply ADJUSTMENT create_aradjustment(AR) Transaction";
                                            $invoiceTransaction['row_type'] = "ADJUSTMENT";
                                            $invoiceTransaction['transaction_type'] = "Debit Memo";
                                            $linkedId = $this->savePlatformInvoiceTransaction( $invoiceTransaction, $fileName );

                                            $$acceptTransaction = false;
                                        }

                                        //Check Scenario 3: One payment to one customer with several different deductions.
                                        else if( //COUNT( $ar['customer'] ) == 1 //check only 1 customer available
                                            isset( $cr['transaction'] ) && COUNT( $cr['transaction'] ) == 1 //check customer only 1 transaction available
                                            && isset( $cr['allocation'] ) && COUNT( $cr['allocation'] ) == 3 //check customer only 3 allocation available
                                        ){
                                            // //Log::info("Scenario 3");
                                            $invoiceTransaction['transaction_reference'] = "(".$trnx[1].") It's simply ADJUSTMENT create_aradjustment(AR) Transaction";
                                            $invoiceTransaction['row_type'] = "ADJUSTMENT";
                                            $invoiceTransaction['transaction_type'] = "Debit Memo";
                                            $linkedId = $this->savePlatformInvoiceTransaction( $invoiceTransaction, $fileName );

                                            //
                                            foreach( $cr['allocation'] as $alloc ){
                                                $acceptAllocation = false;
                                                $invoiceAllocation = [];
                                                $isDebit = ( $alloc[5] != "" ) ? false : true;
                                                $invoiceAllocation['platform_id'] = $this->my_platform_id;
                                                $invoiceAllocation['user_integration_id'] = $user_integration_id;
                                                // $invoiceTransaction['platform_order_id'] = $platform_order_id;
                                                $invoiceAllocation['platform_invoice_id'] = $cr['transaction'][0][2];
                                                $invoiceAllocation['transaction_id'] = null;
                                                $invoiceAllocation['transaction_reference'] = "This ADJUSTMENT required for ".(( $isDebit ) ? "Debit" : "Credit")." ar_adjustment(AR) Transaction";
                                                $invoiceAllocation['memo'] = $alloc[4];
                                                $invoiceAllocation['row_type'] = "ADJUSTMENT";
                                                $invoiceAllocation['transaction_type'] = ( !$isDebit ) ? "Credit Memo" : "Debit Memo";
                                                $invoiceAllocation['transaction_method'] = $alloc[7];
                                                $invoiceAllocation['transaction_amount'] = ( !$isDebit ) ? "-".$alloc[5] : $alloc[6];
                                                $invoiceAllocation['transaction_approval'] = $alloc[0]."-".$invoiceArr[$k]['header'][1];
                                                $invoiceAllocation['platform_customer_id'] = $platform_customer_id;
                                                $invoiceAllocation['currency_code'] = $ar['payment'][13];
                                                $invoiceAllocation['linked_id'] = $linkedId;
                                                if( $trnx[3] ){
                                                    $dateArr = explode( "/", $trnx[3] );
                                                    $invoiceAllocation['receipt_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                                }

                                                $invoiceAllocation['reference_no'] = is_numeric( $ar['payment'][12] ) ?  $ar['payment'][12] : null;

                                                if( $ar['payment'][7] ){
                                                    $dateArr = explode( "/", $ar['payment'][7] );
                                                    $invoiceAllocation['payment_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                                }

                                                $this->savePlatformInvoiceTransaction( $invoiceAllocation, $fileName );
                                            }

                                            //
                                            if( $acceptAllocation ){
                                                //Log::info("Allocation File Name: ".$invoiceArr[$k]['header'][1]);
                                            }
                                        }

                                        //Check Scenario 4: Short Pay Write Off with Adjustment
                                        else if( isset( $cr['transaction'] ) && COUNT( $cr['transaction'] ) == 2 //check customer only 2 transaction available
                                            && isset( $cr['allocation'] ) && COUNT( $cr['allocation'] ) == 1 // check customer only 1 allocation payment
                                            && ( $cr['transaction'][0][7] != "" && is_numeric( $cr['transaction'][0][7] ) ) //check 1st transaction available reference number in +ve format
                                            && $cr['transaction'][0][4] < 0 //check 1st transaction available amount in -ve format
                                            && $cr['transaction'][1][7] == "INV" //check 2nd transaction place amount to INV
                                        ){
                                            // //Log::info("Scenario 4: Short Pay Write Off with Adjustment");

                                            $invoiceTransaction['transaction_reference'] = "(".$trnx[1].") It's simply create_arpayment(AR) Transaction";
                                            $invoiceTransaction['row_type'] = "PAYMENT";
                                            $invoiceTransaction['transaction_type'] = "Credit Memo";
                                            $linkedId = $this->savePlatformInvoiceTransaction( $invoiceTransaction, $fileName );

                                            $acceptTransaction = false;
                                        }

                                        //
                                        //Check Scenario 5: Part Pay that Leaves a Remaining Balance
                                        else if( //COUNT( $ar['customer'] ) == 1 // check only 1 customer available
                                            isset( $cr['transaction'] ) && COUNT( $cr['transaction'] ) == 1 //check customer only 1 transaction
                                            && isset( $cr['allocation'] ) && COUNT( $cr['allocation'] ) == 1 //check customer only 1 allocation
                                        ){
                                            // //Log::info("Scenario 5: Part Pay that Leaves a Remaining Balance");
                                            $invoiceTransaction['transaction_reference'] = "(".$trnx[1].") It's simply create_arpayment(AR) Transaction";
                                            $invoiceTransaction['row_type'] = "ADJUSTMENT";
                                            $invoiceTransaction['transaction_type'] = "Credit Memo";
                                            $linkedId = $this->savePlatformInvoiceTransaction( $invoiceTransaction, $fileName );

                                            $acceptTransaction = false;
                                        }
                                    }

                                    if( $acceptTransaction ){
                                        //Log::info("Transaction File Name: ".$invoiceArr[$k]['header'][1]);
                                    }
                                }

                                // create adjustment with order transaction
                                if( isset( $cr['allocation'] ) && COUNT( $cr['allocation'] ) >0 ){

                                    foreach($cr['allocation'] as $k=>$alloc){
                                        // //Log::info("Allocation");
                                        $linkedId = 0;
                                        $isDebit = ( $alloc[5] != "" ) ? false : true;
                                        $invoiceAllocation = [];
                                        $invoiceAllocation['platform_id'] = $this->my_platform_id;
                                        $invoiceAllocation['user_integration_id'] = $user_integration_id;
                                        // $invoiceTransaction['platform_order_id'] = $platform_order_id;
                                        $invoiceAllocation['platform_invoice_id'] = null;
                                        $invoiceAllocation['transaction_id'] = null;
                                        $invoiceAllocation['transaction_reference'] = "This ADJUSTMENT required for ".( ( $isDebit ) ? "Debit" : "Credit" )." ar_adjustment(AR) Transaction";
                                        $invoiceAllocation['memo'] = $alloc[4];
                                        $invoiceAllocation['row_type'] = "ADJUSTMENT";
                                        $invoiceAllocation['transaction_type'] = ( !$isDebit ) ? "Credit Memo" : "Debit Memo";
                                        $invoiceAllocation['transaction_method'] = $alloc[7];
                                        $invoiceAllocation['transaction_amount'] = ( !$isDebit ) ? "-".$alloc[5] : $alloc[6];
                                        $invoiceAllocation['transaction_approval'] = $alloc[0]."-".$invoiceArr[$k]['header'][1];
                                        $invoiceAllocation['platform_customer_id'] = $platform_customer_id;
                                        $invoiceAllocation['currency_code'] = $ar['payment'][13];
                                        $invoiceAllocation['linked_id'] = $linkedId;
                                        if( $trnx[3] ){
                                            $dateArr = explode( "/", $trnx[3] );
                                            $invoiceAllocation['receipt_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                        }

                                        $invoiceAllocation['reference_no'] = is_numeric( $ar['payment'][12] ) ?  $ar['payment'][12] : null;

                                        if( $ar['payment'][7] ){
                                            $dateArr = explode( "/", $ar['payment'][7] );
                                            $invoiceAllocation['payment_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                        }
                                        $this->savePlatformInvoiceTransaction( $invoiceAllocation, $fileName );
                                        $acceptAllocation = false;
                                    }

                                    if( $acceptAllocation ){
                                        //Log::info("Allocation File Name: ".$invoiceArr[$k]['header'][1]);
                                    }
                                }
                            }

                            //Check Scenario 2: One payment to one customer that includes a deduction to a different customer.
                            if( COUNT( $ar['customer'] ) == 2 //check total 2 customer available
                                && !isset( $ar['customer'][0]['transaction'] ) && isset( $ar['customer'][0]['allocation'] ) //check only 2nd customer available in allocation
                                && isset( $ar['customer'][1]['transaction'] ) && COUNT( $ar['customer'][1]['transaction'] ) == 2 //check 2nd customer available 2 transaction
                                && isset( $ar['customer'][1]['allocation'] ) // check 2nd customer available 1 allocation
                                && $scenarioAccept // check any other scenario is fullfilled
                            ){
                                // //Log::info("Scenario 2");

                                foreach( $ar['customer'][1]['transaction'] as $trnx){
                                    $invoiceTransaction = [];
                                    $invoiceTransaction['platform_id'] = $this->my_platform_id;
                                    $invoiceTransaction['user_integration_id'] = $user_integration_id;
                                    // $invoiceTransaction['platform_order_id'] = $platform_order_id;
                                    $invoiceTransaction['platform_invoice_id'] = $trnx[2];
                                    $invoiceTransaction['transaction_id'] = $trnx[1];
                                    $invoiceTransaction['memo'] = $ar['payment'][3];
                                    $invoiceTransaction['transaction_method'] = $ar['payment'][6];
                                    $invoiceTransaction['transaction_amount'] = $trnx[4];
                                    $invoiceTransaction['transaction_approval'] = $trnx[0]."-".$invoiceArr[$k]['header'][1];
                                    $invoiceTransaction['platform_customer_id'] = $platform_customer_id;
                                    $invoiceTransaction['currency_code'] = $ar['payment'][13];
                                    $invoiceTransaction['linked_id'] = 0;
                                    if( $trnx[3] ){
                                        $dateArr = explode( "/", $trnx[3] );
                                        $invoiceTransaction['receipt_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                    }

                                    $invoiceTransaction['reference_no'] = is_numeric( $ar['payment'][12] ) ?  $ar['payment'][12] : null;

                                    if( $ar['payment'][7] ){
                                        $dateArr = explode( "/", $ar['payment'][7] );
                                        $invoiceTransaction['payment_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                    }

                                    $invoiceTransaction['transaction_reference'] = "(".$trnx[1].") It's simply create_arpayment(AR) Transaction";
                                    $invoiceTransaction['row_type'] = "OVERPAYMENT";
                                    $invoiceTransaction['transaction_type'] = "Credit Memo";

                                    $linkedId = $this->savePlatformInvoiceTransaction( $invoiceTransaction, $fileName );

                                    $acceptTransaction = false;
                                }

                                if( $acceptTransaction ){
                                    //Log::info("Transaction File Name: ".$invoiceArr[$k]['header'][1]);
                                }

                                $isDebit = ( $ar['customer'][1]['allocation'][0][5] != "" ) ? false : true;
                                $invoiceAllocation = [];
                                $invoiceAllocation['platform_id'] = $this->my_platform_id;
                                $invoiceAllocation['user_integration_id'] = $user_integration_id;
                                // $invoiceTransaction['platform_order_id'] = $platform_order_id;
                                $invoiceAllocation['platform_invoice_id'] = null;
                                $invoiceAllocation['transaction_id'] = null;
                                $invoiceAllocation['transaction_reference'] = "(".$ar['customer'][1]['allocation'][0][2].") This ADJUSTMENT required for ".( ( $isDebit ) ? "Debit" : "Credit" )." ar_adjustment(AR) Transaction";
                                $invoiceAllocation['memo'] = $ar['customer'][1]['allocation'][0][4];
                                $invoiceAllocation['row_type'] = "OVERPAYMENT";
                                $invoiceAllocation['transaction_type'] = ( !$isDebit ) ? "Credit Memo" : "Debit Memo";;
                                $invoiceAllocation['transaction_method'] = $ar['customer'][1]['allocation'][0][7];
                                $invoiceAllocation['transaction_amount'] = ( !$isDebit ) ? "-".$ar['customer'][1]['allocation'][0][5] : $ar['customer'][1]['allocation'][0][6];
                                $invoiceAllocation['transaction_approval'] = $ar['customer'][1]['allocation'][0][0]."-".$invoiceArr[$k]['header'][1];
                                $invoiceAllocation['platform_customer_id'] = $platform_customer_id;
                                $invoiceAllocation['currency_code'] = $ar['payment'][13];
                                $invoiceAllocation['linked_id'] = $linkedId;
                                if( $ar['customer'][1]['transaction'][0][3] ){
                                    $dateArr = explode( "/", $ar['customer'][1]['transaction'][0][3] );
                                    $invoiceAllocation['receipt_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                }

                                $invoiceAllocation['reference_no'] = is_numeric( $ar['payment'][12] ) ?  $ar['payment'][12] : null;
                                if( $ar['payment'][7] ){
                                    $dateArr = explode( "/", $ar['payment'][7] );
                                    $invoiceAllocation['payment_date'] = $dateArr[2]."-".$dateArr[1]."-".$dateArr[0];
                                }

                                $this->savePlatformInvoiceTransaction( $invoiceAllocation, $fileName );
                                $scenarioAccept = false;
                            }
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::error($user_integration_id."--getBlacklineInvoicePayments-->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * Upload Open Item/Order Invoice into blackline server
     */
    public function uploadBlacklineOpenInvoiceItems( $user_id, $user_integration_id, $destination_platform = '', $source_platform='blackline' ){

        date_default_timezone_set("US/Eastern");
        $return_response = true;

        //check daily 5 AM CT upload invoice open items as CSV file
        if( date('H') == 5 || ( isset( $_GET['isTest'] ) && $_GET['isTest'] == 1 ) )
        {
            try{
                $getFTPDetails = $this->mobj->getPlatformAccountByUserIntegration( $user_integration_id, $this->my_platform_id, ['api_domain', 'region', 'app_id', 'secret_key'] );
                $host = $getFTPDetails->api_domain;
                $port = $getFTPDetails->region;
                $user = $getFTPDetails->app_id;
                $password = $getFTPDetails->secret_key;
                $sftp = new SFTP($host, $port);

                if (!$sftp->login($user, $password)) {
                    $sftp->getSFTPLog();
                }else{

                    $fileLocation = $this->map->getMappedDataByName($user_integration_id, null, "send_invoice_file_location", ['custom_data']);

                    if( $fileLocation->custom_data )
                    {
                        if (!file_exists( $fileLocation->custom_data ) ) {
                            mkdir( $fileLocation->custom_data, 0777, true);
                        }

                        //URL base get customer records
                        $offset = 0;
                        $pagesize = 50;
                        $limit = [];

                        $limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->my_platform_id,
                            'url_name' => 'blackline_daily_invoice_csv_file_send'
                        ],
                        ['url', 'id']);

                        if ($limit) {
                            $offset = $limit->url;
                        }

                        $customerInvoices = array();
                        $col_val = [
                            "Division",//1
                            "LedgerReference",//2
                            "LedgerInstruction",//3
                            "TransactionReference",//4
                            "TransactionDate",//5
                            "AllocationStatus",//6
                            "AmountOutstanding",//7
                            "StatementTransactionType",//8
                            "CustomerName",//9
                            "InvoiceDueDate",//10
                            "DiscountedAmountAvailable",//11
                            "DocumentNumber",//12
                            "P.O.Number",//13
                            "INVDATA1",//14
                            "INVDATA2",//15
                        ];

                        $customerInvoices[] = $col_val;

                        //fetch Source platform id by name
                        $source_platform_id = $this->helper->getPlatformIdByName( $source_platform );

                        $invoiceArr = PlatformInvoice::with( 'platformCustomer', 'platformOrderAdditionalInformation' )
                            ->where([
                                'user_id' => $user_id,
                                'platform_id' => $source_platform_id,
                                // 'sync_status' => PlatformStatus::READY,
                            ])
                            ->whereIn( 'sync_status', [PlatformStatus::PENDING, PlatformStatus::READY ] )
                            ->where( 'due_amt', '!=', 0 )
                            ->offset($offset)
                            ->limit($pagesize)
                            ->get();

                        $path = "/esb_asset/intacct-customer-order-invoice/";
                        if( COUNT( $invoiceArr ) > 0 ){
                            foreach( $invoiceArr as $k=>$result ){
                                $colG = $result['net_total'];
                                $colK = ( $result['total_paid_amt'] > 0 ) ? $result['total_paid_amt'] : $result['due_amt'];
                                if( $result['invoice_payment_status'] == "Partially Paid" ){
                                    $colG = $colK = $result['due_amt'];
                                }

                                $state = explode( "-", $result['invoice_code'] );
                                if( $state[1] == "SO" || $state[1] == "CM" ){
                                    $colG = "-".$colG;
                                    $colK = "-".$colK;
                                }

                                $col_val = [
                                    "100USD",//1, A
                                    $result['api_customer_code'],//2, B
                                    1,//3, C
                                    $result['invoice_code'],//4, D
                                    $result['invoice_date'],//5, E
                                    "O",//6, F
                                    round( $colG, 2 ),//7, G
                                    "INV",//8, H
                                    $result['platformCustomer']['customer_name'] ?? '',//9, I
                                    $result['due_date'],//10, J
                                    round( $colK, 2 ),//11, K
                                    $result['invoice_code'],//12, L
                                    $result['ref_number'] ?? '',//13, M
                                    $result['order_doc_number'],//14, N
                                    $result['platformOrderAdditionalInformation']['store_number'] ?? '',//15, O
                                ];

                                $customerInvoices[] = $col_val;
                                $parent_invoice = $this->mobj->getFirstResultByConditions('platform_invoice', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_doc_number' => $result['order_doc_number'] ], ['id']);
                                $arr_invoice = array();
                                $arr_invoice['user_id'] = $user_id;
                                $arr_invoice['platform_id'] = $this->my_platform_id;
                                $arr_invoice['user_integration_id'] = $user_integration_id;
                                $arr_invoice['invoice_code'] = $result['invoice_code'];
                                $arr_invoice['net_total'] = $result['net_total'];
                                $arr_invoice['due_date'] = $result['due_date'];
                                $arr_invoice['ref_number'] = $result['ref_number'];
                                $arr_invoice['order_doc_number'] = $result['order_doc_number'];
                                $arr_invoice['customer_name'] = $result['customer_name'];
                                $arr_invoice['sync_status'] = 'Synced';
                                $arr_invoice['linked_id'] = $parent_invoice->id; //parent platform order row id
                                $arr_invoice['updated_at'] = date('Y-m-d H:i:s');

                                //insert blackline invoice record
                                $linked_platform_invoice_id = $this->mobj->makeInsertGetId('platform_invoice', $arr_invoice);

                                //update acknowledge
                                $update_arr = ['sync_status' => 'Synced', 'linked_id' => $linked_platform_invoice_id];

                                //update destination order record
                                $this->mobj->makeUpdate('platform_invoice', $update_arr, ['id' => $parent_invoice->id]);
                                // $this->mobj->makeUpdate('platform_invoice', $update_arr, ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $parent_invoice->id]);

                                //sync logger
                                $sync_error = null;
                                $this->log->syncLog($user_id, $user_integration_id, 0, $source_platform_id, $this->my_platform_id, 0, 'success', $parent_invoice->id, $sync_error);
                            }

                            $this->blacklineapi->createCSVFilesWithSpecificFolder( $customerInvoices, $user_integration_id, $path, 'open-items' );
                            $return_response = 'data Remaining';
                            if ($limit) {
                                $this->mobj->makeUpdate('platform_urls', ['url' => ( $offset + $pagesize )], ['id' => $limit->id]);
                            } else {
                                $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url' => ( $offset + $pagesize ), 'url_name' => 'customer_limit']);
                            }
                        } else {
                            $files = glob( public_path( $path.$user_integration_id."/*.csv" ) );
                            if( COUNT( $files ) >0 ){
                                foreach ( $files as $k=>$file) {
                                    $fileFolderArr = explode( "/", $file );
                                    $fileName = array_reverse( $fileFolderArr );
                                    $sftp->put( $fileLocation->custom_data."/".$fileName[0], $file, 1 );

                                    if( $this->isRemoveFileFromStorage ){
                                        unlink( $file );
                                    }
                                }
                                $return_response = true;
                            }
                        }
                    }
                }
            }  catch (\Exception $e) {
                Log::error($user_integration_id."--uploadBlacklineOpenInvoiceItems-->".$e->getMessage());
                $return_response = $e->getMessage();
            }
        }
        return $return_response;
    }

    /**
     * not sure
     */
    public function createBlacklineARPaymentSynchronize( $user_id, $user_integration_id, $destination_platform = '', $source_platform='' ){

        $response = false;
        try{
            $process_limit = 5;
            $offset = 0;

            /**
             * URL base get customer records
             */
            $platform_urls_id = null;
            $platform_urls = DB::table('platform_urls')
                ->where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->my_platform_id,
                    'url_name' => 'blackline_payment_sync_limit'])
                ->select('id','url')->first();

            if ($platform_urls) {
                $platform_urls_id = $platform_urls->id;
                $offset = $platform_urls->url;
                $url = $platform_urls->url + $process_limit;
                $this->mobj->makeUpdate('platform_urls', ['url' => $url], ['id' => $platform_urls->id]);
            } else {
                $url = $offset + $process_limit;
                $platform_urls_id = $this->mobj->makeInsertGetId( 'platform_urls',
                    ['user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->my_platform_id,
                    'url_name' => 'blackline_payment_sync_limit',
                    'url' => $url]
                );
            }

            //fetch Source platform id by name
            $source_platform_id = $this->helper->getPlatformIdByName( $source_platform );

            $resultArr = PlatformInvoice:://with('platformOrderTransaction')
                select('platform_invoice.id as id', 'platform_invoice.api_customer_code', 'platform_invoice.total_amt', 'platform_invoice.invoice_code', 'platform_invoice.due_date', 'platform_invoice.invoice_date', 'platform_invoice.gl_posting_date', 'platform_invoice.ship_date', 'platform_invoice.api_created_at',
                'platform_order_transactions.transaction_id', 'platform_order_transactions.id as platform_order_id')
                ->join("platform_order_transactions","platform_order_transactions.platform_order_id","=","platform_invoice.platform_order_id")
                ->where(
                    [
                    'platform_invoice.user_id' => $user_id,
                    'platform_invoice.platform_id' => $source_platform_id,
                    'platform_invoice.user_integration_id' => $user_integration_id,
                    'platform_invoice.sync_status' => PlatformStatus::READY
                    ] )
                ->offset($offset)->limit($process_limit)
                ->get();

            if( COUNT( $resultArr ) >0 ){

                //get customer intacct account id unique number
                $undepfundsacct = $this->map->getMappedDataByName($user_integration_id, null, "custom_payment_consent", ['custom_data']);

                foreach( $resultArr as $ar ){
                    $query = '<create_arpayment>
                        <customerid>C-'.$ar['api_customer_code'].'</customerid>
                        <paymentamount>'.$ar['total_amt'].'</paymentamount>
                        <undepfundsacct>'.$undepfundsacct->custom_data.'</undepfundsacct>
                    </create_arpayment>';

                    $responseCreateARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );

                    if( $responseCreateARPayment['api_status'] == "success" ){
                        $dateReceivedArr = explode( "-", $ar['ship_date'] );
                        $query = '<apply_arpayment>
                            <arpaymentkey>'.$responseCreateARPayment['operation']['result']['key'].'</arpaymentkey>
                            <paymentdate>
                                <year>'.$dateReceivedArr[0].'</year>
                                <month>'.$dateReceivedArr[1].'</month>
                                <day>'.$dateReceivedArr[2].'</day>
                            </paymentdate>
                            <memo>Apply AR Payment from API</memo>
                            <overpaylocid/>
                            <overpaydeptid/>
                            <arpaymentitems>
                                <arpaymentitem>
                                    <invoicekey>'.$ar['transaction_id'].'</invoicekey>
                                    <amount>'.$ar['total_amt'].'</amount>
                                </arpaymentitem>
                            </arpaymentitems>
                        </apply_arpayment>';

                        $responseARPayment = $this->intacctapi->CallAPI( $user_id, $user_integration_id, $query );
                        $updateSyncStatus = false;
                        if( $responseARPayment['api_status'] == "success" ){
                            $updateSyncStatus = true;
                        } else if( "There is no due on the invoice ".$ar['transaction_id'] == $responseARPayment['api_error'] ){
                            $updateSyncStatus = true;
                        } else {
                            $updateSyncStatus = false;
                        }

                        if( $updateSyncStatus ){
                            //update synchronize status ready to Synced
                            $platform_order_transactions['transaction_response_text'] = json_encode( $responseARPayment['operation'] );
                            $platform_order_transactions['transaction_response_code'] = $responseARPayment['operation']['result']['key'];
                            $platform_order_transactions['sync_status'] = "Synced";
                            PlatformOrderTransaction::where( 'id', $ar['platform_order_id'] )->update( $platform_order_transactions );
                            PlatformInvoice::where( 'id', $ar['id'] )->update(['sync_status' => 'Synced' ]);
                        }
                    } else {
                        $response =  $user_integration_id.": ".json_encode( $responseCreateARPayment );
                    }
                }
                $response = true;
            } else {
                $this->mobj->makeUpdate('platform_urls', ['url' => 0], ['id' => $platform_urls_id]);
            }
        } catch (\Exception $e) {
            Log::info($user_integration_id."--createBlacklineARPaymentSynchronize-->".$e->getMessage());
            $response = $e->getMessage();
        }
        return $response;
    }

    /**
     * Testing Function
     */
    public function readLocalTXTFile( $user_id, $user_integration_id ){
        $files = fopen(public_path("esb_asset/Scenario/s5.txt"), "r");

        $fileList = [];
        while(!feof($files)) {
            $fileList[] = fgets($files);
        }

        fclose($files);
        $fileKey = -1;
        $paymentKey = -1;
        $customerKey = -1;
        $invoiceArr = [];
        foreach ($fileList as $k=>$file) {
            $data = explode("|", $file); {
                //H: Header
                if( $data['0'] == "H" ){
                    $fileKey++;
                    $invoiceArr[$fileKey]['header'] = $data;
                }

                // P: Payments
                if( $data['0'] == "P" ){
                    $paymentKey++;
                    // $invoiceArr[$fileKey][$paymentKey]['payment'] = $data;
                    $invoiceArr[$fileKey]['payment'] = $data;
                }

                // C: Customers
                if( $data['0'] == "C" ){
                    $customerKey++;
                    // $invoiceArr[$fileKey][$paymentKey]['customer'][] = $data;
                    $invoiceArr[$fileKey]['customer'][] = $data;
                }

                //T: Transaction Level/Open Invoice
                if( $data['0'] == "T" ){
                    // $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['transaction'][] = $data;
                    $invoiceArr[$fileKey]['customer'][$customerKey]['transaction'][] = $data;
                }

                //A: Individual Allocation
                if( $data['0'] == "A" ){
                    // $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['allocation'][] = $data;
                    $invoiceArr[$fileKey]['customer'][$customerKey]['allocation'][] = $data;
                }

                //L: Holding/Suspend
                if( $data['0'] == "L" ){
                    // $invoiceArr[$fileKey][$paymentKey]['customer'][$customerKey]['holding'][] = $data;
                    $invoiceArr[$fileKey]['customer'][$customerKey]['holding'][] = $data;
                }
            }
        }

        foreach($invoiceArr as $k=>$ar){
            $sync_status = PlatformStatus::READY;

            //check txt file customer record exist
            if( isset( $ar['customer'] ) && COUNT( $ar['customer'] ) >0 ){

                foreach($ar['customer'] as $cr){

                    $platform_order_id = 0;///default customer platform order id

                    //generate customer resources
                    $customerArr = $this->blacklineapi->generateCustomerArr( $cr, $user_id, $user_integration_id );

                    //check current platform customer record exist in our database
                    $pc = PlatformCustomer::where( [
                            // 'api_customer_id' => $customerArr['api_customer_id'],
                            'api_customer_code' => $customerArr['api_customer_code'],
                            'platform_id' => $this->my_platform_id,//pass source platform id
                            'user_integration_id' => $user_integration_id
                        ] )->select('id')->first();

                    //customer exist then get customer primary id
                    if ( $pc ) {
                        $platform_customer_id = $pc->id;
                    } else {
                        $customerArr['sync_status'] = 'Pending';
                        $platform_customer_id = PlatformCustomer::insertGetId($customerArr);
                    }

                    // create order invoice with transaction
                    if( isset( $ar['customer'][$k]['transaction'] ) && COUNT( $ar['customer'][$k]['transaction'] ) >0 ){
                        foreach($ar['customer'][$k]['transaction'] as $trnx){

                            $dateArr = explode( "/", $ar['payment'][7] );
                            $dateArr = array_reverse($dateArr);
                            $pay_date = implode( "-", $dateArr );

                            $dateArr = explode( "/", $trnx[3] );
                            $dateArr = array_reverse($dateArr);
                            $invoice_date = implode( "-", $dateArr );

                            $arr_invoice = $this->blacklineapi->generateInvoiceArr( $ar, $trnx, $user_id, $user_integration_id );
                            $arr_invoice['invoice_date'] = $invoice_date;
                            $arr_invoice['pay_date'] = $pay_date;
                            $arr_invoice['platform_customer_id'] = $platform_customer_id;

                            $pi = PlatformInvoice::where( [
                                    'platform_id' => $this->my_platform_id,
                                    'user_integration_id' => $user_integration_id,
                                    'api_invoice_id' => $arr_invoice['api_invoice_id']
                                ] )->select('id')->first();

                            if ($pi) {
                                $platform_invoice_id = $pi->id;
                                $arr_invoice['platform_order_id'] = $pi->platform_order_id;
                                PlatformInvoice::where(['id' => $platform_invoice_id])->update($arr_invoice);
                            } else {
                                $arr_invoice['sync_status'] = 'Pending';
                                $arr_invoice['platform_order_id'] = $platform_order_id;
                                $platform_invoice_id = PlatformInvoice::insertGetId($arr_invoice);
                            }

                            //Log::info("Platforn Invoice ID: ".$platform_invoice_id);
                        }

                        //Check Scenario 1: Overpayment to be Written Off
                        if( isset( $ar['customer'][$k]['transaction'] ) && COUNT( $ar['customer'][$k]['transaction'] ) == 2 //check customer available in 2 transaction
                            && ( $ar['customer'][$k]['transaction'][0][7] != "" && is_numeric( $ar['customer'][$k]['transaction'][0][7] ) ) //check 1st transaction available reference number in +ve format
                            // && $ar['customer'][$k]['transaction'][1][7] == "INV" //check 2nd transaction place amount to INV
                            && !isset( $ar['customer'][$k]['allocation'] ) && COUNT( $ar['customer'][$k]['allocation'] ) == 0 //not use in any allocation payment
                        ){
                            //Log::info("Scenario 1");

                            $this->savePlatformInvoiceTransaction( $user_integration_id,//current integration
                                $platform_order_id,//order id
                                $ar['customer'][$k][10],//payer name
                                "OVERPAYMENT",//row type
                                "Debit Memo",//trnx type
                                $ar['customer'][$k]['transaction'][$k][1],//identifier/unique number
                                $ar['customer'][$k]['transaction'][$k][4],//credit/debit value
                                $ar['customer'][$k][2],//devision
                                $platform_customer_id,//customer id
                            );
                        }

                        //Check Scenario 3: One payment to one customer with several different deductions.
                        if( COUNT( $ar['customer'] ) == 1 //check only 1 customer available
                            && isset( $ar['customer'][$k]['transaction'] ) && COUNT( $ar['customer'][$k]['transaction'] ) == 1 //check customer only 1 transaction available
                            && isset( $ar['customer'][$k]['allocation'] ) && COUNT( $ar['customer'][$k]['allocation'] ) == 3 //check customer only 3 allocation available
                        ){
                            //Log::info("Scenario 3");
                            foreach( $ar['customer'][$k]['allocation'] as $al ){

                                $this->savePlatformInvoiceTransaction( $user_integration_id,//current integration
                                    $platform_order_id,//order id
                                    $al[12],//payment name
                                    "OVERPAYMENT",//row type
                                    'Credit Memo',//trnx type
                                    $al[0],//identifier/unique number
                                    $al[5],//credit/debit value
                                    $al[3],//devision
                                    $platform_customer_id,//customer id
                                );
                            }
                        }

                        //Check Scenario 4: Short Pay Write Off with Adjustment
                        if( isset( $ar['customer'][$k]['transaction'] ) && COUNT( $ar['customer'][$k]['transaction'] ) == 2 //check customer only 2 transaction available
                            && isset( $ar['customer'][$k]['allocation'] ) && COUNT( $ar['customer'][$k]['allocation'] ) == 1 // check customer only 1 allocation payment
                            && ( $ar['customer'][$k]['transaction'][0][7] != "" && is_numeric( $ar['customer'][$k]['transaction'][0][7] ) ) //check 1st transaction available reference number in +ve format
                            && $ar['customer'][$k]['transaction'][0][4] < 0 //check 1st transaction available amount in -ve format
                            && $ar['customer'][$k]['transaction'][1][7] == "INV" //check 2nd transaction place amount to INV
                        ){
                            //Log::info("Scenario 4");

                            $this->savePlatformInvoiceTransaction( $user_integration_id,//current integration
                                $platform_order_id,//order id
                                $ar['customer'][$k]['allocation'][12],//payment name
                                "ADJUSTMENT",//row type
                                "Credit Memo",//trnx type
                                $ar['customer'][$k]['allocation'][0],//identifier/unique number
                                $ar['customer'][$k]['allocation'][5],//credit/debit value
                                $ar['customer'][$k]['allocation'][3],//devision
                                $platform_customer_id,//customer id
                            );
                        }

                        //Check Scenario 5: Part Pay that Leaves a Remaining Balance
                        if( COUNT( $ar['customer'] ) == 1 // check only 1 customer available
                            && isset( $ar['customer'][$k]['transaction'] ) && COUNT( $ar['customer'][$k]['transaction'] ) == 1 //check customer only 1 transaction
                            && isset( $ar['customer'][$k]['allocation'] ) && COUNT( $ar['customer'][$k]['allocation'] ) == 1 //check customer only 1 allocation
                        ){
                            //Log::info("Scenario 5");
                            $this->savePlatformInvoiceTransaction( $user_integration_id,//current integration
                                $platform_order_id,//order id
                                $ar['customer'][$k]['allocation'][12],//payment name
                                "ADJUSTMENT",//row type
                                "Credit Memo",//trnx type
                                $ar['customer'][$k]['allocation'][0],//identifier/unique number
                                $ar['customer'][$k]['allocation'][5],//credit/debit value
                                $ar['customer'][$k]['allocation'][3],//devision
                                $platform_customer_id,//customer id
                            );
                        }
                    }

                    //Check Scenario 2: One payment to one customer that includes a deduction to a different customer.
                    if( COUNT( $ar['customer'] ) == 2 //check total 2 customer available
                        && !isset( $ar['customer'][0]['transaction'] ) && isset( $ar['customer'][0]['allocation'] ) //check only 2nd customer available in allocation
                        && isset( $ar['customer'][1]['transaction'] ) && COUNT( $ar['customer'][1]['transaction'] ) == 2 //check 2nd customer available 2 transaction
                        && isset( $ar['customer'][1]['allocation'] ) // check 2nd customer available 1 allocation
                    ){
                        //Log::info("Scenario 2");
                        $this->savePlatformInvoiceTransaction( $user_integration_id,//current integration
                                $platform_order_id,//order id
                                $ar['customer'][$k]['allocation'][0][12],//payment name
                                "OVERPAYMENT",//row type
                                "Credit Memo",//trnx type
                                $ar['customer'][$k]['allocation'][0][0],//identifier/unique number
                                $ar['customer'][$k]['allocation'][0][5],//credit/debit value
                                $ar['customer'][$k]['allocation'][0][3],//devision
                                $platform_customer_id,//customer id
                            );
                    }
                }
            }
        }
    }

    /**
     *
     */
    public function savePlatformInvoiceTransaction( $invoiceTransaction, $fileName ){
        $pot = PlatformInvoiceTransaction::where( [
            'platform_id' => $this->my_platform_id,
            'user_integration_id' => $invoiceTransaction['user_integration_id'],
            'row_type' => $invoiceTransaction['row_type'],
            'transaction_id' => $invoiceTransaction['transaction_id'],
            'platform_customer_id' => $invoiceTransaction['platform_customer_id'],
            'linked_id' => $invoiceTransaction['linked_id'],
            'transaction_method' => $invoiceTransaction['transaction_method'],
            'transaction_amount' => $invoiceTransaction['transaction_amount'],
        ] )->select('id')->first();

        if ( $pot) {
            // echo "<br>transactionUpdateCount: ".$fileName." - ".++$this->transactionUpdateCount;
            PlatformInvoiceTransaction::where('id', $pot->id)->update($invoiceTransaction);
            $id = $pot->id;
        } else {
            // echo "<br>transactionCount:".$fileName." - ".++$this->transactionCount;
            $invoiceTransaction['sync_status'] = 'Pending';
            $id = PlatformInvoiceTransaction::insertGetId($invoiceTransaction);
        }

        //Log::info("Invoice Transaction ID: ".$id);
        return $id;
    }

    /**
     *
     * @param [type] $string
     * @return void
     */
    public function saveInvoiceScenario( $invoice_code, $api_customer_code, $payment_scenario ){

        $invoiceArr = [];
        $invoiceArr['payment_scenario'] = $payment_scenario;
        return PlatformInvoice::where( ['invoice_code' => $invoice_code, 'api_customer_code' => $api_customer_code ] )->update($invoiceArr);
    }

    /**
     * replaces characters of string with other pre-defined characters.
     * remove interger ( +/- ve ) number
     */
    public function acceptOnlyString( $string ){
        $remove = [0,1,2,3,4,5,6,7,8,9,'.', '-'];
        return str_replace( $remove, '', $string);
    }
}
