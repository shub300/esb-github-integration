<?php

namespace App\Http\Controllers\UPS;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\Api\UPSApi;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;

use App\Models\PlatformLookup;
use App\Models\PlatformObject;
use App\Models\PlatformStates;

use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Models\UserIntegration;
use App\Helper\ConnectionHelper;
use App\Models\PlatformOrderLine;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderShipment;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\PlatformObjectDataAdditionalInformation;
use Lang;

class UPSController extends UPSApi
{
    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'ups';

    private $connectionHelper, $mainModel, $logger, $platformId, $fieldMapHelper, $user_integration_id;

    /**
     * Contructor function to initiate the classes to be used
     * in the controller
     */
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
    public function InitiateUPSAuth( Request $request ) {
        $platform = self::PLATFORMNAME;
        return view( "pages.apiauth.ups_auth", compact( 'platform' ) );
    }

    /**
     * Auth function to connect to the platform with response to the front
     * 
     * @param $request Request class
     * 
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function ConnectUPSAuth( Request $request ) {
        $response = ['status_code' => 0]; // array for return response with status_code default to 0 (false)

        try{
            $validator = Validator::make( $request->all(), [
                'access_license_number' => 'required',
                'username' => 'required',
                'password' => 'required',
                'transaction_id' => 'required',
                'env' => 'required'
            ], [
                'access_license_number.required' => 'License number is required.',
                'username.required' => 'Username is required.',
                'password.required' => 'Password is required.',
                'transaction_id.required' => 'Transaction ID is required.',
                'env.required' => 'Select the account environment.'
            ] );

            if($this->mainModel->checkHtmlTags( $request->all() ) ){
				$response['status_text'] = Lang::get('tags.validate');
				return $response;
			}
            
            if( $validator->fails() ) {
                $statustext = array_values( json_decode( $validator->messages()->toJson(), true ) )[0][0];
            } else {
                $validated = array_map( function( $val ) {
                    return htmlspecialchars( $val );
                }, $validator->validated() );
                $validated = (Object) $validated;

                // Set and Decrypt the values for security measures
                $env = $validated->env;
                $username = $validated->username;
                $access_license_number = $this->mainModel->encrypt_decrypt( $validated->access_license_number );
                $password = $this->mainModel->encrypt_decrypt( $validated->password );
                $transaction_id = $this->mainModel->encrypt_decrypt( $validated->transaction_id );

                // Get Current User Id
                $user_data =  Session::get('user_data');
                $user_id =  $user_data['id'];

                // Check for the account
                $account = PlatformAccount::select( 'id' )->where( [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'account_name' => $username,
                    'env_type' => $env
                ] )->count();
                if( $account === 0 ) {
                    $isConnected = static::checkAuthCredential( $validated );
                    if( $isConnected === true ) {
                        // Add the given data
                        $newAccount = PlatformAccount::create( [
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'account_name' => $username,
                            'app_secret' => $password,
                            'env_type' => $env,
                            'access_key' => $access_license_number,
                            'marketplace_id' => $transaction_id
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
                    $statustext = "Account with $username already connected.";
                }
            }
            $response['status_text'] = $statustext;
        }catch( \Exception $e ) {
            // $response['status_text'] = 'There is some issue adding account.';
            $response['status_text'] = $e->getMessage();
        }
        return $response;
    }

    /**
     * Syncing of the shipment orders from source platform to UPS platform
     * 
     * @param $is_initial_sync, it's always 0 for mutate
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $platform_workflow_rule_id, the platform_workflow_rule id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $record_id, for resyncing the failed data
     * 
     * @return bool or string
     */
    private function syncShipmentForOrders( $is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id ) {
        $returnstatus = true;
        try{
            $this->user_integration_id = $user_integration_id; // set the user_integration_id for whole class
            $account = $this->mainModel->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId ); // get the account information for the integration
            if( $account ) {
                $source_platform = PlatformLookup::where( ['platform_id' => $source_platform_name, 'status' => 1] )->select( 'id', 'platform_name' )->first();
                $object = PlatformObject::where( ['name' => 'sales_order', 'status' => 1] )->select( 'id' )->first();
                if( $source_platform && $object ) {
                    $source_platform_id = $source_platform->id;
                    $object_id = $object->id;
                    if( $record_id ) {
                        $sync_status = 'Failed';
                        // $shipment_status = 'Failed';
                    } else {
                        $sync_status = 'Ready';
                        // $shipment_status = 'Ready';
                    }
                    $limit = 50;

                    $parent_orders = PlatformOrder::where( [
                        'platform_id' => $source_platform_id,
                        'user_integration_id' => $user_integration_id,
                        'sync_status' => $sync_status,
                        // 'shipment_status' => $shipment_status
                    ] ); // select the orders whose order are ready and also the shipment is ready
                    if( $record_id ) {
                        $parent_orders = $parent_orders->where( 'platform_order.id', $record_id );
                    }
                    $parent_orders = $parent_orders->limit( $limit )->get();

                    if( $parent_orders ) { // check if there are order to sync
                        foreach( $parent_orders as $parent_order ) { // loop the orders
                            $apidata = $this->createInitialShipmentArrayRequest( $parent_order ); // get the api data in array

                            $salesOrderAddresses = $parent_order->platformOrderAddress->toArray(); // get the order address array data if there is any
                            $salesOrderLines = $parent_order->platformOrderLine->toArray(); // get the order lines array data if there is any

                            $count = ( !empty( $salesOrderAddresses ) || !empty( $salesOrderLines ) ) ? ( ( count( $salesOrderAddresses ) > count( $salesOrderLines ) ) ? count( $salesOrderAddresses ) : count( $salesOrderLines ) ) : 0; // count the greatest array for the loop handling

                            $parentorderlineIds = []; // store the parent order line ids for the next use of inserting child order line

                            if( $count ) { // check if the count is not 0
                                for( $x = 0; $x < $count; $x++ ) { // loop for the arrays of order address / order lines
                                    if( isset( $salesOrderAddresses[$x] ) && isset( $salesOrderAddresses[$x]['id'] ) ) {
                                        $apidata = $this->createShipmentAddressArrayRequest( $salesOrderAddresses[$x]['id'], $apidata ); // get the address array for the request
                                    }
                                    if( isset( $salesOrderLines[$x] ) && isset( $salesOrderLines[$x]['id'] ) ) {
                                        $parentorderlineIds[] = $salesOrderLines[$x]['id'];
                                        $apidata = $this->createShipmentLinesArrayRequest( $salesOrderLines[$x]['id'], $apidata ); // get the order line / package array for the request
                                    }
                                }
                            }

                            $apiresponse = self::syncOrderForUPS( $account, $apidata );

                            if( isset( $apiresponse['ShipmentResponse'] ) && isset( $apiresponse['ShipmentResponse']['Response'] ) && isset( $apiresponse['ShipmentResponse']['Response']['ResponseStatus'] ) && $apiresponse['ShipmentResponse']['Response']['ResponseStatus']['Code'] == 1 ) { // if response is without any error
                                $childdata = $this->createOrdersLinkingForDatabase( $parent_order, $apiresponse['ShipmentResponse'] ); // save the details to the database for both the parent and child order
                                if( count( $parentorderlineIds ) > 0 ) { // check for the order line ids
                                    if ( count( $parentorderlineIds ) == 1 ) {
                                        $orderlinedata = $this->createOrderLinesLinkingForDatabase( $childdata, $parentorderlineIds[0], $apiresponse['ShipmentResponse']['ShipmentResults']['PackageResults'] ); // save the details to the database for both the parent and child order lines
                                    } else {
                                        for( $y = 0; $y < count( $parentorderlineIds ); $y++ ) { // loop to the ids
                                            $orderlinedata = $this->createOrderLinesLinkingForDatabase( $childdata, $parentorderlineIds[$y], $apiresponse['ShipmentResponse']['ShipmentResults']['PackageResults'][$y] ); // save the details to the database for both the parent and child order lines
                                        }
                                    }
                                }
                                $shipment = $this->createShipmentWithTrackingInfo( $parent_order, $apiresponse['ShipmentResponse']['ShipmentResults'] );
                                $message = "Order synced successfully.";
                                $statusForSync = 'success';
                            } else { // if response has any error
                                $message = "Order failed to sync.";
                                if(isset($apiresponse['response']) && isset($apiresponse['response']['errors'])) {
                                    $message = $apiresponse['response']['errors'][0]['message'];
                                }
                                $returnstatus = $message;
                                $statusForSync = 'failed';
                                $parent_order->sync_status = 'Failed';
                                // $parent_order->shipment_status = 'Failed';
                                $parent_order->save();
                            }

                            $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_order->id, $message);
                        }
                    }
                } else {
                    $returnstatus = 'Account error occured.';
                }
            } else {
                $returnstatus = 'No account found for integration.';
            }
        }catch( \Exception $e ) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /**
     * Get Tracking information of shipment from UPS of source platform
     * 
     * @param $is_initial_sync, at first it's 1 and then it's always 0
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $platform_workflow_rule_id, the platform_workflow_rule id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $record_id, for resyncing the failed data
     * 
     * @return bool or string
     */
    private function getTrackingInformationForShipment( $is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id ) {
        return true; // Getting the full tracking information in syncing of the order from source platform to UPS, so we don't need it
    }

    /**
     * Create the initial data for API to send
     * 
     * cond1 - Required if all of the listed conditions are true: ShipFrom and ShipTo countries or territories are not the same;
     * The packaging type is not UPSLetter;
     * The ShipFrom and or ShipTo countries or territories are not in the European Union or the ShipFrom and ShipTo countries or
     * territories are both in the European Union and the shipments service type is not UPS Standard.
     * 
     * cond2 - Required if destination is international. Required if Invoice and CO International forms are requested and the ShipFrom
     * address is not present.
     * 
     * cond3 - Conditionally required if EEI form (International forms) is requested and ship From is not mentioned.
     * 
     * @param PlatformOrder $order
     * 
     * @return array
     */
    private function createInitialShipmentArrayRequest( PlatformOrder $order ) : array {
        // Required for the shipperNumber and AccountNumber
        $defaultBillingCode = $this->fieldMapHelper->getMappedDataByName($this->user_integration_id, NULL, "default_billing_code", ['custom_data'], 'default');

        /** Payment Information */
        $apidata['ShipmentRequest']['Shipment']['PaymentInformation'] = [
            'ShipmentCharge' => [
                'Type' => "01",
                'BillShipper' => [
                    'AccountNumber' => $defaultBillingCode->custom_data
                ]
            ]
        ];

        /** Service Option */
        $apidata['ShipmentRequest']['Shipment']['Service'] = [
            'Code' => '01',
            'Description' => 'Expedited'
        ];
        // Additional data
        $apidata['ShipmentRequest']['Shipment']['ItemizedChargesRequestedIndicator'] = "";
        $apidata['ShipmentRequest']['Shipment']['RatingMethodRequestedIndicator'] = "";
        $apidata['ShipmentRequest']['Shipment']['TaxInformationIndicator'] = "";
        $apidata['ShipmentRequest']['Shipment']['ShipmentRatingOptions'] = [
            'NegotiatedRatesIndicator' => ''
        ];
        $apidata['ShipmentRequest']['LabelSpecification'] = [
            'LabelImageFormat' => [
                'Code' => 'GIF'
            ]
        ];
        return $apidata;
    }

    /**
     * Create the initial data for API to send
     * 
     * @param int $orderaddressid
     * @param Array $apidata
     * 
     * @return array
     */
    private function createShipmentAddressArrayRequest( int $orderaddressid, Array $apidata ) : array {
        $orderaddress = PlatformOrderAddress::find( $orderaddressid );
        if( $orderaddress ) {
            $statename = isset( $orderaddress->state ) ? $orderaddress->state : $orderaddress->address4;
            $state = PlatformStates::where([
                'name' => $statename,
                'country_code' => $orderaddress->country
            ])->select('iso2')->first();
            if( $orderaddress->address_type === 'shipping' ) {
                $apidata['ShipmentRequest']['Shipment']['ShipTo'] = [
                    'Name' => $orderaddress->address_name,
                    'AttentionName' => $orderaddress->address_name,
                    // 'FaxNumber' => '123456',
                    // 'TaxIdentificationNumber' => '12345'
                ];
                if( $orderaddress->phone_number ) {
                    $apidata['ShipmentRequest']['Shipment']['ShipTo'] += [
                        'Phone' => [
                            'Number' => $orderaddress->phone_number
                        ]
                    ];
                }
                $apidata['ShipmentRequest']['Shipment']['ShipTo'] += [
                    'Address' => [
                        'AddressLine' => $orderaddress->address1,
                        'City' => isset( $orderaddress->city ) ? $orderaddress->city : $orderaddress->address3,
                        'StateProvinceCode' => isset( $state->iso2 ) ? $state->iso2 : $statename,
                        'PostalCode' => preg_replace('/\s/', '', $orderaddress->postal_code),
                        'CountryCode' => $orderaddress->country
                    ]
                ];
            }
            if( $orderaddress->address_type === 'shippedfrom' ) {
                $defaultBillingCode = $this->fieldMapHelper->getMappedDataByName($this->user_integration_id, NULL, "default_billing_code", ['custom_data'], 'default');
                $apidata['ShipmentRequest']['Shipment']['Description'] = $orderaddress->address_name; // cond1
                $apidata['ShipmentRequest']['Shipment']['Shipper'] = [
                    'Name' => $orderaddress->address_name, // optional
                    'AttentionName' => $orderaddress->company, // cond2
                    // 'CompanyDisplayableName' => 'Test' // optional
                    // 'TaxIdentificationNumber' => '12345', // cond3
                    'Phone' => [
                        'Number' => '4504683333', // if phone container then required.
                        // 'Extension' => '+91' // optional
                    ], // cond1 - A phone number is required if destination is international.
                    'ShipperNumber' => $defaultBillingCode->custom_data, // required
                    // 'FaxNumber' => '7018V8', // optionSal
                    'Address' => [
                        'AddressLine' => $orderaddress->address1, // required
                        'City' => $orderaddress->city, // required
                        'StateProvinceCode' => !empty( $state->iso2 ) ? $state->iso2 : $statename, // cond
                        'PostalCode' => preg_replace('/\s/', '', $orderaddress->postal_code), // cond
                        'CountryCode' => $orderaddress->country // required
                    ] // required
                ]; // required

                $apidata['ShipmentRequest']['Shipment']['ShipFrom'] = [
                    'Name' => $orderaddress->address_name,
                    'AttentionName' => $orderaddress->address_name,
                    // 'FaxNumber' => '123456',
                    // 'TaxIdentificationNumber' => '12345'
                ];
                if( $orderaddress->phone_number ) {
                    $apidata['ShipmentRequest']['Shipment']['ShipFrom'] += [
                        'Phone' => [
                            'Number' => $orderaddress->phone_number
                        ]
                    ];
                }
                $apidata['ShipmentRequest']['Shipment']['ShipFrom'] += [
                    'Address' => [
                        'AddressLine' => $orderaddress->address1,
                        'City' => isset( $orderaddress->city ) ? $orderaddress->city : $orderaddress->address3,
                        'StateProvinceCode' => isset( $state->iso2 ) ? $state->iso2 : $statename,
                        'PostalCode' => preg_replace('/\s/', '', $orderaddress->postal_code),
                        'CountryCode' => $orderaddress->country
                    ]
                ];
            }
        }
        return $apidata;
    }

    /**
     * Create the initial data for API to send
     * 
     * @param int $orderlineid
     * @param Array $apidata
     * 
     * @return array
     */
    private function createShipmentLinesArrayRequest( int $orderlineid, Array $apidata ) : array {
        $orderline = PlatformOrderLine::find( $orderlineid );
        if( $orderline ) {
            if( $orderline->row_type === 'ITEM' ) {
                $product = PlatformProduct::where( [
                    'user_integration_id' => $this->user_integration_id,
                    'api_product_id' => $orderline->api_product_id
                ] )->with( 'platformProductAttribute' )->first();
                $qty = $orderline->qty;
                if ( $product ) {
                    while( $qty > 0 ) {
                        $apidata['ShipmentRequest']['Shipment']['Package'][] = [
                            'Description' => $product->product_name,
                            'Packaging' => [
                                'Code' => '02'
                            ],
                            'Dimensions' => [
                                'UnitOfMeasurement' => [
                                    'Code' => 'IN'
                                ],
                                'Length' => ( $product->platformProductAttribute->lenght != 0 ) ? (string) $product->platformProductAttribute->lenght : (string) 10,
                                'Width' => ( $product->platformProductAttribute->width != 0 ) ? (string) $product->platformProductAttribute->width : (string) 10,
                                'Height' => ( $product->platformProductAttribute->height != 0 ) ? (string) $product->platformProductAttribute->height : (string) 10,
                            ],
                            'PackageWeight' => [
                                'UnitOfMeasurement' => [
                                    'Code' => isset( $product->weight_unit ) ? strtoupper( $product->weight_unit ) : 'LBS'
                                ],
                                'Weight' => isset( $product->weight_unit ) ? strtoupper( $product->weight_unit ) : '1'
                            ],
                            'PackageServiceOptions' => ''
                        ];
                        $qty--;
                    }
                }
            }
        }
        return $apidata;
    }

    /**
     * Create the initial data for API to send
     * 
     * @param PlatformOrder $order
     * @param Array $response
     * 
     * @return bool
     */
    private function createOrdersLinkingForDatabase( PlatformOrder $order, array $response ) {
        $childdata = [
            'trading_partner_id' => null,
            'order_type' => $order->order_type,
            'customer_email' => $order->customer_email,
            'order_number' => isset( $response['Response']['TransactionReference']['TransactionIdentifier'] ) ? $response['Response']['TransactionReference']['TransactionIdentifier'] : null,
            'currency' => isset( $response['ShipmentResults']['ShipmentCharges']['TotalCharges']['CurrencyCode'] ) ? $response['ShipmentResults']['ShipmentCharges']['TotalCharges']['CurrencyCode'] : null,
            'order_date' => $order->order_date,
            'order_status' => $order->order_status,
            'api_order_payment_status' => $order->api_order_payment_status,
            'due_days' => $order->due_days,
            'department' => $order->department,
            'vendor' => $order->vendor,
            'total_discount' => $order->total_discount,
            'total_tax' => $order->total_tax,
            'total_amount' => isset( $response['ShipmentResults']['ShipmentCharges']['TotalCharges']['MonetaryValue'] ) ? $response['ShipmentResults']['ShipmentCharges']['TotalCharges']['MonetaryValue'] : null,
            'net_amount' => isset( $response['ShipmentResults']['ShipmentCharges']['TotalCharges']['MonetaryValue'] ) ? $response['ShipmentResults']['ShipmentCharges']['TotalCharges']['MonetaryValue'] : null,
            'shipping_total' => isset( $response['ShipmentResults']['ShipmentCharges']['TransportationCharges']['MonetaryValue'] ) ? $response['ShipmentResults']['ShipmentCharges']['TransportationCharges']['MonetaryValue'] : null,
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
            'shipment_status' => 'Ready',
            'shipment_api_status' => $order->shipment_api_status,
            'platform_order_shipment_id' => $order->platform_order_shipment_id,
            'sync_status' => 'Ready'
        ];
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
            $child_order = PlatformOrder::find( $order->linked_id )->update( $childdata );
        }
        $child_order->save();
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
    private function createOrderLinesLinkingForDatabase( $orderid, int $orderlineid, array $trackinginfo ) : bool {
        $parent_orderline = PlatformOrderLine::find( $orderlineid );
        $orderlinedata = [
            'api_order_line_id' => $trackinginfo['TrackingNumber'],
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
            'api_code' => $trackinginfo['TrackingNumber'],
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

    /**
     * Create the initial data for API to send
     * 
     * @param PlatformOrder $order
     * @param Array $response
     * 
     * @return bool
     */
    private function createShipmentWithTrackingInfo( PlatformOrder $parent_order, array $response ) : bool {
        $parent_shipment = PlatformOrderShipment::where([
            'platform_id' => $parent_order->platform_id,
            'user_integration_id' => $this->user_integration_id,
            'platform_order_id' => $parent_order->id,
        ])->first();
        if( $parent_shipment ) {
            $data = [
                'shipment_id' => isset( $response['ShipmentIdentificationNumber'] ) ? $response['ShipmentIdentificationNumber'] : '',
                'sync_status' => 'Ready',
                'platform_order_id' => $parent_order->linked_id,
                'order_id' => isset( $response['ShipmentIdentificationNumber'] ) ? $response['ShipmentIdentificationNumber'] : '',
                'warehouse_id' => $parent_shipment->warehouse_id,
                'shipment_status' => $parent_shipment->shipment_status,
                'boxes' => null,
                'tracking_info' => isset( $response['ShipmentIdentificationNumber'] ) ? $response['ShipmentIdentificationNumber'] : '',
                'shipping_method' => null,
                'carrier_code' => null,
                'weight' => isset( $response['BillingWeight']['Weight'] ) ? $response['BillingWeight']['Weight'] : '',
                'tracking_url' => null,
            ];
            if( $parent_shipment->linked_id == null || $parent_shipment->linked_id == 0 ) {
                $data += [
                    'user_id' => $parent_order->user_id,
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $this->user_integration_id,
                    'linked_id' => $parent_shipment->id
                ];
                $child_shipment = PlatformOrderShipment::create($data);
                // update the child order for shipment id relation
                PlatformOrder::find($parent_order->linked_id)->update([
                    'platform_order_shipment_id' => $child_shipment->id,
                ]);
            } else {
                $child_shipment = PlatformOrderShipment::find($parent_shipment->linked_id)->update($data);
            }
            $parent_shipment->update([
                'sync_status' => 'Synced',
                'linked_id' => $child_shipment->id
            ]);
        }
        return true;
    }

    /**
     * Syncing of the shipment orders from source platform to UPS platform
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
    public function executeUPS( $method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id ) {
        $response = true;
        try{
            if( $method == 'MUTATE' && $event == 'SALESORDER' ) { // Create Orders shipment for the UPS
                $response = $this->syncShipmentForOrders( $is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id );
            } elseif( $method == 'GET' && $event == 'SHIPMENT' ) {
                $response = true;
            } elseif ( $method == 'MUTATE' && $event == 'ORDERSTATUS' ) {
                $response=true;
            }
            return $response;
        } catch( \Exception $e ) {
            return $e->getMessage();
        }
    }
}