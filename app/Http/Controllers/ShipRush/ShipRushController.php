<?php

namespace App\Http\Controllers\ShipRush;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

use App\Helper\MainModel;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;

use App\Helper\Api\ShipRushApi;
use App\Models\PlatformAccount;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformProduct;
use App\Models\PlatformOrderShipment;
use Lang;

class ShipRushController extends ShipRushApi
{
    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'shiprush';

    public function __construct() {
        $this->connectionHelper = new ConnectionHelper();
        $this->mainModel = new MainModel();
        $this->logger = new Logger();
        $this->fieldMapHelper = new FieldMappingHelper();
        // Set the platform ID
        $this->platformId = $this->connectionHelper->getPlatformIdByName( self::PLATFORMNAME );
    }

    /**
     * Auth function return the view page of authentication
     *
     * @param $request Request class
     */
    public function InitiateShipRushAuth( Request $request ) {
        $platform = self::PLATFORMNAME;
        return view( "pages.apiauth.auth_shiprush", compact( 'platform' ) );
    }

    /**
     * Auth function to connect to the platform with response to the front
     *
     * @param $request Request class
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function ConnectShipRush( Request $request ) {
        $response = ['status_code' => 0]; // array for return response with status_code default to 0 (false)

        if($this->mainModel->checkHtmlTags( $request->all() ) ){
            $response['status_text'] = Lang::get('tags.validate');
            return $response;
        }
        
        try{
            $validator = Validator::make( $request->all(), [
                'account_name' => 'required',
                'user_token' => 'required',
                'developer_token' => 'required',
                'shiprush_version' => 'required'
            ], [
                'account_name.required' => 'Account Name is required.',
                'user_token.required' => 'User Token is required.',
                'developer_token.required' => 'Developer Token is required.',
                'shiprush_version.required' => 'ShipRush Version is required.'
            ] );
            if( $validator->fails() ) {
                $statustext = array_values( json_decode( $validator->messages()->toJson(), true ) )[0][0];
            } else {
                $validated = array_map( function( $val ) {
                    return htmlspecialchars( $val );
                }, $validator->validated() );
                $validated = (Object) $validated;
                // Set and Decrypt the values for security measures
                $account_name = $validated->account_name;
                $user_token = $this->mainModel->encrypt_decrypt( $validated->user_token );
                $developer_token = $this->mainModel->encrypt_decrypt( $validated->developer_token );
                $shiprush_version = $validated->shiprush_version;

                // Get Current User Id
                $user_data =  Session::get('user_data');
                $user_id =  $user_data['id'];

                // Check for the account
                $account = PlatformAccount::select( 'id' )->where( [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'app_id' => $user_token
                ] )->count();
                if( $account === 0 ) {
                    $isConnected = static::checkAuthCredential( $validated );
                    if( $isConnected === true ) {
                        // Add the given data
                        $newAccount = PlatformAccount::create( [
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'account_name' => $account_name,
                            'app_id' => $user_token,
                            'app_secret' => $developer_token,
                            'marketplace_id' => $shiprush_version
                        ] );
                        if( $newAccount->id ) {
                            $response['status_code'] = true;
                            $statustext = 'Account Connected.';
                        } else {
                            $statustext = 'Account not created! Please try again.';
                        }
                    } else {
                        if( $isConnected === false ) {
                            $statustext = 'Please check for the given credential.';
                        } else {
                            $statustext = $isConnected;
                        }
                    }
                } else {
                    $statustext = "Account already connected.";
                }
            }
            $response['status_text'] = $statustext;
        }catch( \Exception $e ) {
            // $response['status_text'] = 'There is some issue adding account.';
            $response['status_text'] = $e->getMessage();
        }
        return $response;
    }

    public function SyncShipmentForOrders( $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id ) {
        $returnstatus = true;
        try{
            $this->user_integration_id = $user_integration_id; // set the user_integration_id for whole class
            $this->platform_workflow_rule_id = $platform_workflow_rule_id; // set the user_integration_id for whole class
            $account = $this->mainModel->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId ); // get the account information for the integration
            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'account found: ' . print_r($account, true));
            if( $account ) {
                $this->source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
                $object_id = $this->connectionHelper->getObjectId('sales_order');
                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'source_platform_id: ' . print_r($this->source_platform_id, true) . " | object_id: ". print_r($object_id, true) );
                if( $this->source_platform_id && $object_id ) {
                    $limit = 50;
                    $platform_orders = PlatformOrder::where( [
                        'platform_id' => $this->source_platform_id,
                        'user_integration_id' => $user_integration_id,
                        'sync_status' => 'Ready',
                        'is_deleted' => 0,//This is basically accept only not deleted orders
                    ] );
                    if( $record_id ) {
                        $platform_orders = $platform_orders->where( ['platform_order.id'=>$record_id, 'platform_order.sync_status'=>'Failed'] );
                    }
                    $platform_orders = $platform_orders->limit( $limit )->orderBy('platform_order.updated_at','ASC')->get();
                   //dd( $platform_orders );
                    if( $platform_orders ) { // check if there are order to sync
                        $post_data = '';
                        foreach( $platform_orders as $order ) { // loop the orders
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'platform_order: ' . print_r($order, true));
                            $post_data = '<?xml version = "1.0"?><Request><ShipTransaction>'; // Request Tag [start]

                            $post_data .= '<Order>'; // Order Tag [start]
                            $order_detail = $this->CreateOrderDetailRequest( $order );// get the order related post data in xml format
                            if($order_detail){
                                $post_data .= $order_detail;
                            }else{
                                $message = 'Something went wrong while creating order detail post request.';
                                $order->sync_status = 'Failed';
                                $order->updated_at = new \DateTime();
                                $order->save();
                                $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $message);
                                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'failed status: ' . print_r($message, true));
                            }
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'post_data after CreateOrderDetailRequest: ' . print_r($post_data, true));
                            $salesOrderAddresses = $order->platformOrderAddress->toArray(); // get the order address array data if there is any
                            $salesOrderLines = $order->platformOrderLine->toArray(); // get the order lines array data if there is any

                            $parentorderlineIds = []; // store the parent order line ids for the next use of inserting child order line
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'salesOrderAddresses: ' . print_r($salesOrderAddresses, true));
                            if( $salesOrderAddresses ) { // check if the count is not 0
                                for( $x = 0; $x < count($salesOrderAddresses); $x++ ) { // loop for the arrays of order address / order lines
                                    \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'salesOrderAddresses x: ' . print_r($salesOrderAddresses[$x], true));
                                    \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'salesOrderAddresses xid: ' . print_r($salesOrderAddresses[$x]['id'], true));
                                    if( isset( $salesOrderAddresses[$x] ) && isset( $salesOrderAddresses[$x]['id'] ) ) {
                                        $post_data .= $this->CreateShipmentAddressRequest( $salesOrderAddresses[$x] ); // get the address array for the request
                                        \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'post_data after CreateShipmentAddressRequest: ' . print_r($post_data, true));
                                        // Check for shippind address
                                        \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'salesOrderAddresses address_type: ' . print_r($salesOrderAddresses[$x]['address_type'], true));
                                        if( $salesOrderAddresses[$x]['address_type'] == 'shipping' ) {
                                            $delivery_address = $this->CreateDeliveryAddressRequest( $salesOrderAddresses[$x] ); // get the address array for the request
                                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'delivery_address: ' . print_r($delivery_address, true));
                                        }
                                    }
                                }
                            }else{
                                $message = 'Address not found.';
                                $order->sync_status = 'Failed';
                                $order->updated_at = new \DateTime();
                                $order->save();
                                $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->source_platform_id, $this->platformId, $object_id, 'failed', $order->id, $message);
                                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'failed status: ' . print_r($message, true));
                                continue;
                            }

                            if( $salesOrderLines ) { // check if the count is not 0
                                for( $y = 0; $y < count($salesOrderLines); $y++ ) { // loop for the arrays of order address / order lines
                                    if( isset( $salesOrderLines[$y] ) && isset( $salesOrderLines[$y]['id'] ) ) {
                                        $parentorderlineIds[] = $salesOrderLines[$y]['id'];
                                        $post_data .= $this->CreateShipmentLinesRequest( $salesOrderLines[$y] ); // get the order line / package array for the request
                                    }
                                }
                            }
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'post_data after CreateShipmentLinesRequest: ' . print_r($post_data, true));
                            $post_data .= '</Order>'; // Order Tag [end]

                            $post_data .= '<Shipment>'; // Shipment Tag [start]

                                $post_data .= '<Package><PackageReference1>'.$order->order_number.'</PackageReference1></Package>';
                                if($delivery_address){
                                    $post_data .= $delivery_address;
                                }
                                $post_data .= '<ShipmentType>Pending</ShipmentType>';

                            $post_data .= '</Shipment>'; // Shipment Tag [end]

                            $post_data .= '</ShipTransaction></Request>'; // Request Tag [end]
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'post_data: ' . print_r($post_data, true));

                            $apiresponse = self::SyncOrderForShipRush( $account, $post_data );
                            //$apiresponse = [ "ShipmentId" => "kta83d7e-0b3a-4e74-b6e7-ae2c004b63kt" ]; // Test: to debug below code
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'apiresponse: ' . print_r($apiresponse, true));
                            if( isset( $apiresponse['ShipmentId'] ) ) { // if response is without any error
                                $childdata = $this->CreateOrdersLinkingForDatabase( $order, $apiresponse ); // save the details to the database for both the parent and child order
                                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'childdata: ' . print_r($childdata, true));
                                if( count( $parentorderlineIds ) > 0 ) { // check for the order line ids
                                    for( $y = 0; $y < count( $parentorderlineIds ); $y++ ) { // loop to the ids
                                        $op = $this->CreateOrderLinesLinkingForDatabase( $childdata, $parentorderlineIds[$y], $apiresponse ); // save the details to the database for both the parent and child order lines
                                        \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'output CreateOrderLinesLinkingForDatabase: ' . print_r($op, true));
                                    }
                                }
                                $op2 = $this->CreateShipmentWithTrackingInfo( $order, $apiresponse );
                                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'output CreateShipmentWithTrackingInfo: ' . print_r($op2, true));
                                $message = "Order synced successfully.";
                                $statusForSync = 'success';
                            } else { // if response has any error
                                $message = "Order failed to sync.";
                                if(isset($apiresponse)) {
                                    $message = $apiresponse;
                                }
                                $returnstatus = $message;
                                $statusForSync = 'failed';
                                $order->sync_status = 'Failed';
                                $order->updated_at = new \DateTime();
                                $order->save();
                                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'sync failed: ' . print_r($message, true));
                            }
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'Record Saved');
                            \Storage::append('ShipRush_SyncShipmentForOrders.txt', '###############');
                            $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->source_platform_id, $this->platformId, $object_id, $statusForSync, $order->id, $message);
                        }
                    }
                } else {
                    $returnstatus = 'Account error occured.';
                }
            }else {
                $returnstatus = 'No account found for integration.';
            }
        }catch( \Exception $e ) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Create the post request data (Order initial detail) for API to send
     * @param PlatformOrder $order
     *
     * @return array
     */
    private function CreateOrderDetailRequest( PlatformOrder $order ) : string {
        $return_data = false;
        $shipping_method_object_id = $this->connectionHelper->getObjectId('shipping_method');
        if( $order && isset($order->shipping_method) ) {
            $sourceCheck = [];
            $sourceCheck['user_integration_id'] = $this->user_integration_id;
            $sourceCheck['platform_id'] = $this->source_platform_id;
            $sourceCheck['platform_object_id'] = $shipping_method_object_id;
            $sourceCheck['api_id'] = $order->shipping_method;
            $shipping_method = PlatformObjectData::where($sourceCheck)->first();
        }
        if(!$shipping_method){
            $shipping_method = $this->fieldMapHelper->getMappedDataByName($this->user_integration_id, null, 'sorder_shipping_method', ['name']);
        }

        $order_detail = $order->toArray();

        if($order_detail){
            $return_data = '';

            if($shipping_method && isset($shipping_method->name)){
                $return_data .= '<ShipMethod>'.$shipping_method->name.'</ShipMethod>';
            }
            $return_data .= '<ShipmentType>Pending</ShipmentType>';
            $return_data .= '<AlternativeOrderNumber>'.$order_detail['order_number'].'</AlternativeOrderNumber>';
            $return_data .= '<OrderDate>'.date('Y-m-d\TH:i:s', strtotime($order_detail['order_date'])).'.000Z</OrderDate>';
            $return_data .= '<ItemsTotal>'.$order_detail['total_amount'].'</ItemsTotal>';
            $return_data .= '<Total>'.$order_detail['total_amount'].'</Total>';
            $return_data .= '<ShippingChargesPaid>'.$order_detail['shipping_total'].'</ShippingChargesPaid>';
            $return_data .= '<ItemsTax>'.$order_detail['total_tax'].'</ItemsTax>';
            $return_data .= '<OrderNumber>'.$order_detail['order_number'].'</OrderNumber>';
            $return_data .= '<ExternalID>'.htmlspecialchars($order_detail['api_order_reference']).'</ExternalID>';

        }
        return $return_data;
    }

    /**
     * Create the post request data (Shipment detail) for API to send
     *
     * @param array $orderaddress
     *
     * @return string
     */
    private function CreateShipmentAddressRequest( array $orderaddress ) : string {
        $return_data = '';

        if( $orderaddress ) {
            if( $orderaddress['address_type'] === 'shipping' ) {
                $return_data .= '<ShippingAddress>';
            }
            if( $orderaddress['address_type'] === 'billing' ) {
                $return_data .= '<BillingAddress>';
            }

            if( isset($orderaddress['address_name']) ){
                $return_data .= '<LastName>' . htmlspecialchars($orderaddress['address_name']) . '</LastName>';
                $return_data .= '<NickName>' . htmlspecialchars($orderaddress['address_name']) . '</NickName>';
            }else if( isset($orderaddress['firstname']) ){
                $return_data .= '<LastName>' . htmlspecialchars($orderaddress['firstname']) . '</LastName>';
                $return_data .= '<NickName>' . htmlspecialchars($orderaddress['firstname']) . '</NickName>';
            }

            $address2 = ( isset($orderaddress['address2']) && !empty($orderaddress['address2']) ) ? ', ' . $orderaddress['address2'] : null;
            $return_data .= '<Address1>' . htmlspecialchars($orderaddress['address1']) . htmlspecialchars($address2) . '</Address1>';
            $return_data .= '<City>' . htmlspecialchars($orderaddress['address3']) . '</City>';
            $return_data .= '<State>' . htmlspecialchars($orderaddress['address4']) . '</State>';
            $return_data .= '<StateString>' . htmlspecialchars($orderaddress['address4']) . '</StateString>';
            $return_data .= '<CountryString>' . $orderaddress['country'] . '</CountryString>';
            $return_data .= '<PostalCode>' . $orderaddress['postal_code'] . '</PostalCode>';
            $return_data .= '<Country>' . $orderaddress['country'] . '</Country>';
            $return_data .= '<EMail>' . $orderaddress['email'] . '</EMail>';

            if( $orderaddress['address_type'] === 'shipping' ) {
                $return_data .= '</ShippingAddress>';
            }
            if( $orderaddress['address_type'] === 'billing' ) {
                $return_data .= '</BillingAddress>';
            }
        }

        return $return_data;
    }

    /**
     * Create the post request data (Delivery detail) for API to send
     *
     * @param array $orderaddress
     *
     * @return string
     */
    private function CreateDeliveryAddressRequest( array $orderaddress ) : string {
        $return_data = '';

        if( $orderaddress ) {
            $return_data .= '<DeliveryAddress><Address>';

            if( isset($orderaddress['address_name']) ){
                $return_data .= '<FirstName>' . htmlspecialchars($orderaddress['address_name']) . '</FirstName>';
            }else if( isset($orderaddress['firstname']) ){
                $return_data .= '<FirstName>' . htmlspecialchars($orderaddress['firstname']) . '</FirstName>';
            }

            $address2 = ( isset($orderaddress['address2']) && !empty($orderaddress['address2']) ) ? ', ' . $orderaddress['address2'] : null;
            $return_data .= '<Address1>' . htmlspecialchars($orderaddress['address1']) . htmlspecialchars($address2) . '</Address1>';
            $return_data .= '<City>' . htmlspecialchars($orderaddress['address3']) . '</City>';
            $return_data .= '<State>' . htmlspecialchars($orderaddress['address4']) . '</State>';
            $return_data .= '<PostalCode>' . $orderaddress['postal_code'] . '</PostalCode>';
            $return_data .= '<Country>' . $orderaddress['country'] . '</Country>';
            $return_data .= '<Phone>' . $orderaddress['phone_number'] . '</Phone>';

            $return_data .= '</Address></DeliveryAddress>';
        }

        return $return_data;
    }

    /**
     * Create the post request data (Shipment line item detail) for API to send
     *
     * @param int $orderlineid
     *
     * @return string
     */
    private function CreateShipmentLinesRequest( array $orderline ) : string {
        $return_data = '';

        if( $orderline ) {
            if( $orderline['row_type'] === 'ITEM' ) {
                $product = PlatformProduct::where( [
                    'user_integration_id' => $this->user_integration_id,
                    'api_product_id' => $orderline['api_product_id']
                ] )->first();

                $qty = $orderline['qty'];
                if ( $product ) {
                    $return_data .= '<ShipmentOrderItem>';

                    $return_data .= '<Name>'. htmlspecialchars($product->product_name) .'</Name>';
                    $return_data .= '<Price>'. ( (float)$orderline['total'] / $qty ) .'</Price>';
                    $return_data .= '<ExternalID>'. $product->sku .'</ExternalID>';
                    $return_data .= '<Quantity>'. $qty .'</Quantity>';
                    $return_data .= '<Total>'. $orderline['total'] .'</Total>';

                    $return_data .= '</ShipmentOrderItem>';
                }
            }
        }
        return $return_data;
    }

    /**
     * Create the initial data for API to send
     *
     * @param PlatformOrder $order
     * @param Array $response
     *
     * @return bool
     */
    private function CreateOrdersLinkingForDatabase( PlatformOrder $order, array $response ) {
        $childdata = [
            'trading_partner_id' => null,
            'order_type' => $order->order_type,
            'customer_email' => $order->customer_email,
            'order_number' => isset( $response['ShipmentId'] ) ? $response['ShipmentId'] : null,
            'currency' => $order->currency,
            'order_date' => $order->order_date,
            'order_status' => $order->order_status,
            'api_order_payment_status' => $order->api_order_payment_status,
            'due_days' => $order->due_days,
            'department' => $order->department,
            'vendor' => $order->vendor,
            'total_discount' => $order->total_discount,
            'total_tax' => $order->total_tax,
            'total_amount' => $order->total_amount,
            'net_amount' => $order->net_amount,
            'shipping_total' => $order->shipping_total,
            'shipping_tax' => null,
            'discount_tax' => $order->discount_tax,
            'payment_date' => $order->payment_date,
            'delivery_date' => $order->delivery_date,
            'shipping_method' => $order->shipping_method,
            'notes' => $order->notes,
            'refund_sync_status' => 'Pending',
            'is_voided' => $order->is_voided,
            'invoice_sync_status' => 'Pending',
            'file_name' => null,
            'ship_speed' => $order->ship_speed,
            'carrier_code' => $order->carrier_code,
            'warehouse_id' => $order->warehouse_id,
            'order_update_status' => $order->order_update_status,
            'shipment_status' => 'Pending',
            'shipment_api_status' => $order->shipment_api_status,
            'platform_order_shipment_id' => $order->platform_order_shipment_id,
            'sync_status' => 'Ready'
        ];
        $child_order = '';
        if( $order->linked_id === 0 ) {
            $childdata += [
                'user_id' => $order->user_id,
                'user_workflow_rule_id' => $order->user_workflow_rule_id,
                'platform_id' => $this->platformId,
                'user_integration_id' => $this->user_integration_id,
                'platform_customer_id' => $order->platform_customer_id,
                'linked_id' => $order->id
            ];
            $child_order = PlatformOrder::create( $childdata );
            $order->linked_id = $child_order->id;
        } else {
            $child_order = PlatformOrder::find( $order->linked_id );
            $child_order_update = $child_order;
            $child_order_update->update( $childdata );
        }
        $order->sync_status = 'Synced';
        $order->shipment_status = 'Synced';
        $order->save();
        return $child_order->id;
    }

    /**
     * Create the initial data for API to send
     *
     * @param int $orderline
     *
     * @return bool
     */
    private function CreateOrderLinesLinkingForDatabase( $orderid, int $orderlineid, array $trackinginfo ) : bool {
        $parent_orderline = PlatformOrderLine::find( $orderlineid );
        $orderlinedata = [
            'api_order_line_id' => $trackinginfo['ShipmentId'],
            'api_product_id' => $parent_orderline->api_product_id,
            'product_name' => $parent_orderline->product_name,
            'item_row_sequence' => $parent_orderline->item_row_sequence,
            'ean' => $parent_orderline->ean,
            'sku' => $parent_orderline->sku,
            'gtin' => $parent_orderline->gtin,
            'upc' => $parent_orderline->upc,
            'mpn' => $parent_orderline->mpn,
            'qty' => $parent_orderline->qty,
            'subtotal' => $parent_orderline->subtotal,
            'subtotal_tax' => $parent_orderline->subtotal_tax,
            'total' => $parent_orderline->total,
            'total_tax' => $parent_orderline->total_tax,
            'taxes' => $parent_orderline->taxes,
            'variation_id' => $parent_orderline->variation_id,
            'price' => $parent_orderline->price,
            'unit_price' => $parent_orderline->unit_price,
            'uom' => $parent_orderline->uom,
            'description' => $parent_orderline->description,
            'notes' => $parent_orderline->notes,
            'api_code' => $trackinginfo['ShipmentId'],
            'row_type' => $parent_orderline->row_type,
        ];
        if( $parent_orderline->linked_id === 0 ) {
            $orderlinedata += [
                'linked_id' => $parent_orderline->id,
                'platform_order_id' => $orderid,
            ];
            $child_orderline = PlatformOrderLine::create( $orderlinedata );
            $parent_orderline->linked_id = $child_orderline->id;
            $parent_orderline->save();
        } else {
            $child_orderline = PlatformOrderLine::find( $parent_orderline->linked_id )->update( $orderlinedata );
        }
        return true;
    }

    private function CreateShipmentWithTrackingInfo( PlatformOrder $parent_order, array $response ) : bool {
        \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 745 clear');
        $parent_shipment = PlatformOrderShipment::where([
            'platform_id' => $parent_order->platform_id,
            'user_integration_id' => $this->user_integration_id,
            'platform_order_id' => $parent_order->id,
        ])->first();  \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 751 clear');
        if( $parent_shipment ) {
            $data = [
                'shipment_id' => isset( $response['ShipmentId'] ) ? $response['ShipmentId'] : '',
                'sync_status' => 'Pending',
                'platform_order_id' => $parent_order->linked_id,
                'order_id' => isset( $response['ShipmentId'] ) ? $response['ShipmentId'] : '',
                'warehouse_id' => $parent_shipment->warehouse_id,
                'shipment_status' => $parent_shipment->shipment_status,
                'boxes' => null,
                'tracking_info' => null,
                'shipping_method' => null,
                'carrier_code' => null,
                'weight' => null,
                'tracking_url' => null,
            ];
            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 767 clear');

            $child_shipment_id = null;
            if( $parent_shipment->linked_id == null || $parent_shipment->linked_id == 0 ) { \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 770 clear');
                $data += [
                    'user_id' => $parent_order->user_id,
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $this->user_integration_id,
                    'linked_id' => $parent_shipment->id
                ]; \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 776 clear');
                $child_shipment = PlatformOrderShipment::create($data);
                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 778 clear');
                // update the child order for shipment id relation
                $op = PlatformOrder::find($parent_order->linked_id)->update([
                    'platform_order_shipment_id' => $child_shipment->id,
                ]);
                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 783 clear');
                $child_shipment_id = $child_shipment->id;
                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 785 clear');
            } else {
                $child_shipment = PlatformOrderShipment::find($parent_shipment->linked_id)->update($data);
                $child_shipment_id = $parent_shipment->linked_id;
                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 789 clear');
            }
            \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 791 clear');
            if( $child_shipment_id ){
                $op3 = $parent_shipment->update([
                    'sync_status' => 'Synced',
                    'linked_id' => $child_shipment_id
                ]);
                \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 797 clear');
            }
        }
        \Storage::append('ShipRush_SyncShipmentForOrders.txt', 'CreateShipmentWithTrackingInfo line no. 800 clear');
        return true;
    }

    private function GetShipmentTrackingInfo( $user_id, $user_integration_id ) {
        $returnstatus = true;
        \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'Called @ ' . now());
        try{
            $limit = 100;
            $this->user_integration_id = $user_integration_id; // set the user_integration_id for whole class
            $account = $this->mainModel->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId ); // get the account information for the integration
            \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'account : ' . print_r($account, true));
            if( $account ) {
                $shipment_list = PlatformOrder::select('id', 'platform_order_shipment_id', 'updated_at', 'linked_id', 'shipping_method')->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'shipment_status' => 'Pending'

                ])->orderBy('updated_at', 'ASC')->orderBy('id', 'ASC')->take($limit)->get();

                if ( count($shipment_list) && !empty($shipment_list) ) {
                    foreach ($shipment_list as $order) {
                        \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'shipment_list : ' . print_r($order, true));
                        $post_data = '';
                        if (isset($order->linked_id)) {
                            $shipment = PlatformOrderShipment::select('linked_id', 'id', 'shipment_id')->where('id', $order->platform_order_shipment_id)->first();
                            \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'shipment : ' . print_r($shipment, true));
                            if ($shipment && isset($shipment->shipment_id)) {
                                $post_data .= '<?xml version="1.0" encoding="utf-8"?><GetShipmentsRequest>';
                                $post_data .= '<ShipmentId>'. $shipment->shipment_id .'</ShipmentId>';
                                $post_data .= '<DetailLevel>Full</DetailLevel>';
                                $post_data .= '</GetShipmentsRequest>';

                                $result = self::GetShipmentServiceInfo( $account, $post_data );
                                $apiresponse = json_decode(json_encode($result), true); \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'apiresponse : ' . print_r($apiresponse, true));

                                //$apiresponse['ShipTransactions']['TShipTransaction']['Shipment']['ShipmentType'] = 'History'; // testing data
                                //$apiresponse['ShipTransactions']['TShipTransaction']['Shipment']['ShipmentNumber'] = 'FAKE35865152525996'; // testing data

                                if( isset( $apiresponse['ShipTransactions'] ) ) { // if response is without any error

                                    // Check if shipment status is Shipped or not (Note: here 'History' means Shipped)
                                    /*if( isset($apiresponse['ShipTransactions']['TShipTransaction']['Shipment']) && isset($apiresponse['ShipTransactions']['TShipTransaction']['Shipment']['ShipmentType'])){
                                        if($apiresponse['ShipTransactions']['TShipTransaction']['Shipment']['ShipmentType'] =! 'History'){
                                            \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'process failed because shipment is pending');
                                            continue;
                                        }
                                    }*/
                                    $tracking_number = null;
                                    if( isset($apiresponse['ShipTransactions']['TShipTransaction']['Shipment']) && isset($apiresponse['ShipTransactions']['TShipTransaction']['Shipment']['ShipmentNumber'])){
                                        $tracking_number = $apiresponse['ShipTransactions']['TShipTransaction']['Shipment']['ShipmentNumber'];
                                    }
                                    if( $tracking_number ){
                                        \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'tracking_number : ' . print_r($tracking_number, true));
                                        if ($shipment->id) {
                                            $shipment_data = PlatformOrderShipment::find($shipment->id)->update([
                                                'sync_status' => "Ready",
                                                'tracking_info' => $tracking_number,
                                                'shipping_method' => $order->shipping_method
                                            ]);
                                        } else {

                                            $shipment_data = PlatformOrderShipment::create([
                                                'user_id' => $user_id,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' =>  $user_integration_id,
                                                'sync_status' => "Ready",
                                                'platform_order_id' => $order->id,
                                                'order_id' => $order->api_order_id,
                                                'tracking_info' => $tracking_number,
                                                'shipping_method' => $order->shipping_method,
                                                'linked_id' => $shipment->id
                                            ]);

                                            PlatformOrderShipment::find($shipment->linked_id)->update([
                                                'linked_id' => $shipment_data->id
                                            ]);
                                            $order->platform_order_shipment_id = $shipment_data->id;
                                        }
                                        $order->shipment_status = 'Ready';
                                        $order->save();
                                        \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'tracking_number updated in db');
                                        \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', '##########');
                                    }else{
                                        $order->updated_at = new \DateTime();
                                        $order->save();
                                    }
                                } else { // if response has any error
                                    $returnstatus = 'API Error: Failed to get shipping information'; \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'returnstatus : ' . print_r($returnstatus, true));
                                    continue;
                                }
                            }else{
                                $returnstatus = 'Shipment information not found.'; \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'returnstatus : ' . print_r($returnstatus, true));
                                continue;
                            }
                        }
                    }
                }
            }else {
                $returnstatus = 'No account found for integration.';
            }
        }catch( \Exception $e ) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Syncing of the shipment orders from source platform to ShipRush platform
     *
     * @param $method, for 'MUTATE' it's for creation of new data and for 'GET' to get any data from the platform
     * @param $event, the event for the function is initiated
     * @param $is_initial_sync, at first it's 1 and then it's always 0
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $platform_workflow_rule_id, the platform_workflow_rule id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $record_id, for resyncing the failed data
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function ExecuteShipRush( $method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id ) {
        $response = true; \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'ExecuteShipRush Called @ ' . now());
        \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'method : ' . $method . ' | event : ' . $event . ' | is_initial_sync : ' . $is_initial_sync  . ' | user_integration_id : ' . $user_integration_id);
        try{
            if( $method == 'MUTATE' && $event == 'SALESORDER' ) { // Create Orders shipment for the ShipRush
                if ($is_initial_sync == 0) {
                    $response = $this->SyncShipmentForOrders( $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id );
                }
            } else if ($method == 'GET' && $event == 'SHIPMENT') {
                if ($is_initial_sync == 0) { \Storage::append('ShipRush_GetShipmentTrackingInfo.txt', 'ExecuteShipRush passed');
                    $response = $this->GetShipmentTrackingInfo( $user_id, $user_integration_id );
                }
            } else if ( $method == 'MUTATE' && $event == 'ORDERSTATUS' ) {
                $response=true;
            }
            return $response;
        } catch( \Exception $e ) {
            return $e->getMessage();
        }
    }
}
