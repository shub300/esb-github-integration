<?php

namespace App\Http\Controllers\Tipalti;

use DB;
use Auth;
use Mail;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;

class TipaltiUtility extends Model
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
        $list[] = "Purchase order date";
        $list[] = "Purchase order number";
        $list[] = "Payer Entity";
        $list[] = "Currency Code";
        $list[] = "Status";
        $list[] = "Payee ID";
        $list[] = "Memo";
        $list[] = "Approvers list";
        $list[] = "PO line number";
        $list[] = "PO line description";
        $list[] = "PO line type";
        $list[] = "Item Code";
        $list[] = "GL account";
        $list[] = "Unit ID";
        $list[] = "Amount";
        $list[] = "Rate";
        $list[] = "Quantity";
        $list[] = "Tax Amount";
        $list[] = "Tax code";
        $list[] = "Is Closed?";
        $po_list[] = $list;

        foreach($po_data as $row){
            $list = array();
            $list[] = $row['order_date'];
            $list[] = $row['po_number'];
            $list[] = $row['supplier_name'];
            $list[] = $row['currency'];
            $list[] = "Active";
            $list[] = $row['supplier_code'];
            $list[] = "";//memo
            $list[] = "";//Approvers list
            $list[] = $row['li_id'];
            $list[] = $row['li_description'];
            $list[] = "Item";
            $list[] = $row['li_productcode'];
            $list[] = $row['li_gl_code'];
            $list[] = "";//Unit ID
            $list[] = $row['li_line_total'];
            $list[] = $row['li_price'];
            $list[] = $row['li_quantity'];
            $list[] = $row['li_tax'];
            $list[] = $row['li_vat_code'];
            $list[] = $row['is_closed'];
            $po_list[] = $list;

        }



        return $po_list;
    }


    public function GetStructuredGRNPostData($goods_data){

        $goods_list = array();

        $list = array();
        $list[] = "Receipt number";
        $list[] = "Status";
        $list[] = "Payee ID";
        $list[] = "Receipt date";
        $list[] = "Payer entity";
        $list[] = "Shipment date";
        $list[] = "Notes";
        $list[] = "Receipt by";
        $list[] = "Purchase order number";
        $list[] = "Invoice number";
        $list[] = "Item code";
        $list[] = "Item description";
        $list[] = "Item units";
        $list[] = "Quantity shipped";
        $list[] = "Line notes";
        $list[] = "PO line ID";
        $goods_list[] = $list;

        foreach($goods_data as $row){
            $list = array();
            $list[] = $row['receipt_number'];
            $list[] = "Active";
            $list[] = $row['supplier_code'];
            $list[] = $row['order_date'];
            $list[] = $row['supplier_name'];
            $list[] = $row['delivery_date'];
            $list[] = $row['li_description'];//notes
            $list[] = "";//Receipt by
            $list[] = $row['po_number'];
            $list[] = "";//Invoice number
            $list[] = $row['li_productcode'];
            $list[] = $row['li_description'];
            $list[] = "";//Item units
            $list[] = $row['li_quantity'];
            $list[] = "";//Line notes
            $list[] = $row['li_id'];//Line Id
            $goods_list[] = $list;

        }

        return $goods_list;
    }

}



