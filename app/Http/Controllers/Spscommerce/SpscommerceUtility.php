<?php

namespace App\Http\Controllers\Spscommerce;

use DB;
use Auth;
use Mail;
use App\Helper\FieldMappingHelper;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use Illuminate\Database\Eloquent\Model;
//use App\Http\Controllers\Spscommerce\SpscommerceApiController;
use Illuminate\Support\LazyCollection;

class SpscommerceUtility extends Model
{

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->map = new FieldMappingHelper();
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper();
    }



    public function GetStructuredInvoicePostData($user_id,$user_integration_id,$platform_workflow_rule_id,$mapped_file,$inv_detail)
    {


        $file_name = $inv_detail->file_name;
        $invoice_date = @$inv_detail->invoice_date ? date('Y-m-d',strtotime($inv_detail->invoice_date)) : '';
        $ship_date = @$inv_detail->ship_date ? date('Y-m-d',strtotime($inv_detail->ship_date)) : '';
        $ship_by_date = @$inv_detail->ship_by_date ? date('Y-m-d',strtotime($inv_detail->ship_by_date)) : '';
        $ship_via = @$inv_detail->ship_via;
        $invoice_code = @$inv_detail->invoice_code;
        $tracking_number = @$inv_detail->tracking_number;
        $TradingPartnerId = @$inv_detail->trading_partner_id;
        $total_amt = @$inv_detail->total_amt;
        $total_qty = @$inv_detail->total_qty;
        $due_days = @$inv_detail->due_days;

/*
        $trading_partner_1 = '71AALLMANDAPACK';//Associated Grocers - LA
        $trading_partner_2 = '73PALLMANDAPACK';//Associated Grocers of the South
        $trading_partner_3 = '755ALLMANDAPACK';//Associated Wholesale Grocers (AWG)
        $trading_partner_4 = '5W4ALLMANDAPACK';//Brookshire Grocery Company
        $trading_partner_5 = '';//C&S Wholesale
        $trading_partner_6 = '';//Dollar General
        $trading_partner_7 = '';//Labatt Food Service Llc
        $trading_partner_8 = '1MBALLMANDAPACK';//Reinhart Foodservice OSR
        $trading_partner_9 = '';//SAFEWAY
        $trading_partner_10 = '';//Save-A-Lot, Ltd
        $trading_partner_11 = '';//Schnuck Markets Inc
        $trading_partner_12 = '027ALLMANDAPACK';//Supervalu Holdings, Inc.
        $trading_partner_13 = '';//Sysco Corp
        $trading_partner_14 = '';//US Foods
        $trading_partner_15 = '529ALLMANDAPACK';//Walmart-USA

*/


        $csvFile = file(base_path().'/'.$mapped_file);
        $data = [];
        $i = 0;
        $mappings = [];
        foreach ($csvFile as $line) {
            if($i!=0){
                $arrrow = str_getcsv($line);

                $fields = explode('/',$arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[1] having default value
                if(count($fields)==2 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]] = $arrrow[1];
                }else if(count($fields)==3 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]][$fields[2]] = $arrrow[1];
                }else if(count($fields)==4 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]] = $arrrow[1];
                }else if(count($fields)==5 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[1];
                }else if(count($fields)==6 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[1];
                }else if(count($fields)==7 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[1];
                }else if(count($fields)==8 && trim($arrrow[1])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[1];
                }

            }
            $i++;
        }


        $data = file_get_contents(public_path().'/esb_asset/spscommerce/'.$user_integration_id.'/'.$file_name);

        /*
        //(commented)   -> it means already going from PO but in-case of change value we have map it
        ////M           -> having different dropdown values & mandatory fields
        //M             -> text value & mandatory fields

        */

        $res = str_replace('OrderHeader','InvoiceHeader',$data);
        $res = str_replace('OrderLine','InvoiceLine',$res);

        $res = json_decode($res,true);


        /*if($TradingPartnerId==$trading_partner_10){
            //purchase order date CCYYMMDD
            //invoice date CCYYMMDD
            //Dates/Date CCYYMMDD
        }*/

        $res['Header']['InvoiceHeader']['InvoiceNumber'] = $invoice_code;//M
        $res['Header']['InvoiceHeader']['InvoiceDate'] = $invoice_date;//M

        /*if($TradingPartnerId==$trading_partner_6 && isset($res['Header']['InvoiceHeader']['PrimaryPOTypeCode']) && trim($res['Header']['InvoiceHeader']['PrimaryPOTypeCode'])=='SD'){

        }else{*/
            $res['Header']['InvoiceHeader']['PrimaryPOTypeCode'] = $mappings['Header']['InvoiceHeader']['PrimaryPOTypeCode'];//
        //}


        /*if($TradingPartnerId==$trading_partner_15 && isset($res['Header']['InvoiceHeader']['InvoiceTypeCode']) && trim($res['Header']['InvoiceHeader']['InvoiceTypeCode'])=='00099'){
            $res['Header']['InvoiceHeader']['InvoiceTypeCode'] = 'CT';//
        }else{*/

            $res['Header']['InvoiceHeader']['InvoiceTypeCode'] = $mappings['Header']['InvoiceHeader']['InvoiceTypeCode'];//

        //}


        $res['Header']['InvoiceHeader']['TsetPurposeCode'] = $mappings['Header']['InvoiceHeader']['TsetPurposeCode'];//
        //$res['Header']['InvoiceHeader']['ExchangeRate'] = '';
        //$res['Header']['InvoiceHeader']['InternalOrderNumber'] = ''; //doubt
        //$res['Header']['InvoiceHeader']['JobNumber'] = '';
        //$res['Header']['InvoiceHeader']['CustomerAccountNumber'] = '';
        $res['Header']['InvoiceHeader']['CarrierProNumber'] = $tracking_number;
        //$res['Header']['InvoiceHeader']['BillOfLadingNumber'] = '';
        $res['Header']['InvoiceHeader']['ShipDate'] = $ship_date;//M
        $res['Header']['InvoiceHeader']['ShipDeliveryDate'] = $ship_by_date;//M


        /***********PaymentTerms fields************/

        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['PaymentTerms']['TermsType']!=''){
            $mapped_list = explode(',',$mappings['Header']['PaymentTerms']['TermsType']);
        }

        if(isset($res['Header']['PaymentTerms'])){
            foreach($res['Header']['PaymentTerms'] as $key=>$val){
                if(isset($res['Header']['PaymentTerms'][$key]['TermsType'])){
                    if (in_array($res['Header']['PaymentTerms'][$key]['TermsType'], $mapped_list)){
                        $res['Header']['PaymentTerms'][$key]['TermsType'] = $res['Header']['PaymentTerms'][$key]['TermsType'];////M
                        $res['Header']['PaymentTerms'][$key]['TermsBasisDateCode'] = $mappings['Header']['PaymentTerms']['TermsBasisDateCode'];////M
                        //$res['Header']['PaymentTerms'][$key]['TermsDeferredDueDate'] = '';
                        //$res['Header']['PaymentTerms'][$key]['TermsDeferredAmountDue'] = '';
                        //$res['Header']['PaymentTerms'][$key]['TermsDueDay'] = $mappings['Header']['PaymentTerms']['TermsDueDay'];
                        //$res['Header']['PaymentTerms'][$key]['PaymentMethodCode'] = $mappings['Header']['PaymentTerms']['PaymentMethodCode'];
                        //$res['Header']['PaymentTerms'][$key]['TermsStartDate'] = '';
                        //$res['Header']['PaymentTerms'][$key]['TermsDueDateQual'] = $mappings['Header']['PaymentTerms']['TermsDueDateQual'];


                        unset($res['Header']['PaymentTerms'][$key]['PaymentMethodID']);

                        $mapped_list = array_diff($mapped_list, [$res['Header']['PaymentTerms'][$key]['TermsType']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['PaymentTerms'][$key]);
                    }
                }
            }
        }


        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['PaymentTerms'][$last_key]['TermsType'] = $row;
                $res['Header']['PaymentTerms'][$last_key]['TermsBasisDateCode'] = $mappings['Header']['PaymentTerms']['TermsBasisDateCode'];////M
                //$res['Header']['PaymentTerms'][$last_key]['TermsDiscountPercentage'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDiscountDate'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDiscountDueDays'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsNetDueDate'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsNetDueDays'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDiscountAmount'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDeferredDueDate'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDeferredAmountDue'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['PercentOfInvoicePayable'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDescription'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDueDay'] = $mappings['Header']['PaymentTerms']['TermsDueDay'];
                //$res['Header']['PaymentTerms'][$last_key]['PaymentMethodCode'] = $mappings['Header']['PaymentTerms']['PaymentMethodCode'];
                //$res['Header']['PaymentTerms'][$last_key]['TermsStartDate'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['TermsDueDateQual'] = $mappings['Header']['PaymentTerms']['TermsDueDateQual'];
                //$res['Header']['PaymentTerms'][$last_key]['AmountSubjectToDiscount'] = '';
                //$res['Header']['PaymentTerms'][$last_key]['DiscountAmountDue'] = '';

                $last_key++;
            }
        }*/
        /***********End PaymentTerms fields************/

        /***********Dates fields************/

        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['Dates']['DateTimeQualifier']!=''){
            $mapped_list = explode(',',$mappings['Header']['Dates']['DateTimeQualifier']);
        }

        if(isset($res['Header']['Dates'])){
            foreach($res['Header']['Dates'] as $key=>$val){
                if(isset($res['Header']['Dates'][$key]['DateTimeQualifier'])){
                    if (in_array($res['Header']['Dates'][$key]['DateTimeQualifier'], $mapped_list)){
                        //$res['Header']['Dates'][$key]['DateTimeQualifier'] = $res['Header']['Dates'][$key]['DateTimeQualifier'];////M

                        unset($res['Header']['Dates'][$key]['Time']);
                        $mapped_list = array_diff($mapped_list, [$res['Header']['Dates'][$key]['DateTimeQualifier']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['Dates'][$key]);
                    }
                }
            }
        }

        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['Dates'][$last_key]['DateTimeQualifier'] = $row;
                //$res['Header']['Dates'][$last_key]['Date'] = '';
                $last_key++;
            }
        }*/


        /***********End Dates fields************/

        /***********Contacts fields************/

        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['Contacts']['ContactTypeCode']!=''){
            $mapped_list = explode(',',$mappings['Header']['Contacts']['ContactTypeCode']);
        }

        if(isset($res['Header']['Contacts'])){
            foreach($res['Header']['Contacts'] as $key=>$val){
                if(isset($res['Header']['Contacts'][$key]['ContactTypeCode'])){
                    if (in_array($res['Header']['Contacts'][$key]['ContactTypeCode'], $mapped_list)){
                        //$res['Header']['Contacts'][$key]['ContactTypeCode'] = $res['Header']['Contacts'][$key]['ContactTypeCode'];////M
                        $mapped_list = array_diff($mapped_list, [$res['Header']['Contacts'][$key]['ContactTypeCode']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['Contacts'][$key]);
                    }
                }
            }
        }

        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['Contacts'][$last_key]['ContactTypeCode'] = $row;
                //$res['Header']['Contacts'][$last_key]['ContactName'] = "";
                //$res['Header']['Contacts'][$last_key]['PrimaryPhone'] = "";
                //$res['Header']['Contacts'][$last_key]['ContactTypeCode'] = "";
                //$res['Header']['Contacts'][$last_key]['PrimaryEmail'] = "";
                $last_key++;
            }
        }*/
        /***********End Contacts fields************/




        /***********Address Fields************/
        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['Address']['AddressTypeCode']!=''){
            $mapped_list = explode(',',$mappings['Header']['Address']['AddressTypeCode']);
        }

        if(isset($res['Header']['Address'])){
            foreach($res['Header']['Address'] as $key=>$val){
                if(isset($res['Header']['Address'][$key]['AddressTypeCode'])){

                    if (in_array($res['Header']['Address'][$key]['AddressTypeCode'], $mapped_list)){
                        //$res['Header']['Address'][$key]['AddressTypeCode'] = $res['Header']['Address'][$key]['AddressTypeCode'];////M  //doubt for field mapping
                        //$res['Header']['Address'][$key]['LocationCodeQualifier'] = $mappings['Header']['Address']['LocationCodeQualifier'];////M


                        if($res['Header']['Address'][$key]['AddressTypeCode']=='RI'){
                            $res['Header']['Address'][$key]['Address1'] = $mappings['Header']['Address']['Address1'];
                            $res['Header']['Address'][$key]['City'] = $mappings['Header']['Address']['City'];
                            $res['Header']['Address'][$key]['State'] = $mappings['Header']['Address']['State'];
                            $res['Header']['Address'][$key]['PostalCode'] = $mappings['Header']['Address']['PostalCode'];
                            $res['Header']['Address'][$key]['Country'] = $mappings['Header']['Address']['Country'];


                        }else {

                            if(isset($res['Header']['Address'][$key]['LocationCodeQualifier']) && trim($res['Header']['Address'][$key]['LocationCodeQualifier'])==''){//$TradingPartnerId==$trading_partner_5 && trim($res['Header']['Address'][$key]['AddressLocationNumber'])!='' &&
                                $res['Header']['Address'][$key]['LocationCodeQualifier'] = $mappings['Header']['Address']['LocationCodeQualifier'];
                            }
                            if(isset($val['Contacts'])){
                                foreach($val['Contacts'] as $key1=>$val1){
                                    $res['Header']['Address'][$key]['Contacts'][$key1]['ContactTypeCode'] = $mappings['Header']['Address']['Contacts']['ContactTypeCode'];//M

                                    unset($res['Header']['Address'][$key]['Contacts'][$key1]['PrimaryFax']);
                                    unset($res['Header']['Address'][$key]['Contacts'][$key1]['PrimaryEmail']);
                                    unset($res['Header']['Address'][$key]['Contacts'][$key1]['ContactReference']);
                                }
                            }

                        }



                        unset($res['Header']['Address'][$key]['LocationID']);

                        $mapped_list = array_diff($mapped_list, [$res['Header']['Address'][$key]['AddressTypeCode']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['Address'][$key]);
                    }
                }
            }
        }


        if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                if($row=='RI'){
                    $res['Header']['Address'][$last_key]['AddressTypeCode'] = $row;
                    $res['Header']['Address'][$last_key]['Address1'] = $mappings['Header']['Address']['Address1'];
                    $res['Header']['Address'][$last_key]['City'] = $mappings['Header']['Address']['City'];
                    $res['Header']['Address'][$last_key]['State'] = $mappings['Header']['Address']['State'];
                    $res['Header']['Address'][$last_key]['PostalCode'] = $mappings['Header']['Address']['PostalCode'];
                    $res['Header']['Address'][$last_key]['Country'] = $mappings['Header']['Address']['Country'];
                    $last_key++;
                }else{

                    //$res['Header']['Address'][$last_key]['AddressTypeCode'] = $row;
                    //$res['Header']['Address'][$last_key]['LocationCodeQualifier'] = $mappings['Header']['Address']['LocationCodeQualifier'];////M
                    //$res['Header']['Address'][$last_key]['AddressLocationNumber'] = "";
                    //$res['Header']['Address'][$last_key]['AddressName'] = "";
                    //$res['Header']['Address'][$last_key]['AddressAlternateName'] = "";
                    //$res['Header']['Address'][$last_key]['AddressAlternateName2'] = "";
                    //$res['Header']['Address'][$last_key]['Address1'] = "";
                    //$res['Header']['Address'][$last_key]['Address2'] = "";
                    //$res['Header']['Address'][$last_key]['Address3'] = "";
                    //$res['Header']['Address'][$last_key]['Address4'] = "";
                    //$res['Header']['Address'][$last_key]['City'] = "";
                    //$res['Header']['Address'][$last_key]['State'] = "";
                    //$res['Header']['Address'][$last_key]['PostalCode'] = "";
                    //$res['Header']['Address'][$last_key]['Country'] = "";
                    //$last_key++;
                }

            }
        }

        /*********** End Address Fields************/

        /***********References fields************/

        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['References']['ReferenceQual']!=''){
            $mapped_list = explode(',',$mappings['Header']['References']['ReferenceQual']);
        }

        if(isset($res['Header']['References'])){
            foreach($res['Header']['References'] as $key=>$val){
                if(isset($res['Header']['References'][$key]['ReferenceQual'])){
                    if (in_array($res['Header']['References'][$key]['ReferenceQual'], $mapped_list)){
                        //$res['Header']['References'][$key]['ReferenceQual'] = $res['Header']['References'][$key]['ReferenceQual'];////M
                        //$res['Header']['References'][$key]['ReferenceID'] = '';////M   //doubt for field mapping

                        unset($res['Header']['References'][$key]['Date']);
                        unset($res['Header']['References'][$key]['Time']);

                        $mapped_list = array_diff($mapped_list, [$res['Header']['References'][$key]['ReferenceQual']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['References'][$key]);
                    }
                }
            }
        }

        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['References'][$last_key]['ReferenceQual'] = $row;
                //$res['Header']['References'][$last_key]['ReferenceID'] = "";
                //$res['Header']['References'][$last_key]['Description'] = "";
                $last_key++;
            }
        }*/

        /***********End References fields************/

        /***********Notes fields************/

        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['Notes']['NoteCode']!=''){
            $mapped_list = explode(',',$mappings['Header']['Notes']['NoteCode']);
        }

        if(isset($res['Header']['Notes'])){
            foreach($res['Header']['Notes'] as $key=>$val){
                if(isset($res['Header']['Notes'][$key]['NoteCode'])){

                    if (in_array($res['Header']['Notes'][$key]['NoteCode'], $mapped_list)){
                        //$res['Header']['Notes'][$key]['NoteCode'] = $res['Header']['Notes'][$key]['NoteCode'];////M

                        $mapped_list = array_diff($mapped_list, [$res['Header']['Notes'][$key]['NoteCode']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['Notes'][$key]);
                    }

                }

            }
        }

        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['Notes'][$last_key]['NoteCode'] = $row;
                //$res['Header']['Notes'][$last_key]['Note'] = "";
                $last_key++;
            }
        }*/

        /***********End Notes fields************/



        /***********Tax fields************/
        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['Taxes']['TaxTypeCode']!=''){
            $mapped_list = explode(',',$mappings['Header']['Taxes']['TaxTypeCode']);
        }

        if(isset($res['Header']['Taxes'])){
            foreach($res['Header']['Taxes'] as $key=>$val){
                if(isset($res['Header']['Taxes'][$key]['TaxTypeCode'])){
                    if (in_array($res['Header']['Taxes'][$key]['TaxTypeCode'], $mapped_list)){
                        //$res['Header']['Taxes'][$key]['TaxTypeCode'] = $res['Header']['Taxes'][$key]['TaxTypeCode'];////M
                        //$res['Header']['Taxes'][$key]['TaxAmount'] = '0';//M
                        //$res['Header']['Taxes'][$key]['TaxPercentQual'] = $mappings['Header']['Taxes']['TaxPercentQual'];//
                        //$res['Header']['Taxes'][$key]['JurisdictionQual'] = $mappings['Header']['Taxes']['JurisdictionQual'];
                        //$res['Header']['Taxes'][$key]['JurisdictionCode'] = '';
                        //$res['Header']['Taxes'][$key]['PercentDollarBasis']= '';
                        //$res['Header']['Taxes'][$key]['TaxHandlingCode']= $mappings['Header']['Taxes']['TaxHandlingCode'];//M
                        //$res['Header']['Taxes'][$key]['TaxID']= '';
                        //$res['Header']['Taxes'][$key]['Description']= '';

                        unset($res['Header']['Taxes'][$key]['TaxExemptCode']);

                        $mapped_list = array_diff($mapped_list, [$res['Header']['Taxes'][$key]['TaxTypeCode']]);
                        $last_key = $key + 1;
                    }else{
                        //unset($res['Header']['Taxes'][$key]);
                    }

                }

            }
        }

        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['Taxes'][$last_key]['TaxTypeCode'] = $row;
                //$res['Header']['Taxes'][$last_key]['TaxAmount'] = '0';//M
                //$res['Header']['Taxes'][$last_key]['TaxPercentQual'] = $mappings['Header']['Taxes']['TaxPercentQual'];//
                //$res['Header']['Taxes'][$last_key]['TaxPercent'] = '';
                //$res['Header']['Taxes'][$last_key]['JurisdictionQual'] = $mappings['Header']['Taxes']['JurisdictionQual'];
                //$res['Header']['Taxes'][$last_key]['JurisdictionCode'] = '';
                //$res['Header']['Taxes'][$last_key]['PercentDollarBasis']= '';
                //$res['Header']['Taxes'][$last_key]['TaxHandlingCode']= $mappings['Header']['Taxes']['TaxHandlingCode'];//M
                //$res['Header']['Taxes'][$last_key]['TaxID']= '';
                //$res['Header']['Taxes'][$last_key]['Description']= '';
                $last_key++;
            }
        }*/
        /***********End Tax fields************/


        /***********ChargesAllowances fields************/
/*
        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['ChargesAllowances']['AllowChrgIndicator']!=''){
            $mapped_list = explode(',',$mappings['Header']['ChargesAllowances']['AllowChrgIndicator']);
        }

        if(isset($res['Header']['ChargesAllowances'])){
            foreach($res['Header']['ChargesAllowances'] as $key=>$val){
                if(isset($res['Header']['ChargesAllowances'][$key]['AllowChrgIndicator'])){
                    if (in_array($res['Header']['ChargesAllowances'][$key]['AllowChrgIndicator'], $mapped_list)){
                        //$res['Header']['ChargesAllowances'][$key]['AllowChrgIndicator'] = $res['Header']['ChargesAllowances'][$key]['AllowChrgIndicator']; //
                        //$res['Header']['ChargesAllowances'][$key]['AllowChrgCode'] = ''; // //doubt for field mapping
                        //$res['Header']['ChargesAllowances'][$key]['AllowChrgPercentQual'] = $mappings['Header']['ChargesAllowances']['AllowChrgPercentQual'];//
                        //$res['Header']['ChargesAllowances'][$key]['PercentDollarBasis'] = $mappings['Header']['ChargesAllowances']['PercentDollarBasis'];//
                        //$res['Header']['ChargesAllowances'][$key]['AllowChrgQtyUOM'] = $mappings['Header']['ChargesAllowances']['AllowChrgQtyUOM'];//
                        //$res['Header']['ChargesAllowances'][$key]['AllowChrgHandlingCode'] = $mappings['Header']['ChargesAllowances']['AllowChrgHandlingCode'];//

                        unset($res['Header']['ChargesAllowances'][$key]['AllowChrgAgencyCode']);
                        unset($res['Header']['ChargesAllowances'][$key]['AllowChrgAgency']);
                        $mapped_list = array_diff($mapped_list, [$res['Header']['ChargesAllowances'][$key]['AllowChrgIndicator']]);
                        $last_key = $key + 1;
                    }else{
                        unset($res['Header']['ChargesAllowances'][$key]);
                    }
                }
            }
        }
*/
        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['ChargesAllowances'][$last_key]['AllowChrgIndicator'] = $row;
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgCode'] = ''; //
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgAmt'] = ''; //
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgPercentQual'] = $mappings['Header']['ChargesAllowances']['AllowChrgPercentQual'];//
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgPercent'] = ''; //
                //$res['Header']['ChargesAllowances'][$last_key]['PercentDollarBasis'] = $mappings['Header']['ChargesAllowances']['PercentDollarBasis'];//
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgRate'] = ''; //
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgQtyUOM'] = $mappings['Header']['ChargesAllowances']['AllowChrgQtyUOM'];//
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgQty'] = ''; //
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgHandlingCode'] = $mappings['Header']['ChargesAllowances']['AllowChrgHandlingCode'];///$res['Header']['ChargesAllowances'][$last_key]['ReferenceIdentification'] = ''; //
                //$res['Header']['ChargesAllowances'][$last_key]['AllowChrgHandlingDescription'] = ''; //
                $last_key++;
            }
        }*/
        /***********End ChargesAllowances fields************/


        /***********FOBRelatedInstruction fields************/

        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['FOBRelatedInstruction']['FOBPayCode']!=''){
            $mapped_list = explode(',',$mappings['Header']['FOBRelatedInstruction']['FOBPayCode']);
        }

        if(isset($res['Header']['FOBRelatedInstruction'])){

            foreach($res['Header']['FOBRelatedInstruction'] as $key=>$val){
                if(isset($res['Header']['FOBRelatedInstruction'][$key]['FOBPayCode'])){
                    if (in_array($res['Header']['FOBRelatedInstruction'][$key]['FOBPayCode'], $mapped_list)){
                        //$res['Header']['FOBRelatedInstruction'][$key]['FOBPayCode'] = $res['Header']['FOBRelatedInstruction'][$key]['FOBPayCode'];////M
                        //$res['Header']['FOBRelatedInstruction'][$key]['FOBLocationQualifier'] = $mappings['Header']['FOBRelatedInstruction']['FOBLocationQualifier'];////M

                        unset($res['Header']['FOBRelatedInstruction'][$key]['FOBTitlePassageCode']);
                        unset($res['Header']['FOBRelatedInstruction'][$key]['FOBTitlePassageLocation']);
                        unset($res['Header']['FOBRelatedInstruction'][$key]['TransportationTermsType']);
                        unset($res['Header']['FOBRelatedInstruction'][$key]['TransportationTerms']);

                        $mapped_list = array_diff($mapped_list, [$res['Header']['FOBRelatedInstruction'][$key]['FOBPayCode']]);
                        $last_key = $key + 1;

                    }else{
                        //unset($res['Header']['FOBRelatedInstruction'][$key]);
                    }
                }
            }
        }

        /*if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['FOBRelatedInstruction'][$last_key]['FOBPayCode'] = $row;
                //$res['Header']['FOBRelatedInstruction'][$last_key]['FOBLocationQualifier'] = "";
                //$res['Header']['FOBRelatedInstruction'][$last_key]['FOBLocationDescription'] = "";
                $last_key++;
            }
        }*/

        /***********End FOBRelatedInstruction fields************/


        /***********CarrierInformation fields************/



        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['CarrierInformation']['StatusCode']!=''){
            $mapped_list = explode(',',$mappings['Header']['CarrierInformation']['StatusCode']);
        }


        if(isset($res['Header']['CarrierInformation'])){

            foreach($res['Header']['CarrierInformation'] as $key=>$val){
                if (isset($res['Header']['CarrierInformation'][$key]['StatusCode'])){
                    if (in_array($res['Header']['CarrierInformation'][$key]['StatusCode'], $mapped_list)){
                        //$res['Header']['CarrierInformation'][$key]['StatusCode'] = $res['Header']['CarrierInformation'][$key]['StatusCode'];////M
                        //$res['Header']['CarrierInformation'][$key]['CarrierTransMethodCode'] = $mappings['Header']['CarrierInformation']['CarrierTransMethodCode'];////M

                        //$res['Header']['CarrierInformation'][$key]['CarrierRouting'] = $ship_via;

                        unset($res['Header']['CarrierInformation'][$key]['RoutingSequenceCode']);

                        $mapped_list = array_diff($mapped_list, [$res['Header']['CarrierInformation'][$key]['StatusCode']]);
                        $last_key = $key + 1;

                    }else{
                        //unset($res['Header']['CarrierInformation'][$key]);
                    }
                }
            }
        }

        if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['CarrierInformation'][$last_key]['StatusCode'] = $row;
                $res['Header']['CarrierInformation'][$last_key]['CarrierTransMethodCode'] = $mappings['Header']['CarrierInformation']['CarrierTransMethodCode'];
                //$res['Header']['CarrierInformation'][$last_key]['CarrierAlphaCode'] = "";
                $res['Header']['CarrierInformation'][$last_key]['CarrierRouting'] = $ship_via;
                //$res['Header']['CarrierInformation'][$last_key]['CarrierEquipmentInitial'] = "";
                //$res['Header']['CarrierInformation'][$last_key]['CarrierEquipmentNumber'] = "";
                $last_key++;
            }
        }



        /***********End CarrierInformation fields************/

        /***********QuantityTotals fields************/



        $last_key = 0;
        $mapped_list = array();
        if($mappings['Header']['QuantityTotals']['QuantityTotalsQualifier']!=''){
            $mapped_list = explode(',',$mappings['Header']['QuantityTotals']['QuantityTotalsQualifier']);
        }

        if(isset($res['Header']['QuantityTotals'])){

            foreach($res['Header']['QuantityTotals'] as $key=>$val){
                if (isset($res['Header']['QuantityTotals'][$key]['QuantityTotalsQualifier'])){
                    if (in_array($res['Header']['QuantityTotals'][$key]['QuantityTotalsQualifier'], $mapped_list)){
                        //$res['Header']['QuantityTotals'][$key]['QuantityTotalsQualifier'] = $res['Header']['QuantityTotals'][$key]['QuantityTotalsQualifier'];////M

                        $mapped_list = array_diff($mapped_list, [$res['Header']['QuantityTotals'][$key]['QuantityTotalsQualifier']]);
                        $last_key = $key + 1;

                    }else{
                        //unset($res['Header']['QuantityTotals'][$key]);
                    }
                }
            }
        }

        if(count($mapped_list) > 0){
            foreach($mapped_list as $row){
                $res['Header']['QuantityTotals'][$last_key]['QuantityTotalsQualifier'] = $row;
                $res['Header']['QuantityTotals'][$last_key]['Quantity'] = $total_qty;
                $res['Header']['QuantityTotals'][$last_key]['QuantityUOM'] = $mappings['Header']['QuantityTotals']['QuantityUOM'];
                //$res['Header']['QuantityTotals'][$last_key]['Weight'] = "";
                //$res['Header']['QuantityTotals'][$last_key]['WeightUOM'] = $mappings['Header']['QuantityTotals']['WeightUOM'];
                //$res['Header']['QuantityTotals'][$last_key]['Volume'] = "";
                //$res['Header']['QuantityTotals'][$last_key]['VolumeUOM'] = $mappings['Header']['QuantityTotals']['VolumeUOM'];
                $last_key++;
            }
        }/**/



        /***********End QuantityTotals fields************/


        /*********** LineItem fields************/

        if(isset($res['LineItem'])){
            foreach($res['LineItem'] as $keyline=>$line){

                /*********** InvoiceLine fields************/

                if(isset($line['InvoiceLine']['ProductID'])){
                    foreach($line['InvoiceLine']['ProductID'] as $key=>$val){

                        //$res['LineItem'][$keyline]['InvoiceLine']['ProductID'][$key]['PartNumberQual'] = $mappings['LineItem']['InvoiceLine']['ProductID']['PartNumberQual'];
                        //$res['LineItem'][$keyline]['InvoiceLine']['ProductID'][$key]['PartNumber'] = ''; //doubt for field mapping
                    }
                }

                 /*********** End InvoiceLine fields************/

                $res['LineItem'][$keyline]['InvoiceLine']['InvoiceQty'] = @$line['InvoiceLine']['OrderQty'];//M
                $res['LineItem'][$keyline]['InvoiceLine']['InvoiceQtyUOM'] = @$line['InvoiceLine']['OrderQtyUOM'];////M
                //--$res['LineItem'][$keyline]['InvoiceLine']['InvoiceQtyUOM'] = $mappings['LineItem']['InvoiceLine']['InvoiceQtyUOM'];////M
                //--$res['LineItem'][$keyline]['InvoiceLine']['OrderQtyUOM'] = $mappings['LineItem']['InvoiceLine']['OrderQtyUOM'];////M
                //$res['LineItem'][$keyline]['InvoiceLine']['PurchasePrice'] = $mappings['LineItem']['InvoiceLine']['PurchasePrice']; //doubt for field mapping
                //$res['LineItem'][$keyline]['InvoiceLine']['PurchasePriceBasis'] = $mappings['LineItem']['InvoiceLine']['PurchasePriceBasis'];
                $res['LineItem'][$keyline]['InvoiceLine']['ShipQty'] = @$line['InvoiceLine']['OrderQty'];//M
                $res['LineItem'][$keyline]['InvoiceLine']['ShipQtyUOM'] = @$line['InvoiceLine']['OrderQtyUOM'];////M
                //--$res['LineItem'][$keyline]['InvoiceLine']['ShipQtyUOM'] = $mappings['LineItem']['InvoiceLine']['ShipQtyUOM'];////M

                //$res['LineItem'][$keyline]['InvoiceLine']['QtyLeftToReceive'] = '';
                //$res['LineItem'][$keyline]['InvoiceLine']['ProductSizeDescription'] = '';
                //$res['LineItem'][$keyline]['InvoiceLine']['Class'] = '';

                /*********** PriceInformation fields************/

                if(isset($line['PriceInformation'])){
                    foreach($line['PriceInformation'] as $key=>$val){
                        //$res['LineItem'][$keyline]['PriceInformation'][$key]['PriceTypeIDCode'] = $mappings['LineItem']['PriceInformation']['PriceTypeIDCode'];

                        unset($res['LineItem'][$keyline]['PriceInformation'][$key]['UnitPriceBasis']);
                        unset($res['LineItem'][$keyline]['PriceInformation'][$key]['ClassOfTradeCode']);
                        unset($res['LineItem'][$keyline]['PriceInformation'][$key]['Description']);
                    }
                }

                /*********** End PriceInformation fields************/

                /*********** ProductOrItemDescription fields************/

                if(isset($line['ProductOrItemDescription'])){
                    foreach($line['ProductOrItemDescription'] as $key=>$val){
                        //$res['LineItem'][$keyline]['ProductOrItemDescription'][$key]['ProductCharacteristicCode'] = $mappings['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'];//M

                        unset($res['LineItem'][$keyline]['ProductOrItemDescription'][$key]['AgencyQualifierCode']);
                        unset($res['LineItem'][$keyline]['ProductOrItemDescription'][$key]['ProductDescriptionCode']);
                        unset($res['LineItem'][$keyline]['ProductOrItemDescription'][$key]['YesOrNoResponse']);
                    }
                }
                /***********End ProductOrItemDescription fields************/

                /*********** MasterItemAttribute fields************/

                if(isset($line['MasterItemAttribute'])){
                    foreach($line['MasterItemAttribute'] as $key=>$val){

                        if(isset($val['ItemAttribute'])){
                            foreach($val['ItemAttribute'] as $key1=>$val1){
                                $res['LineItem'][$keyline]['MasterItemAttribute'][$key]['ItemAttribute'][$key1]['ItemAttributeQualifier'] = $mappings['LineItem']['MasterItemAttribute']['ItemAttribute']['ItemAttributeQualifier'];
                            }
                        }

                    }
                }
                /*********** End MasterItemAttribute fields************/

                /*********** PhysicalDetails fields************/

                $last_key = 0;
                $mapped_list = array();
                if($mappings['LineItem']['PhysicalDetails']['PackQualifier']!=''){
                    $mapped_list = explode(',',$mappings['LineItem']['PhysicalDetails']['PackQualifier']);
                }


                if(isset($line['PhysicalDetails'])){
                    foreach($line['PhysicalDetails'] as $key=>$val){
                        if(isset($res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackQualifier'])){
                            if (in_array($res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackQualifier'], $mapped_list)){

                                //$res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackQualifier'] = $mappings['LineItem']['PhysicalDetails']['PackQualifier'];////M
                                //$res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackUOM'] = $mappings['LineItem']['PhysicalDetails']['PackUOM'];
                                //$res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackWeightUOM'] = $mappings['LineItem']['PhysicalDetails']['PackWeightUOM'];
                                //$res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackVolumeUOM'] = $mappings['LineItem']['PhysicalDetails']['PackVolumeUOM'];

                                $mapped_list = array_diff($mapped_list, [$res['LineItem'][$keyline]['PhysicalDetails'][$key]['PackQualifier']]);
                                $last_key = $key + 1;
                            }else{
                                //unset($res['LineItem'][$keyline]['PhysicalDetails'][$key]);
                            }
                        }

                    }
                }


                /*if(count($mapped_list) > 0){
                    foreach($mapped_list as $row){
                        $res['LineItem'][$keyline]['PhysicalDetails'][$last_key]['PackQualifier'] = $row;
                        $last_key++;
                    }
                }*/
                /*********** End PhysicalDetails fields************/

                /*********** References fields************/
                if(isset($line['References'])){
                    foreach($line['References'] as $key=>$val){
                        $res['LineItem'][$keyline]['References'][$key]['ReferenceQual'] = $mappings['LineItem']['References']['ReferenceQual'];////M
                        $res['LineItem'][$keyline]['References'][$key]['ReferenceID'] = '';//M
                        //$val['Description'] = '';
                    }
                }
                /*********** End References fields************/

                /*********** Dates fields************/
                if(isset($line['Dates'])){
                    foreach($line['Dates'] as $key=>$val){
                        //$res['LineItem'][$keyline]['Dates'][$key]['DateTimeQualifier'] = '';////M //doubt for field mapping
                    }
                }
                /*********** End Dates fields************/

                /*********** CarrierInformation fields************/
                if(isset($line['CarrierInformation'])){
                    foreach($line['CarrierInformation'] as $key=>$val){
                        //$res['LineItem'][$keyline]['CarrierInformation'][$key]['StatusCode'] = '';
                    }
                }
                /*********** End CarrierInformation fields************/

                 /*********** Address fields************/
                if(isset($line['Address'])){
                    foreach($line['Address'] as $key=>$val){
                        //$res['LineItem'][$keyline]['Address'][$key]['AddressTypeCode'] = $mappings['LineItem']['Address']['AddressTypeCode'];////M //doubt for field mapping
                        //$res['LineItem'][$keyline]['Address'][$key]['LocationCodeQualifier'] = $mappings['LineItem']['Address']['LocationCodeQualifier'];//M

                        unset($res['LineItem'][$keyline]['Address'][$key]['AddressAlternateName']);
                        unset($res['LineItem'][$keyline]['Address'][$key]['AddressAlternateName2']);
                        unset($res['LineItem'][$keyline]['Address'][$key]['Address2']);
                        unset($res['LineItem'][$keyline]['Address'][$key]['Address3']);
                        unset($res['LineItem'][$keyline]['Address'][$key]['Address4']);
                    }
                }
                /*********** End Address fields************/

                /*********  Taxes Line item Fields **********/

                $last_key = 0;
                $mapped_list = array();
                if($mappings['LineItem']['Taxes']['TaxTypeCode']!=''){
                    $mapped_list = explode(',',$mappings['LineItem']['Taxes']['TaxTypeCode']);
                }

                if(isset($line['Taxes'])){
                    foreach($line['Taxes'] as $key=>$val){
                        if(isset($res['LineItem'][$keyline]['Taxes'][$key]['TaxTypeCode'])){
                            if (in_array($res['LineItem'][$keyline]['Taxes'][$key]['TaxTypeCode'], $mapped_list)){

                                $res['LineItem'][$keyline]['Taxes'][$key]['TaxTypeCode'] = $res['LineItem'][$keyline]['Taxes'][$key]['TaxTypeCode'];////M
                                //$res['LineItem'][$keyline]['Taxes'][$key]['TaxAmount'] = '0';//M
                                //$res['LineItem'][$keyline]['Taxes'][$key]['TaxPercentQual'] = $mappings['LineItem']['Taxes']['TaxPercentQual'];
                                //$res['LineItem'][$keyline]['Taxes'][$key]['TaxPercent'] = '';//M
                                //$res['LineItem'][$keyline]['Taxes'][$key]['PercentDollarBasis'] = '';//M
                                //$res['LineItem'][$keyline]['Taxes'][$key]['TaxHandlingCode'] = $mappings['LineItem']['Taxes']['TaxHandlingCode'];

                                unset($res['LineItem'][$keyline]['Taxes'][$key]['Description']);

                                $mapped_list = array_diff($mapped_list, [$res['LineItem'][$keyline]['Taxes'][$key]['TaxTypeCode']]);
                                $last_key = $key + 1;
                            }else{
                                //unset($res['LineItem'][$keyline]['Taxes'][$key]);
                            }
                        }

                    }
                }

                /*if(count($mapped_list) > 0){
                    foreach($mapped_list as $row){
                        $res['LineItem'][$keyline]['Taxes'][$last_key]['TaxTypeCode'] = $row;
                        $last_key++;
                    }
                }*/

                /********* End Taxes Line item Fields **********/


                /*********** ChargesAllowances fields************/
                if(isset($line['ChargesAllowances'])){
                    foreach($line['ChargesAllowances'] as $key=>$val){
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgIndicator'] = $mappings['LineItem']['ChargesAllowances']['AllowChrgIndicator'];//M
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgCode'] = '';////M  //doubt for field mapping
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgAgencyCode'] = $mappings['LineItem']['ChargesAllowances']['AllowChrgAgencyCode'];//
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgPercentQual'] = $mappings['LineItem']['ChargesAllowances']['AllowChrgPercentQual'];//
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgRate'] = '';//M
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgQtyUOM'] = $mappings['LineItem']['ChargesAllowances']['AllowChrgQtyUOM'];//
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgQty'] = '0';//M
                        //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['AllowChrgHandlingCode'] = $mappings['LineItem']['ChargesAllowances']['AllowChrgHandlingCode'];////M
                        if(isset($val['Taxes'])){
                            foreach($val['Taxes'] as $key1=>$val1){
                                // $res['LineItem'][$keyline]['ChargesAllowances'][$key]['Taxes'][$key1]['TaxTypeCode'] = $mappings['LineItem']['ChargesAllowances']['Taxes']['TaxTypeCode'];////M
                                //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['Taxes'][$key1]['TaxAmount'] = '';
                                //$res['LineItem'][$keyline]['ChargesAllowances'][$key]['Taxes'][$key1]['TaxPercent'] = '';
                            }
                        }
                    }
                }

                /*********** End ChargesAllowances fields************/

                /*********** RegulatoryCompliances fields************/

                $last_key = 0;
                $mapped_list = array();
                if($mappings['LineItem']['RegulatoryCompliances']['RegulatoryComplianceQual']!=''){
                    $mapped_list = explode(',',$mappings['LineItem']['RegulatoryCompliances']['RegulatoryComplianceQual']);
                }

                if(isset($line['RegulatoryCompliances'])){
                    foreach($line['RegulatoryCompliances'] as $key=>$val){
                        if(isset($res['LineItem'][$keyline]['RegulatoryCompliances'][$key]['RegulatoryComplianceQual'])){

                            if (in_array($res['LineItem'][$keyline]['RegulatoryCompliances'][$key]['RegulatoryComplianceQual'], $mapped_list)){
                                //$res['LineItem'][$keyline]['RegulatoryCompliances'][$key]['RegulatoryComplianceQual'] = $res['LineItem'][$keyline]['RegulatoryCompliances'][$key]['RegulatoryComplianceQual'];//
                                //$res['LineItem'][$keyline]['RegulatoryCompliances'][$key]['YesOrNoResponse'] = $mappings['LineItem']['RegulatoryCompliances']['YesOrNoResponse'];

                                $mapped_list = array_diff($mapped_list, [$res['LineItem'][$keyline]['RegulatoryCompliances'][$key]['RegulatoryComplianceQual']]);
                                $last_key = $key + 1;
                            }else{
                                //unset($res['LineItem'][$keyline]['RegulatoryCompliances'][$key]);
                            }
                        }

                    }
                }

                /*if(count($mapped_list) > 0){
                    foreach($mapped_list as $row){
                        $res['LineItem'][$keyline]['RegulatoryCompliances'][$last_key]['RegulatoryComplianceQual'] = $row;
                        $last_key++;
                    }
                }*/

                /*********** End RegulatoryCompliances fields************/


                //unset
                unset($res['LineItem'][$keyline]['InvoiceLine']['InternationalStandardBookNumber']);
                unset($res['LineItem'][$keyline]['InvoiceLine']['PurchasePriceType']);
                unset($res['LineItem'][$keyline]['InvoiceLine']['ProductSizeCode']);
                unset($res['LineItem'][$keyline]['InvoiceLine']['ProductColorCode']);
                unset($res['LineItem'][$keyline]['InvoiceLine']['ProductColorDescription']);
                unset($res['LineItem'][$keyline]['InvoiceLine']['Department']);

                unset($res['LineItem'][$keyline]['Measurements']);

                unset($res['LineItem'][$keyline]['Notes']);

                unset($res['LineItem'][$keyline]['Subline']);

                unset($res['LineItem'][$keyline]['QuantitiesSchedulesLocations']);

                unset($res['LineItem'][$keyline]['PaymentTerms']);
                unset($res['LineItem'][$keyline]['ConditionOfSale']);
                unset($res['LineItem'][$keyline]['Packaging']);

            }
        }

        /***********End LineItem fields************/


        $res['Summary']['TotalAmount']= $total_amt;
        //$res['Summary']['TotalSalesAmount']= '';
        //$res['Summary']['TotalTermsDiscountAmount']= '';
        //$res['Summary']['TotalLineItemNumber']= '';
        //$res['Summary']['InvoiceAmtDueByTermsDate']= '';



        //unset

        unset($res['Meta']);
        unset($res['Header']['InvoiceHeader']['BuyersCurrency']);
        unset($res['Header']['InvoiceHeader']['DepartmentDescription']);

        unset($res['Header']['Packaging']);
        unset($res['Header']['QuantityAndWeight']);

        unset($res['Header']['MonetaryAmounts']);

        unset($res['Header']['RegulatoryCompliances']);

        $postdata = json_encode($res,true);

        return $postdata;


    }

    function GetStructuredData($data,$ct_qualifiers,$is_qualifier=0,$map,$val,$ct_qualifier_param,$param1,$param2,$param3){

        if($is_qualifier==0){

            $last_key = 0;
            $total_qualifiers = @$ct_qualifier_param ? $ct_qualifier_param : 0;
            if($total_qualifiers==0){
                $data[$param1][$param2][$last_key][$param3] = '';
            }else{
                for($last_key=0;$last_key<$total_qualifiers;$last_key++){
                    $data[$param1][$param2][$last_key][$param3] = '';
                }
            }

        }else{
            $param = $map[$param1][$param2][$param3];

            if(isset($param)){
                $last_key = 0;
                $mapped_list = array();
                if($param!=''){
                    $mapped_list = explode(',',$param);

                    foreach($mapped_list as $val){
                        $data[$param1][$param2][$last_key][$param3] = $val;
                        $last_key++;
                    }
                }
                $ct_qualifiers[$param1][$param2][$param3] = $last_key;
            }
        }



    }

    function GetStructuredMappingData($total_qualifiers,$is_qualifier=0,$field_value,$arrparam,$param,$exist_data=[],$multi_field_values=[]){
        $last_key = 0;
        $fielddata = array();

        if($is_qualifier==1){

            $mapped_list = array();
            if($arrparam[$param]!=''){
                $mapped_list = explode(',',$arrparam[$param]);

                foreach($mapped_list as $val){
                    $fielddata[$last_key][$param] = $val;
                    $last_key++;
                }
            }
        }else{


            if($total_qualifiers==0){
                if(trim($field_value)!=''){
                    $fielddata[$last_key][$param] = $field_value;
                }
            }else{
                for($last_key=0;$last_key<$total_qualifiers;$last_key++){
                    if(isset($multi_field_values[$last_key])){
                        if(trim($multi_field_values[$last_key])!=''){
                            $fielddata[$last_key][$param] = $multi_field_values[$last_key];
                        }
                    }else{
                        if(trim($field_value)!=''){
                            $fielddata[$last_key][$param] = $field_value;
                        }
                    }

                }
            }



        }

        return ['fielddata'=>$fielddata,'ct_key'=>$last_key];
    }

    public function GetStructuredPOPostData($user_id,$user_integration_id,$platform_workflow_rule_id,$source_platform_id,$trading_partner_id='',$sync_object_id,$mapped_file,$customer_detail,$order_detail,$order_line_detail,$order_address_detail,$order_additional_detail,$is_order_acknowledge=0)
    {



        $csvFile = file(base_path().'/'.$mapped_file);

        $i = 0;
        $mappings = $mappings_fields = $mappings_custom_fields = $data =  $ct_qualifiers = [];
        foreach ($csvFile as $line) {
            if($i!=0){
                $arrrow = str_getcsv($line);

                $fields = explode('/',$arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[1] having default value & $arrrow[2]  having custom field

                /*
                if(count($fields)==2 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]] = $arrrow[2];
                }else if(count($fields)==3 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]][$fields[2]] = $arrrow[2];
                }else if(count($fields)==4 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                }else if(count($fields)==5 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                }else if(count($fields)==6 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                }else if(count($fields)==7 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                }else if(count($fields)==8 && trim($arrrow[2])!=''){
                    $mappings[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                }
                */
                if(count($fields)==2){
                    $mappings[][$fields[1]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]] = $arrrow[2];
                }else if(count($fields)==3){
                    $mappings[][$fields[1]][$fields[2]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]] = $arrrow[2];
                }else if(count($fields)==4){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                }else if(count($fields)==5){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                }else if(count($fields)==6){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                }else if(count($fields)==7){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                }else if(count($fields)==8){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                }

            }
            $i++;
        }



        //$total_line_items = count($order_line_detail);
        $field_value = $OrderStatusID =  $Vendor = $product_name = $ean = $sku = $gtin = $mpn = $upc = $qty = $unit_price = $shipping_address_name = $shipping_company = $shipping_address1 = $shipping_address2 = $shipping_address3 = $shipping_address4 = $shipping_city = $shipping_state = $shipping_postal_code = $shipping_country = $shipping_email = $shipping_phone_number = $billing_address_name = $billing_company = $billing_address1 = $billing_address2 = $billing_address3 = $billing_address4 = $billing_city = $billing_state = $billing_postal_code = $billing_country = $billing_email = $billing_phone_number = "";
        $AcknowledgementNumber = $AcknowledgementDate = "";

        $total_line_items = $line_sequence = 1;
        $total = 0;

        $default_info = "No Information";

        $customer_id = @$customer_detail->api_customer_id;
        $customer_name = @$customer_detail->customer_name;
        $customer_phone = @$customer_detail->phone;
        $customer_email = @$customer_detail->email;
        $customer_fax = @$customer_detail->fax;

        $id = $order_detail->id;
        //$PurchaseOrderNumber = @$order_detail->api_order_id;
        if($is_order_acknowledge==1){
            $PurchaseOrderNumber = @$order_detail->api_order_reference;
        }else{
            $PurchaseOrderNumber = @$order_detail->order_number;
        }
        $DeliveryDate = @$order_detail->delivery_date ? date('Y-m-d',strtotime($order_detail->delivery_date)) : '';
        $PurchaseOrderDate = @$order_detail->order_date ? date('Y-m-d',strtotime($order_detail->order_date)) : '';
        $POChangeDate = @$order_detail->updated_at ? date('Y-m-d',strtotime($order_detail->updated_at)) : '';
        $AcknowledgementDate = @$order_detail->order_date ? date('Y-m-d',strtotime($order_detail->order_date)) : '';
        $OrderRef = @$order_detail->api_order_reference ? $order_detail->api_order_reference : '';
        $linked_id = $order_detail->linked_id;
        $TotalAmount = @$order_detail->total_amount ? @$order_detail->total_amount : 0;


        $spm = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "porder_shipping_method", ['api_id','name'], 'regular', $order_detail->shipping_method);
        $ShippingMethod = isset($spm->api_id) ? $spm->api_id : '';
        $ShippingMethodName = isset($spm->name) ? $spm->name : '';

        $IsDropShip = @$order_additional_detail->is_drop_ship ? $order_additional_detail->is_drop_ship : 0;
        $CustomerOrderNumber = @$order_additional_detail->parent_order_id ? $order_additional_detail->parent_order_id : '';


        if($is_order_acknowledge==1){

            if($order_detail->order_status!=''){
                $OrderStatus = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "get_order_status", ['name'], "regular", $order_detail->order_status, "single", "destination", ['api_id', 'name']);
                $OrderStatusID = @$OrderStatus->api_id ? $OrderStatus->api_id : '';
            }

        }else{
            if($order_detail->order_status!=''){
                $OrderStatus = $this->map->getMappedDataByName($user_integration_id, null, "get_order_status", ['name'], "regular", $order_detail->order_status, "single", "destination", ['api_id', 'name']);
                $OrderStatusID = @$OrderStatus->api_id ? $OrderStatus->api_id : '';
            }
        }






        foreach($order_address_detail as $rowaddress){
            if($rowaddress->address_type=='shipping'){
                $shipping_address_name = $rowaddress->address_name;
                $shipping_company = $rowaddress->company;
                $shipping_address1 = $rowaddress->address1;
                $shipping_address2 = $rowaddress->address2;
                $shipping_address3 = $rowaddress->address3;
                $shipping_address4 = $rowaddress->address4;
                $shipping_city = $rowaddress->city;
                $shipping_state = $rowaddress->state;
                $shipping_postal_code = $rowaddress->postal_code;
                $shipping_country = $rowaddress->country;
                $shipping_email = $rowaddress->email;
                $shipping_phone_number = $rowaddress->phone_number;
            }else if($rowaddress->address_type=='billing'){
                $billing_address_name = $rowaddress->address_name;
                $billing_company = $rowaddress->company;
                $billing_address1 = $rowaddress->address1;
                $billing_address2 = $rowaddress->address2;
                $billing_address3 = $rowaddress->address3;
                $billing_address4 = $rowaddress->address4;
                $billing_city = $rowaddress->city;
                $billing_state = $rowaddress->state;
                $billing_postal_code = $rowaddress->postal_code;
                $billing_country = $rowaddress->country;
                $billing_email = $rowaddress->email;
                $billing_phone_number = $rowaddress->phone_number;
            }
        }



        foreach($mappings as $row){

            if(isset($row['Header']['OrderHeader']['TradingPartnerId']) && $trading_partner_id!=''){
                $data['Header']['OrderHeader']['TradingPartnerId'] = $trading_partner_id;
            }

            if(isset($row['Header']['OrderHeader']['PurchaseOrderNumber'])){
                //if($linked_id!=''){
                //     $data['Header']['OrderHeader']['PurchaseOrderNumber'] = $OrderRef;
                // }else{
                    $data['Header']['OrderHeader']['PurchaseOrderNumber'] = $PurchaseOrderNumber;
                // }
            }

            if(isset($row['Header']['OrderHeader']['TsetPurposeCode'])){
                if($linked_id!=''){
                    $data['Header']['OrderHeader']['TsetPurposeCode'] = @$OrderStatusID ? $OrderStatusID : $row['Header']['OrderHeader']['TsetPurposeCode'];
                }else{
                    $data['Header']['OrderHeader']['TsetPurposeCode'] = $row['Header']['OrderHeader']['TsetPurposeCode'];
                }

            }

            if(isset($row['Header']['OrderHeader']['PrimaryPOTypeCode'])){
                $PrimaryPOTypeCode = $row['Header']['OrderHeader']['PrimaryPOTypeCode'];
                if($IsDropShip==1){
                    $PrimaryPOTypeCode = 'DS';
                }else{
                    $PrimaryPOTypeCode = 'SA';
                }
                $data['Header']['OrderHeader']['PrimaryPOTypeCode'] = $PrimaryPOTypeCode;
            }

            if(isset($row['Header']['OrderHeader']['PurchaseOrderDate'])){
                $data['Header']['OrderHeader']['PurchaseOrderDate'] = $PurchaseOrderDate;
            }

            if(isset($row['Header']['OrderHeader']['POChangeDate']) && $linked_id!=''){
                $data['Header']['OrderHeader']['POChangeDate'] = $POChangeDate;
            }



            if(isset($row['Header']['OrderHeader']['Vendor'])){
                $data['Header']['OrderHeader']['Vendor'] = $customer_id;
            }


            if(isset($row['Header']['OrderHeader']['CustomerOrderNumber'])){
                if($IsDropShip==1){
                    $data['Header']['OrderHeader']['CustomerOrderNumber'] = $CustomerOrderNumber;
                }
            }


            if($is_order_acknowledge==1){
                if(isset($row['Header']['OrderHeader']['AcknowledgementType'])){
                    $data['Header']['OrderHeader']['AcknowledgementType'] = @$OrderStatusID ? $OrderStatusID : $row['Header']['OrderHeader']['AcknowledgementType'];
                }

                if(isset($row['Header']['OrderHeader']['AcknowledgementNumber']) && $AcknowledgementNumber!=''){
                    $data['Header']['OrderHeader']['AcknowledgementNumber'] = $AcknowledgementNumber;
                }

                if(isset($row['Header']['OrderHeader']['AcknowledgementDate']) && $AcknowledgementDate!=''){
                    $data['Header']['OrderHeader']['AcknowledgementDate'] = $AcknowledgementDate;
                }
            }


            /***********PaymentTerms fields************/

            if(isset($row['Header']['PaymentTerms']['TermsType'])){
                $field_value = $row['Header']['PaymentTerms']['TermsType'];
                if($field_value!=''){

                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();

                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['PaymentTerms'],'TermsType');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['PaymentTerms']['TermsType'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['PaymentTerms']['TermsType'] ? $ct_qualifiers['Header']['PaymentTerms']['TermsType'] : 0;

            if(isset($row['Header']['PaymentTerms']['TermsDescription'])){
                $custom_field_value = "";
                if(isset($mappings_custom_fields['Header']['PaymentTerms']['TermsDescription'])){
                    $custom_field_name = $mappings_custom_fields['Header']['PaymentTerms']['TermsDescription'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }

                $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();

                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsDescription');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }


            /***********End PaymentTerms fields************/


            /***********Dates fields************/
            if(isset($row['Header']['Dates']['DateTimeQualifier'])){
                $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                $field_value = $row['Header']['Dates']['DateTimeQualifier'];
                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Dates'],'DateTimeQualifier');
                $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] = $returndata['ct_key'];
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Dates']['DateTimeQualifier'] ? $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] : 0;

            if(isset($row['Header']['Dates']['Date'])){
                $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                $field_value = $DeliveryDate;
                $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Dates'],'Date');
                $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);

            }

            /***********End Dates fields************/

            /***********Contacts fields************/
            if(isset($row['Header']['Contacts']['ContactTypeCode'])){

                $field_value = $row['Header']['Contacts']['ContactTypeCode'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Contacts'],'ContactTypeCode');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] = $returndata['ct_key'];
                }
            }



            $total_qualifiers = @$ct_qualifiers['Header']['Contacts']['ContactTypeCode'] ? $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] : 0;

            if(isset($row['Header']['Contacts']['ContactName'])){
                $field_value = @$row['Header']['Contacts']['ContactName'] ? $row['Header']['Contacts']['ContactName'] : $customer_name;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'ContactName');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryPhone'])){
                $field_value = @$row['Header']['Contacts']['PrimaryPhone'] ? $row['Header']['Contacts']['PrimaryPhone'] : $customer_phone;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryPhone');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryEmail'])){
                $field_value = @$row['Header']['Contacts']['PrimaryEmail'] ? $row['Header']['Contacts']['PrimaryEmail'] : $customer_email;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryEmail');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['Contacts']['PrimaryFax'])){
                $field_value = @$row['Header']['Contacts']['PrimaryFax'] ? $row['Header']['Contacts']['PrimaryFax'] : $customer_fax;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryFax');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            /***********End Contacts fields************/

            /***********Address Fields************/
            if(isset($row['Header']['Address']['AddressTypeCode'])){
                $field_value = $row['Header']['Address']['AddressTypeCode'];
                if($field_value!=''){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Address'],'AddressTypeCode');
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Address']['AddressTypeCode'] = $returndata['ct_key'];
                }

            }

            $total_qualifiers = @$ct_qualifiers['Header']['Address']['AddressTypeCode'] ? $ct_qualifiers['Header']['Address']['AddressTypeCode'] : 0;

            if(isset($row['Header']['Address']['LocationCodeQualifier'])){
                $field_value = $row['Header']['Address']['LocationCodeQualifier'];
                if($field_value!=''){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'LocationCodeQualifier');
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['AddressLocationNumber'])){
                $field_value = $row['Header']['Address']['AddressLocationNumber'];
                if($field_value!=''){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressLocationNumber');
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['AddressName'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_address_name;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_address_name;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['AddressName'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressName',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }

            }

            //if(isset($row['Header']['Address']['AddressAlternateName'])){
            //    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
            //    $field_value = "";
            //     $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressAlternateName');
            //    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
            //}

            if(isset($row['Header']['Address']['Address1'])){
                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_address1;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_address1;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['Address1'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address1',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }


            if(isset($row['Header']['Address']['Address2'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_address2;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_address2;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['Address2'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address2',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['Address3'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_address3;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_address3;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['Address3'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address3',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['Address4'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_address4;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_address4;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['Address4'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address4',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['City'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_city;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_city;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['City'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'City',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['State'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_state;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_state;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['State'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }

                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'State',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['PostalCode'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_postal_code;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_postal_code;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['PostalCode'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }
                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'PostalCode',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['Country'])){

                $field_value = "";
                $multi_values = [];
                if(isset($data['Header']['Address'])){
                    foreach($data['Header']['Address'] as $rowadd){
                        if($rowadd['AddressTypeCode']=='ST'){
                            $multi_values[] = $shipping_country;
                        }else if($rowadd['AddressTypeCode']=='BT'){
                            $multi_values[]  = $billing_country;
                        }else if($rowadd['AddressTypeCode']=='RI'){
                            $multi_values[]  = $row['Header']['Address']['Country'];
                        }else{
                            $multi_values[] = '';
                        }
                    }
                }
                if(count($multi_values) > 0){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Country',$data,$multi_values);
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }



            if($IsDropShip==1){

                // some special case here for more childs
                for($j=0;$j<$total_qualifiers;$j++){

                    if(isset($row['Header']['Address']['Contacts']['ContactTypeCode'])){
                        $field_value = $row['Header']['Address']['Contacts']['ContactTypeCode'];
                        if($field_value!=''){
                            $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();
                            $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Address']['Contacts'],'ContactTypeCode');
                            $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                            $ct_qualifiers['Header']['Address'][$j]['Contacts']['ContactTypeCode'] = $returndata['ct_key'];
                        }
                    }

                    $total_qualifiers_child = @$ct_qualifiers['Header']['Address'][$j]['Contacts']['ContactTypeCode'] ? $ct_qualifiers['Header']['Address'][$j]['Contacts']['ContactTypeCode'] : 0;

                    if(isset($row['Header']['Address']['Contacts']['ContactName'])){
                        $field_value = $shipping_address_name;
                        if($field_value!=''){
                            $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'ContactName');
                            $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                        }
                    }

                    if(isset($row['Header']['Address']['Contacts']['PrimaryPhone'])){
                        $field_value = $shipping_phone_number;
                        if($field_value!=''){
                            $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'PrimaryPhone');
                            $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                        }
                    }

                    if(isset($row['Header']['Address']['Contacts']['PrimaryFax'])){
                        $field_value = '';
                        if($field_value!=''){
                            $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();
                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'PrimaryFax');
                            $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                        }
                    }

                    if(isset($row['Header']['Address']['Contacts']['PrimaryEmail'])){
                        $field_value = $shipping_email;
                        if($field_value!=''){
                            $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'PrimaryEmail');
                            $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                        }
                    }


                }
            }



            /*********** End Address Fields************/


            /***********CarrierInformation fields************/
            if(isset($row['Header']['CarrierInformation']['StatusCode'])){
                $field_value = $row['Header']['CarrierInformation']['StatusCode'];
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['CarrierInformation'],'StatusCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['CarrierInformation']['StatusCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['CarrierInformation']['StatusCode'] ? $ct_qualifiers['Header']['CarrierInformation']['StatusCode'] : 0;

            if(isset($row['Header']['CarrierInformation']['CarrierRouting'])){
                $field_value = $ShippingMethodName;
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'CarrierRouting');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['CarrierInformation']['ServiceLevelCode'])){
                $field_value = $ShippingMethod;
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'ServiceLevelCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }


            /***********End CarrierInformation fields************/

            /***********References fields************/

            if(isset($row['Header']['References']['ReferenceQual'])){
                $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();
                $field_value = $row['Header']['References']['ReferenceQual'];
                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['References'],'ReferenceQual');
                $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                $ct_qualifiers['Header']['References']['ReferenceQual'] = $returndata['ct_key'];
            }

            $total_qualifiers = @$ct_qualifiers['Header']['References']['ReferenceQual'] ? $ct_qualifiers['Header']['References']['ReferenceQual'] : 0;

            if(isset($row['Header']['References']['ReferenceID'])){
                $custom_field_name = "";
                if(isset($mappings_custom_fields['Header']['References']['ReferenceID'])){
                    $custom_field_name = $mappings_custom_fields['Header']['References']['ReferenceID'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }

                $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                if($field_value!=''){
                    $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();

                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['References'],'ReferenceID');
                    $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                }
            }


            /***********End References fields************/

            /***********Notes fields************/

            if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){
                // when custom field is having value
                $custom_field_name = "";
                if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){

                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['NoteCode'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }
                $field_value = @$custom_field_value;
                if($field_value!=''){

                    $data['Header']['Notes'][0]['NoteCode'] = $field_value;
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = 1;
                }

            }else if(isset($row['Header']['Notes']['NoteCode'])){
                $field_value = $row['Header']['Notes']['NoteCode'];
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Notes'],'NoteCode');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Notes']['NoteCode'] ? $ct_qualifiers['Header']['Notes']['NoteCode'] : 0;

            if(isset($row['Header']['Notes']['Note'])){
                $custom_field_value = "";
                if(isset($mappings_custom_fields['Header']['Notes']['Note'])){
                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['Note'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }
                $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Notes'],'Note');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                }
            }

            /***********End Notes fields************/



            if(isset($row['LineItem'])){

                $line_sequence = 1;

                foreach($order_line_detail as $rowline){


                    $product_name = $rowline->product_name;
                    $ean = $rowline->ean;
                    $sku = $rowline->sku;
                    $gtin = $rowline->gtin;
                    $mpn = $rowline->mpn;
                    $upc = $rowline->upc;
                    $qty = $rowline->qty;
                    $unit_price = $rowline->unit_price;
                    $total = $rowline->total;
                    $description = $rowline->description;
                    $k = $line_sequence - 1;




                    $total_qualifiers = 0;

                    if(isset($row['LineItem']['OrderLine']['LineSequenceNumber'])){
                        $field_value = $line_sequence;
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['LineSequenceNumber'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['BuyerPartNumber'])){
                        $field_value = @${$mappings_fields['LineItem']['OrderLine']['BuyerPartNumber']};
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                           $data['LineItem'][$k]['OrderLine']['BuyerPartNumber'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['VendorPartNumber'])){
                        $field_value = @${$mappings_fields['LineItem']['OrderLine']['VendorPartNumber']};
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['VendorPartNumber'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['ConsumerPackageCode'])){
                        $field_value = @${$mappings_fields['LineItem']['OrderLine']['ConsumerPackageCode']};
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['ConsumerPackageCode'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['EAN'])){
                        $field_value = @${$mappings_fields['LineItem']['OrderLine']['EAN']};
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['EAN'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['GTIN'])){
                        $field_value = @${$mappings_fields['LineItem']['OrderLine']['GTIN']};
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['GTIN'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['UPCCaseCode'])){
                        $field_value = @${$mappings_fields['LineItem']['OrderLine']['UPCCaseCode']};
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['UPCCaseCode'] = $field_value;
                        }
                    }


                    if(isset($row['LineItem']['OrderLine']['LineChangeCode']) && $linked_id!=''){
                        $field_value = $row['LineItem']['OrderLine']['LineChangeCode'];
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['LineChangeCode'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['OrderQty'])){
                        $field_value = $qty;
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['OrderQty'] = $field_value;
                        }
                    }

                    if(isset($row['LineItem']['OrderLine']['OrderQtyUOM'])){
                        $field_value = $row['LineItem']['OrderLine']['OrderQtyUOM'];
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['OrderQtyUOM'] = $field_value;
                        }
                    }



                    if(isset($row['LineItem']['OrderLine']['PurchasePrice'])){
                        $field_value = $unit_price;//confirmed value
                        if($field_value!=''){
                            $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                            $data['LineItem'][$k]['OrderLine']['PurchasePrice'] = $field_value;
                        }
                    }



                    /*
                    if(isset($row['LineItem']['OrderLine']['LineSequenceNumber'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = $line_sequence;
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'LineSequenceNumber');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['OrderLine']['BuyerPartNumber'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = ${$mappings_fields['LineItem']['OrderLine']['BuyerPartNumber']};
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'BuyerPartNumber');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['OrderLine']['VendorPartNumber'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = ${$mappings_fields['LineItem']['OrderLine']['VendorPartNumber']};
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'VendorPartNumber');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['OrderLine']['ConsumerPackageCode'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = ${$mappings_fields['LineItem']['OrderLine']['ConsumerPackageCode']};
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'ConsumerPackageCode');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['OrderLine']['OrderQty'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = $qty;
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'OrderQty');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['OrderLine']['OrderQtyUOM'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = $row['LineItem']['OrderLine']['OrderQtyUOM'];
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'OrderQtyUOM');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['OrderLine']['PurchasePrice'])){
                        $data['LineItem'][$k]['OrderLine'] = @$data['LineItem'][$k]['OrderLine'] ? $data['LineItem'][$k]['OrderLine'] : array();
                        $field_value = $total;
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['LineItem']['OrderLine'],'PurchasePrice');
                        $data['LineItem'][$k]['OrderLine'] = array_replace_recursive(@$data['LineItem'][$k]['OrderLine'],$returndata['fielddata']);
                    }

                    */


                    if($is_order_acknowledge==1){


                        if(isset($row['LineItem']['LineItemAcknowledgement']['ItemStatusCode'])){
                            $data['LineItem'][$k]['LineItemAcknowledgement'] = @$data['LineItem'][$k]['LineItemAcknowledgement'] ? $data['LineItem'][$k]['LineItemAcknowledgement'] : array();
                            $field_value = $row['LineItem']['LineItemAcknowledgement']['ItemStatusCode'];
                            $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['LineItem']['LineItemAcknowledgement'],'ItemStatusCode');
                            $data['LineItem'][$k]['LineItemAcknowledgement'] = array_replace_recursive(@$data['LineItem'][$k]['LineItemAcknowledgement'],$returndata['fielddata']);
                            $ct_qualifiers['LineItem'][$k]['LineItemAcknowledgement']['ItemStatusCode'] = $returndata['ct_key'];
                        }

                        $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['LineItemAcknowledgement']['ItemStatusCode'] ? $ct_qualifiers['LineItem'][$k]['LineItemAcknowledgement']['ItemStatusCode'] : 0;

                        if(isset($row['LineItem']['LineItemAcknowledgement']['ItemScheduleQty'])){

                            $field_value = $qty;

                            if($field_value!=''){
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = @$data['LineItem'][$k]['LineItemAcknowledgement'] ? $data['LineItem'][$k]['LineItemAcknowledgement'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['LineItemAcknowledgement'],'ItemScheduleQty');
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = array_replace_recursive(@$data['LineItem'][$k]['LineItemAcknowledgement'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['LineItem']['LineItemAcknowledgement']['ItemScheduleUOM'])){

                            $field_value = $row['LineItem']['LineItemAcknowledgement']['ItemScheduleUOM'];

                            if($field_value!=''){
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = @$data['LineItem'][$k]['LineItemAcknowledgement'] ? $data['LineItem'][$k]['LineItemAcknowledgement'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['LineItemAcknowledgement'],'ItemScheduleUOM');
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = array_replace_recursive(@$data['LineItem'][$k]['LineItemAcknowledgement'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['LineItem']['LineItemAcknowledgement']['ItemScheduleQualifier'])){

                            $field_value = $row['LineItem']['LineItemAcknowledgement']['ItemScheduleQualifier'];

                            if($field_value!=''){
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = @$data['LineItem'][$k]['LineItemAcknowledgement'] ? $data['LineItem'][$k]['LineItemAcknowledgement'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['LineItemAcknowledgement'],'ItemScheduleQualifier');
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = array_replace_recursive(@$data['LineItem'][$k]['LineItemAcknowledgement'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['LineItem']['LineItemAcknowledgement']['ItemScheduleDate'])){

                            $field_value = $PurchaseOrderDate;

                            if($field_value!=''){
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = @$data['LineItem'][$k]['LineItemAcknowledgement'] ? $data['LineItem'][$k]['LineItemAcknowledgement'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['LineItemAcknowledgement'],'ItemScheduleDate');
                                $data['LineItem'][$k]['LineItemAcknowledgement'] = array_replace_recursive(@$data['LineItem'][$k]['LineItemAcknowledgement'],$returndata['fielddata']);
                            }
                        }


                    }



                    if(isset($row['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'])){
                        $data['LineItem'][$k]['ProductOrItemDescription'] = @$data['LineItem'][$k]['ProductOrItemDescription'] ? $data['LineItem'][$k]['ProductOrItemDescription'] : array();
                        $field_value = $row['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'];
                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['LineItem']['ProductOrItemDescription'],'ProductCharacteristicCode');
                        $data['LineItem'][$k]['ProductOrItemDescription'] = array_replace_recursive(@$data['LineItem'][$k]['ProductOrItemDescription'],$returndata['fielddata']);
                        $ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] = $returndata['ct_key'];
                    }

                    $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] ? $ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] : 0;

                    if(isset($row['LineItem']['ProductOrItemDescription']['ProductDescription'])){

                        $field_value = @$product_name ? @$product_name : (@$description ? @$description : $default_info);

                        if($field_value!=''){
                            $data['LineItem'][$k]['ProductOrItemDescription'] = @$data['LineItem'][$k]['ProductOrItemDescription'] ? $data['LineItem'][$k]['ProductOrItemDescription'] : array();

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['ProductOrItemDescription'],'ProductDescription');
                            $data['LineItem'][$k]['ProductOrItemDescription'] = array_replace_recursive(@$data['LineItem'][$k]['ProductOrItemDescription'],$returndata['fielddata']);
                        }
                    }


                    /*
                    // Using When Discount Comes Up with allowance 'A'
                    if(isset($row['LineItem']['ChargesAllowances']['AllowChrgIndicator'])){
                        $data['LineItem'][$k]['ChargesAllowances'] = @$data['LineItem'][$k]['ChargesAllowances'] ? $data['LineItem'][$k]['ChargesAllowances'] : array();

                        $field_value = $row['LineItem']['ChargesAllowances']['AllowChrgIndicator'];

                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['LineItem']['ChargesAllowances'],'AllowChrgIndicator');
                        $data['LineItem'][$k]['ChargesAllowances'] = array_replace_recursive(@$data['LineItem'][$k]['ChargesAllowances'],$returndata['fielddata']);
                        $ct_qualifiers['LineItem'][$k]['ChargesAllowances']['AllowChrgIndicator'] = $returndata['ct_key'];
                    }

                    $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['ChargesAllowances']['AllowChrgIndicator'] ? $ct_qualifiers['LineItem'][$k]['ChargesAllowances']['AllowChrgIndicator'] : 0;

                    if(isset($row['LineItem']['ChargesAllowances']['AllowChrgCode'])){
                        $data['LineItem'][$k]['ChargesAllowances'] = @$data['LineItem'][$k]['ChargesAllowances'] ? $data['LineItem'][$k]['ChargesAllowances'] : array();
                        $field_value = $row['LineItem']['ChargesAllowances']['AllowChrgCode'];
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['ChargesAllowances'],'AllowChrgCode');
                        $data['LineItem'][$k]['ChargesAllowances'] = array_replace_recursive(@$data['LineItem'][$k]['ChargesAllowances'],$returndata['fielddata']);
                    }

                    if(isset($row['LineItem']['ChargesAllowances']['AllowChrgAmt'])){
                        $data['LineItem'][$k]['ChargesAllowances'] = @$data['LineItem'][$k]['ChargesAllowances'] ? $data['LineItem'][$k]['ChargesAllowances'] : array();
                        $field_value = "";
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['ChargesAllowances'],'AllowChrgAmt');
                        $data['LineItem'][$k]['ChargesAllowances'] = array_replace_recursive(@$data['LineItem'][$k]['ChargesAllowances'],$returndata['fielddata']);
                    }
                    */

                    $line_sequence++;

                }




            }

            if(isset($row['Summary']['TotalLineItemNumber'])){
                $data['Summary']['TotalLineItemNumber'] = $line_sequence - 1;

            }

            if(isset($row['Summary']['TotalAmount'])){
                $data['Summary']['TotalAmount'] = $TotalAmount;
            }


        }

        $postdata = json_encode($data,true);

        return $postdata;


    }




    public function GetStructuredInvoicePostDataNew($user_id,$user_integration_id,$platform_workflow_rule_id,$source_platform_id,$trading_partner_id='',$sync_object_id,$mapped_file,$customer_detail,$order_detail,$order_line_detail,$order_address_detail,$order_additional_detail,$invoice_detail)
    {


    if (str_contains($mapped_file, ".s3.")) {
        //From S3 File
        $csvFilePath = trim($mapped_file);
        $limit=500;
        $i = 0;
        $mappings = $mappings_fields = $mappings_custom_fields = $data =  $ct_qualifiers = [];
        
        LazyCollection::make(function () use($csvFilePath) {
            // $csvFilePath = storage_path($csvFilePath); // use if file stored in local server
            $handle = fopen($csvFilePath, 'r');
            while ($line = fgetcsv($handle)) {
                yield $line;
            }
        })
        ->chunk($limit) //split in chunk to reduce the number of queries
        ->each(function ($lines) use($i, &$mappings_custom_fields, &$mappings, &$mappings_fields) {
            // $list = []; // temp, only to check chunk processing
            foreach ($lines as $key => $arrrow) {
            if($i!=0){

                $fields = explode('/',$arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[1] having default value & $arrrow[2]  having custom field

                if(count($fields)==2){
                    $mappings[][$fields[1]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]] = $arrrow[2];
                }else if(count($fields)==3){
                    $mappings[][$fields[1]][$fields[2]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]] = $arrrow[2];
                }else if(count($fields)==4){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                }else if(count($fields)==5){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                }else if(count($fields)==6){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                }else if(count($fields)==7){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                }else if(count($fields)==8){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                }

            }
            $i++;
        }
    });

        } else {
    // From Local file 
    $csvFile = file(base_path().'/'.$mapped_file);

        $i = 0;
        $mappings = $mappings_fields = $mappings_custom_fields = $data =  $ct_qualifiers = [];
        foreach ($csvFile as $line) {
            if($i!=0){
                $arrrow = str_getcsv($line);

                $fields = explode('/',$arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[1] having default value & $arrrow[2]  having custom field


                if(count($fields)==2){
                    $mappings[][$fields[1]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]] = $arrrow[2];
                }else if(count($fields)==3){
                    $mappings[][$fields[1]][$fields[2]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]] = $arrrow[2];
                }else if(count($fields)==4){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                }else if(count($fields)==5){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                }else if(count($fields)==6){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                }else if(count($fields)==7){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                }else if(count($fields)==8){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                }

            }
            $i++;
        }
    }
 
        $allow_discount_fields = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->AllowDiscountFields(['user_integration_id'=>$user_integration_id]);

        //$total_line_items = count($order_line_detail);
        $chargesandallowanceitemdetail = $terms_info = [];
        $total = $discount_amount = $discount_percentage = 0;
        $total_line_items = $line_sequence = 1;
        $default_info = "No Information";
        $field_value = $OrderStatusID = $Vendor = $product_name = $ean = $sku = $mpn = $gtin = $upc = $qty = $unit_price = $shipping_address_name = $shipping_address_location = $shipping_company = $shipping_address1 = $shipping_address2 = $shipping_address3 = $shipping_address4 = $shipping_city = $shipping_state = $shipping_postal_code = $shipping_country = $shipping_email = $shipping_phone_number = $billing_address_name = $billing_address_location = $billing_company = $billing_address1 = $billing_address2 = $billing_address3 = $billing_address4 = $billing_city = $billing_state = $billing_postal_code = $billing_country = $billing_email = $billing_phone_number = $vendor_address_name = $vendor_address_location = $vendor_company = $vendor_address1 = $vendor_address2 = $vendor_address3 = $vendor_address4 = $vendor_city = $vendor_state = $vendor_postal_code = $vendor_country = $vendor_email = $vendor_phone_number = $chargesandallowanceitem = $customer_code = "";


        $invoice_code = $invoice_date = $tracking_number = $ship_date = $ship_by_date = $ShippingMethodName = "";

        //invoice details
        $invoice_code = @$invoice_detail->invoice_code;
        $invoice_date = @$invoice_detail->invoice_date ? date('Y-m-d',strtotime($invoice_detail->invoice_date)) : '';
        $tracking_number = @$invoice_detail->tracking_number;
        $ship_date = @$invoice_detail->ship_date ? date('Y-m-d',strtotime($invoice_detail->ship_date)) : '';
        $ship_by_date = @$invoice_detail->ship_by_date ? date('Y-m-d',strtotime($invoice_detail->ship_by_date)) : '';
        $ship_via = @$invoice_detail->ship_via;
        $TotalAmount = @$invoice_detail->total_amt;
        $total_qty = @$invoice_detail->total_qty;
        $due_days = @$invoice_detail->due_days;

        if($invoice_detail->payment_terms!=''){

            $object_id = $this->helper->getObjectId('terms');
            $terms_info_data = DB::table('platform_object_data_additional_information as podai')
                    ->join("platform_object_data as pod",function($join){
                        $join->on("podai.platform_object_data_id","=","pod.id");
                    })->where(['pod.user_id' => $user_id,'pod.user_integration_id' => $user_integration_id,'pod.platform_id' => $source_platform_id,'pod.platform_object_id' => $object_id,'pod.name' => $invoice_detail->payment_terms])->select(['podai.terms_info','pod.description'])->first();
            $terms_info = @$terms_info_data->terms_info ? json_decode($terms_info_data->terms_info,true) : [];
            $terms_info['description'] = @$terms_info_data->description;

            if(count($terms_info) > 0 && isset($terms_info['discount_type'])){
                $discount_percentage = ($terms_info['discount_type']=='%') ? @$terms_info['discount_amount'] : 0;
                $discount_amount = ($terms_info['discount_type']!='%') ? @$terms_info['discount_amount'] : 0;
            }

        }


        // customer details
        $customer_id = @$customer_detail->api_customer_id;
        $customer_name = @$customer_detail->customer_name;
        $customer_phone = @$customer_detail->phone;
        $customer_email = @$customer_detail->email;
        $customer_fax = @$customer_detail->fax;
        $location_id = @$customer_detail->location_id;



        $id = $order_detail->id;
        $PurchaseOrderNumber = @$order_detail->api_order_id;
        $DeliveryDate = @$order_detail->delivery_date ? date('Y-m-d',strtotime($order_detail->delivery_date)) : '';
        $PurchaseOrderDate = @$order_detail->order_date ? date('Y-m-d',strtotime($order_detail->order_date)) : '';
        $OrderRef = @$order_detail->api_order_reference ? $order_detail->api_order_reference : '';
        $linked_id = @$order_detail->linked_id ? $order_detail->linked_id : '';
        $vendor = @$order_detail->vendor ? $order_detail->vendor : '';
        $total_weight = @$order_detail->total_weight ? round($order_detail->total_weight,2) : 0;
        $order_number = @$order_detail->order_number ? $order_detail->order_number : '';



        $CustomDataVendor = $this->map->getMappedDataByName($user_integration_id,null,"default_vendor", ['custom_data'], "default");
        $custom_vendor = @$CustomDataVendor->custom_data;
        if($custom_vendor!=''){
            $vendor = $custom_vendor;
        }

        //$TotalAmount = @$order_detail->total_amount ? @$order_detail->total_amount : 0;


        $spm = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "porder_shipping_method", ['api_id','name'], 'regular', $order_detail->shipping_method);
        $ShippingMethod = isset($spm->api_id) ? $spm->api_id : '';
        $ShippingMethodName = isset($spm->name) ? $spm->name : '';

        if($ship_via!=''){
            $ShippingMethodName = $ship_via;
        }


        if($order_detail->order_status!=''){
            $OrderStatus = $this->map->getMappedDataByName($user_integration_id, null, "get_order_status", ['name'], "regular", $order_detail->order_status, "single", "destination", ['api_id', 'name']);
            $OrderStatusID = @$OrderStatus->api_id ? $OrderStatus->api_id : '';
        }


        $IsDropShip = @$order_additional_detail->is_drop_ship ? $order_additional_detail->is_drop_ship : 0;
        $CustomerOrderNumber = @$order_additional_detail->parent_order_id ? $order_additional_detail->parent_order_id : '';


        // RI Address -> we are using File mapping for entry default values so need to maintain it through file
        // For some custom address & customer using we need to set this below object
        $CustomerPreferenceMap = $this->map->getMappedDataByName($user_integration_id, null, "customer_preference", ['name']);
        $customer_preference = @$CustomerPreferenceMap->name ? $CustomerPreferenceMap->name : 'Default';

        if($customer_preference=='Default'){


            //For address
            foreach($order_address_detail as $rowaddress){
                if($rowaddress->address_type=='shipping'){
                    $shipping_address_name = $rowaddress->address_name;
                    $shipping_company = $rowaddress->company;
                    $shipping_address1 = $rowaddress->address1;
                    $shipping_address2 = $rowaddress->address2;
                    $shipping_address3 = $rowaddress->address3;
                    $shipping_address4 = $rowaddress->address4;
                    $shipping_city = $rowaddress->city;
                    $shipping_state = $rowaddress->state;
                    $shipping_postal_code = $rowaddress->postal_code;
                    $shipping_country = $rowaddress->country;
                    $shipping_email = $rowaddress->email;
                    $shipping_phone_number = $rowaddress->phone_number;
                }else if($rowaddress->address_type=='billing'){
                    $billing_address_name = $rowaddress->address_name;
                    $billing_company = $rowaddress->company;
                    $billing_address1 = $rowaddress->address1;
                    $billing_address2 = $rowaddress->address2;
                    $billing_address3 = $rowaddress->address3;
                    $billing_address4 = $rowaddress->address4;
                    $billing_city = $rowaddress->city;
                    $billing_state = $rowaddress->state;
                    $billing_postal_code = $rowaddress->postal_code;
                    $billing_country = $rowaddress->country;
                    $billing_email = $rowaddress->email;
                    $billing_phone_number = $rowaddress->phone_number;
                }
            }


        }else if($customer_preference=='Custom'){


            // custom address using for intacct -sps integration client wants maintain their custom address

            $result_linked_order_address = $this->mobj->getResultByConditions('platform_order_address',['platform_order_id'=>$linked_id],['address_type','address_id']);
            foreach($result_linked_order_address as $rowaddress){
                if($rowaddress->address_type=='shipping'){
                    $shipping_address_location = $rowaddress->address_id;
                }else if($rowaddress->address_type=='billing'){
                    $billing_address_location = $rowaddress->address_id;
                }
            }


            $AddressPriority = $this->map->getMappedDataByName($user_integration_id, null, "address_priority", ['api_code']);
            $address_priority = @$AddressPriority->api_code ? $AddressPriority->api_code : 'bill_to_customer';


            $CustomDataBillTo = $this->map->getMappedDataByName($user_integration_id,null,"bill_to_customer", ['custom_data'], "default");
            $bill_to_customer_code = @$CustomDataBillTo->custom_data;


            $CustomDataShipTo = $this->map->getMappedDataByName($user_integration_id,null,"ship_to_customer", ['custom_data'], "default");
            $ship_to_customer_code = @$CustomDataShipTo->custom_data;


            if($bill_to_customer_code!='' || $billing_address_location!=''){

                $bill_to_object_id = $this->helper->getObjectId('bill_to_customer');
                if($bill_to_customer_code!=''){
                    $bill_address = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $bill_to_object_id, 'api_id', $bill_to_customer_code,["name","api_code","description","api_id"]);
                }else if($billing_address_location!=''){
                    $bill_address = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $bill_to_object_id, 'api_code', $billing_address_location,["name","api_code","description","api_id"]);
                }


                if($bill_address){
                    $billing_address_location =  $bill_address->api_code;
                    $billing_address_name = $bill_address->name;
                    $billing_address_name = $bill_address->name;
                    $customer_code = $bill_address->api_id;
                    if($customer_name==''){
                        $customer_name = $billing_address_name;
                    }

                    if($bill_address->description!=''){
                        $address = json_decode($bill_address->description,true);
                        $billing_address1 = $address['address1'];
                        $billing_address2 = $address['address2'];
                        $billing_city = $address['city'];
                        $billing_state = $address['state'];
                        $billing_postal_code = $address['postal_code'];
                        $billing_country = $address['country'];
                    }
                }



                if($ship_to_customer_code!='' && $address_priority=='bill_to_customer'){

                    $ship_to_object_id = $this->helper->getObjectId('ship_to_customer');
                    $ship_address = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $ship_to_object_id, 'api_id', $ship_to_customer_code,["name","api_code","description","api_id"]);

                }else if($billing_address_location!='' && $address_priority=='bill_to_customer'){

                    $ship_to_object_id = $this->helper->getObjectId('ship_to_customer');
                    $ship_address = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $ship_to_object_id, 'api_code', $billing_address_location,["name","api_code","description","api_id"]);

                }else if($shipping_address_location!='' && $address_priority=='ship_to_customer'){

                    $ship_to_object_id = $this->helper->getObjectId('ship_to_customer');
                    $ship_address = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $ship_to_object_id, 'api_code', $shipping_address_location,["name","api_code","description","api_id"]);

                }

                if($ship_address){
                    $shipping_address_location =  $ship_address->api_code;
                    $shipping_address_name = $ship_address->name;

                    if($customer_name==''){
                        $customer_name = $shipping_address_name;
                    }

                    if($customer_code==''){
                        $customer_code = $ship_address->api_id;
                    }



                    if($ship_address->description!=''){
                        $address = json_decode($ship_address->description,true);
                        $shipping_address1 = $address['address1'];
                        $shipping_address2 = $address['address2'];
                        $shipping_city = $address['city'];
                        $shipping_state = $address['state'];
                        $shipping_postal_code = $address['postal_code'];
                        $shipping_country = $address['country'];
                    }
                }

                $vendor_address_code = 'VN';
                $vendor_object_id = $this->helper->getObjectId('vendor_address');
                $vendor_address = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $vendor_object_id, 'api_id', $vendor_address_code,["name","api_code","description"]);

                if($vendor_address){
                    $vendor_address_location =  $vendor_address->api_code;
                    $vendor_address_name = $vendor_address->name;

                    if($vendor_address->description!=''){
                        $address = json_decode($vendor_address->description,true);
                        $vendor_address1 = $address['address1'];
                        $vendor_address2 = $address['address2'];
                        $vendor_city = $address['city'];
                        $vendor_state = $address['state'];
                        $vendor_postal_code = $address['postal_code'];
                        $vendor_country = $address['country'];
                    }
                }
            }

        }



        // For charges & allowance
        if(count($order_line_detail) > 0){
            $CustomDataChargeAllowItem = $this->map->getMappedDataByName($user_integration_id,$platform_workflow_rule_id,"charges_allowances_item", ['custom_data'], "default");
            $chargesandallowanceitem = @$CustomDataChargeAllowItem->custom_data;
            foreach($order_line_detail as $inld){
                if($chargesandallowanceitem == $inld->api_code){
                    $chargesandallowanceitemdetail['unit_price'] = abs($inld->unit_price);
                    $chargesandallowanceitemdetail['uom'] = $inld->uom;
                    $chargesandallowanceitemdetail['qty'] = $inld->qty;
                    $chargesandallowanceitemdetail['product_name'] = $inld->product_name;
                }
            }
        }


        $ct_row = 0;
        foreach($mappings as $row){

            if(isset($row['Header']['InvoiceHeader']['TradingPartnerId']) && $trading_partner_id!=''){
                $data['Header']['InvoiceHeader']['TradingPartnerId'] = $trading_partner_id;
            }

            if(isset($row['Header']['InvoiceHeader']['PurchaseOrderNumber'])){
                if($linked_id!=''){
                    $data['Header']['InvoiceHeader']['PurchaseOrderNumber'] = $OrderRef;
                }else{
                    $data['Header']['InvoiceHeader']['PurchaseOrderNumber'] = $PurchaseOrderNumber;
                }
            }


            if(isset($row['Header']['InvoiceHeader']['TsetPurposeCode'])){
                $data['Header']['InvoiceHeader']['TsetPurposeCode'] = @$OrderStatusID ? $OrderStatusID : $row['Header']['InvoiceHeader']['TsetPurposeCode'];
            }

            if(isset($row['Header']['InvoiceHeader']['PrimaryPOTypeCode'])){
                $PrimaryPOTypeCode = $row['Header']['InvoiceHeader']['PrimaryPOTypeCode'];
                if($IsDropShip==1){
                    $PrimaryPOTypeCode = 'DS';
                }else{
                    $PrimaryPOTypeCode = 'SA';
                }
                $data['Header']['InvoiceHeader']['PrimaryPOTypeCode'] = $PrimaryPOTypeCode;
            }

            if(isset($row['Header']['InvoiceHeader']['PurchaseOrderDate'])){
                $data['Header']['InvoiceHeader']['PurchaseOrderDate'] = $PurchaseOrderDate;
            }

            if(isset($row['Header']['InvoiceHeader']['Vendor'])){
                $vendor = @$vendor ? $vendor : $customer_id;
                if($vendor!=''){
                    $data['Header']['InvoiceHeader']['Vendor'] = $vendor;
                }
            }


            if(isset($row['Header']['InvoiceHeader']['CustomerOrderNumber'])){
                if($IsDropShip==1){
                    $data['Header']['InvoiceHeader']['CustomerOrderNumber'] = $CustomerOrderNumber;
                }
            }

            if(isset($row['Header']['InvoiceHeader']['InvoiceNumber'])){
                $data['Header']['InvoiceHeader']['InvoiceNumber'] = $invoice_code;
            }

            if(isset($row['Header']['InvoiceHeader']['InvoiceDate'])){
                if($invoice_date!=''){
                    $data['Header']['InvoiceHeader']['InvoiceDate'] = $invoice_date;
                }
            }

            if(isset($row['Header']['InvoiceHeader']['InvoiceTypeCode'])){
                $data['Header']['InvoiceHeader']['InvoiceTypeCode'] = $row['Header']['InvoiceHeader']['InvoiceTypeCode'];
            }

            if(isset($row['Header']['InvoiceHeader']['CarrierProNumber']) && $tracking_number!=''){
                $data['Header']['InvoiceHeader']['CarrierProNumber'] = $tracking_number;
            }

            if(isset($row['Header']['InvoiceHeader']['ShipDate'])){
                if($invoice_date!=''){
                    $data['Header']['InvoiceHeader']['ShipDate'] = $invoice_date;//$ship_date;
                }
            }

            if(isset($row['Header']['InvoiceHeader']['ShipDeliveryDate'])){
                if($ship_by_date!=''){
                    $data['Header']['InvoiceHeader']['ShipDeliveryDate'] = $ship_by_date;
                }
            }

            //$res['Header']['InvoiceHeader']['ExchangeRate'] = '';
            //$res['Header']['InvoiceHeader']['InternalOrderNumber'] = ''; //doubt
            //$res['Header']['InvoiceHeader']['JobNumber'] = '';
            //$res['Header']['InvoiceHeader']['CustomerAccountNumber'] = '';
            if(isset($row['Header']['InvoiceHeader']['BillOfLadingNumber'])){

                $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomBillOfLadingNumber(['user_integration_id'=>$user_integration_id,'custom_value'=>$order_number,'default_value'=>$invoice_code]);

                $data['Header']['InvoiceHeader']['BillOfLadingNumber'] = $custom_field_value;
            }



            /***********PaymentTerms fields************/

            if(isset($row['Header']['PaymentTerms']['TermsType'])){
                $field_value = $row['Header']['PaymentTerms']['TermsType'];
                if($field_value!=''){

                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();

                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['PaymentTerms'],'TermsType');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['PaymentTerms']['TermsType'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['PaymentTerms']['TermsType'] ? $ct_qualifiers['Header']['PaymentTerms']['TermsType'] : 0;

            if(isset($row['Header']['PaymentTerms']['TermsBasisDateCode'])){
                $field_value = $row['Header']['PaymentTerms']['TermsBasisDateCode']; //01 hardcode
                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsBasisDateCode');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }


            if(isset($row['Header']['PaymentTerms']['TermsDiscountDate']) && ($allow_discount_fields  || $discount_percentage > 0 || $discount_amount > 0)){

                $field_value = "";
                if(count($terms_info) > 0){
                    $field_value = date('Y-m-d',strtotime('+'.(@$terms_info['discount_days'] ? ($terms_info['discount_days'] - 1) : 0).' day', strtotime($invoice_date)));//$row['Header']['PaymentTerms']['TermsDiscountDate'];
                }

                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsDiscountDate');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['PaymentTerms']['TermsDiscountDueDays']) && ($allow_discount_fields  || $discount_percentage > 0 || $discount_amount > 0)){
                $field_value = "";
                if(count($terms_info) > 0){
                    //$field_value = @$terms_info['discount_days'] ? $terms_info['discount_days'] : $row['Header']['PaymentTerms']['TermsDiscountDueDays'];
                    $field_value = @$terms_info['due_days'] ? $terms_info['due_days'] : $row['Header']['PaymentTerms']['TermsDiscountDueDays'];
                }
                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsDiscountDueDays');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['PaymentTerms']['TermsDiscountPercentage']) && ($allow_discount_fields  || $discount_percentage > 0 || $discount_amount > 0)){

                $field_value = $discount_percentage;

                //if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsDiscountPercentage');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                //}
            }

            if(isset($row['Header']['PaymentTerms']['TermsDiscountAmount']) && ($allow_discount_fields  || $discount_percentage > 0 || $discount_amount > 0)){

                $field_value = $discount_amount;

                //if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsDiscountAmount');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                //}
            }



            if(isset($row['Header']['PaymentTerms']['TermsNetDueDate'])){
                $field_value = "";
                if(count($terms_info) > 0){
                    $field_value = date('Y-m-d',strtotime('+'.(@$terms_info['due_days'] ? ($terms_info['due_days'] - 1) : 0).' day', strtotime($invoice_date)));//$row['Header']['PaymentTerms']['TermsNetDueDate'];
                }
                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsNetDueDate');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }


            if(isset($row['Header']['PaymentTerms']['TermsNetDueDays'])){
                $field_value = "";
                if(count($terms_info) > 0){
                    $field_value = @$terms_info['due_days'] ? $terms_info['due_days'] : $row['Header']['PaymentTerms']['TermsNetDueDays'];
                }
                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsNetDueDays');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['PaymentTerms']['TermsDescription'])){
                $field_value = "";
                if(isset($mappings_custom_fields['Header']['PaymentTerms']['TermsDescription'])){
                    $custom_field_name = $mappings_custom_fields['Header']['PaymentTerms']['TermsDescription'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);

                    $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                }else{
                    $field_value = "";
                    if(count($terms_info) > 0){
                        $field_value = @$terms_info['description'] ? $terms_info['description'] : $row['Header']['PaymentTerms']['TermsDescription'];
                    }
                }


                if($field_value!=''){
                    $data['Header']['PaymentTerms'] = @$data['Header']['PaymentTerms'] ? $data['Header']['PaymentTerms'] : array();

                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['PaymentTerms'],'TermsDescription');
                    $data['Header']['PaymentTerms'] = array_replace_recursive(@$data['Header']['PaymentTerms'],$returndata['fielddata']);
                }
            }







            /***********End PaymentTerms fields************/


            /***********Dates fields************/
            if(isset($row['Header']['Dates']['DateTimeQualifier'])){
                $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                $field_value = $row['Header']['Dates']['DateTimeQualifier'];
                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Dates'],'DateTimeQualifier');
                $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] = $returndata['ct_key'];
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Dates']['DateTimeQualifier'] ? $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] : 0;

            if(isset($row['Header']['Dates']['Date'])){

                $field_value = $DeliveryDate;
                if($field_value!=''){
                    $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Dates'],'Date');
                    $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                }

            }

            /***********End Dates fields************/

            /***********Contacts fields************/
            if(isset($row['Header']['Contacts']['ContactTypeCode'])){

                $field_value = $row['Header']['Contacts']['ContactTypeCode'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Contacts'],'ContactTypeCode');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] = $returndata['ct_key'];
                }
            }



            $total_qualifiers = @$ct_qualifiers['Header']['Contacts']['ContactTypeCode'] ? $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] : 0;

            if(isset($row['Header']['Contacts']['ContactName'])){
                $field_value = @$row['Header']['Contacts']['ContactName'] ? $row['Header']['Contacts']['ContactName'] : $customer_name;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'ContactName');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryPhone'])){
                $field_value = @$row['Header']['Contacts']['PrimaryPhone'] ? $row['Header']['Contacts']['PrimaryPhone'] : $customer_phone;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryPhone');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryEmail'])){
                $field_value = @$row['Header']['Contacts']['PrimaryEmail'] ? $row['Header']['Contacts']['PrimaryEmail'] : $customer_email;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryEmail');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['Contacts']['PrimaryFax'])){
                $field_value = @$row['Header']['Contacts']['PrimaryFax'] ? $row['Header']['Contacts']['PrimaryFax'] : $customer_fax;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryFax');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }


            /***********End Contacts fields************/

            /***********Address Fields************/
            //foreach($addresses as $addr){

                if(isset($row['Header']['Address']['AddressTypeCode'])){
                    $field_value = $row['Header']['Address']['AddressTypeCode'];
                    if($field_value!=''){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Address'],'AddressTypeCode');
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                        $ct_qualifiers['Header']['Address']['AddressTypeCode'] = $returndata['ct_key'];
                    }

                }

                $total_qualifiers = @$ct_qualifiers['Header']['Address']['AddressTypeCode'] ? $ct_qualifiers['Header']['Address']['AddressTypeCode'] : 0;

                /*if(isset($row['Header']['Address']['LocationCodeQualifier'])){
                    $field_value = $row['Header']['Address']['LocationCodeQualifier'];
                    if($field_value!=''){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'LocationCodeQualifier');
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }*/
                if(isset($row['Header']['Address']['LocationCodeQualifier'])){
                    $field_value = $row['Header']['Address']['LocationCodeQualifier'];
                    if($field_value!=''){

                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();


                        if(strpos($row['Header']['Address']['LocationCodeQualifier'], ',') !== false){
                            $multi_values = explode(',',$row['Header']['Address']['LocationCodeQualifier']);

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'LocationCodeQualifier',$data,$multi_values);
                            $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);

                        }else{

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'LocationCodeQualifier');
                            $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);

                        }

                    }
                }

                if(isset($row['Header']['Address']['AddressLocationNumber'])){


                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address']) && $billing_address_location!='' && $shipping_address_location!=''){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address_location;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_address_location;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['AddressLocationNumber'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_address_location;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }else{
                        $field_value = @$location_id ? $location_id : $row['Header']['Address']['AddressLocationNumber'];
                    }


                    if(count($multi_values) > 0 || $field_value!=''){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressLocationNumber',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

               if(isset($row['Header']['Address']['AddressName'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address_name;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_address_name;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['AddressName'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_address_name;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressName',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }

                }

                //if(isset($row['Header']['Address']['AddressAlternateName'])){
                //    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                //    $field_value = "";
                //     $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressAlternateName');
                //    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                //}

                if(isset($row['Header']['Address']['Address1'])){
                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address1;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_address1;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['Address1'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_address1;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address1',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }


                if(isset($row['Header']['Address']['Address2'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address2;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_address2;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['Address2'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_address2;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address2',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['Address3'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address3;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_address3;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['Address3'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_address3;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address3',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['Address4'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address4;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_address4;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['Address4'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_address4;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address4',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['City'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_city;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_city;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['City'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_city;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                       $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'City',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['State'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_state;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_state;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['State'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_state;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'State',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['PostalCode'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_postal_code;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_postal_code;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['PostalCode'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_postal_code;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }
                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'PostalCode',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['Country'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_country;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[] = $billing_country;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[] = $row['Header']['Address']['Country'];
                            }else if($rowadd['AddressTypeCode']=='VN'){
                                $multi_values[] = $vendor_country;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }
                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Country',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }


                if($IsDropShip==1){

                    // some special case here for more childs
                    for($j=0;$j<$total_qualifiers;$j++){

                        if(isset($row['Header']['Address']['Contacts']['ContactTypeCode'])){
                            $field_value = $row['Header']['Address']['Contacts']['ContactTypeCode'];
                            if($field_value!=''){
                                $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();
                                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Address']['Contacts'],'ContactTypeCode');
                                $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                                $ct_qualifiers['Header']['Address'][$j]['Contacts']['ContactTypeCode'] = $returndata['ct_key'];
                            }
                        }

                        $total_qualifiers_child = @$ct_qualifiers['Header']['Address'][$j]['Contacts']['ContactTypeCode'] ? $ct_qualifiers['Header']['Address'][$j]['Contacts']['ContactTypeCode'] : 0;

                        if(isset($row['Header']['Address']['Contacts']['ContactName'])){
                            $field_value = $shipping_address_name;
                            if($field_value!=''){
                                $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'ContactName');
                                $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['Header']['Address']['Contacts']['PrimaryPhone'])){
                            $field_value = $shipping_phone_number;
                            if($field_value!=''){
                                $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'PrimaryPhone');
                                $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['Header']['Address']['Contacts']['PrimaryFax'])){
                            $field_value = '';
                            if($field_value!=''){
                                $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();
                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'PrimaryFax');
                                $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['Header']['Address']['Contacts']['PrimaryEmail'])){
                            $field_value = $shipping_email;
                            if($field_value!=''){
                                $data['Header']['Address'][$j]['Contacts'] = @$data['Header']['Address'][$j]['Contacts'] ? $data['Header']['Address'][$j]['Contacts'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Header']['Address']['Contacts'],'PrimaryEmail');
                                $data['Header']['Address'][$j]['Contacts'] = array_replace_recursive(@$data['Header']['Address'][$j]['Contacts'],$returndata['fielddata']);
                            }
                        }


                    }
                }

            //}



            /*********** End Address Fields************/


            /***********CarrierInformation fields************/
            if(isset($row['Header']['CarrierInformation']['StatusCode'])){
                $field_value = $row['Header']['CarrierInformation']['StatusCode'];
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['CarrierInformation'],'StatusCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['CarrierInformation']['StatusCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['CarrierInformation']['StatusCode'] ? $ct_qualifiers['Header']['CarrierInformation']['StatusCode'] : 0;

            if(isset($row['Header']['CarrierInformation']['CarrierTransMethodCode'])){
                $field_value = $row['Header']['CarrierInformation']['CarrierTransMethodCode'];
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'CarrierTransMethodCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }



            if(isset($row['Header']['CarrierInformation']['CarrierRouting'])){
                $field_value = $ShippingMethodName ? $ShippingMethodName : $default_info;
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'CarrierRouting');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['CarrierInformation']['ServiceLevelCode'])){
                $field_value = @$ShippingMethod ? $ShippingMethod : $default_info;
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'ServiceLevelCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }



            /***********End CarrierInformation fields************/

            /***********References fields************/

            if(isset($row['Header']['References']['ReferenceQual'])){
                $field_value = $row['Header']['References']['ReferenceQual'];
                if($field_value!=''){
                    $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();

                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['References'],'ReferenceQual');
                    $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['References']['ReferenceQual'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['References']['ReferenceQual'] ? $ct_qualifiers['Header']['References']['ReferenceQual'] : 0;

            if(isset($row['Header']['References']['ReferenceID'])){
                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['References']['ReferenceID'])){
                    $custom_field_name = $mappings_custom_fields['Header']['References']['ReferenceID'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }



                $field_value = @$custom_field_value ? @$custom_field_value : (($linked_id!='') ? $OrderRef : (@$PurchaseOrderNumber ? @$PurchaseOrderNumber : $default_info));
                if($field_value!=''){
                    $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['References'],'ReferenceID');
                    $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                }
            }



            /***********End References fields************/

            /***********Notes fields************/

            if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){
                // when custom field is having value
                $custom_field_name = "";
                if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){

                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['NoteCode'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }
                $field_value = @$custom_field_value;
                if($field_value!=''){

                    $data['Header']['Notes'][0]['NoteCode'] = $field_value;
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = 1;
                }

            }else if(isset($row['Header']['Notes']['NoteCode'])){
                $field_value = $row['Header']['Notes']['NoteCode'];
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Notes'],'NoteCode');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Notes']['NoteCode'] ? $ct_qualifiers['Header']['Notes']['NoteCode'] : 0;

            if(isset($row['Header']['Notes']['Note'])){
                $custom_field_value = "";
                if(isset($mappings_custom_fields['Header']['Notes']['Note'])){
                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['Note'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }
                $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Notes'],'Note');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                }
            }


            /***********End Notes fields************/



            /***********Charges & allowance fields************/
            if(isset($row['Header']['ChargesAllowances']['AllowChrgIndicator'])){
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = 'A';
                }
                //$field_value = $row['Header']['ChargesAllowances']['AllowChrgIndicator'];
                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgIndicator');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['ChargesAllowances']['AllowChrgIndicator'] = $returndata['ct_key'];
                }
            }


            $total_qualifiers = @$ct_qualifiers['Header']['ChargesAllowances']['AllowChrgIndicator'] ? $ct_qualifiers['Header']['ChargesAllowances']['AllowChrgIndicator'] : 0;

            if(isset($row['Header']['ChargesAllowances']['AllowChrgCode'])){
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = $row['Header']['ChargesAllowances']['AllowChrgCode'];
                }

                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgCode');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['ChargesAllowances']['AllowChrgAmt'])){ //AllowChrgAmt
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = @$chargesandallowanceitemdetail['unit_price'];
                }
                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgAmt');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['ChargesAllowances']['AllowChrgQtyUOM'])){
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>1,'type'=>'chargeallowance','uom'=>$chargesandallowanceitemdetail['uom'],'default_uom'=>$row['Header']['ChargesAllowances']['AllowChrgQtyUOM']]);
                }

                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgQtyUOM');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['ChargesAllowances']['AllowChrgQty'])){
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = @$chargesandallowanceitemdetail['qty'];
                }
                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgQty');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['ChargesAllowances']['AllowChrgHandlingCode'])){
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = @$row['Header']['ChargesAllowances']['AllowChrgHandlingCode'];
                }
                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgHandlingCode');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['ChargesAllowances']['AllowChrgHandlingDescription'])){
                $field_value = "";
                if(count($chargesandallowanceitemdetail) > 0){
                    $field_value = @$chargesandallowanceitemdetail['product_name'];
                }
                if($field_value!=''){
                    $data['Header']['ChargesAllowances'] = @$data['Header']['ChargesAllowances'] ? $data['Header']['ChargesAllowances'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['ChargesAllowances'],'AllowChrgHandlingDescription');
                    $data['Header']['ChargesAllowances'] = array_replace_recursive(@$data['Header']['ChargesAllowances'],$returndata['fielddata']);
                }

            }





            /***********End Charges & allowance fields************/


            /***********FOBRelatedInstruction ************/


            if(isset($mappings_custom_fields['Header']['FOBRelatedInstruction']['FOBPayCode'])){
                // when custom field is having value

                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['FOBRelatedInstruction']['FOBPayCode'])){
                    $custom_field_name = $mappings_custom_fields['Header']['FOBRelatedInstruction']['FOBPayCode'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);

                }
                $field_value = $custom_field_value;
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'][0]['FOBPayCode'] = $field_value;
                    $ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] = 1;
                }

            }else if(isset($row['Header']['FOBRelatedInstruction']['FOBPayCode'])){
                $field_value = $row['Header']['FOBRelatedInstruction']['FOBPayCode'];
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'] = @$data['Header']['FOBRelatedInstruction'] ? $data['Header']['FOBRelatedInstruction'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['FOBRelatedInstruction'],'FOBPayCode');
                    $data['Header']['FOBRelatedInstruction'] = array_replace_recursive(@$data['Header']['FOBRelatedInstruction'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] ? $ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] : 0;

            if(isset($row['Header']['FOBRelatedInstruction']['FOBLocationQualifier'])){
                $field_value = $row['Header']['FOBRelatedInstruction']['FOBLocationQualifier'];
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'] = @$data['Header']['FOBRelatedInstruction'] ? $data['Header']['FOBRelatedInstruction'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['FOBRelatedInstruction'],'FOBLocationQualifier');
                    $data['Header']['FOBRelatedInstruction'] = array_replace_recursive(@$data['Header']['FOBRelatedInstruction'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['FOBRelatedInstruction']['FOBLocationDescription'])){
                $field_value = @$row['Header']['FOBRelatedInstruction']['FOBLocationDescription'] ? $row['Header']['FOBRelatedInstruction']['FOBLocationDescription'] : $default_info;
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'] = @$data['Header']['FOBRelatedInstruction'] ? $data['Header']['FOBRelatedInstruction'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['FOBRelatedInstruction'],'FOBLocationDescription');
                    $data['Header']['FOBRelatedInstruction'] = array_replace_recursive(@$data['Header']['FOBRelatedInstruction'],$returndata['fielddata']);
                }

            }

            /***********End FOBRelatedInstruction************/



            /***********Quantity Total fields************/
            if(isset($row['Header']['QuantityTotals']['QuantityTotalsQualifier'])){
                $field_value = $row['Header']['QuantityTotals']['QuantityTotalsQualifier'];
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['QuantityTotals'],'QuantityTotalsQualifier');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['QuantityTotals']['QuantityTotalsQualifier'] = $returndata['ct_key'];
                }
            }


            $total_qualifiers = @$ct_qualifiers['Header']['QuantityTotals']['QuantityTotalsQualifier'] ? $ct_qualifiers['Header']['QuantityTotals']['QuantityTotalsQualifier'] : 0;

            if(isset($row['Header']['QuantityTotals']['Quantity'])){
                $field_value = $total_qty;
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityTotals'],'Quantity');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['QuantityTotals']['QuantityUOM'])){
                $field_value = $row['Header']['QuantityTotals']['QuantityUOM'];
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityTotals'],'QuantityUOM');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                }
            }



            if(isset($row['Header']['QuantityTotals']['Weight'])){
                $field_value = $total_weight;
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityTotals'],'Weight');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['QuantityTotals']['WeightUOM'])){
                $field_value = $row['Header']['QuantityTotals']['WeightUOM'];
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityTotals'],'WeightUOM');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                }
            }






            /***********End Quantity Total fields************/



            /***********Line Item fields************/
            if(isset($row['LineItem'])){

                $line_sequence = 1;


                foreach($order_line_detail as $rowline){

                    if($rowline->api_code!='' && $chargesandallowanceitem==$rowline->api_code){
                        //ignore case when Changes And Allowance item came from Invoice
                    }else{


                        $pack_value = 0;
                        /*$is_allow_item_exchange = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->AllowItemExchange(['user_integration_id'=>$user_integration_id]);

                        if($is_allow_item_exchange && $rowline->api_code=='00914'){

                            $exchange_product = DB::table('platform_product')->select('product_name', 'description', 'mpn', 'sku', 'ean', 'gtin', 'upc', 'custom_fields')->where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id,'platform_id' => $source_platform_id,'api_product_code' => '00912'])->first();

                            $product_name = $exchange_product->product_name;
                            $ean = $exchange_product->ean;
                            $sku = $exchange_product->sku;
                            $gtin = $exchange_product->gtin;
                            $mpn = $exchange_product->mpn;
                            $upc = $exchange_product->upc;

                            if($exchange_product->custom_fields!=''){
                                $custom_fields = json_decode($exchange_product->custom_fields,true);
                                $pack_value = @$custom_fields['pack_value'] ? $custom_fields['pack_value'] : 0;
                            }

                        }else{*/
                            $product_code = $rowline->api_code;
                            $product_name = $rowline->product_name;
                            $ean = $rowline->ean;
                            $sku = $rowline->sku;
                            $gtin = $rowline->gtin;
                            $mpn = $rowline->mpn;
                            $upc = $rowline->upc;

                            if($rowline->custom_fields!=''){
                                $custom_fields = json_decode($rowline->custom_fields,true);
                                $pack_value = @$custom_fields['pack_value'] ? $custom_fields['pack_value'] : 0;
                            }

                        //}

                        $qty = $rowline->qty;
                        $uom = $rowline->uom;
                        $unit_price = @$rowline->unit_price ? @$rowline->unit_price : 0;
                        $shipped_qty = @$rowline->shipped_qty ? @$rowline->shipped_qty : 0;
                        $total = @$rowline->total ? @$rowline->total : 0;
                        $description = $rowline->description;
                        $k = $line_sequence - 1;
                        $price =  @$rowline->price;



                        $total_qualifiers = 0;


                        if(isset($row['LineItem']['InvoiceLine']['LineSequenceNumber'])){
                            $field_value = $line_sequence;
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['LineSequenceNumber'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['BuyerPartNumber'])){
                            $field_value = @${$mappings_fields['LineItem']['InvoiceLine']['BuyerPartNumber']};
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                            $data['LineItem'][$k]['InvoiceLine']['BuyerPartNumber'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['VendorPartNumber'])){
                            $field_value = @${$mappings_fields['LineItem']['InvoiceLine']['VendorPartNumber']};
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['VendorPartNumber'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['ConsumerPackageCode'])){
                            $field_value = @${$mappings_fields['LineItem']['InvoiceLine']['ConsumerPackageCode']};
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['ConsumerPackageCode'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['EAN'])){
                            $field_value = @${$mappings_fields['LineItem']['InvoiceLine']['EAN']};
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['EAN'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['GTIN'])){
                            $field_value = @${$mappings_fields['LineItem']['InvoiceLine']['GTIN']};
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['GTIN'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['UPCCaseCode'])){
                            $field_value = @${$mappings_fields['LineItem']['InvoiceLine']['UPCCaseCode']};
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['UPCCaseCode'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['OrderQty'])){
                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>0,'type'=>'order','shipped_qty'=>$shipped_qty,'qty'=>$qty,'uom'=>$uom,'default_uom'=>$mappings[intval($ct_row)+1]['LineItem']['InvoiceLine']['OrderQtyUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['OrderQty'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['OrderQtyUOM'])){
                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>1,'type'=>'order','uom'=>$uom,'default_uom'=>$row['LineItem']['InvoiceLine']['OrderQtyUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['OrderQtyUOM'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['PurchasePrice'])){
                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomPrice(['user_integration_id'=>$user_integration_id,'unit_price'=>$unit_price,'total'=>$total,'shipped_qty'=>$shipped_qty,'uom'=>$uom,'product_code'=>$product_code,'customer_code'=>$customer_code,'price'=>$price]);
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['PurchasePrice'] = $field_value;
                            }
                        }



                        if(isset($row['LineItem']['InvoiceLine']['InvoiceQty'])){

                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>0,'type'=>'invoice','shipped_qty'=>$shipped_qty,'qty'=>$qty,'uom'=>$uom,'default_uom'=>$mappings[intval($ct_row)+1]['LineItem']['InvoiceLine']['InvoiceQtyUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['InvoiceQty'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['InvoiceQtyUOM'])){

                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>1,'type'=>'invoice','uom'=>$uom,'default_uom'=>$row['LineItem']['InvoiceLine']['InvoiceQtyUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['InvoiceQtyUOM'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['ShipQty'])){
                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>0,'type'=>'shipping','shipped_qty'=>$shipped_qty,'qty'=>$qty,'uom'=>$uom,'default_uom'=>$mappings[intval($ct_row)+1]['LineItem']['InvoiceLine']['ShipQtyUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);
                            if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['ShipQty'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['ShipQtyUOM'])){
                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>1,'type'=>'shipping','uom'=>$uom,'default_uom'=>$row['LineItem']['InvoiceLine']['ShipQtyUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);
                           if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['ShipQtyUOM'] = $field_value;
                            }
                        }

                        if(isset($row['LineItem']['InvoiceLine']['ExtendedItemTotal'])){
                            $field_value = 0;//$row['LineItem']['InvoiceLine']['ExtendedItemTotal'];
                            //it can be like total of line item like below commented code
                            //$field_value = floatval(@$unit_price ? $unit_price : $total) * floatval($qty);
                            //if($field_value!=''){
                                $data['LineItem'][$k]['InvoiceLine'] = @$data['LineItem'][$k]['InvoiceLine'] ? $data['LineItem'][$k]['InvoiceLine'] : array();
                                $data['LineItem'][$k]['InvoiceLine']['ExtendedItemTotal'] = $field_value;
                            //}
                        }




                        if(isset($row['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'])){
                            $field_value = $row['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'];
                            if($field_value!=''){
                                $data['LineItem'][$k]['ProductOrItemDescription'] = @$data['LineItem'][$k]['ProductOrItemDescription'] ? $data['LineItem'][$k]['ProductOrItemDescription'] : array();

                                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['LineItem']['ProductOrItemDescription'],'ProductCharacteristicCode');
                                $data['LineItem'][$k]['ProductOrItemDescription'] = array_replace_recursive(@$data['LineItem'][$k]['ProductOrItemDescription'],$returndata['fielddata']);
                                $ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] = $returndata['ct_key'];

                            }

                        }

                        $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] ? $ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] : 0;

                        if(isset($row['LineItem']['ProductOrItemDescription']['ProductDescription'])){
                            $field_value = @$product_name ? @$product_name : (@$description ? @$description : $default_info);

                            if($field_value!=''){
                                $data['LineItem'][$k]['ProductOrItemDescription'] = @$data['LineItem'][$k]['ProductOrItemDescription'] ? $data['LineItem'][$k]['ProductOrItemDescription'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['ProductOrItemDescription'],'ProductDescription');
                                $data['LineItem'][$k]['ProductOrItemDescription'] = array_replace_recursive(@$data['LineItem'][$k]['ProductOrItemDescription'],$returndata['fielddata']);
                            }
                        }


                        if(isset($row['LineItem']['PhysicalDetails']['PackQualifier'])){
                            $field_value = @$row['LineItem']['PhysicalDetails']['PackQualifier'];
                            if($field_value!=''){
                                $data['LineItem'][$k]['PhysicalDetails'] = @$data['LineItem'][$k]['PhysicalDetails'] ? $data['LineItem'][$k]['PhysicalDetails'] : array();

                                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['LineItem']['PhysicalDetails'],'PackQualifier');
                                $data['LineItem'][$k]['PhysicalDetails'] = array_replace_recursive(@$data['LineItem'][$k]['PhysicalDetails'],$returndata['fielddata']);
                                $ct_qualifiers['LineItem'][$k]['PhysicalDetails']['PackQualifier'] = $returndata['ct_key'];

                            }

                        }

                        $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['PhysicalDetails']['PackQualifier'] ? $ct_qualifiers['LineItem'][$k]['PhysicalDetails']['PackQualifier'] : 0;


                        if(isset($row['LineItem']['PhysicalDetails']['PackValue']) && $total_qualifiers_child > 0){

                            $field_value = @$pack_value ? @$pack_value : @$row['LineItem']['PhysicalDetails']['PackValue'];

                            if($field_value!=''){
                                $data['LineItem'][$k]['PhysicalDetails'] = @$data['LineItem'][$k]['PhysicalDetails'] ? $data['LineItem'][$k]['PhysicalDetails'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['PhysicalDetails'],'PackValue');
                                $data['LineItem'][$k]['PhysicalDetails'] = array_replace_recursive(@$data['LineItem'][$k]['PhysicalDetails'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['LineItem']['PhysicalDetails']['WeightQualifier']) && $total_qualifiers_child > 0){

                            $field_value = @$row['LineItem']['PhysicalDetails']['WeightQualifier'];

                            if($field_value!=''){
                                $data['LineItem'][$k]['PhysicalDetails'] = @$data['LineItem'][$k]['PhysicalDetails'] ? $data['LineItem'][$k]['PhysicalDetails'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['PhysicalDetails'],'WeightQualifier');
                                $data['LineItem'][$k]['PhysicalDetails'] = array_replace_recursive(@$data['LineItem'][$k]['PhysicalDetails'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['LineItem']['PhysicalDetails']['PackWeight']) && $total_qualifiers_child > 0){

                            $field_value = @$row['LineItem']['PhysicalDetails']['PackWeight'];

                            if($field_value!=''){
                                $data['LineItem'][$k]['PhysicalDetails'] = @$data['LineItem'][$k]['PhysicalDetails'] ? $data['LineItem'][$k]['PhysicalDetails'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['PhysicalDetails'],'PackWeight');
                                $data['LineItem'][$k]['PhysicalDetails'] = array_replace_recursive(@$data['LineItem'][$k]['PhysicalDetails'],$returndata['fielddata']);
                            }
                        }

                        if(isset($row['LineItem']['PhysicalDetails']['PackWeightUOM']) && $total_qualifiers_child > 0){

                            $field_value = app('App\Http\Controllers\Spscommerce\SpscommerceIntegrationCustomLogic')->CustomQuantityAndUOM($user_integration_id,['is_return_uom'=>1,'type'=>'pack','uom'=>$uom,'default_uom'=>$row['LineItem']['PhysicalDetails']['PackWeightUOM'],'product_code'=>$product_code,'customer_code'=>$customer_code]);


                            if($field_value!=''){
                                $data['LineItem'][$k]['PhysicalDetails'] = @$data['LineItem'][$k]['PhysicalDetails'] ? $data['LineItem'][$k]['PhysicalDetails'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['PhysicalDetails'],'PackWeightUOM');
                                $data['LineItem'][$k]['PhysicalDetails'] = array_replace_recursive(@$data['LineItem'][$k]['PhysicalDetails'],$returndata['fielddata']);
                            }
                        }


                        /*
                        // Using When Discount Comes Up with allowance 'A'
                        if(isset($row['LineItem']['ChargesAllowances']['AllowChrgIndicator'])){
                            $data['LineItem'][$k]['ChargesAllowances'] = @$data['LineItem'][$k]['ChargesAllowances'] ? $data['LineItem'][$k]['ChargesAllowances'] : array();

                            $field_value = $row['LineItem']['ChargesAllowances']['AllowChrgIndicator'];

                            $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['LineItem']['ChargesAllowances'],'AllowChrgIndicator');
                            $data['LineItem'][$k]['ChargesAllowances'] = array_replace_recursive(@$data['LineItem'][$k]['ChargesAllowances'],$returndata['fielddata']);
                            $ct_qualifiers['LineItem'][$k]['ChargesAllowances']['AllowChrgIndicator'] = $returndata['ct_key'];
                        }

                        $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['ChargesAllowances']['AllowChrgIndicator'] ? $ct_qualifiers['LineItem'][$k]['ChargesAllowances']['AllowChrgIndicator'] : 0;

                        if(isset($row['LineItem']['ChargesAllowances']['AllowChrgCode'])){
                            $data['LineItem'][$k]['ChargesAllowances'] = @$data['LineItem'][$k]['ChargesAllowances'] ? $data['LineItem'][$k]['ChargesAllowances'] : array();
                            $field_value = $row['LineItem']['ChargesAllowances']['AllowChrgCode'];
                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['ChargesAllowances'],'AllowChrgCode');
                            $data['LineItem'][$k]['ChargesAllowances'] = array_replace_recursive(@$data['LineItem'][$k]['ChargesAllowances'],$returndata['fielddata']);
                        }

                        if(isset($row['LineItem']['ChargesAllowances']['AllowChrgAmt'])){
                            $data['LineItem'][$k]['ChargesAllowances'] = @$data['LineItem'][$k]['ChargesAllowances'] ? $data['LineItem'][$k]['ChargesAllowances'] : array();
                            $field_value = "";
                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['LineItem']['ChargesAllowances'],'AllowChrgAmt');
                            $data['LineItem'][$k]['ChargesAllowances'] = array_replace_recursive(@$data['LineItem'][$k]['ChargesAllowances'],$returndata['fielddata']);
                        }
                        */

                        $line_sequence++;

                    }



                }



            }

            if(isset($row['Summary']['TotalLineItemNumber'])){
                $data['Summary']['TotalLineItemNumber'] = $line_sequence - 1;
            }

            if(isset($row['Summary']['TotalAmount'])){
                $data['Summary']['TotalAmount'] = $TotalAmount;
            }

            /***********End Quantity Total fields************/
            $ct_row++;

        }

        $postdata = json_encode($data,true);

        return $postdata;


    }







    public function GetStructuredShipmentPostData($user_id,$user_integration_id,$platform_workflow_rule_id,$source_platform_id,$trading_partner_id='',$sync_object_id,$mapped_file,$customer_detail,$order_detail,$order_line_detail,$order_address_detail,$order_additional_detail,$shipment_detail)
    {


        $csvFile = file(base_path().'/'.$mapped_file);

        $i = $ct_order = $ct_pack_level =  $ct_item_level = 0;
        $mappings = $mappings_fields = $mappings_custom_fields = $data =  $ct_qualifiers = $order_level = $pack_level = $item_level = [];
        foreach ($csvFile as $line) {
            if($i!=0){
                $arrrow = str_getcsv($line);

                $fields = explode('/',$arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[1] having default value & $arrrow[2]  having custom field

                if(count($fields)==2){
                    $mappings[][$fields[1]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]] = $arrrow[2];
                }else if(count($fields)==3){
                    $mappings[][$fields[1]][$fields[2]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]] = $arrrow[2];
                }else if(count($fields)==4){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                }else if(count($fields)==5){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                }else if(count($fields)==6){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                }else if(count($fields)==7){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                }else if(count($fields)==8){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                }

            }
            $i++;
        }



        //$total_line_items = count($order_line_detail);
        $total = 0;
        $total_line_items = 1;

        $default_info = "No Information";


        $field_value = $OrderStatusID = $Vendor = $product_name = $ean = $sku = $mpn = $gtin = $upc = $qty = $unit_price = $shipping_address_name = $shipping_company = $shipping_address1 = $shipping_address2 = $shipping_address3 = $shipping_address4 = $shipping_city = $shipping_state = $shipping_postal_code = $shipping_country = $shipping_email = $shipping_phone_number = $billing_address_name = $billing_company = $billing_address1 = $billing_address2 = $billing_address3 = $billing_address4 = $billing_city = $billing_state = $billing_postal_code = $billing_country = $billing_email = $billing_phone_number = $shippedfrom_address_name = $shippedfrom_company = $shippedfrom_address1 = $shippedfrom_address2 = $shippedfrom_address3 = $shippedfrom_address4 = $shippedfrom_city = $shippedfrom_state = $shippedfrom_postal_code = $shippedfrom_country = $shippedfrom_email = $shippedfrom_phone_number = "";




        $shipment_id = $order_id = $shipment_date = $shipment_time = $shipping_method = $carrier_code = $tracking_info = $current_delivery_date = $current_delivery_time = $ShippingMethodName = $carrier_trans_method_code = $carrier_alpha_code = "";

        $customer_name = $customer_phone = $customer_email = $customer_fax = "";

        $shipment_id = @$shipment_detail->shipment_id;
        $order_id = @$shipment_detail->order_id;
        $shipment_date = @$shipment_detail->created_on ? date('Y-m-d',strtotime($shipment_detail->created_on)) : '';
        $shipment_time = @$shipment_detail->created_on ? date('H:i:s',strtotime($shipment_detail->created_on)) : '';
        $shipping_method = @$shipment_detail->shipping_method;
        $carrier_code = @$shipment_detail->carrier_code;
        $tracking_info = @$shipment_detail->tracking_info;
        $current_delivery_date = @$shipment_detail->realease_date ? date('Y-m-d',strtotime($shipment_detail->realease_date)) : '';
        $current_delivery_time = @$shipment_detail->realease_date ? date('H:i:s',strtotime($shipment_detail->realease_date)) : '';
        $total_ship_qty = $shipment_detail->total_ship_qty;


        // Carrier Trans Method Code Mapping
        $carrier_trans_method = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "shipment_shipping_method", ['api_id','name'], 'regular', $shipping_method);
        $carrier_trans_method_code = isset($carrier_trans_method->api_id) ? $carrier_trans_method->api_id : '';


        // Carrier Alpha Code Mapping
        $shipping_method_with_custom_object_id = $this->helper->getObjectId('shipping_method_with_custom');
        $carrier_alpha = DB::table('platform_object_data')->where(['user_integration_id' =>$user_integration_id,'api_id' => $shipping_method,'platform_object_id'=>$shipping_method_with_custom_object_id, 'platform_id' => $source_platform_id])->select('id')->first();
        if ($carrier_alpha) {
            $carrier_alpha_code = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "shipping_method_with_custom", [], 'regular', null,'single','source',[], $carrier_alpha->id);
        }




        $customer_name = @$customer_detail->customer_name;
        $customer_phone = @$customer_detail->phone;
        $customer_email = @$customer_detail->email;
        $customer_fax = @$customer_detail->fax;


        $id = $order_detail->id;
        $PurchaseOrderNumber = @$order_detail->order_number;
        //$PurchaseOrderNumber = @$order_detail->api_order_id;
        $DeliveryDate = @$order_detail->delivery_date ? date('Y-m-d',strtotime($order_detail->delivery_date)) : '';
        $PurchaseOrderDate = @$order_detail->order_date ? date('Y-m-d',strtotime($order_detail->order_date)) : '';
        $OrderRef = @$order_detail->api_order_reference ? $order_detail->api_order_reference : '';
        $linked_id = @$order_detail->linked_id ? $order_detail->linked_id : '';
        $shipment_status = @$order_detail->shipment_status;
        //$trading_partner_id = @$order_detail->trading_partner_id;
        $total_tax = @$order_detail->total_tax ? $order_detail->total_tax : 0;

        //$TotalAmount = @$order_detail->total_amount ? @$order_detail->total_amount : 0;


        $spm = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "porder_shipping_method", ['api_id','name'], 'regular', $shipping_method);
        $ShippingMethod = isset($spm->api_id) ? $spm->api_id : '';
        $ShippingMethodName = isset($spm->name) ? $spm->name : '';
        if($order_detail->order_status!=''){
            $OrderStatus = $this->map->getMappedDataByName($user_integration_id, null, "get_order_status", ['name'], "regular", $order_detail->order_status, "single", "destination", ['api_id', 'name']);
            $OrderStatusID = @$OrderStatus->api_id ? $OrderStatus->api_id : '';
        }





        $IsDropShip = @$order_additional_detail->is_drop_ship ? $order_additional_detail->is_drop_ship : 0;
        $CustomerOrderNumber = @$order_additional_detail->parent_order_id ? $order_additional_detail->parent_order_id : '';


        $addresses = [];
        foreach($order_address_detail as $rowaddress){
            if($rowaddress->address_type=='shipping'){
                //$addresses[] = 'ST';

                $shipping_address_name = $rowaddress->address_name;
                $shipping_company = $rowaddress->company;
                $shipping_address1 = $rowaddress->address1;
                $shipping_address2 = $rowaddress->address2;
                $shipping_address3 = $rowaddress->address3;
                $shipping_address4 = $rowaddress->address4;
                $shipping_city = $rowaddress->city;
                $shipping_state = $rowaddress->state;
                $shipping_postal_code = $rowaddress->postal_code;
                $shipping_country = $rowaddress->country;
                $shipping_email = $rowaddress->email;
                $shipping_phone_number = $rowaddress->phone_number;
            }else if($rowaddress->address_type=='billing'){
                //$addresses[] = 'BT';
                $billing_address_name = $rowaddress->address_name;
                $billing_company = $rowaddress->company;
                $billing_address1 = $rowaddress->address1;
                $billing_address2 = $rowaddress->address2;
                $billing_address3 = $rowaddress->address3;
                $billing_address4 = $rowaddress->address4;
                $billing_city = $rowaddress->city;
                $billing_state = $rowaddress->state;
                $billing_postal_code = $rowaddress->postal_code;
                $billing_country = $rowaddress->country;
                $billing_email = $rowaddress->email;
                $billing_phone_number = $rowaddress->phone_number;
            }if($rowaddress->address_type=='shippedfrom'){
                //$addresses[] = 'ST';

                $shippedfrom_address_name = $rowaddress->address_name;
                $shippedfrom_company = $rowaddress->company;
                $shippedfrom_address1 = $rowaddress->address1;
                $shippedfrom_address2 = $rowaddress->address2;
                $shippedfrom_address3 = $rowaddress->address3;
                $shippedfrom_address4 = $rowaddress->address4;
                $shippedfrom_city = $rowaddress->city;
                $shippedfrom_state = $rowaddress->state;
                $shippedfrom_postal_code = $rowaddress->postal_code;
                $shippedfrom_country = $rowaddress->country;
                $shippedfrom_email = $rowaddress->email;
                $shippedfrom_phone_number = $rowaddress->phone_number;
            }
        }


        foreach($mappings as $row){

            if(isset($row['Header']['ShipmentHeader']['TradingPartnerId']) && $trading_partner_id!=''){
                $data['Header']['ShipmentHeader']['TradingPartnerId'] = $trading_partner_id;
            }
            if(isset($row['Header']['ShipmentHeader']['ShipmentIdentification']) && $tracking_info!=''){
                $data['Header']['ShipmentHeader']['ShipmentIdentification'] = $tracking_info;
            }
            if(isset($row['Header']['ShipmentHeader']['ShipDate']) && $shipment_date!=''){
                $data['Header']['ShipmentHeader']['ShipDate'] = $shipment_date;
            }
            if(isset($row['Header']['ShipmentHeader']['ShipmentTime']) && $shipment_time!=''){
                $data['Header']['ShipmentHeader']['ShipmentTime'] = $shipment_time;
            }
            if(isset($row['Header']['ShipmentHeader']['TsetPurposeCode'])){
                $data['Header']['ShipmentHeader']['TsetPurposeCode'] =  $row['Header']['ShipmentHeader']['TsetPurposeCode'];//@$OrderStatusID ? $OrderStatusID : $row['Header']['ShipmentHeader']['TsetPurposeCode'];
            }
            if(isset($row['Header']['ShipmentHeader']['TsetTypeCode'])){
                //$data['Header']['ShipmentHeader']['TsetTypeCode'] = ""; //deprecated
            }
            if(isset($row['Header']['ShipmentHeader']['ShipNoticeDate']) && $shipment_date!=''){
                $data['Header']['ShipmentHeader']['ShipNoticeDate'] = $shipment_date;
            }
            if(isset($row['Header']['ShipmentHeader']['ShipNoticeTime']) && $shipment_time!=''){
                $data['Header']['ShipmentHeader']['ShipNoticeTime'] = $shipment_time;
            }
            if(isset($row['Header']['ShipmentHeader']['ASNStructureCode'])){
                $data['Header']['ShipmentHeader']['ASNStructureCode'] = $row['Header']['ShipmentHeader']['ASNStructureCode'];
            }
            if(isset($row['Header']['ShipmentHeader']['BillOfLadingNumber']) && $tracking_info!=''){
                $data['Header']['ShipmentHeader']['BillOfLadingNumber'] = $tracking_info;
            }
            if(isset($row['Header']['ShipmentHeader']['CarrierProNumber']) && $tracking_info!=''){
                $data['Header']['ShipmentHeader']['CarrierProNumber'] = $tracking_info;
            }
            if(isset($row['Header']['ShipmentHeader']['CurrentScheduledDeliveryDate']) && $PurchaseOrderDate!=''){
                $data['Header']['ShipmentHeader']['CurrentScheduledDeliveryDate'] = $PurchaseOrderDate;
            }
            if(isset($row['Header']['ShipmentHeader']['CurrentScheduledDeliveryTime']) && $current_delivery_time!=''){
                //$data['Header']['ShipmentHeader']['CurrentScheduledDeliveryTime'] = $current_delivery_time;
            }



            /***********Dates fields************/

            if(isset($mappings_custom_fields['Header']['Dates']['DateTimeQualifier'])){
                // when custom field is having  multiple value

                $custom_field_value = [];
                if(strpos($mappings_custom_fields['Header']['Dates']['DateTimeQualifier'], ',') !== false){
                    $arr_custom_field_name = explode(',',$mappings_custom_fields['Header']['Dates']['DateTimeQualifier']);
                    foreach($arr_custom_field_name as $custom_field_name){
                        $custom_val = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                        $custom_field_value[] = @$custom_val ? $custom_val : '';
                    }
                }else{
                    $custom_field_name = $mappings_custom_fields['Header']['Dates']['DateTimeQualifier'];
                    $custom_val = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                    $custom_field_value[] = @$custom_val ? $custom_val : '';
                }

                $field_value = implode(',',$custom_field_value);
                if($field_value!=''){
                    $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Dates'],'DateTimeQualifier');
                    $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] = $returndata['ct_key'];
                }

            }else if(isset($row['Header']['Dates']['DateTimeQualifier'])){
                $field_value = $row['Header']['Dates']['DateTimeQualifier'];
                if($field_value!=''){
                    $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Dates'],'DateTimeQualifier');
                    $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] = $returndata['ct_key'];
                }
            }



            $total_qualifiers = @$ct_qualifiers['Header']['Dates']['DateTimeQualifier'] ? $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] : 0;

            if(isset($mappings_custom_fields['Header']['Dates']['Date'])){
                // when custom field is having multiple value

                $custom_field_value = [];
                if(strpos($mappings_custom_fields['Header']['Dates']['Date'], ',') !== false){
                    $arr_custom_field_name = explode(',',$mappings_custom_fields['Header']['Dates']['Date']);
                    foreach($arr_custom_field_name as $custom_field_name){
                        $custom_val = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                        $custom_field_value[] = @$custom_val ? $custom_val : '';
                    }
                }else{
                    $custom_field_name = $mappings_custom_fields['Header']['Dates']['Date'];
                    $custom_val = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                    $custom_field_value[] = @$custom_val ? $custom_val : '';
                }

                $field_value = implode(',',$custom_field_value);
                if($field_value!=''){
                    $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Dates'],'Date');
                    $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                }

            }else if(isset($row['Header']['Dates']['Date'])){

                $field_value = @$DeliveryDate ? @$DeliveryDate : @$shipment_date;
                if($field_value!=''){
                    $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Dates'],'Date');
                    $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                }

            }

            /***********End Dates fields************/

            /***********References fields************/

            if(isset($mappings_custom_fields['Header']['References']['ReferenceQual'])){
                // when custom field is having value

                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['References']['ReferenceQual'])){
                    $custom_field_name = $mappings_custom_fields['Header']['References']['ReferenceQual'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);

                }
                $field_value = $custom_field_value;
                if($field_value!=''){
                    $data['Header']['References'][0]['ReferenceQual'] = $field_value;
                    $ct_qualifiers['Header']['References']['ReferenceQual'] = 1;
                }

            }else if(isset($row['Header']['References']['ReferenceQual'])){
                $field_value = $row['Header']['References']['ReferenceQual'];
                if($field_value!=''){
                    $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['References'],'ReferenceQual');
                    $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['References']['ReferenceQual'] = $returndata['ct_key'];
                }
            }



            $total_qualifiers = @$ct_qualifiers['Header']['References']['ReferenceQual'] ? $ct_qualifiers['Header']['References']['ReferenceQual'] : 0;

            if(isset($row['Header']['References']['ReferenceID'])){
                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['References']['ReferenceID'])){
                    $custom_field_name = $mappings_custom_fields['Header']['References']['ReferenceID'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }


                $field_value = $custom_field_value;
                if($field_value!=''){
                    $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['References'],'ReferenceID');
                    $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                }
            }


            /***********End References fields************/

            /***********Notes fields************/

            if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){
                // when custom field is having value

                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){
                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['NoteCode'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);

                }
                $field_value = $custom_field_value;
                if($field_value!=''){
                    $data['Header']['Notes'][0]['NoteCode'] = $field_value;
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = 1;
                }

            }else if(isset($row['Header']['Notes']['NoteCode'])){
                $field_value = $row['Header']['Notes']['NoteCode'];
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Notes'],'NoteCode');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Notes']['NoteCode'] ? $ct_qualifiers['Header']['Notes']['NoteCode'] : 0;

            if(isset($row['Header']['Notes']['Note'])){
                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['Notes']['Note'])){
                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['Note'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }

                $field_value = $custom_field_value;
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Notes'],'Note');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                }


            }

            /***********End Notes fields************/



            /***********Contacts fields************/
            if(isset($row['Header']['Contacts']['ContactTypeCode'])){

                $field_value = $row['Header']['Contacts']['ContactTypeCode'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Contacts'],'ContactTypeCode');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] = $returndata['ct_key'];
                }
            }



            $total_qualifiers = @$ct_qualifiers['Header']['Contacts']['ContactTypeCode'] ? $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] : 0;

            if(isset($row['Header']['Contacts']['ContactName'])){
                $field_value = @$customer_name ? $customer_name : $row['Header']['Contacts']['ContactName'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'ContactName');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryPhone'])){
                $field_value = @$customer_phone ? $customer_phone : $row['Header']['Contacts']['PrimaryPhone'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryPhone');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryEmail'])){
                $field_value = @$customer_email ? $customer_email : $row['Header']['Contacts']['PrimaryEmail'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryEmail');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['Contacts']['PrimaryFax'])){
                $field_value = @$customer_fax ? $customer_fax : $row['Header']['Contacts']['PrimaryFax'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryFax');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            /***********End Contacts fields************/

            /***********Address Fields************/
            //foreach($addresses as $addr){

                if(isset($row['Header']['Address']['AddressTypeCode'])){
                    $field_value = $row['Header']['Address']['AddressTypeCode'];
                    if($field_value!=''){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Address'],'AddressTypeCode');
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                        $ct_qualifiers['Header']['Address']['AddressTypeCode'] = $returndata['ct_key'];
                    }

                }

                $total_qualifiers = @$ct_qualifiers['Header']['Address']['AddressTypeCode'] ? $ct_qualifiers['Header']['Address']['AddressTypeCode'] : 0;

                if(isset($row['Header']['Address']['LocationCodeQualifier'])){
                    $field_value = $row['Header']['Address']['LocationCodeQualifier'];
                    if($field_value!=''){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'LocationCodeQualifier');
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['AddressLocationNumber'])){
                    $field_value = $row['Header']['Address']['AddressLocationNumber'];
                    if($field_value!=''){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressLocationNumber');
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

               if(isset($row['Header']['Address']['AddressName'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address_name;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_address_name;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['AddressName'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_address_name;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressName',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }

                }

                //if(isset($row['Header']['Address']['AddressAlternateName'])){
                //    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                //    $field_value = "";
                //     $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressAlternateName');
                //    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                //}

                if(isset($row['Header']['Address']['Address1'])){
                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address1;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_address1;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['Address1'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_address1;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address1',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }


                if(isset($row['Header']['Address']['Address2'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address2;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_address2;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['Address2'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_address2;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address2',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['Address3'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address3;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_address3;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['Address3'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_address3;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address3',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['Address4'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_address4;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_address4;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['Address4'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_address4;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Address4',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['City'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_city;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_city;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['City'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_city;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                       $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'City',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['State'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_state;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_state;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['State'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_state;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }

                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'State',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['PostalCode'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_postal_code;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_postal_code;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['PostalCode'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_postal_code;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }
                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'PostalCode',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

                if(isset($row['Header']['Address']['Country'])){

                    $field_value = "";
                    $multi_values = [];
                    if(isset($data['Header']['Address'])){
                        foreach($data['Header']['Address'] as $rowadd){
                            if($rowadd['AddressTypeCode']=='ST'){
                                $multi_values[] = $shipping_country;
                            }else if($rowadd['AddressTypeCode']=='BT'){
                                $multi_values[]  = $billing_country;
                            }else if($rowadd['AddressTypeCode']=='RI'){
                                $multi_values[]  = $row['Header']['Address']['Country'];
                            }else if($rowadd['AddressTypeCode']=='SF'){
                                $multi_values[]  = $shippedfrom_country;
                            }else{
                                $multi_values[] = '';
                            }
                        }
                    }
                    if(count($multi_values) > 0){
                        $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                        $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'Country',$data,$multi_values);
                        $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    }
                }

            //}


            /*********** End Address Fields************/


            /***********CarrierInformation fields************/
            if(isset($row['Header']['CarrierInformation']['StatusCode'])){

                $field_value = ($shipment_status=='Partial') ? 'PR' : $row['Header']['CarrierInformation']['StatusCode'];
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['CarrierInformation'],'StatusCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['CarrierInformation']['StatusCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['CarrierInformation']['StatusCode'] ? $ct_qualifiers['Header']['CarrierInformation']['StatusCode'] : 0;


            if(isset($row['Header']['CarrierInformation']['CarrierTransMethodCode'])){
                $field_value = @$carrier_trans_method_code ? $carrier_trans_method_code : $row['Header']['CarrierInformation']['CarrierTransMethodCode'];
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'CarrierTransMethodCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }


            if(isset($row['Header']['CarrierInformation']['CarrierAlphaCode'])){
                $field_value = @$carrier_alpha_code ? $carrier_alpha_code : $row['Header']['CarrierInformation']['CarrierAlphaCode'];
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'CarrierAlphaCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }



            if(isset($row['Header']['CarrierInformation']['CarrierRouting'])){
                $field_value = $ShippingMethodName;
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'CarrierRouting');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }

            /*if(isset($row['Header']['CarrierInformation']['ServiceLevelCode'])){
                $field_value = $ShippingMethod;
                if($field_value!=''){
                    $data['Header']['CarrierInformation'] = @$data['Header']['CarrierInformation'] ? $data['Header']['CarrierInformation'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['CarrierInformation'],'ServiceLevelCode');
                    $data['Header']['CarrierInformation'] = array_replace_recursive(@$data['Header']['CarrierInformation'],$returndata['fielddata']);
                }
            }*/



            /***********End CarrierInformation fields************/

            /***********Quantity And Weight fields************/
            if(isset($row['Header']['QuantityAndWeight']['PackingMedium'])){
                $field_value = $row['Header']['QuantityAndWeight']['PackingMedium'];
                if($field_value!=''){
                    $data['Header']['QuantityAndWeight'] = @$data['Header']['QuantityAndWeight'] ? $data['Header']['QuantityAndWeight'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['QuantityAndWeight'],'PackingMedium');
                    $data['Header']['QuantityAndWeight'] = array_replace_recursive(@$data['Header']['QuantityAndWeight'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['QuantityAndWeight']['PackingMedium'] = $returndata['ct_key'];
                }
            }


            $total_qualifiers = @$ct_qualifiers['Header']['QuantityAndWeight']['PackingMedium'] ? $ct_qualifiers['Header']['QuantityAndWeight']['PackingMedium'] : 0;

            if(isset($row['Header']['QuantityAndWeight']['PackingMaterial'])){
                $field_value = $row['Header']['QuantityAndWeight']['PackingMaterial'];
                if($field_value!=''){
                    $data['Header']['QuantityAndWeight'] = @$data['Header']['QuantityAndWeight'] ? $data['Header']['QuantityAndWeight'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityAndWeight'],'PackingMaterial');
                    $data['Header']['QuantityAndWeight'] = array_replace_recursive(@$data['Header']['QuantityAndWeight'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['QuantityAndWeight']['LadingQuantity'])){
                $field_value = $total_ship_qty;
                if($field_value!=''){
                    $data['Header']['QuantityAndWeight'] = @$data['Header']['QuantityAndWeight'] ? $data['Header']['QuantityAndWeight'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityAndWeight'],'LadingQuantity');
                    $data['Header']['QuantityAndWeight'] = array_replace_recursive(@$data['Header']['QuantityAndWeight'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['QuantityAndWeight']['WeightQualifier'])){
                $field_value = $row['Header']['QuantityAndWeight']['WeightQualifier'];
                if($field_value!=''){
                    $data['Header']['QuantityAndWeight'] = @$data['Header']['QuantityAndWeight'] ? $data['Header']['QuantityAndWeight'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityAndWeight'],'WeightQualifier');
                    $data['Header']['QuantityAndWeight'] = array_replace_recursive(@$data['Header']['QuantityAndWeight'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['QuantityAndWeight']['Weight'])){
                $field_value = $row['Header']['QuantityAndWeight']['Weight'];
                if($field_value!=''){
                    $data['Header']['QuantityAndWeight'] = @$data['Header']['QuantityAndWeight'] ? $data['Header']['QuantityAndWeight'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityAndWeight'],'Weight');
                    $data['Header']['QuantityAndWeight'] = array_replace_recursive(@$data['Header']['QuantityAndWeight'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['QuantityAndWeight']['WeightUOM'])){
                $field_value = $row['Header']['QuantityAndWeight']['WeightUOM'];
                if($field_value!=''){
                    $data['Header']['QuantityAndWeight'] = @$data['Header']['QuantityAndWeight'] ? $data['Header']['QuantityAndWeight'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityAndWeight'],'WeightUOM');
                    $data['Header']['QuantityAndWeight'] = array_replace_recursive(@$data['Header']['QuantityAndWeight'],$returndata['fielddata']);
                }
            }



            /***********End Quantity And Weight fields************/


            /***********Tax fields************/


            if(isset($row['Header']['Taxes']['TaxTypeCode'])){
                $field_value = $row['Header']['Taxes']['TaxTypeCode'];
                if($field_value!=''){
                    $data['Header']['Taxes'] = @$data['Header']['Taxes'] ? $data['Header']['Taxes'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Taxes'],'TaxTypeCode');
                    $data['Header']['Taxes'] = array_replace_recursive(@$data['Header']['Taxes'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Taxes']['TaxTypeCode'] = $returndata['ct_key'];
                }
            }


            $total_qualifiers = @$ct_qualifiers['Header']['Taxes']['TaxTypeCode'] ? $ct_qualifiers['Header']['Taxes']['TaxTypeCode'] : 0;

            if(isset($row['Header']['Taxes']['TaxAmount'])){
                $field_value = $total_tax;
                if($field_value!=''){
                    $data['Header']['Taxes'] = @$data['Header']['Taxes'] ? $data['Header']['Taxes'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Taxes'],'TaxAmount');
                    $data['Header']['Taxes'] = array_replace_recursive(@$data['Header']['Taxes'],$returndata['fielddata']);
                }

            }

            /*********** End Tax fields************/


            /***********FOBRelatedInstruction ************/


            if(isset($mappings_custom_fields['Header']['FOBRelatedInstruction']['FOBPayCode'])){
                // when custom field is having value

                $custom_field_value = '';
                if(isset($mappings_custom_fields['Header']['FOBRelatedInstruction']['FOBPayCode'])){
                    $custom_field_name = $mappings_custom_fields['Header']['FOBRelatedInstruction']['FOBPayCode'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);

                }
                $field_value = $custom_field_value;
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'][0]['FOBPayCode'] = $field_value;
                    $ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] = 1;
                }

            }else if(isset($row['Header']['FOBRelatedInstruction']['FOBPayCode'])){
                $field_value = $row['Header']['FOBRelatedInstruction']['FOBPayCode'];
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'] = @$data['Header']['FOBRelatedInstruction'] ? $data['Header']['FOBRelatedInstruction'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['FOBRelatedInstruction'],'FOBPayCode');
                    $data['Header']['FOBRelatedInstruction'] = array_replace_recursive(@$data['Header']['FOBRelatedInstruction'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] = $returndata['ct_key'];
                }
            }


            $total_qualifiers = @$ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] ? $ct_qualifiers['Header']['FOBRelatedInstruction']['FOBPayCode'] : 0;

            if(isset($row['Header']['FOBRelatedInstruction']['FOBLocationQualifier'])){
                $field_value = $row['Header']['FOBRelatedInstruction']['FOBLocationQualifier'];
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'] = @$data['Header']['FOBRelatedInstruction'] ? $data['Header']['FOBRelatedInstruction'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['FOBRelatedInstruction'],'FOBLocationQualifier');
                    $data['Header']['FOBRelatedInstruction'] = array_replace_recursive(@$data['Header']['FOBRelatedInstruction'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['FOBRelatedInstruction']['FOBLocationDescription'])){
                $field_value = @$row['Header']['FOBRelatedInstruction']['FOBLocationDescription'] ? $row['Header']['FOBRelatedInstruction']['FOBLocationDescription'] : $default_info;
                if($field_value!=''){
                    $data['Header']['FOBRelatedInstruction'] = @$data['Header']['FOBRelatedInstruction'] ? $data['Header']['FOBRelatedInstruction'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['FOBRelatedInstruction'],'FOBLocationDescription');
                    $data['Header']['FOBRelatedInstruction'] = array_replace_recursive(@$data['Header']['FOBRelatedInstruction'],$returndata['fielddata']);
                }

            }

            /***********End FOBRelatedInstruction************/

            /***********Quantity Total fields************/
            if(isset($row['Header']['QuantityTotals']['QuantityTotalsQualifier'])){
                $field_value = $row['Header']['QuantityTotals']['QuantityTotalsQualifier'];
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['QuantityTotals'],'QuantityTotalsQualifier');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['QuantityTotals']['QuantityTotalsQualifier'] = $returndata['ct_key'];
                }
            }


            $total_qualifiers = @$ct_qualifiers['Header']['QuantityTotals']['QuantityTotalsQualifier'] ? $ct_qualifiers['Header']['QuantityTotals']['QuantityTotalsQualifier'] : 0;

            if(isset($row['Header']['QuantityTotals']['Quantity'])){
                $field_value = "";
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityTotals'],'Quantity');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['QuantityTotals']['QuantityUOM'])){
                $field_value = $row['Header']['QuantityTotals']['QuantityUOM'];
                if($field_value!=''){
                    $data['Header']['QuantityTotals'] = @$data['Header']['QuantityTotals'] ? $data['Header']['QuantityTotals'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['QuantityTotals'],'QuantityUOM');
                    $data['Header']['QuantityTotals'] = array_replace_recursive(@$data['Header']['QuantityTotals'],$returndata['fielddata']);
                }
            }



            /***********End Quantity Total fields************/

            /***********Order Level fields************/

            if(isset($row['OrderLevel'])){

                if(isset($row['OrderLevel']['OrderHeader']['InternalOrderNumber'])){
                    $order_level[$ct_order]['OrderHeader']['InternalOrderNumber'] = $OrderRef;
                }
                if(isset($row['OrderLevel']['OrderHeader']['InvoiceNumber'])){
                    //$order_level[$ct_order]['OrderHeader']['InvoiceNumber'] = $PurchaseOrderNumber;
                }

                if(isset($row['OrderLevel']['OrderHeader']['PurchaseOrderNumber'])){
                    //if($linked_id!=''){
                    //    $order_level[$ct_order]['OrderHeader']['PurchaseOrderNumber'] = $OrderRef;
                    //}else{
                        $order_level[$ct_order]['OrderHeader']['PurchaseOrderNumber'] = $OrderRef;
                    //}
                }


                if(isset($row['OrderLevel']['OrderHeader']['PurchaseOrderDate'])){
                    $order_level[$ct_order]['OrderHeader']['PurchaseOrderDate'] = $PurchaseOrderDate;
                }
                if(isset($row['OrderLevel']['OrderHeader']['Department'])){
                    //$order_level[$ct_order]['OrderHeader']['Department'] = $PurchaseOrderNumber;
                }
                if(isset($row['OrderLevel']['OrderHeader']['Vendor'])){
                    //$order_level[$ct_order]['OrderHeader']['Vendor'] = $PurchaseOrderNumber;
                }




                /*********** Order Level Quantity Total fields************/

                if(isset($row['OrderLevel']['QuantityAndWeight']['PackingMedium'])){
                    $field_value = $row['OrderLevel']['QuantityAndWeight']['PackingMedium'];
                    if($field_value!=''){
                        $order_level[$ct_order]['QuantityAndWeight'] = @$order_level[$ct_order]['QuantityAndWeight'] ? $order_level[$ct_order]['QuantityAndWeight'] : array();

                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['OrderLevel']['QuantityAndWeight'],'PackingMedium');

                        $order_level[$ct_order]['QuantityAndWeight'] = array_replace_recursive(@$order_level[$ct_order]['QuantityAndWeight'],$returndata['fielddata']);
                        $ct_qualifiers['OrderLevel'][$ct_order]['QuantityAndWeight']['PackingMedium'] = $returndata['ct_key'];

                    }

                }



                $total_qualifiers_order = @$ct_qualifiers['OrderLevel'][$ct_order]['QuantityAndWeight']['PackingMedium'] ? $ct_qualifiers['OrderLevel'][$ct_order]['QuantityAndWeight']['PackingMedium'] : 0;

                if(isset($row['OrderLevel']['QuantityAndWeight']['PackingMaterial'])){

                    $field_value = $row['OrderLevel']['QuantityAndWeight']['PackingMaterial'];

                    if($field_value!=''){
                        $order_level[$ct_order]['QuantityAndWeight'] = @$order_level[$ct_order]['QuantityAndWeight'] ? $order_level[$ct_order]['QuantityAndWeight'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['QuantityAndWeight'],'PackingMaterial');
                        $order_level[$ct_order]['QuantityAndWeight'] = array_replace_recursive(@$order_level[$ct_order]['QuantityAndWeight'],$returndata['fielddata']);
                    }

                }



                if(isset($row['OrderLevel']['QuantityAndWeight']['LadingQuantity'])){

                    $field_value = $row['OrderLevel']['QuantityAndWeight']['LadingQuantity'];

                    if($field_value!=''){
                        $order_level[$ct_order]['QuantityAndWeight'] = @$order_level[$ct_order]['QuantityAndWeight'] ? $order_level[$ct_order]['QuantityAndWeight'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['QuantityAndWeight'],'LadingQuantity');
                        $order_level[$ct_order]['QuantityAndWeight'] = array_replace_recursive(@$order_level[$ct_order]['QuantityAndWeight'],$returndata['fielddata']);
                    }
                }


                if(isset($row['OrderLevel']['QuantityAndWeight']['WeightQualifier'])){

                    $field_value = $row['OrderLevel']['QuantityAndWeight']['WeightQualifier'];

                    if($field_value!=''){
                        $order_level[$ct_order]['QuantityAndWeight'] = @$order_level[$ct_order]['QuantityAndWeight'] ? $order_level[$ct_order]['QuantityAndWeight'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['QuantityAndWeight'],'WeightQualifier');
                        $order_level[$ct_order]['QuantityAndWeight'] = array_replace_recursive(@$order_level[$ct_order]['QuantityAndWeight'],$returndata['fielddata']);
                    }
                }

                if(isset($row['OrderLevel']['QuantityAndWeight']['Weight'])){

                    $field_value = $row['OrderLevel']['QuantityAndWeight']['Weight'];

                    if($field_value!=''){
                        $order_level[$ct_order]['QuantityAndWeight'] = @$order_level[$ct_order]['QuantityAndWeight'] ? $order_level[$ct_order]['QuantityAndWeight'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['QuantityAndWeight'],'Weight');
                        $order_level[$ct_order]['QuantityAndWeight'] = array_replace_recursive(@$order_level[$ct_order]['QuantityAndWeight'],$returndata['fielddata']);
                    }
                }

                if(isset($row['OrderLevel']['QuantityAndWeight']['WeightUOM'])){

                    $field_value = $row['OrderLevel']['QuantityAndWeight']['WeightUOM'];

                    if($field_value!=''){
                        $order_level[$ct_order]['QuantityAndWeight'] = @$order_level[$ct_order]['QuantityAndWeight'] ? $order_level[$ct_order]['QuantityAndWeight'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['QuantityAndWeight'],'WeightUOM');
                        $order_level[$ct_order]['QuantityAndWeight'] = array_replace_recursive(@$order_level[$ct_order]['QuantityAndWeight'],$returndata['fielddata']);
                    }
                }

                /***********End Order Level Quantity Total fields************/



                /*********** Order Level - Pack Level PhysicalDetails fields************/


                if(isset($row['OrderLevel']['PackLevel']['Pack']['PackLevelType'])){
                    $pack_level[$ct_pack_level]['Pack']['PackLevelType'] = $row['OrderLevel']['PackLevel']['Pack']['PackLevelType'];
                }

                if(isset($row['OrderLevel']['PackLevel']['Pack']['ShippingSerialID']) && $tracking_info!=''){
                    $pack_level[$ct_pack_level]['Pack']['ShippingSerialID'] = $tracking_info;

                }

                if(isset($row['OrderLevel']['PackLevel']['Pack']['CarrierPackageID']) && $tracking_info!=''){
                    $pack_level[$ct_pack_level]['Pack']['CarrierPackageID'] = $tracking_info;

                }

                /*


                if(isset($row['OrderLevel']['PackLevel']['PhysicalDetails']['PackQualifier'])){
                    $field_value = $row['OrderLevel']['PackLevel']['PhysicalDetails']['PackQualifier'];
                    if($field_value!=''){
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = @$pack_level[$ct_pack_level]['OrderLevel'][$ct_pack_level]['PhysicalDetails'] ? $pack_level[$ct_pack_level]['PhysicalDetails'] : array();

                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['OrderLevel']['PackLevel']['PhysicalDetails'],'PackQualifier');
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = array_replace_recursive(@$pack_level[$ct_pack_level]['PhysicalDetails'],$returndata['fielddata']);
                        $ct_qualifiers['PackLevel'][$ct_pack_level]['PhysicalDetails']['PackQualifier'] = $returndata['ct_key'];




                    }

                }

                $total_qualifiers_order = @$ct_qualifiers['PackLevel'][$ct_pack_level]['PhysicalDetails']['PackQualifier'] ? $ct_qualifiers['PackLevel'][$ct_pack_level]['PhysicalDetails']['PackQualifier'] : 0;

                if(isset($row['OrderLevel']['PackLevel']['PhysicalDetails']['PackValue'])){

                    $field_value = $row['OrderLevel']['PackLevel']['PhysicalDetails']['PackValue'];

                    if($field_value!=''){
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = @$pack_level[$ct_pack_level]['PhysicalDetails'] ? $pack_level[$ct_pack_level]['PhysicalDetails'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['PackLevel']['PhysicalDetails'],'PackValue');
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = array_replace_recursive(@$pack_level[$ct_pack_level]['PhysicalDetails'],$returndata['fielddata']);
                    }
                }


                if(isset($row['OrderLevel']['PackLevel']['PhysicalDetails']['PackSize'])){

                    $field_value = $row['OrderLevel']['PackLevel']['PhysicalDetails']['PackSize'];

                    if($field_value!=''){
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = @$pack_level[$ct_pack_level]['PhysicalDetails'] ? $pack_level[$ct_pack_level]['PhysicalDetails'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['PackLevel']['PhysicalDetails'],'PackSize');
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = array_replace_recursive(@$pack_level[$ct_pack_level]['PhysicalDetails'],$returndata['fielddata']);
                    }
                }

                if(isset($row['OrderLevel']['PackLevel']['PhysicalDetails']['PackUOM'])){

                    $field_value = $row['OrderLevel']['PackLevel']['PhysicalDetails']['PackUOM'];

                    if($field_value!=''){
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = @$pack_level[$ct_pack_level]['PhysicalDetails'] ? $pack_level[$ct_pack_level]['PhysicalDetails'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['PackLevel']['PhysicalDetails'],'PackUOM');
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = array_replace_recursive(@$pack_level[$ct_pack_level]['PhysicalDetails'],$returndata['fielddata']);
                    }
                }


                if(isset($row['OrderLevel']['PackLevel']['PhysicalDetails']['PackingMedium'])){

                    $field_value = $row['OrderLevel']['PackLevel']['PhysicalDetails']['PackingMedium'];

                    if($field_value!=''){
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = @$pack_level[$ct_pack_level]['PhysicalDetails'] ? $pack_level[$ct_pack_level]['PhysicalDetails'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['PackLevel']['PhysicalDetails'],'PackingMedium');
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = array_replace_recursive(@$pack_level[$ct_pack_level]['PhysicalDetails'],$returndata['fielddata']);
                    }
                }


                if(isset($row['OrderLevel']['PackLevel']['PhysicalDetails']['PackingMaterial'])){

                    $field_value = $row['OrderLevel']['PackLevel']['PhysicalDetails']['PackingMaterial'];

                    if($field_value!=''){
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = @$pack_level[$ct_pack_level]['PhysicalDetails'] ? $pack_level[$ct_pack_level]['PhysicalDetails'] : array();

                        $returndata =  $this->GetStructuredMappingData($total_qualifiers_order,0,$field_value,$row['OrderLevel']['PackLevel']['PhysicalDetails'],'PackingMaterial');
                        $pack_level[$ct_pack_level]['PhysicalDetails'] = array_replace_recursive(@$pack_level[$ct_pack_level]['PhysicalDetails'],$returndata['fielddata']);
                    }
                }
                */

                /***********End Order Level - Pack Level PhysicalDetails fields************/


                /*********** Order Level - Pack Level _Item Level Shipment Line fields************/


                if(isset($row['OrderLevel']['PackLevel']['ItemLevel'])){

                    $line_sequence = 1;

                    foreach($order_line_detail as $rowline){


                        $product_name = $rowline->product_name;
                        $ean = $rowline->ean;
                        $sku = $rowline->sku;
                        $gtin = $rowline->gtin;
                        $mpn = $rowline->mpn;
                        $upc = $rowline->upc;
                        $qty = $rowline->qty;
                        $unit_price = $rowline->unit_price;
                        $total = $rowline->total;
                        $description = $rowline->description;
                        $k = $line_sequence - 1;



                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['LineSequenceNumber'])){
                            $field_value = $line_sequence;
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['LineSequenceNumber'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['BuyerPartNumber'])){
                            $field_value = @${$mappings_fields['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['BuyerPartNumber']};
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                               $item_level[$k]['ShipmentLine']['BuyerPartNumber'] = $field_value;
                            }


                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['VendorPartNumber'])){
                            $field_value = @${$mappings_fields['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['VendorPartNumber']};
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                               $item_level[$k]['ShipmentLine']['VendorPartNumber'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['ConsumerPackageCode'])){
                            $field_value = @${$mappings_fields['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['ConsumerPackageCode']};
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                               $item_level[$k]['ShipmentLine']['ConsumerPackageCode'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['EAN'])){
                            $field_value = @${$mappings_fields['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['EAN']};
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                               $item_level[$k]['ShipmentLine']['EAN'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['GTIN'])){
                            $field_value = @${$mappings_fields['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['GTIN']};
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                               $item_level[$k]['ShipmentLine']['GTIN'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['UPCCaseCode'])){
                            $field_value = @${$mappings_fields['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['UPCCaseCode']};
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                               $item_level[$k]['ShipmentLine']['UPCCaseCode'] = $field_value;
                            }
                        }



                        /*

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['OrderQty'])){
                            $field_value = $qty;
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['OrderQty'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['OrderQtyUOM'])){
                            $field_value = $row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['OrderQtyUOM'];
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['OrderQtyUOM'] = $field_value;
                            }
                        }


                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['PurchasePrice'])){
                            $field_value = $total;
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['PurchasePrice'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['ItemStatusCode'])){
                            $field_value = $total;
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['ItemStatusCode'] = $field_value;
                            }
                        }
                    */


                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['ShipQty'])){
                            $field_value = $qty;
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['ShipQty'] = $field_value;
                            }
                        }

                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['ShipQtyUOM'])){
                            $field_value = $row['OrderLevel']['PackLevel']['ItemLevel']['ShipmentLine']['ShipQtyUOM'];
                            if($field_value!=''){
                                $item_level[$k]['ShipmentLine'] = @$item_level[$k]['ShipmentLine'] ? $item_level[$k]['ShipmentLine'] : array();
                                $item_level[$k]['ShipmentLine']['ShipQtyUOM'] = $field_value;
                            }
                        }





                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ProductOrItemDescription']['ProductCharacteristicCode'])){

                            $field_value = $row['OrderLevel']['PackLevel']['ItemLevel']['ProductOrItemDescription']['ProductCharacteristicCode'];
                            if($field_value!=''){

                                $item_level[$k]['ProductOrItemDescription'] = @$item_level[$k]['ProductOrItemDescription'] ? $item_level[$k]['ProductOrItemDescription'] : array();

                                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['OrderLevel']['PackLevel']['ItemLevel']['ProductOrItemDescription'],'ProductCharacteristicCode');
                                $item_level[$k]['ProductOrItemDescription'] = array_replace_recursive(@$item_level[$k]['ProductOrItemDescription'],$returndata['fielddata']);
                                $ct_qualifiers['ItemLevel'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] = $returndata['ct_key'];

                            }

                        }


                        $total_qualifiers_child = @$ct_qualifiers['ItemLevel'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] ? $ct_qualifiers['ItemLevel'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] : 0;


                        if(isset($row['OrderLevel']['PackLevel']['ItemLevel']['ProductOrItemDescription']['ProductDescription'])){


                            $field_value = $product_name ? $product_name : $default_info;

                            if($field_value!=''){
                                $item_level[$k]['ProductOrItemDescription'] = @$item_level[$k]['ProductOrItemDescription'] ? $item_level[$k]['ProductOrItemDescription'] : array();

                                $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['OrderLevel']['PackLevel']['ItemLevel']['ProductOrItemDescription'],'ProductDescription');
                                $item_level[$k]['ProductOrItemDescription'] = array_replace_recursive(@$item_level[$k]['ProductOrItemDescription'],$returndata['fielddata']);
                            }
                        }




                        $line_sequence++;

                        $pack_level[$ct_pack_level]['ItemLevel'] = $item_level;


                    }





                    //$order_level[$ct_order]['PackLevel'] = $pack_level;

                }

                $order_level[$ct_order]['PackLevel'] = $pack_level;


                /***********End Order Level - Pack Level _Item Level Shipment Line fields************/

                $data['OrderLevel'] = $order_level;
            }





            /***********End Order Level fields************/



            /***********Summary fields************/

            if(isset($row['Summary']['TotalLineItemNumber'])){
                $data['Summary']['TotalLineItemNumber'] = $line_sequence - 1;


            }

            if(isset($row['Summary']['TotalAmount'])){
                //$data['Summary']['TotalAmount'] = 0;
            }

            /***********End Summary fields************/


        }

        //echo "<pre>";
        //print_r($data);
        $postdata = json_encode($data,true);

        return $postdata;


    }




    public function GetStructuredInventoryPostData($user_id,$user_integration_id,$platform_workflow_rule_id,$source_platform_id,$trading_partner_id='',$sync_object_id,$mapped_file,$product_detail,$inventory_detail)
    {



        $csvFile = file(base_path().'/'.$mapped_file);

        $i = 0;
        $mappings = $mappings_fields = $mappings_custom_fields = $data =  $ct_qualifiers = [];
        foreach ($csvFile as $line) {
            if($i!=0){
                $arrrow = str_getcsv($line);

                $fields = explode('/',$arrrow[0]);
                // $arrrow[0] having fields  & $arrrow[1] having default value & $arrrow[2]  having custom field

                if(count($fields)==2){
                    $mappings[][$fields[1]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]] = $arrrow[2];
                }else if(count($fields)==3){
                    $mappings[][$fields[1]][$fields[2]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]] = $arrrow[2];
                }else if(count($fields)==4){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]] = $arrrow[2];
                }else if(count($fields)==5){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]] = $arrrow[2];
                }else if(count($fields)==6){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]] = $arrrow[2];
                }else if(count($fields)==7){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]] = $arrrow[2];
                }else if(count($fields)==8){
                    $mappings[][$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[1];
                    if($arrrow[3]=='yes'){
                        $mappings_custom_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                    }
                    $mappings_fields[$fields[1]][$fields[2]][$fields[3]][$fields[4]][$fields[5]][$fields[6]][$fields[7]] = $arrrow[2];
                }

            }
            $i++;
        }


        //$total_line_items = count($order_line_detail);
        $field_value = $OrderStatusID = $Vendor = $product_name = $ean = $sku = $gtin = $mpn = $upc = $qty = $unit_price = "";

        $total_line_items = $line_sequence = 1;
        $total = $ct_structure = 0;

        $default_info = "No Information";



        $customer_id = "";//@$customer_detail->api_customer_id;
        $customer_name = "";//@$customer_detail->customer_name;
        $customer_phone = "";//@$customer_detail->phone;
        $customer_email = "";//@$customer_detail->email;
        $customer_fax = "";//@$customer_detail->fax;

        $id = $inventory_detail->id;


        $OrderStatusID = "";

        $InventoryDate = @$product_detail->api_updated_at ? date('Y-m-d',strtotime($product_detail->api_updated_at)) : '';
        $InventoryTime = @$product_detail->api_updated_at ? date('H:i:s',strtotime($product_detail->api_updated_at)) : '';
        if($InventoryDate==''){
            $InventoryDate = @$product_detail->updated_at ? date('Y-m-d',strtotime($product_detail->updated_at)) : '';
            $InventoryTime = @$product_detail->updated_at ? date('H:i:s',strtotime($product_detail->updated_at)) : '';
        }


        // Location Mapping For Inventory
        $DefaultInventoryWarehouseLocation = NULL;
        $DefaultInventoryWarehouseId = NULL;
        $DefaultWarehouseId = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id','name']);

        if ($DefaultWarehouseId) {
            //$DefaultInventoryWarehouseLocation = $this->GetWarehouseDefaultLocation($user_integration_id, $DefaultWarehouseId->api_id);
            $DefaultInventoryWarehouseId = $DefaultWarehouseId->api_id;
        }


        $defaultLocation = null;
        $InventoryWarehouseId = null;
        $warehouseId = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id'], 'regular', $inventory_detail->api_warehouse_id);
        if ($warehouseId) {
            //$defaultLocation = $this->GetWarehouseDefaultLocation($user_integration_id, $warehouseId->api_id);
            $InventoryWarehouseId = $warehouseId->api_id;
        } else {
            //if ($DefaultInventoryWarehouseLocation) {
            //    $defaultLocation = $DefaultInventoryWarehouseLocation;
                $InventoryWarehouseId = $DefaultInventoryWarehouseId;
            //}
        }




        foreach($mappings as $row){

            if(isset($row['Header']['HeaderReport']['TradingPartnerId']) && $trading_partner_id!=''){
                $data['Header']['HeaderReport']['TradingPartnerId'] = $trading_partner_id;
            }

            if(isset($row['Header']['HeaderReport']['DocumentId'])){
                $data['Header']['HeaderReport']['DocumentId'] = time();
            }

            if(isset($row['Header']['HeaderReport']['TsetPurposeCode'])){
                $data['Header']['HeaderReport']['TsetPurposeCode'] = @$OrderStatusID ? $OrderStatusID : $row['Header']['HeaderReport']['TsetPurposeCode'];
            }

            if(isset($row['Header']['HeaderReport']['ReportTypeCode'])){
               // $data['Header']['HeaderReport']['ReportTypeCode'] = "";
            }

            if(isset($row['Header']['HeaderReport']['InventoryDate']) && $InventoryDate!=''){
                $data['Header']['HeaderReport']['InventoryDate'] = $InventoryDate;
            }

            if(isset($row['Header']['HeaderReport']['InventoryTime']) && $InventoryDate!=''){
                $data['Header']['HeaderReport']['InventoryTime'] = $InventoryTime;
            }

            if(isset($row['Header']['HeaderReport']['Vendor']) && $customer_id!=''){
                $data['Header']['HeaderReport']['Vendor'] = $customer_id;
            }

            if(isset($row['Header']['HeaderReport']['ActionCode'])){
                $data['Header']['HeaderReport']['ActionCode'] = $row['Header']['HeaderReport']['ActionCode'];
            }




            /***********Dates fields************/
            if(isset($row['Header']['Dates']['DateTimeQualifier'])){
                $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                $field_value = $row['Header']['Dates']['DateTimeQualifier'];
                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Dates'],'DateTimeQualifier');
                $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);
                $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] = $returndata['ct_key'];
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Dates']['DateTimeQualifier'] ? $ct_qualifiers['Header']['Dates']['DateTimeQualifier'] : 0;

            if(isset($row['Header']['Dates']['Date'])){
                $data['Header']['Dates'] = @$data['Header']['Dates'] ? $data['Header']['Dates'] : array();
                $field_value = "";
                $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Dates'],'Date');
                $data['Header']['Dates'] = array_replace_recursive(@$data['Header']['Dates'],$returndata['fielddata']);

            }

            /***********End Dates fields************/

            /***********Contacts fields************/
            if(isset($row['Header']['Contacts']['ContactTypeCode'])){

                $field_value = $row['Header']['Contacts']['ContactTypeCode'];
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Contacts'],'ContactTypeCode');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] = $returndata['ct_key'];
                }
            }



            $total_qualifiers = @$ct_qualifiers['Header']['Contacts']['ContactTypeCode'] ? $ct_qualifiers['Header']['Contacts']['ContactTypeCode'] : 0;

            if(isset($row['Header']['Contacts']['ContactName'])){
                $field_value = @$row['Header']['Contacts']['ContactName'] ? $row['Header']['Contacts']['ContactName'] : $customer_name;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'ContactName');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryPhone'])){
                $field_value = @$row['Header']['Contacts']['PrimaryPhone'] ? $row['Header']['Contacts']['PrimaryPhone'] : $customer_phone;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryPhone');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Contacts']['PrimaryEmail'])){
                $field_value = @$row['Header']['Contacts']['PrimaryEmail'] ? $row['Header']['Contacts']['PrimaryEmail'] : $customer_email;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryEmail');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            if(isset($row['Header']['Contacts']['PrimaryFax'])){
                $field_value = @$row['Header']['Contacts']['PrimaryFax'] ? $row['Header']['Contacts']['PrimaryFax'] : $customer_fax;
                if($field_value!=''){
                    $data['Header']['Contacts'] = @$data['Header']['Contacts'] ? $data['Header']['Contacts'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Contacts'],'PrimaryFax');
                    $data['Header']['Contacts'] = array_replace_recursive(@$data['Header']['Contacts'],$returndata['fielddata']);
                }

            }

            /***********End Contacts fields************/



            /***********References fields************/

            if(isset($row['Header']['References']['ReferenceQual'])){
                $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();
                $field_value = $row['Header']['References']['ReferenceQual'];
                $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['References'],'ReferenceQual');
                $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                $ct_qualifiers['Header']['References']['ReferenceQual'] = $returndata['ct_key'];
            }

            $total_qualifiers = @$ct_qualifiers['Header']['References']['ReferenceQual'] ? $ct_qualifiers['Header']['References']['ReferenceQual'] : 0;

            if(isset($row['Header']['References']['ReferenceID'])){
                $custom_field_name = "";
                if(isset($mappings_custom_fields['Header']['References']['ReferenceID'])){
                    $custom_field_name = $mappings_custom_fields['Header']['References']['ReferenceID'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }

                $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                if($field_value!=''){
                    $data['Header']['References'] = @$data['Header']['References'] ? $data['Header']['References'] : array();

                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['References'],'ReferenceID');
                    $data['Header']['References'] = array_replace_recursive(@$data['Header']['References'],$returndata['fielddata']);
                }
            }


            /***********End References fields************/

            /***********Notes fields************/

            if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){
                // when custom field is having value
                $custom_field_name = "";
                if(isset($mappings_custom_fields['Header']['Notes']['NoteCode'])){

                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['NoteCode'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }
                $field_value = @$custom_field_value;
                if($field_value!=''){

                    $data['Header']['Notes'][0]['NoteCode'] = $field_value;
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = 1;
                }

            }else if(isset($row['Header']['Notes']['NoteCode'])){
                $field_value = $row['Header']['Notes']['NoteCode'];
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Notes'],'NoteCode');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Notes']['NoteCode'] = $returndata['ct_key'];
                }
            }

            $total_qualifiers = @$ct_qualifiers['Header']['Notes']['NoteCode'] ? $ct_qualifiers['Header']['Notes']['NoteCode'] : 0;

            if(isset($row['Header']['Notes']['Note'])){
                $custom_field_value = "";
                if(isset($mappings_custom_fields['Header']['Notes']['Note'])){
                    $custom_field_name = $mappings_custom_fields['Header']['Notes']['Note'];
                    $custom_field_value = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetCustomFieldValues($user_id,$user_integration_id,$source_platform_id,$sync_object_id,$custom_field_name,$id);
                }
                $field_value = @$custom_field_value ? $custom_field_value : $default_info;
                if($field_value!=''){
                    $data['Header']['Notes'] = @$data['Header']['Notes'] ? $data['Header']['Notes'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Notes'],'Note');
                    $data['Header']['Notes'] = array_replace_recursive(@$data['Header']['Notes'],$returndata['fielddata']);
                }
            }

            /***********End Notes fields************/

            /***********Address fields************/
            if(isset($row['Header']['Address']['AddressTypeCode'])){
                $field_value = $row['Header']['Address']['AddressTypeCode'];
                if($field_value!=''){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();

                    $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Header']['Address'],'AddressTypeCode');
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                    $ct_qualifiers['Header']['Address']['AddressTypeCode'] = $returndata['ct_key'];
                }

            }

            $total_qualifiers = @$ct_qualifiers['Header']['Address']['AddressTypeCode'] ? $ct_qualifiers['Header']['Address']['AddressTypeCode'] : 0;

            if(isset($row['Header']['Address']['LocationCodeQualifier'])){
                $field_value = $row['Header']['Address']['LocationCodeQualifier'];
                if($field_value!=''){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'LocationCodeQualifier');
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            if(isset($row['Header']['Address']['AddressLocationNumber'])){
                $field_value = @$InventoryWarehouseId ? $InventoryWarehouseId : $row['Header']['Address']['AddressLocationNumber'];
                if($field_value!=''){
                    $data['Header']['Address'] = @$data['Header']['Address'] ? $data['Header']['Address'] : array();
                    $returndata =  $this->GetStructuredMappingData($total_qualifiers,0,$field_value,$row['Header']['Address'],'AddressLocationNumber');
                    $data['Header']['Address'] = array_replace_recursive(@$data['Header']['Address'],$returndata['fielddata']);
                }
            }

            /***********End Address fields************/


            if(isset($row['Structure']['LineItem'])){

                $line_sequence = 1;

                //foreach($inventory_detail as $rowline){

                    $product_name = @$product_detail->product_name;
                    $ean = @$product_detail->ean;
                    $sku = @$product_detail->sku;
                    $gtin = @$product_detail->gtin;
                    $mpn = @$product_detail->mpn;
                    $upc = @$product_detail->upc;
                    $price = @$product_detail->price;
                    $description = @$product_detail->description;

                    $qty = @$inventory_detail->quantity;

                    /*
                    $product_name = $rowline->product_name;
                    $ean = $rowline->ean;
                    $sku = $rowline->sku;
                    $gtin = $rowline->gtin;
                    $mpn = $rowline->mpn;
                    $upc = $rowline->upc;
                    $qty = $rowline->qty;
                    $unit_price = $rowline->unit_price;
                    $total = $rowline->total;
                    $description = $rowline->description;
                    */
                    $k = $line_sequence - 1;



                    $total_qualifiers = 0;

                    if(isset($row['Structure']['LineItem']['InventoryLine']['LineSequenceNumber'])){
                        $field_value = $line_sequence;
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['LineSequenceNumber'] = $field_value;
                        }
                    }

                    if(isset($row['Structure']['LineItem']['InventoryLine']['BuyerPartNumber'])){
                        $field_value = @${$mappings_fields['Structure']['LineItem']['InventoryLine']['BuyerPartNumber']};
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                           $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['BuyerPartNumber'] = $field_value;
                        }
                    }

                    if(isset($row['Structure']['LineItem']['InventoryLine']['VendorPartNumber'])){
                        $field_value = @${$mappings_fields['Structure']['LineItem']['InventoryLine']['VendorPartNumber']};
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['VendorPartNumber'] = $field_value;
                        }
                    }

                    if(isset($row['Structure']['LineItem']['InventoryLine']['ConsumerPackageCode'])){
                        $field_value = @${$mappings_fields['Structure']['LineItem']['InventoryLine']['ConsumerPackageCode']};
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['ConsumerPackageCode'] = $field_value;
                        }
                    }

                    if(isset($row['Structure']['LineItem']['InventoryLine']['EAN'])){
                        $field_value = @${$mappings_fields['Structure']['LineItem']['InventoryLine']['EAN']};
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['EAN'] = $field_value;
                        }
                    }

                    if(isset($row['Structure']['LineItem']['InventoryLine']['GTIN'])){
                        $field_value = @${$mappings_fields['Structure']['LineItem']['InventoryLine']['GTIN']};
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['GTIN'] = $field_value;
                        }
                    }

                    if(isset($row['Structure']['LineItem']['InventoryLine']['UPCCaseCode'])){
                        $field_value = @${$mappings_fields['Structure']['LineItem']['InventoryLine']['UPCCaseCode']};
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['UPCCaseCode'] = $field_value;
                        }
                    }



                    if(isset($row['Structure']['LineItem']['InventoryLine']['PurchasePrice'])){
                        $field_value = $price;
                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine'] : array();
                            $data['Structure'][$ct_structure]['LineItem'][$k]['InventoryLine']['PurchasePrice'] = $field_value;
                        }
                    }



                    if(isset($row['Structure']['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'])){
                        $data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] : array();
                        $field_value = $row['Structure']['LineItem']['ProductOrItemDescription']['ProductCharacteristicCode'];
                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Structure']['LineItem']['ProductOrItemDescription'],'ProductCharacteristicCode');
                        $data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] = array_replace_recursive(@$data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'],$returndata['fielddata']);
                        $ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] = $returndata['ct_key'];
                    }

                    $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] ? $ct_qualifiers['LineItem'][$k]['ProductOrItemDescription']['ProductCharacteristicCode'] : 0;

                    if(isset($row['Structure']['LineItem']['ProductOrItemDescription']['ProductDescription'])){

                        $field_value = $description ? $description : $default_info;

                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] : array();

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Structure']['LineItem']['ProductOrItemDescription'],'ProductDescription');
                            $data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'] = array_replace_recursive(@$data['Structure'][$ct_structure]['LineItem'][$k]['ProductOrItemDescription'],$returndata['fielddata']);
                        }
                    }



                    if(isset($row['Structure']['LineItem']['QuantitiesSchedulesLocations']['QuantityQualifier'])){
                        $data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] : array();
                        $field_value = $row['Structure']['LineItem']['QuantitiesSchedulesLocations']['QuantityQualifier'];
                        $returndata =  $this->GetStructuredMappingData(0,1,$field_value,$row['Structure']['LineItem']['QuantitiesSchedulesLocations'],'QuantityQualifier');
                        $data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] = array_replace_recursive(@$data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'],$returndata['fielddata']);
                        $ct_qualifiers['LineItem'][$k]['QuantitiesSchedulesLocations']['QuantityQualifier'] = $returndata['ct_key'];
                    }

                    $total_qualifiers_child = @$ct_qualifiers['LineItem'][$k]['QuantitiesSchedulesLocations']['QuantityQualifier'] ? $ct_qualifiers['LineItem'][$k]['QuantitiesSchedulesLocations']['QuantityQualifier'] : 0;

                    if(isset($row['Structure']['LineItem']['QuantitiesSchedulesLocations']['TotalQty'])){

                        $field_value = $qty;

                        if($field_value!=''){
                            $data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] = @$data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] ? $data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] : array();

                            $returndata =  $this->GetStructuredMappingData($total_qualifiers_child,0,$field_value,$row['Structure']['LineItem']['QuantitiesSchedulesLocations'],'TotalQty');
                            $data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'] = array_replace_recursive(@$data['Structure'][$ct_structure]['LineItem'][$k]['QuantitiesSchedulesLocations'],$returndata['fielddata']);
                        }
                    }




                    $line_sequence++;

                //}




            }

            if(isset($row['Summary']['TotalLineItemNumber'])){
                $data['Summary']['TotalLineItemNumber'] = $line_sequence - 1;

            }


        }

        $postdata = json_encode($data,true);

        return $postdata;


    }







}
