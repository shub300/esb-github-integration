<?php

namespace App\Http\Controllers\Brandwise;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\Logger;
use Illuminate\Support\Facades\Session;
use phpseclib3\Net\SFTP;
use App\Http\Controllers\Brandwise\BrandwiseUtility;
use File;
use Lang;

class BrandwiseController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        //$this->spsapi = new SpscommerceApi();
        $this->log = new Logger();
        $this->brandwiseutility = new BrandwiseUtility();
        $this->helper = new ConnectionHelper();
        $this->my_platform = 'brandwise';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    // Kefron FTP Auth View Page
    public function InitiateBrandWiseAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.brandwise_auth", compact('platform'));
    }


    // Kefron FTP Auth
    public function ConnectBrandWiseOauth(Request $request)
    {

        $account_name = trim($request->account_name);
        $ftp_server = trim($request->host_name);
        $ftp_username = trim($request->user_name);
        $ftp_userpass = trim($request->password);

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            Session::put('auth_msg', Lang::get('tags.validate'));
            return redirect()->back();
         }
         
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $sftp = new SFTP($ftp_server, 10022);
        if (!$sftp->login($ftp_username, $ftp_userpass)) {
            Session::put('auth_msg', 'Authentication Error');
        } else {
            $OauthData = [
                'api_domain' => $ftp_server,
                'app_id' => $this->mobj->encryptString($ftp_username),
                'app_secret' => $this->mobj->encryptString($ftp_userpass),
                'account_name' => $account_name,
                'user_id' => $user_id,
                'platform_id' => $this->my_platform_id,
                'expires_in' => 3600,
                'token_refresh_time' => time()
            ];
            $ufound =  $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'account_name' => $account_name], ['id']);


            if ($ufound) {
                $this->mobj->makeUpdate('platform_accounts', $OauthData, ['id' => $ufound->id]);
            } else {
                $this->mobj->makeInsert('platform_accounts', $OauthData);
            }
        }
        echo '<script>window.close();</script>';
    }


    public function BrandWiseConnection($user_integration_id)
    {
        $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'app_id', 'app_secret']);
        if ($acc_detail) {

            $ftp_server = $acc_detail->api_domain;
            $ftp_username = $this->mobj->decryptString($acc_detail->app_id);
            $ftp_userpass = $this->mobj->decryptString($acc_detail->app_secret);

            $sftp = new SFTP($ftp_server, 10022);

            if (!$sftp->login($ftp_username, $ftp_userpass)) {
                return false;
            } else {

                return $sftp;
            }
        } else {
            return false;
        }
    }

    public function ReadXmlFile($user_id, $user_integration_id = '', $platform_workflow_rule_id = '')
    {
        try {
            $return_data = true;
            $is_proxy = 0;
            if ($is_proxy) {
             
                $XmalData = $this->CallProxyServer($user_integration_id);
                $return_data = $this->ReadeFileData($XmalData, $user_id, $user_integration_id, $platform_workflow_rule_id);
            } else {
                $sftp = $this->BrandWiseConnection($user_integration_id);
                if ($sftp) {
                    $files = $sftp->nlist('/');
                    foreach ($files as $key => $file) {
                        if ($key == 5) {
                            return  $return_data;
                        }
                        $xmlString = $sftp->get('/' . $file);
                        $xmlObject = simplexml_load_string($xmlString);
                        $json = json_encode($xmlObject);
                        $XmalData = json_decode($json, true);
                        $return_data = $this->ReadeFileData($XmalData, $user_id, $user_integration_id, $platform_workflow_rule_id);
                        // if ($return_data) {
                             $sftp->delete('/' . $file); //Delete file from folder.
                       //  }
                    }
                }
            }
            return $return_data;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function ReadeFileData($XmalData, $user_id, $user_integration_id, $platform_workflow_rule_id)
    {
        $return_data = true;
        $ObjectId = $this->helper->getObjectId('sales_order');
        $find_Ship_Date_Record = $this->mobj->getFirstResultByConditions('platform_fields', [
            'platform_id' => $this->my_platform_id, 'user_integration_id' => 0,
            'field_type' => 'custom', 'name' => 'Ship_Date', 'platform_object_id' => $ObjectId, 'status' => 1
        ], ['id']);
        $find_Batch_ID_Record = $this->mobj->getFirstResultByConditions('platform_fields', [
            'platform_id' => $this->my_platform_id, 'user_integration_id' => 0,
            'field_type' => 'custom', 'name' => 'Batch_ID', 'platform_object_id' => $ObjectId, 'status' => 1
        ], ['id']);
        if (!array_key_exists('error', $XmalData)) {
            if (!isset($XmalData['SalesOrder']['BatchID'])) {
                foreach ($XmalData['SalesOrder'] as $SalesOrder) {
                  
                    $return = $this->brandwiseutility->orderData($user_id, $user_integration_id, $SalesOrder, $find_Ship_Date_Record, $find_Batch_ID_Record, $platform_workflow_rule_id);
                    if (!$return) {
                        $return_data = false;
                    }
                }
            } else {
                $return = $this->brandwiseutility->orderData($user_id, $user_integration_id, $XmalData['SalesOrder'], $find_Ship_Date_Record, $find_Batch_ID_Record, $platform_workflow_rule_id);
                if (!$return) {
                    $return_data = false;
                }
            }
        } else {
            $return_data = false;
        }
        return $return_data;
    }

    public function CallProxyServer($user_integration_id)
    {
        $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'app_id', 'app_secret']);
        if ($acc_detail) {
            $ftp_server = $acc_detail->api_domain;
            $ftp_username = $this->mobj->decryptString($acc_detail->app_id);
            $ftp_userpass = $this->mobj->decryptString($acc_detail->app_secret);
            $post_data = array();
            $post_data['sftp_server'] = $ftp_server;
            $post_data['sftp_username'] = $ftp_username;
            $post_data['sftp_userpass'] = $ftp_userpass;
            $service_url = 'https://esb-stag.apiworx.net/ProxyFTP/brandwise_get_data.php';
            // $curl = curl_init();
            // curl_setopt_array($curl, array(
            //     CURLOPT_URL =>  $service_url,
            //     CURLOPT_RETURNTRANSFER => true,
            //     CURLOPT_ENCODING => '',
            //     CURLOPT_MAXREDIRS => 10,
            //     CURLOPT_TIMEOUT => 0,
            //     CURLOPT_FOLLOWLOCATION => true,
            //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            //     CURLOPT_CUSTOMREQUEST => 'POST',
            //     CURLOPT_POSTFIELDS => $post_data,
            // ));
            // $response = curl_exec($curl);
            //curl_close($curl);
            $response = $this->mobj->makeCurlRequest('POST', $service_url, $post_data);
            $return = json_decode($response, true);
            return $return;
        }
    }

    public function ExecuteBrandWise($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
    {
        try {
            $response = true;
            $response = $this->ReadXmlFile($user_id, $user_integration_id, $platform_workflow_rule_id);
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}