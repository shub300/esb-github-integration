<?php

namespace App\Http\Controllers\Korsbakken;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Http\Controllers\Controller;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\Logger;
use App\Helper\Api\Korsbakken;
use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use App\Models\PlatformUrl;
use phpseclib3\Net\SFTP;
use Lang;
use Auth;
use DB;

class KorsbakkenController extends Controller
{
    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'korsbakken';
    public static $FILE_GET_PATH = '/22043730/prod/touser/'; // '/22043730/test/touser/';
    public static $FILE_MOVE_PATH = '/22043730/prod/tofms/'; // '/22043730/test/tofms/';
    public static $FILE_NAME = 'KORSBAKKEN_INVENTORYREPORT_V1.xml';
    public function __construct() {
        $this->connectionHelper = new ConnectionHelper();
        $this->mainModel = new MainModel();
        $this->logger = new Logger();
        // Set the platform ID
        $this->platformId = $this->connectionHelper->getPlatformIdByName( self::PLATFORMNAME );
    }

    /**
     * Auth function return the view page of authentication
     *
     * @param $request Request class
     */
    public function InitiateKorsbakkenAuth(Request $request) {
        $platform = self::PLATFORMNAME;
        return view("pages.apiauth.auth_korsbakken", compact('platform'));
    }

    /**
     * Auth function to connect to the platform with response to the front
     *
     * @param $request Request class
     */
    public function ConnectKorsbakken( Request $request ) {
        $error_msg = null;
        try {
            $account_name = trim($request->account_name);
            $ftp_protocol = trim($request->protocol);
            $ftp_server = trim($request->host_name);
            $ftp_port = trim($request->port);
            $ftp_username = trim($request->user_name);
            $ftp_userpass = trim($request->password);

            if($this->mainModel->checkHtmlTags( $request->all() ) ){
                Session::put('auth_msg', Lang::get('tags.validate'));
                return redirect()->back();
            }

            $user_data =  Session::get('user_data');
            $user_id =  $user_data['id'];

            $login = false;
            if( $ftp_protocol == 'SFTP' ){
                if($ftp_port){
                    $sftp = new SFTP($ftp_server, $ftp_port);
                }else{
                    $sftp = new SFTP($ftp_server);
                }
                $login = $sftp->login($ftp_username, $ftp_userpass);
            }else if( $ftp_protocol == 'FTP' ){
                $ftp_conn = @ftp_connect($ftp_server);
                if ($ftp_conn) {
                    $login = @ftp_login($ftp_conn, $ftp_username, $ftp_userpass);
                    // turn passive mode on
                    ftp_pasv($ftp_conn, true);
                }
            }

            if ($login) {

                // Check for the account
                $account = PlatformAccount::select( 'id' )->where( [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'account_name' => $account_name
                ] )->first();

                if ($account) {
                    $account->api_domain = $ftp_server;
                    $account->app_id = $this->mainModel->encrypt_decrypt($ftp_username, 'encrypt');
                    $account->app_secret = $this->mainModel->encrypt_decrypt($ftp_userpass, 'encrypt');
                    $account->region = $ftp_port;
                    $account->connection_type = $ftp_protocol;
                    $account->account_name = $account_name;
                    $account->user_id = $user_id;
                    $account->platform_id = $this->platformId;
                    $account->expires_in = 3600;
                    $account->token_refresh_time = time();
                    $account->save();
                } else {
                    $newAccount = new PlatformAccount();
                    $newAccount->api_domain = $ftp_server;
                    $newAccount->app_id = $this->mainModel->encrypt_decrypt($ftp_username, 'encrypt');
                    $newAccount->app_secret = $this->mainModel->encrypt_decrypt($ftp_userpass, 'encrypt');
                    $newAccount->region = $ftp_port;
                    $newAccount->connection_type = $ftp_protocol;
                    $newAccount->account_name = $account_name;
                    $newAccount->user_id = $user_id;
                    $newAccount->platform_id = $this->platformId;
                    $newAccount->expires_in = 3600;
                    $newAccount->token_refresh_time = time();
                    $newAccount->save();
                    if( !isset($newAccount->id) ) {
                        $error_msg = 'Account not created! Please try again.';
                    }
                }
            } else {
                $error_msg = 'Authentication Error';
            }

            echo '<script>window.close();</script>';
        } catch (\Exception $e) {
            $error_msg = $e->getMessage();
        }

        if( $error_msg ){
            Session::put('auth_msg', $error_msg);
        }
    }

    /**
     * Auth function to check account connectivity and return connection details if connected
     *
     * @param $user_integration_id
     */
    public function KorsbakkenConnection($user_integration_id){
        $connection_info = [ 'protocol'=>null, 'connection'=>null ];
        $acc_detail = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'app_id', 'app_secret', 'connection_type', 'region']);
        if ($acc_detail) {
            $ftp_server = $acc_detail->api_domain;
            $ftp_username = $this->mainModel->encrypt_decrypt($acc_detail->app_id, 'decrypt');
            $ftp_userpass = $this->mainModel->encrypt_decrypt($acc_detail->app_secret, 'decrypt');
            $ftp_protocol = $acc_detail->connection_type;
            $ftp_port = $acc_detail->region;

            if( $ftp_protocol == 'SFTP' ){
                if($ftp_port){
                    $sftp = new SFTP($ftp_server, $ftp_port);
                }else{
                    $sftp = new SFTP($ftp_server);
                }
                if ($sftp->login($ftp_username, $ftp_userpass)) {
                    $connection_info = [ 'protocol'=>'SFTP', 'connection'=>$sftp ];
                }
            }else if( $ftp_protocol == 'FTP' ){
                $ftp = @ftp_connect($ftp_server);
                if (@ftp_login($ftp, $ftp_username, $ftp_userpass)) {
                    $connection_info = [ 'protocol'=>'FTP', 'connection'=>$ftp ];
                }
            }
        }
        return $connection_info;
    }

    /** To reaceive inventory data as array and devide into chunks
     * @param $from: array key position to start
     * @param $to: array key position to end
     * @param $array: array data to be divided
     */
    private function GetInventoryChunk($from, $to, $array){
        $keys = array_flip(array_keys($array));
        if( max($keys) < $to ){
            /** If array values are less than requested number of rows then set
             * the max key value to the variable to pick the last most value */
            $to = max($keys);
        }
        if (isset($keys[$from]) and isset($keys[$to])) {
            return array_slice($array, $keys[$from], $keys[$to] - $keys[$from] + 1);
        }
    }

    /** To read XML file to get inventory details and handle the records to insert or update into the database
     *
     * @param $user_id
     * @param $user_integration_id
     */
    public function syncInventory($user_id, $user_integration_id = ''){
        $return_data = true;
        try {
            $connection_res = $this->KorsbakkenConnection( $user_integration_id );

            if( isset($connection_res['connection']) ){
                $contents = $this->readInventoryFile( $connection_res );
                if( $contents ){

                    // Convet XML data into simple array
                    $xml = simplexml_load_string($contents, "SimpleXMLElement", LIBXML_NOCDATA);
                    $json = json_encode($xml);
                    $productArray = json_decode($json, TRUE);

                    if( isset($productArray) && is_array($productArray) && isset($productArray['Article']) ){
                        $limit = 500;
                        $offset = 0;
                        $arrProductArticles = $productArray['Article'];

                        $is_url = PlatformUrl::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId, 'url_name' => 'inventory_limit', 'status'=>1
                        ])->select('id', 'url', 'status')->first();

                        if($is_url && $is_url->status == 1){
                            $url_id = $is_url->id;
                            $offset = $is_url->url;
                            $no_of_row = $limit + $offset;
                            $is_url->update(['url' => $no_of_row]);
                        }else{
                            $no_of_row = $limit + $offset;
                            $url_data = [
                                'user_id' => $user_id,
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'url_name' => 'inventory_limit',
                                'url' => $no_of_row,
                                'status' => 1
                            ];
                            $url_id = PlatformUrl::create( $url_data )->id;
                        }

                        // If getting no_of_row value larger than the total number of line count of the file then set URL value to zero
                        if($no_of_row >= count($arrProductArticles)){
                            PlatformUrl::where(['id' => $url_id])->update(['url' => 0]);
                        }

                        $inventory_list = $this->GetInventoryChunk($offset, $no_of_row, $arrProductArticles);

                        $warehouse_id = 1;
                        if (is_array($inventory_list) || is_object($inventory_list)){
                            foreach ($inventory_list as $key => $article) {
                                // warehoouse filter
                                if( isset($article['WareHouseNo']) && $article['WareHouseNo'] != $warehouse_id ){
                                    continue;
                                }

                                $data = [];
                                $sku = $data['sku'] = isset($article['ArticleNo']) ? $article['ArticleNo'] : null;
                                $quantity = isset($article['ActualStock_Qty']) && (int)$article['ActualStock_Qty'] > 0 ? (int)$article['ActualStock_Qty'] : 0; // If inventory value is negative then set quantity to zero
                                $data['api_warehouse_id'] = isset($article['WareHouseNo']) ? $article['WareHouseNo'] : null;
                                $data['ean'] = isset($article['ItemNo_EAN']) ? $article['ItemNo_EAN'] : null;

                                if ($sku) {

                                    $product_exist = PlatformProduct::where([ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'sku'=>$sku])
                                    ->select('id', 'inventory_sync_status')->first();
                                    if ($product_exist) {
                                        $product_id = $product_exist->id;
                                    } else {
                                        $data['user_id'] = $user_id;
                                        $data['platform_id'] = $this->platformId;
                                        $data['user_integration_id'] = $user_integration_id;
                                        $data['inventory_sync_status'] = 'Ready';
                                        $product_id = PlatformProduct::create( $data )->id;
                                    }

                                    $is_exist = PlatformProductInventory::where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'platform_product_id' => $product_id,
                                    ])->select('id', 'quantity', 'sync_status')->first();

                                    if ($is_exist) {
                                        $allow_inventory_status_change = false;
                                        if ( $is_exist->quantity == $quantity && $is_exist->sync_status == 'Synced' ) {
                                            if($product_exist && $product_exist->inventory_sync_status == 'Synced'){
                                                continue;
                                            }else{
                                                $allow_inventory_status_change = true;
                                            }
                                        }else{
                                            $allow_inventory_status_change = true;
                                        }

                                        if( $allow_inventory_status_change ){
                                            $is_exist->update(['quantity' => $quantity, 'sync_status' => 'Ready']);
                                            PlatformProduct::where('id', $product_id)->update(['inventory_sync_status' => 'Ready']);
                                        }
                                    } else {
                                        $inventory_data = [
                                            'user_id' => $user_id,
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $this->platformId,
                                            'platform_product_id' => $product_id,
                                            'quantity' => $quantity,
                                            'sku' => $sku
                                        ];
                                        PlatformProductInventory::create( $inventory_data );
                                    }

                                }
                            }
                        }

                        // perform file upload
                        $this->copyInventoryFile( $user_integration_id, $contents );
                    }

                }else{
                    $return_data = "Inventory data not found.";
                }
            }else{
                $return_data = "Authentication error.";
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> KorsbakkenController -> syncInventory -> " . $e->getLine() . " -> " . $e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    /** To read XML file from the remote server using FTP or SFTP protocol */
    public function readInventoryFile( $connection_info ){
        $contents = null;
        if( isset($connection_info['connection']) && $connection_info['connection'] ){
            $connection = $connection_info['connection'];
            $protocol = isset($connection_info['protocol']) ? $connection_info['protocol'] : null;
            if( $protocol && $protocol == 'SFTP' ){
                $file_list = $connection->nlist(self::$FILE_GET_PATH);
                $arr_file_names = $this->pickXMLFileName($file_list);
                $xml_file_name = '';
                if( count($arr_file_names) ){
                    $xml_file_name = $arr_file_names[0];
                }
                // $contents = $connection->get(self::$FILE_GET_PATH . self::$FILE_NAME);
                $contents = $connection->get(self::$FILE_GET_PATH . $xml_file_name);
            }else if( $protocol && $protocol == 'FTP' ){
                ftp_pasv($connection, true);

                $file_list = ftp_nlist($connection, self::$FILE_GET_PATH);
                $arr_file_names = $this->pickXMLFileName($file_list);
                $xml_file_name = '';
                if( count($arr_file_names) ){
                    $xml_file_name = $arr_file_names[0];
                }

                $h = fopen('php://temp', 'r+');
                // ftp_fget($connection, $h, self::$FILE_GET_PATH . self::$FILE_NAME, FTP_ASCII, 0);
                ftp_fget($connection, $h, self::$FILE_GET_PATH . basename($xml_file_name), FTP_ASCII, 0);
                $fstats = fstat($h);
                fseek($h, 0);
                $contents = fread($h, $fstats['size']);
                fclose($h);
                ftp_close($connection);
            }
        }
        return $contents;
    }

    /** To returns only .xml file names from a list of directory and file names */
    public function pickXMLFileName($files){
        $file_names = [];
        if( is_array($files) ){
            foreach ($files as $file){
                if (preg_match("/\.xml$/i", $file)){
                    $file_names[] = $file;
                }
            }
        }
        return $file_names;
    }

    /** To copy XML file to the remote server's another directory */
    public function copyInventoryFile( $user_integration_id, $contents ){

        $connection_info = $this->KorsbakkenConnection( $user_integration_id );
        if( isset($connection_info['connection']) && $connection_info['connection'] ){
            $connection = $connection_info['connection'];
            $protocol = isset($connection_info['protocol']) ? $connection_info['protocol'] : null;

            if( $protocol && $protocol == 'SFTP' ){
                //$connection->put(self::$FILE_MOVE_PATH.self::$FILE_NAME, $contents);
                $file_list = $connection->nlist(self::$FILE_GET_PATH);
                $arr_file_names = $this->pickXMLFileName($file_list);
                $xml_file_name = '';
                if( count($arr_file_names) ){
                    $xml_file_name = $arr_file_names[0];
                }

                $connection->put(self::$FILE_MOVE_PATH.$xml_file_name, $contents);
            } else if( $protocol && $protocol == 'FTP' ){
                ftp_pasv($connection, true);

                // $file_list = ftp_nlist($connection, self::$FILE_MOVE_PATH);
                // $arr_file_names = $this->pickXMLFileName($file_list);
                // $dest_file_name = '';
                // if( count($arr_file_names) ){
                //     $dest_file_name = $arr_file_names[0];
                // }else{

                //     $dest_file_name = self::$FILE_NAME;
                // }

                $h = fopen('php://temp', 'r+');
                fwrite($h, $contents);
                rewind($h);
                ftp_fput($connection, self::$FILE_MOVE_PATH.self::$FILE_NAME, $h, FTP_BINARY, 0);
                //ftp_fput($connection, self::$FILE_MOVE_PATH.$dest_file_name, $h, FTP_BINARY, 0);
                fclose($h);
                ftp_close($connection);
            }

        }
    }

    /**
     * To manage function calling of diffeent module
     *
     * @param $method, for 'MUTATE' it's for creation of new data and for 'GET' to get any data from the platform
     * @param $event, the event for the function is initiated
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     *
     * @return boolean data: either true or false
     */
    public function ExecuteKorsbakken($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
    {
        try {
            $response = true;
            if ($method == 'GET' && $event == 'INVENTORY') {
                $response = $this->syncInventory($user_id, $user_integration_id);
            }
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}