<?php

namespace App\Http\Controllers\Kefron;

use DB;
use Auth;
use Mail;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;

class KefronUtility extends Model
{

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function GetStructuredPOPostData($po_data)
    {

        $po_list = array();
			
        $list = array();
        $list[] = "Supplier_Name";
        $list[] = "Supplier_Code";
        $list[] = "PO_NUMBER";
        $list[] = "Order_Date";
        $list[] = "Currency";
        $list[] = "Net_Total";
        $list[] = "Tax_Total";
        $list[] = "Discount";
        $list[] = "Order_Total";
        $list[] = "LI_ProductCode";
        $list[] = "LI_Description";
        $list[] = "LI_GL_Code";
        $list[] = "LI_Quantity";
        $list[] = "LI_Unit_Price";
        $list[] = "LI_Net";
        $list[] = "LI_VAT_Code";
        $list[] = "LI_Tax";
        $list[] = "LI_Line_Total";
        $po_list[] = $list;

        $index = 1;
        foreach($po_data as $row){
            $list = array();
            $list[] = $row['supplier_name'];
            $list[] = $row['supplier_code'];
            $list[] = $row['po_number'];
            $list[] = $row['order_date'];
            $list[] = $row['currency'];
            $list[] = $row['net_total'];
            $list[] = $row['tax_total'];
            $list[] = $row['discount'];
            $list[] = $row['order_total'];
            $list[] = $row['li_productcode'];
            $list[] = $row['li_description'];
            $list[] = $row['li_gl_code'];
            $list[] = $row['li_quantity'];
            $list[] = $row['li_unit_price'];
            $list[] = $row['li_net'];
            $list[] = $row['li_vat_code'];
            $list[] = $row['li_tax'];
            $list[] = $row['li_line_total'];
            $po_list[] = $list;


            $index++;
            
        }



        return $po_list;
    }


    public function GetStructuredGRNPostData($goods_data){

        $goods_list = array();
			
        $list = array();
        $list[] = "Supplier_Name";
        $list[] = "Supplier_Code";
        $list[] = "PO_NUMBER";
        $list[] = "Order_Date";
        $list[] = "Delivery_Date";
        $list[] = "LI_ProductCode";
        $list[] = "LI_Description";
        $list[] = "LI_GL_Code";
        $list[] = "LI_Quantity";
        $list[] = "LI_Unit_Price";
        $list[] = "LI_Net";
        $list[] = "LI_Tax";
        $list[] = "LI_Line_Total";
        $goods_list[] = $list;

        $index = 1;
        foreach($goods_data as $row){
            $list = array();
            $list[] = $row['supplier_name'];
            $list[] = $row['supplier_code'];
            $list[] = $row['po_number'];
            $list[] = $row['order_date'];
            $list[] = $row['delivery_date'];
            $list[] = $row['li_productcode'];
            $list[] = $row['li_description'];
            $list[] = $row['li_gl_code'];
            $list[] = $row['li_quantity'];
            $list[] = $row['li_unit_price'];
            $list[] = $row['li_net'];
            $list[] = $row['li_tax'];
            $list[] = $row['li_line_total'];
            $goods_list[] = $list;


            $index++;
            
        }

        return $goods_list;
    }
   

   
}



