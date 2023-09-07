<?php

namespace App\Http\Controllers\Intacct;

use DB;
use App\Helper\FieldMappingHelper;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use Illuminate\Database\Eloquent\Model;
use App\Helper\Api\IntacctApi;

class IntacctIntegrationCustomLogic extends Model
{

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        //$this->map = new FieldMappingHelper();
        //$this->mobj = new MainModel();
        //$this->helper = new ConnectionHelper();
        $this->intacctapi = new IntacctApi();
    }


    public function CustomPrice($info=[])
    {

        $itemprice = array();
        if($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178){


            /*$query_item_price_list ='<readByQuery>
                <object>SOPRICELISTENTRY</object>
                <fields>*</fields>
                <query>Name = \''.$info['price_list_name'].'\' AND ITEMID IN ('.$info['itemids'].')</query>
                <pagesize>200</pagesize>
            </readByQuery>';


            $response_price = $this->intacctapi->CallAPI($info['user_id'],$info['user_integration_id'],$query_item_price_list);

            if($response_price['api_status']=='success'){
                if(isset($response_price['operation']['result']['data']['sopricelistentry'])){
                    $prices = array();
                    if(isset($response_price['operation']['result']['data']['sopricelistentry']['RECORDNO'])){
                        $prices[0] = $response_price['operation']['result']['data']['sopricelistentry'];
                    }else{
                        $prices = $response_price['operation']['result']['data']['sopricelistentry'];
                    }

                    foreach($prices as $ip){
                        if(isset($ip['ITEMID'])){
                            if(strpos(strtolower($info['itemidswithunitweight'][$ip['ITEMID']]['uom']), 'case of') !== false || strtolower($info['itemidswithunitweight'][$ip['ITEMID']]['uom'])=='case' || strtolower($info['itemidswithunitweight'][$ip['ITEMID']]['uom'])=='each'){
                                $itemprice[$ip['ITEMID']] = $ip['VALUE'];
                            }else{
                                $itemprice[$ip['ITEMID']] = round((floatval($ip['VALUE']) * floatval($info['itemidswithunitweight'][$ip['ITEMID']]['weight'])),2);
                            }
                        }
                    }
                }
            }
            */


            $date = date('m/d/Y');
            $query_item_price_list ='<query>
            <object>SOPRICELISTENTRY</object>
                <filter>
                    <and>
                        <equalto>
                            <field>PRICELISTID</field>
                            <value>'.$info['price_list_name'].'</value>
                        </equalto>
                        <in>
                            <field>ITEMID</field>';
                            foreach($info['itemids'] as $item_id){
                            $query_item_price_list.='<value>'.$item_id.'</value>';
                            }
                            $query_item_price_list.='</in>
                        <greaterthanorequalto>
                            <field>DATETO</field>
                            <value>'.$date.'</value>
                        </greaterthanorequalto>
                        <lessthanorequalto>
                            <field>DATEFROM</field>
                            <value>'.$date.'</value>
                        </lessthanorequalto>
                    </and>
                </filter>
                <select>
                    <field>RECORDNO</field>
                    <field>PRICELISTID</field>
                    <field>ITEMNAME</field>
                    <field>ITEMID</field>
                    <field>VALUE</field>
                    <field>DATEFROM</field>
                    <field>DATETO</field>
                </select>
            </query>';

           

            $response_price = $this->intacctapi->CallAPI($info['user_id'],$info['user_integration_id'],$query_item_price_list);
   

            if($response_price['api_status']=='success'){
                if(isset($response_price['operation']['result']['data']['SOPRICELISTENTRY'])){
                    $prices = array();
                    if(isset($response_price['operation']['result']['data']['SOPRICELISTENTRY']['RECORDNO'])){
                        $prices[0] = $response_price['operation']['result']['data']['SOPRICELISTENTRY'];
                    }else{
                        $prices = $response_price['operation']['result']['data']['SOPRICELISTENTRY'];
                    }

                    foreach($prices as $ip){
                        if(isset($ip['ITEMID'])){
                            if(strpos(strtolower($info['itemidswithunitweight'][$ip['ITEMID']]['uom']), 'case of') !== false || strtolower($info['itemidswithunitweight'][$ip['ITEMID']]['uom'])=='case' || strtolower($info['itemidswithunitweight'][$ip['ITEMID']]['uom'])=='each'){
                                $itemprice[$ip['ITEMID']] = $ip['VALUE'];
                            }else{
                                $itemprice[$ip['ITEMID']] = round((floatval($ip['VALUE']) * floatval($info['itemidswithunitweight'][$ip['ITEMID']]['weight'])),2);
                            }
                        }
                    }
                }
            }

        }

        return $itemprice;
    }


    public function AllowedInvoiceUpdates($info=[])
    {
        $is_allowed = false;
        if($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178){
            $is_allowed = true;
        }
        return $is_allowed;
    }


    public function AllowedInvoiceUpdatesBasedOnTime($info=[])
    {


        $curretdatetime = date("Y-m-d H:i:s");
        $new_time = date("Y-m-d H:i:s", strtotime('+6 hours',strtotime($info['created_at'])));

        $is_allowed = false;
        if(($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178)  && $new_time > $curretdatetime){
            $is_allowed = true;
        }
        return $is_allowed;
    }


    // need to change item as per client requirements
    public function CustomItemExchange($info=[])
    {
        $intacct_api_product_code = $info['intacct_api_product_code'];
        if(($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178) && $intacct_api_product_code=='00912'){
            $intacct_api_product_code = '00914';
        }
        return $intacct_api_product_code;
    }




}
