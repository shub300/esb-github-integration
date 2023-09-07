<?php

namespace App\Http\Controllers\Kefron;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\Logger;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Kefron\KefronUtility;
use Illuminate\Support\Facades\Session;
use phpseclib3\Net\SFTP;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformUrl;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use Lang;

class KefronApiController extends Controller
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
        $this->utikefron = new KefronUtility();
        $this->my_platform = 'kefron';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    // Kefron FTP Auth View Page
    public function InitiateKefronAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.kefron_auth", compact('platform'));
    }


    // Kefron FTP Auth
    public function ConnectKefronOauth(Request $request)
    {
        try {

            $account_name = trim($request->account_name);
            $ftp_server = trim($request->host_name);
            $ftp_username = trim($request->user_name);
            $ftp_userpass = trim($request->password);

            if ($this->mobj->checkHtmlTags($request->all())) {
                Session::put('auth_msg', Lang::get('tags.validate'));
                return redirect()->back();
            }

            if ($this->disAllowColonOnInput($request->all())) {
                Session::put('auth_msg', 'Invalid Data retrieved.');
                return redirect()->back();
            }

            $user_data =  Session::get('user_data');
            $user_id =  $user_data['id'];

            $sftp = new SFTP($ftp_server);

            if (!$sftp->login($ftp_username, $ftp_userpass)) {
                Session::put('auth_msg', 'Authentication Error');
            } else {
                $OauthData = [
                    'api_domain' => $ftp_server,
                    'app_id' => $this->mobj->encrypt_decrypt($ftp_username, 'encrypt'),
                    'app_secret' => $this->mobj->encrypt_decrypt($ftp_userpass, 'encrypt'),
                    'account_name' => $account_name,
                    'user_id' => $user_id,
                    'platform_id' => $this->my_platform_id,
                    'allow_refresh' => 0
                ];

                $ufound =  $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'account_name' => $account_name], ['id']);
                if ($ufound) {
                    $this->mobj->makeUpdate('platform_accounts', $OauthData, ['id' => $ufound->id]);
                } else {
                    $this->mobj->makeInsert('platform_accounts', $OauthData);
                }
            }

            echo '<script>window.close();</script>';
        } catch (\Exception $e) {
            Session::put('auth_msg', $e->getMessage());
        }
    }

    public function disAllowColonOnInput(array $inputs)
    {
        foreach ($inputs as $input) {
            if (strpos($input, ':') !== false) {
                return 1;
            }
        }
        return 0;
    }

    // Kefron FTP Connection Check
    public function KefronEstablishConnection($user_id)
    {

        $acc_detail = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $this->my_platform_id, 'user_id' => $user_id]);
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


    // Upload CSV File To Kefron FTP Using Proxy Server
    public function KefronUploadFileToSFTP($user_id, $user_integration_id, $temp_file_name = null, $original_file_name = null, $data = [], $access_folder)
    {

        try {

            $response = "";
            $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->my_platform_id, ['api_domain', 'app_id', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($acc_detail) {

                $sftp_server = $acc_detail->api_domain;
                $sftp_username = $this->mobj->encrypt_decrypt($acc_detail->app_id, 'decrypt');
                $sftp_userpass = $this->mobj->encrypt_decrypt($acc_detail->app_secret, 'decrypt');
                $sftp_upload_folder = $access_folder;


                $sftp = new SFTP($sftp_server);

                if (!$sftp->login($sftp_username, $sftp_userpass)) {
                    $response = "Could not connect to server";
                } else {

                    $file_path = public_path() . '/esb_asset/kefron/' . $user_integration_id;
                    if (!file_exists($file_path)) {
                        mkdir($file_path, 0777, true);
                    }

                    $TempFile = $file_path . '/' . $original_file_name;

                    $file = fopen($TempFile, "w");

                    foreach ($data as $line) {
                        fputcsv($file, $line);
                    }

                    fclose($file);

                    $sftp->chdir($sftp_upload_folder);
                    $result = $sftp->put($original_file_name, $TempFile, SFTP::SOURCE_LOCAL_FILE);

                    if ($result) {
                        $response = 'Success';
                    } else {
                        $response = "There was a problem while uploading.";
                    }

                    /*
                    //we will open it when everything running fine
                    if (file_exists($file_as_temp)) {
                        unlink($file_as_temp);
                    }*/
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronUploadFileToSFTP-->" . $e->getMessage());
            $response = $e->getMessage();
        }

        return $response;
    }



    public function GetSFTPData($account, $user_id, $user_integration_id, $order_type = 'PO', $sftp_folder = null, $sftp_archived_folder = null, $type = "Invoice")
    {

        if ($account) {

            $sftp_server = $account->api_domain;
            $sftp_username = $this->mobj->encrypt_decrypt($account->app_id, 'decrypt');
            $sftp_userpass = $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt');
            $is_sftp_file_move_to_archive = false;



            $sftp = new SFTP($sftp_server);

            if (!$sftp->login($sftp_username, $sftp_userpass)) {
                \Log::error($user_integration_id . "--Kefron GetSFTPData--> Could not connect to server");
            } else {

                $res_sftp_change = $sftp->chdir($sftp_folder);
                if ($res_sftp_change) {

                    $files = $sftp->nlist();

                    if (count($files) > 0) {
                        foreach ($files as $original_file_name) {

                            $extension = substr($original_file_name, -3);

                            if ($extension == 'csv' || $extension == 'CSV') {

                                $file_path = public_path() . '/esb_asset/kefron/' . $user_integration_id;
                                if (!file_exists($file_path)) {
                                    mkdir($file_path, 0777, true);
                                }
                                if ($type == 'Invoice') {
                                    $new_file_name = "INV-" . time() . "-" . date('Y-m-d') . ".csv";
                                } else if ($type == 'Payment') {
                                    $new_file_name = "PAYMENT-" . time() . "-" . date('Y-m-d') . ".csv";
                                }

                                $file = $file_path . '/' . $new_file_name;

                                $get = $sftp->get($original_file_name);
                                file_put_contents($file, $get);

                                if ($type == 'Invoice') {
                                    $this->KefronProxyGetPOInvoiceFiles(['file' => $file, 'order_type' => $order_type, 'user_integration_id' => $user_integration_id, 'user_id' => $user_id]);
                                } else if ($type == 'Payment') {
                                    $this->KefronProxyGetNonPOInvoiceFiles(['file' => $file, 'order_type' => $order_type, 'user_integration_id' => $user_integration_id, 'user_id' => $user_id]);
                                }

                                if ($is_sftp_file_move_to_archive) {
                                    $exist_file = $sftp->get($original_file_name);
                                    $result = $sftp->put($sftp_archived_folder . '/' . time() . '_' . $original_file_name, $exist_file);
                                }
                                $sftp->delete($original_file_name);

                                /*
                                //we will open it when everything running fine
                                if (file_exists($file)) {
                                    unlink($file);
                                }
                                */
                            }
                        }
                    }
                } else {
                    \Log::error($user_integration_id . "--Kefron " . $type . " GetSFTPData--> Access folder not found");
                }
            }
        }
    }




    // Event Excution For Kefron
    public function ExecuteEventKefron($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        $response = true;
        ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.

        if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $sync_status = 'Ready';
            $order_type = 'PO';
            $response = $this->KefronCreateUpdatePO($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id);
        } else if ($method == 'MUTATE' && $event == 'GRN') {
            $sync_status = 'Ready';
            $response = $this->KefronCreateUpdateGRN($user_id, $source_platform_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $sync_status, $record_id);
        } else if ($method == 'GET' && $event == 'PURCHASEORDERINVOICE') {
            $order_type = 'PO';
            $response = $this->KefronGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type);
        } else if ($method == 'GET' && $event == 'NONPURCHASEINVOICEPAYMENT') {
            $order_type = 'PO';
            $response = $this->KefronGetNonPOInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type);
        }

        return $response;
    }

    // Creating Purchase order to Kefron FTP
    public function KefronCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {

            $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
            $sync_object_id = $this->helper->getObjectId('purchase_order');
            $CustomDataPOFolder = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "po_access_folder", ['custom_data'], "default");
            $sftp_folder = '/';
            if ($CustomDataPOFolder) {
                $sftp_folder = @$CustomDataPOFolder->custom_data;
            }
            $process_limit = 30;
            $offset = 0;

            $tax_object_id = $this->helper->getObjectId('taxcode');
            $shipping_object_id = $this->helper->getObjectId('shipping_method');



            // do{

            $allow_next_call = false; // This flag will help for pagination


            if ($record_id != '') {

                $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type, 'id' => $record_id], ['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'total_amount', 'total_tax', 'total_discount', 'net_amount', 'shipping_method', 'platform_customer_id'], ['id' => 'asc'], $process_limit, $offset);
            } else {

                $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => $order_type, 'sync_status' => $sync_status], ['id', 'api_order_id', 'order_number', 'currency', 'order_date', 'customer_email', 'total_amount', 'total_tax', 'total_discount', 'net_amount', 'shipping_method', 'platform_customer_id'], ['id' => 'asc'], $process_limit, $offset);
            }

            if (count($result_order) == $process_limit) {
                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                $offset += $process_limit;
            }

            //echo "<pre>";
            //print_r($result_order);
            //die;

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

                    $shipping_method = $roworder->shipping_method;

                    $customer_name = '';
                    $customer_id = '';
                    if ($roworder->platform_customer_id != '') {
                        $result_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $roworder->platform_customer_id], ['customer_name', 'api_customer_id', 'company_name']);
                        //$customer_name = @$result_customer->customer_name;
                        //if(trim($customer_name)==''){
                        $customer_name = @$result_customer->company_name;
                        //}

                        $customer_id = @$result_customer->api_customer_id;
                    }


                    /*$shipping_method_name = '';
                         if($shipping_object_id!='' && $roworder->shipping_method!='0' && $roworder->shipping_method!=''){
                             $object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$source_platform_id,'platform_object_id' => $shipping_object_id,'api_id' => $roworder->shipping_method], ['name']);

                             $shipping_method_name = @$object_data->name;
                         }*/


                    $result_order_line = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $id], ['product_name', 'sku', 'qty', 'unit_price', 'total', 'taxes', 'subtotal', 'total_tax', 'api_code', 'api_order_line_id']);


                    if (count($result_order_line) > 0) {

                        $platform_order_ids[] = $roworder->id;

                        $is_found_tracked_item = 0;

                        foreach ($result_order_line as $rowline) {

                            $tax_code = '';
                            if ($tax_object_id != '' && $rowline->taxes != '0' && $rowline->taxes != '') {
                                $object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $tax_object_id, 'api_id' => $rowline->taxes], ['api_code']);

                                $tax_code = @$object_data->api_code;
                            }

                            $li_total = @$rowline->total ? $rowline->total : 0;
                            $li_total_tax = @$rowline->total_tax ? $rowline->total_tax : 0;

                            $li_line_total = floatval($li_total) + floatval($li_total_tax);

                            $order_date_new = "";
                            if ($order_date != '') {
                                $arrd = explode('T', $order_date);
                                $o_date = \DateTime::createFromFormat('Y-m-d', $arrd[0]);
                                $order_date_new = $o_date->format('d/m/Y');
                            }

                            if ($rowline->sku != '') {
                                $is_found_tracked_item = 1;
                            }

                            $po_data[$ct_index]['supplier_name'] = $customer_name;
                            $po_data[$ct_index]['supplier_code'] = $customer_id;
                            $po_data[$ct_index]['po_number'] = $api_order_id;
                            $po_data[$ct_index]['order_date'] = $order_date_new;
                            $po_data[$ct_index]['currency'] = $currency;
                            $po_data[$ct_index]['net_total'] = $net_amount;
                            $po_data[$ct_index]['tax_total'] = $total_tax;
                            $po_data[$ct_index]['discount'] = $total_discount;
                            $po_data[$ct_index]['order_total'] = $total_amount;

                            $po_data[$ct_index]['li_productcode'] = $rowline->sku;
                            $po_data[$ct_index]['li_description'] = $rowline->product_name;
                            $po_data[$ct_index]['li_gl_code'] = $rowline->api_code;
                            $po_data[$ct_index]['li_quantity'] = $rowline->qty;
                            $po_data[$ct_index]['li_unit_price'] = $rowline->unit_price;
                            $po_data[$ct_index]['li_net'] = $li_total;
                            $po_data[$ct_index]['li_vat_code'] = $tax_code;
                            $po_data[$ct_index]['li_tax'] = $li_total_tax;
                            $po_data[$ct_index]['li_line_total'] = $li_line_total;

                            $ct_index++;
                        }

                        if ($is_found_tracked_item == 0) { //for all non tracked item we are adding default shipment

                            $shipment_id = $order_number . "-1";
                            $shipmentinfo = [];
                            $shipmentinfo['user_id'] = $user_id;
                            $shipmentinfo['platform_id'] = $source_platform_id;
                            $shipmentinfo['user_integration_id'] = $user_integration_id;
                            $shipmentinfo['platform_order_id'] = $id;
                            $shipmentinfo['order_id'] = $order_number;
                            $shipmentinfo['order_number'] = $order_number;
                            $shipmentinfo['shipment_id'] = $shipment_id;
                            $shipmentinfo['created_on'] = $order_date;
                            $shipmentinfo['realease_date'] = $order_date;
                            $shipmentinfo['type'] = 'POShipment';
                            $shipmentinfo['sync_status'] = 'Ready';



                            $findShipment = PlatformOrderShipment::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $id, 'shipment_id' => $shipment_id])->select('id')->first();
                            $shipmentPrimaryID = "";
                            if (empty($findShipment)) {
                                $saveShipment = PlatformOrderShipment::create($shipmentinfo);
                                $shipmentPrimaryID = isset($saveShipment->id) ? $saveShipment->id : null;

                                if ($shipmentPrimaryID) {
                                    $shipmentlines = [];
                                    foreach ($result_order_line as $rowline) {

                                        $shipmentlineinfo = [];
                                        $shipmentlineinfo['platform_order_shipment_id'] = $shipmentPrimaryID;
                                        $shipmentlineinfo['row_id'] = $rowline->api_order_line_id;
                                        $shipmentlineinfo['product_id'] = $rowline->api_code;
                                        $shipmentlineinfo['sku'] = $rowline->sku;
                                        $shipmentlineinfo['price'] = $rowline->unit_price;
                                        $shipmentlineinfo['quantity'] = $rowline->qty;
                                        $shipmentlineinfo['user_batch_reference'] = $rowline->product_name;

                                        $shipmentlines[] = $shipmentlineinfo;
                                    }
                                    PlatformOrderShipmentLine::insert($shipmentlines);

                                    PlatformOrder::where('id', $id)->update(['shipment_status' => 'Ready']);
                                }
                            }
                        }
                    } else {

                        if ($source_platform == 'brightpearl') {

                            $ct_url = PlatformUrl::where(['status' => 1, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'url_name' => 'purchase_orders', 'response' => 'reattempt', 'url' => '/order/' . $api_order_id])->count();
                            if ($ct_url < 4) {

                                PlatformUrl::insert(['url' => '/order/' . $api_order_id, 'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'url_name' => 'purchase_orders', 'response' => 'reattempt']);

                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Pending'], ['id' => $id]);
                            } else {
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
                                $return_response = $sync_error = "Line items Missing.";
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                            }
                        } else {

                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
                            $return_response = $sync_error = "Line items Missing.";
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                        }
                    }
                }


                if ($ct_index > 1) {

                    //Get Formatted CSV File
                    $data = $this->utikefron->GetStructuredPOPostData($po_data);

                    $temp_file_name = "kefron_po.csv";
                    $original_file_name = "PO_" . date('Ymd_Hmsv') . time() . ".csv";
                    $response = $this->KefronUploadFileToSFTP($user_id, $user_integration_id, $temp_file_name, $original_file_name, $data, $sftp_folder);


                    // Maintain Logs
                    if ($response === 'Success') {
                        foreach ($platform_order_ids as $id) {

                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s'), 'file_name' => $original_file_name], ['id' => $id]);

                            $sync_error = null;
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $id, $sync_error);
                        }
                    } else {
                        $return_response = $sync_error = 'Connection Failed.';
                        foreach ($platform_order_ids as $id) {

                            $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $id, $sync_error);
                        }
                    }
                }
            }

            // }while($allow_next_call);


        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronCreateUpdatePO-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }




    // Creating GRN to Kefron FTP
    public function KefronCreateUpdateGRN($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $sync_status, $record_id = '')
    {
        $this->mobj->AddMemory();
        $return_response = true;

        try {

            $source_platform_id = $this->helper->getPlatformIdByName($source_platform);
            $sync_object_id = $this->helper->getObjectId('accept_order');

            $CustomDataGRNFolder = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "grn_access_folder", ['custom_data'], "default");
            $sftp_folder = '/';
            if ($CustomDataGRNFolder) {
                $sftp_folder = @$CustomDataGRNFolder->custom_data;
            }


            $process_limit = 30;
            $offset = 0;


            $ChangesStatusAllowed = $this->map->getMappedDataByName($user_integration_id, null, "default_porder_status", ['name'], "regular", null, "multiple", "source", ['api_id', 'name']);
            if (!is_array($ChangesStatusAllowed)) {
                $ChangesStatusAllowed = [];
            }


            //do{

            $allow_next_call = false; // This flag will help for pagination

            $wheredata = ['pos.user_id' => $user_id, 'pos.user_integration_id' => $user_integration_id, 'pos.platform_id' => $source_platform_id, 'pos.type' => 'POShipment'];

            if ($record_id != '') {
                $wheredata['po.id'] = $record_id;
            } else {
                $wheredata['po.shipment_status'] = $sync_status;
            }

            $result_goods = DB::table('platform_order_shipments as pos')
                ->join('platform_order as po', 'pos.platform_order_id', '=', 'po.id')
                ->where($wheredata)->whereIn('pos.sync_status', ['Ready', 'Failed'])->whereIn('po.order_status', $ChangesStatusAllowed)->select('pos.id', 'pos.platform_order_id', 'pos.order_id', 'pos.shipment_id', 'pos.created_on', 'po.order_date', 'po.customer_email', 'po.order_number', 'po.platform_customer_id')->orderBy('pos.id', 'asc')->skip($offset)->take($process_limit)->get();



            if (count($result_goods) == $process_limit) {
                $allow_next_call = true; // Make it false as well if we want to avoid contineous loop
                $offset += $process_limit;
            }



            $ct_index = 1;
            $platform_goods_ids = $platform_order_ids = $goods_data = $failed_order_logs = [];
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


                    if (substr($shipment_id, -2) !== "-1") { // format used on order for default shipment for all non tracked items

                        // Logic For tracked & nontracked item both to sync with first Goods in note. Attaching all nontracked to first shipment with tracked of order

                        $existing_goods = PlatformOrderShipment::where('platform_order_id', $platform_order_id)->select('sync_status', 'id')->orderBy('id', 'asc')->first();
                        if ($existing_goods->sync_status == 'Ready') {
                            //process for non-tracked item entry

                            $list_order_line = PlatformOrderLine::where('platform_order_id', $platform_order_id)->select('product_name', 'sku', 'qty', 'unit_price', 'total', 'taxes', 'subtotal', 'total_tax', 'api_code', 'api_order_line_id')->where('sku', '')->get();
                            if (count($list_order_line) > 0) {

                                $shipmentlines = [];
                                foreach ($list_order_line as $orderline) {

                                    $ct_goods_line = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $existing_goods->id, 'row_id' => $orderline->api_order_line_id])->select('id')->count();
                                    if ($ct_goods_line == 0) {

                                        $shipmentlineinfo = [];
                                        $shipmentlineinfo['platform_order_shipment_id'] = $existing_goods->id;
                                        $shipmentlineinfo['row_id'] = $orderline->api_order_line_id;
                                        $shipmentlineinfo['product_id'] = $orderline->api_code;
                                        $shipmentlineinfo['sku'] = $orderline->sku;
                                        $shipmentlineinfo['price'] = $orderline->unit_price;
                                        $shipmentlineinfo['quantity'] = $orderline->qty;
                                        $shipmentlineinfo['user_batch_reference'] = $orderline->product_name;

                                        $shipmentlines[] = $shipmentlineinfo;
                                    }
                                }


                                if (count($shipmentlines) > 0) {
                                    PlatformOrderShipmentLine::insert($shipmentlines);
                                }
                            }
                        }
                    }






                    $customer_name = '';
                    $customer_id = '';
                    if ($rowgoods->platform_customer_id != '') {
                        $result_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $rowgoods->platform_customer_id], ['customer_name', 'api_customer_id', 'company_name']);
                        //$customer_name = @$result_customer->customer_name;
                        //if(trim($customer_name)==''){
                        $customer_name = @$result_customer->company_name;
                        // }
                        $customer_id = @$result_customer->api_customer_id;
                    }


                    $result_goods_line = DB::table('platform_order_shipment_lines as posl')
                        ->leftJoin('platform_order_line as pol', function ($join) use ($platform_order_id) {
                            $join->on('pol.api_product_id', '=', 'posl.product_id');
                            $join->where('pol.platform_order_id', '=', $platform_order_id);
                        })->where(['platform_order_shipment_id' => $id])->select('posl.id', 'posl.row_id', 'posl.product_id', 'posl.location_id', 'posl.currency', 'posl.price', 'posl.quantity', 'pol.sku', 'pol.product_name', 'pol.sku', 'pol.qty as ol_qty', 'pol.total_tax as ol_total_tax', 'pol.api_code', 'posl.user_batch_reference')->get();


                    if (count($result_goods_line) > 0) {

                        $platform_goods_ids[] = $rowgoods->id;
                        $platform_order_ids[$rowgoods->id] = $rowgoods->platform_order_id;

                        foreach ($result_goods_line as $rowline) {
                            $quantity = @$rowline->quantity ? $rowline->quantity : 0;
                            $price = @$rowline->price ? $rowline->price : 0;
                            $ol_total_tax = @$rowline->ol_total_tax ? $rowline->ol_total_tax : 0;
                            $ol_qty = @$rowline->ol_qty ? $rowline->ol_qty : 0;
                            $product_name = @$rowline->product_name ? @$rowline->product_name : @$rowline->user_batch_reference;

                            $line_net_total = floatval($quantity) * floatval($price);
                            $line_total_tax = 0;
                            if ($ol_qty > 0) {
                                $line_total_tax = floatval($quantity) * (floatval($ol_total_tax) / floatval($ol_qty));
                            }

                            $order_date_new = $created_on_new = "";
                            if ($order_date != '') {
                                $arrd = explode('T', $order_date);
                                $o_date = \DateTime::createFromFormat('Y-m-d', $arrd[0]);
                                $order_date_new = $o_date->format('d/m/Y');
                            }

                            if ($created_on != '') {
                                $arrco = explode('T', $created_on);
                                $co_date = \DateTime::createFromFormat('Y-m-d', $arrco[0]);
                                $created_on_new = $co_date->format('d/m/Y');
                            }


                            $goods_data[$ct_index]['supplier_name'] = $customer_name;
                            $goods_data[$ct_index]['supplier_code'] = $customer_id;
                            $goods_data[$ct_index]['po_number'] = $order_id;
                            $goods_data[$ct_index]['order_date'] = $order_date_new;
                            $goods_data[$ct_index]['delivery_date'] = $created_on_new;

                            $goods_data[$ct_index]['li_productcode'] = $rowline->sku;
                            $goods_data[$ct_index]['li_description'] = $product_name;
                            $goods_data[$ct_index]['li_gl_code'] = $rowline->api_code;
                            $goods_data[$ct_index]['li_quantity'] = $quantity;
                            $goods_data[$ct_index]['li_unit_price'] = $price;
                            $goods_data[$ct_index]['li_net'] = $line_net_total;
                            $goods_data[$ct_index]['li_tax'] = $line_total_tax;
                            $goods_data[$ct_index]['li_line_total'] = $line_net_total + $line_total_tax;

                            $ct_index++;
                        }
                    } else {

                        $return_response = $sync_error = "Line items Missing.";
                        $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $id]);
                        $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_id]);
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $platform_order_id, $sync_error);

                        $failed_order_logs[$platform_order_id] = $sync_error;
                    }
                }


                if ($ct_index > 1) {


                    //Get Formatted CSV File
                    $data = $this->utikefron->GetStructuredGRNPostData($goods_data);


                    $temp_file_name = "kefron_grn.csv";
                    $original_file_name = "GRN_" . date('Ymd_Hmsv') . time() . ".csv";
                    $response = $this->KefronUploadFileToSFTP($user_id, $user_integration_id, $temp_file_name, $original_file_name, $data, $sftp_folder);


                    // Maintain Logs
                    if ($response === 'Success') {
                        foreach ($platform_goods_ids as $id) {

                            $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Synced', 'shipment_file_name' => $original_file_name], ['id' => $id]);

                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $platform_order_ids[$id]]);



                            $sync_error = null;
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'success', $platform_order_ids[$id], $sync_error);
                        }
                    } else {
                        $return_response = $sync_error = 'Connection Failed.';

                        foreach ($platform_goods_ids as $id) {

                            $failed_order_logs[$platform_order_id] = $sync_error;

                            $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $id]);

                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_ids[$id]]);

                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $platform_order_ids[$id], $sync_error);
                        }
                    }
                }
            }


            foreach ($failed_order_logs as $pid => $error) {
                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $pid]);

                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $sync_object_id, 'failed', $pid, $error);
            }


            // }while($allow_next_call);



        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronCreateUpdateGRN-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }





    public function KefronGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {


            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'app_id', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($account) {

                $CustomDataInvoiceFolder = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "invoice_access_folder", ['custom_data'], "default");
                $sftp_folder = '/';
                if ($CustomDataInvoiceFolder) {
                    $sftp_folder = @$CustomDataInvoiceFolder->custom_data;
                }

                $CustomDataArchivedFolder = $this->map->getMappedDataByName($user_integration_id, null, "archived_access_folder", ['custom_data'], "default");
                $sftp_archived_folder = '/';
                if ($CustomDataArchivedFolder) {
                    $sftp_archived_folder = @$CustomDataArchivedFolder->custom_data;
                }

                $this->GetSFTPData($account, $user_id, $user_integration_id, $order_type, $sftp_folder, $sftp_archived_folder, 'Invoice');
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronGetInvoice-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }


    public function KefronProxyGetPOInvoiceFiles($process_data = [])
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {


            $user_id = $process_data['user_id'];
            $user_integration_id = $process_data['user_integration_id'];
            $order_type = $process_data['order_type'];
            $file = $process_data['file'];


            $fileData = fopen($file, 'r');

            $order_ids = $contact_ids = $invoice_refs = $txn_dates = $descriptions = $currencies = $exchange_rates = $net_amounts = $tax_amounts = $nominal_codes = $tax_codes = array();

            $ct_line = 0;
            while (($line = fgetcsv($fileData)) !== FALSE) {

                if ($ct_line > 0) {

                    $contact_id = $line[0];
                    $invoice_ref = $line[1];
                    $txn_date = $line[2];
                    $description = $line[3];
                    $currency = $line[4];
                    $exchange_rate = $line[5];
                    $net_amount = $line[6] ? $line[6] : 0;
                    $tax_amount = $line[7] ? $line[7] : 0;
                    $nominal_code = $line[8];
                    $tax_code = $line[9];
                    $order_id = $line[10];

                    $order_ids[] = $order_id;
                    $contact_ids[$order_id] = $contact_id;
                    $invoice_refs[$order_id] = $invoice_ref;
                    $txn_dates[$order_id] = $txn_date;
                    $descriptions[$order_id] = $description;
                    $currencies[$order_id] = $currency;
                    $exchange_rates[$order_id] = $exchange_rate;
                    $net_amounts[$order_id] = floatval($net_amount) + (isset($net_amounts[$order_id]) ? $net_amounts[$order_id] : 0);
                    $tax_amounts[$order_id] = floatval($tax_amount) + (isset($tax_amounts[$order_id]) ? $tax_amounts[$order_id] : 0);
                    $nominal_codes[$order_id] = $nominal_code;
                    $tax_codes[$order_id] = $tax_code;
                }

                $ct_line++;
            }

            $order_ids = array_unique($order_ids, true);



            foreach ($order_ids as $order_id) {

                //Add Order Invoice For Log
                $result_order =  $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'order_type' => $order_type, 'api_order_id' => $order_id], ['id']);
                $platform_order_id = '';
                if ($result_order) {

                    $platform_order_id = $result_order->id;


                    $arr_invoice = array();
                    $arr_invoice['user_id'] = $user_id;
                    $arr_invoice['platform_id'] = $this->my_platform_id;
                    $arr_invoice['user_integration_id'] = $user_integration_id;
                    $arr_invoice['platform_order_id'] = $platform_order_id;
                    $arr_invoice['order_doc_number'] = $order_id;
                    $arr_invoice['invoice_date'] = $txn_dates[$order_id];
                    $arr_invoice['invoice_code'] = $nominal_codes[$order_id];
                    $arr_invoice['customer_name'] = $contact_ids[$order_id];
                    $arr_invoice['ref_number'] = $invoice_refs[$order_id];
                    $arr_invoice['message'] = $descriptions[$order_id];
                    $arr_invoice['api_tax_code'] = $tax_codes[$order_id];
                    $arr_invoice['currency'] = $currencies[$order_id];
                    $arr_invoice['exchange_rate'] = $exchange_rates[$order_id];
                    $arr_invoice['net_total'] = $net_amounts[$order_id];
                    $arr_invoice['total_tax'] = $tax_amounts[$order_id];
                    $arr_invoice['api_created_at'] = date('Y-m-d H:i:s');
                    $arr_invoice['api_updated_at'] = date('Y-m-d H:i:s');
                    $arr_invoice['sync_status'] = 'Ready';


                    $inv =  $this->mobj->getFirstResultByConditions('platform_invoice', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id], ['id', 'linked_id']);


                    if ($inv) {
                        //$this->mobj->makeUpdate('platform_invoice', $arr_invoice, ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id]);

                        if ($inv->linked_id == 0) {
                            $this->mobj->makeUpdate('platform_invoice', $arr_invoice, ['id' => $inv->id]);
                            $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Ready'], ['id' => $platform_order_id]);
                        }
                    } else {
                        $this->mobj->makeInsertGetId('platform_invoice', $arr_invoice);
                        $this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Ready'], ['id' => $platform_order_id]);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronProxyGetPOInvoiceFiles-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        //return $return_response;

    }




    public function KefronGetNonPOInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO')
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'app_id', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($account) {


                $CustomDataInvoiceFolder = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "payment_access_folder", ['custom_data'], "default");
                $sftp_folder = '/';
                if ($CustomDataInvoiceFolder) {
                    $sftp_folder = @$CustomDataInvoiceFolder->custom_data;
                }


                $CustomDataArchivedFolder = $this->map->getMappedDataByName($user_integration_id, null, "archived_access_folder", ['custom_data'], "default");
                $sftp_archived_folder = '/';
                if ($CustomDataArchivedFolder) {
                    $sftp_archived_folder = @$CustomDataArchivedFolder->custom_data;
                }

                $this->GetSFTPData($account, $user_id, $user_integration_id, $order_type, $sftp_folder, $sftp_archived_folder, 'Payment');
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronGetNonPOInvoice-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }



    public function KefronProxyGetNonPOInvoiceFiles($process_data)
    {
        $this->mobj->AddMemory();
        $return_response = true;
        try {


            $user_id = $process_data['user_id'];
            $user_integration_id = $process_data['user_integration_id'];
            $order_type = $process_data['order_type'];
            $file = $process_data['file'];


            $fileData = fopen($file, 'r');
            $contact_invoice_ids = $contact_ids = $invoice_refs = $txn_dates = $descriptions = $currency_codes = $bank_account_nominal_codes = $exchange_rates = $total_amounts = array();

            $ct_line = 0;
            while (($line = fgetcsv($fileData)) !== FALSE) {

                if ($ct_line > 0) {

                    $contact_id = $line[0];
                    $invoice_ref = $line[1];
                    $txn_date = $line[2];
                    $description = $line[3];
                    $currency_code = $line[4];
                    $exchange_rate = $line[5];
                    $bank_account_nominal_code = $line[6];
                    $amount = $line[7];

                    $contact_invoice_id = $contact_id . '#@#' . $invoice_ref;
                    $contact_invoice_ids[] = $contact_invoice_id;

                    $invoice_refs[$contact_invoice_id] = $invoice_ref;
                    $contact_ids[$contact_invoice_id] = $contact_id;
                    $txn_dates[$contact_invoice_id] = $txn_date;
                    $descriptions[$contact_invoice_id] = $description;
                    $currency_codes[$contact_invoice_id] = $currency_code;

                    $bank_account_nominal_codes[$contact_invoice_id] = $bank_account_nominal_code;
                    $exchange_rates[$contact_invoice_id] = $exchange_rate;
                    $total_amounts[$contact_invoice_id] = floatval($amount) + (isset($total_amounts[$contact_invoice_id]) ? $total_amounts[$contact_invoice_id] : 0);
                }

                $ct_line++;
            }
            $contact_invoice_ids = array_unique($contact_invoice_ids, SORT_STRING);



            foreach ($contact_invoice_ids as $ci_id) {

                $result_customer = PlatformCustomer::where(['user_integration_id' => $user_integration_id, 'api_customer_id' => $contact_ids[$ci_id]])->select(['api_customer_id', 'id'])->first();
                $platform_customer_id = @$result_customer->id;

                $arr_payment = array();
                $arr_payment['platform_id'] = $this->my_platform_id;
                $arr_payment['user_integration_id'] = $user_integration_id;
                //$arr_payment['platform_order_id'] = $platform_order_id;
                $arr_payment['row_type'] = 'PAYMENT';
                $arr_payment['transaction_datetime'] = $txn_dates[$ci_id];
                $arr_payment['transaction_amount'] = $total_amounts[$ci_id];
                $arr_payment['transaction_id'] = $invoice_refs[$ci_id];
                $arr_payment['transaction_reference'] = $invoice_refs[$ci_id];
                //$arr_payment['transaction_method'] = $payment_method;
                $arr_payment['transaction_response_text'] = $descriptions[$ci_id];
                $arr_payment['exchange_rate'] = $exchange_rates[$ci_id];
                $arr_payment['currency_code'] = $currency_codes[$ci_id];
                $arr_payment['bank_account'] = $bank_account_nominal_codes[$ci_id];
                $arr_payment['platform_customer_id'] = $platform_customer_id;
                $arr_payment['api_customer_code'] = $ci_id;
                $arr_payment['sync_status'] = 'Ready';


                $ct_inv = PlatformOrderTransaction::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'transaction_reference' => $invoice_refs[$ci_id], 'platform_customer_id' => $platform_customer_id])->count();

                if ($ct_inv > 0) {

                    PlatformOrderTransaction::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'transaction_reference' => $invoice_refs[$ci_id], 'platform_customer_id' => $platform_customer_id])->update($arr_payment);
                } else {

                    PlatformOrderTransaction::insert($arr_payment);
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--KefronProxyGetNonPOInvoiceFiles-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        //return $return_response;

    }





    public function test_kefron()
    {

        die;
        DB::table('platform_order')->where('user_integration_id', 106)->whereDate('created_at', '>=', '2023-03-17')->update(['sync_status' => 'Ready', 'shipment_status' => 'Ready']);

        DB::table('platform_order_shipments')->where('user_integration_id', 106)->whereDate('created_at', '>=', '2023-03-17')->update(['sync_status' => 'Ready']);


        die;

        $user_id = 167;
        $user_integration_id = 106;
        $platform_workflow_rule_id = 64;
        $user_workflow_rule_id = 247;
        $order_type = 'PO';

        $this->KefronGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type);
        die;
        $user_id = 167;
        $user_integration_id = 106;
        $platform_workflow_rule_id = 62;
        $user_workflow_rule_id = 245;
        $is_initial_sync = 1;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';

        //$this->KefronCreateUpdateGRN($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,$sync_status,$record_id = '');
        $this->KefronCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, 2655742);
        die;




        $user_integration_id = 106;

        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  1, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

        $url = "/order-service/order/163787?includeOptional=customFields";

        $response = app('App\Helper\Api\BrightpearlApi')->GetPurchaseOrders($ufound, $url);
        $bsOrders = json_decode($response->getBody(), true);
        echo "<pre>";
        print_r($bsOrders);
        die;


        $user_id = 147;
        $user_integration_id = 73;
        $platform_workflow_rule_id = 23;
        $user_workflow_rule_id = 97;

        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';

        $this->KefronCreateUpdatePO($user_id, $source_platform, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '');
        die;
        $user_id = 147;
        $user_integration_id = 73;
        $platform_workflow_rule_id = 62;
        $user_workflow_rule_id = 345;

        $this->KefronGetInvoice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO');
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



        $ftp_server = '52.52.161.240';
        $ftp_username = 'asbdcapiworx';
        $ftp_userpass = 'mS%5]&HqGQ(g';

        echo "<br/>" . $this->mobj->encryptString($ftp_username);
        echo "<br/>" . $this->mobj->encryptString($ftp_userpass);

        die;
        /*$ftp_server = '52.52.161.240';
        $ftp_username = 'asbdcapiworx';
        $ftp_userpass = 'mS%5]&HqGQ(g';*/



        $user_id = 137;
        $user_integration_id = 45;
        $platform_workflow_rule_id = 6;
        $user_workflow_rule_id = 86;
        $is_initial_sync = 1;
        $order_type = 'PO';
        $sync_status = 'Ready';
        $source_platform = 'brightpearl';

        $this->KefronCreateUpdatePO($user_id, $source_platform, $user_integration_id, $user_workflow_rule_id, $order_type, $sync_status, $record_id = '');
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

            $temp_file_name = "kefron_po.csv";
            $original_file_name = "PO-" . date('Y-m-d') . ".csv";

            $TempFile = public_path() . '/' . $temp_file_name;
            $file = fopen($TempFile, "w");

            $OFileName = "/" . $original_file_name;
            $OrginalFile = $OFileName;

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

        //$this->KefronCreateUpdatePO($user_id,$source_platform,$user_integration_id,$user_workflow_rule_id,$order_type,$sync_status,$record_id = '');


        /*
        $response = app('App\Http\Controllers\Brightpearl\BrightpearlApiController')->SearchPurchaseOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,$is_initial_sync);


        $response = app('App\Http\Controllers\Brightpearl\BrightpearlApiController')->GetPurchaseOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id,$is_initial_sync);
        //echo "<pre>";
        //print_r($response);
        */

        $response = $this->GetShipment($user_id, $user_integration_id, ['shipment'], 1, $is_initial_sync);
        // $response = $this->ProcessShipmentInfomation($user_id, $source_platform_id, $user_integration_id, "Pending");
        die;


        //For Kefron
        $platform_id = 9;
        $field_type = 'default';
        $platform_object_id = 6;
        $platform_object_id_line_item = 60;

        $fieldlist = array();
        $fields = array();
        $fields['name'] = 'Databank';
        $fields['description'] = 'Databank';
        $fields['db_field_name'] = 'Databank';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Supplier_Name';
        $fields['description'] = 'Supplier Name';
        $fields['db_field_name'] = 'Supplier_Name';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Supplier_Code';
        $fields['description'] = 'Supplier Code';
        $fields['db_field_name'] = 'Supplier_Code';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'PO_NUMBER';
        $fields['description'] = 'PO NUMBER';
        $fields['db_field_name'] = 'PO_NUMBER';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Order_Date';
        $fields['description'] = 'Order Date';
        $fields['db_field_name'] = 'Order_Date';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Originator';
        $fields['description'] = 'Originator';
        $fields['db_field_name'] = 'Originator';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Reference';
        $fields['description'] = 'Reference';
        $fields['db_field_name'] = 'Reference';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Currency';
        $fields['description'] = 'Currency';
        $fields['db_field_name'] = 'Currency';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Net_Total';
        $fields['description'] = 'Net Total';
        $fields['db_field_name'] = 'Net_Total';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Tax_Total';
        $fields['description'] = 'Tax Total';
        $fields['db_field_name'] = 'Tax_Total';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Carriage';
        $fields['description'] = 'Carriage';
        $fields['db_field_name'] = 'Carriage';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Discount';
        $fields['description'] = 'Discount';
        $fields['db_field_name'] = 'Discount';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'Order_Total';
        $fields['description'] = 'Order Total';
        $fields['db_field_name'] = 'Order_Total';
        $fields['platform_object_id'] = $platform_object_id;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_ProductCode';
        $fields['description'] = 'Line Item Product Code';
        $fields['db_field_name'] = 'LI_ProductCode';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Description';
        $fields['description'] = 'Line Item Description';
        $fields['db_field_name'] = 'LI_Description';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_GL_Code';
        $fields['description'] = 'Line Item GL Code';
        $fields['db_field_name'] = 'LI_GL_Code';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Department_Code';
        $fields['description'] = 'Line Item Department Code';
        $fields['db_field_name'] = 'LI_Department_Code';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_AdditionalDefault1';
        $fields['description'] = 'Line Item AdditionalDefault1';
        $fields['db_field_name'] = 'LI_AdditionalDefault1';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_AdditionalDefault2';
        $fields['description'] = 'Line Item AdditionalDefault2';
        $fields['db_field_name'] = 'LI_AdditionalDefault2';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_AdditionalDefault3';
        $fields['description'] = 'Line Item AdditionalDefault3';
        $fields['db_field_name'] = 'LI_AdditionalDefault3';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Quantity';
        $fields['description'] = 'Line Item Quantity';
        $fields['db_field_name'] = 'LI_Quantity';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Unit_Price';
        $fields['description'] = 'Line Item Unit Price';
        $fields['db_field_name'] = 'LI_Unit_Price';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Net';
        $fields['description'] = 'Line Item Net Amount';
        $fields['db_field_name'] = 'LI_Net';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_VAT_Code';
        $fields['description'] = 'Line Item VAT Code';
        $fields['db_field_name'] = 'LI_VAT_Code';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Tax';
        $fields['description'] = 'Line Item Tax';
        $fields['db_field_name'] = 'LI_Tax';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;

        $fields = array();
        $fields['name'] = 'LI_Line_Total';
        $fields['description'] = 'Line Item Total';
        $fields['db_field_name'] = 'LI_Line_Total';
        $fields['platform_object_id'] = $platform_object_id_line_item;
        $fieldlist[] = $fields;



        foreach ($fieldlist as $row) {

            $name = $row['name'];
            $description = $row['description'];
            $db_field_name = $row['db_field_name'];
            $platform_object_id = $row['platform_object_id'];

            $this->mobj->makeInsert('platform_fields', ['name' => $name, 'description' => $description, 'db_field_name' => $db_field_name, 'platform_id' => $platform_id, 'field_type' => $field_type, 'platform_object_id' => $platform_object_id, 'status' => 1]);
        }



        die;
    }
}
