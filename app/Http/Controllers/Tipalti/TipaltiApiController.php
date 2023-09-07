<?php

namespace App\Http\Controllers\Tipalti;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\Logger;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Tipalti\TipaltiUtility;
use Illuminate\Support\Facades\Session;
use phpseclib3\Net\SFTP;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformObjectData;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformAccount;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformInvoice;
use App\Models\PlatformOrderTransaction;
use App\Helper\Api\AwsS3Api;
use App\Models\PlatformUrl;
use App\Helper\WorkflowSnippet;
use Lang;

class TipaltiApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        $this->helper = new ConnectionHelper();
        $this->utitipalti = new TipaltiUtility();
        $this->awsapi = new AwsS3Api();
        $this->wfsnip = new WorkflowSnippet();
        $this->my_platform = 'tipalti';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    // Tipalti FTP Auth View Page
    public function InitiateTipaltiAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.tipalti_auth", compact('platform'));
    }

    // Tipalti FTP Auth
    public function ConnectTipaltiOauth(Request $request)
    {
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];
        $account_choice = trim($request->account_choice);
        $account_name = trim($request->account_name);

        if ($this->mobj->checkHtmlTags($request->all())) {
            Session::put('auth_msg', Lang::get('tags.validate'));
            return redirect()->back();
        }

        if ($account_choice = 'aws-s3') {
            $aws_data = array();
            $aws_data['user_id'] = trim($request->user_id);
            $aws_data['aws_region'] = trim($request->aws_region);
            $aws_data['aws_access_key'] = trim($request->aws_access_key);
            $aws_data['aws_secret_key'] = trim($request->aws_secret_key);
            $aws_data['private_key'] = trim($request->private_key);
            $aws_data['public_key'] = trim($request->public_key);
            $aws_data['pgp_password'] = trim($request->pgp_password);

            $aws_response = $this->awsapi->CheckAWSCredentials($aws_data);
            if ($aws_response == 'Success') {

                $pgp_response = app('App\Http\Controllers\Tipalti\PGPUtility')->CheckPGPCredentials($aws_data);
                if ($pgp_response == 'Success') {

                    $OauthData = [
                        'connection_type' =>  $account_choice,
                        'region' => $aws_data['aws_region'],
                        'access_key' => $this->mobj->encrypt_decrypt($aws_data['aws_access_key'], 'encrypt'),
                        'secret_key' => $this->mobj->encrypt_decrypt($aws_data['aws_secret_key'], 'encrypt'),
                        'app_secret' => $this->mobj->encrypt_decrypt($aws_data['pgp_password'], 'encrypt'),
                        'refresh_token' => $aws_data['private_key'],
                        'access_token' => $aws_data['public_key'],
                        'account_name' => $account_name,
                        'user_id' => $user_id,
                        'platform_id' => $this->my_platform_id,
                        'allow_refresh' => 0
                    ];

                    $ufound = PlatformAccount::where(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'account_name' => $account_name])->select(['id'])->first();
                    if ($ufound) {
                        PlatformAccount::where(['id' => $ufound->id])->update($OauthData);
                    } else {
                        PlatformAccount::insert($OauthData);
                    }

                    try {
                        $pvt_file_name = $user_id . "_private.key";
                        $myfile = fopen(public_path() . '/esb_asset/tipalti/' . $pvt_file_name, "w") or die("Unable to open file!");
                        fwrite($myfile, $aws_data['private_key']);
                        fclose($myfile);

                        $public_file_name = $user_id . "_public.key";
                        $myfile = fopen(public_path() . '/esb_asset/tipalti/' . $public_file_name, "w") or die("Unable to open file!");
                        fwrite($myfile, $aws_data['public_key']);
                        fclose($myfile);

                        shell_exec('gpg --allow-secret-key-import --import ./' . public_path() . '/esb_asset/tipalti/' . $pvt_file_name);
                    } catch (Exception $e) {
                        \Storage::disk('local')->append('tipalti.txt', 'PGP Secret Error user_id: ' . $user_id . ' time: ' . date('Y-m-d H:i:s') . ' msg: ' . $e->getMessage());
                    }
                } else {
                    Session::put('auth_msg', $pgp_response);
                }
            } else {
                Session::put('auth_msg', $aws_response);
            }
        } else {
            $post_data = array();
            $post_data['sftp_server'] = trim($request->host_name);
            $post_data['sftp_username'] = trim($request->user_name);
            $post_data['sftp_userpass'] = trim($request->password);

            //$result = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SFTPProxyAuth'), $post_data);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, \Config::get('apiconfig.SFTPProxyAuth'));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            $result = curl_exec($ch);
            curl_close($ch);

            if ($result == 'Success') {
                $OauthData = [
                    'api_domain' => $post_data['sftp_server'],
                    'app_id' => $this->mobj->encrypt_decrypt($post_data['sftp_username'], 'encrypt'),
                    'app_secret' => $this->mobj->encrypt_decrypt($post_data['sftp_userpass'], 'encrypt'),
                    'account_name' => $account_name,
                    'user_id' => $user_id,
                    'platform_id' => $this->my_platform_id,
                    'allow_refresh' => 0
                ];

                $ufound = PlatformAccount::where(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'account_name' => $account_name])->select(['id'])->first();

                if ($ufound) {
                    PlatformAccount::where(['id' => $ufound->id])->update($OauthData);
                } else {
                    PlatformAccount::insert($OauthData);
                }
            } else {
                Session::put('auth_msg', 'Authentication Error');
            }
        }

        echo '<script>window.close();</script>';
    }

    // Tipalti FTP Connection Check
    public function TipaltiEstablishConnection($user_id)
    {
        $acc_detail = PlatformAccount::where(['platform_id' => $this->my_platform_id, 'user_id' => $user_id])->select(['api_domain', 'app_id', 'app_secret'])->first();
        if ($acc_detail) {

            $ftp_server = $acc_detail->api_domain;
            $ftp_username = $this->mobj->encrypt_decrypt($acc_detail->app_id, 'decrypt');
            $ftp_userpass = $this->mobj->encrypt_decrypt($acc_detail->app_secret, 'decrypt');

            $sftp = new SFTP($ftp_server);

            if (!$sftp->login($ftp_username, $ftp_userpass)) {
                return false;
            } else {

                return $sftp;
            }
        } else {
            return false;
        }
    }

    // Upload CSV File To Tipalti FTP
    public function TipaltiUploadFileToFTPServer($sftp = null, $temp_file_name = null, $original_file_name = null, $data = [])
    {
        $TempFile = public_path() . '/' . $temp_file_name;
        $file = fopen($TempFile, "w");

        $OFileName = "/" . $original_file_name;
        $OriginalFile = $OFileName;

        foreach ($data as $line) {
            fputcsv($file, $line);
        }

        fclose($file);

        $sftp->chdir('/home/asbdcapiworx/TEST_DATA_IN');
        //$sftp->chdir('/TEST_DATA_IN');
        $result = $sftp->put($original_file_name, $TempFile, SFTP::SOURCE_LOCAL_FILE);
        if ($result) {
            $response = 'Success';
        } else {
            $response = "There was a problem while uploading.";
        }

        /*
        if (@ftp_put($ftp_conn, $OriginalFile, $TempFile, FTP_ASCII)) {
            $response = 'Success';
        } else {
            $response = "There was a problem while uploading.";
        }

        //ftp_close($ftp_conn);
        */
        if (file_exists($TempFile)) {
            unlink($TempFile);
        }

        return $response;
    }

    // Upload CSV File To Tipalti FTP Using Proxy Server
    public function TipaltiUploadFileToSFTPServerUsingProxyServer($acc_detail, $user_id, $user_integration_id, $temp_file_name = null, $original_file_name = null, $data = [], $access_folder)
    {
        try {
            if ($acc_detail) {
                $sftp_server = $acc_detail->api_domain;
                $sftp_username = $this->mobj->encrypt_decrypt($acc_detail->app_id, 'decrypt');
                $sftp_userpass = $this->mobj->encrypt_decrypt($acc_detail->app_secret, 'decrypt');
                $sftp_upload_folder = $access_folder;

                $file_path = public_path() . '/esb_asset/tipalti/' . $user_integration_id;
                if (!file_exists($file_path)) {
                    mkdir($file_path, 0777, true);
                }

                $file_as_temp = $file_path . '/' . $original_file_name;

                $TempFile = $file_as_temp;
                $file = fopen($TempFile, "w");

                foreach ($data as $line) {
                    fputcsv($file, $line);
                }

                fclose($file);

                $file_url = \URL::to('/') . '/public/esb_asset/tipalti/' . $user_integration_id . '/' . $original_file_name;

                $post_data = array();
                $post_data['sftp_server'] = $sftp_server;
                $post_data['sftp_username'] = $sftp_username;
                $post_data['sftp_userpass'] = $sftp_userpass;
                $post_data['sftp_upload_folder'] = $sftp_upload_folder;
                $post_data['user_integration_id'] = $user_integration_id;
                $post_data['file_name'] = $original_file_name;
                $post_data['file_url'] = $file_url;
                $post_data['original_file_name'] = $original_file_name;
                //print_r($post_data);

                //$result = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SFTPProxyDataSend'), $post_data);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, \Config::get('apiconfig.SFTPProxyDataSend'));
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                $result = curl_exec($ch);
                curl_close($ch);

                $response = $result;

                if (file_exists($TempFile)) {
                    //unlink($TempFile);
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--TipaltiUploadFileToSFTPServerUsingProxyServer-->" . $e->getMessage());
            $response = $e->getMessage();
        }

        return $response;
    }

    // Event Execution For Tipalti
    public function ExecuteEventTipalti($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        $response = true;
        ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.

        if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $response = $this->TipaltiCreateUpdatePO($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'PO', 'Ready', $record_id);
        } else if ($method == 'MUTATE' && $event == 'GRN') {
            $response = $this->TipaltiCreateUpdateGRN($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'Ready', $record_id);
        } else if ($method == 'GET' && $event == 'PURCHASEORDERINVOICE') {
            $response = $this->TipaltiGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'PO');
        } else if ($method == 'GET' && $event == 'PAYMENT') {
            $response = $this->TipaltiGetPayment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'PO');
        }

        return $response;
    }

    // Creating Purchase order to Tipalti FTP
    public function TipaltiCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {
            $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['id', 'user_id', 'platform_id', 'account_name', 'api_domain', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'connection_type', 'access_key', 'secret_key', 'region']);
            if ($acc_detail) {
                $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                $sync_object_id = $this->helper->getObjectId('purchase_order');
                $CustomDataPOFolder = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "po_access_folder", ['custom_data'], "default");
                $sftp_folder = '';
                if ($CustomDataPOFolder) {
                    $sftp_folder = @$CustomDataPOFolder->custom_data;
                }

                $CustomDataDefaultSupplier = $this->map->getMappedDataByName($user_integration_id, null, "customer", ['custom_data'], "default");
                $default_supplier_name = '';
                if ($CustomDataDefaultSupplier) {
                    $default_supplier_name = @$CustomDataDefaultSupplier->custom_data;
                }

                $CloseOrderStatus = $this->map->getMappedDataByName($user_integration_id, null, "get_order_status", ['name'], "regular", null, "multiple", "source", ['api_id', 'name']);

                $process_limit = 100;
                $offset = 0;

                $tax_object_id = $this->helper->getObjectId('taxcode');
                $shipping_object_id = $this->helper->getObjectId('shipping_method');

                //do{

                $allow_next_call = false; // This flag will help for pagination

                if ($record_id != '') {

                    $result_order = PlatformOrder::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type, 'id' => $record_id])->select(['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'total_amount', 'total_tax', 'total_discount', 'net_amount', 'shipping_method', 'platform_customer_id', 'order_status'])->orderBy('id', 'asc')->skip($offset)->take($process_limit)->get();
                } else {

                    $result_order = PlatformOrder::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type, 'sync_status' => $sync_status])->select(['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'total_amount', 'total_tax', 'total_discount', 'net_amount', 'shipping_method', 'platform_customer_id', 'order_status'])->orderBy('id', 'asc')->skip($offset)->take($process_limit)->get();
                }

                if (count($result_order) == $process_limit) {
                    $allow_next_call = true; // Make it false as well if we want to avoid continuous loop
                    $offset += $process_limit;
                }

                $ct_index = 1;
                $platform_order_ids = array();
                $po_data = array();
                if (count($result_order) > 0) {
                    foreach ($result_order as $roworder) {
                        $id = $roworder->id;
                        $api_order_id = $roworder->api_order_id;
                        $order_number = $roworder->order_number;
                        $currency = $roworder->currency;
                        $order_date = $roworder->order_date;
                        $customer_email = $roworder->customer_email;
                        $net_amount = $roworder->net_amount;
                        $total_amount = $roworder->total_amount;
                        $total_tax = $roworder->total_tax;
                        $total_discount = $roworder->total_discount;
                        $order_status = $roworder->order_status;
                        $shipping_method = $roworder->shipping_method;

                        $customer_name = '';
                        $customer_id = '';
                        if ($roworder->platform_customer_id != '') {
                            $result_customer = PlatformCustomer::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $roworder->platform_customer_id])->select(['customer_name', 'api_customer_id', 'company_name', 'api_customer_code'])->first();

                            //$customer_name = @$result_customer->customer_name;
                            //if(trim($customer_name)==''){
                            $customer_name = @$result_customer->company_name;
                            //}

                            $customer_id = @$result_customer->api_customer_id;
                            $customer_code = @$result_customer->api_customer_code;
                        }

                        $shipping_method_name = '';
                        if ($shipping_object_id != '' && $roworder->shipping_method != '0' && $roworder->shipping_method != '') {
                            $object_data = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $shipping_object_id, 'api_id' => $roworder->shipping_method])->select(['name'])->first();
                            $shipping_method_name = @$object_data->name;
                        }

                        $is_closed = 'NO';
                        if (in_array($order_status, $CloseOrderStatus)) {
                            $is_closed = 'YES';
                        }

                        $result_order_line = PlatformOrderLine::where(['platform_order_id' => $id])->select(['product_name', 'sku', 'qty', 'unit_price', 'price', 'total', 'taxes', 'subtotal', 'total_tax', 'api_code', 'description'])->get();
                        if (count($result_order_line) > 0) {
                            $platform_order_ids[] = $roworder->id;
                            $line_id = 1;
                            foreach ($result_order_line as $rowline) {
                                $tax_code = '';
                                if ($tax_object_id != '' && $rowline->taxes != '0' && $rowline->taxes != '') {

                                    $object_data = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $tax_object_id, 'api_id' => $rowline->taxes])->select(['api_code'])->first();

                                    $tax_code = @$object_data->api_code;
                                }

                                $li_total = @$rowline->total ? $rowline->total : 0;
                                $li_total_tax = @$rowline->total_tax ? $rowline->total_tax : 0;

                                $li_line_total = floatval($li_total) + floatval($li_total_tax);

                                $order_date_new = "";
                                if ($order_date != '') {
                                    $arrd = explode('T', $order_date);
                                    $o_date = \DateTime::createFromFormat('Y-m-d', $arrd[0]);
                                    $order_date_new = $o_date->format('m/d/Y');
                                }

                                $po_data[$ct_index]['supplier_name'] = $default_supplier_name;
                                $po_data[$ct_index]['supplier_code'] = $customer_code;
                                $po_data[$ct_index]['po_number'] = $api_order_id;
                                $po_data[$ct_index]['order_date'] = $order_date_new;
                                $po_data[$ct_index]['currency'] = $currency;
                                $po_data[$ct_index]['net_total'] = $net_amount;
                                $po_data[$ct_index]['tax_total'] = $total_tax;
                                $po_data[$ct_index]['discount'] = $total_discount;
                                $po_data[$ct_index]['order_total'] = $total_amount;
                                $po_data[$ct_index]['is_closed'] = $is_closed;

                                $po_data[$ct_index]['li_id'] = $line_id;
                                $po_data[$ct_index]['li_productcode'] = $rowline->sku;
                                $po_data[$ct_index]['li_description'] = str_replace(',', '', $rowline->product_name); //$rowline->description;
                                $po_data[$ct_index]['li_gl_code'] = $rowline->api_code;
                                $po_data[$ct_index]['li_quantity'] = $rowline->qty;
                                $po_data[$ct_index]['li_unit_price'] = $rowline->unit_price;
                                $po_data[$ct_index]['li_price'] = $rowline->price;
                                $po_data[$ct_index]['li_net'] = $li_total;
                                $po_data[$ct_index]['li_vat_code'] = $tax_code;
                                $po_data[$ct_index]['li_tax'] = $li_total_tax;
                                $po_data[$ct_index]['li_line_total'] = $li_line_total;

                                $line_id++;
                                $ct_index++;
                            }
                        } else {
                            if ($source_platform == 'brightpearl') {
                                $ct_url = PlatformUrl::where(['status' => 1, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'url_name' => 'purchase_orders', 'response' => 'reattempt', 'url' => '/order/' . $api_order_id])->count();
                                if ($ct_url < 4) {
                                    PlatformUrl::insert(['url' => '/order/' . $api_order_id, 'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'url_name' => 'purchase_orders', 'response' => 'reattempt']);

                                    PlatformOrder::where(['id' => $id])->update(['sync_status' => 'Pending']);
                                } else {
                                    PlatformOrder::where(['id' => $id])->update(['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')]);

                                    $return_response = $sync_error = "Line items Missing.";
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                                }
                            } else {
                                PlatformOrder::where(['id' => $id])->update(['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')]);

                                $return_response = $sync_error = "Line items Missing.";
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                            }
                        }
                    }


                    if ($ct_index > 1) {
                        //Get Formatted CSV File
                        $data = $this->utitipalti->GetStructuredPOPostData($po_data);

                        $temp_file_name = "tipalti_po.csv";
                        $original_file_name = "PurchaseOrder_" . date('Ymd_Hmsv') . ".csv";
                        if ($acc_detail->connection_type == 'sftp') {
                            $sftp_folder = @$sftp_folder ? $sftp_folder : '/';

                            $response = $this->TipaltiUploadFileToSFTPServerUsingProxyServer($acc_detail, $user_id, $user_integration_id, $temp_file_name, $original_file_name, $data, $sftp_folder);
                        } else if ($acc_detail->connection_type == 'aws-s3') {
                            $CustomDataBucket = $this->map->getMappedDataByName($user_integration_id, null, "aws_bucket", ['custom_data'], "default");
                            $bucket = '';
                            if ($CustomDataBucket) {
                                $bucket = @$CustomDataBucket->custom_data;
                            }

                            $response = $this->AWSImportFileWithEncryption($acc_detail, ['user_integration_id' => $user_integration_id, 'access_folder' => $sftp_folder, 'original_file_name' => $original_file_name, 'bucket' => $bucket], $data);
                        }

                        //\Storage::disk('local')->append('tipalti.txt.txt','6  response: '.$response.' time: '.date('Y-m-d H:i:s'));

                        // Maintain Logs
                        if ($response == 'Success') {
                            foreach ($platform_order_ids as $id) {
                                PlatformOrder::where(['id' => $id])->update(['sync_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s'), 'file_name' => $original_file_name]);

                                $sync_error = null;
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $id, $sync_error);
                            }
                        } else {
                            $return_response = $sync_error = $response;
                            foreach ($platform_order_ids as $id) {
                                PlatformOrder::where(['id' => $id])->update(['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')]);

                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                            }
                        }
                    }
                }

                // }while($allow_next_call);

            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--TipaltiCreateUpdatePO-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    // Creating GRN to Tipalti FTP
    public function TipaltiCreateUpdateGRN($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {
            $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['id', 'user_id', 'platform_id', 'account_name', 'api_domain', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'connection_type', 'access_key', 'secret_key', 'region']);
            if ($acc_detail) {
                $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
                $sync_object_id = $this->helper->getObjectId('accept_order');

                $CustomDataGRNFolder = $this->map->getMappedDataByName($user_integration_id, null, "grn_access_folder", ['custom_data'], "default");
                $sftp_folder = '';
                if ($CustomDataGRNFolder) {
                    $sftp_folder = @$CustomDataGRNFolder->custom_data;
                }

                $CustomDataDefaultSupplier = $this->map->getMappedDataByName($user_integration_id, null, "customer", ['custom_data'], "default");
                $default_supplier_name = '';
                if ($CustomDataDefaultSupplier) {
                    $default_supplier_name = @$CustomDataDefaultSupplier->custom_data;
                }

                $process_limit = 100;
                $offset = 0;

                //do{

                $allow_next_call = false; // This flag will help for pagination
                if ($record_id != '') {
                    $result_goods = DB::table('platform_order_shipments as pos')
                        ->join('platform_order as po', 'pos.platform_order_id', '=', 'po.id')
                        ->where(['pos.user_id' => $user_id, 'pos.user_integration_id' => $user_integration_id, 'pos.platform_id' => $source_platform_id, 'po.id' => $record_id, 'pos.type' => 'POShipment'])->whereIn('pos.sync_status', ['Ready', 'Failed'])->select('pos.id', 'pos.platform_order_id', 'pos.order_id', 'pos.shipment_id', 'pos.created_on', 'po.order_date', 'po.customer_email', 'po.order_number', 'po.platform_customer_id')->orderBy('pos.id', 'asc')->skip($offset)->take($process_limit)->get();
                } else {
                    $result_goods = DB::table('platform_order_shipments as pos')
                        ->join('platform_order as po', 'pos.platform_order_id', '=', 'po.id')
                        ->where(['pos.user_id' => $user_id, 'pos.user_integration_id' => $user_integration_id, 'pos.platform_id' => $source_platform_id, 'po.shipment_status' => $sync_status, 'pos.type' => 'POShipment'])->whereIn('pos.sync_status', ['Ready', 'Failed'])->select('pos.id', 'pos.platform_order_id', 'pos.order_id', 'pos.shipment_id', 'pos.created_on', 'po.order_date', 'po.customer_email', 'po.order_number', 'po.platform_customer_id')->orderBy('pos.id', 'asc')->skip($offset)->take($process_limit)->get(); //,'pos.sync_status'=>$sync_status
                }

                if (count($result_goods) == $process_limit) {
                    $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                    $offset += $process_limit;
                }

                $ct_index = 1;
                $platform_goods_ids = array();
                $platform_order_ids = array();
                $goods_data = array();
                $failed_order_logs = array();
                if (count($result_goods) > 0) {
                    foreach ($result_goods as $rowgoods) {
                        $id = $rowgoods->id;
                        $platform_order_id = $rowgoods->platform_order_id;
                        $order_id = $rowgoods->order_id;
                        $shipment_id = $rowgoods->shipment_id;
                        $created_on = $rowgoods->created_on;
                        $order_date = $rowgoods->order_date;
                        $order_number = $rowgoods->order_number;
                        $order_id = $rowgoods->order_id;

                        $customer_name = '';
                        $customer_id = '';
                        if ($rowgoods->platform_customer_id != '') {
                            $result_customer = PlatformCustomer::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $rowgoods->platform_customer_id])->select(['customer_name', 'api_customer_id', 'company_name', 'api_customer_code'])->first();

                            //$customer_name = @$result_customer->customer_name;
                            //if(trim($customer_name)==''){
                            $customer_name = @$result_customer->company_name;
                            // }
                            $customer_code = @$result_customer->api_customer_code;
                            $customer_id = @$result_customer->api_customer_id;
                        }

                        $result_goods_line = DB::table('platform_order_shipment_lines as posl')
                            ->leftJoin('platform_order_line as pol', function ($join) use ($platform_order_id) {
                                $join->on('pol.api_product_id', '=', 'posl.product_id');
                                $join->where('pol.platform_order_id', '=', $platform_order_id);
                            })->where(['platform_order_shipment_id' => $id])->select('posl.id', 'posl.row_id', 'posl.product_id', 'posl.location_id', 'posl.currency', 'posl.price', 'posl.quantity', 'pol.sku', 'pol.product_name', 'pol.sku', 'pol.qty as ol_qty', 'pol.total_tax as ol_total_tax', 'pol.api_code', 'pol.description')->get();

                        if (count($result_goods_line) > 0) {
                            $platform_goods_ids[] = $rowgoods->id;
                            $platform_order_ids[$rowgoods->id] = $rowgoods->platform_order_id;

                            $line_id = 1;

                            $description = [];
                            foreach ($result_goods_line as $rowline) {
                                $description[] = str_replace(',', '', $rowline->product_name); //$rowline->description;
                            }
                            foreach ($result_goods_line as $rowline) {
                                $quantity = @$rowline->quantity ? $rowline->quantity : 0;
                                $price = @$rowline->price ? $rowline->price : 0;
                                $ol_total_tax = @$rowline->ol_total_tax ? $rowline->ol_total_tax : 0;
                                $ol_qty = @$rowline->ol_qty ? $rowline->ol_qty : 0;

                                $line_net_total = floatval($quantity) * floatval($price);
                                $line_total_tax = 0;
                                if ($ol_qty > 0) {
                                    $line_total_tax = floatval($quantity) * (floatval($ol_total_tax) / floatval($ol_qty));
                                }
                                $order_date_new = $created_on_new = "";
                                if ($order_date != '') {
                                    $arrd = explode('T', $order_date);
                                    $o_date = \DateTime::createFromFormat('Y-m-d', $arrd[0]);
                                    $order_date_new = $o_date->format('m/d/Y');
                                }

                                if ($created_on != '') {
                                    $arrco = explode('T', $created_on);
                                    $co_date = \DateTime::createFromFormat('Y-m-d', $arrco[0]);
                                    $created_on_new = $co_date->format('m/d/Y');
                                }

                                $goods_data[$ct_index]['receipt_number'] = "GRN" . $shipment_id;
                                $goods_data[$ct_index]['supplier_name'] = $default_supplier_name;
                                $goods_data[$ct_index]['supplier_code'] = $customer_code;
                                $goods_data[$ct_index]['po_number'] = $order_id;
                                $goods_data[$ct_index]['order_date'] = $order_date_new;
                                $goods_data[$ct_index]['delivery_date'] = $created_on_new;

                                $goods_data[$ct_index]['li_id'] = $line_id;
                                $goods_data[$ct_index]['li_productcode'] = $rowline->sku;
                                $goods_data[$ct_index]['li_description'] = implode('; ', $description); //$rowline->product_name;//$rowline->description;
                                $goods_data[$ct_index]['li_gl_code'] = $rowline->api_code;
                                $goods_data[$ct_index]['li_quantity'] = $quantity;
                                $goods_data[$ct_index]['li_unit_price'] = $price;
                                $goods_data[$ct_index]['li_net'] = $line_net_total;
                                $goods_data[$ct_index]['li_tax'] = $line_total_tax;
                                $goods_data[$ct_index]['li_line_total'] = $line_net_total + $line_total_tax;

                                $line_id++;
                                $ct_index++;
                            }
                        } else {
                            $return_response = $sync_error = "Line items Missing.";
                            PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);

                            PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Failed']);

                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $platform_order_id, $sync_error);

                            $failed_order_logs[$platform_order_id] = $sync_error;
                        }
                    }

                    if ($ct_index > 1) {
                        //Get Formatted CSV File
                        $data = $this->utitipalti->GetStructuredGRNPostData($goods_data);

                        $temp_file_name = "tipalti_grn.csv";
                        $original_file_name = "GRN_" . date('Ymd_Hmsv') . ".csv";

                        if ($acc_detail->connection_type == 'sftp') {
                            $sftp_folder = @$sftp_folder ? $sftp_folder : '/';

                            $response = $this->TipaltiUploadFileToSFTPServerUsingProxyServer($acc_detail, $user_id, $user_integration_id, $temp_file_name, $original_file_name, $data, $sftp_folder);
                        } else if ($acc_detail->connection_type == 'aws-s3') {
                            $CustomDataBucket = $this->map->getMappedDataByName($user_integration_id, null, "aws_bucket", ['custom_data'], "default");
                            $bucket = '';
                            if ($CustomDataBucket) {
                                $bucket = @$CustomDataBucket->custom_data;
                            }

                            $response = $this->AWSImportFileWithEncryption($acc_detail, ['user_integration_id' => $user_integration_id, 'access_folder' => $sftp_folder, 'original_file_name' => $original_file_name, 'bucket' => $bucket], $data);
                        }

                        // Maintain Logs
                        if ($response == 'Success') {
                            foreach ($platform_goods_ids as $id) {
                                PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Synced', 'shipment_file_name' => $original_file_name]);

                                PlatformOrder::where(['id' => $platform_order_ids[$id]])->update(['shipment_status' => 'Synced']);

                                $sync_error = null;
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $platform_order_ids[$id], $sync_error);
                            }
                        } else {
                            $return_response = $sync_error = $response;
                            foreach ($platform_goods_ids as $id) {
                                $failed_order_logs[$platform_order_id] = $sync_error;

                                PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);

                                PlatformOrder::where(['id' => $platform_order_ids[$id]])->update(['shipment_status' => 'Failed']);

                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $platform_order_ids[$id], $sync_error);
                            }
                        }
                    }
                }

                foreach ($failed_order_logs as $pid => $error) {
                    PlatformOrder::where(['id' => $pid])->update(['shipment_status' => 'Failed']);

                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $pid, $error);
                }

                //}while($allow_next_call);
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--TipaltiCreateUpdateGRN-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    public function TipaltiGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'user_id', 'platform_id', 'account_name', 'api_domain', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'connection_type', 'access_key', 'secret_key', 'region']);
            if ($account) {
                $redirect_url = \URL::to('/') . '/' . \Config::get('apiconfig.TipaltiInvoiceRedirectURL');

                $CustomDataInvoiceFolder = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "invoice_access_folder", ['custom_data'], "default");
                $sftp_folder = '';
                if ($CustomDataInvoiceFolder) {
                    $sftp_folder = @$CustomDataInvoiceFolder->custom_data;
                }

                $CustomDataArchivedFolder = $this->map->getMappedDataByName($user_integration_id, null, "archived_access_folder", ['custom_data'], "default");
                $sftp_archived_folder = '';
                if ($CustomDataArchivedFolder) {
                    $sftp_archived_folder = @$CustomDataArchivedFolder->custom_data;
                }

                if ($account->connection_type == 'sftp') {
                    $sftp_folder = @$sftp_folder ? $sftp_folder : '/';
                    $sftp_archived_folder = @$sftp_archived_folder ? $sftp_archived_folder : '/';

                    $this->GetSFTPData($account, $user_id, $user_integration_id, $order_type, $redirect_url, $sftp_folder, $sftp_archived_folder);
                } else if ($account->connection_type == 'aws-s3') {
                    $CustomDataBucket = $this->map->getMappedDataByName($user_integration_id, null, "aws_bucket", ['custom_data'], "default");
                    $bucket = '';
                    if ($CustomDataBucket) {
                        $bucket = @$CustomDataBucket->custom_data;
                    }

                    $this->GetAWSData($account, ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'order_type' => $order_type, 'module' => 'invoice', 'bucket' => $bucket, 'access_folder' => $sftp_folder, 'archived_access_folder' => $sftp_archived_folder, 'server_access_folder' => public_path() . '/esb_asset/tipalti/' . $user_integration_id . '/', 'user_workflow_rule_id' => $user_workflow_rule_id]);
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--TipaltiGetInvoice-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }


    public function AWSImportFileWithEncryption($acc_detail, $info = [], $data_to_sync)
    {
        $encrypted_file_name = public_path() . '/esb_asset/tipalti/' . $info['user_integration_id'] . '/' . $info['original_file_name'];

        $file_path = public_path() . '/esb_asset/tipalti/' . $info['user_integration_id'];
        if (!file_exists($file_path)) {
            mkdir($file_path, 0777, true);
        }

        $csv_data = "";
        foreach ($data_to_sync as $line) {
            $csv_data .= implode(",", $line) . PHP_EOL;
        }

        $encryption_status = app('App\Http\Controllers\Tipalti\PGPUtility')->EncryptDataPGP($acc_detail, ['encrypted_file_name' => $encrypted_file_name], $csv_data);

        if ($encryption_status == 'Success') {
            $response = $this->awsapi->AWSUploadFile($acc_detail, ['bucket' => $info['bucket'], 'file_name' => $info['original_file_name'], 'path_to_file' => $encrypted_file_name, 'access_folder' => $info['access_folder']]);

            if (file_exists($encrypted_file_name)) {
                //unlink($encrypted_file_name);
            }
        } else {
            $response = $encryption_status;
        }

        return $response;
    }

    public function GetAWSData($account, $info = [])
    {
        $list_object = $this->awsapi->AWSGetListObject($account, ['bucket' => $info['bucket'], 'access_folder' => $info['access_folder']]);

        //$list_object -> you can not check isset or isarray or somthing because you can read data only through  foreach
        foreach ($list_object as $object) {
            if (isset($object['Key'])) {
                $extension = substr($object['Key'], -3);
                if ($extension == 'csv' || $extension == 'CSV') {
                    //replacing due to file name having folder prefix on it.
                    if (substr($info['access_folder'], -1) == '/') {
                        $aws_file_name = str_replace(" ", "_", str_replace($info['access_folder'], '', $object['Key']));
                    } else {
                        $aws_file_name = str_replace(" ", "_", str_replace($info['access_folder'] . '/', '', $object['Key']));
                    }

                    if ($aws_file_name != '') {
                        // echo $aws_file_name;
                        $file_path = public_path() . '/esb_asset/tipalti/' . $info['user_integration_id'];
                        if (!file_exists($file_path)) {
                            mkdir($file_path, 0777, true);
                        }

                        // get file detail from AWS & store to our server
                        $object_details = $this->awsapi->AWSGetObject($account, ['bucket' => $info['bucket'], 'object_key' => $object['Key'], 'file_name' => $aws_file_name, 'server_access_folder' => $info['server_access_folder']]);

                        $file_to_decrypt = public_path() . '/esb_asset/tipalti/' . $info['user_integration_id'] . '/' . $aws_file_name;

                        //renaming file name because we try to overwrite into same file but it overwriting blank data on it
                        $rename_decrypt_file_name = time() . '_' . $aws_file_name;
                        $decrypt_file_name = public_path() . '/esb_asset/tipalti/' . $info['user_integration_id'] . '/' . $rename_decrypt_file_name;

                        // file data save to FTP server
                        //file_put_contents($decrypt_file_name, $object_details);

                        //Decrypt data from file to file
                        $decryption_status = app('App\Http\Controllers\Tipalti\PGPUtility')->DecryptDataPGP($account, ['file_to_decrypt' => $file_to_decrypt, 'decrypted_file_name' => $decrypt_file_name]);

                        if ($decryption_status == 'Success') {

                            $file_url = \URL::to('/') . '/public/esb_asset/tipalti/' . $info['user_integration_id'] . '/' . $rename_decrypt_file_name;

                            $myRequest = new \Illuminate\Http\Request();
                            $myRequest->setMethod('POST'); //default METHOD
                            $myRequest->request->add(['user_id' => $info['user_id'], 'user_integration_id' => $info['user_integration_id'], 'order_type' => $info['order_type'], 'file_url' => $file_url, 'user_workflow_rule_id' => $info['user_workflow_rule_id']]);

                            // Calling existing functions which used on SFTP
                            if ($info['module'] == 'invoice') {
                                $this->TipaltiProxyGetPOInvoiceFiles($myRequest);
                            } else if ($info['module'] == 'payment') {
                                $this->TipaltiProxyGetPOPaymentFiles($myRequest);
                            }

                            //Removing files from our server folder
                            if (file_exists($file_to_decrypt)) {
                                //unlink($file_to_decrypt);
                            }
                            if (file_exists($decrypt_file_name)) {
                                //unlink($decrypt_file_name);
                            }


                            //Moving Files After Use
                            //copy files to archive folder
                            $this->awsapi->AWSCopyObject($account, ['bucket' => $info['bucket'], 'file_name' => $aws_file_name, 'object_key' => $object['Key'], 'archived_access_folder' => $info['archived_access_folder']]);
                            //delete files
                            $this->awsapi->AWSDeleteObject($account, ['bucket' => $info['bucket'], 'object_key' => $object['Key']]);
                        } else {
                            //error
                        }
                    }
                }
            } else {
                //error
            }
        }
    }


    public function GetSFTPData($account, $user_id, $user_integration_id, $order_type = 'PO', $redirect_url = null, $sftp_folder = null, $sftp_archived_folder = null)
    {

        // $acc_detail = PlatformAccount::where(['platform_id' => $this->my_platform_id,'user_id' => $user_id])->select(['api_domain','app_id','app_secret','region'])->first();

        if ($account) {

            $sftp_server = $account->api_domain;
            $sftp_username = $this->mobj->encrypt_decrypt($account->app_id, 'decrypt');
            $sftp_userpass = $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt');


            $post_data = array();
            $post_data['sftp_server'] = $sftp_server;
            $post_data['sftp_username'] = $sftp_username;
            $post_data['sftp_userpass'] = $sftp_userpass;
            $post_data['sftp_folder'] = $sftp_folder;
            $post_data['sftp_archived_folder'] = $sftp_archived_folder;
            $post_data['user_id'] = $user_id;
            $post_data['user_integration_id'] = $user_integration_id;
            $post_data['order_type'] = $order_type;
            $post_data['redirect_url'] = $redirect_url;
            $post_data['target_url'] = \Config::get('apiconfig.SFTPProxyDataGet');
            $post_data['is_sftp_file_move_to_archive'] = true;


            //$response = $this->mobj->makeCurlRequest('POST', \Config::get('apiconfig.SFTPProxyDataGet'), $post_data);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, \Config::get('apiconfig.SFTPProxyDataGet'));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            $result = curl_exec($ch);
            curl_close($ch);

            $response = $result;

            //echo "<pre>";
            //print_r($response);
        }
    }

    public function TipaltiProxyGetPOInvoiceFiles(Request $request)
    {
        $return_response = true;
        try {
            $user_id = $request->user_id;
            $user_integration_id = $request->user_integration_id;
            $order_type = $request->order_type;
            $file_url = $request->file_url;
            $user_workflow_rule_id = $request->user_workflow_rule_id;

            /*
            // store file in to a folder
            $file_path = public_path().'/esb_asset/tipalti/'.$user_integration_id;
            if (!file_exists($file_path)) {
                mkdir($file_path, 0777, true);
            }

            $data = file_get_contents($file_url);
            $file_name = "INV-".date('Y-m-d');
            file_put_contents($file_path.'/'.$file_name, $data);
            */

            $getFlowEvent = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);
            $sync_start_date = "";
            if ($getFlowEvent && $getFlowEvent->sync_start_date) {
                $sync_start_date = date('Y-m-d', strtotime(trim($getFlowEvent->sync_start_date)));
            }

            $fileData = fopen($file_url, 'r');
            $contact_invoice_ids = $order_ids = $contact_codes = $invoice_refs = $txn_dates = $invoice_dates = $invoice_due_dates = $net_amounts = $tax_amounts = $nominal_codes = $currency_codes = $exchange_rates = $is_pre_payments = $invoice_line_ids = $descriptions =  $tax_codes =  $tax_amounts = $totals = array();

            $ct_line = 0;
            while (($line = fgetcsv($fileData)) !== FALSE) {
                //echo "<pre>";
                //print_r($line);
                if ($ct_line > 0) {
                    $in_date = \DateTime::createFromFormat('d/m/Y', (@$line[4] ? @$line[4] : date('d/m/Y')));
                    $invoice_date = $in_date->format('Y-m-d');

                    if (strtotime($sync_start_date) >= strtotime($invoice_date)) {
                        $invoice_date = date('Y-m-d', strtotime("+1 day", strtotime($sync_start_date)));
                    }

                    $contact_code = @$line[0];
                    $invoice_ref = @$line[1];
                    //$order_number = @$line[2];
                    $order_id = str_replace('PO', '', @$line[2]);

                    $t_date = \DateTime::createFromFormat('d/m/Y', (@$line[3] ? @$line[3] : date('d/m/Y')));
                    $txn_date = $t_date->format('Y-m-d');

                    $in_due_date = \DateTime::createFromFormat('d/m/Y', (@$line[5] ? @$line[5] : date('d/m/Y')));
                    $invoice_due_date = $in_due_date->format('Y-m-d');

                    //$txn_date = @$line[3];
                    //$invoice_date = @$line[4];
                    //$invoice_due_date = @$line[5];
                    $invoice_line_id = @$line[6];
                    $description = @$line[8];

                    $nominal_code = @$line[10];
                    $net_amount = @$line[13] ? $line[13] : 0;
                    $currency_code = @$line[14];
                    $exchange_rate = @$line[15];
                    $is_prepayment = (trim(@$line[16]) == TRUE) ? 1 : 0;

                    $tax_amount = @$line[17] ? $line[17] : 0;
                    $tax_code = @$line[18];
                    $total = @$line[19] ? $line[19] : 0;

                    $contact_invoice_id = $contact_code . '#@#' . $invoice_ref . '#@#' . $invoice_line_id;
                    $contact_invoice_ids[] = $contact_invoice_id;

                    $order_ids[$contact_invoice_id] = $order_id;
                    $contact_codes[$contact_invoice_id] = $contact_code;
                    $invoice_refs[$contact_invoice_id] = $invoice_ref;
                    $txn_dates[$contact_invoice_id] = $txn_date;
                    $invoice_dates[$contact_invoice_id] = $invoice_date;
                    $invoice_due_dates[$contact_invoice_id] = $invoice_due_date;
                    $invoice_line_ids[$contact_invoice_id] = $invoice_line_id;
                    $nominal_codes[$contact_invoice_id] = $nominal_code;
                    $currency_codes[$contact_invoice_id] = $currency_code;
                    $net_amounts[$contact_invoice_id] = floatval($net_amount) + (isset($net_amounts[$contact_invoice_id]) ? $net_amounts[$contact_invoice_id] : 0);

                    $exchange_rates[$contact_invoice_id] = @$exchange_rate ? $exchange_rate : 0;
                    $is_pre_payments[$contact_invoice_id] = $is_prepayment;

                    $descriptions[$contact_invoice_id] = $description;
                    $tax_codes[$contact_invoice_id] = $tax_code;
                    $tax_amounts[$contact_invoice_id] = $tax_amount;
                    $totals[$contact_invoice_id] = $total;
                }

                $ct_line++;
            }

            $contact_invoice_ids = array_unique($contact_invoice_ids, SORT_STRING);
            foreach ($contact_invoice_ids as $ci_id) {
                $platform_order_id = $currency = $exchange_rate = '';
                if (trim($order_ids[$ci_id]) != '') {
                    //Add Order PO Invoice if having order
                    $result_order =  PlatformOrder::where(['user_integration_id' => $user_integration_id, 'order_type' => $order_type, 'api_order_id' => $order_ids[$ci_id]])->select(['id', 'currency'])->first();
                    $platform_order_id = @$result_order->id;
                }

                //if($contact_codes[$ci_id]!=''){
                // No need to mention platform id because we never create customer while syncing because of file
                //$result_customer = PlatformCustomer::where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'api_customer_code'=>$contact_codes[$ci_id]])->select(['api_customer_id'])->first();

                //$customer_id = @$result_customer->api_customer_id;
                //if($customer_id!=''){

                /*$order_additional_information = PlatformOrderAdditionalInformation::where(['platform_order_id'=>$platform_order_id])->select(['exchange_rate'])->first();
                        $exchange_rate = @$order_additional_information->exchange_rate ? $order_additional_information->exchange_rate : 0;*/

                $arr_invoice = array();
                $arr_invoice['user_id'] = $user_id;
                $arr_invoice['platform_id'] = $this->my_platform_id;
                $arr_invoice['user_integration_id'] = $user_integration_id;
                $arr_invoice['platform_order_id'] = $platform_order_id;
                $arr_invoice['order_doc_number'] = $order_ids[$ci_id];
                $arr_invoice['invoice_date'] = $invoice_dates[$ci_id];
                $arr_invoice['due_date'] = $invoice_due_dates[$ci_id];
                $arr_invoice['api_invoice_id'] = $invoice_line_ids[$ci_id]; // we assuming it as line item id as invoice id for make unique
                $arr_invoice['invoice_code'] = $nominal_codes[$ci_id];
                $arr_invoice['api_customer_code'] = $contact_codes[$ci_id];
                $arr_invoice['ref_number'] = $invoice_refs[$ci_id];
                $arr_invoice['message'] = $descriptions[$ci_id];
                $arr_invoice['api_tax_code'] = $tax_codes[$ci_id];
                $arr_invoice['currency'] = $currency_codes[$ci_id];
                $arr_invoice['exchange_rate'] = $exchange_rates[$ci_id];
                $arr_invoice['net_total'] = $net_amounts[$ci_id];
                $arr_invoice['is_pre_payment'] = $is_pre_payments[$ci_id];
                $arr_invoice['total_tax'] = $tax_amounts[$ci_id];
                $arr_invoice['total_amt'] = $totals[$ci_id];
                $arr_invoice['api_updated_at'] = date('Y-m-d H:i:s');
                $arr_invoice['sync_status'] = 'Ready';

                $inv = PlatformInvoice::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'ref_number' => $invoice_refs[$ci_id], 'api_invoice_id' => $invoice_line_ids[$ci_id]])->select('id', 'linked_id')->first();

                if ($inv) {
                    //PlatformInvoice::where(['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id, 'sync_status' => 'Pending'])->update($arr_invoice);

                    if ($inv->linked_id == 0) {
                        PlatformInvoice::where(['id' => $inv->id])->update($arr_invoice);
                        if ($platform_order_id) {
                            PlatformOrder::where(['id' => $platform_order_id])->update(['invoice_sync_status' => 'Ready']);
                        }
                    }
                } else {
                    $arr_invoice['api_created_at'] = date('Y-m-d H:i:s');

                    PlatformInvoice::insert($arr_invoice);
                    if ($platform_order_id) {
                        PlatformOrder::where(['id' => $platform_order_id])->update(['invoice_sync_status' => 'Ready']);
                    }
                }

                $transaction = PlatformOrderTransaction::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'transaction_reference' => $invoice_refs[$ci_id]])->select('id', 'linked_id')->first();

                if ($transaction && $platform_order_id) {
                    //updates allow till not synced
                    if ($transaction->linked_id == 0) {
                        PlatformOrderTransaction::where(['id' => $transaction->id])->update(['platform_order_id' => $platform_order_id]);
                        if ($platform_order_id) {
                            PlatformOrder::where(['id' => $platform_order_id])->update(['transaction_sync_status' => 'Ready']);
                        }
                    }
                }
                //}
                //}
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " --> TipaltiApiController -> TipaltiProxyGetPOInvoiceFiles --> " . $e->getLine() . " --> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        //return $return_response;

    }


    public function TipaltiGetPayment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'user_id', 'platform_id', 'account_name', 'api_domain', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'connection_type', 'access_key', 'secret_key', 'region']);

            if ($account) {


                $redirect_url = \URL::to('/') . '/' . \Config::get('apiconfig.TipaltiTransactionRedirectURL');

                $CustomDataTransactionFolder = $this->map->getMappedDataByName($user_integration_id, null, "payment_access_folder", ['custom_data'], "default");
                $sftp_folder = '';
                if ($CustomDataTransactionFolder) {
                    $sftp_folder = @$CustomDataTransactionFolder->custom_data;
                }


                $CustomDataArchivedFolder = $this->map->getMappedDataByName($user_integration_id, null, "payment_archived_access_folder", ['custom_data'], "default");
                $sftp_archived_folder = '';
                if ($CustomDataArchivedFolder) {
                    $sftp_archived_folder = @$CustomDataArchivedFolder->custom_data;
                }




                if ($account->connection_type == 'sftp') {

                    $sftp_folder = @$sftp_folder ? $sftp_folder : '/';
                    $sftp_archived_folder = @$sftp_archived_folder ? $sftp_archived_folder : '/';

                    $this->GetSFTPData($account, $user_id, $user_integration_id, $order_type, $redirect_url, $sftp_folder, $sftp_archived_folder);
                } else if ($account->connection_type == 'aws-s3') {

                    $CustomDataBucket = $this->map->getMappedDataByName($user_integration_id, null, "aws_bucket", ['custom_data'], "default");
                    $bucket = '';
                    if ($CustomDataBucket) {
                        $bucket = @$CustomDataBucket->custom_data;
                    }

                    $this->GetAWSData($account, ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'order_type' => $order_type, 'module' => 'payment', 'bucket' => $bucket, 'access_folder' => $sftp_folder, 'archived_access_folder' => $sftp_archived_folder, 'server_access_folder' => public_path() . '/esb_asset/tipalti/' . $user_integration_id . '/', 'user_workflow_rule_id' => $user_workflow_rule_id]);
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--TipaltiGetPayment-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }




    public function TipaltiProxyGetPOPaymentFiles(Request $request)
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {


            $user_id = $request->user_id;
            $user_integration_id = $request->user_integration_id;
            $order_type = $request->order_type;
            $file_url = $request->file_url;

            /*
             // store file in to a folder
             $file_path = public_path().'/esb_asset/tipalti/'.$user_integration_id;
             if (!file_exists($file_path)) {
                 mkdir($file_path, 0777, true);
             }

             $data = file_get_contents($file_url);
             $file_name = "INV-".date('Y-m-d');
             file_put_contents($file_path.'/'.$file_name, $data);
             */

            $fileData = fopen($file_url, 'r');
            $order_ids = $contact_codes = $invoice_refs = $txn_dates = $invoice_dates = $invoice_due_dates = $net_amounts = $tax_amounts = $nominal_codes = array();



            $ct_line = 0;
            while (($line = fgetcsv($fileData)) !== FALSE) {
                echo "<pre>";
                print_r($line);
                if ($ct_line > 0) {

                    $contact_code = $line[0];

                    $date = \DateTime::createFromFormat('d/m/Y', (@$line[1] ? @$line[1] : date('d/m/Y')));
                    $txn_date = $date->format('Y-m-d');
                    //$txn_date = $line[1];
                    //$payment_method = $line[2];
                    $payment_method = $line[3];
                    $bank_account = $line[3];
                    $invoice_ref = $line[4];
                    $currency_code = $line[5];
                    $exchange_rate = $line[6];
                    $amount = $line[7];
                    $payment_reference = $line[8];





                    $arr_payment = array();
                    //$arr_payment['user_id'] = $user_id;
                    $arr_payment['platform_id'] = $this->my_platform_id;
                    $arr_payment['user_integration_id'] = $user_integration_id;
                    $arr_payment['row_type'] = 'PAYMENT';
                    $arr_payment['transaction_datetime'] = $txn_date;
                    $arr_payment['transaction_amount'] = $amount;
                    $arr_payment['transaction_id'] = $payment_reference;
                    $arr_payment['transaction_reference'] = $invoice_ref;
                    $arr_payment['transaction_method'] = $payment_method;
                    $arr_payment['exchange_rate'] = $exchange_rate;
                    $arr_payment['currency_code'] = $currency_code;
                    $arr_payment['bank_account'] = $bank_account;
                    //$arr_payment['platform_customer_id'] = $platform_customer_id;
                    $arr_payment['api_customer_code'] = $contact_code;

                    $arr_payment['sync_status'] = 'Ready';


                    //Add Order Invoice For Log
                    $result_invoice =  PlatformInvoice::where(['user_integration_id' => $user_integration_id, 'ref_number' => $invoice_ref])->select(['id', 'platform_order_id'])->first();

                    $platform_order_id = $id = '';
                    if ($result_invoice) {

                        $id = $result_invoice->id;
                        $platform_order_id = $result_invoice->platform_order_id;
                    }

                    if ($platform_order_id) {
                        $arr_payment['platform_order_id'] = $platform_order_id;
                    }

                    //dd($arr_payment);

                    $transaction = PlatformOrderTransaction::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'transaction_id' => $payment_reference, 'transaction_reference' => $invoice_ref])->select('id', 'linked_id')->first();

                    if ($transaction) {
                        //updates allow till not synced
                        if ($transaction->linked_id == 0) {
                            PlatformOrderTransaction::where(['id' => $transaction->id])->update($arr_payment);
                            if ($platform_order_id) {
                                PlatformOrder::where(['id' => $platform_order_id])->update(['transaction_sync_status' => 'Ready']);
                            }
                        }
                    } else {
                        PlatformOrderTransaction::insert($arr_payment);
                        if ($platform_order_id) {
                            PlatformOrder::where(['id' => $platform_order_id])->update(['transaction_sync_status' => 'Ready']);
                        }
                    }
                }

                $ct_line++;
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--TipaltiProxyGetPOPaymentFiles-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        //return $return_response;

    }




    public function test_tipalti()
    {
        die;

        $line = '01/12/2022';
        $t_date = DateTime::createFromFormat('d/m/Y', (@$line ? @$line : date('d/m/Y')));
        echo $txn_date = $t_date->format('Y-m-d');

        die;
        $user_id = 192;
        $user_integration_id = 215;
        $platform_workflow_rule_id = 97;
        $user_workflow_rule_id = 567;
        $sync_status = 'Ready';
        $source_platform_id = 'tipalti';


        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreatePurchaseOrderInvoicePayment($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'POInvoice', 'Ready', 6971221);
        die;


        phpinfo();
        die;
        $user_integration_id = 215;

        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  1, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

        $url = "/order-service/order/1172910?includeOptional=customFields";

        $response = app('App\Helper\Api\BrightpearlApi')->GetPurchaseOrders($ufound, $url);
        $bsOrders = json_decode($response->getBody(), true);
        dd($bsOrders);
        die;

        $user_integration_id = 215;

        $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'user_id', 'platform_id', 'account_name', 'api_domain', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'connection_type', 'access_key', 'secret_key', 'region']);

        $file_to_decrypt = '/home/esbapiworx/public_html/integration/public/esb_asset/tipalti/215/Created_Bills_May_16,_2022_-_17ss.csv';
        $decrypt_file_name = '/home/esbapiworx/public_html/integration/public/esb_asset/tipalti/215/1652767792_Created_Bills_May_16,_2022_-_17ss.csv';

        shell_exec('gpg --batch --passphrase ' . $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt') . ' -d ' . $file_to_decrypt . ' > ' . $decrypt_file_name);


        // $decryption_status = app('App\Http\Controllers\Tipalti\PGPUtility')->DecryptDataPGP($account,['file_to_decrypt'=>$file_to_decrypt,'decrypted_file_name'=>$decrypt_file_name]);

        //echo $decryption_status;
        die;


        $user_id = 192;
        $user_integration_id = 215;
        $platform_workflow_rule_id = 96;
        $user_workflow_rule_id = 566;


        $this->TipaltiGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO');
        die;




        echo date('yymd_Hmsv');
        // echo "<br/>";
        // echo date('_yyyyMMdd_HHmmssfff');
        // echo "<br/>";
        //  echo date('_yyMd_Hms');
        die;

        $user_id = 150;
        $user_integration_id = 419;
        $platform_workflow_rule_id = 70;
        $user_workflow_rule_id = 727;
        //$this->TipaltiGetInvoice($user_id,$user_integration_id,$platform_workflow_rule_id,$user_workflow_rule_id,$order_type='PO');
        $this->TipaltiGetPayment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO');

        die;

        $user_id = 150;
        $user_integration_id = 419;
        $platform_workflow_rule_id = 68;
        $user_workflow_rule_id = 725;

        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';
        $this->TipaltiCreateUpdateGRN($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $sync_status, $record_id = '');
        //$this->TipaltiCreateUpdatePO($user_id,$source_platform,$user_integration_id,$platform_workflow_rule_id,$user_workflow_rule_id,$order_type,$sync_status,$record_id = '');

        die;

        $aws_accessKey = 'AKIAZNGS4GXEDNKKRTXU';
        $aws_secretKey = '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';


        try {

            $credentials = new \Aws\Credentials\Credentials($aws_accessKey, $aws_secretKey);

            $s3 = new \Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => 'us-west-2',
                'credentials' => $credentials
            ]);
            $result = $s3->listBuckets();
            /*
             // Convert the result object to a PHP array
             $array = $result->toArray();
             echo"<pre>";
             print_r($array);
            */
            foreach ($result['Buckets'] as $bucket) {
                echo $bucket['Name'] . "\n";
            }
        } catch (\Aws\S3\Exception\S3Exception $e) {
            echo "There was an error uploading the file.\n";
        }


        die;

        // Encryption working code

        $pvtkey = "-----BEGIN PGP PRIVATE KEY BLOCK-----
        Version: BCPG C# v1.6.1.0

        lQOsBGIfV7oBCAC2Dv0JTOnVVly/JtbDZg0QZSU+PfmR9xv9S0QFWYH2z0szcHE7
        b0HywmJXlBMvXj0s8TuJfKWfA/WoNRO8A7wTKq3FYlyCW10l3kutaS0UO9ebveIh
        M51Smd4l9BKcsYe/kLrA1udrQYX0pRkpBUDubGSZZnNt2bjavqKCPCkQfo6TCMJQ
        H5a0zEjYbhZD8PW5X+PIqfgJBvOoBMDOtBo2lyrIl1PlFW4YYaBcZoO4NVtOvua/
        pgXBNaQgr33Ey44AmGoGqD/SpyKd7xB5BMyhPnDknWho5BzTXu9HdsADiH+AoY1D
        xESZqX0EQTje6UV3rfN6MBqASJL/NUEMIY+bABEBAAH/AwMCWz7F9UVqqQVgit2P
        iAlqtpx6tHMqHSRlcHg3JGZIxh9emmJKoJ4Exny2/zNTIYXUUPezTnRzOn1HP8Y4
        2yOEjhETFJEcuk3jlE+I+Ot/eVbv/qL/3PExujTaAxF8s2TDV/CxxG/UUSJpwCta
        FvNOvnyyhJoGVmFYf7Wur4ZRfcHfp3HvsSiiWHPjOtmrZG2G/bYTeAQQAPrfDrYV
        rFnZ+2lVBTXPOpsutBwsrpfzfc4reyPdrbZv8X5U6eDC643Yf7bJAot5KX4xItRS
        E8u70s8UhGyTH49RbK0DzQEQgSm4hpaanbT8M8/ZfQIq86X04u6OhK
        uzvnzz0wBfIePtctIKteSlRpIDWXnXCqgDS4NXpCfCmTSJo5gWty5KRLGLZB8M9O
        ewTu9/QYkgkBmL3Q3B7rqgz57Vlexht3Vkzi5iUDQskEfGbxUxktCryHeehflebu
        xOMgcmGZLZL46ZlTly/7AXEG0IInz21uQdClalwaOQXUW/294FEPpNrFBGOBVelR
        /dmZxE5FEB3WdvmLILToyvq6+u0MK+DKQLMwe79+PYC6e0gL70SiT99xwEhWI1Uf
        lmNhjig9GAl+9BaXB6Xsml5t2Q50/MSwdyV7yblg5O1NFeJTSLLezUnxTv+XZ+A4
        lj/Kxx+6L7Djjb3E2SWW1UZ8Hl+shHK3RF7WcjaH9cphOzlKFGly6m/jQ1ZlFdcu
        bEnYTZiTbVWp2EYB1qDK4ueHUqTiIpyLJjTeEcoKDwQLzgT+gbIDVVb5FZysmInf
        eZwNp+89kmkkDxxrHXJ50rLpN6UufW0LwWfYLXGdVqIZ8huG2tRcOUAlJweu1Rgy
        KdDrp6YheoRrt7rHapPWRxZBRdqRdnP+hotUSkyJPbQYb2Zlci5tYW4xMjEyQHRp
        cGFsdGkuY29tiQEcBBABAgAGBQJiH1e6AAoJEFwZl4pStf/ScdMIALRyzhKsoxuo
        n8qaGO0S7S76+hJmlI2cw/5QkrUgtElLFVE+vXV99E3lbxhwC9tmQhflKhbHnv6T
        bMcqR3j1n01DeCBgLJcnJAq32SSXlR8d1oFuPM4iFfJoPpbHdguaY9rys6L4e//T
        wkhhBZ/09BZbjU6vfLg+UDPNj/q6QooXRbjqN0SF4Csq4fHXmbJOfV03Ih4z/NNb
        Rr0r1FR5o5OUBEh/c3T/Z9zKjB8umQ6yYHtwSyUAqZmjG0WARKxWRQi8xMZRXGL5
        HseY7wOE4ArLBTcs/QtkIXc0heqZm1HyoV5h2sJ120BXdiJR6UEyM1g10CuzQWfZ
        BwjlcgYBFHM=
        =vGrW
        -----END PGP PRIVATE KEY BLOCK-----
        ";

        $res = gnupg_init();
        $rtv = gnupg_import($res, $pvtkey);
        dd($rtv);
        die;

        file_put_contents('shell_pgp.sh', 'gpg --batch --passphrase 1234 -d ' . public_path() . '/Created_Payments.csv > ' . public_path() . '/payment_hh_bhoopendra.csv');


        $cmd = 'gpg --batch --passphrase 1234 -d ' . public_path() . '/Created_Payments.csv > ' . public_path() . '/payment_hh_bhoopendra.csv';
        $process = new Process([$cmd]);
        $process->run();

        // executes after the command finishes
        if (!$process->isSuccessful()) {
            //throw new ProcessFailedException($process);
        }

        echo $process->getOutput();

        die;


        file_put_contents('shell_pgp.sh', 'gpg --batch --passphrase 1234 -d ' . public_path() . '/Created_Payments.csv > ' . public_path() . '/payment_bhoopendra.csv');
        echo base_path();


        dd(shell_exec('gpg --batch --passphrase 1234 -d ' . public_path() . '/Created_Payments.csv > ' . public_path() . '/payment_bhoopendra.csv'));

        $gpg = new \gnupg();

        // throw exception if error occurs
        $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

        // ciphertext message
        $ciphertext = file_get_contents(public_path() . '/ciphertext_shubham.csv');

        // register secret key by providing passphrase
        // decrypt ciphertext with secret key
        // display plaintext message
        try {
            $pvtKey = file_get_contents(public_path() . '/private_shubham.asc');
            $info = $gpg->import($pvtKey);
            $gpg->adddecryptkey($info['fingerprint'], 'shubham');
            dd(shell_exec('gpg --batch --passphrase 1234 -d ' . public_path() . '/ciphertext_bhoopendra.csv > ' . public_path() . '/decrpt_key_shubham_file_bhoopendra.csv'));
            // $plaintext = $gpg->decrypt($ciphertext);
            //echo '<pre>' . $plaintext . '</pre>';
        } catch (Exception $e) {
            die('ERROR: ' . $e->getMessage());
        }

        die;


        // create new GnuPG object
        $gpg = new \gnupg();

        // throw exception if error occurs
        $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

        // plaintext message
        $plaintext =
            "Hii \n
  i am shubham.\n
Team Leader";

        // find key matching email address
        // encrypt plaintext message
        // display and also write to file
        try {
            $publicKey = file_get_contents(public_path() . '/public_shubham.asc');
            $info = $gpg->import($publicKey);
            $gpg->addencryptkey($info['fingerprint']);
            $ciphertext = $gpg->encrypt($plaintext);
            echo '<pre>' . $ciphertext . '</pre>';
            file_put_contents(public_path() . '/ciphertext_shubham.csv', $ciphertext);
        } catch (Exception $e) {
            die('ERROR: ' . $e->getMessage());
        }

        die;












        die;
        dd(shell_exec('gpg --batch --passphrase 1234 -d ' . public_path() . '/encryptedPO.gpg > ' . public_path() . '/decrpt_converted1.csv'));








        $passphrase = '1234';
        $file = "-----BEGIN PGP PRIVATE KEY BLOCK-----
        Version: BCPG C# v1.6.1.0

        lQOrBGIgSvgBCACBVLdwJhGGXkOZLRaWrc0ZujQw013MFVW6uFZNklBHTkmceI6I
        d4RmO4zNoIKpU5c65mgWWaH1dDzYeQ8WT5D1PACjcEGhkHSM6HkapF9wFzgOuPB9
        DvkSFbgUGcXNkkaez0syzh2wI2LrVKKP4xQoaKkH0cUgCZKLFnOyHBmcN9649naz
        4vRBC0wxvigqYCtQh6HJdXFXxlKgowaNoegMdWIEuW9LPGOHigoYe4nfHHy3mh3K
        vHjbSzZBRIsmz6cQsqW1SKkQCxtKjT5E2bM100R4jvdyrnz81wmWJjiUsc3Y8nfz
        sFb7noYK64NuRmCv9wuOgrlOsSOk5Exj86GvABEBAAH/AwMCd7Z8Jset56xgv8jO
        /GgqIo2XsfKwKqanQBV1QAh++iT+90CEqPPP9/6/7WHMhvDGVOngX4ltOmvF5k6A
        ecuEXPqW0jdv0lbYouWWnPWJcR8GFbqgLoWxPQDFhuwBLZX/VjCW6kLdf6DdvXpM
        P2+XEgZpHZZ81i8uGPhOm7DkwExc54bZvJhpekhigtBK1llcWlLuYOGvCg5FHrx3
        c0c5u1aKF/TNoDlhkdYoxgF1H74om3DuE8QgRiIwBP5bDfceTjj6ltaDiGuicpQd
        Xb9z8kj76bTlmxPy0r1YQUnRamla9O0jgB4TS+oPQ4ZBjdFRVjDpPwPuz0+bcd+v
        9o2M/WDOVksuAZ8Px9ehrFjujltjdD58iKq5djjWqEvbFJQxV4hmOflOS240mlvw
        I6pbU3WM6RA4GPhmp/KjsUoV1sOthluY52RDvTgIEoV2I7Zlk8606UtXUdSkcMQG
        lDjV7hMMJ1uvTS929DdDzq1c6E9eL3OLb+2XNPJRrEm9eWHKSveZHkzfK8mhZ1Jo
        UOiLRPOBg6Jxals7p3hRW5EAZ1DdZfejMI+frC6pT8yII7Li1pRHvZ5nORruGUww
        YOjdHX9g3nKjZVo5l/82c77uuWAEvnIO/2NedaxYJ+es9ljstBgaTBg/F4GrbIj9
        gcjruhbaHtz/RTaV9vQa61bXlLpHHTCxOzjt5gcdDtoejJ7is0b0ES3/eAIt0EEV
        5A5fnjYeJd6uaVWofh9nCgAfA30UZPXVQAno9DpmgGdIp7Qgmi9b0JUVC7xL6oOT
        XCYoZO+2CEKTldKznnf67MRJ3RqFYFwRbXxwpaM5/WDU7FYdYjtA8+Wl4R16p4Cz
        Lf3kky0AcT+3XDuxGURPIsKD7rwlgC1RtHz+SEyktBRiaG9vcGVuZHJhQGdtYWls
        LmNvbYkBHAQQAQIABgUCYiBK+AAKCRDF4CczBc7syVv+CACApgBxY2ePdR03l2kb
        ZgrLz8Ze/OgCgABNm3C8Yjt882h122POwQVkM92YNm24KejnZ19bK0JUI3KulXzi
        0gWtTXt4jFTHupe4paXX7mA3kEFMlUN7iTsCpJjClHkKsXu5O1NF8mR+xVLNHCUx
        eu+cIXsqyq2NaYeeIHqNcGZwXGLSDFZIyCg/OZtm1NwhKq/3iKdFfAuTS0ZSg2if
        j4fdG6qn3kdaXHtVB5Z/T54pczYYK2Nz4LX0td0cBa8qw4tP3W58Vt+xjnpENm6l
        c1WpcCrFCDZHkveW9MV5Oo1KmiMiZsVd7o28aNj9uf51Wdbr/eFU7M4hFRbtENot
        cQ+k
        =0SER
        -----END PGP PRIVATE KEY BLOCK-----";
        $decrypted_file_name = "hQEMA8XgJzMFzuzJAQf/fJbryW5Vpkfll6vfUnSYUqC9XDe6u3wN8/59UYfDRIRf a74wx55Gxw1Qav8wBLFSsrC0+J7Kt7J2aZPfw3JNs9X8daK6GHSoxoF3UVYFLIOB 7UqyLsTLHorsrSk8Pvgj2o/50Bk84pnOBgA+WE6556RIbPJkqpd371gplTPjjVqO oFT24oyeLHjuE18BQzHyO0ioxRd1ZXc+0qAQphAb+dsNmjj2tPmtzf83FY5dA/OO vqHsgyHBoEBdFFQZ3mFWpToyIks9Y22Gf/ES3GbS8JzkmpKb9WHCyTka2swRafa/ qVKOjvJx0U2pfEkJ8VRT/QknO2UjCwUgsUXSQzheN8ktUIveL16YWDdZ6otAj11r fOlN1PKbW2TZk7Ery/T3mGWfRB24aM4dXjhIyf80 =Z5cg";
        dd(shell_exec("gpg --batch --passphrase \"" . $passphrase . "\" -d $file > $decrypted_file_name"));
        die;

        $pvtkey = "-----BEGIN PGP PRIVATE KEY BLOCK-----
        Version: BCPG C# v1.6.1.0

        lQOrBGIgSvgBCACBVLdwJhGGXkOZLRaWrc0ZujQw013MFVW6uFZNklBHTkmceI6I
        d4RmO4zNoIKpU5c65mgWWaH1dDzYeQ8WT5D1PACjcEGhkHSM6HkapF9wFzgOuPB9
        DvkSFbgUGcXNkkaez0syzh2wI2LrVKKP4xQoaKkH0cUgCZKLFnOyHBmcN9649naz
        4vRBC0wxvigqYCtQh6HJdXFXxlKgowaNoegMdWIEuW9LPGOHigoYe4nfHHy3mh3K
        vHjbSzZBRIsmz6cQsqW1SKkQCxtKjT5E2bM100R4jvdyrnz81wmWJjiUsc3Y8nfz
        sFb7noYK64NuRmCv9wuOgrlOsSOk5Exj86GvABEBAAH/AwMCd7Z8Jset56xgv8jO
        /GgqIo2XsfKwKqanQBV1QAh++iT+90CEqPPP9/6/7WHMhvDGVOngX4ltOmvF5k6A
        ecuEXPqW0jdv0lbYouWWnPWJcR8GFbqgLoWxPQDFhuwBLZX/VjCW6kLdf6DdvXpM
        P2+XEgZpHZZ81i8uGPhOm7DkwExc54bZvJhpekhigtBK1llcWlLuYOGvCg5FHrx3
        c0c5u1aKF/TNoDlhkdYoxgF1H74om3DuE8QgRiIwBP5bDfceTjj6ltaDiGuicpQd
        Xb9z8kj76bTlmxPy0r1YQUnRamla9O0jgB4TS+oPQ4ZBjdFRVjDpPwPuz0+bcd+v
        9o2M/WDOVksuAZ8Px9ehrFjujltjdD58iKq5djjWqEvbFJQxV4hmOflOS240mlvw
        I6pbU3WM6RA4GPhmp/KjsUoV1sOthluY52RDvTgIEoV2I7Zlk8606UtXUdSkcMQG
        lDjV7hMMJ1uvTS929DdDzq1c6E9eL3OLb+2XNPJRrEm9eWHKSveZHkzfK8mhZ1Jo
        UOiLRPOBg6Jxals7p3hRW5EAZ1DdZfejMI+frC6pT8yII7Li1pRHvZ5nORruGUww
        YOjdHX9g3nKjZVo5l/82c77uuWAEvnIO/2NedaxYJ+es9ljstBgaTBg/F4GrbIj9
        gcjruhbaHtz/RTaV9vQa61bXlLpHHTCxOzjt5gcdDtoejJ7is0b0ES3/eAIt0EEV
        5A5fnjYeJd6uaVWofh9nCgAfA30UZPXVQAno9DpmgGdIp7Qgmi9b0JUVC7xL6oOT
        XCYoZO+2CEKTldKznnf67MRJ3RqFYFwRbXxwpaM5/WDU7FYdYjtA8+Wl4R16p4Cz
        Lf3kky0AcT+3XDuxGURPIsKD7rwlgC1RtHz+SEyktBRiaG9vcGVuZHJhQGdtYWls
        LmNvbYkBHAQQAQIABgUCYiBK+AAKCRDF4CczBc7syVv+CACApgBxY2ePdR03l2kb
        ZgrLz8Ze/OgCgABNm3C8Yjt882h122POwQVkM92YNm24KejnZ19bK0JUI3KulXzi
        0gWtTXt4jFTHupe4paXX7mA3kEFMlUN7iTsCpJjClHkKsXu5O1NF8mR+xVLNHCUx
        eu+cIXsqyq2NaYeeIHqNcGZwXGLSDFZIyCg/OZtm1NwhKq/3iKdFfAuTS0ZSg2if
        j4fdG6qn3kdaXHtVB5Z/T54pczYYK2Nz4LX0td0cBa8qw4tP3W58Vt+xjnpENm6l
        c1WpcCrFCDZHkveW9MV5Oo1KmiMiZsVd7o28aNj9uf51Wdbr/eFU7M4hFRbtENot
        cQ+k
        =0SER
        -----END PGP PRIVATE KEY BLOCK-----";

        $enc = (null);
        $res = gnupg_init();
        echo "gnupg_init RTV = <br/><pre>\n";
        var_dump($res);
        echo "</pre>\n";
        $rtv = gnupg_import($res, $pvtkey);
        echo "gnupg_import RTV = <br/><pre>\n";
        var_dump($rtv);
        echo "</pre>\n";
        $rtv = gnupg_adddecryptkey($res, $rtv['fingerprint'], "1234");
        echo "gnupg_adddecryptkey RTV = <br /><pre>\n";
        var_dump($rtv);
        echo "</pre>\n";
        $encrypted_text = "-----BEGIN PGP MESSAGE----- hQEMA8XgJzMFzuzJAQf/fJbryW5Vpkfll6vfUnSYUqC9XDe6u3wN8/59UYfDRIRf a74wx55Gxw1Qav8wBLFSsrC0+J7Kt7J2aZPfw3JNs9X8daK6GHSoxoF3UVYFLIOB 7UqyLsTLHorsrSk8Pvgj2o/50Bk84pnOBgA+WE6556RIbPJkqpd371gplTPjjVqO oFT24oyeLHjuE18BQzHyO0ioxRd1ZXc+0qAQphAb+dsNmjj2tPmtzf83FY5dA/OO vqHsgyHBoEBdFFQZ3mFWpToyIks9Y22Gf/ES3GbS8JzkmpKb9WHCyTka2swRafa/ qVKOjvJx0U2pfEkJ8VRT/QknO2UjCwUgsUXSQzheN8ktUIveL16YWDdZ6otAj11r fOlN1PKbW2TZk7Ery/T3mGWfRB24aM4dXjhIyf80 =Z5cg -----END PGP MESSAGE-----";
        $enc = gnupg_decrypt($res, $encrypted_text);
        //$info = gnupg_decryptverify($res,$encrypted_text,"");
        echo gnupg_geterror($res);
        var_dump($enc);
        echo "Decrypted Data: " . $enc . "<br/>";


        die;
        // Encryption working code without uploaded key

        $pubkey = "-----BEGIN PGP PUBLIC KEY BLOCK-----
Version: BCPG C# v1.6.1.0

mQENBGIgSvgBCACBVLdwJhGGXkOZLRaWrc0ZujQw013MFVW6uFZNklBHTkmceI6I
d4RmO4zNoIKpU5c65mgWWaH1dDzYeQ8WT5D1PACjcEGhkHSM6HkapF9wFzgOuPB9
DvkSFbgUGcXNkkaez0syzh2wI2LrVKKP4xQoaKkH0cUgCZKLFnOyHBmcN9649naz
4vRBC0wxvigqYCtQh6HJdXFXxlKgowaNoegMdWIEuW9LPGOHigoYe4nfHHy3mh3K
vHjbSzZBRIsmz6cQsqW1SKkQCxtKjT5E2bM100R4jvdyrnz81wmWJjiUsc3Y8nfz
sFb7noYK64NuRmCv9wuOgrlOsSOk5Exj86GvABEBAAG0FGJob29wZW5kcmFAZ21h
aWwuY29tiQEcBBABAgAGBQJiIEr4AAoJEMXgJzMFzuzJW/4IAICmAHFjZ491HTeX
aRtmCsvPxl786AKAAE2bcLxiO3zzaHXbY87BBWQz3Zg2bbgp6OdnX1srQlQjcq6V
fOLSBa1Ne3iMVMe6l7ilpdfuYDeQQUyVQ3uJOwKkmMKUeQqxe7k7U0XyZH7FUs0c
JTF675wheyrKrY1ph54geo1wZnBcYtIMVkjIKD85m2bU3CEqr/eIp0V8C5NLRlKD
aJ+Ph90bqqfeR1pce1UHln9PnilzNhgrY3PgtfS13RwFryrDi0/dbnxW37GOekQ2
bqVzValwKsUINkeS95b0xXk6jUqaIyJmxV3ujbxo2P25/nVZ1uv94VTsziEVFu0Q
2i1xD6Q=
=opp3
-----END PGP PUBLIC KEY BLOCK-----";

        $res = gnupg_init();
        $rtv = gnupg_import($res, $pubkey);
        $rtv = gnupg_addencryptkey($res, $rtv['fingerprint']);
        $enc = gnupg_encrypt($res, "hiii, this, is, bhoopendra");
        echo "Encrypted Data: " . $enc . "<br/>";
        die;














        $gpg = new \gnupg();
        $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

        //$gpg->adddecryptkey("ofer.man1212@tipalti.com","1234");
        $gpg->adddecryptkey("5924140435AA3637BBE965E85C19978A52B5FFD2", "1234");
        $encrypted_text = "hQEMA1wZl4pStf/SAQf7BXX3Ig1FUXfNf2mELu5M4Ziya1mIwVTX8bfdA0D/yJ52 jak+4or4xyxq/yOpmcZJioXoUGOM5JnkS89O8OEjtb9NqVT0JYtB2F0FWFzlP8Bh uFOYMU82FWyR2TDeblYIswpYWNAwDsX6Tqc9bbAlyb5MeN9ElF+SUqO3e3f1ykBI j+gH/dWwTGyLOxq3owFR9RUm74BBp/CgUIo1/u5lE6drPDtimws4Abf4S/5RQu4M pBH5uHnRIuKaOpTslLp/U5JSS6zBNqs60Ryf0INFaGbx8fLbX3rakQz3pfxIEwMy atMi7aZ6OJ0QaSq0a3Dy+zdhDPbGIIO0LxBCqtjJeMk5fmHgUNyMWwcFH2i3szoH QFngV+h3nFLAD7XB/illBZxdiqexFT3TVSJB/Lmtpnp4DWgUREMJZ6Sy =Zv69";
        //-----BEGIN PGP MESSAGE----- Version: GnuPG v2.0.22 (GNU/Linux) hQEMA1wZl4pStf/SAQf7BXX3Ig1FUXfNf2mELu5M4Ziya1mIwVTX8bfdA0D/yJ52 jak+4or4xyxq/yOpmcZJioXoUGOM5JnkS89O8OEjtb9NqVT0JYtB2F0FWFzlP8Bh uFOYMU82FWyR2TDeblYIswpYWNAwDsX6Tqc9bbAlyb5MeN9ElF+SUqO3e3f1ykBI j+gH/dWwTGyLOxq3owFR9RUm74BBp/CgUIo1/u5lE6drPDtimws4Abf4S/5RQu4M pBH5uHnRIuKaOpTslLp/U5JSS6zBNqs60Ryf0INFaGbx8fLbX3rakQz3pfxIEwMy atMi7aZ6OJ0QaSq0a3Dy+zdhDPbGIIO0LxBCqtjJeMk5fmHgUNyMWwcFH2i3szoH QFngV+h3nFLAD7XB/illBZxdiqexFT3TVSJB/Lmtpnp4DWgUREMJZ6Sy =Zv69 -----END PGP MESSAGE-----
        dd($gpg);
        $plain = $gpg->decrypt($encrypted_text);
        echo $plain;



        die;



        // Encryption working code

        $pvtkey = "-----BEGIN PGP PRIVATE KEY BLOCK-----
          Version: BCPG C# v1.6.1.0

          lQOsBGIfV7oBCAC2Dv0JTOnVVly/JtbDZg0QZSU+PfmR9xv9S0QFWYH2z0szcHE7
          b0HywmJXlBMvXj0s8TuJfKWfA/WoNRO8A7wTKq3FYlyCW10l3kutaS0UO9ebveIh
          M51Smd4l9BKcsYe/kLrA1udrQYX0pRkpBUDubGSZZnNt2bjavqKCPCkQfo6TCMJQ
          H5a0zEjYbhZD8PW5X+PIqfgJBvOoBMDOtBo2lyrIl1PlFW4YYaBcZoO4NVtOvua/
          pgXBNaQgr33Ey44AmGoGqD/SpyKd7xB5BMyhPnDknWho5BzTXu9HdsADiH+AoY1D
          xESZqX0EQTje6UV3rfN6MBqASJL/NUEMIY+bABEBAAH/AwMCWz7F9UVqqQVgit2P
          iAlqtpx6tHMqHSRlcHg3JGZIxh9emmJKoJ4Exny2/zNTIYXUUPezTnRzOn1HP8Y4
          2yOEjhETFJEcuk3jlE+I+Ot/eVbv/qL/3PExujTaAxF8s2TDV/CxxG/UUSJpwCta
          FvNOvnyyhJoGVmFYf7Wur4ZRfcHfp3HvsSiiWHPjOtmrZG2G/bYTeAQQAPrfDrYV
          rFnZ+2lVBTXPOpsutBwsrpfzfc4reyPdrbZv8X5U6eDC643Yf7bJAot5KX4xItRS
          E8u70s8UhGyTH49RbK0DzQEj5bk1JvCkEQgSm4hpaanbT8M8/ZfQIq86X04u6OhK
          uzvnzz0wBfIePtctIKteSlRpIDWXnXCqgDS4NXpCfCmTSJo5gWty5KRLGLZB8M9O
          ewTu9/QYkgkBmL3Q3B7rqgz57Vlexht3Vkzi5iUDQskEfGbxUxktCryHeehflebu
          xOMgcmGZLZL46ZlTly/7AXEG0IInz21uQdClalwaOQXUW/294FEPpNrFBGOBVelR
          /dmZxE5FEB3WdvmLILToyvq6+u0MK+DKQLMwe79+PYC6e0gL70SiT99xwEhWI1Uf
          lmNhjig9GAl+9BaXB6Xsml5t2Q50/MSwdyV7yblg5O1NFeJTSLLezUnxTv+XZ+A4
          lj/Kxx+6L7Djjb3E2SWW1UZ8Hl+shHK3RF7WcjaH9cphOzlKFGly6m/jQ1ZlFdcu
          bEnYTZiTbVWp2EYB1qDK4ueHUqTiIpyLJjTeEcoKDwQLzgT+gbIDVVb5FZysmInf
          eZwNp+89kmkkDxxrHXJ50rLpN6UufW0LwWfYLXGdVqIZ8huG2tRcOUAlJweu1Rgy
          KdDrp6YheoRrt7rHapPWRxZBRdqRdnP+hotUSkyJPbQYb2Zlci5tYW4xMjEyQHRp
          cGFsdGkuY29tiQEcBBABAgAGBQJiH1e6AAoJEFwZl4pStf/ScdMIALRyzhKsoxuo
          n8qaGO0S7S76+hJmlI2cw/5QkrUgtElLFVE+vXV99E3lbxhwC9tmQhflKhbHnv6T
          bMcqR3j1n01DeCBgLJcnJAq32SSXlR8d1oFuPM4iFfJoPpbHdguaY9rys6L4e//T
          wkhhBZ/09BZbjU6vfLg+UDPNj/q6QooXRbjqN0SF4Csq4fHXmbJOfV03Ih4z/NNb
          Rr0r1FR5o5OUBEh/c3T/Z9zKjB8umQ6yYHtwSyUAqZmjG0WARKxWRQi8xMZRXGL5
          HseY7wOE4ArLBTcs/QtkIXc0heqZm1HyoV5h2sJ120BXdiJR6UEyM1g10CuzQWfZ
          BwjlcgYBFHM=
          =vGrW
          -----END PGP PRIVATE KEY BLOCK-----
          ";

        $res = gnupg_init();
        $rtv = gnupg_import($res, $pvtkey);
        $rtv = gnupg_adddecryptkey($res, $rtv['fingerprint'], '1234');
        $encrypted_data = "hQEMA1wZl4pStf/SAQf7BXX3Ig1FUXfNf2mELu5M4Ziya1mIwVTX8bfdA0D/yJ52 jak+4or4xyxq/yOpmcZJioXoUGOM5JnkS89O8OEjtb9NqVT0JYtB2F0FWFzlP8Bh uFOYMU82FWyR2TDeblYIswpYWNAwDsX6Tqc9bbAlyb5MeN9ElF+SUqO3e3f1ykBI j+gH/dWwTGyLOxq3owFR9RUm74BBp/CgUIo1/u5lE6drPDtimws4Abf4S/5RQu4M pBH5uHnRIuKaOpTslLp/U5JSS6zBNqs60Ryf0INFaGbx8fLbX3rakQz3pfxIEwMy atMi7aZ6OJ0QaSq0a3Dy+zdhDPbGIIO0LxBCqtjJeMk5fmHgUNyMWwcFH2i3szoH QFngV+h3nFLAD7XB/illBZxdiqexFT3TVSJB/Lmtpnp4DWgUREMJZ6Sy =Zv69";
        $enc = gnupg_decrypt($res, $encrypted_data);
        echo "Decrypted Data: " . $enc . "<br/>";
        die;










        $publicKey = file_get_contents(public_path() . '/private.asc');
        $gpg = new \gnupg();
        $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);
        $info = $gpg->import($publicKey);
        $gpg->adddecryptkey($info['fingerprint'], '1234');
        //$uploadFileContent = file_get_contents('/tmp/file-to-encrypt');
        $ds = 'hQEMA/Dqq6ZvXVgCAQf+JFIw5fy+1wkTZLCvJDmhyNdY22IKM/5PQmATxzbZ6inl
        ess9Sr625i87v6uG/0yuCBvv1vmjq3f9AflwAcEkTyjDcZVzVKdUM/KNGHe/Bu/Z
        cNIcKUG3FZ6B2T3SahSiXxs3Q6HNOxV8iSoTzDdVvqh7rEiKEAez45rFkMaTsJ+A
        TSY4zEfVygU1ozBmddB4gNgpT6AP0X5p1z28QL8Q+aIyemJZDnJoLbZvkJ6Ly/n/
        oQPpP2dtzeUFU1fNZCTeyrEMIjFTUBSzQrvOz+43WojaGHUi52fbSeSe6JH1CHL1
        8qVGsQtTiADY1sUDP4kNQYleko8m2md2iFwUjc0gk8ki13+pan2PdcJS/fmUNyWj
        6kk61Ik2VqOq8Qz52y3DOwWPrg==
        =lSiR';
        $enc = $gpg->decrypt($ds);
        echo $enc;


        die;


        // Encryption working code without uploaded key

        $pubkey = "-----BEGIN PGP PUBLIC KEY BLOCK-----
Version: BCPG C# v1.6.1.0

mQENBGIfbgEBCACR7Zdmgyu4QiR5RTXRzVcxkyI1LTsWpZm9T2jmRDMw1/L3wscm
Exs/O+wlA68pT3iHqHoMMzmoUfchgTB6hFsUwMH9CXr5rYidyaJvRXhVVTECoTsG
59+N2X3vJ96cu8MYNM7QImJPFw6ytYNCbVtK6uEUDFK5B0uueN2AlJdFQ4TdWWjo
z4x+EHRdOmjHbYFyjihiFl5dYHdrzSNLman43I+/kD6oYjEBDBX/T0iEQkUFVVQJ
Jh2oLQXDtqwWdijau/uCL1SVszQObT6K9DV7Y5x1UV7zfqDWgbMXKaRHtJNlV9Io
LS25VSYIwU9HPY5trh3ATAWx3PQXJLhUmWypABEBAAG0FGJob29wZW5kcmFAZ21h
aWwuY29tiQEcBBABAgAGBQJiH24BAAoJEBdG4gk9JZFS4e4IAIH7w5BC5vJPCfds
E4mrmQQUOn5FI3VaiNY+5Y4XqSqISUi2/dPByOrUWHg/Ayyt/Hm2sTsCnMdsqTdo
EeGyN5tbpGNwm0mr1wv5gWTJE+QGjguS8Pi4jgCLawgzVpM2zDAMbeTxeFsLZBQU
4ip93JZA+6lZVl0jYFqvPrSoqd6KfvD0Uya0ldZ4ogDm6hGF61QXt38dPCZu6T2v
kPKawKFPKK5aXBoN49EU1jz7xiShjNLdY2UkubXsi4h5sv/MPS2/poDG4TUvB+ab
xClC3U6J6IRmO1oVFs3MrodglDVt0CRnfQ8NkKOgSkt2vnV+bwdV/WbQFyomI2tS
cDRrW08=
=EGYX
-----END PGP PUBLIC KEY BLOCK-----
";

        $res = gnupg_init();
        $rtv = gnupg_import($res, $pubkey);
        $rtv = gnupg_addencryptkey($res, $rtv['fingerprint']);
        $enc = gnupg_encrypt($res, "hiii, this, is, bhoopendra");
        echo "Encrypted Data: " . $enc . "<br/>";
        die;













        // Encryption working code upload key
        $publicKey = file_get_contents(public_path() . '/public.asc');
        $gpg = new \gnupg();
        $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);
        $info = $gpg->import($publicKey);
        $gpg->addencryptkey($info['fingerprint']);
        //$uploadFileContent = file_get_contents('/tmp/file-to-encrypt');
        $enc = $gpg->encrypt('This is a test!');
        echo $enc;


        die;


        $gpg = new \gnupg();
        //$info = $gpg -> import(public_path().'/public.asc');
        $info = $gpg->import('https://esb-stag.apiworx.net/public/public.asc');
        dd($info);
        die;

        $gpg = new \gnupg();
        $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

        // Check key ring for recipient public key, otherwise import it
        $keyInfo = $gpg->keyinfo('0DAA2C747B1974BE9EB9E6DCF7EE249AD00A46AA');
        if (empty($keyInfo)) {
            $gpg->import(public_path() . '/public.asc');
        }
        $gpg->addencryptkey('0DAA2C747B1974BE9EB9E6DCF7EE249AD00A46AA');
        echo $gpg->encrypt('This is a test!');

        die;


        $res = gnupg_init();
        gnupg_addencryptkey($res, "42670A7FE4D0441C8E4632349E4FDC074A4EF02D");
        $enc = gnupg_encrypt($res, "just a test");
        echo $enc;

        die;
        // Encrypt

        $res = gnupg_init();
        gnupg_addencryptkey($res, "-----BEGIN PGP PUBLIC KEY BLOCK-----
        Version: BCPG C# v1.6.1.0

        mQENBGIfLo4BCACYtnx/7QsEJlKcA6chSxOSNmhzF/W8ZgJCghdcl0kGIcY8eEw7
        NbCCl8pkE6h7+zZdIUYmoF14PDgoNcBFpjQjK8iJAnZrR3jOp0QOlITmJFJ7Y//t
        1c16mUbS3sl8VxVlvOEiyGvi6HuLNI5OyzEhnA3N+wtTLlSHf3n656XhkbR0/y5j
        zYYLrNEE7MjDvpebtRAJqWY1NkrAv8Rvr5w6CIFlmCyISfLBxaMRdKqT08HTuo/j
        IOWAw5FsMXJrYWMD50o+sEbDtKC1376VCFEO846UCnuaPK1MKl6Cxex1Y3ukrSPt
        g7bkiyEZdQkW711Cq++1Brb6sV9j8oc14KYdABEBAAG0GG9mZXIubWFuMTIxMkB0
        aXBhbHRpLmNvbYkBHAQQAQIABgUCYh8ujgAKCRDw6qumb11YAlUGB/9t4hQ/t68S
        Qq6YbBT1UexWjvMYCkUUiQ4lwlToOgGs6e+/4DbBRsL8dmtWSHuIy7Wn1/XZyRRt
        GAaPehatOazIZXwQjwvNdN5aHuCLv18dA+1vkkCKAEHxXdX7SxDu0jSz007jxQAj
        2hz6fa1PcFYGUESYgB7BYCtkSVpERGhysdDzhGUPmry4YHcivdeGKXTCC+cpUW0O
        lNGEnooJHbAwJt6oGVwxeXktN2Gdxspr/dm4cMwcn6yWHVStP2UP2h4f1WIOs9oX
        7p0vn2joQcHSxyjeJ+9RHc4N5hcdX5BSfJmT7WQrGM+6JRUU3UVFe6KNYIXmqTkv
        f16SGX3vHVSm
        =MMX9
        -----END PGP PUBLIC KEY BLOCK-----
        ");
        $enc = gnupg_encrypt($res, "just a test");
        echo $enc;
        die;


        $gpg = new \gnupg();
        $gpg->addencryptkey("mQENBGIfLo4BCACYtnx/7QsEJlKcA6chSxOSNmhzF/W8ZgJCghdcl0kGIcY8eEw7
        NbCCl8pkE6h7+zZdIUYmoF14PDgoNcBFpjQjK8iJAnZrR3jOp0QOlITmJFJ7Y//t
        1c16mUbS3sl8VxVlvOEiyGvi6HuLNI5OyzEhnA3N+wtTLlSHf3n656XhkbR0/y5j
        zYYLrNEE7MjDvpebtRAJqWY1NkrAv8Rvr5w6CIFlmCyISfLBxaMRdKqT08HTuo/j
        IOWAw5FsMXJrYWMD50o+sEbDtKC1376VCFEO846UCnuaPK1MKl6Cxex1Y3ukrSPt
        g7bkiyEZdQkW711Cq++1Brb6sV9j8oc14KYdABEBAAG0GG9mZXIubWFuMTIxMkB0
        aXBhbHRpLmNvbYkBHAQQAQIABgUCYh8ujgAKCRDw6qumb11YAlUGB/9t4hQ/t68S
        Qq6YbBT1UexWjvMYCkUUiQ4lwlToOgGs6e+/4DbBRsL8dmtWSHuIy7Wn1/XZyRRt
        GAaPehatOazIZXwQjwvNdN5aHuCLv18dA+1vkkCKAEHxXdX7SxDu0jSz007jxQAj
        2hz6fa1PcFYGUESYgB7BYCtkSVpERGhysdDzhGUPmry4YHcivdeGKXTCC+cpUW0O
        lNGEnooJHbAwJt6oGVwxeXktN2Gdxspr/dm4cMwcn6yWHVStP2UP2h4f1WIOs9oX
        7p0vn2joQcHSxyjeJ+9RHc4N5hcdX5BSfJmT7WQrGM+6JRUU3UVFe6KNYIXmqTkv
        f16SGX3vHVSm
        =MMX9");
        $enc = $gpg->encrypt("just a test");
        echo $enc;
        die;






        phpinfo();
        die;








        $user_id = 150;
        $user_integration_id = 309;
        $platform_workflow_rule_id = 69;
        $user_workflow_rule_id = 487;
        $sync_status = 'Ready';
        $source_platform_id = 'brightpearl';
        $response = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetPOGoodsInNotes($user_id, $user_integration_id, $user_workflow_rule_id, ['goodsinnote'], 3, 0, $source_platform_id, "Pending");


        die;


        $user_id = 147;
        $user_integration_id = 273;
        $platform_workflow_rule_id = 83;
        $user_workflow_rule_id = 463;

        $this->TipaltiGetPayment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO');
        die;


        $user_id = 147;
        $user_integration_id = 273;
        $platform_workflow_rule_id = 70;
        $user_workflow_rule_id = 433;
        $sync_status = 'Ready';
        $source_platform = 'tipalti';

        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreatePurchaseOrderInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform, $sync_status, '');

        die;

        $user_id = 147;
        $user_integration_id = 273;
        $platform_workflow_rule_id = 70;
        $user_workflow_rule_id = 433;

        $this->TipaltiGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO');
        die;






        $user_id = 147;
        $user_integration_id = 273;
        $platform_workflow_rule_id = 68;
        $user_workflow_rule_id = 431;

        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';



        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetOrdersByType($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'tipalti', $is_initial_sync = 0, $type = 'purchase_orders');



        //$this->TipaltiCreateUpdateGRN($user_id,$source_platform,$user_integration_id,$platform_workflow_rule_id,$user_workflow_rule_id,$sync_status,$record_id = '');

        //$this->TipaltiCreateUpdatePO($user_id,$source_platform,$user_integration_id,$platform_workflow_rule_id,$user_workflow_rule_id,$order_type,$sync_status,$record_id = '');
        die;

        $user_id = 147;
        $user_integration_id = 73;
        $platform_workflow_rule_id = 62;
        $user_workflow_rule_id = 345;

        $this->TipaltiGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO');
        die;


        $user_id = 147;
        $user_integration_id = 73;
        $platform_workflow_rule_id = 24;
        $user_workflow_rule_id = 104;
        $is_initial_sync = 1;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';

        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetPOGoodsInNotes($user_id, $user_integration_id, ['goodsinnote'], 1, 0, $source_platform, "Pending");

        die;


        /*
        $ftp_server = 'ftp.scanning.ie';
        $ftp_username = 'Cotterill_Civils';
        $ftp_userpass = '1v771b3Z@!';
       */
        $ftp_server = 'ftp.scanning.ie';
        $ftp_username = 'Cotterill_Civils';
        $ftp_userpass = '1v771b3Z@!';

        echo "<br/>" . $this->mobj->encryptString($ftp_username);
        echo "<br/>" . $this->mobj->encryptString($ftp_userpass);
        die;


        $sftp = new SFTP($ftp_server);

        if (!$sftp->login($ftp_username, $ftp_userpass)) {
            throw new Exception('Login failed');
        } else {
            $sftp->chdir('/TEST_DATA_IN');

            $temp_file_name = "tipalti_po.csv";
            $original_file_name = "PO-" . date('Y-m-d') . ".csv";

            $TempFile = public_path() . '/' . $temp_file_name;
            $file = fopen($TempFile, "w");

            $OFileName = "/" . $original_file_name;
            $OriginalFile = $OFileName;

            $data = array();
            $data[0][] = 'A';
            $data[0][] = 'B';
            $data[0][] = 'C';
            $data[0][] = 'D';
            foreach ($data as $line) {
                fputcsv($file, $line);
            }

            fclose($file);
            $sftp->chdir('/TEST_DATA_IN');
            $result = $sftp->put($original_file_name, $TempFile, SFTP::SOURCE_LOCAL_FILE);
            dd($result);

            //unlink($TempFile);





        }
        die;


        $ftp_conn = ssh2_connect($ftp_server, 22);

        if ($ftp_conn) {
            $login = @ftp_login($ftp_conn, $ftp_username, $ftp_userpass);

            // turn passive mode on
            ftp_pasv($ftp_conn, true);

            if ($login) {
                echo "Success";
            } else {
                echo "Fail 2";
            }
        } else {
            echo "Fail 1";
        }

        die;

        //For BP

        $user_id = 137;
        $user_integration_id = 45;
        $platform_workflow_rule_id = 6;
        $user_workflow_rule_id = 86;
        $is_initial_sync = 1;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';


        /*
        $response = app('App\Http\Controllers\Brightpearl\BrightpearlApiController')->SearchPurchaseOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,$is_initial_sync);


        $response = app('App\Http\Controllers\Brightpearl\BrightpearlApiController')->GetPurchaseOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,$is_initial_sync);
        //echo "<pre>";
        //print_r($response);
        */

        $response = $this->GetShipment($user_id, $user_integration_id, ['shipment'], 1, $is_initial_sync);
        // $response = $this->ProcessShipmentInfomation($user_id, $source_platform_id, $user_integration_id, "Pending");
        die;
    }
}
