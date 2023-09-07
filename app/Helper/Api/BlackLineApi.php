<?php
namespace App\Helper\Api;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Exception;
use Illuminate\Database\Eloquent\Model;
use phpseclib3\Net\SFTP;

class BlackLineApi extends Model
{
    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper();
        $this->my_platform = 'blackline';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    /**
     *
     */
    public static function is_valid_xml($xml)
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->loadXML($xml);
        $errors = libxml_get_errors();
        return empty($errors);
    }

    /********************Main Code Start Here**************************/
    public function GetAppInfo()
    {
        $api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id]);
        if ($api_app) {
            return $api_app;
        } else
            return false;
    }

    /* Check Blackline credentials */
    public function CheckCredentials($host, $port, $user, $password)
    {
        try {
            $sftp = new SFTP($host, $port);
            if ($sftp->login($user, $password)) {
                return true;
            }else{
                return false;
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     *
     */
    public function generateCustomerArr( $cr=[], $user_id=0, $user_integration_id=0 ){
        $customerArr['user_id'] = $user_id;
        $customerArr['platform_id'] = $this->my_platform_id;
        $customerArr['user_integration_id'] = $user_integration_id;
        $customerArr['api_customer_id'] = null;
        $customerArr['api_customer_code'] = $cr[1] ?? null;//(int)preg_replace('/[^0-9\-]/', '', str_replace('-', '', $cr[1] ) );
        $customerArr['customer_name'] = $cr[11] ?? null;//customer name

        return $customerArr;
    }

    /**
     *
     */
    public function generateOrderArr( $ar=[], $user_id=0, $user_integration_id=0 ){
        $orderArr['user_id'] = $user_id;
        $orderArr['platform_id'] = $this->my_platform_id;
        $orderArr['user_integration_id'] = $user_integration_id;
        // $orderArr['store_number'] = $ar['transaction'][0][8] ?? null;
        $orderArr['order_type'] = "SO";
        $orderArr['api_order_id'] = $ar['payment'][9];
        $orderArr['api_order_reference'] = $ar['payment'][10];
        $orderArr['order_number'] = $ar['header'][1];
        $orderArr['currency'] = $ar['payment'][13];
        $orderArr['total_discount'] = 0;
        $orderArr['total_tax'] = 0;
        $orderArr['total_amount'] = (float)$ar['header'][3] ?? 0;
        $orderArr['net_amount'] = (float)$ar['header'][3] ?? 0;
        $orderArr['notes'] = $ar['payment'][3];
        $orderArr['sync_status'] = "Synced";
        return $orderArr;
    }

    /**
     *
     */
    public function generateInvoiceArr( $ar=[], $trxn, $user_id=0, $user_integration_id=0 ){
        $arr_invoice['user_id'] = $user_id;
        $arr_invoice['platform_id'] = $this->my_platform_id;
        $arr_invoice['user_integration_id'] = $user_integration_id;
        $arr_invoice['trading_partner_id'] = null;
        $arr_invoice['api_invoice_id'] = $ar['payment'][9] ?? null;
        $arr_invoice['invoice_code'] = $trnx[2] ?? null;
        $arr_invoice['invoice_state'] = "Pending";
        $arr_invoice['ref_number'] = $trnx[7] ?? null;
        $arr_invoice['order_doc_number'] = $ar['payment'][12] ?? null;
        $arr_invoice['gl_posting_date'] = $ar['payment'][7];
        $arr_invoice['total_amt'] = $ar['payment'][5] ?? 0;//(float)$ar['header'][3] ?? 0;
        $arr_invoice['total_paid_amt'] = $ar['payment'][5] ?? 0;
        $arr_invoice['api_created_at'] = date('Y-m-d');
        $arr_invoice['api_updated_at'] = date('Y-m-d');
        $arr_invoice['ship_date'] = null;
        $arr_invoice['ship_via'] = null;
        $arr_invoice['tracking_number'] = $ar['payment'][12] ?? null;
        $arr_invoice['ship_by_date'] = null;
        $arr_invoice['customer_name'] = null;
        $arr_invoice['message'] = $ar['payment'][3];
        $arr_invoice['payment_terms'] = null;
        $arr_invoice['due_days'] = null;
        $arr_invoice['currency'] = $ar['payment'][13];
        $arr_invoice['due_date'] = null;
        $arr_invoice['net_total'] = $ar['payment'][5] ?? 0;//(float)$ar['header'][3] ?? 0;

        return $arr_invoice;
    }

    /**
     *
     */
    public function generateTransactionArr( $ar=[], $trnx=[] ){
        $transactionArr['api_invoice_line_id'] = $ar['payment'][9];
        $transactionArr['api_product_id'] = 0;
        $transactionArr['product_name'] = $ar['payment'][11] ?? null;
        $transactionArr['qty'] = 1;
        $transactionArr['shipped_qty'] = 1;
        $transactionArr['unit_price'] = $trnx[4];
        $transactionArr['price'] = $trnx[4];
        $transactionArr['uom'] = null;
        $transactionArr['description'] = $trnx[5];
        $transactionArr['total'] = $ar['payment'][4];
        $transactionArr['total_weight'] = 0;
        $transactionArr['api_code'] = preg_replace('/[^0-9\-]/', '', str_replace('-', '', $trnx[1] ) );
        $transactionArr['row_type'] = $ar['payment'][6];

        return $transactionArr;
    }

    /**
     *
     */
    public function createCSVFilesWithSpecificFolder( $data=[], $user_integration_id, $path='', $original_file_name='' ){
        $original_file_name = "test-".$original_file_name."-".time().".csv";
        $file_path = public_path().$path.$user_integration_id;
        if (!file_exists($file_path)) {
            mkdir($file_path, 0777, true);
        }

        $file_as_temp = $file_path.'/'.$original_file_name;

        $TempFile = $file_as_temp;
        $file = fopen($TempFile,"w");

        foreach ($data as $line) {
            fputcsv($file, $line);
        }
        fclose($file);
    }
}
