<?php

namespace App\Http\Controllers\Spscommerce;

use DB;
use App\Helper\FieldMappingHelper;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use Illuminate\Database\Eloquent\Model;

class SpscommerceIntegrationCustomLogic extends Model
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

    }


    public function CustomQuantityAndUOM($user_integration_id='',$data=[])
    {
        $uomdef = ['Each'=>'EA','Case'=>'CA','Gram'=>'GR','Pounds'=>'LB','Pound'=>'LB','Lbs'=>'LB','LBs'=>'LB'];
        $uom = "";
        if($user_integration_id==163 || ($user_integration_id==176 && $data['type']=='shipping')){
            $uom = "CA";
        }else{
            $uom = @$uomdef[$data['uom']] ? @$uomdef[$data['uom']] : @$data['default_uom'];
        }

        if($user_integration_id==174 && isset($data['product_code']) && isset($data['customer_code'])){
            if($data['product_code']=='00280' && $data['customer_code']=='C-10664' && $uom=='CA'){
                $uom = "LB";
            }
        }


        if($data['is_return_uom']==1){
            return $uom;
        }else{
            $qty = 0;
            if($uom=="LB"){
                $qty = @$data['qty'] ? @$data['qty'] : 0;
            }else{
                //$qty = @$data['shipped_qty'] ? @$data['shipped_qty'] : $data['qty'];
                $qty = @$data['shipped_qty'] ? @$data['shipped_qty'] : 0;
            }
            return $qty;

        }
    }

    public function CustomPrice($info=[])
    {
        $uomdef = ['Each'=>'EA','Case'=>'CA','Gram'=>'GR','Pounds'=>'LB','Pound'=>'LB','Lbs'=>'LB','LBs'=>'LB'];
        $price = "";
        if($info['user_integration_id']==163 && $uomdef[$info['uom']]=='LB'){
            $price = round(floatval($info['total']) / floatval($info['shipped_qty']),2);
        }else if($info['user_integration_id']==174 && isset($info['product_code']) && isset($info['customer_code'])){
            if($info['product_code']=='00280' && $info['customer_code']=='C-10664' && $uomdef[$info['uom']]=='CA'){
                $price = @$info['price'] ? @$info['price'] : @$info['unit_price'];
            }else{
                $price = @$info['unit_price'] ? @$info['unit_price'] : @$info['total'];
            }
        }else{
            $price = @$info['unit_price'] ? @$info['unit_price'] : @$info['total'];
        }
        return $price;
    }

    public function OrderAdditinalWhereConditions($info=[])
    {
        $where = ['platform_id' => $info['platform_id'], 'user_integration_id' => $info['user_integration_id'], 'api_order_id' => $info['api_order_id']];

        if($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178){
            $where['order_date'] = $info['order_date'];
        }

        return $where;
    }

    public function CustomBillOfLadingNumber($info=[])
    {
        $returndata = $info['default_value'];
        if($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178){
            $returndata = $info['custom_value'];
        }

        return $returndata;
    }


    // need to change item as per client requirements
    public function AllowItemExchange($info=[])
    {
        $is_allowed = false;
        if(($info['user_integration_id']==163 || $info['user_integration_id']==174 || $info['user_integration_id']==175 || $info['user_integration_id']==176 || $info['user_integration_id']==177 || $info['user_integration_id']==178)){
            $is_allowed = true;
        }
        return $is_allowed;
    }


    // need to change item as per client requirements
    public function AllowDiscountFields($info=[])
    {
        $is_allowed = true;
        if($info['user_integration_id']==163){
            $is_allowed = false;
        }
        return $is_allowed;
    }






}
