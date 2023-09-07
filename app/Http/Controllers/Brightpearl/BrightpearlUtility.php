<?php

namespace App\Http\Controllers\Brightpearl;

use DB;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;
use App\Models\PlatformCustomerAdditionalInformation;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;

class BrightpearlUtility extends Model
{
    /**
     * Create a new model instance.
     *
     * @return void
     */
    public static $myPlatform = 'brightpearl';
    public $mobj, $helper, $map, $order;
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper;
        $this->map = new FieldMappingHelper();
        $this->order = new PlatformOrder();
    }

    /* Check duplicate source platform order & prevent to sync in Brightpearl */
    public function CheckAndPreventDuplicateOrder($platform_id, $user_integration_id, $order)
    {
        $return = false;
        $find = $this->order->select('id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $platform_id, 'order_type' => $order->order_type, 'order_number' => $order->order_number])->where('linked_id', '>', 0)->first();
        if ($find) {
            if ($find->id != $order->id) {
                $this->order->where('id', $order->id)->update(['sync_status' => 'Inactive']);
                $return = true;
            } else {
                $return = false;
            }
        }

        return $return;
    }

    /* Get UTC Offset */
    public function getUTCOffset($timezone)
    {
        try {
            $current = timezone_open($timezone);
            $utcTime = new \DateTime('now', new \DateTimeZone('UTC'));
            $offsetInSecs = timezone_offset_get($current, $utcTime);
            $hoursAndSec = gmdate('H:i', abs($offsetInSecs));
            return stripos($offsetInSecs, '-') === false ? "+{$hoursAndSec}" : "-{$hoursAndSec}";
        } catch (\Exception $e) {
            return false;
        }
    }

    // Function used to bifercate each number from the set and get all numbers of a range
    public function BifurcateTagIdSet($tags)
    {
        // $tags = "1,3,4-6,7-12,13,15"; // example format of input
        $arrTags1 = explode(',', $tags);
        $customerTags = [];
        for ($i = 0; $i < count($arrTags1); $i++) {
            if (strpos($arrTags1[$i], "-")) {
                $arrTags2 = explode('-', $arrTags1[$i]);
                $numbers = range($arrTags2[0], $arrTags2[1]);
                for ($j = 0; $j < count($numbers); $j++) {
                    $customerTags[] = $numbers[$j];
                }
            } else {
                $customerTags[] = (int)$arrTags1[$i];
            }
        }

        return $customerTags;
    }

    // Function to check whether a contact belongs to specific tag filter data or not
    public function CustomerTagFilter($TagFilterData, $contactTags)
    {
        $tag_filter_flag = true;
        $customer_tags = $this->BifurcateTagIdSet($contactTags);
        $result = array_intersect($TagFilterData, $customer_tags);
        if (!$result) {
            $tag_filter_flag = false;
        }

        return $tag_filter_flag;
    }

    // Function to store customer additional information
    public function StoreCustomerTags($custom_customer_id, $contactTags)
    {
        $info_exists = PlatformCustomerAdditionalInformation::where(['platform_customer_id' => $custom_customer_id])->first();
        if ($info_exists) { // check if the warehouse data is already there
            $info_exists->api_tag_id = $contactTags;
            $info_exists->save();
        } else {
            PlatformCustomerAdditionalInformation::create(['platform_customer_id' => $custom_customer_id, 'api_tag_id' => $contactTags]);
        }
    }

    // Function to check customer already exists with applicable tag filter data
    public function CheckTagFilterApplicability($TagFilterData, $contact_id)
    {
        if (!$contact_id || !isset($contact_id)) {
            return false;
        }
        $customer = DB::table('platform_customer AS pf_cust')
            ->join('platform_customer_additional_information AS cust_adtnl', 'cust_adtnl.platform_customer_id', '=', 'pf_cust.id')
            ->select('pf_cust.id', 'cust_adtnl.api_tag_id')
            ->where(['pf_cust.api_customer_id' => $contact_id])->first();

        $tag_filter_flag = true;
        if ($customer && $customer->api_tag_id) {
            $customer_tags = $this->BifurcateTagIdSet($customer->api_tag_id);
            $result = array_intersect($TagFilterData, $customer_tags);
            if (!$result) {
                $tag_filter_flag = false;
            }
        } else {
            $tag_filter_flag = false;
        }

        return $tag_filter_flag;
    }

    /* Custom Errors */
    public function MakeCustomError($Error, $GON)
    {
        $pattern = ["You have provided an invalid goods-out note Id:", "it has been shipped."]; //Error pattern which is comes form BP
        $return = ["status" => 0, "status_text" => $Error];
        foreach ($pattern as $err) {
            if (strpos($Error, $err) !== false) {
                $Error = preg_replace('/[0-9]+/', $GON, $Error); //replace number with GON
                $return = ["status" => 1, "status_text" => $Error];
                break;
            }
        }

        return $return;
    }

    /* Product Identity Mapping */
    public function ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $mapping_data = $this->map->getMappedField($userIntegrationId, $PlatformWorkFlowRuleID, $product_identity_obj_id);
        $source_row_data = $destination_row_data = '';
        if ($mapping_data) {
            if ($mapping_data['destination_platform_id'] == self::$myPlatform) {
                $destination_row_data = $mapping_data['destination_row_data'];
                $source_row_data = $mapping_data['source_row_data'];
            } else {
                $destination_row_data = $mapping_data['source_row_data'];
                $source_row_data = $mapping_data['destination_row_data'];
            }
        }

        return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
    }

    /* Insert Products at time of order line & shipment lines loop */
    public function InsertPendingProducts($productIds, $user_id, $user_integration_id, $platform_id)
    {
        $array_string = "'" . implode("','", $productIds) . "'";
        $findArr = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $platform_id])->whereIn('api_product_id', [$array_string])->pluck('api_product_id')->toArray();
        $arr_difference = array_diff($productIds, $findArr);
        if ($arr_difference) {
            $insert = [];
            foreach ($arr_difference as $key => $product_id) {
                array_push($insert, ['user_id' => $user_id, 'platform_id' => $platform_id, 'user_integration_id' => $user_integration_id, 'api_product_id' => $product_id, 'product_sync_status' => 'Pending']);
            }

            if ($insert) {
                DB::table('platform_product')->insert($insert);
            }
        }
    }

    /* Find one to one order status mapping */
    public function OneToOneOrderStatusMapping($user_id, $user_integration_id, $source_platform_id, $order_status_object_id, $order_status)
    {
        $statusId = false;
        $order_status = PlatformObjectData::select('api_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $order_status_object_id, 'name' => $order_status, 'status' => 1])->first();
        if ($order_status) {
            $sorder_status = $this->map->getMappedDataByName($user_integration_id, null, "sorder_status", ['api_id'], 'regular', $order_status->api_id);
            if ($sorder_status) {
                $statusId = $sorder_status;
            }
        } else {
            $sorder_status = $this->map->getMappedDataByName($user_integration_id, null, "sorder_status", ['api_id'], 'regular', $order_status);
            if ($sorder_status) {
                $statusId = $sorder_status;
            }
        }

        return $statusId;
    }

    /* ---Insert Order Details--- */
    public function SaveOrderDetails($payload)
    {
        $orderID = false;
        if (!empty($payload)) {
            DB::beginTransaction();
            try {
                $order = new PlatformOrder();
                $order->user_id = $payload['user_id'];
                $order->platform_id = $payload['platform_id'];
                $order->user_integration_id = $payload['user_integration_id'];
                $order->user_workflow_rule_id = $payload['user_workflow_rule_id'];
                $order->order_type = $payload['order_type'];
                $order->api_order_id = $payload['api_order_id'];
                $order->order_date = $payload['order_date'];
                $order->order_number = $payload['order_number'];
                $order->sync_status = $payload['sync_status'];
                $order->linked_id = $payload['linked_id'];
                $order->shipment_status = $payload['shipment_status'];
                $order->order_updated_at = $payload['order_updated_at'];
                $order->warehouse_id = $payload['warehouse_id'];
                if ($order->save()) {
                    $orderID = $order->id;
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error($payload['user_integration_id'] . ' -> BrightpearlUtility -> SaveOrderDetails -> ' . $e->getMessage());
            }
        }

        return $orderID;
    }

    // Function to check custom field replacement yes or not
    public function AllowCustomFieldAddOrder($custom_field, $type)
    {
        $return_response = true;
        if (is_array($custom_field) && !empty($type)) {
            foreach ($custom_field as $key => $field) {
                if ($key == $type) {
                    if (isset($field['value']) && $field['value'] != "YES") {
                        $return_response = false;
                        break;
                    }
                }
            }
        }

        return $return_response;
    }
}
