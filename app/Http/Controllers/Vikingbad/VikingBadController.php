<?php

namespace App\Http\Controllers\Vikingbad;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\Logger;
use Illuminate\Support\Facades\Session;
use phpseclib3\Net\SFTP;
use File;
use Lang;
class VikingBadController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $file_name = 'lagerstatus.txt';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper();
        $this->my_platform = 'vikingbad';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    // Kefron FTP Auth View Page
    public function InitiateVikingBadAuth(Request $request)
    {
        $platform = $this->my_platform;
        return view("pages.apiauth.vikingbad_auth", compact('platform'));
    }


    // Kefron FTP Auth
    public function ConnectVikingBadOauth(Request $request)
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

        $ftp_conn = @ftp_connect($ftp_server);

        if ($ftp_conn) {
            $login = @ftp_login($ftp_conn, $ftp_username, $ftp_userpass);
            // turn passive mode on
            ftp_pasv($ftp_conn, true);
            if ($login) {
                $OauthData = [
                    'api_domain' => $ftp_server,
                    'app_id' =>   $this->mobj->encrypt_decrypt($ftp_username, $action = 'encrypt'),
                    'app_secret' => $this->mobj->encrypt_decrypt($ftp_userpass, $action = 'encrypt'),
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
            } else {
                Session::put('auth_msg', 'Authentication Error');
            }
        } else {
            Session::put('auth_msg', 'Authentication Error');
        }
        echo '<script>window.close();</script>';
    }


    public function VikingBadConnection($user_integration_id)
    {
        $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['api_domain', 'app_id', 'app_secret']);
        if ($acc_detail) {

            $ftp_server = $acc_detail->api_domain;
            $ftp_username = $this->mobj->encrypt_decrypt($acc_detail->app_id, $action = 'decrypt');
            $ftp_userpass = $this->mobj->encrypt_decrypt($acc_detail->app_secret, $action = 'decrypt');

            $ftp = @ftp_connect($ftp_server);
            if (!@ftp_login($ftp, $ftp_username, $ftp_userpass)) {

                //log error msg 
                $this->mobj->apiErrorLogForNotify($acc_detail->id,'FTP connection issue check credentials');
                return false;

            } else {
                return $ftp;
            }
        } else {
            return false;
        }
    }

    private function GetInventoryChunk($from, $to, $array){
        $keys = array_flip(array_keys($array));
        if (isset($keys[$from]) and isset($keys[$to])) {
            return array_slice($array, $keys[$from], $keys[$to] - $keys[$from] + 1);
        }
    }

    public function ReadTexFile($user_id, $user_integration_id = '')
    {
        $conn_id = $this->VikingBadConnection($user_integration_id);
        //ftp_login($conn_id, 'username', 'password');
        $return_data = true;
        ftp_pasv($conn_id, true);
        $h = fopen('php://temp', 'r+');
        ftp_fget($conn_id, $h, self::$file_name, FTP_ASCII, 0);
        $fstats = fstat($h);
        fseek($h, 0);
        $contents = fread($h, $fstats['size']);
        $productarray[] = explode("\n", $contents);
        fclose($h);
        ftp_close($conn_id);

        $limit = 250;
        $offset = 0;

        $is_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id,
                'platform_id' => $this->my_platform_id, 'url_name' => 'inventory_limit', 'status'=>1], ['id', 'url', 'status']);

        if($is_url && $is_url->status == 1){
            $url_id = $is_url->id;
            $offset = $is_url->url;
            $no_of_row = $limit + $offset;
            $this->mobj->makeUpdate('platform_urls', ['url' => $no_of_row], ['id' => $url_id]);
        }else{
            $no_of_row = $limit + $offset;
            $url_data = [
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->my_platform_id,
                'url_name' => 'inventory_limit',
                'url' => $no_of_row,
                'status' => 1
            ];
            $url_id = $this->mobj->makeInsertGetId('platform_urls', $url_data);
        }

        // If getting no_of_row value larger than the total number of line count of the file then set URL value to zero
        if($no_of_row >= count($productarray[0])){
            $this->mobj->makeUpdate('platform_urls', ['url' => 0], ['id' => $url_id]);
        }

        $inventory_list = $this->GetInventoryChunk($offset, $no_of_row, $productarray[0]);

        foreach ($inventory_list as $prodect) {
            $quantity = $api_product_id = $sku = '';
            $prodect_data = explode(";", $prodect);

            $data = [
                'user_id' => $user_id,
                'platform_id' => $this->my_platform_id,
                'user_integration_id' => $user_integration_id
            ];
            if (isset($prodect_data[0])) {
                $data['sku'] = mb_convert_encoding($prodect_data[0], "UTF-8", "UCS-2");
                $sku = mb_convert_encoding($prodect_data[0], "UTF-8", "UCS-2");
            }
            if (isset($prodect_data[1])) {
                $data['api_product_id'] = mb_convert_encoding($prodect_data[1], "UTF-8", "UCS-2");
                $api_product_id = mb_convert_encoding($prodect_data[1], "UTF-8", "UCS-2");
            }
            if (isset($prodect_data[2])) {
                $quantity = mb_convert_encoding($prodect_data[2], "UTF-8", "UCS-2");;
            }
            if (isset($prodect_data[3])) {
                //$data[''] = utf8_encode($prodect_data[3]);
            }
            if (isset($prodect_data[4])) {
                $data['product_status'] = mb_convert_encoding($prodect_data[4], "UTF-8", "UCS-2");;
            }
            if ($sku || $api_product_id) {
                $product_count = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $sku], ['id', 'inventory_sync_status']);
                if ($product_count) {
                    $this->mobj->makeUpdate('platform_product', $data, ['id' => $product_count->id]);
                    $product_id = $product_count->id;
                } else {
                    $data['inventory_sync_status'] = 'Ready';
                    $product_id = $this->mobj->makeInsertGetId('platform_product', $data);
                }

                $is_exist = $this->mobj->getFirstResultByConditions('platform_product_inventory', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'platform_product_id' => $product_id], ['id', 'quantity', 'sync_status']);
                if ($is_exist) {
                    $allow_inventory_status_change = false;
                    if ( $is_exist->quantity == $quantity && $is_exist->sync_status == 'Synced' ) {
                        if($product_count && $product_count->inventory_sync_status == 'Synced'){
                            continue;
                        }else{
                            $allow_inventory_status_change = true;
                        }
                    }else{
                        $allow_inventory_status_change = true;
                    }
                    if( $allow_inventory_status_change ){
                        $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready'], ['id' => $product_id]);
                        $this->mobj->makeUpdate('platform_product_inventory', ['api_product_id' => $api_product_id, 'quantity' => $quantity, 'sku' => $sku, 'sync_status' => 'Ready'], ['id' => $is_exist->id]);
                    }
                } else {
                    $inventory_data = [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->my_platform_id,
                        'platform_product_id'=>$product_id,
                        'api_product_id' => $api_product_id,
                        'quantity' => $quantity,
                        'sku' => $sku
                    ];
                    $this->mobj->makeInsertGetId('platform_product_inventory', $inventory_data);
                }
            }
        }
        return $return_data;
    }

    public function ExecuteVikingBad($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
    {
        try {
            $response = true;
            if ($method == 'GET' && $event == 'INVENTORY') {
                $response = $this->ReadTexFile($user_id, $user_integration_id);
            }
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}