<?php

namespace App\Helper\Api;

use DB;
use Auth;
use Mail;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;

class IntacctApi extends Model
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
    $this->my_platform = 'intacct';
    $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
  }




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

  public function GetIntacctAccInfo($user_id,$user_integration_id=null)
  {

    if($user_integration_id!=''){

        $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['account_name','app_id', 'app_secret', 'access_token']);

        if($acc_detail){
            $intacct_cred = array();
            $intacct_cred['companyid'] = $acc_detail->account_name;
            $intacct_cred['userid'] = $this->mobj->encrypt_decrypt($acc_detail->app_id,'decrypt');
            $intacct_cred['userpassword'] = $this->mobj->encrypt_decrypt($acc_detail->app_secret,'decrypt');
            $intacct_cred['session_id'] = $this->mobj->encrypt_decrypt($acc_detail->access_token,'decrypt');

            $app_detail = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id]);
            if($app_detail){
                $intacct_cred['control_id'] = $this->mobj->encrypt_decrypt($app_detail->app_ref,'decrypt');
                $intacct_cred['sender_id'] = $this->mobj->encrypt_decrypt($app_detail->client_id,'decrypt');
                $intacct_cred['sender_password'] = $this->mobj->encrypt_decrypt($app_detail->client_secret,'decrypt');
            }
            return $intacct_cred;
        }else{
            return false;
        }

    }else{

        $app_detail = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->my_platform_id]);
        if($app_detail){
            $intacct_cred = array();
            $intacct_cred['control_id'] = $this->mobj->encrypt_decrypt($app_detail->app_ref,'decrypt');
            $intacct_cred['sender_id'] = $this->mobj->encrypt_decrypt($app_detail->client_id,'decrypt');
            $intacct_cred['sender_password'] = $this->mobj->encrypt_decrypt($app_detail->client_secret,'decrypt');

            return $intacct_cred;
        }else{
            return false;
        }

    }

   }

  public function CheckAPIAndReturnSession($user_id,$companyid, $intacctuserid, $userpassword)
  {

    $acc_info = $this->GetIntacctAccInfo($user_id);
    //dd($acc_info);
    if($acc_info){
      // dd($senderid, $password, $controlid, $companyid, $userid, $userpassword);
      $post = "<?xml version='1.0' encoding='UTF-8'?>
      <request>
        <control>
          <senderid>" . $acc_info['sender_id'] . "</senderid>
          <password>" . $acc_info['sender_password'] . "</password>
          <controlid>" . $acc_info['control_id'] . "</controlid>
          <uniqueid>false</uniqueid>
          <dtdversion>3.0</dtdversion>
          <includewhitespace>false</includewhitespace>
        </control>
        <operation>
          <authentication>
            <login>
              <userid>$intacctuserid</userid>
              <companyid>$companyid</companyid>
              <password>" . htmlspecialchars($userpassword, ENT_XML1) . "</password>
            </login>
          </authentication>
          <content>
            <function controlid='" . $acc_info['control_id'] . "'>
              <getAPISession />
            </function>
          </content>
        </operation>
      </request>";

      $service_url = \Config::get('apiconfig.IntacctAuthUrl') . '/ia/xml/xmlgw.phtml';

      $headers = ["content-type:application/xml"];
      $response = $this->mobj->makeCurlRequest('POST', $service_url, $post, $headers, 1); // Xml Request
      $doc = simplexml_load_string($response);

      if ($doc) {

        $res = json_decode(json_encode((array) $doc), 1);

        if (isset($res['control']['status']) && @$res['control']['status'] == 'success' && @$res['operation']['result']['status'] == 'success') {
          $res['api_status'] = 'success';
          $res['api_error'] = '';

        }else if (isset($res['operation']['result']['errormessage']['error']) || isset($res['operation']['errormessage']['error'])) {

          if(isset($res['operation']['result']['errormessage']['error'])){
            $arrerror = $res['operation']['result']['errormessage']['error'];
          }else if($res['operation']['errormessage']['error']){
              $arrerror = $res['operation']['errormessage']['error'];
          }

          $reason = "";
          if(isset($arrerror['description2'])){
              $reason.= $arrerror['description2'].',';
          }else if(count($arrerror) > 0){
              foreach($arrerror as $error){
                  $reason.=$error['description2'].',';
              }
          }

          $reason = rtrim($reason,', ');

          $res['api_status'] = 'failed';
          $res['api_error'] = $reason;
        }else{

          $res['api_status'] = 'failed';
          $res['api_error'] = 'Sign-in information is incorrect';

        }

        return $res;
      } else {
        return false;
      }
    }


  }




  public function CallAPI($user_id,$user_integration_id,$post_body, $isLog=0)
  {

    $acc_info = $this->GetIntacctAccInfo($user_id,$user_integration_id);
    if($acc_info){

      $post = "<?xml version='1.0' encoding='UTF-8'?>
          <request>
            <control>
            <senderid>" . $acc_info['sender_id'] . "</senderid>
            <password>" . $acc_info['sender_password'] . "</password>
            <controlid>" . $acc_info['control_id'] . "</controlid>
              <uniqueid>false</uniqueid>
              <dtdversion>3.0</dtdversion>
              <includewhitespace>false</includewhitespace>
            </control>
            <operation>
              <authentication>
                <sessionid>" .$acc_info['session_id']. "</sessionid>
              </authentication>
              <content>
                <function controlid='" . $acc_info['control_id'] . "'>
                $post_body
                </function>
              </content>
            </operation>
          </request>";

    if( $isLog ){
        \Log::info( "CallAPI: ".$post );
    }
      $headers = ['content-type:application/xml'];
      $response = $this->mobj->makeCurlRequest('POST',\Config::get('apiconfig.IntacctAuthUrl').'/ia/xml/xmlgw.phtml',$post,$headers);

     // $res = json_encode((array) simplexml_load_string($response));
      $res = json_decode(json_encode((array) simplexml_load_string($response)), 1);


      if (isset($res['control']['status']) && @$res['control']['status'] == 'success' && @$res['operation']['result']['status'] == 'success') {
        $res['api_status'] = 'success';
        $res['api_error'] = '';

      }else if (isset($res['operation']['result']['errormessage']['error']) || isset($res['operation']['errormessage']['error'])) {

        if(isset($res['operation']['result']['errormessage']['error'])){
          $arrerror = $res['operation']['result']['errormessage']['error'];
        }else if($res['operation']['errormessage']['error']){
            $arrerror = $res['operation']['errormessage']['error'];
        }

        $reason = "";
        if(isset($arrerror['description2'])){
            $reason.= $arrerror['description2'].',';
        }else if(count($arrerror) > 0){
            foreach($arrerror as $error){
                $reason.=$error['description2'].',';
            }
        }

        $reason = rtrim($reason,', ');

        $res['api_status'] = 'failed';
        $res['api_error'] = $reason;
      }else{
        $res['api_status'] = 'failed';
        $res['api_error'] = 'Oops! something went wrong.';
        exit;
      }

      return $res;

    }else{
      return false;
    }
  }

    /**
     *
     */
    public function generateOrderArr( $user_id=0, $platform_id=0, $user_integration_id=0, $order_number = 0, $rowinv=[], $discount = 0, $arr_invoice=[], $sync_status="Ready", $destination_platform_id=0 ){
        $orderArr['user_id'] = $user_id;
        $orderArr['platform_id'] = $platform_id;
        $orderArr['user_integration_id'] = $user_integration_id;
        // $orderArr['platform_customer_id'] = $customerArr->id;
        $orderArr['order_type'] = "SO";
        $orderArr['api_order_id'] = preg_replace('/[^0-9\-]/', '', str_replace('-', '', $order_number ) );
        $orderArr['api_order_reference'] = (!is_array(@$rowinv['CUSTOMERPONUMBER'])) ? @$rowinv['CUSTOMERPONUMBER'] : null;
        $orderArr['order_number'] = $order_number;
        $orderArr['currency'] = (!is_array(@$rowinv['CURRENCY'])) ? @$rowinv['CURRENCY'] : null;
        $orderArr['order_date'] = (!is_array(@$rowinv['WHENCREATED'])) ? @$rowinv['WHENCREATED'] : null;
        $orderArr['total_discount'] = $discount;
        $orderArr['total_tax'] = 0;
        $orderArr['total_amount'] = $arr_invoice['total_amt'];
        $orderArr['net_amount'] = $arr_invoice['net_total'];
        $orderArr['payment_date'] = null;
        $orderArr['notes'] = null;
        $orderArr['sync_status'] = $sync_status;//"Synced";
        $orderArr['linked_id'] = $destination_platform_id;

        return$orderArr;
    }

    /**
     *
     */
    public function generateorderTransactionArr( $platform_id=0, $user_integration_id=0, $platform_order_id = 0, $rowinv=[], $orderArr = 0, $destination_platform_id=0 ){
        $orderTransaction['platform_id'] = $platform_id;
        $orderTransaction['user_integration_id'] = $user_integration_id;
        $orderTransaction['platform_order_id'] = $platform_order_id;
        $orderTransaction['transaction_id'] = $rowinv['PRRECORDKEY'];
        $orderTransaction['transaction_type'] = "PRRECORDKEY";
        $orderTransaction['transaction_amount'] = $orderArr['net_amount'];
        $orderTransaction['transaction_reference'] = "This PRRECORDKEY id required for create apply_arpayment(AR) Transaction";
        $orderTransaction['sync_status'] = "Inactive";
        $orderTransaction['currency_code'] = $orderArr['currency'];
        $orderTransaction['linked_id'] = $destination_platform_id;

        return $orderTransaction;
    }
}
