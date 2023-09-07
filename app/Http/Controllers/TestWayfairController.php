<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Config;
use App\Models\PlatformOrderShipment;;

class TestWayfairController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $my_platform_name = 'wayfair';

    public $mobj,$wayfair,$log,$mapping,$WorkflowSnippet,$ConnectionHelper,$my_platform_id;

    public function __construct()
    {
        // $this->mobj = new MainModel();
        // $this->wayfair = new WayfairApi();
        // $this->log = new Logger();
        // $this->mapping = new FieldMappingHelper();
        // $this->WorkflowSnippet = new WorkflowSnippet();
        // $this->ConnectionHelper = new ConnectionHelper();
        // $this->my_platform_id = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform_name);
    }

    /**
     * user_id: 146 - user_integration_id: 610 - source_platform_id: brightpearl - platform_workflow_rule_id: 185 - user_workflow_rule_id: 1177 - record_id:  - destination_platform_id: wayfair
     */
    public function createShipmentLabel($user_id = 146, $user_integration_id = 610, $source_platform_name = 'brightpearl', $platform_workflow_rule_id = 185, $user_workflow_rule_id = 1177, $record_id = 0, $destination_platform_name = 'wayfair')
    {
        echo "here";die;
        try {
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

            $object_id = $this->ConnectionHelper->getObjectId('purchase_order');
            $return = true;

            $estimatedShipDate = date( "Y-m-d h:s:i" );
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'env_type']);

            if ($ufound) {
                    $result_order = '';
                    $dryRun = 'true';
                    if ($ufound->env_type == 'production') { // checke account type .
                        $url = Config::get('apiconfig.WayfairAudience');
                    } else {
                        $url = Config::get('apiconfig.WayfairUrlSandbox');
                    }

                    if ($ufound->env_type == 'production') { // set  dryRun
                        $dryRun =  'false';
                    }

                    $limit = 1;
                    if ($record_id) {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id], ['id', 'linked_id', 'order_number']);
                    } else {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Ready'], ['id', 'linked_id', 'order_number'], ['id' => 'asc'], $limit);
                        if (!count($result_order)) {
                            $result_order = $this->mobj->getResultByConditions('platform_order', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Failed'], ['id', 'linked_id', 'order_number'], ['id' => 'asc'], $limit);
                        }
                    }

                    foreach ($result_order as $row) {
                        $variables = [];
                        $parent_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $row->linked_id], ['id', 'order_number', 'ship_speed', 'order_date']);

                        if ($parent_order) {
                            $purchase_order_object_id = $this->ConnectionHelper->getObjectId('estimate_ship_in_days');
                            $warehouse_mapp = $this->mapping->getMappedWarehouse($user_integration_id, $platform_workflow_rule_id, $purchase_order_object_id, ['custom_data']);
                            $lineItem = [];
                            if ($warehouse_mapp && $parent_order->order_date) {
                                $estimatedShipDate = date(DATE_ISO8601, strtotime('+' . $warehouse_mapp->custom_data . ' days ', strtotime($parent_order->order_date)));
                            }

                            $OrderWarehouseId = null;
                            $defaultSelectedWarehouse = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "order_warehouse", ['api_id']);

                            if (isset($defaultSelectedWarehouse->api_id)) {
                                $OrderWarehouseId = $defaultSelectedWarehouse->api_id;
                            }
                            $param_data = [
                                'poNumber' => $parent_order->order_number,
                                'warehouseId' => $OrderWarehouseId,
                                'requestForPickupDate' => $estimatedShipDate
                            ];

                            $params = '$params';
                            $curl_post_data = [
                                "query" => "mutation register($params: RegistrationInput!) {
                                    purchaseOrders {
                                        register(registrationInput: $params) {
                                            eventDate,
                                            pickupDate,
                                            consolidatedShippingLabel {
                                                url,
                                            },
                                            shippingLabelInfo {
                                                carrier,
                                                carrierCode,
                                                trackingNumber,
                                            },
                                            purchaseOrder {
                                                poNumber,
                                                shippingInfo {
                                                    carrierCode
                                                }
                                            }
                                        }
                                    }
                                } ", "variables" => [
                                    "params" => $param_data
                                ]
                            ];

                            $request_data_json = json_encode($curl_post_data);
                            $response = $this->wayfair->createShipmentLabel($ufound->access_token, $url, $request_data_json, $source_platform_name, $destination_platform_name);
                            $order_data = json_decode($response, true);
                            if( isset( $_GET['istest'] ) && $_GET['istest'] == 1 ){
                                dd( $parent_order, $request_data_json, $order_data );
                            }

                            if (isset($order_data['errors']) && count($order_data['errors'])) {
                                $return = $order_data['errors'][0]['message'];
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $return);
                            } else {
                                if (isset($order_data['data']['purchaseOrders']['register'])) {
                                    $lableCreationResponse = $order_data['data']['purchaseOrders']['register'];
                                    $shipment = PlatformOrderShipment::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $row->id])->first();
                                    if($shipment){ //udapte shipping label info after create shipment label
                                        $shipment->tracking_url = $lableCreationResponse['consolidatedShippingLabel']['url'];
                                        $shipment->carrier_code = $lableCreationResponse['shippingLabelInfo'][0]['carrierCode'];
                                        $shipment->tracking_info = $lableCreationResponse['shippingLabelInfo'][0]['trackingNumber'];
                                        $shipment->is_shipped = 1;
                                        $shipment->save();
                                    }
                                    $nmsg = 'Label Created successfully!';
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $row->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $row->id, $nmsg);
                                }
                            }
                        }
                    }
            }
        } catch (\Exception $e) {
            $return = $e->getMessage();
        }
        return $return;
    }
}
