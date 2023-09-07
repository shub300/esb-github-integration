<?php

namespace App\Http\Controllers\Brandwise;

use DB;
use Auth;
use Mail;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use Hamcrest\Arrays\IsArray;
use App\CountryCodes;
use Illuminate\Database\Eloquent\Model;

class BrandwiseUtility extends Model
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
        $this->CountryCodes = new CountryCodes();
        $this->map = new FieldMappingHelper();
        $this->my_platform = 'brandwise';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    public function orderData($user_id, $user_integration_id, $SalesOrder, $find_Ship_Date_Record, $find_Batch_ID_Record, $platform_workflow_rule_id)
    {
        try {
            $customer_email = $nominalcode = $pricelis = '';
            $bill_currency = $ship_currency = $sync_status = '';
            $platform_order_id = null;
            $company_name = null;
            $bill_Email_address = null;
            $platform_customer_id = null;
            $platform_customer_emp_id = null;
            $Customerompany = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "customer_company", ['api_id']);
            if (isset($Customerompany->api_id) && $Customerompany->api_id == 'bill_to_name') {
                if (isset($SalesOrder['BillToName']) && !is_array($SalesOrder['BillToName'])) {
                    $company_name = $SalesOrder['BillToName'];
                }
            } elseif (isset($Customerompany->api_id) && $Customerompany->api_id == 'ship_to_name') {
                if (isset($SalesOrder['ShipToName']) && !is_array($SalesOrder['ShipToName'])) {
                    $company_name = $SalesOrder['ShipToName'];
                }
            }
            $arr_customer = array('email3'=>null);
            $arr_customer['user_id'] = $user_id;
            $arr_customer['platform_id'] = $this->my_platform_id;
            $arr_customer['user_integration_id'] = $user_integration_id;
           // Account number refers to destiantion platform customer primary id
            if (isset($SalesOrder['AccountNumber']) && !is_array($SalesOrder['AccountNumber'])) {
                $arr_customer['api_customer_id'] = trim($SalesOrder['AccountNumber']);
            }

            if (isset($SalesOrder['BuyerName']) && !is_array($SalesOrder['BuyerName'])) {
                $name_array = explode(' ', $SalesOrder['BuyerName'], 2);
                $arr_customer['first_name'] = (isset($name_array[0])) ? $name_array[0] : ' ';
                $arr_customer['last_name'] = (isset($name_array[1])) ? $name_array[1] : ' ';
                $arr_customer['customer_name'] = $SalesOrder['BuyerName'];
            }

            $arr_customer['sync_status'] = 'Ready';
            if (isset($SalesOrder['ContactPhoneNumber']) && !is_array($SalesOrder['ContactPhoneNumber'])) {
                $arr_customer['phone'] = $SalesOrder['ContactPhoneNumber'];
            }
            if (isset($SalesOrder['CustomerEmailAddress']) && !is_array($SalesOrder['CustomerEmailAddress'])) {
                $customer_email = $SalesOrder['CustomerEmailAddress'];
                $arr_customer['email'] = $SalesOrder['CustomerEmailAddress'];
            }

            $arr_customer['company_name'] = $company_name;

            // Add Email to email3 as a tertiary
            $email3Tertiary = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "allow_email_in_tertiary", ['api_id']);
            if($email3Tertiary && $email3Tertiary->api_id=='Yes' && $customer_email){
                $arr_customer['email3'] = $customer_email;
            }

            $customer_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'email' => $customer_email], ['id']);
            if ($customer_details) {
                $platform_customer_id = $customer_details->id;
                $this->mobj->makeUpdate('platform_customer', $arr_customer, ['id' => $customer_details->id]);
            } else {
                $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
            }

            //Store SalesRepName in customer as employee
            if (isset($SalesOrder['SalesRepName']) && !is_array($SalesOrder['SalesRepName'])) {
                $arr_customer_srep = array();
                $arr_customer_srep['user_id'] = $user_id;
                $arr_customer_srep['platform_id'] = $this->my_platform_id;
                $arr_customer_srep['user_integration_id'] = $user_integration_id;
                $arr_customer_srep['sync_status'] = 'Ready';
                $arr_customer_srep['customer_name'] = $SalesOrder['SalesRepName'];
                $arr_customer_srep['type'] = 'Employee';

                $srep_details = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'customer_name' => $SalesOrder['SalesRepName'], 'type' => 'Employee'], ['id']);
                if ($srep_details) {
                    $platform_customer_emp_id = $srep_details->id;
                    $this->mobj->makeUpdate('platform_customer', $arr_customer_srep, ['id' => $srep_details->id]);
                } else {
                    $platform_customer_emp_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer_srep);
                }
            }



            //orders data
            $arr_order = array();
            $arr_order['user_id'] = $user_id;
            $arr_order['platform_id'] = $this->my_platform_id;
            $arr_order['user_integration_id'] = $user_integration_id;
            $arr_order['order_type'] = "SO";
            $arr_order['platform_customer_id'] = $platform_customer_id;
            $arr_order['platform_customer_emp_id'] = $platform_customer_emp_id;
            if (isset($SalesOrder['ID'])) {
                $arr_order['api_order_id'] = $SalesOrder['ID'];
            }
            // if (isset($SalesOrder['BatchID']) && !is_array($SalesOrder['BatchID'])) {
            //     $arr_order['order_number'] = $SalesOrder['BatchID'];
            // }
            if (isset($SalesOrder['RetailerPurchaseOrderNumber']) && !is_array($SalesOrder['RetailerPurchaseOrderNumber'])) {
                $arr_order['order_number'] = $SalesOrder['RetailerPurchaseOrderNumber'];
            }
            if (isset($SalesOrder['OrderDate']) && !is_array($SalesOrder['OrderDate'])) {
                $arr_order['order_date'] = date('Y-m-d H:i:s', strtotime($SalesOrder['OrderDate']));
            }
            if (isset($SalesOrder['NetOrderTotal']) && !is_array($SalesOrder['NetOrderTotal'])) {
                $arr_order['total_amount'] =  $SalesOrder['NetOrderTotal'];
            }
            if (isset($SalesOrder['ShippingMethodID']) && !is_array($SalesOrder['ShippingMethodID'])) {
                $arr_order['carrier_code'] = $SalesOrder['ShippingMethodID'];
            }

            if (isset($SalesOrder['RequiredShipDate']) && !is_array($SalesOrder['RequiredShipDate'])) {
                $arr_order['delivery_date'] = $SalesOrder['RequiredShipDate'];
            }

            $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'api_order_id' => $SalesOrder['ID']], ['id', 'sync_status']);

            if ($order_details) {
                $platform_order_id = $order_details->id;
                $sync_status = $order_details->sync_status;
                if ($order_details->sync_status != 'Synced') {
                    $arr_order['order_updated_at'] = date('Y-m-d H:i:s');
                    $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
                }
            } else {
                $arr_order['sync_status'] = 'Ready';
                $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
            }

            /*    bill_address...*/
            $arr_order_bill_address = array();
            $arr_order_bill_address['platform_order_id'] = $platform_order_id;
            $arr_order_bill_address['address_type'] = 'Billing';
            if (isset($SalesOrder['BillToName']) && !is_array($SalesOrder['BillToName'])) {
                $name_array = explode(' ', $SalesOrder['BillToName'], 2);
                $arr_order_bill_address['firstname'] = (isset($name_array[0])) ? $name_array[0] : ' ';
                $arr_order_bill_address['lastname'] = (isset($name_array[1])) ? $name_array[1] : ' ';
                $arr_order_bill_address['address_name'] = $SalesOrder['BillToName'];
            }
            if (isset($SalesOrder['BillToAddressLine1']) && !is_array($SalesOrder['BillToAddressLine1'])) {
                $arr_order_bill_address['address1'] = $SalesOrder['BillToAddressLine1'];
            }
            if (isset($SalesOrder['BillToAddressLine2']) && !is_array($SalesOrder['BillToAddressLine2'])) {
                $arr_order_bill_address['address2'] = $SalesOrder['BillToAddressLine2'];
            }
            if (isset($SalesOrder['BillToCity']) && !is_array($SalesOrder['BillToCity'])) {
                $arr_order_bill_address['city'] = $SalesOrder['BillToCity'];
            }
            if (isset($SalesOrder['BillToState']) && !is_array($SalesOrder['BillToState'])) {
                $arr_order_bill_address['state'] = $SalesOrder['BillToState'];
            }
            if (isset($SalesOrder['BillToPostalCode']) && !is_array($SalesOrder['BillToPostalCode'])) {
                $arr_order_bill_address['postal_code'] = $SalesOrder['BillToPostalCode'];
            }
            if (isset($SalesOrder['BillToCountry']) && !is_array($SalesOrder['BillToCountry'])) {
                $arr_order_bill_address['country'] = $this->CountryCodes->getCountryIsoFromName($SalesOrder['BillToCountry']);
                if (strtolower($SalesOrder['BillToCountry']) == 'canada') {
                    $bill_currency = 'CAD';
                } else {
                    $bill_currency = 'USD';
                }
            }

            // If country is not set and empty then assign US default
            $arr_order_bill_address['country'] = (!isset($arr_order_bill_address['country']))?'US':(trim($arr_order_bill_address['country'])?$arr_order_bill_address['country']:'US');

            $arr_order_bill_address['company'] = $company_name;
            if (isset($SalesOrder['BillToPhoneNumber']) && !is_array($SalesOrder['BillToPhoneNumber'])) {
                $arr_order_bill_address['phone_number'] = $SalesOrder['BillToPhoneNumber'];
            }
            if (isset($SalesOrder['CustomerEmailAddress']) && !is_array($SalesOrder['CustomerEmailAddress'])) {
                $arr_order_bill_address['email'] = $SalesOrder['CustomerEmailAddress'];
                $bill_Email_address = $SalesOrder['CustomerEmailAddress'];
            }
            $bill_ct_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing'], ['id']);

            if ($bill_ct_address) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['id' => $bill_ct_address->id]);
            } else {
                $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
            }
            /*    bill_address...*/

            /*    ShipTo address...*/
            $arr_order_address = array();
            $arr_order_address['platform_order_id'] = $platform_order_id;
            $arr_order_address['address_type'] = 'Shipping';
            if (isset($SalesOrder['ShipToName']) && !is_array($SalesOrder['ShipToName'])) {
                $name_array = explode(' ', $SalesOrder['ShipToName'], 2);
                $arr_order_address['firstname'] = (isset($name_array[0])) ? $name_array[0] : ' ';
                $arr_order_address['lastname'] = (isset($name_array[1])) ? $name_array[1] : ' ';
                $arr_order_address['address_name'] = $SalesOrder['ShipToName'];
            }
            if (isset($SalesOrder['ShipToAddressLine1']) && !is_array($SalesOrder['ShipToAddressLine1'])) {
                $arr_order_address['address1'] = $SalesOrder['ShipToAddressLine1'];
            }
            if (isset($SalesOrder['ShipToAddressLine2']) && !is_array($SalesOrder['ShipToAddressLine2'])) {
                $arr_order_address['address2'] = $SalesOrder['ShipToAddressLine2'];
            }
            if (isset($SalesOrder['ShipToCity']) && !is_array($SalesOrder['ShipToCity'])) {
                $arr_order_address['city'] = $SalesOrder['ShipToCity'];
            }
            if (isset($SalesOrder['ShipToState']) && !is_array($SalesOrder['ShipToState'])) {
                $arr_order_address['state'] = $SalesOrder['ShipToState'];
            }
            if (isset($SalesOrder['ShipToPostalCode']) && !is_array($SalesOrder['ShipToPostalCode'])) {
                $arr_order_address['postal_code'] = $SalesOrder['ShipToPostalCode'];
            }
            if (isset($SalesOrder['ShipToCountry']) && !is_array($SalesOrder['ShipToCountry'])) {
                $arr_order_address['country'] = $this->CountryCodes->getCountryIsoFromName($SalesOrder['ShipToCountry']);
                if (strtolower($SalesOrder['ShipToCountry']) == 'canada') {
                    $ship_currency = 'CAD';
                } else {
                    $ship_currency = 'USD';
                }
            }

            // If country is not set and empty then assign US default
            $arr_order_address['country'] = (!isset($arr_order_address['country']))?'US':(trim($arr_order_address['country'])?$arr_order_address['country']:'US');

            $arr_order_address['company'] = $company_name;

            $currency = ($ship_currency) ? $ship_currency : $bill_currency; // if ship dont have currency
            if ($currency == 'CAD') {
                $nominalcode = 'nominalcode-cad';
                $pricelis = 'currency-cad';
            } else {
                $nominalcode = 'nominalcode-usd';
                $pricelis = 'currency-usd';
            }
            if ($platform_order_id) { // update  currency
                if ($sync_status != 'Synced') {
                    $this->mobj->makeUpdate('platform_order', ['currency' => $currency, 'api_pricelist_id' => $pricelis], ['id' => $platform_order_id]);
                }
            }

            if (isset($SalesOrder['ShipToPhoneNumber']) && !is_array($SalesOrder['ShipToPhoneNumber'])) {
                $arr_order_address['phone_number'] = $SalesOrder['ShipToPhoneNumber'];
            }
            if (isset($SalesOrder['ShippingEmailAddress']) && !is_array($SalesOrder['ShippingEmailAddress'])) {
                $arr_order_address['email'] = $SalesOrder['ShippingEmailAddress'];
            } else {
                $arr_order_address['email'] = $bill_Email_address;
            }
            $ct_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping'], ['id']);
            if ($ct_address) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['id' => $ct_address->id]);
            } else {
                $this->mobj->makeInsert('platform_order_address', $arr_order_address);
            }
            /*    ShipTo address...*/

            // if (isset($SalesOrder['RequiredShipDate']) && !is_array($SalesOrder['RequiredShipDate'])) {
            //     if ($find_Ship_Date_Record) {
            //         $fields = array(
            //             'platform_field_id' => $find_Ship_Date_Record->id,
            //             'user_integration_id' => $user_integration_id,
            //             'platform_id' => $this->my_platform_id,
            //             'field_value' => $SalesOrder['RequiredShipDate'],
            //             'record_id' => $platform_order_id
            //         );
            //         $platform_custom_field = $this->mobj->getFirstResultByConditions('platform_custom_field_values', ['record_id' => $platform_order_id, 'user_integration_id' => $user_integration_id, 'platform_field_id' => $find_Ship_Date_Record->id], ['id']);
            //         if ($platform_custom_field) {
            //             //$this->mobj->makeUpdate('platform_custom_field_values', $fields, ['id' => $platform_custom_field->id]);
            //         } else {
            //             $this->mobj->makeInsert('platform_custom_field_values', $fields);
            //         }
            //     }
            // }

            if (isset($SalesOrder['BatchID']) && !is_array($SalesOrder['BatchID'])) {
                if ($find_Batch_ID_Record) {
                    $fields_arr = array(
                        'platform_field_id' => $find_Batch_ID_Record->id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->my_platform_id,
                        'field_value' => $SalesOrder['BatchID'],
                        'record_id' => $platform_order_id
                    );
                    $platform_custom_field_data = $this->mobj->getFirstResultByConditions('platform_custom_field_values', ['record_id' => $platform_order_id, 'user_integration_id' => $user_integration_id, 'platform_field_id' => $find_Batch_ID_Record->id,], ['id']);
                    if ($platform_custom_field_data) {
                        //$this->mobj->makeUpdate('platform_custom_field_values', $fields_arr, ['id' => $platform_custom_field_data->id]);
                    } else {
                        $this->mobj->makeInsert('platform_custom_field_values', $fields_arr);
                    }
                }
            }


            /* .....LineItem ...*/
            if (!isset($SalesOrder['LineItem']['LineItemNumber'])) {
                foreach ($SalesOrder['LineItem'] as $LineItem) {
                    $this->SaveLineItem($platform_order_id, $LineItem, $nominalcode);
                }
            } else if (isset($SalesOrder['LineItem']['LineItemNumber'])) {
                $this->SaveLineItem($platform_order_id, $SalesOrder['LineItem'], $nominalcode);
            }
            /* .....LineItem ...*/
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }



    public function SaveLineItem($platform_order_id, $LineItem, $nominalcode)
    {
        try {
            $arr_order_line = array();
            $arr_order_line['platform_order_id'] = $platform_order_id;
            $arr_order_line['api_code'] = $nominalcode;
            //$arr_order_line['api_product_id'] = @$lineitem['partNumber'];
            if (isset($LineItem['LineItemNumber']) && !is_array($LineItem['LineItemNumber'])) {
                $arr_order_line['api_order_line_id'] = $LineItem['LineItemNumber'];
            }
            if (isset($LineItem['ProductSKU']) && !is_array($LineItem['ProductSKU'])) {
                $arr_order_line['sku'] = $LineItem['ProductSKU'];
            }
            if (isset($LineItem['ProductUPC']) && !is_array($LineItem['ProductUPC'])) {
                $arr_order_line['upc'] = $LineItem['ProductUPC'];
            }
            if (isset($LineItem['ProductName']) && !is_array($LineItem['ProductName'])) {
                $arr_order_line['product_name'] = $LineItem['ProductName'];
            }
            if (isset($LineItem['OrderQuantity']) && !is_array($LineItem['OrderQuantity'])) {
                $arr_order_line['qty'] = $LineItem['OrderQuantity'];
            }
            if (isset($LineItem['UnitPrice']) && !is_array($LineItem['UnitPrice'])) {
                $arr_order_line['price'] = $LineItem['UnitPrice'];
            }

            if (isset($LineItem['ExtendedPrice']) && !is_array($LineItem['ExtendedPrice'])) {
                $arr_order_line['total'] = $LineItem['ExtendedPrice'];
            }
            if (isset($LineItem['ExtendedDiscountedPrice']) && !is_array($LineItem['ExtendedDiscountedPrice'])) {
                $arr_order_line['subtotal'] = $LineItem['ExtendedDiscountedPrice'];
            }

            $ct_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => $LineItem['ProductSKU'], 'api_order_line_id' => $LineItem['LineItemNumber']], ['id']);
            if ($ct_order_line) {
                $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['id' => $ct_order_line->id]);
            } else {
                $this->mobj->makeInsert('platform_order_line', $arr_order_line);
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
